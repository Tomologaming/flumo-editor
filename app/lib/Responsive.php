<?php
declare(strict_types=1);

/**
 * Responsive - Breakpoint-Regeln pro Element.
 *
 * Die globalen (groessenunabhaengigen) Werte liegen als Inline-Style am Element.
 * Breakpoint-Werte landen in einem eigenen <style data-wfx-responsive>-Block im
 * <head> der Seite, adressiert ueber data-wfx-id. Vorhandene Media Queries der
 * Website bleiben unberuehrt; die erzeugten Regeln stehen am Ende und gewinnen
 * dadurch nur fuer die markierten Elemente.
 */
final class Responsive
{
    public const BREAKPOINTS = [
        'tablet' => '834px',
        'phone' => '480px',
    ];

    public static function update(string $file, array $path, array $tags, string $breakpoint, string $prop, ?string $value): array
    {
        Auth::requirePerm('edit');
        Store::assertEditable($file);
        $bpKey = $breakpoint;
        if (!isset(self::BREAKPOINTS[$bpKey])) {
            $found = array_search($breakpoint, self::BREAKPOINTS, true);
            if ($found === false) {
                throw new AppError('Unbekannter Breakpoint: ' . $breakpoint);
            }
            $bpKey = $found;
        }
        $bpPx = self::BREAKPOINTS[$bpKey];
        $prop = strtolower(trim($prop));
        if (!preg_match('/^-?[a-z][a-z0-9-]*$/', $prop)) {
            throw new AppError('Ungueltige CSS-Eigenschaft.');
        }
        if ($value !== null) {
            $value = trim($value);
            if ($value === '') {
                $value = null;
            } elseif (preg_match('/[;{}]/', $value) || preg_match('/expression\s*\(/i', $value)) {
                throw new AppError('Ungueltiger CSS-Wert.');
            }
        }

        $src = Store::read($file);
        $doc = new HtmlDoc($src);
        $node = $doc->nodeByPath($path);
        if ($tags && array_map('strtolower', $tags) !== $doc->tagsOf($node['id'])) {
            throw new AppError('Element hat sich geaendert. Bitte Vorschau neu laden.', 409);
        }
        $wfxId = $doc->attr($node, 'data-wfx-id');
        if ($wfxId === null || $wfxId === '') {
            $wfxId = 'wfx-' . substr(sha1($file . '|' . implode('/', $path) . '|' . microtime(true)), 0, 6);
            $doc->applyOps([[
                'kind' => 'attr',
                'path' => $path,
                'tags' => $doc->tagsOf($node['id']),
                'name' => 'data-wfx-id',
                'value' => $wfxId,
            ]]);
            Store::writeAtomic($file, $doc->src, Store::working());
            $doc = new HtmlDoc(Store::read($file));
        }

        $styleNode = self::findStyleBlock($doc);
        if ($styleNode === null) {
            $headPath = self::headPath($doc);
            if ($headPath === null) {
                throw new AppError('Die Seite hat keinen <head> - responsive Regeln koennen nicht eingefuegt werden.', 422);
            }
            $doc->applyOps([[
                'kind' => 'insert',
                'parentPath' => $headPath,
                'parentTags' => null,
                'index' => null,
                'system' => true,
                'html' => "\n<style data-wfx-responsive=\"1\">\n</style>\n",
            ]]);
            Store::writeAtomic($file, $doc->src, Store::working());
            $doc = new HtmlDoc(Store::read($file));
            $styleNode = self::findStyleBlock($doc);
            if ($styleNode === null) {
                throw new AppError('Responsive-Styleblock konnte nicht angelegt werden.', 500);
            }
        }

        $css = $doc->innerOf($styleNode);
        $rules = self::parseCss($css);
        if ($value === null) {
            if (isset($rules[$bpPx][$wfxId])) {
                unset($rules[$bpPx][$wfxId][$prop]);
                if (!$rules[$bpPx][$wfxId]) {
                    unset($rules[$bpPx][$wfxId]);
                }
            }
        } else {
            $rules[$bpPx][$wfxId][$prop] = $value;
        }
        $newCss = self::buildCss($rules);
        $ops = [[
            'kind' => '_inner',
            'path' => $doc->pathOf($styleNode['id']),
            'tags' => $doc->tagsOf($styleNode['id']),
            'html' => $newCss,
        ]];
        $doc->applyOps($ops);
        Store::writeAtomic($file, $doc->src, Store::working());
        Journal::write('responsive-edit', null, ['file' => $file, 'breakpoint' => $breakpoint, 'prop' => $prop]);

        return [
            'wfxId' => $wfxId,
            'breakpoint' => $bpKey,
            'rules' => $rules[$bpPx][$wfxId] ?? [],
            'css' => $newCss,
            'allRules' => $rules,
        ];
    }

