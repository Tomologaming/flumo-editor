<?php
declare(strict_types=1);

/**
 * Components - Bausteinbibliothek (wiederverwendbare Elemente).
 *
 * Instanzen tragen data-wfx-component (Slug) und data-wfx-mode
 * ("global" = folgt der Definition, "copy" = unabhaengige Kopie).
 */
final class Components
{
    private const DEFAULT_CATEGORIES = [
        ['id' => 'buttons', 'label' => 'Buttons'],
        ['id' => 'text', 'label' => 'Textbloecke'],
        ['id' => 'headings', 'label' => 'Ueberschriften'],
        ['id' => 'images', 'label' => 'Bilder'],
        ['id' => 'cards', 'label' => 'Karten'],
        ['id' => 'containers', 'label' => 'Container'],
        ['id' => 'heroes', 'label' => 'Hero-Bereiche'],
        ['id' => 'nav', 'label' => 'Navigation'],
        ['id' => 'footer', 'label' => 'Footer'],
        ['id' => 'grids', 'label' => 'Inhaltsraster'],
    ];

    public static function file(): string
    {
        return Store::data() . '/components.json';
    }

    public static function all(): array
    {
        $data = Store::readJson(self::file(), ['categories' => self::DEFAULT_CATEGORIES, 'items' => []]);
        if (!isset($data['categories']) || !is_array($data['categories']) || $data['categories'] === []) {
            $data['categories'] = self::DEFAULT_CATEGORIES;
        }
        $known = array_column($data['categories'], 'id');
        foreach (self::DEFAULT_CATEGORIES as $cat) {
            if (!in_array($cat['id'], $known, true)) {
                $data['categories'][] = $cat;
            }
        }
        if (!isset($data['items']) || !is_array($data['items'])) {
            $data['items'] = [];
        }
        return $data;
    }

    public static function get(string $slug): array
    {
        $items = self::all()['items'];
        if (!isset($items[$slug])) {
            throw new AppError('Baustein nicht gefunden: ' . $slug, 404);
        }
        return $items[$slug];
    }

