<?php
declare(strict_types=1);

/**
 * Languages - Verwaltung logischer Seiten mit mehreren Sprachversionen.
 *
 * Zuordnung erfolgt ausdruecklich durch den Benutzer. Elemente werden ueber
 * stabile IDs (data-wfx-tid) verbunden, nicht ueber ihre Position im HTML.
 */
final class Languages
{
    public const LANGS = [
        'de' => 'Deutsch',
        'en' => 'Englisch',
        'fr' => 'Franzoesisch',
        'es' => 'Spanisch',
        'it' => 'Italienisch',
        'nl' => 'Niederlaendisch',
        'pl' => 'Polnisch',
        'tr' => 'Tuerkisch',
        'ru' => 'Russisch',
    ];

    public static function file(): string
    {
        return Store::data() . '/languages.json';
    }

    public static function all(): array
    {
        $data = Store::readJson(self::file(), ['pages' => [], 'snapshots' => []]);
        $data['pages'] = is_array($data['pages'] ?? null) ? $data['pages'] : [];
        $data['snapshots'] = is_array($data['snapshots'] ?? null) ? $data['snapshots'] : [];
        return $data;
    }

    /** Kandidaten aus der Dateistruktur (nur Vorschlaege, keine Automatik). */
    public static function detect(): array
    {
        $pages = Sync::pages();
        $groups = [];
        foreach ($pages as $p) {
            $groups[$p['slug']][] = ['lang' => $p['lang'], 'path' => $p['path'], 'name' => $p['name']];
        }
        $mapped = [];
        foreach (self::all()['pages'] as $entry) {
            foreach (($entry['versions'] ?? []) as $lang => $path) {
                $mapped[$path] = $entry['id'];
            }
        }
        $out = [];
        foreach ($groups as $slug => $versions) {
            $out[] = [
                'slug' => $slug,
                'versions' => $versions,
                'mapped' => (bool) array_intersect(array_column($versions, 'path'), array_keys($mapped)),
            ];
        }
        usort($out, static fn($a, $b) => strcmp($a['slug'], $b['slug']));
        return $out;
    }

