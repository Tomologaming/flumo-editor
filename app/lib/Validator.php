<?php
declare(strict_types=1);

/**
 * Validator - technische Pruefungen vor der Veroeffentlichung.
 * Unterscheidet Fehler (blockieren), Warnungen und Hinweise.
 */
final class Validator
{
    private static ?bool $nodeAvailable = null;

    public static function phpBinary(): string
    {
        return PHP_BINARY;
    }

    /** @return array{code:int,out:string,err:string} */
    public static function run(array $cmd, int $timeout = 15): array
    {
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) {
            return ['code' => -1, 'out' => '', 'err' => 'Prozess konnte nicht gestartet werden'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $start = microtime(true);
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $out .= (string) stream_get_contents($pipes[1]);
                $err .= (string) stream_get_contents($pipes[2]);
                break;
            }
            if (microtime(true) - $start > $timeout) {
                proc_terminate($proc, 9);
                $err .= "\nZeitueberschreitung";
                break;
            }
            usleep(20000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return ['code' => $code, 'out' => $out, 'err' => $err];
    }

    public static function nodeAvailable(): bool
    {
        if (self::$nodeAvailable !== null) {
            return self::$nodeAvailable;
        }
        $res = self::run(['node', '--version'], 5);
        self::$nodeAvailable = $res['code'] === 0 && trim($res['out']) !== '';
        return self::$nodeAvailable;
    }

    /**
     * @param string[] $files  leere Liste = alle Seiten der Arbeitskopie
     * @return array{issues:array,counts:array}
     */
    public static function validate(array $files = [], bool $includeTranslations = true): array
    {
        $issues = [];
        $add = static function (string $level, string $file, string $message, ?int $line = null, string $category = '') use (&$issues) {
            $issues[] = [
                'level' => $level,
                'file' => $file,
                'message' => $message,
                'line' => $line,
                'category' => $category,
            ];
        };

        if ($files === []) {
            foreach (Store::listFiles(Store::working()) as $f) {
                $ext = Store::extOf($f['path']);
                if (in_array($ext, ['html', 'htm', 'php', 'css', 'js', 'mjs', 'json', 'svg', 'xml'], true)) {
                    $files[] = $f['path'];
                }
            }
        }

        $componentCache = null;
        foreach ($files as $rel) {
            if (!Store::exists($rel)) {
                $add('error', $rel, 'Datei ist nicht mehr vorhanden.', null, 'existenz');
                continue;
            }
            $ext = Store::extOf($rel);
            $src = '';
            try {
                $src = Store::read($rel);
            } catch (Throwable $e) {
                $add('error', $rel, $e->getMessage(), null, 'lesen');
                continue;
            }
            if (in_array($ext, ['html', 'htm', 'php'], true)) {
                self::checkMarkup($rel, $src, $add, $componentCache);
            } elseif ($ext === 'css') {
                self::checkCss($rel, $src, $add);
            } elseif (in_array($ext, ['js', 'mjs'], true)) {
                self::checkJs($rel, $src, $add);
            } elseif ($ext === 'json') {
                json_decode($src, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $add('error', $rel, 'Ungueltiges JSON: ' . json_last_error_msg(), null, 'json');
                }
            } elseif ($ext === 'php') {
                // bereits oben behandelt
            }
            if ($ext === 'php') {
                self::checkPhpSyntax($rel, $add);
            }
        }

        if ($includeTranslations) {
            try {
                foreach (Languages::statusAll() as $row) {
                    if (($row['summary']['missing'] ?? 0) > 0) {
                        $add('warning', implode(', ', array_values($row['versions'])), 'Uebersetzung unvollstaendig: ' . $row['summary']['missing'] . ' fehlende(r) Text(e) auf Seite "' . $row['label'] . '".', null, 'uebersetzung');
                    }
                    if (($row['summary']['changed'] ?? 0) > 0) {
                        $add('warning', implode(', ', array_values($row['versions'])), $row['summary']['changed'] . ' geaenderte(r) Text(e) muessen auf Seite "' . $row['label'] . '" geprueft werden.', null, 'uebersetzung');
                    }
                    foreach ($row['problems'] as $problem) {
                        $add('error', '-', $problem['message'], null, 'uebersetzung');
                    }
                }
            } catch (Throwable $e) {
                $add('warning', '-', 'Uebersetzungspruefung nicht moeglich: ' . $e->getMessage(), null, 'uebersetzung');
            }
        }

        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
        foreach ($issues as $issue) {
            $counts[$issue['level']] = ($counts[$issue['level']] ?? 0) + 1;
        }
        return ['issues' => $issues, 'counts' => $counts];
    }

    private static function lineOf(string $src, int $offset): int
    {
        return substr_count(substr($src, 0, max(0, $offset)), "\n") + 1;
    }

    private static function checkMarkup(string $rel, string $src, callable $add, ?array &$componentCache): void
    {
        $doc = new HtmlDoc($src);
        foreach ($doc->problems as $problem) {
            $add($problem['level'] === 'error' ? 'error' : 'warning', $rel, $problem['msg'], null, 'html');
        }
        if ($doc->roots === []) {
            $add('error', $rel, 'Die Datei enthaelt kein HTML-Element.', null, 'html');
            return;
        }
        $ids = [];
        $componentSlugs = null;
        foreach ($doc->nodes as $node) {
            $idAttr = $doc->attr($node, 'id');
            if ($idAttr !== null && $idAttr !== '') {
                if (isset($ids[$idAttr])) {
                    $add('warning', $rel, 'Doppelte ID "' . $idAttr . '".', self::lineOf($src, $node['start']), 'html');
                }
                $ids[$idAttr] = true;
            }
            $slug = $doc->attr($node, 'data-wfx-component');
            if ($slug !== null && $slug !== '') {
                if ($componentCache === null) {
                    $componentCache = Components::all()['items'];
                }
                if (!isset($componentCache[$slug])) {
                    $add('error', $rel, 'Baustein-Instanz "' . $slug . '" verweist auf eine nicht vorhandene Definition.', self::lineOf($src, $node['start']), 'baustein');
                }
            }
            $parent = $node['parent'] >= 0 ? $doc->nodes[$node['parent']] : null;
            if ($parent !== null && !$doc->canContain($parent['tag'], $node['tag'])) {
                $add('warning', $rel, '<' . $node['tag'] . '> ist innerhalb von <' . $parent['tag'] . '> ungewoehnlich/ungueltig.', self::lineOf($src, $node['start']), 'struktur');
            }
            if (in_array($node['tag'], ['html', 'head', 'meta', 'base', 'title', 'style', 'template'], true)) {
                continue;
            }
            $ref = null;
            $kind = '';
            if (in_array($node['tag'], ['img', 'script', 'iframe', 'source', 'video', 'audio', 'embed'], true)) {
                $ref = $doc->attr($node, 'src');
                $kind = 'datei';
            } elseif ($node['tag'] === 'link') {
                $ref = $doc->attr($node, 'href');
                $kind = 'datei';
            } elseif ($node['tag'] === 'a') {
                $ref = $doc->attr($node, 'href');
                $kind = 'seite';
            }
            if ($ref === null || trim($ref) === '') {
                continue;
            }
            $check = self::checkRef($rel, $ref, $kind === 'seite');
            if ($check === null) {
                continue;
            }
            if ($check === 'missing') {
                $add($kind === 'seite' ? 'warning' : 'error', $rel, 'Referenz nicht gefunden: ' . $ref, self::lineOf($src, $node['start']), $kind === 'seite' ? 'link' : 'ressource');
            }
        }
    }

    /**
     * @return string|null null = nicht pruefbar/extern, "ok" oder "missing"
     */
    public static function checkRef(string $baseFile, string $ref, bool $isPage = false): ?string
    {
        $ref = trim($ref);
        if ($ref === '' || $ref === '#' || str_starts_with($ref, '#')) {
            return null;
        }
        if (preg_match('#^(https?:|mailto:|tel:|javascript:|data:|ftp:|//)#i', $ref)) {
            return null;
        }
        $ref = preg_replace('/[?#].*$/s', '', $ref) ?? '';
        if ($ref === '') {
            return 'ok';
        }
        $ref = rawurldecode($ref);
        if (str_starts_with($ref, '/')) {
            $path = $ref;
        } else {
            $baseDir = Store::dirOf($baseFile);
            if ($baseDir === '/') {
                $baseDir = '';
            }
            $path = ($baseDir === '' || $baseDir === '.' ? '' : $baseDir) . '/' . $ref;
        }
        $stack = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($stack);
                continue;
            }
            $stack[] = $seg;
        }
        $normalized = '/' . implode('/', $stack);
        if (str_ends_with($normalized, '/')) {
            $normalized .= 'index.html';
        }
        if (Store::exists($normalized)) {
            return 'ok';
        }
        foreach (['.html' => '.php', '.htm' => '.php'] as $a => $b) {
            if (str_ends_with($normalized, $a)) {
                $alt = substr($normalized, 0, -strlen($a)) . $b;
                if (Store::exists($alt)) {
                    return 'ok';
                }
            }
        }
        if (Store::exists($normalized . '/index.html') || Store::exists($normalized . '/index.php')) {
            return 'ok';
        }
        if (!str_contains(basename($normalized), '.')) {
            foreach (['.html', '.php', '/'] as $suffix) {
                if (Store::exists($normalized . $suffix) || Store::exists($normalized . $suffix . 'index.html')) {
                    return 'ok';
                }
            }
        }
        if (Store::extOf($normalized) === '') {
            return $isPage ? 'missing' : null;
        }
        return 'missing';
    }

    private static function checkCss(string $rel, string $src, callable $add): void
    {
        $noStrings = preg_replace('#/\*.*?\*/#s', '', $src) ?? $src;
        $noStrings = preg_replace('#("|\')(?:\\\\.|(?!\1).)*\1#s', '""', $noStrings) ?? $noStrings;
        $open = substr_count($noStrings, '{');
        $close = substr_count($noStrings, '}');
        if ($open !== $close) {
            $add('error', $rel, 'CSS-Klammern sind nicht ausbalanciert (' . $open . ' oeffnend / ' . $close . ' schliessend).', null, 'css');
        }
        if (str_contains($noStrings, '/*') && !str_contains($noStrings, '*/')) {
            $add('warning', $rel, 'Nicht geschlossener CSS-Kommentar.', null, 'css');
        }
        if (preg_match_all('/@import\s+url\(\s*["\']?\s*(https?:)?\/\//i', $src)) {
            $add('info', $rel, 'Externe @import-Regeln gefunden - diese werden nicht lokal geprueft.', null, 'css');
        }
    }

    private static function checkJs(string $rel, string $src, callable $add): void
    {
        if (!Store::exists($rel)) {
            return;
        }
        if (!self::nodeAvailable()) {
            $add('info', $rel, 'JavaScript-Syntaxpruefung uebersprungen (Node.js nicht gefunden).', null, 'js');
            return;
        }
        try {
            $path = Store::resolveUnder(Store::working(), $rel);
        } catch (Throwable $e) {
            return;
        }
        $res = self::run(['node', '--check', $path], 20);
        if ($res['code'] !== 0) {
            $msg = trim($res['err'] !== '' ? $res['err'] : $res['out']);
            $msg = preg_replace('/^.*?SyntaxError/i', 'SyntaxError', $msg) ?? $msg;
            $add('error', $rel, 'JavaScript-Syntaxfehler: ' . mb_substr(trim($msg), 0, 400), null, 'js');
        }
    }

    private static function checkPhpSyntax(string $rel, callable $add): void
    {
        try {
            $path = Store::resolveUnder(Store::working(), $rel);
        } catch (Throwable $e) {
            return;
        }
        if (!is_file($path)) {
            return;
        }
        $res = self::run([self::phpBinary(), '-l', $path], 20);
        $text = trim($res['out'] . "\n" . $res['err']);
        if ($res['code'] !== 0) {
            $text = str_replace($path, $rel, $text);
            $add('error', $rel, 'PHP-Syntaxfehler: ' . mb_substr(trim($text), 0, 500), null, 'php');
        }
    }

    /** Aenderungsuebersicht inkl. betroffener Seiten und gemeinsamer Ressourcen. */
    public static function affected(array $changes): array
    {
        $pages = Sync::pages();
        $pageList = array_column($pages, 'path');
        $out = [];
        $shared = [];
        foreach ($changes as $rel => $info) {
            $ext = Store::extOf($rel);
            if (in_array($ext, ['html', 'htm', 'php'], true)) {
                $out[$rel] = ['reason' => 'Seite', 'pages' => [$rel]];
                continue;
            }
            if (in_array($ext, ['css', 'js', 'mjs', 'json'], true)) {
                $referencing = [];
                $needle = basename($rel);
                foreach ($pageList as $pagePath) {
                    try {
                        $src = Store::read($pagePath);
                    } catch (Throwable $e) {
                        continue;
                    }
                    if (str_contains($src, $rel) || str_contains($src, $needle)) {
                        $referencing[] = $pagePath;
                    }
                }
                $out[$rel] = ['reason' => 'gemeinsame Ressource', 'pages' => $referencing];
                $shared[$rel] = $referencing;
                continue;
            }
            $out[$rel] = ['reason' => 'Datei', 'pages' => []];
        }
        $allPages = [];
        foreach ($out as $info) {
            foreach ($info['pages'] as $p) {
                $allPages[$p] = true;
            }
        }
        return ['files' => $out, 'shared' => $shared, 'pages' => array_keys($allPages)];
    }
}
