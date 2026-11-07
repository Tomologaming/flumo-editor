<?php
declare(strict_types=1);

/**
 * Api - alle JSON-Endpunkte. Jede Aenderung wird serverseitig autorisiert,
 * CSRF-geprueft und protokolliert.
 */
final class Api
{
    private static array $body = [];

    public static function dispatch(string $method, string $path): void
    {
        Store::init();
        $route = preg_replace('#^/api/#', '', $path) ?? '';
        $route = trim($route, '/');
        try {
            if ($method === 'POST') {
                Auth::requireLogin();
                Csrf::check();
                self::$body = self::parseBody();
            }
            switch ($route) {
                case 'state':
                    Auth::requireLogin();
                    self::state();
                    return;
                case 'info':
                    Auth::requireLogin();
                    self::info();
                    return;
                case 'edit':
                    Auth::requirePerm('edit');
                    self::edit();
                    return;
                case 'save-file':
                    Auth::requirePerm('edit');
                    self::saveFile();
                    return;
                case 'file':
                    Auth::requireLogin();
                    self::fileContent();
                    return;
                case 'diff':
                    Auth::requireLogin();
                    self::diff();
                    return;
                case 'validate':
                    Auth::requireLogin();
                    self::validate();
                    return;
                case 'changes':
                    Auth::requireLogin();
                    wfx_json(['ok' => true, 'data' => Publish::preview()]);
                    return;
                case 'publish':
                    Auth::requirePerm('publish');
                    self::publish();
                    return;
                case 'backups':
                    Auth::requireLogin();
                    wfx_json(['ok' => true, 'backups' => Publish::backups()]);
                    return;
                case 'backup-detail':
                    Auth::requireLogin();
                    wfx_json(['ok' => true, 'data' => Publish::backupDetail((string) (self::$body['id'] ?? ''))]);
                    return;
                case 'rollback':
                    Auth::requirePerm('restore');
                    wfx_json(['ok' => true, 'data' => Publish::rollback(
                        (string) (self::$body['id'] ?? ''),
                        (string) (self::$body['target'] ?? 'both')
                    )]);
                    return;
                case 'remote-index':
                    Auth::requireLogin();
                    self::remoteIndex();
                    return;
                case 'remote-list':
                    Auth::requirePerm('sync');
                    wfx_json(['ok' => true, 'data' => Sync::remoteList()]);
                    return;
                case 'fetch-file':
                    Auth::requirePerm('sync');
                    wfx_json(['ok' => true, 'data' => Sync::fetchFile((string) self::body('path', ''))]);
                    return;
                case 'sync-pull':
                    Auth::requirePerm('sync');
                    wfx_json(['ok' => true, 'data' => Sync::pullFromFtp()]);
                    return;
                case 'import-local':
                    Auth::requirePerm('sync');
                    wfx_json(['ok' => true, 'data' => Sync::importLocal((string) (self::$body['dir'] ?? ''))]);
                    return;
                case 'ftp-test':
                    Auth::requirePerm('sync');
                    $cfg = array_merge(Config::source(), (array) (self::$body['source'] ?? []));
                    $ftp = new Ftp($cfg);
                    wfx_json(['ok' => true, 'data' => $ftp->test()]);
                    return;
                case 'components':
                    Auth::requireLogin();
                    wfx_json(['ok' => true, 'data' => Components::all()]);
                    return;
                case 'component-save':
                    Auth::requirePerm('components');
                    wfx_json(['ok' => true, 'item' => Components::save(self::$body)]);
                    return;
                case 'component-delete':
                    Auth::requirePerm('components');
                    Components::delete((string) (self::$body['slug'] ?? ''));
                    wfx_json(['ok' => true]);
                    return;
                case 'component-impact':
                    Auth::requireLogin();
                    wfx_json(['ok' => true, 'data' => Components::impact((string) (self::$body['slug'] ?? ''))]);
                    return;
                case 'component-from-selection':
                    Auth::requirePerm('components');
                    self::componentFromSelection();
                    return;
                case 'component-insert':
                    Auth::requirePerm('edit');
                    self::componentInsert();
                    return;
                case 'component-detach':
                    Auth::requirePerm('edit');
                    Components::detach((string) (self::$body['file'] ?? ''), (array) (self::$body['path'] ?? []));
                    wfx_json(['ok' => true]);
                    return;
                case 'component-restore':
                    Auth::requirePerm('components');
                    self::componentRestore();
                    return;
                case 'component-update-definition':
                    Auth::requirePerm('components');
                    self::componentUpdateDefinition();
                    return;
                case 'languages':
                    Auth::requireLogin();
                    wfx_json(['ok' => true, 'data' => Languages::all(), 'detected' => Languages::detect(), 'status' => Languages::statusAll()]);
                    return;
                case 'lang-link':
                    Auth::requirePerm('edit');
                    wfx_json(['ok' => true, 'page' => Languages::link(
                        (string) (self::$body['label'] ?? ''),
                        (array) (self::$body['versions'] ?? [])
                    )]);
                    return;
                case 'lang-unlink':
                    Auth::requirePerm('edit');
                    Languages::unlink((string) (self::$body['id'] ?? ''));
                    wfx_json(['ok' => true]);
                    return;
                case 'lang-status':
                    Auth::requireLogin();
                    wfx_json(['ok' => true, 'data' => Languages::status((string) (self::$body['id'] ?? ''))]);
                    return;
                case 'lang-compare':
                    Auth::requireLogin();
                    wfx_json(['ok' => true, 'data' => Languages::compare(
                        (string) (self::$body['id'] ?? ''),
                        (string) (self::$body['a'] ?? ''),
                        (string) (self::$body['b'] ?? '')
                    )]);
                    return;
                case 'lang-snapshot':
                    Auth::requirePerm('edit');
                    wfx_json(['ok' => true, 'data' => Languages::snapshot((string) (self::$body['id'] ?? ''))]);
                    return;
                case 'lang-partners':
                    Auth::requireLogin();
                    wfx_json(['ok' => true, 'data' => Languages::partners(
                        Store::normRel((string) (self::$body['file'] ?? '')),
                        (string) (self::$body['tid'] ?? '')
                    )]);
                    return;
                case 'journal':
                    Auth::requireLogin();
                    wfx_json(['ok' => true, 'entries' => Journal::tail(200)]);
                    return;
                case 'tree':
                    Auth::requireLogin();
                    self::tree();
                    return;
                case 'responsive':
                    Auth::requirePerm('edit');
                    wfx_json(['ok' => true, 'data' => Responsive::update(
                        Store::normRel((string) self::body('file', '')),
                        (array) self::body('path', []),
                        (array) self::body('tags', []),
                        (string) self::body('breakpoint', 'tablet'),
                        (string) self::body('prop', ''),
                        self::body('value')
                    )]);
                    return;
                case 'responsive-rules':
                    Auth::requireLogin();
                    wfx_json(['ok' => true, 'data' => Responsive::rulesFor(
                        Store::normRel((string) self::body('file', '')),
                        (array) self::body('path', [])
                    )]);
                    return;
                case 'backup-view':
                    Auth::requireLogin();
                    wfx_json(['ok' => true, 'data' => Publish::backupView(
                        (string) (self::$body['id'] ?? ''),
                        (string) (self::$body['file'] ?? '')
                    )]);
                    return;
                case 'component-export':
                    Auth::requireLogin();
                    wfx_json(['ok' => true, 'data' => Components::export(
                        array_values(array_filter((array) (self::$body['slugs'] ?? [])))
                    )]);
                    return;
                case 'component-import':
                    Auth::requirePerm('components');
                    wfx_json(['ok' => true, 'data' => Components::import(
                        (array) (self::$body['payload'] ?? []),
                        (bool) (self::$body['overwrite'] ?? false)
                    )]);
                    return;
                case 'media-list':
                    Auth::requireLogin();
                    wfx_json(['ok' => true, 'data' => Media::index()]);
                    return;
                case 'media-usage':
                    Auth::requireLogin();
                    $usage = Media::usageMap();
                    wfx_json(['ok' => true, 'data' => $usage[(string) (self::$body['path'] ?? '')] ?? []]);
                    return;
                case 'media-insert':
                    Auth::requirePerm('edit');
                    wfx_json(['ok' => true, 'data' => Media::insert(self::$body)]);
                    return;
                case 'media-upload':
                    Auth::requirePerm('edit');
                    wfx_json(['ok' => true, 'data' => Media::upload()]);
                    return;
                case 'media-sync':
                    Auth::requirePerm('edit');
                    wfx_json(['ok' => true, 'data' => Media::syncAll()]);
                    return;
                case 'supabase-test':
                    Auth::requirePerm('manage');
                    wfx_json(['ok' => true, 'data' => Supabase::test()]);
                    return;
                case 'upload':
                    Auth::requirePerm('edit');
                    self::upload();
                    return;
                case 'settings':
                    Auth::requirePerm('manage');
                    self::settings();
                    return;
                case 'users':
                    Auth::requirePerm('manage');
                    self::users();
                    return;
                default:
                    throw new AppError('Unbekannter API-Endpunkt: ' . $route, 404);
            }
        } catch (AppError $e) {
            wfx_json(['ok' => false, 'error' => $e->getMessage(), 'status' => $e->status], $e->status);
        } catch (Throwable $e) {
            error_log('WFX API: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            wfx_json(['ok' => false, 'error' => 'Interner Fehler: ' . $e->getMessage()], 500);
        }
    }

    private static function parseBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return $_POST;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new AppError('Ungueltige Anfrage (JSON erwartet).', 400);
        }
        return $data;
    }

    private static function body(string $key, mixed $default = null): mixed
    {
        return self::$body[$key] ?? $default;
    }

    private static function state(): void
    {
        $user = Auth::user();
        $stats = Sync::stats();
        wfx_json([
            'ok' => true,
            'user' => [
                'name' => $user['name'] ?? null,
                'role' => $user['role'] ?? null,
                'perms' => Auth::perms($user['role'] ?? 'viewer'),
            ],
            'csrf' => Csrf::token(),
            'stats' => $stats,
            'pages' => Sync::pages(),
            'components' => Components::all(),
            'languages' => Languages::all(),
            'languageStatus' => Languages::statusAll(),
            'langs' => Languages::LANGS,
            'source' => [
                'type' => Config::source()['type'] ?? 'ftp',
                'host' => Config::source()['host'] ?? '',
                'root' => Config::source()['root'] ?? '',
                'hasCredentials' => (bool) (Config::source()['user'] ?? ''),
                'localPath' => Config::source()['localPath'] ?? '',
            ],
            'renderPhp' => (bool) (Config::all()['renderPhp'] ?? true),
            'baseUrl' => Config::all()['baseUrl'] ?? '',
            'php' => PHP_VERSION,
            'node' => Validator::nodeAvailable(),
        ]);
    }

    private static function info(): void
    {
        $file = Store::normRel((string) self::body('file', ''));
        Store::assertEditable($file);
        $src = Store::read($file);
        $doc = new HtmlDoc($src);
        $path = (array) self::body('path', []);
        $info = $doc->infoFor($path);
        $info['etag'] = sha1($src);
        $info['file'] = $file;
        $info['liveHash'] = Store::hashOf($file, Store::live());
        $info['draftHash'] = sha1($src);
        wfx_json(['ok' => true, 'info' => $info]);
    }

    private static function tree(): void
    {
        $file = Store::normRel((string) self::body('file', ''));
        Store::assertEditable($file);
        $doc = new HtmlDoc(Store::read($file));
        wfx_json(['ok' => true, 'tree' => $doc->tree(), 'etag' => sha1($doc->src)]);
    }

    private static function edit(): void
    {
        $file = Store::normRel((string) self::body('file', ''));
        Store::assertEditable($file);
        $src = Store::read($file);
        $etag = sha1($src);
        $baseEtag = (string) self::body('baseEtag', '');
        if ($baseEtag !== '' && $baseEtag !== $etag) {
            wfx_json([
                'ok' => false,
                'error' => 'Die Datei wurde zwischenzeitlich geaendert. Bitte Vorschau neu laden.',
                'etag' => $etag,
                'conflict' => true,
            ], 409);
            return;
        }
        $ops = (array) self::body('ops', []);
        if (!$ops) {
            throw new AppError('Keine Aenderungen uebergeben.');
        }
        $undo = (bool) self::body('undo', false);
        $scope = self::body('scope');

        $doc = new HtmlDoc($src);
        $targetPath = null;
        foreach ($ops as $op) {
            if (isset($op['path']) && is_array($op['path'])) {
                $targetPath = $op['path'];
                break;
            }
            if (isset($op['parentPath']) && is_array($op['parentPath'])) {
                $targetPath = $op['parentPath'];
                break;
            }
        }

        if ($scope === 'global') {
            self::globalComponentEdit($file, $doc, $ops, $targetPath, $undo);
            return;
        }

        if ($targetPath !== null && $scope === null) {
            try {
                $info = $doc->infoFor($targetPath);
            } catch (Throwable $e) {
                $info = null;
            }
            if ($info !== null && $info['component'] !== null && $info['component']['mode'] === 'global') {
                $impact = Components::impact($info['component']['slug']);
                wfx_json([
                    'ok' => false,
                    'needsScope' => true,
                    'error' => 'Das Element liegt in einer globalen Komponente. Bitte Umfang waehlen.',
                    'component' => $info['component'],
                    'impact' => $impact,
                ], 409);
                return;
            }
        }

        if ($scope === 'copy' && $targetPath !== null) {
            try {
                $info = $doc->infoFor($targetPath);
                if ($info['component'] !== null && $info['component']['mode'] === 'global') {
                    Components::detach($file, $info['component']['instancePath']);
                    $src = Store::read($file);
                    $doc = new HtmlDoc($src);
                }
            } catch (Throwable $e) {
                // Element nicht in Komponente - normal weiter
            }
        }

        $result = $doc->applyOps($ops, $undo);
        if ($result['changed']) {
            Store::writeAtomic($file, $doc->src, Store::working());
            Journal::write($undo ? 'undo' : 'edit', null, [
                'file' => $file,
                'ops' => array_map(static fn($o) => $o['kind'] ?? '?', $ops),
            ]);
        }
        $newEtag = sha1($doc->src);
        $info = null;
        if ($targetPath !== null) {
            try {
                $info = (new HtmlDoc($doc->src))->infoFor($targetPath);
            } catch (Throwable $e) {
                $info = null;
            }
        }
        wfx_json([
            'ok' => true,
            'inverse' => $result['inverse'],
            'changed' => $result['changed'],
            'etag' => $newEtag,
            'warnings' => $result['warnings'],
            'info' => $info,
        ]);
    }

    private static function globalComponentEdit(string $file, HtmlDoc $doc, array $ops, ?array $targetPath, bool $undo): void
    {
        Auth::requirePerm('global');
        if ($targetPath === null) {
            throw new AppError('Ziel fuer globale Aenderung fehlt.');
        }
        $info = $doc->infoFor($targetPath);
        $component = $info['component'];
        if ($component === null) {
            throw new AppError('Das Element gehoert zu keiner Komponente.', 422);
        }
        $item = Components::get($component['slug']);
        $defDoc = new HtmlDoc((string) $item['html']);
        if (count($defDoc->roots) !== 1) {
            throw new AppError('Die Definition hat mehrere Wurzelelemente; globale Aenderungen sind hier nicht eindeutig. Bitte im Baustein-Editor bearbeiten.', 422);
        }
        $rel = $component['relativePath'];
        $targetInDef = array_merge([0], $rel);
        $defNode = $defDoc->nodeByPath($targetInDef);
        $expectedTags = $doc->nodeByPath($targetPath)['tag'];
        if ($defNode['tag'] !== $expectedTags) {
            throw new AppError('Struktur der Definition weicht ab. Bitte Baustein manuell ueberarbeiten.', 422);
        }
        $rewritten = [];
        foreach ($ops as $op) {
            if (isset($op['path']) && is_array($op['path'])) {
                $localRel = array_slice($op['path'], count($component['instancePath']));
                $op['path'] = array_merge([0], $localRel);
                $op['tags'] = array_merge([$defDoc->roots[0] >= 0 ? $defDoc->nodes[$defDoc->roots[0]]['tag'] : 'div'], array_slice((array) ($op['tags'] ?? []), count($component['instancePath'])));
                if (($op['kind'] ?? '') === '_inner') {
                    $op['raw'] = true;
                }
            }
            if (isset($op['target']['parent']) && is_array($op['target']['parent'])) {
                $localRel = array_slice($op['target']['parent'], count($component['instancePath']));
                $op['target']['parent'] = array_merge([0], $localRel);
            }
            $rewritten[] = $op;
        }
        $res = $defDoc->applyOps($rewritten, $undo);
        if (!$res['changed']) {
            wfx_json(['ok' => true, 'changed' => false, 'global' => true]);
            return;
        }
        $newHtml = $defDoc->outerOf($defDoc->nodeByPath([0]));
        Components::save([
            'slug' => $item['slug'],
            'name' => $item['name'],
            'category' => $item['category'],
            'description' => $item['description'],
            'html' => $newHtml,
            'schema' => $item['schema'],
        ]);
        $rerender = Components::rerenderAll($item['slug']);
        Journal::write('component-global-edit', null, [
            'slug' => $item['slug'],
            'file' => $file,
            'files' => $rerender['files'],
        ]);
        wfx_json([
            'ok' => true,
            'changed' => true,
            'global' => true,
            'inverse' => [],
            'rerendered' => $rerender,
            'message' => 'Globale Komponente aktualisiert: ' . count($rerender['files']) . ' Datei(en), ' . $rerender['instances'] . ' Instanz(en).',
        ]);
    }

    private static function saveFile(): void
    {
        $file = Store::normRel((string) self::body('path', ''), true);
        Store::assertEditable($file);
        $content = (string) self::body('content', '');
        $ext = Store::extOf($file);
        if (strlen($content) > WFX_MAX_EDIT_BYTES) {
            throw new AppError('Inhalt zu gross (Limit 1,5 MB).', 413);
        }
        if (in_array($ext, ['php', 'htaccess'], true) && !self::body('confirmStructural', false)) {
            throw new AppError('Strukturelle Aenderung an ' . $file . ' erfordert eine ausdrueckliche Bestaetigung.', 428);
        }
        // Autosave legt kein Vollbackup je Tastendruck an; die manuelle
        // Quelltextbearbeitung sichert wie bisher die Vorgaengerversion.
        $autosave = (bool) self::body('autosave', false);
        $before = Store::exists($file) ? sha1(Store::read($file)) : null;
        $backupId = null;
        $backupDir = '';
        if (!$autosave) {
            $backupId = 'manual-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
            $backupDir = Store::backups() . '/' . $backupId;
            if (Store::exists($file)) {
                Store::copyFile($file, Store::working(), $backupDir . '/working');
            }
        }
        Store::writeAtomic($file, $content, Store::working());
        if ($backupId !== null) {
            Store::writeJson($backupDir . '/meta.json', [
                'id' => $backupId,
                'createdAt' => date('c'),
                'user' => Auth::user()['name'] ?? null,
                'message' => 'Quelltextbearbeitung ' . $file,
                'type' => 'manual',
                'files' => [$file],
            ]);
        }
        Journal::write($autosave ? 'source-autosave' : 'source-edit', null, [
            'file' => $file,
            'before' => $before,
            'after' => sha1($content),
            'backup' => $backupId,
        ]);
        $validation = Validator::validate([$file], false);
        wfx_json([
            'ok' => true,
            'etag' => sha1($content),
            'backup' => $backupId,
            'validation' => $validation,
        ]);
    }

    private static function fileContent(): void
    {
        $file = Store::normRel((string) ($_GET['path'] ?? ''), true);
        Store::assertEditable($file);
        $content = Store::read($file);
        $doc = null;
        $problems = [];
        if (in_array(Store::extOf($file), ['html', 'htm', 'php'], true)) {
            $doc = new HtmlDoc($content);
            $problems = $doc->problems;
        }
        wfx_json([
            'ok' => true,
            'path' => $file,
            'content' => $content,
            'etag' => sha1($content),
            'problems' => $problems,
            'editable' => true,
            'isDraft' => Store::hashOf($file, Store::working()) !== Store::hashOf($file, Store::live()),
        ]);
    }

    /** Serverobjekte inklusive Kennzeichnung, was bereits lokal liegt. */
    private static function remoteIndex(): void
    {
        $index = Sync::remoteIndex();
        $local = [];
        foreach (Store::listFiles(Store::working()) as $f) {
            $local[strtolower($f['path'])] = true;
        }
        $entries = [];
        $pages = 0;
        $localCount = 0;
        foreach ((array) ($index['files'] ?? []) as $f) {
            $path = (string) ($f['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $ext = strtolower((string) ($f['ext'] ?? pathinfo($path, PATHINFO_EXTENSION)));
            $isPage = in_array($ext, ['html', 'htm', 'php'], true);
            $isLocal = isset($local[strtolower($path)]);
            if ($isPage) {
                $pages++;
            }
            if ($isLocal) {
                $localCount++;
            }
            $entries[] = [
                'path' => $path,
                'size' => (int) ($f['size'] ?? 0),
                'ext' => $ext,
                'local' => $isLocal,
                'page' => $isPage,
            ];
        }
        wfx_json([
            'ok' => true,
            'listedAt' => $index['listedAt'] ?? null,
            'host' => $index['host'] ?? '',
            'root' => $index['root'] ?? '',
            'warnings' => $index['warnings'] ?? [],
            'counts' => ['total' => count($entries), 'local' => $localCount, 'pages' => $pages],
            'entries' => $entries,
        ]);
    }

    private static function diff(): void
    {
        $file = Store::normRel((string) ($_GET['path'] ?? ''));
        if (!Store::exists($file)) {
            throw new AppError('Datei nicht gefunden: ' . $file, 404);
        }
        $draft = Store::read($file, Store::working());
        $live = Store::exists($file, Store::live()) ? Store::read($file, Store::live()) : null;
        wfx_json([
            'ok' => true,
            'path' => $file,
            'live' => $live,
            'draft' => $draft,
            'diff' => self::unifiedDiff($live ?? '', $draft),
            'isNew' => $live === null,
        ]);
    }

    public static function unifiedDiff(string $a, string $b): array
    {
        $la = explode("\n", $a);
        $lb = explode("\n", $b);
        $n = count($la);
        $m = count($lb);
        $max = 4000;
        if ($n > $max || $m > $max) {
            return [['type' => 'info', 'text' => 'Datei zu gross fuer Detailvergleich.']];
        }
        $lines = [];
        $prefix = 0;
        while ($prefix < $n && $prefix < $m && $la[$prefix] === $lb[$prefix]) {
            $prefix++;
        }
        $suffix = 0;
        while ($suffix < ($n - $prefix) && $suffix < ($m - $prefix) && $la[$n - 1 - $suffix] === $lb[$m - 1 - $suffix]) {
            $suffix++;
        }
        $ctx = 3;
        $start = max(0, $prefix - $ctx);
        for ($i = $start; $i < $prefix; $i++) {
            $lines[] = ['type' => 'ctx', 'text' => $la[$i], 'n' => $i + 1];
        }
        for ($i = $prefix; $i < $n - $suffix; $i++) {
            $lines[] = ['type' => 'del', 'text' => $la[$i], 'n' => $i + 1];
        }
        for ($i = $prefix; $i < $m - $suffix; $i++) {
            $lines[] = ['type' => 'add', 'text' => $lb[$i], 'n' => $i + 1];
        }
        for ($i = $n - $suffix; $i < min($n, $n - $suffix + $ctx); $i++) {
            $lines[] = ['type' => 'ctx', 'text' => $la[$i], 'n' => $i + 1];
        }
        if (!$lines) {
            $lines[] = ['type' => 'info', 'text' => 'Keine Unterschiede.'];
        }
        return $lines;
    }

    private static function validate(): void
    {
        $files = (array) self::body('files', []);
        $clean = [];
        foreach ($files as $f) {
            $clean[] = Store::normRel((string) $f);
        }
        wfx_json(['ok' => true, 'data' => Validator::validate($clean)]);
    }

    private static function publish(): void
    {
        $message = trim((string) self::body('message', ''));
        if ($message === '') {
            $message = 'Veroeffentlichung ' . date('d.m.Y H:i');
        }
        $files = (array) self::body('files', []);
        $clean = [];
        foreach ($files as $f) {
            $clean[] = Store::normRel((string) $f);
        }
        wfx_json(['ok' => true, 'data' => Publish::publish($message, $clean)]);
    }

    private static function componentFromSelection(): void
    {
        $file = Store::normRel((string) self::body('file', ''));
        Store::assertEditable($file);
        $item = Components::fromSelection(
            $file,
            (array) self::body('path', []),
            (string) self::body('name', ''),
            (string) self::body('category', 'containers'),
            (string) self::body('description', '')
        );
        wfx_json(['ok' => true, 'item' => $item]);
    }

    private static function componentInsert(): void
    {
        $file = Store::normRel((string) self::body('file', ''));
        Store::assertEditable($file);
        $slug = (string) self::body('slug', '');
        $mode = (string) self::body('mode', 'copy') === 'global' ? 'global' : 'copy';
        $item = Components::get($slug);
        $props = (array) self::body('props', []);
        $parentPath = (array) self::body('parentPath', []);
        $index = self::body('index', null);

        $src = Store::read($file);
        $doc = new HtmlDoc($src);
        $parent = $doc->nodeByPath($parentPath);
        if ($doc->isLocked($parent)) {
            throw new AppError('In dieses Element kann nichts eingefuegt werden.', 422);
        }
        $html = Components::instanceHtml($item, $props, $mode);
        $op = [
            'kind' => 'insert',
            'parentPath' => $parentPath,
            'parentTags' => $doc->tagsOf($parent['id']),
            'index' => $index,
            'html' => $html,
        ];
        if (!$doc->canContain($parent['tag'], 'div') && !$doc->canContain($parent['tag'], 'a')) {
            throw new AppError('An dieser Stelle kann kein Baustein eingefuegt werden.', 422);
        }
        $res = $doc->applyOps([$op]);
        Store::writeAtomic($file, $doc->src, Store::working());
        Journal::write('component-insert', null, ['file' => $file, 'slug' => $slug, 'mode' => $mode]);
        wfx_json([
            'ok' => true,
            'etag' => sha1($doc->src),
            'inverse' => $res['inverse'],
            'warnings' => $res['warnings'],
        ]);
    }

    private static function componentRestore(): void
    {
        $slug = (string) self::body('slug', '');
        $version = (int) self::body('version', 0);
        $item = Components::get($slug);
        $found = null;
        foreach ((array) ($item['history'] ?? []) as $entry) {
            if ((int) ($entry['version'] ?? 0) === $version) {
                $found = $entry;
                break;
            }
        }
        if ($found === null) {
            throw new AppError('Diese Version ist nicht mehr im Verlauf vorhanden.', 404);
        }
        Components::save([
            'slug' => $item['slug'],
            'name' => $item['name'],
            'category' => $item['category'],
            'description' => $item['description'],
            'html' => (string) $found['html'],
            'schema' => $item['schema'],
        ]);
        $rerender = Components::rerenderAll($slug);
        wfx_json(['ok' => true, 'data' => $rerender]);
    }

    private static function componentUpdateDefinition(): void
    {
        $slug = (string) self::body('slug', '');
        $item = Components::get($slug);
        $before = Components::impact($slug);
        Components::save([
            'slug' => $slug,
            'name' => $item['name'],
            'category' => $item['category'],
            'description' => $item['description'],
            'html' => (string) self::body('html', $item['html']),
            'schema' => (array) self::body('schema', $item['schema']),
        ]);
        $rerender = Components::rerenderAll($slug);
        wfx_json(['ok' => true, 'data' => $rerender, 'impact' => $before]);
    }

    private static function upload(): void
    {
        if (!isset($_FILES['file'])) {
            throw new AppError('Keine Datei empfangen.');
        }
        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new AppError('Upload-Fehler (Code ' . (int) $file['error'] . '). Moeglicherweise ist die Datei zu gross (Limit 2 MB).', 413);
        }
        $dir = Store::normRel((string) self::body('dir', '/'));
        $name = (string) $file['name'];
        $name = preg_replace('/[^A-Za-z0-9._-]/', '-', basename($name)) ?? 'datei';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, WFX_ASSET_EXT, true)) {
            throw new AppError('Dateityp ".' . $ext . '" ist nicht erlaubt.', 415);
        }
        $tmp = (string) $file['tmp_name'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        $allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml', 'image/avif', 'application/pdf', 'font/woff', 'font/woff2', 'font/ttf', 'font/otf', 'video/mp4', 'video/webm', 'audio/mpeg'];
        if (!in_array($mime, $allowed, true)) {
            throw new AppError('Dateiinhalt (' . $mime . ') passt nicht zu einem erlaubten Medientyp.', 415);
        }
        $content = file_get_contents($tmp);
        if ($content === false || strlen($content) > 5000000) {
            throw new AppError('Datei konnte nicht gelesen werden oder ist zu gross.', 413);
        }
        if (str_starts_with($mime, 'image/svg')) {
            if (preg_match('/<\s*script/i', $content)) {
                throw new AppError('SVG mit Skriptinhalt wird abgelehnt.', 415);
            }
        }
        $target = rtrim($dir, '/') . '/' . $name;
        Store::writeAtomic($target, $content, Store::working());
        Journal::write('upload', null, ['file' => $target, 'size' => strlen($content), 'mime' => $mime]);
        wfx_json(['ok' => true, 'path' => $target]);
    }

    private static function settings(): void
    {
        $cfg = Config::all();
        $body = self::$body;
        if (isset($body['baseUrl'])) {
            $url = trim((string) $body['baseUrl']);
            if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                throw new AppError('Die oeffentliche Basis-URL muss mit http:// oder https:// beginnen.');
            }
            $cfg['baseUrl'] = rtrim($url, '/');
        }
        if (isset($body['renderPhp'])) {
            $cfg['renderPhp'] = (bool) $body['renderPhp'];
        }
        if (isset($body['supabase']) && is_array($body['supabase'])) {
            $supa = Supabase::config();
            foreach (['url', 'key', 'bucket', 'table'] as $key) {
                if (!array_key_exists($key, $body['supabase'])) {
                    continue;
                }
                $val = trim((string) $body['supabase'][$key]);
                if ($key === 'key' && $val === '') {
                    continue;
                }
                $supa[$key] = $val;
            }
            if (array_key_exists('enabled', $body['supabase'])) {
                $supa['enabled'] = (bool) $body['supabase']['enabled'];
            }
            if ($supa['url'] !== '' && !preg_match('#^https://#i', (string) $supa['url'])) {
                throw new AppError('Die Supabase-URL muss mit https:// beginnen.');
            }
            if (!preg_match('/^[A-Za-z0-9._-]{1,60}$/', (string) $supa['bucket'])) {
                throw new AppError('Ungueltiger Bucket-Name (erlaubt: Buchstaben, Zahlen, . _ -).');
            }
            if (!preg_match('/^[A-Za-z0-9_]{1,60}$/', (string) $supa['table'])) {
                throw new AppError('Ungueltiger Tabellenname.');
            }
            if ($supa['enabled'] && ((string) $supa['url'] === '' || (string) $supa['key'] === '')) {
                throw new AppError('Zum Aktivieren werden Supabase-URL und Service-Key benoetigt.');
            }
            $cfg['supabase'] = $supa;
        }
        if (isset($body['source']) && is_array($body['source'])) {
            $src = $cfg['source'];
            foreach (['type', 'host', 'user', 'root', 'localPath'] as $key) {
                if (isset($body['source'][$key]) && $body['source'][$key] !== null && $body['source'][$key] !== '') {
                    $src[$key] = (string) $body['source'][$key];
                }
            }
            if (isset($body['source']['port'])) {
                $src['port'] = max(1, min(65535, (int) $body['source']['port']));
            }
            foreach (['tls', 'passive', 'implicit'] as $key) {
                if (array_key_exists($key, $body['source'])) {
                    $src[$key] = (bool) $body['source'][$key];
                }
            }
            if (isset($body['source']['pass']) && (string) $body['source']['pass'] !== '') {
                $src['pass'] = (string) $body['source']['pass'];
            }
            if (($src['type'] ?? 'ftp') === 'local' && ($src['localPath'] ?? '') === '') {
                throw new AppError('Fuer die lokale Quelle wird ein Verzeichnispfad benoetigt.');
            }
            $cfg['source'] = $src;
        }
        Config::save($cfg);
        Journal::write('settings', null, ['keys' => array_keys($body)]);
        wfx_json(['ok' => true, 'source' => ['type' => $cfg['source']['type'], 'host' => $cfg['source']['host'], 'root' => $cfg['source']['root']], 'baseUrl' => $cfg['baseUrl'], 'supabase' => Supabase::status()]);
    }

    private static function users(): void
    {
        $cfg = Config::all();
        $body = self::$body;
        $action = (string) ($body['action'] ?? '');
        if ($action === 'add') {
            $name = trim((string) ($body['username'] ?? ''));
            if (!preg_match('/^[a-zA-Z0-9._-]{3,40}$/', $name)) {
                throw new AppError('Benutzername: 3-40 Zeichen, nur Buchstaben, Zahlen, . _ -');
            }
            if (isset($cfg['users'][$name])) {
                throw new AppError('Benutzer existiert bereits.');
            }
            $role = in_array($body['role'] ?? '', ['owner', 'editor', 'viewer'], true) ? (string) $body['role'] : 'editor';
            $cfg['users'][$name] = [
                'hash' => Auth::hashPassword((string) ($body['password'] ?? '')),
                'role' => $role,
                'createdAt' => date('c'),
            ];
            Config::save($cfg);
            Journal::write('user-add', null, ['user' => $name, 'role' => $role]);
            wfx_json(['ok' => true]);
        }
        if ($action === 'delete') {
            $name = (string) ($body['username'] ?? '');
            $me = Auth::user()['name'] ?? '';
            if ($name === $me) {
                throw new AppError('Der eigene Benutzer kann nicht geloescht werden.');
            }
            $owners = array_filter($cfg['users'], static fn($u) => ($u['role'] ?? '') === 'owner');
            if (count($owners) <= 1 && isset($cfg['users'][$name]) && ($cfg['users'][$name]['role'] ?? '') === 'owner') {
                throw new AppError('Der letzte Eigentuemer kann nicht geloescht werden.');
            }
            unset($cfg['users'][$name]);
            Config::save($cfg);
            Journal::write('user-delete', null, ['user' => $name]);
            wfx_json(['ok' => true]);
        }
        if ($action === 'password') {
            $name = (string) ($body['username'] ?? '');
            if (!isset($cfg['users'][$name])) {
                throw new AppError('Benutzer nicht gefunden.');
            }
            $cfg['users'][$name]['hash'] = Auth::hashPassword((string) ($body['password'] ?? ''));
            Config::save($cfg);
            Journal::write('user-password', null, ['user' => $name]);
            wfx_json(['ok' => true]);
        }
        wfx_json(['ok' => true, 'users' => array_map(static fn($u) => ['role' => $u['role'], 'createdAt' => $u['createdAt'] ?? null], $cfg['users'])]);
    }
}