    public static function link(string $label, array $versions): array
    {
        Auth::requirePerm('edit');
        $clean = [];
        foreach ($versions as $lang => $path) {
            $lang = strtolower(trim((string) $lang));
            if (!preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $lang)) {
                throw new AppError('Ungueltiger Sprachcode: ' . $lang);
            }
            $rel = Store::normRel((string) $path);
            Store::assertEditable($rel);
            if (!Store::exists($rel)) {
                throw new AppError('Datei existiert nicht: ' . $rel);
            }
            $clean[$lang] = $rel;
        }
        if (count($clean) < 1) {
            throw new AppError('Bitte mindestens eine Sprachversion angeben.');
        }
        $data = self::all();
        $id = 'p_' . substr(sha1(implode('|', $clean) . microtime()), 0, 10);
        foreach ($data['pages'] as $existing) {
            foreach (($existing['versions'] ?? []) as $path) {
                foreach ($clean as $newPath) {
                    if ($path === $newPath) {
                        throw new AppError('Die Datei ' . $newPath . ' ist bereits der Seite "' . $existing['label'] . '" zugeordnet.');
                    }
                }
            }
        }
        $entry = [
            'id' => $id,
            'label' => trim($label) !== '' ? trim($label) : 'Seite ' . count($data['pages']) + 1,
            'versions' => $clean,
            'createdAt' => date('c'),
            'updatedAt' => date('c'),
        ];
        $data['pages'][] = $entry;
        Store::writeJson(self::file(), $data);
        $paths = array_values($clean);
        $primary = $paths[0];
        self::assignTids($primary, $id, null);
        foreach ($paths as $path) {
            if ($path !== $primary) {
                self::assignTids($path, $id, $primary);
            }
        }
        self::snapshot($id);
        Journal::write('language-link', null, ['id' => $id, 'versions' => $clean]);
        return $entry;
    }

    public static function unlink(string $id): void
    {
        Auth::requirePerm('edit');
        $data = self::all();
        $data['pages'] = array_values(array_filter($data['pages'], static fn($p) => ($p['id'] ?? '') !== $id));
        unset($data['snapshots'][$id]);
        Store::writeJson(self::file(), $data);
        Journal::write('language-unlink', null, ['id' => $id]);
    }

    public static function getPage(string $id): array
    {
        foreach (self::all()['pages'] as $p) {
            if (($p['id'] ?? '') === $id) {
                return $p;
            }
        }
        throw new AppError('Seitenzuordnung nicht gefunden.', 404);
    }

    /** Vergibt stabile Uebersetzungs-IDs fuer alle Textknoten ohne ID. */
    public static function assignTids(string $rel, string $pageId, ?string $primary = null): int
    {
        Auth::requirePerm('edit');
        $src = Store::read($rel);
        $doc = new HtmlDoc($src);
        $nodes = $doc->textNodes();
        $used = [];
        $max = 0;
        foreach ($nodes as $n) {
            if ($n['tid'] !== null && $n['tid'] !== '') {
                $used[$n['tid']] = true;
                if (preg_match('/-(\d+)$/', $n['tid'], $m)) {
                    $max = max($max, (int) $m[1]);
                }
            }
        }
        $ops = [];
        $added = 0;
        $map = [];
        if ($primary !== null && $primary !== $rel && Store::exists($primary)) {
            $pdoc = new HtmlDoc(Store::read($primary));
            foreach ($pdoc->textNodes() as $pn) {
                if ($pn['tid'] !== null && $pn['tid'] !== '') {
                    $map[self::structKey($pn)] = $pn['tid'];
                }
            }
        }
        foreach ($nodes as $n) {
            if ($n['tid'] !== null && $n['tid'] !== '') {
                continue;
            }
            $key = self::structKey($n);
            if (isset($map[$key]) && !isset($used[$map[$key]])) {
                $tid = $map[$key];
            } else {
                $max++;
                $tid = $pageId . '-' . $max;
            }
            $used[$tid] = true;
            $ops[] = [
                'kind' => 'attr',
                'path' => $n['path'],
                'name' => 'data-wfx-tid',
                'value' => $tid,
            ];
            $added++;
        }
        if (!$ops) {
            return 0;
        }
        $fresh = new HtmlDoc($src);
        $cleanOps = [];
        foreach ($ops as $op) {
            $node = $fresh->nodeByPath($op['path']);
            $op['tags'] = $fresh->tagsOf($node['id']);
            $cleanOps[] = $op;
        }
        $fresh->applyOps($cleanOps);
        Store::writeAtomic($rel, $fresh->src, Store::working());
        return $added;
    }

    /** Strukturschluessel zum Zuordnen von Textknoten zwischen Sprachversionen. */
    private static function structKey(array $node): string
    {
        return implode('/', array_slice($node['path'], 1)) . '|' . $node['tag'];
    }

    /** Aktuellen Stand als "geprueft" festhalten. */
    public static function snapshot(string $pageId): array
    {
        $page = self::getPage($pageId);
        $data = self::all();
        $snap = [];
        foreach ($page['versions'] as $lang => $path) {
            if (!Store::exists($path)) {
                continue;
            }
            $doc = new HtmlDoc(Store::read($path));
            foreach ($doc->textNodes() as $n) {
                if ($n['tid'] === null || $n['tid'] === '') {
                    continue;
                }
                $snap[$lang][$n['tid']] = [
                    'text' => $n['text'],
                    'hash' => sha1($n['text']),
                ];
            }
        }
        $data['snapshots'][$pageId] = ['takenAt' => date('c'), 'langs' => $snap];
        $data['pages'] = array_map(static function ($p) use ($pageId) {
            if (($p['id'] ?? '') === $pageId) {
                $p['updatedAt'] = date('c');
            }
            return $p;
        }, $data['pages']);
        Store::writeJson(self::file(), $data);
        Journal::write('language-snapshot', null, ['id' => $pageId]);
        return $data['snapshots'][$pageId];
    }

    /**
     * Status je Sprache: vorhanden / fehlend / veraendert / ungeprueft.
     */
    public static function status(string $pageId): array
    {
        $page = self::getPage($pageId);
        $data = self::all();
        $snap = $data['snapshots'][$pageId]['langs'] ?? [];
        $current = [];
        $problems = [];
        foreach ($page['versions'] as $lang => $path) {
            if (!Store::exists($path)) {
                $problems[] = ['lang' => $lang, 'message' => 'Datei fehlt: ' . $path];
                continue;
            }
            $doc = new HtmlDoc(Store::read($path));
            foreach ($doc->textNodes() as $n) {
                if ($n['tid'] === null || $n['tid'] === '') {
                    continue;
                }
                $current[$lang][$n['tid']] = $n['text'];
            }
        }
        $allTids = [];
        foreach ($current as $lang => $map) {
            foreach ($map as $tid => $text) {
                $allTids[$tid] = true;
            }
        }
        $rows = [];
        $summary = ['missing' => 0, 'changed' => 0, 'ok' => 0, 'unreviewed' => 0, 'total' => count($allTids)];
        foreach (array_keys($allTids) as $tid) {
            $row = ['tid' => $tid, 'langs' => []];
            foreach ($page['versions'] as $lang => $path) {
                $text = $current[$lang][$tid] ?? null;
                $snapText = $snap[$lang][$tid]['text'] ?? null;
                if ($text === null) {
                    $state = $snapText === null ? 'unknown' : 'missing';
                } elseif ($snapText === null) {
                    $state = 'new';
                } elseif ($snapText !== $text) {
                    $state = 'changed';
                } else {
                    $state = 'ok';
                }
                $row['langs'][$lang] = ['text' => $text, 'state' => $state];
            }
            $states = array_map(static fn($v) => $v['state'], $row['langs']);
            if (in_array('missing', $states, true)) {
                $row['state'] = 'missing';
                $summary['missing']++;
            } elseif (in_array('changed', $states, true)) {
                $row['state'] = 'changed';
                $summary['changed']++;
            } elseif (in_array('new', $states, true)) {
                $row['state'] = 'unreviewed';
                $summary['unreviewed']++;
            } else {
                $row['state'] = 'ok';
                $summary['ok']++;
            }
            $rows[] = $row;
        }
        return ['page' => $page, 'rows' => $rows, 'summary' => $summary, 'problems' => $problems];
    }

    public static function statusAll(): array
    {
        $out = [];
        foreach (self::all()['pages'] as $page) {
            try {
                $st = self::status($page['id']);
                $out[] = [
                    'id' => $page['id'],
                    'label' => $page['label'],
                    'versions' => $page['versions'],
                    'summary' => $st['summary'],
                    'problems' => $st['problems'],
                ];
            } catch (Throwable $e) {
                $out[] = [
                    'id' => $page['id'],
                    'label' => $page['label'],
                    'versions' => $page['versions'],
                    'summary' => ['missing' => 0, 'changed' => 0, 'ok' => 0, 'unreviewed' => 0, 'total' => 0],
                    'problems' => [['lang' => '-', 'message' => $e->getMessage()]],
                ];
            }
        }
        return $out;
    }

    /** Nebeneinandervergleich zweier Sprachversionen. */
    public static function compare(string $pageId, string $langA, string $langB): array
    {
        $page = self::getPage($pageId);
        foreach ([$langA, $langB] as $l) {
            if (!isset($page['versions'][$l])) {
                throw new AppError('Sprache ' . $l . ' ist dieser Seite nicht zugeordnet.');
            }
        }
        $texts = [];
        foreach ([$langA, $langB] as $lang) {
            $path = $page['versions'][$lang];
            $doc = new HtmlDoc(Store::read($path));
            foreach ($doc->textNodes() as $n) {
                if ($n['tid'] === null || $n['tid'] === '') {
                    continue;
                }
                $texts[$lang][$n['tid']] = ['text' => $n['text'], 'tag' => $n['tag']];
            }
            $texts[$lang . '_meta'] = self::metaOf($path);
        }
        $tids = array_unique(array_merge(array_keys($texts[$langA] ?? []), array_keys($texts[$langB] ?? [])));
        sort($tids);
        $rows = [];
        foreach ($tids as $tid) {
            $a = $texts[$langA][$tid] ?? null;
            $b = $texts[$langB][$tid] ?? null;
            $rows[] = [
                'tid' => $tid,
                'tag' => $a['tag'] ?? $b['tag'] ?? '',
                'a' => $a['text'] ?? null,
                'b' => $b['text'] ?? null,
                'state' => $a === null ? 'only-b' : ($b === null ? 'only-a' : ($a['text'] === $b['text'] ? 'equal' : 'different')),
            ];
        }
        return [
            'page' => $page,
            'langA' => $langA,
            'langB' => $langB,
            'rows' => $rows,
            'meta' => [
                'a' => $texts[$langA . '_meta'] ?? [],
                'b' => $texts[$langB . '_meta'] ?? [],
            ],
        ];
    }

    public static function metaOf(string $rel): array
    {
        $src = Store::read($rel);
        $doc = new HtmlDoc($src);
        $out = ['title' => '', 'description' => '', 'lang' => '', 'hreflang' => [], 'canonical' => ''];
        foreach ($doc->nodes as $node) {
            if ($node['tag'] === 'title' && $out['title'] === '') {
                $out['title'] = $doc->textOf($node);
            }
            if ($node['tag'] === 'html') {
                $out['lang'] = (string) $doc->attr($node, 'lang');
            }
            if ($node['tag'] === 'meta') {
                $name = strtolower((string) $doc->attr($node, 'name'));
                $prop = strtolower((string) $doc->attr($node, 'property'));
                if ($name === 'description' || $prop === 'og:description') {
                    $out['description'] = (string) $doc->attr($node, 'content');
                }
            }
            if ($node['tag'] === 'link') {
                $relAttr = strtolower((string) $doc->attr($node, 'rel'));
                if ($relAttr === 'alternate') {
                    $hl = (string) $doc->attr($node, 'hreflang');
                    if ($hl !== '') {
                        $out['hreflang'][$hl] = (string) $doc->attr($node, 'href');
                    }
                }
                if ($relAttr === 'canonical') {
                    $out['canonical'] = (string) $doc->attr($node, 'href');
                }
            }
        }
        return $out;
    }

    /** Fuer einen Textknoten die Partnertexte in anderen Sprachen finden. */
    public static function partners(string $file, string $tid): array
    {
        foreach (self::all()['pages'] as $page) {
            $paths = array_values($page['versions']);
            if (!in_array($file, $paths, true)) {
                continue;
            }
            $out = [];
            foreach ($page['versions'] as $lang => $path) {
                if ($path === $file) {
                    continue;
                }
                if (!Store::exists($path)) {
                    continue;
                }
                $doc = new HtmlDoc(Store::read($path));
                foreach ($doc->textNodes() as $n) {
                    if ($n['tid'] === $tid) {
                        $out[$lang] = ['path' => $path, 'text' => $n['text'], 'path_nodes' => $n['path']];
                        break;
                    }
                }
            }
            return ['page' => $page, 'partners' => $out];
        }
        return ['page' => null, 'partners' => []];
    }

    public static function pendingSummary(): array
    {
        $missing = 0;
        $changed = 0;
        foreach (self::statusAll() as $row) {
            $missing += (int) ($row['summary']['missing'] ?? 0);
            $changed += (int) ($row['summary']['changed'] ?? 0);
        }
        return ['missing' => $missing, 'changed' => $changed];
    }
}