    public static function rulesFor(string $file, ?array $path = null): array
    {
        $src = Store::read($file);
        $doc = new HtmlDoc($src);
        $styleNode = self::findStyleBlock($doc);
        if ($styleNode === null) {
            return [];
        }
        $rules = self::parseCss($doc->innerOf($styleNode));
        if ($path !== null) {
            try {
                $node = $doc->nodeByPath($path);
                $id = $doc->attr($node, 'data-wfx-id');
                if ($id === null) {
                    return [];
                }
                $out = [];
                foreach ($rules as $bp => $ids) {
                    if (isset($ids[$id])) {
                        $out[self::keyOfWidth($bp)] = $ids[$id];
                    }
                }
                return $out;
            } catch (Throwable $e) {
                return [];
            }
        }
        $normalized = [];
        foreach ($rules as $bp => $ids) {
            $normalized[self::keyOfWidth($bp)] = $ids;
        }
        return $normalized;
    }

    private static function keyOfWidth(string $width): string
    {
        $found = array_search($width, self::BREAKPOINTS, true);
        return $found === false ? $width : $found;
    }

    private static function findStyleBlock(HtmlDoc $doc): ?array
    {
        foreach ($doc->nodes as $node) {
            if ($node['tag'] !== 'style') {
                continue;
            }
            foreach ($node['attrs'] as $attr) {
                if ($attr['lower'] === 'data-wfx-responsive') {
                    return $node;
                }
            }
        }
        return null;
    }

    private static function headPath(HtmlDoc $doc): ?array
    {
        foreach ($doc->nodes as $node) {
            if ($node['tag'] === 'head') {
                return $doc->pathOf($node['id']);
            }
        }
        return null;
    }

    /** @return array<string,array<string,array<string,string>>> */
    private static function parseCss(string $css): array
    {
        $rules = [];
        $current = null;
        foreach (preg_split('/\r\n|\n|\r/', $css) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '/*')) {
                continue;
            }
            if (preg_match('/^@media\s*\(max-width:\s*([^)]+)\)\s*\{$/', $line, $m)) {
                $current = trim($m[1]);
                $rules[$current] = $rules[$current] ?? [];
                continue;
            }
            if ($line === '}') {
                $current = null;
                continue;
            }
            if ($current !== null && preg_match('/^\[data-wfx-id="([^"]+)"\]\s*\{\s*(.+?)\s*\}$/', $line, $m)) {
                $id = $m[1];
                $decls = [];
                foreach (explode(';', $m[2]) as $decl) {
                    $decl = trim($decl);
                    if ($decl === '') {
                        continue;
                    }
                    $pos = strpos($decl, ':');
                    if ($pos === false) {
                        continue;
                    }
                    $p = strtolower(trim(substr($decl, 0, $pos)));
                    $v = trim(substr($decl, $pos + 1));
                    if ($p === '' || $v === '') {
                        continue;
                    }
                    $decls[$p] = $v;
                }
                if ($decls) {
                    $rules[$current][$id] = $decls;
                }
            }
        }
        return $rules;
    }

    private static function buildCss(array $rules): string
    {
        $order = array_keys(self::BREAKPOINTS);
        $keys = array_keys($rules);
        usort($keys, static function ($a, $b) use ($order) {
            $ia = array_search($a, $order, true);
            $ib = array_search($b, $order, true);
            $ia = $ia === false ? 99 : $ia;
            $ib = $ib === false ? 99 : $ib;
            return $ia <=> $ib;
        });
        $out = "\n/* Automatisch erzeugt vom Website-Editor: Breakpoint-Regeln. */\n";
        $any = false;
        foreach ($keys as $bp) {
            if (!isset($rules[$bp]) || !$rules[$bp]) {
                continue;
            }
            $any = true;
            $out .= '@media (max-width: ' . $bp . ") {\n";
            foreach ($rules[$bp] as $id => $decls) {
                $parts = [];
                foreach ($decls as $p => $v) {
                    $parts[] = $p . ': ' . $v;
                }
                if (!$parts) {
                    continue;
                }
                $out .= '[data-wfx-id="' . $id . '"] { ' . implode('; ', $parts) . "; }\n";
            }
            $out .= "}\n";
        }
        if (!$any) {
            $out .= "\n";
        }
        return $out;
    }
}
