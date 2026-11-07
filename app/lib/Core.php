<?php
declare(strict_types=1);
/**
 * Kernbausteine: Fehler, Ablage (Pfade, atomare Schreibvorgaenge), Konfiguration,
 * Authentifizierung, CSRF, Journal.
 */

final class AppError extends RuntimeException
{
    public int $status;

    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

const WFX_EDIT_EXT = ['html', 'htm', 'php', 'css', 'js', 'mjs', 'json', 'svg', 'txt', 'md', 'xml', 'webmanifest', 'htaccess'];
const WFX_ASSET_EXT = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm', 'mp3', 'pdf'];
const WFX_MAX_EDIT_BYTES = 1500000;

final class Store
{
    public static function root(): string
    {
        if (defined('WFX_ROOT')) {
            return WFX_ROOT;
        }
        return dirname(__DIR__, 2);
    }

    public static function ws(): string
    {
        return self::root() . '/workspace';
    }

    public static function working(): string
    {
        return self::ws() . '/working';
    }

    public static function live(): string
    {
        return self::ws() . '/live';
    }

    public static function data(): string
    {
        return self::ws() . '/data';
    }

    public static function backups(): string
    {
        return self::ws() . '/backups';
    }

    public static function init(): void
    {
        foreach ([self::ws(), self::working(), self::live(), self::data(), self::backups()] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new AppError('Verzeichnis konnte nicht erstellt werden: ' . $dir, 500);
            }
        }
        $guard = self::ws() . '/.htaccess';
        if (!file_exists($guard)) {
            @file_put_contents($guard, "# Kein direkter Webzugriff auf Arbeitsdaten\nRequire all denied\nDeny from all\n");
        }
        $guard = self::ws() . '/index.php';
        if (!file_exists($guard)) {
            @file_put_contents($guard, "<?php http_response_code(404); exit;\n");
        }
    }

    /** Normalisiert einen website-relativen Pfad ("/de/index.html"). */
    public static function normRel(string $rel, bool $allowDotHtaccess = false): string
    {
        $rel = str_replace('\\', '/', trim($rel));
        $rel = preg_replace('/[?#].*$/s', '', $rel) ?? '';
        if ($rel === '') {
            throw new AppError('Leerer Pfad.');
        }
        if (str_contains($rel, "\0")) {
            throw new AppError('Ungueltiger Pfad.');
        }
        if (!str_starts_with($rel, '/')) {
            $rel = '/' . $rel;
        }
        $segments = explode('/', $rel);
        $out = [];
        foreach ($segments as $i => $seg) {
            if ($i === 0) {
                continue;
            }
            if ($seg === '') {
                continue;
            }
            if ($seg === '.' || $seg === '..') {
                throw new AppError('Pfadbestandteile wie ".." sind nicht erlaubt.', 403);
            }
            if (preg_match('/[\x00-\x1f<>:"|*\\\\]/', $seg)) {
                throw new AppError('Ungueltige Zeichen im Pfad.', 403);
            }
            if (strlen($seg) > 180) {
                throw new AppError('Pfadbestandteil ist zu lang.', 400);
            }
            if (str_starts_with($seg, '.')) {
                if ($allowDotHtaccess && $seg === '.htaccess') {
                    $out[] = $seg;
                    continue;
                }
                throw new AppError('Versteckte Dateien sind nicht bearbeitbar.', 403);
            }
            $out[] = $seg;
        }
        if (!$out) {
            throw new AppError('Ungueltiger Pfad.');
        }
        return '/' . implode('/', $out);
    }

    public static function extOf(string $rel): string
    {
        $base = basename($rel);
        if ($base === '.htaccess') {
            return 'htaccess';
        }
        return strtolower(pathinfo($base, PATHINFO_EXTENSION));
    }