    public static function save(array $data): array
    {
        Auth::requirePerm('components');
        $all = self::all();
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new AppError('Bitte einen Namen fuer den Baustein angeben.');
        }
        $category = (string) ($data['category'] ?? 'containers');
        if (!in_array($category, array_column($all['categories'], 'id'), true)) {
            $category = 'containers';
        }
        $html = (string) ($data['html'] ?? '');
        if (trim($html) === '') {
            throw new AppError('Der Baustein enthaelt kein HTML.');
        }
        $slug = (string) ($data['slug'] ?? '');
        if ($slug === '') {
            $slug = self::slugify($name);
        }
        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower($slug)) ?? '';
        if ($slug === '' || strlen($slug) > 60) {
            throw new AppError('Ungueltiger Baustein-Schluessel.');
        }
        $doc = new HtmlDoc($html);
        if ($doc->roots === []) {
            throw new AppError('Der Baustein muss mindestens ein HTML-Element enthalten.');
        }
        if (str_contains($html, 'data-wfx-component="' . $slug . '"')) {
            throw new AppError('Ein Baustein darf sich nicht selbst enthalten (Zirkelbezug).');
        }
        $schema = self::normalizeSchema((array) ($data['schema'] ?? []), $html);
        $existing = $all['items'][$slug] ?? null;
        $version = $existing ? ((int) ($existing['version'] ?? 1) + 1) : 1;
        $item = [
            'slug' => $slug,
            'name' => $name,
            'category' => $category,
            'description' => trim((string) ($data['description'] ?? '')),
            'html' => $html,
            'schema' => $schema,
            'version' => $version,
            'createdAt' => $existing['createdAt'] ?? date('c'),
            'updatedAt' => date('c'),
            'history' => array_slice(array_merge(
                [[
                    'version' => $existing['version'] ?? 0,
                    'html' => $existing['html'] ?? '',
                    'updatedAt' => $existing['updatedAt'] ?? null,
                ]],
                array_slice((array) ($existing['history'] ?? []), 0, 4)
            ), 0, 5),
        ];
        if ($existing === null) {
            array_pop($item['history']);
        }
        $all['items'][$slug] = $item;
        Store::writeJson(self::file(), $all);
        Journal::write('component-save', null, ['slug' => $slug, 'version' => $version]);
        return $item;
    }

    private static function normalizeSchema(array $schema, string $html): array
    {
        $out = [];
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $html, $m);
        $tokens = array_values(array_unique($m[1] ?? []));
        $byKey = [];
        foreach ($schema as $entry) {
            if (!is_array($entry) || !isset($entry['key'])) {
                continue;
            }
            $key = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $entry['key']) ?? '';
            if ($key === '') {
                continue;
            }
            $byKey[$key] = [
                'key' => $key,
                'label' => (string) ($entry['label'] ?? $key),
                'type' => (string) ($entry['type'] ?? 'text'),
                'default' => (string) ($entry['default'] ?? ''),
            ];
        }
        foreach ($tokens as $token) {
            if (!isset($byKey[$token])) {
                $byKey[$token] = ['key' => $token, 'label' => ucfirst($token), 'type' => 'text', 'default' => ''];
            }
            $out[] = $byKey[$token];
            unset($byKey[$token]);
        }
        foreach ($byKey as $rest) {
            $out[] = $rest;
        }
        return $out;
    }

    /** Export als portable JSON-Struktur. */
    public static function export(array $slugs = []): array
    {
        $all = self::all();
        $items = [];
        foreach ($all['items'] as $slug => $item) {
            if ($slugs && !in_array($slug, $slugs, true)) {
                continue;
            }
            $items[] = [
                'slug' => $item['slug'] ?? $slug,
                'name' => $item['name'] ?? $slug,
                'category' => $item['category'] ?? 'containers',
                'description' => $item['description'] ?? '',
                'html' => $item['html'] ?? '',
                'schema' => $item['schema'] ?? [],
            ];
        }
        if (!$items) {
            throw new AppError('Keine Bausteine zum Exportieren gefunden.', 404);
        }
        return [
            'format' => 'wfx-components',
            'version' => 1,
            'exportedAt' => date('c'),
            'categories' => $all['categories'],
            'items' => $items,
        ];
    }

    /**
     * Importiert Bausteine. Vorhandene Slugs werden - ohne overwrite - mit
     * Suffix gespeichert, damit nichts ueberschrieben wird.
     * @return array{saved:array,renamed:array,skipped:array}
     */
    public static function import(array $payload, bool $overwrite = false): array
    {
        Auth::requirePerm('components');
        $items = (array) ($payload['items'] ?? $payload);
        if (!$items) {
            throw new AppError('Die Datei enthaelt keine Bausteine.', 422);
        }
        $saved = [];
        $renamed = [];
        $skipped = [];
        $existing = self::all()['items'];
        foreach ($items as $raw) {
            if (!is_array($raw)) {
                $skipped[] = 'Ungueltiger Eintrag';
                continue;
            }
            $html = (string) ($raw['html'] ?? '');
            $name = trim((string) ($raw['name'] ?? ''));
            if (trim($html) === '' || $name === '') {
                $skipped[] = $name !== '' ? $name : 'Eintrag ohne Namen';
                continue;
            }
            if (str_contains($html, '<?')) {
                $skipped[] = $name . ' (enthaelt PHP)';
                continue;
            }
            $slug = self::slugify((string) ($raw['slug'] ?? '')) ?: self::slugify($name);
            if ($slug === '') {
                $skipped[] = $name . ' (kein gueltiger Schluessel)';
                continue;
            }
            $target = $slug;
            if (isset($existing[$target]) && !$overwrite) {
                $target = $slug . '-' . substr(sha1($html . $name . microtime(true)), 0, 4);
                $renamed[$slug] = $target;
            }
            try {
                self::save([
                    'slug' => $target,
                    'name' => $name,
                    'category' => (string) ($raw['category'] ?? 'containers'),
                    'description' => (string) ($raw['description'] ?? ''),
                    'html' => $html,
                    'schema' => (array) ($raw['schema'] ?? []),
                ]);
                $saved[] = $target;
                $existing[$target] = true;
            } catch (Throwable $e) {
                $skipped[] = $name . ' (' . $e->getMessage() . ')';
            }
        }
        Journal::write('component-import', null, ['saved' => count($saved), 'skipped' => count($skipped)]);
        return ['saved' => $saved, 'renamed' => $renamed, 'skipped' => $skipped];
    }

    public static function slugify(string $name): string
    {
        $map = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue'];
        $slug = strtr($name, $map);
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }

    public static function delete(string $slug): void
    {
        Auth::requirePerm('components');
        $all = self::all();
        if (!isset($all['items'][$slug])) {
            throw new AppError('Baustein nicht gefunden.', 404);
        }
        unset($all['items'][$slug]);
        Store::writeJson(self::file(), $all);
        Journal::write('component-delete', null, ['slug' => $slug]);
    }

    public static function render(string $html, array $props, array $schema = []): string
    {
        $defaults = [];
        foreach ($schema as $entry) {
            $defaults[$entry['key']] = (string) ($entry['default'] ?? '');
        }
        $values = array_merge($defaults, $props);
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static function ($m) use ($values) {
            $value = $values[$m[1]] ?? '';
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, $html) ?? $html;
    }

    /** Markup einer Instanz inkl. Marker-Attributen. */
    public static function instanceHtml(array $item, array $props, string $mode): string
    {
        $rendered = self::render((string) $item['html'], $props, (array) ($item['schema'] ?? []));
        $attrs = [
            'data-wfx-component' => (string) $item['slug'],
            'data-wfx-mode' => $mode === 'copy' ? 'copy' : 'global',
        ];
        if ($props) {
            $attrs['data-wfx-props'] = json_encode($props, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        }
        $doc = new HtmlDoc($rendered);
        if (count($doc->roots) === 1) {
            $root = $doc->nodeByPath([0]);
            if (empty($root['void'])) {
                $ops = [];
                foreach ($attrs as $k => $v) {
                    $ops[] = ['kind' => 'attr', 'path' => [0], 'tags' => [$root['tag']], 'name' => $k, 'value' => $v];
                }
                $doc->applyOps($ops);
                return $doc->src;
            }
        }
        $attrStr = '';
        foreach ($attrs as $k => $v) {
            $attrStr .= ' ' . $k . '="' . htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return '<div' . $attrStr . '>' . $rendered . '</div>';
    }

    /** @return array{pages:array,instances:int} */
    public static function impact(string $slug): array
    {
        $pages = [];
        $count = 0;
        foreach (Store::listFiles(Store::working()) as $f) {
            $ext = Store::extOf($f['path']);
            if (!in_array($ext, ['html', 'htm', 'php'], true)) {
                continue;
            }
            $src = Store::read($f['path'], Store::working());
            if (!str_contains($src, 'data-wfx-component="' . $slug . '"')) {
                continue;
            }
            $doc = new HtmlDoc($src);
            $n = count($doc->instanceRoots($slug));
            if ($n > 0) {
                $pages[] = ['path' => $f['path'], 'instances' => $n];
                $count += $n;
            }
        }
        return ['pages' => $pages, 'instances' => $count];
    }

    /**
     * Alle globalen Instanzen eines Bausteins in der Arbeitskopie neu rendern.
     * @return array{files:array,instances:int,warnings:array}
     */
    public static function rerenderAll(string $slug, array $overrideProps = [], array $clearProps = []): array
    {
        $item = self::get($slug);
        $files = [];
        $total = 0;
        $warnings = [];
        foreach (Store::listFiles(Store::working()) as $f) {
            $ext = Store::extOf($f['path']);
            if (!in_array($ext, ['html', 'htm', 'php'], true)) {
                continue;
            }
            $src = Store::read($f['path'], Store::working());
            if (!str_contains($src, 'data-wfx-component="' . $slug . '"')) {
                continue;
            }
            $doc = new HtmlDoc($src);
            $instances = $doc->instanceRoots($slug);
            if (!$instances) {
                continue;
            }
            $ops = [];
            foreach ($instances as $inst) {
                if ($doc->attr($inst, 'data-wfx-mode') === 'copy') {
                    continue;
                }
                $props = json_decode((string) $doc->attr($inst, 'data-wfx-props'), true);
                $props = is_array($props) ? $props : [];
                foreach ($clearProps as $key) {
                    unset($props[$key]);
                }
                $props = array_merge($props, $overrideProps);
                $html = self::instanceHtml($item, $props, 'global');
                $ops[] = [
                    'kind' => '_outer',
                    'path' => $doc->pathOf($inst['id']),
                    'tags' => $doc->tagsOf($inst['id']),
                    'html' => $html,
                ];
                $total++;
            }
            if (!$ops) {
                continue;
            }
            try {
                $res = $doc->applyOps($ops, true);
                Store::writeAtomic($f['path'], $doc->src, Store::working());
                $files[] = $f['path'];
                Journal::write('component-rerender', null, ['slug' => $slug, 'file' => $f['path'], 'instances' => count($ops)]);
                if ($res['warnings']) {
                    foreach ($res['warnings'] as $w) {
                        $warnings[] = $f['path'] . ': ' . $w;
                    }
                }
            } catch (Throwable $e) {
                $warnings[] = $f['path'] . ': ' . $e->getMessage();
            }
        }
        return ['files' => $files, 'instances' => $total, 'warnings' => $warnings];
    }

    /** Erzeugt eine unabhaengige Kopie einer globalen Instanz. */
    public static function detach(string $file, array $instancePath): void
    {
        Store::assertEditable($file);
        $src = Store::read($file);
        $doc = new HtmlDoc($src);
        $node = $doc->nodeByPath($instancePath);
        if ($doc->attr($node, 'data-wfx-component') === null) {
            throw new AppError('Das Element ist keine Baustein-Instanz.', 422);
        }
        $doc->applyOps([[
            'kind' => 'attr',
            'path' => $instancePath,
            'tags' => $doc->tagsOf($node['id']),
            'name' => 'data-wfx-mode',
            'value' => 'copy',
        ]]);
        Store::writeAtomic($file, $doc->src, Store::working());
        Journal::write('component-detach', null, ['file' => $file]);
    }

    /** Baustein-Entwurf aus einer bestehenden Auswahl erstellen. */
    public static function fromSelection(string $file, array $path, string $name, string $category, string $description): array
    {
        Store::assertEditable($file);
        $doc = new HtmlDoc(Store::read($file));
        $node = $doc->nodeByPath($path);
        if ($doc->isLocked($node)) {
            throw new AppError('Dieses Element kann nicht als Baustein gespeichert werden.');
        }
        $html = $doc->outerOf($node);
        if (str_contains($html, '<?')) {
            throw new AppError('Auswahl enthaelt PHP-Code - bitte einen reinen HTML-Bereich waehlen.', 422);
        }
        $slug = self::slugify($name);
        self::save([
            'slug' => $slug,
            'name' => $name,
            'category' => $category,
            'description' => $description,
            'html' => $html,
            'schema' => [],
        ]);
        return self::get($slug);
    }
}
