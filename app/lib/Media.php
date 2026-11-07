<?php
declare(strict_types=1);

/**
 * Media - Medienbibliothek fuer eigene Uploads.
 *
 * In der Bibliothek erscheinen ausschliesslich Dateien aus /assets/media
 * (also das, was ueber den Editor hochgeladen wurde). Die Bilder der Website
 * selbst werden bewusst NICHT indexiert.
 *
 * Bilder wandelt der Browser beim Hochladen zu WebP (Canvas) - dieses PHP hat
 * kein GD/Imagick. Der Server prueft nur Typ und Groesse und legt die Datei ab.
 * Videos: maximal 5 MB, ohne Umwandlung.
 */
final class Media
{
    public const EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico'];
    public const VIDEO_EXT = ['mp4', 'webm'];
    public const DIR = '/assets/media';
    public const MAX_VIDEO_BYTES = 5242880;      // 5 MB
    public const MAX_IMAGE_BYTES = 10485760;     // 10 MB
    private const MAX_CLOUD_BYTES = 6000000;
    private const MAX_CLOUD_FILES = 300;

    /** Nur Dateien in der Bibliothek (/assets/media/...). */
    public static function isLibraryPath(string $path): bool
    {
        $p = '/' . ltrim(str_replace('\\', '/', $path), '/');
        return str_starts_with(strtolower($p), strtolower(self::DIR) . '/');
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024) . ' KB';
        }
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }

    /** @return array{items:array,cloud:array,count:int,limits:array} */
    public static function index(): array
    {
        $usage = self::usageMap();
        $items = [];
        foreach (Store::listFiles(Store::working()) as $f) {
            $ext = Store::extOf($f['path']);
            $isVideo = in_array($ext, self::VIDEO_EXT, true);
            $isImage = in_array($ext, self::EXT, true);
            if ((!$isImage && !$isVideo) || !self::isLibraryPath($f['path'])) {
                continue;
            }
            $usages = $usage[$f['path']] ?? [];
            $items[] = [
                'path' => $f['path'],
                'name' => basename($f['path']),
                'dir' => Store::dirOf($f['path']),
                'size' => $f['size'],
                'mtime' => $f['mtime'],
                'ext' => $ext,
                'type' => $isVideo ? 'video' : 'image',
                'usages' => $usages,
                'pages' => count($usages),
            ];
        }
        usort($items, static function ($a, $b) {
            if ($a['pages'] === $b['pages']) {
                return strcmp($a['path'], $b['path']);
            }
            return $b['pages'] <=> $a['pages'];
        });
        return [
            'items' => $items,
            'cloud' => Supabase::status(),
            'count' => count($items),
            'limits' => ['videoBytes' => self::MAX_VIDEO_BYTES, 'imageBytes' => self::MAX_IMAGE_BYTES],
        ];
    }

    /**
     * Fundstellen je Bibliotheksdatei.
     * @return array<string,array<int,array{page:string,count:int}>>
     */
    public static function usageMap(): array
    {
        $media = [];
        $pages = [];
        foreach (Store::listFiles(Store::working()) as $f) {
            $ext = Store::extOf($f['path']);
            if ((in_array($ext, self::EXT, true) || in_array($ext, self::VIDEO_EXT, true)) && self::isLibraryPath($f['path'])) {
                $media[$f['path']] = true;
            } elseif (in_array($ext, ['html', 'htm', 'php', 'css'], true)) {
                $pages[] = $f['path'];
            }
        }
        $out = [];
        foreach ($pages as $page) {
            try {
                $src = Store::read($page, Store::working());
            } catch (Throwable $e) {
                continue;
            }
            $counts = [];
            foreach (self::refs($src, $page) as $ref) {
                if (!isset($media[$ref])) {
                    continue;
                }
                $counts[$ref] = ($counts[$ref] ?? 0) + 1;
            }
            foreach ($counts as $ref => $count) {
                $out[$ref][] = ['page' => $page, 'count' => $count];
            }
        }
        return $out;
    }

    /** @return array<int,string> aufgeloeste Verweise einer Datei */
    private static function refs(string $src, string $page): array
    {
        preg_match_all(
            '/(?:src|href|poster|data-src)\s*=\s*["\']([^"\']+)["\']|url\(\s*["\']?([^"\')]+)["\']?\s*\)/i',
            $src,
            $m,
            PREG_SET_ORDER
        );
        $refs = [];
        foreach ($m as $hit) {
            $ref = trim((string) ($hit[1] ?? ''));
            if ($ref === '') {
                $ref = trim((string) ($hit[2] ?? ''));
            }
            if ($ref === '' || preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|\#)#i', $ref)) {
                continue;
            }
            $resolved = self::resolve($ref, $page);
            if ($resolved !== '') {
                $refs[] = $resolved;
            }
        }
        return array_values(array_unique($refs));
    }

    /** Loest einen relativen Verweis gegen die Datei auf ("/de/a.html" + "../img/x.png"). */
    public static function resolve(string $ref, string $fromFile): string
    {
        $ref = preg_replace('/[?#].*$/s', '', $ref) ?? '';
        $ref = str_replace('\\', '/', trim($ref));
        if ($ref === '') {
            return '';
        }
        if (!str_starts_with($ref, '/')) {
            $dir = Store::dirOf($fromFile);
            $ref = ($dir === '/' ? '' : $dir) . '/' . $ref;
        }
        $parts = [];
        foreach (explode('/', $ref) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        return $parts ? '/' . implode('/', $parts) : '';
    }

    /** Bibliotheksdatei (Bild oder Video) an der aktuellen Auswahl einfuegen. */
    public static function insert(array $body): array
    {
        Auth::requirePerm('edit');
        $file = Store::normRel((string) ($body['file'] ?? ''));
        Store::assertEditable($file);
        $src = Store::normRel((string) ($body['src'] ?? ''));
        if (!Store::exists($src, Store::working())) {
            throw new AppError('Datei nicht gefunden: ' . $src, 404);
        }
        $ext = Store::extOf($src);
        $isVideo = in_array($ext, self::VIDEO_EXT, true);
        if (!$isVideo && !in_array($ext, self::EXT, true)) {
            throw new AppError('Nur Bilder und Videos koennen eingesetzt werden.', 415);
        }
        $alt = trim((string) ($body['alt'] ?? ''));
        $class = trim((string) ($body['class'] ?? ''));
        $tag = $isVideo ? 'video' : 'img';
        $doc = new HtmlDoc(Store::read($file));
        $parentPath = (array) ($body['parentPath'] ?? []);
        $index = $body['index'] ?? null;
        $parent = $doc->nodeByPath($parentPath);

        // Anker: nach dem gewaehlten Element oder als Kind eines Containers.
        if (!empty($body['afterPath'])) {
            $afterPath = (array) $body['afterPath'];
            $after = $doc->nodeByPath($afterPath);
            $parentPath = array_slice($afterPath, 0, -1);
            $index = (int) end($afterPath) + 1;
            $parent = $doc->nodeByPath($parentPath);
        }

        if ($doc->isLocked($parent)) {
            throw new AppError('An dieser Stelle kann nichts eingefuegt werden.', 422);
        }
        if (!$doc->canContain($parent['tag'], $tag)) {
            $parentPath = array_slice($parentPath, 0, -1);
            if (!$parentPath) {
                throw new AppError('Bitte ein Element auswaehlen, in dem ein ' . $tag . ' stehen kann.', 422);
            }
            $parent = $doc->nodeByPath($parentPath);
            $index = null;
            if (!$doc->canContain($parent['tag'], $tag)) {
                throw new AppError('Bitte ein Element auswaehlen, in dem ein ' . $tag . ' stehen kann.', 422);
            }
        }

        $attrs = ' src="' . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        if (!$isVideo) {
            $attrs .= ' alt="' . htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            $attrs .= ' loading="lazy"';
        }
        if ($class !== '') {
            $attrs .= ' class="' . htmlspecialchars($class, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        $html = $isVideo ? '<video' . $attrs . ' controls playsinline></video>' : '<img' . $attrs . '>';
        $op = [
            'kind' => 'insert',
            'parentPath' => $parentPath,
            'parentTags' => $doc->tagsOf($parent['id']),
            'index' => $index,
            'html' => $html,
        ];
        $res = $doc->applyOps([$op]);
        Store::writeAtomic($file, $doc->src, Store::working());
        Journal::write('media-insert', null, ['file' => $file, 'src' => $src]);
        $cloud = self::syncOne($src);
        return [
            'etag' => sha1($doc->src),
            'inverse' => $res['inverse'],
            'warnings' => $res['warnings'],
            'cloud' => $cloud,
        ];
    }

    /**
     * Upload in die Bibliothek. Der Browser liefert Bilder bereits als WebP,
     * hier werden nur Typ und Groesse geprueft und die Datei abgelegt.
     */
    public static function upload(): array
    {
        Auth::requirePerm('edit');
        if (!isset($_FILES['file'])) {
            throw new AppError('Keine Datei empfangen.');
        }
        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new AppError('Upload-Fehler (Code ' . (int) $file['error'] . '). Moeglicherweise ist die Datei zu gross.', 413);
        }
        $name = preg_replace('/[^A-Za-z0-9._-]/', '-', basename((string) $file['name'])) ?? 'datei';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $isVideo = in_array($ext, self::VIDEO_EXT, true);
        $isImage = in_array($ext, self::EXT, true);
        if (!$isImage && !$isVideo) {
            throw new AppError('Erlaubt sind Bilder (PNG, JPG, GIF, WebP, AVIF, SVG) und Videos (MP4, WebM).', 415);
        }
        $tmp = (string) $file['tmp_name'];
        $size = (int) ($file['size'] ?? 0);
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);

        if ($isVideo) {
            if (!in_array($mime, ['video/mp4', 'video/webm'], true)) {
                throw new AppError('Dateiinhalt (' . $mime . ') ist kein unterstuetztes Video.', 415);
            }
            if ($size > self::MAX_VIDEO_BYTES) {
                throw new AppError('Videos duerfen hoechstens 5 MB gross sein (diese Datei: ' . self::humanSize($size) . ').', 413);
            }
        } else {
            $allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml', 'image/avif', 'image/x-icon'];
            if (!in_array($mime, $allowed, true)) {
                throw new AppError('Dateiinhalt (' . $mime . ') ist kein erlaubtes Bildformat.', 415);
            }
            if ($size > self::MAX_IMAGE_BYTES) {
                throw new AppError('Bilder duerfen hoechstens 10 MB gross sein (diese Datei: ' . self::humanSize($size) . ').', 413);
            }
        }

        $content = file_get_contents($tmp);
        if ($content === false || $content === '') {
            throw new AppError('Datei konnte nicht gelesen werden.', 413);
        }
        if (str_starts_with($mime, 'image/svg') && preg_match('/<\s*script/i', $content)) {
            throw new AppError('SVG mit Skriptinhalt wird abgelehnt.', 415);
        }

        $dir = (string) ($_POST['dir'] ?? self::DIR);
        $dir = Store::normRel($dir === '' ? self::DIR : $dir);
        $target = rtrim($dir, '/') . '/' . $name;
        $i = 2;
        while (Store::exists($target, Store::working())) {
            $target = rtrim($dir, '/') . '/' . pathinfo($name, PATHINFO_FILENAME) . '-' . $i . '.' . $ext;
            $i++;
        }
        Store::writeAtomic($target, $content, Store::working());
        Journal::write('media-upload', null, ['file' => $target, 'size' => strlen($content), 'mime' => $mime]);
        $cloud = self::syncOne($target, $content, $mime);
        return [
            'path' => $target,
            'size' => strlen($content),
            'mime' => $mime,
            'type' => $isVideo ? 'video' : 'image',
            'cloud' => $cloud,
        ];
    }

    /** Einzelne Datei nach Supabase spiegeln (Datei + Fundstellen). */
    public static function syncOne(string $path, ?string $bytes = null, ?string $mime = null): array
    {
        if (!Supabase::active()) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'Supabase nicht aktiv'];
        }
        try {
            if ($bytes === null) {
                $bytes = Store::read($path, Store::working());
            }
            if (strlen($bytes) > self::MAX_CLOUD_BYTES) {
                return ['ok' => false, 'skipped' => true, 'reason' => 'Datei groesser als 6 MB'];
            }
            if ($mime === null) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string) $finfo->buffer($bytes);
            }
            $object = 'media' . $path;
            $up = Supabase::upload($object, $bytes, (string) $mime);
            if (!$up['ok']) {
                return ['ok' => false, 'error' => $up['error'] ?? 'Upload fehlgeschlagen'];
            }
            $usage = self::usageMap()[$path] ?? [];
            $row = [[
                'path' => $path,
                'bucket' => Supabase::status()['bucket'],
                'storage_path' => ltrim($object, '/'),
                'size' => strlen($bytes),
                'mime' => (string) $mime,
                'usages' => $usage,
                'updated_at' => date('c'),
            ]];
            $res = Supabase::upsertRows($row);
            return [
                'ok' => $res['ok'],
                'error' => $res['ok'] ? '' : ($res['error'] ?? ''),
                'usages' => count($usage),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Alle Bibliotheksdateien abgleichen. */
    public static function syncAll(): array
    {
        Auth::requirePerm('edit');
        if (!Supabase::active()) {
            throw new AppError('Supabase ist nicht aktiv. Bitte in den Einstellungen aktivieren und speichern.', 422);
        }
        $usage = self::usageMap();
        $rows = [];
        $uploaded = 0;
        $errors = [];
        $bytesTotal = 0;
        $files = 0;
        foreach (Store::listFiles(Store::working()) as $f) {
            if (!self::isLibraryPath($f['path'])) {
                continue;
            }
            if (!in_array(Store::extOf($f['path']), array_merge(self::EXT, self::VIDEO_EXT), true)) {
                continue;
            }
            if ($files >= self::MAX_CLOUD_FILES || $bytesTotal > 25000000) {
                $errors[] = 'Grenze erreicht: nur ' . $files . ' Dateien bzw. 25 MB pro Abgleich.';
                break;
            }
            $content = Store::read($f['path'], Store::working());
            $files++;
            $bytesTotal += strlen($content);
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->buffer($content);
            $object = 'media' . $f['path'];
            $up = Supabase::upload($object, $content, $mime);
            if ($up['ok']) {
                $uploaded++;
            } else {
                $errors[] = $f['path'] . ': ' . ($up['error'] ?? 'Upload fehlgeschlagen');
                continue;
            }
            $rows[] = [
                'path' => $f['path'],
                'bucket' => Supabase::status()['bucket'],
                'storage_path' => ltrim($object, '/'),
                'size' => $f['size'],
                'mime' => $mime,
                'usages' => $usage[$f['path']] ?? [],
                'updated_at' => date('c'),
            ];
        }
        $res = Supabase::upsertRows($rows);
        Journal::write('media-sync', null, ['files' => $uploaded, 'errors' => count($errors)]);
        return [
            'uploaded' => $uploaded,
            'rows' => count($rows),
            'errors' => $errors,
            'table' => $res['ok'] ? 'aktualisiert' : ('Fehler: ' . ($res['error'] ?? '')),
        ];
    }
}