    /** Prueft, ob eine Datei ueberhaupt gegenstand der Dateibearbeitung sein darf. */
    public static function assertEditable(string $rel, string $base = ''): void
    {
        $base = $base === '' ? self::working() : $base;
        if (self::extOf($rel) === 'htaccess' && basename($rel) === '.htaccess') {
            return;
        }
        $ext = self::extOf($rel);
        if ($ext === '') {
            throw new AppError('Dateien ohne Endung koennen nicht bearbeitet werden.', 415);
        }
        if (!in_array($ext, WFX_EDIT_EXT, true)) {
            throw new AppError('Dateityp ".' . $ext . '" ist fuer die Textbearbeitung nicht freigegeben.', 415);
        }
        $path = self::resolveUnder($base, $rel);
        if (is_file($path) && filesize($path) > WFX_MAX_EDIT_BYTES) {
            throw new AppError('Datei ist zu gross fuer die Bearbeitung (Limit 1,5 MB).', 413);
        }
    }

    public static function resolveUnder(string $base, string $rel): string
    {
        $baseReal = realpath($base);
        if ($baseReal === false) {
            throw new AppError('Arbeitsverzeichnis fehlt.', 500);
        }
        $target = $baseReal . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (file_exists($target)) {
            $real = realpath($target);
            if ($real === false || !self::inside($real, $baseReal)) {
                throw new AppError('Pfad verlaesst das Projektverzeichnis.', 403);
            }
            if (is_link($target)) {
                throw new AppError('Symbolische Verknuepfungen werden nicht bearbeitet.', 403);
            }
            return $real;
        }
        $dir = dirname($target);
        while (!file_exists($dir) && strlen($dir) > strlen($baseReal)) {
            $dir = dirname($dir);
        }
        $dirReal = realpath($dir);
        if ($dirReal === false || !self::inside($dirReal, $baseReal)) {
            throw new AppError('Pfad verlaesst das Projektverzeichnis.', 403);
        }
        return $target;
    }

    public static function inside(string $path, string $base): bool
    {
        $p = rtrim(str_replace('\\', '/', $path), '/');
        $b = rtrim(str_replace('\\', '/', $base), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $p = strtolower($p);
            $b = strtolower($b);
        }
        return $p === $b || str_starts_with($p, $b . '/');
    }

    public static function read(string $rel, string $base = ''): string
    {
        $base = $base === '' ? self::working() : $base;
        $path = self::resolveUnder($base, $rel);
        if (!is_file($path)) {
            throw new AppError('Datei nicht gefunden: ' . $rel, 404);
        }
        $data = file_get_contents($path);
        if ($data === false) {
            throw new AppError('Datei konnte nicht gelesen werden.', 500);
        }
        return $data;
    }

    public static function exists(string $rel, string $base = ''): bool
    {
        $base = $base === '' ? self::working() : $base;
        try {
            return is_file(self::resolveUnder($base, $rel));
        } catch (AppError) {
            return false;
        }
    }

    /**
     * Verzeichnis eines Site-Pfads, immer mit "/".
     * Windows-PHP liefert fuer dirname('/datei.html') einen Backslash.
     */
    public static function dirOf(string $rel): string
    {
        $rel = str_replace('\\', '/', $rel);
        $rel = rtrim($rel, '/');
        $pos = strrpos($rel, '/');
        if ($pos === false) {
            return '';
        }
        return $pos === 0 ? '/' : substr($rel, 0, $pos);
    }

