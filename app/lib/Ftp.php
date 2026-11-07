<?php
declare(strict_types=1);

/**
 * Ftp - schlanker, aber vollstaendiger FTP-Client auf Socket-Basis.
 * Unterstuetz explizites TLS (AUTH TLS), passiven Modus (EPSV/PASV) und
 * MLSD mit LIST-Fallback.
 */
final class Ftp
{
    /** @var resource|null */
    private $ctrl = null;
    /** @var resource|null */
    private $data = null;
    private array $cfg;
    private string $lastResponse = '';
    private bool $mlsdSupported = true;
    private bool $featKnown = false;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function __destruct()
    {
        $this->close();
    }

    private static function clean(string $value): string
    {
        if (preg_match('/[\r\n\0]/', $value)) {
            throw new AppError('FTP-Parameter enthaelt ungueltige Zeichen.');
        }
        return $value;
    }

    /**
     * Dateipfade werden relativ zum Startverzeichnis (CWD) gesendet.
     * Ein fuehrender Schraegstrich wuerde vom Server als absoluter Pfad ab
     * der Konto-Wurzel gedeutet - bei Startverzeichnis /htdocs also ausserhalb
     * der Website (Antwort 550).
     */
    private static function rel(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        return ltrim($path, '/');
    }

    private function pathArg(string $path): string
    {
        $rel = self::rel($path);
        return $rel === '' ? '' : ' ' . self::clean($rel);
    }

