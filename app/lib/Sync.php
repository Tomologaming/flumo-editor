<?php
declare(strict_types=1);

/**
 * Sync - Abgleich zwischen FTP-Server, lokaler Arbeitskopie (working/)
 * und dem Live-Spiegel (live/ = zuletzt bekannter veroeffentlichter Stand).
 */
final class Sync
{
    public const MAX_FILES = 4000;
    public const MAX_TOTAL = 200000000;
    public const MAX_DIRS = 800;

    /** Dateitypen, die beim Oeffnen einer Seite mitgeladen werden. */
    private const ASSET_EXT = [
        'css', 'js', 'mjs', 'json', 'xml', 'txt', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg',
        'avif', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm', 'pdf', 'map',
    ];

    public static function ftp(): Ftp
    {
        $cfg = Config::source();
        if (($cfg['type'] ?? 'ftp') !== 'ftp') {
            throw new AppError('Als Quelle ist ein lokales Verzeichnis konfiguriert, kein FTP.');
        }
        return new Ftp($cfg);
    }

    /** Zwischendatei mit der Objektliste des Servers. */
    public static function indexFile(): string
    {
        return Store::data() . '/remote-index.json';
    }

    /**
     * Liest die Objektliste des FTP-Servers, ohne eine Datei herunterzuladen.
     * Das Ergebnis wird zwischengespeichert, damit die Oberflaeche ohne
     * erneute FTP-Runde auskommt.
     * @return array{listedAt:string,host:string,root:string,dirs:int,skipped:int,warnings:array,files:array}
     */
    public static function remoteList(?callable $progress = null): array
    {
        Store::init();
        $ftp = self::ftp();
        $ftp->connect();
        $files = [];
        $queue = [''];
        $dirs = 0;
        $skipped = 0;
        $warnings = [];
        while ($queue) {
            $dir = array_shift($queue);
            $entries = $ftp->listDir($dir);
            $dirs++;
            foreach ($entries as $entry) {
                $rel = $dir === '' ? '/' . $entry['name'] : $dir . '/' . $entry['name'];
                if (Store::isHiddenPath($rel) || preg_match('#/(_wfx|\.git|cgi-bin)(/|$)#', $rel)) {
                    $skipped++;
                    continue;
                }
                if ($entry['type'] === 'dir') {
                    if (count($queue) + $dirs > self::MAX_DIRS) {
                        $warnings[] = 'Verzeichnisgrenze (' . self::MAX_DIRS . ') erreicht.';
                        break 2;
                    }
                    $queue[] = $rel;
                    continue;
                }
                if (count($files) >= self::MAX_FILES) {
                    $warnings[] = 'Dateilimit (' . self::MAX_FILES . ') erreicht.';
                    break 2;
                }
                $files[] = [
                    'path' => $rel,
                    'size' => (int) $entry['size'],
                    'ext' => strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION)),
                ];
                if ($progress && count($files) % 50 === 0) {
                    $progress(count($files), 0, $rel);
                }
            }
        }
        $ftp->close();
        usort($files, static fn($a, $b) => strcmp($a['path'], $b['path']));
        $index = [
            'listedAt' => date('c'),
            'host' => (string) (Config::source()['host'] ?? ''),
            'root' => (string) (Config::source()['root'] ?? ''),
            'dirs' => $dirs,
            'skipped' => $skipped,
            'warnings' => $warnings,
            'files' => $files,
        ];
        Store::writeJson(self::indexFile(), $index);
        Journal::write('ftp-list', null, ['files' => count($files), 'dirs' => $dirs, 'root' => $index['root']]);
        return $index;
    }

    /** Zwischengespeicherte Objektliste (leer, wenn noch nie gelistet wurde). */
    public static function remoteIndex(): array
    {
        $file = self::indexFile();
        $empty = ['listedAt' => null, 'host' => '', 'root' => '', 'dirs' => 0, 'skipped' => 0, 'warnings' => [], 'files' => []];
        if (!is_file($file)) {
            return $empty;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !isset($data['files'])) {
            return $empty;
        }
        return $data + $empty;
    }

    /**
     * Laedt genau eine Datei - beim Oeffnen einer Seite samt der Dateien,
     * auf die sie verweist - in die Arbeitskopie. Alles andere bleibt auf dem Server.
     * @return array{path:string,files:array,bytes:int,failed:array}
     */
    public static function fetchFile(string $rel): array
    {
        Store::init();
        $rel = Store::normRel($rel, true);
        if ($rel === '/' || str_ends_with($rel, '/')) {
            throw new AppError('Fuer Verzeichnisse wird keine Datei geladen.', 422);
        }
        $ftp = self::ftp();
        $ftp->connect();
        $fetched = [];
        $failed = [];
        $bytes = 0;
        try {
            $data = $ftp->get($rel, 12000000);
        } catch (AppError $e) {
            $ftp->close();
            throw $e;
        }
        Store::writeAtomic($rel, $data, Store::working());
        Store::writeAtomic($rel, $data, Store::live());
        $fetched[] = $rel;
        $bytes += strlen($data);

        $queue = [];
        if (in_array(Store::extOf($rel), ['html', 'htm', 'php'], true)) {
            $queue = self::assetRefs($data, Store::dirOf($rel));
        }
        while ($queue) {
            if (count($fetched) > 40 || $bytes > 8000000) {
                break;
            }
            $asset = array_shift($queue);
            if (!self::isAssetPath($asset) || Store::exists($asset, Store::working())) {
                continue;
            }
            try {
                $content = $ftp->get($asset, 6000000);
            } catch (AppError $e) {
                $failed[] = ['file' => $asset, 'error' => $e->getMessage()];
                continue;
            }
            Store::writeAtomic($asset, $content, Store::working());
            Store::writeAtomic($asset, $content, Store::live());
            $fetched[] = $asset;
            $bytes += strlen($content);
            if (Store::extOf($asset) === 'css') {
                foreach (self::assetRefs($content, Store::dirOf($asset)) as $next) {
                    $queue[] = $next;
                }
            }
        }
        $ftp->close();
        Journal::write('ftp-fetch', null, ['page' => $rel, 'files' => count($fetched)]);
        return ['path' => $rel, 'files' => $fetched, 'bytes' => $bytes, 'failed' => $failed];
    }

    private static function isAssetPath(string $rel): bool
    {
        return in_array(Store::extOf($rel), self::ASSET_EXT, true);
    }

    /**
     * Dateiverweise aus HTML/CSS (src, href, poster, url(...)) als Pfade der Arbeitskopie.
     * @return string[]
     */
    private static function assetRefs(string $source, string $dir): array
    {
        $raw = [];
        if (preg_match_all('/\b(?:src|href|poster)\s*=\s*["\']([^"\']+)["\']/i', $source, $m)) {
            $raw = array_merge($raw, $m[1]);
        }
        if (preg_match_all('/url\(\s*["\']?([^"\')\s]+)["\']?\s*\)/i', $source, $m2)) {
            $raw = array_merge($raw, $m2[1]);
        }
        $out = [];
        foreach ($raw as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '' || $ref[0] === '#' || str_starts_with($ref, 'data:') || str_starts_with($ref, '//')
                || preg_match('#^(mailto|tel|javascript|ftp|https?):#i', $ref)) {
                continue;
            }
            $cut = strcspn($ref, '?#');
            $ref = substr($ref, 0, $cut);
            if ($ref === '') {
                continue;
            }
            $full = str_starts_with($ref, '/') ? $ref : ($dir === '/' ? '/' . $ref : $dir . '/' . $ref);
            $parts = [];
            foreach (explode('/', str_replace('\\', '/', $full)) as $seg) {
                if ($seg === '' || $seg === '.') {
                    continue;
                }
                if ($seg === '..') {
                    array_pop($parts);
                    continue;
                }
                $parts[] = $seg;
            }
            if ($parts) {
                $out[] = '/' . implode('/', $parts);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Laedt den kompletten Bestand per FTP herunter.
     * Eine nicht lesbare Datei (z. B. 550, weil sie auf dem Server fehlt)
     * bricht den Import nicht ab, sondern wird als Warnung gemeldet.
     * @return array{files:int,bytes:int,dirs:int,skipped:int,failed:array,warnings:array}
     */
    public static function pullFromFtp(?callable $progress = null): array
    {
        Store::init();
        $ftp = self::ftp();
        $ftp->connect();
        $root = (string) (Config::source()['root'] ?? '');
        $collected = [];
        $queue = [''];
        $dirs = 0;
        $skipped = 0;
        $warnings = [];
        while ($queue) {
            $dir = array_shift($queue);
            $entries = $ftp->listDir($dir);
            $dirs++;
            foreach ($entries as $entry) {
                $rel = $dir === '' ? '/' . $entry['name'] : $dir . '/' . $entry['name'];
                if (Store::isHiddenPath($rel) || preg_match('#/(_wfx|\.git|cgi-bin)(/|$)#', $rel)) {
                    $skipped++;
                    continue;
                }
                if ($entry['type'] === 'dir') {
                    if (count($queue) + $dirs > 500) {
                        $warnings[] = 'Verzeichnisgrenze erreicht, Rest wurde uebersprungen.';
                        break 2;
                    }
                    $queue[] = $rel;
                    continue;
                }
                if (count($collected) >= self::MAX_FILES) {
                    $warnings[] = 'Dateilimit (' . self::MAX_FILES . ') erreicht.';
                    break 2;
                }
                $collected[] = $rel;
            }
        }

        $bytes = 0;
        $files = 0;
        $i = 0;
        $failed = [];
        foreach ($collected as $rel) {
            $i++;
            if ($progress) {
                $progress($i, count($collected), $rel);
            }
            try {
                $data = $ftp->get($rel, 12000000);
            } catch (AppError $e) {
                $failed[] = ['file' => $rel, 'error' => $e->getMessage()];
                $warnings[] = 'Uebersprungen: ' . $rel . ' (' . $e->getMessage() . ')';
                continue;
            }
            $bytes += strlen($data);
            if ($bytes > self::MAX_TOTAL) {
                $warnings[] = 'Gesamtgroesse ueberschritten, Rest wurde uebersprungen.';
                break;
            }
            Store::writeAtomic($rel, $data, Store::working());
            Store::writeAtomic($rel, $data, Store::live());
            $files++;
        }
        $ftp->close();
        Config::set('lastPull', date('c'));
        Config::save(array_merge(Config::all(), ['lastPull' => date('c')]));
        Journal::write('ftp-pull', null, [
            'files' => $files,
            'bytes' => $bytes,
            'root' => $root,
            'failed' => array_slice($failed, 0, 20),
        ]);
        return [
            'files' => $files,
            'bytes' => $bytes,
            'dirs' => $dirs,
            'skipped' => $skipped,
            'failed' => $failed,
            'warnings' => $warnings,
        ];
    }

    /**
     * Importiert ein lokales Verzeichnis (z. B. FileZilla-Download oder GitHub-Klon).
     * @return array{files:int,bytes:int}
     */
    public static function importLocal(string $dir): array
    {
        Store::init();
        $dir = trim($dir, " \t\"'");
        if ($dir === '' || !is_dir($dir)) {
            throw new AppError('Verzeichnis nicht gefunden: ' . $dir);
        }
        $real = realpath($dir);
        if ($real === false) {
            throw new AppError('Verzeichnis nicht lesbar.');
        }
        if (Store::inside($real, Store::ws()) || Store::inside(Store::root(), $real)) {
            if (Store::inside($real, Store::ws())) {
                throw new AppError('Der Editor-Arbeitsordner kann nicht als Quelle importiert werden.');
            }
        }
        $files = 0;
        $bytes = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if ($file->isLink()) {
                continue;
            }
            $rel = '/' . ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($real))), '/');
            if (Store::isHiddenPath($rel)) {
                continue;
            }
            if ($file->isDir()) {
                continue;
            }
            if ($files >= self::MAX_FILES) {
                throw new AppError('Zu viele Dateien im Quellverzeichnis (Limit ' . self::MAX_FILES . ').');
            }
            $size = $file->getSize();
            if ($size > 12000000) {
                continue;
            }
            $data = file_get_contents($file->getPathname());
            if ($data === false) {
                continue;
            }
            $bytes += strlen($data);
            Store::writeAtomic($rel, $data, Store::working());
            Store::writeAtomic($rel, $data, Store::live());
            $files++;
        }
        Config::set('lastPull', date('c'));
        Journal::write('local-import', null, ['dir' => $real, 'files' => $files]);
        return ['files' => $files, 'bytes' => $bytes];
    }

    /**
     * Laedt eine einzelne Remote-Datei in ein Backup-Verzeichnis (Rueckfallebene).
     */
    public static function fetchRemoteToBackup(Ftp $ftp, string $rel, string $backupDir): bool
    {
        try {
            $data = $ftp->get($rel, 12000000);
        } catch (AppError $e) {
            return false;
        }
        Store::writeAtomic($rel, $data, $backupDir);
        return true;
    }

    /**
     * Uebertraegt Dateien aus der Arbeitskopie auf den FTP-Server
     * und prueft jede Uebertragung nach.
     * @param string[] $files
     * @return array{uploaded:array,deleted:array,failed:array,warnings:array}
     */
    public static function pushFiles(array $files, array $deleteFiles = [], ?callable $progress = null): array
    {
        $cfg = Config::source();
        if (($cfg['type'] ?? 'ftp') !== 'ftp') {
            return ['uploaded' => [], 'deleted' => [], 'failed' => [], 'warnings' => ['Kein FTP konfiguriert - es wurde nur lokal veroeffentlicht.']];
        }
        $ftp = self::ftp();
        $ftp->connect();
        $uploaded = [];
        $deleted = [];
        $failed = [];
        $warnings = [];
        $total = count($files) + count($deleteFiles);
        $i = 0;
        foreach ($files as $rel) {
            $i++;
            if ($progress) {
                $progress($i, $total, $rel);
            }
            $content = Store::read($rel, Store::working());
            $remote = $rel;
            $dir = Store::dirOf($remote);
            if ($dir !== '/') {
                $ftp->mkdRecursive($dir);
            }
            $tmpName = $remote . '.wfx-tmp';
            try {
                $ftp->put($tmpName, $content);
                $ftp->rename($tmpName, $remote);
                $size = $ftp->size($remote);
                if ($size !== null && $size !== strlen($content)) {
                    throw new AppError('Groessenpruefung fehlgeschlagen (' . $size . ' != ' . strlen($content) . ')');
                }
                if (strlen($content) <= 524288) {
                    $back = $ftp->get($remote, 12000000);
                    if (sha1($back) !== sha1($content)) {
                        throw new AppError('Pruefsumme nach Upload weicht ab.');
                    }
                }
                $uploaded[] = $rel;
            } catch (AppError $e) {
                $failed[] = ['file' => $rel, 'error' => $e->getMessage()];
            }
        }
        foreach ($deleteFiles as $rel) {
            $i++;
            if ($progress) {
                $progress($i, $total, $rel);
            }
            try {
                $ftp->delete($rel);
                $deleted[] = $rel;
            } catch (AppError $e) {
                $failed[] = ['file' => $rel, 'error' => $e->getMessage()];
            }
        }
        $ftp->close();
        if ($failed) {
            $warnings[] = count($failed) . ' Datei(en) konnten nicht uebertragen werden.';
        }
        Journal::write('ftp-push', null, [
            'uploaded' => count($uploaded),
            'deleted' => count($deleted),
            'failed' => $failed,
        ]);
        return ['uploaded' => $uploaded, 'deleted' => $deleted, 'failed' => $failed, 'warnings' => $warnings];
    }

    /** Seitenbaum der Arbeitskopie. */
    public static function pages(): array
    {
        $files = Store::listFiles(Store::working());
        $pages = [];
        foreach ($files as $f) {
            $ext = Store::extOf($f['path']);
            if (!in_array($ext, ['html', 'htm', 'php'], true)) {
                continue;
            }
            if (preg_match('#^/editor/#', $f['path'])) {
                continue;
            }
            $lang = self::guessLang($f['path']);
            $pages[] = [
                'path' => $f['path'],
                'name' => basename($f['path']),
                'dir' => Store::dirOf($f['path']),
                'ext' => $ext,
                'lang' => $lang,
                'slug' => self::slugOf($f['path']),
                'size' => $f['size'],
                'mtime' => $f['mtime'],
                'draft' => Store::hashOf($f['path'], Store::working()) !== Store::hashOf($f['path'], Store::live()),
            ];
        }
        usort($pages, static fn($a, $b) => strcmp($a['path'], $b['path']));
        return $pages;
    }

    public static function guessLang(string $rel): string
    {
        $dir = Store::dirOf($rel);
        $first = trim(explode('/', ltrim($dir, '/'))[0] ?? '', '/');
        if (preg_match('/^[a-z]{2}(-[a-z]{2})?$/i', $first)) {
            return strtolower($first);
        }
        $base = basename($rel);
        if (preg_match('/[-_.]([a-z]{2})\.(html?|php)$/i', $base, $m)) {
            return strtolower($m[1]);
        }
        if (is_file(Store::working() . str_replace('/', DIRECTORY_SEPARATOR, $rel))) {
            $head = (string) @file_get_contents(Store::working() . str_replace('/', DIRECTORY_SEPARATOR, $rel), false, null, 0, 2048);
            if (preg_match('/<html[^>]*\blang\s*=\s*["\']([a-z-]{2,8})["\']/i', $head, $m)) {
                return strtolower($m[1]);
            }
        }
        return 'und';
    }

    public static function slugOf(string $rel): string
    {
        $dir = Store::dirOf($rel);
        $segments = array_values(array_filter(explode('/', trim($dir, '/')), static fn($s) => $s !== ''));
        if ($segments && preg_match('/^[a-z]{2}(-[a-z]{2})?$/i', $segments[0])) {
            array_shift($segments);
        }
        $base = preg_replace('/\.(html?|php)$/i', '', basename($rel)) ?? basename($rel);
        $base = preg_replace('/[-_.](de|en|fr|es|it|nl|pl|tr|ru)$/i', '', $base) ?? $base;
        if (in_array($base, ['index', 'start', 'home'], true)) {
            $base = '';
        }
        $segments[] = $base;
        $slug = implode('/', array_filter($segments, static fn($s) => $s !== ''));
        return $slug === '' ? 'startseite' : $slug;
    }

    public static function stats(): array
    {
        $pages = self::pages();
        $dirty = 0;
        foreach ($pages as $p) {
            if ($p['draft']) {
                $dirty++;
            }
        }
        $all = Store::listFiles(Store::working());
        $changed = Store::diff(Store::working(), Store::live());
        return [
            'pages' => count($pages),
            'files' => count($all),
            'drafts' => count($changed),
            'dirtyPages' => $dirty,
            'lastPull' => Config::all()['lastPull'] ?? null,
            'lastPublish' => Config::all()['lastPublish'] ?? null,
            'sourceType' => Config::source()['type'] ?? 'ftp',
            'ftpHost' => Config::source()['host'] ?? '',
            'baseUrl' => Config::all()['baseUrl'] ?? '',
        ];
    }
}