    /** Atomares Schreiben: temporaere Datei im Zielverzeichnis + rename. */
    public static function writeAtomic(string $rel, string $content, string $base = ''): void
    {
        $base = $base === '' ? self::working() : $base;
        if (!is_dir($base) && !@mkdir($base, 0775, true) && !is_dir($base)) {
            throw new AppError('Zielbasis konnte nicht erstellt werden: ' . $base, 500);
        }
        $path = self::resolveUnder($base, $rel);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new AppError('Zielverzeichnis konnte nicht erstellt werden.', 500);
        }
        $tmp = $dir . DIRECTORY_SEPARATOR . '.wfx-' . bin2hex(random_bytes(6)) . '.tmp';
        $ok = @file_put_contents($tmp, $content, LOCK_EX);
        if ($ok === false || $ok !== strlen($content)) {
            @unlink($tmp);
            throw new AppError('Schreiben fehlgeschlagen (Schreibrechte pruefen).', 500);
        }
        $old = umask(0);
        @chmod($tmp, 0664);
        umask($old);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new AppError('Datei konnte nicht ersetzt werden.', 500);
        }
    }

    public static function writeJson(string $absPath, array $data): void
    {
        $dir = dirname($absPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new AppError('Daten konnten nicht serialisiert werden.', 500);
        }
        $tmp = $absPath . '.tmp';
        file_put_contents($tmp, $json . "\n", LOCK_EX);
        rename($tmp, $absPath);
    }

    public static function readJson(string $absPath, array $default = []): array
    {
        if (!is_file($absPath)) {
            return $default;
        }
        $raw = file_get_contents($absPath);
        if ($raw === false || trim($raw) === '') {
            return $default;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : $default;
    }

    /** @return array<int,array{path:string,size:int,mtime:int}> */
    public static function listFiles(string $root): array
    {
        $out = [];
        if (!is_dir($root)) {
            return $out;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO)
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $rel = '/' . ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
            if (self::isHiddenPath($rel)) {
                continue;
            }
            $out[] = ['path' => $rel, 'size' => $file->getSize(), 'mtime' => $file->getMTime()];
        }
        usort($out, static fn($a, $b) => strcmp($a['path'], $b['path']));
        return $out;
    }

    public static function isHiddenPath(string $rel): bool
    {
        foreach (explode('/', $rel) as $seg) {
            if ($seg !== '' && $seg[0] === '.') {
                return true;
            }
        }
        return false;
    }

    public static function hashOf(string $rel, string $base): ?string
    {
        $path = $base . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_file($path)) {
            return null;
        }
        return sha1_file($path) ?: null;
    }

    /**
     * Vergleicht zwei Verzeichnisse (Arbeitskopie vs. Live-Spiegel).
     * @return array<string,array{status:string,before:?string,after:?string,size:int}>
     */
    public static function diff(string $a, string $b): array
    {
        $out = [];
        $filesA = self::listFiles($a);
        $filesB = self::listFiles($b);
        $mapA = [];
        foreach ($filesA as $f) {
            $mapA[$f['path']] = $f;
        }
        $mapB = [];
        foreach ($filesB as $f) {
            $mapB[$f['path']] = $f;
        }
        foreach ($mapB as $rel => $f) {
            $hashB = self::hashOf($rel, $b);
            $hashA = isset($mapA[$rel]) ? self::hashOf($rel, $a) : null;
            if ($hashA === null) {
                $out[$rel] = ['status' => 'added', 'before' => null, 'after' => $hashB, 'size' => $f['size']];
            } elseif ($hashA !== $hashB) {
                $out[$rel] = ['status' => 'modified', 'before' => $hashA, 'after' => $hashB, 'size' => $f['size']];
            }
        }
        foreach ($mapA as $rel => $f) {
            if (!isset($mapB[$rel])) {
                $out[$rel] = ['status' => 'deleted', 'before' => self::hashOf($rel, $a), 'after' => null, 'size' => $f['size']];
            }
        }
        ksort($out);
        return $out;
    }

    public static function copyFile(string $rel, string $fromBase, string $toBase): void
    {
        $content = self::read($rel, $fromBase);
        self::writeAtomic($rel, $content, $toBase);
    }

    public static function unlinkRel(string $rel, string $base): void
    {
        $path = self::resolveUnder($base, $rel);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

final class Config
{
    private static ?array $cache = null;

    public static function path(): string
    {
        if (defined('WFX_CONFIG')) {
            return WFX_CONFIG;
        }
        return __DIR__ . '/../config.php';
    }

    public static function defaults(): array
    {
        return [
            'installed' => false,
            'installedAt' => null,
            'users' => [],
            'source' => [
                'type' => 'ftp',
                'host' => '',
                'port' => 21,
                'tls' => false,
                'passive' => true,
                'user' => '',
                'pass' => '',
                'root' => '/htdocs',
                'localPath' => '',
            ],
            'baseUrl' => '',
            'renderPhp' => true,
            'remoteSyncOnPublish' => true,
            'lastPull' => null,
            'lastPublish' => null,
            'supabase' => [
                'enabled' => false,
                'url' => '',
                'key' => '',
                'bucket' => 'media',
                'table' => 'media',
            ],
        ];
    }

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $cfg = self::defaults();
        if (is_file(self::path())) {
            $loaded = include self::path();
            if (is_array($loaded)) {
                $cfg = array_replace_recursive($cfg, $loaded);
            }
        }
        self::$cache = $cfg;
        return $cfg;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $cfg = self::all();
        return $cfg[$key] ?? $default;
    }

    public static function source(): array
    {
        $s = self::get('source', []);
        return is_array($s) ? $s : [];
    }

    public static function save(array $cfg): void
    {
        $cfg['installed'] = $cfg['installed'] ?? false;
        $php = "<?php\n// Lokale Konfiguration des Website-Editors.\n// ACHTUNG: enthaelt Zugangsdaten - niemals versionieren oder weitergeben.\nreturn " . var_export($cfg, true) . ";\n";
        $tmp = self::path() . '.tmp';
        if (file_put_contents($tmp, $php, LOCK_EX) === false) {
            throw new AppError('Konfiguration konnte nicht geschrieben werden.', 500);
        }
        @chmod($tmp, 0600);
        if (!rename($tmp, self::path())) {
            throw new AppError('Konfiguration konnte nicht ersetzt werden.', 500);
        }
        @chmod(self::path(), 0600);
        self::$cache = $cfg;
    }

    public static function set(string $key, mixed $value): void
    {
        $cfg = self::all();
        $cfg[$key] = $value;
        self::save($cfg);
    }

    public static function installed(): bool
    {
        return (bool) (self::all()['installed'] ?? false) && is_file(self::path());
    }
}