    public function connect(): void
    {
        $host = trim((string) ($this->cfg['host'] ?? ''));
        if ($host === '') {
            throw new AppError('FTP-Host ist nicht konfiguriert.');
        }
        self::clean($host);
        $port = (int) ($this->cfg['port'] ?? 21);
        if ($port <= 0 || $port > 65535) {
            $port = 21;
        }
        $implicit = !empty($this->cfg['implicit']);
        $scheme = $implicit ? 'tls' : 'tcp';
        $remote = $scheme . '://' . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!is_resource($sock)) {
            throw new AppError('FTP-Verbindung zu ' . $host . ':' . $port . ' fehlgeschlagen: ' . ($errstr ?: 'unbekannter Fehler'), 502);
        }
        stream_set_timeout($sock, 30);
        $this->ctrl = $sock;
        [$code, $text] = $this->readResponse();
        if ($code !== 220) {
            throw new AppError('FTP-Server hat die Verbindung nicht angenommen: ' . $text, 502);
        }
        if (!$implicit && !empty($this->cfg['tls'])) {
            [$code, $text] = $this->cmd('AUTH TLS');
            if ($code !== 234 && $code !== 334 && $code !== 220) {
                throw new AppError('FTP-Server unterstuetzt kein explizites TLS: ' . $text, 502);
            }
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (@stream_socket_enable_crypto($sock, true, $crypto) !== true) {
                throw new AppError('TLS-Aushandlung mit dem FTP-Server fehlgeschlagen.', 502);
            }
            $this->cmd('PBSZ 0');
            $this->cmd('PROT P');
        }
        $user = (string) ($this->cfg['user'] ?? '');
        $pass = (string) ($this->cfg['pass'] ?? '');
        if ($user === '') {
            throw new AppError('FTP-Benutzername fehlt.');
        }
        self::clean($user);
        self::clean($pass);
        [$code, $text] = $this->cmd('USER ' . $user);
        if ($code === 331) {
            [$code, $text] = $this->cmd('PASS ' . $pass);
        }
        if ($code !== 230 && $code !== 202) {
            throw new AppError('FTP-Anmeldung fehlgeschlagen: ' . $text, 502);
        }
        $this->cmd('TYPE I');
        $root = (string) ($this->cfg['root'] ?? '');
        if ($root !== '' && $root !== '/') {
            $this->chdir($root);
        }
    }

    public function close(): void
    {
        if (is_resource($this->data)) {
            @fclose($this->data);
        }
        $this->data = null;
        if (is_resource($this->ctrl)) {
            @fwrite($this->ctrl, "QUIT\r\n");
            @fclose($this->ctrl);
        }
        $this->ctrl = null;
    }

    private function readResponse(): array
    {
        if (!is_resource($this->ctrl)) {
            throw new AppError('Keine FTP-Verbindung.', 500);
        }
        $lines = [];
        $first = true;
        $code = 0;
        while (true) {
            $line = fgets($this->ctrl, 8192);
            if ($line === false) {
                $meta = stream_get_meta_data($this->ctrl);
                throw new AppError('FTP-Server hat nicht geantwortet' . (!empty($meta['timed_out']) ? ' (Zeitueberschreitung)' : '') . '.', 504);
            }
            $line = rtrim($line, "\r\n");
            $lines[] = $line;
            if ($first) {
                if (!preg_match('/^(\d{3})([ -])(.*)$/', $line, $m)) {
                    throw new AppError('Unerwartete FTP-Antwort: ' . $line, 502);
                }
                $code = (int) $m[1];
                if ($m[2] === ' ') {
                    break;
                }
                $first = false;
                continue;
            }
            if (preg_match('/^' . $code . ' /', $line)) {
                break;
            }
            if (count($lines) > 500) {
                break;
            }
        }
        $this->lastResponse = implode("\n", $lines);
        return [$code, $this->lastResponse];
    }

    public function cmd(string $command): array
    {
        if (!is_resource($this->ctrl)) {
            throw new AppError('Keine FTP-Verbindung.', 500);
        }
        if (preg_match('/[\r\n]/', $command)) {
            throw new AppError('Ungueltiger FTP-Befehl.');
        }
        if (@fwrite($this->ctrl, $command . "\r\n") === false) {
            throw new AppError('FTP-Befehl konnte nicht gesendet werden.', 502);
        }
        return $this->readResponse();
    }

    private function need(array $res, string $what): void
    {
        if ($res[0] >= 400) {
            throw new AppError(strtoupper($what) . ' fehlgeschlagen: ' . $res[1], 502);
        }
    }

    public function pwd(): string
    {
        [$code, $text] = $this->cmd('PWD');
        if ($code === 257 && preg_match('/"([^"]*)"/', $text, $m)) {
            return $m[1];
        }
        return '/';
    }

    public function chdir(string $path): void
    {
        if ($path === '' || $path === '.') {
            return;
        }
        $res = $this->cmd('CWD ' . self::clean($path));
        if ($res[0] >= 400) {
            // Verzeichnis eventuell erst anlegen
            $this->mkdRecursive($path);
            $res = $this->cmd('CWD ' . self::clean($path));
            $this->need($res, 'CWD');
        }
    }

    public function mkdRecursive(string $path): void
    {
        $path = self::rel($path);
        if ($path === '') {
            return;
        }
        $parts = array_values(array_filter(explode('/', $path), static fn($p) => $p !== ''));
        $cur = '';
        foreach ($parts as $part) {
            $cur = $cur === '' ? $part : $cur . '/' . $part;
            $res = $this->cmd('MKD ' . self::clean($cur));
            // 257 = ok, 550 = existiert bereits
            if ($res[0] === 550) {
                continue;
            }
            if ($res[0] >= 400) {
                throw new AppError('Verzeichnis konnte nicht erstellt werden: ' . $cur . ' (' . $res[1] . ')', 502);
            }
        }
    }

    public function features(): array
    {
        if ($this->featKnown) {
            return [];
        }
        $this->featKnown = true;
        $res = $this->cmd('FEAT');
        $feats = [];
        if ($res[0] === 211) {
            foreach (explode("\n", $res[1]) as $line) {
                $line = trim($line);
                if ($line === '' || preg_match('/^\d{3}/', $line)) {
                    continue;
                }
                $feats[] = strtoupper($line);
            }
            $this->mlsdSupported = (bool) preg_grep('/^(MLSD|MLST)/', $feats);
        }
        return $feats;
    }

    private function openPassive(): void
    {
        $host = (string) ($this->cfg['host'] ?? '');
        $res = $this->cmd('EPSV');
        $port = 0;
        if ($res[0] === 229 && preg_match('/\(\|\|\|(\d+)\|\)/', $res[1], $m)) {
            $port = (int) $m[1];
        } else {
            $res = $this->cmd('PASV');
            if ($res[0] !== 227) {
                throw new AppError('Passiver FTP-Modus nicht verfuegbar: ' . $res[1], 502);
            }
            if (!preg_match('/\((\d+),(\d+),(\d+),(\d+),(\d+),(\d+)\)/', $res[1], $m)) {
                throw new AppError('PASV-Antwort nicht lesbar: ' . $res[1], 502);
            }
            $ip = $m[1] . '.' . $m[2] . '.' . $m[3] . '.' . $m[4];
            $port = ((int) $m[5]) * 256 + (int) $m[6];
            if ($ip !== '0.0.0.0') {
                $host = $ip;
            }
        }
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 20);
        if (!is_resource($sock)) {
            throw new AppError('Datenverbindung fehlgeschlagen: ' . $errstr, 502);
        }
        stream_set_timeout($sock, 60);
        if (!empty($this->cfg['tls']) || !empty($this->cfg['implicit'])) {
            $crypto = defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')
                ? (STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)
                : STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (@stream_socket_enable_crypto($sock, true, $crypto) !== true) {
                throw new AppError('TLS auf der Datenverbindung fehlgeschlagen.', 502);
            }
        }
        $this->data = $sock;
    }

    private function closeData(): void
    {
        if (is_resource($this->data)) {
            @fclose($this->data);
        }
        $this->data = null;
    }

    /** @return array<int,array{name:string,type:string,size:int}> */
    public function listDir(string $path = ''): array
    {
        $this->features();
        $entries = [];
        if ($this->mlsdSupported) {
            $this->openPassive();
            $arg = $this->pathArg($path);
            $res = $this->cmd('MLSD' . $arg);
            if ($res[0] >= 400) {
                $this->closeData();
                $this->mlsdSupported = false;
            } else {
                $raw = '';
                while (($chunk = fread($this->data, 8192)) !== false && $chunk !== '') {
                    $raw .= $chunk;
                }
                $this->closeData();
                [$code, $text] = $this->readResponse();
                if ($code >= 400) {
                    throw new AppError('Verzeichnis konnte nicht gelesen werden: ' . $text, 502);
                }
                foreach (explode("\n", $raw) as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $semi = strpos($line, ';');
                    $space = strpos($line, ' ');
                    if ($semi === false || $space === false) {
                        continue;
                    }
                    $facts = substr($line, 0, $space);
                    $name = trim(substr($line, $space + 1));
                    $type = 'file';
                    $size = 0;
                    foreach (explode(';', $facts) as $fact) {
                        if (str_starts_with(strtolower($fact), 'type=')) {
                            $t = strtolower(substr($fact, 5));
                            $type = str_starts_with($t, 'dir') ? 'dir' : 'file';
                        }
                        if (str_starts_with(strtolower($fact), 'size=')) {
                            $size = (int) substr($fact, 5);
                        }
                    }
                    if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                        continue;
                    }
                    $entries[] = ['name' => $name, 'type' => $type, 'size' => $size];
                }
                return $entries;
            }
        }

        $this->openPassive();
        $arg = $this->pathArg($path);
        $res = $this->cmd('LIST' . $arg);
        if ($res[0] >= 400) {
            $this->closeData();
            throw new AppError('Verzeichnis konnte nicht gelesen werden: ' . $res[1], 502);
        }
        $raw = '';
        while (($chunk = fread($this->data, 8192)) !== false && $chunk !== '') {
            $raw .= $chunk;
        }
        $this->closeData();
        [$code, $text] = $this->readResponse();
        if ($code >= 400) {
            throw new AppError('Verzeichnis konnte nicht gelesen werden: ' . $text, 502);
        }
        foreach (explode("\n", $raw) as $line) {
            $line = rtrim($line, "\r");
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^([dl-])\S*\s+\d+\s+\S+\s+\S+\s+(\d+)\s+\w+\s+\w+\s+([\d:]+)\s+(.+)$/', $line, $m)) {
                $name = $m[4];
                if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                    continue;
                }
                $entries[] = ['name' => $name, 'type' => $m[1] === 'd' ? 'dir' : 'file', 'size' => (int) $m[2]];
                continue;
            }
            if (preg_match('/^([\d-]+)\s+([\d:]+[AP]M)\s+(<DIR>|\d+)\s+(.+)$/i', $line, $m)) {
                $name = $m[4];
                if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                    continue;
                }
                $isDir = strtoupper($m[3]) === '<DIR>';
                $entries[] = ['name' => $name, 'type' => $isDir ? 'dir' : 'file', 'size' => $isDir ? 0 : (int) $m[3]];
            }
        }
        return $entries;
    }

    public function size(string $remote): ?int
    {
        $res = $this->cmd('SIZE ' . self::clean(self::rel($remote)));
        if ($res[0] === 213 && preg_match('/213\s+(\d+)/', $res[1], $m)) {
            return (int) $m[1];
        }
        return null;
    }

    public function mdtm(string $remote): ?int
    {
        $res = $this->cmd('MDTM ' . self::clean(self::rel($remote)));
        if ($res[0] === 213 && preg_match('/213\s+(\d{14})/', $res[1], $m)) {
            return strtotime(substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2) . ' ' .
                substr($m[1], 8, 2) . ':' . substr($m[1], 10, 2) . ':' . substr($m[1], 12, 2) . ' UTC') ?: null;
        }
        return null;
    }

    public function get(string $remote, int $maxBytes = 12000000): string
    {
        $this->openPassive();
        $res = $this->cmd('RETR ' . self::clean(self::rel($remote)));
        if ($res[0] >= 400) {
            $this->closeData();
            throw new AppError('Datei konnte nicht geladen werden: ' . $remote . ' (' . $res[1] . ')', 502);
        }
        $data = '';
        while (!feof($this->data)) {
            $chunk = fread($this->data, 65536);
            if ($chunk === false) {
                break;
            }
            $data .= $chunk;
            if (strlen($data) > $maxBytes) {
                $this->closeData();
                @$this->cmd('ABOR');
                throw new AppError('Datei ist zu gross zum Herunterladen: ' . $remote, 413);
            }
        }
        $this->closeData();
        [$code, $text] = $this->readResponse();
        if ($code >= 400 && $code !== 426) {
            throw new AppError('Uebertragung fehlgeschlagen: ' . $remote . ' (' . $text . ')', 502);
        }
        return $data;
    }

    public function put(string $remote, string $content): void
    {
        $this->openPassive();
        $res = $this->cmd('STOR ' . self::clean(self::rel($remote)));
        if ($res[0] >= 400) {
            $this->closeData();
            throw new AppError('Datei konnte nicht hochgeladen werden: ' . $remote . ' (' . $res[1] . ')', 502);
        }
        $len = strlen($content);
        $written = 0;
        while ($written < $len) {
            $n = @fwrite($this->data, substr($content, $written, 65536));
            if ($n === false || $n === 0) {
                $this->closeData();
                throw new AppError('Schreibfehler beim Upload: ' . $remote, 502);
            }
            $written += $n;
        }
        $this->closeData();
        [$code, $text] = $this->readResponse();
        if ($code >= 400) {
            throw new AppError('Upload fehlgeschlagen: ' . $remote . ' (' . $text . ')', 502);
        }
    }

    public function delete(string $remote): void
    {
        $res = $this->cmd('DELE ' . self::clean(self::rel($remote)));
        if ($res[0] >= 400 && $res[0] !== 550) {
            throw new AppError('Loeschen fehlgeschlagen: ' . $remote, 502);
        }
    }

    public function rename(string $from, string $to): void
    {
        $res = $this->cmd('RNFR ' . self::clean(self::rel($from)));
        if ($res[0] >= 400) {
            throw new AppError('Umbenennen fehlgeschlagen (Quelle): ' . $from . ' (' . $res[1] . ')', 502);
        }
        $res = $this->cmd('RNTO ' . self::clean(self::rel($to)));
        if ($res[0] >= 400) {
            throw new AppError('Umbenennen fehlgeschlagen (Ziel): ' . $res[1], 502);
        }
    }

    public function test(): array
    {
        $this->connect();
        $entries = $this->listDir('');
        $sample = array_slice(array_map(static fn($e) => $e['name'] . ($e['type'] === 'dir' ? '/' : ''), $entries), 0, 25);
        $pwd = $this->pwd();
        return ['ok' => true, 'pwd' => $pwd, 'count' => count($entries), 'sample' => $sample];
    }
}