final class Auth
{
    private const MAX_ATTEMPTS = 8;
    private const LOCK_SECONDS = 300;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => false,
        ]);
        session_name('WFXSESS');
        @session_start();
        if (!isset($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
        }
    }

    public static function user(): ?array
    {
        $u = $_SESSION['wfx_user'] ?? null;
        if (!is_array($u) || !isset($u['name'], $u['role'])) {
            return null;
        }
        $users = Config::all()['users'] ?? [];
        if (!isset($users[$u['name']])) {
            return null;
        }
        return ['name' => $u['name'], 'role' => $users[$u['name']]['role'] ?? 'viewer'];
    }

    public static function perms(string $role): array
    {
        return match ($role) {
            'owner' => ['edit', 'components', 'global', 'publish', 'restore', 'manage', 'sync'],
            'editor' => ['edit', 'components', 'sync'],
            default => [],
        };
    }

    public static function can(string $perm): bool
    {
        $u = self::user();
        if ($u === null) {
            return false;
        }
        return in_array($perm, self::perms($u['role']), true);
    }

    public static function requirePerm(string $perm): void
    {
        self::requireLogin();
        if (!self::can($perm)) {
            throw new AppError('Fehlende Berechtigung fuer diese Aktion (' . $perm . ').', 403);
        }
    }

    public static function requireLogin(): void
    {
        if (self::user() === null) {
            throw new AppError('Nicht angemeldet.', 401);
        }
    }

    private static function attemptsFile(): string
    {
        return Store::data() . '/login-attempts.json';
    }

    private static function throttleKey(string $name): string
    {
        return strtolower($name) . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'local');
    }

    private static function assertNotLocked(string $name): void
    {
        $data = Store::readJson(self::attemptsFile(), []);
        $key = self::throttleKey($name);
        $entry = $data[$key] ?? ['count' => 0, 'last' => 0];
        if ($entry['count'] >= self::MAX_ATTEMPTS && (time() - (int) $entry['last']) < self::LOCK_SECONDS) {
            $wait = self::LOCK_SECONDS - (time() - (int) $entry['last']);
            throw new AppError('Zu viele Fehlversuche. Bitte in ' . max(1, (int) ceil($wait / 60)) . ' Minute(n) erneut versuchen.', 429);
        }
    }

    private static function registerFailure(string $name): void
    {
        $path = self::attemptsFile();
        $data = Store::readJson($path, []);
        $key = self::throttleKey($name);
        $entry = $data[$key] ?? ['count' => 0, 'last' => 0];
        if ((time() - (int) $entry['last']) > self::LOCK_SECONDS) {
            $entry['count'] = 0;
        }
        $entry['count'] = (int) $entry['count'] + 1;
        $entry['last'] = time();
        $data[$key] = $entry;
        foreach ($data as $k => $v) {
            if ((time() - (int) ($v['last'] ?? 0)) > 86400) {
                unset($data[$k]);
            }
        }
        Store::writeJson($path, $data);
    }

    private static function clearFailures(string $name): void
    {
        $path = self::attemptsFile();
        $data = Store::readJson($path, []);
        unset($data[self::throttleKey($name)]);
        Store::writeJson($path, $data);
    }

    public static function attempt(string $name, string $password): void
    {
        $name = trim($name);
        self::assertNotLocked($name);
        $users = Config::all()['users'] ?? [];
        $u = $users[$name] ?? null;
        if ($u === null || !password_verify($password, (string) ($u['hash'] ?? ''))) {
            self::registerFailure($name);
            usleep(250000);
            throw new AppError('Benutzername oder Passwort ist falsch.', 401);
        }
        self::clearFailures($name);
        session_regenerate_id(true);
        $_SESSION['wfx_user'] = ['name' => $name, 'role' => $u['role'] ?? 'viewer'];
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        Journal::write('login', $name, []);
    }

    public static function logout(): void
    {
        $u = self::user();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
        }
        @session_destroy();
        if ($u) {
            Journal::write('logout', $u['name'], []);
        }
    }

    public static function hashPassword(string $password): string
    {
        if (strlen($password) < 10) {
            throw new AppError('Das Passwort muss mindestens 10 Zeichen lang sein.');
        }
        return password_hash($password, PASSWORD_DEFAULT);
    }
}

final class Csrf
{
    public static function token(): string
    {
        if (!isset($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['csrf'];
    }

    public static function check(): void
    {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
        if (!is_string($sent) || $sent === '' || !hash_equals(self::token(), $sent)) {
            throw new AppError('CSRF-Pruefung fehlgeschlagen. Bitte Seite neu laden.', 419);
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '') {
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $oHost = parse_url($origin, PHP_URL_HOST);
            $oPort = parse_url($origin, PHP_URL_PORT);
            $expected = $oHost . ($oPort ? ':' . $oPort : '');
            if ($oHost === null || ($expected !== $host && $oHost !== '127.0.0.1' && $oHost !== 'localhost')) {
                throw new AppError('Ungueltiger Origin.', 403);
            }
        }
    }
}

final class Journal
{
    public static function file(): string
    {
        return Store::data() . '/journal.jsonl';
    }

    public static function write(string $action, ?string $user, array $detail): void
    {
        $path = self::file();
        if (is_file($path) && filesize($path) > 2097152) {
            @rename($path, $path . '.1');
        }
        $user = $user ?? (Auth::user()['name'] ?? 'system');
        $line = json_encode([
            't' => date('c'),
            'u' => $user,
            'a' => $action,
            'd' => $detail,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line !== false) {
            @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    public static function tail(int $limit = 200): array
    {
        $path = self::file();
        if (!is_file($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, -$limit);
        $out = [];
        foreach (array_reverse($lines) as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }
}

function wfx_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function wfx_json(mixed $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
