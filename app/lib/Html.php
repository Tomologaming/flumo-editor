<?php
declare(strict_types=1);

/**
 * HtmlDoc - strukturerhaltender HTML-Scanner und Editor.
 *
 * Grundprinzip: Die Quelldatei wird NICHT neu serialisiert. Stattdessen wird ein
 * Baum aus Byte-Offsets aufgebaut und jede Aenderung als gezielter Schnitt
 * (splice) auf den Quelltext angewendet. PHP-Bloecke, Kommentare, Skripte und
 * die vorhandene Formatierung bleiben dadurch unveraendert erhalten.
 */
final class HtmlDoc
{
    public string $src;

    /** @var array<int,array> */
    public array $nodes = [];

    /** @var int[] */
    public array $roots = [];

    /** @var array<int,array{level:string,msg:string}> */
    public array $problems = [];

    private const VOID = [
        'area' => 1, 'base' => 1, 'br' => 1, 'col' => 1, 'embed' => 1, 'hr' => 1, 'img' => 1,
        'input' => 1, 'link' => 1, 'meta' => 1, 'param' => 1, 'source' => 1, 'track' => 1, 'wbr' => 1,
    ];

    private const RAW = ['script' => 1, 'style' => 1, 'textarea' => 1, 'title' => 1];

    /** Elemente, die nie visuell als Text/Container bearbeitet werden. */
    public const LOCKED = [
        'html' => 1, 'head' => 1, 'script' => 1, 'style' => 1, 'link' => 1, 'meta' => 1,
        'base' => 1, 'title' => 1, 'template' => 1, 'noscript' => 1,
    ];

    public function __construct(string $src)
    {
        $this->src = $src;
        $this->parse();
    }

    /* ------------------------------------------------------------------ */
    /* Parsen                                                              */
    /* ------------------------------------------------------------------ */

    private function parse(): void
    {
        $src = $this->src;
        $len = strlen($src);
        $this->nodes = [];
        $this->roots = [];
        $this->problems = [];
        $stack = [];
        $nextId = 0;
        $i = 0;

        while ($i < $len) {
            $lt = strpos($src, '<', $i);
            if ($lt === false) {
                break;
            }
            if (substr($src, $lt, 4) === '<!--') {
                $e = strpos($src, '-->', $lt + 4);
                $i = $e === false ? $len : $e + 3;
                continue;
            }
            if (substr($src, $lt, 2) === '<?') {
                $e = strpos($src, '?>', $lt + 2);
                $i = $e === false ? $len : $e + 2;
                continue;
            }
            if (substr($src, $lt, 2) === '<!') {
                $e = strpos($src, '>', $lt + 2);
                $i = $e === false ? $len : $e + 1;
                continue;
            }
            if (!preg_match('/\G<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9:_-]*)/', $src, $m, 0, $lt)) {
                $i = $lt + 1;
                continue;
            }
            $isClose = $m[1] === '/';
            $tag = strtolower($m[2]);
            $afterName = $lt + strlen($m[0]);

            if ($isClose) {
                $gt = strpos($src, '>', $afterName);
                if ($gt === false) {
                    $this->problems[] = ['level' => 'error', 'msg' => 'Nicht geschlossenes Tag </' . $tag . '> am Dateiende'];
                    $i = $len;
                    break;
                }
                $found = -1;
                for ($k = count($stack) - 1; $k >= 0; $k--) {
                    if ($this->nodes[$stack[$k]]['tag'] === $tag) {
                        $found = $k;
                        break;
                    }
                }
                if ($found < 0) {
                    $this->problems[] = ['level' => 'warning', 'msg' => 'Schliessendes </' . $tag . '> ohne passendes oeffnendes Tag (Position ' . $lt . ')'];
                    $i = $gt + 1;
                    continue;
                }
                for ($k = count($stack) - 1; $k > $found; $k--) {
                    $id = array_pop($stack);
                    if ($this->nodes[$id]['end'] < 0) {
                        $this->nodes[$id]['end'] = $lt;
                        $this->nodes[$id]['innerEnd'] = $lt;
                    }
                    $this->problems[] = ['level' => 'warning', 'msg' => '<' . $this->nodes[$id]['tag'] . '> wurde implizit geschlossen'];
                }
                $id = array_pop($stack);
                $this->nodes[$id]['end'] = $gt + 1;
                $this->nodes[$id]['innerEnd'] = $lt;
                $i = $gt + 1;
                continue;
            }

            [$attrs, $tagEnd, $selfClose] = $this->scanAttrs($afterName, $len);
            if ($tagEnd < 0) {
                $this->problems[] = ['level' => 'error', 'msg' => 'Tag <' . $tag . '> ist nicht abgeschlossen'];
                $i = $len;
                break;
            }

            $id = $nextId++;
            $parentId = $stack ? $stack[count($stack) - 1] : -1;
            $node = [
                'id' => $id,
                'tag' => $tag,
                'parent' => $parentId,
                'index' => $parentId < 0 ? count($this->roots) : count($this->nodes[$parentId]['children']),
                'start' => $lt,
                'openEnd' => $tagEnd,
                'end' => -1,
                'innerStart' => $tagEnd,
                'innerEnd' => -1,
                'attrs' => $attrs,
                'children' => [],
                'void' => false,
                'selfClosing' => $selfClose,
            ];

            $isVoid = isset(self::VOID[$tag]) || $selfClose;
            if ($isVoid) {
                $node['void'] = true;
                $node['end'] = $tagEnd;
                $node['innerEnd'] = $tagEnd;
            }
            $this->nodes[$id] = $node;
            if ($parentId < 0) {
                $this->roots[] = $id;
            } else {
                $this->nodes[$parentId]['children'][] = $id;
            }
            if ($isVoid) {
                $i = $tagEnd;
                continue;
            }

            if (isset(self::RAW[$tag])) {
                $close = stripos($src, '</' . $tag, $tagEnd);
                if ($close === false) {
                    $this->nodes[$id]['end'] = $len;
                    $this->nodes[$id]['innerEnd'] = $len;
                    $i = $len;
                    break;
                }
                $gt = strpos($src, '>', $close);
                if ($gt === false) {
                    $gt = $len - 1;
                }
                $this->nodes[$id]['innerEnd'] = $close;
                $this->nodes[$id]['end'] = $gt + 1;
                $i = $gt + 1;
                continue;
            }

            $stack[] = $id;
            $i = $tagEnd;
        }

        foreach ($stack as $id) {
            if ($this->nodes[$id]['end'] < 0) {
                $this->nodes[$id]['end'] = $len;
                $this->nodes[$id]['innerEnd'] = $len;
                $this->problems[] = ['level' => 'warning', 'msg' => '<' . $this->nodes[$id]['tag'] . '> ist nicht geschlossen'];
            }
        }
    }

    /**
     * @return array{0:array<int,array>,1:int,2:bool}
     */
    private function scanAttrs(int $p, int $len): array
    {
        $src = $this->src;
        $attrs = [];
        $selfClose = false;
        $j = $p;
        $tagEnd = -1;
        while ($j < $len) {
            while ($j < $len && ctype_space($src[$j])) {
                $j++;
            }
            if ($j >= $len) {
                break;
            }
            if ($src[$j] === '>') {
                $tagEnd = $j + 1;
                break;
            }
            if ($src[$j] === '/' && $j + 1 < $len && $src[$j + 1] === '>') {
                $selfClose = true;
                $tagEnd = $j + 2;
                break;
            }
            $attrStart = $j;
            while ($j < $len) {
                $c = $src[$j];
                if (ctype_space($c) || $c === '=' || $c === '>') {
                    break;
                }
                if ($c === '/' && $j + 1 < $len && $src[$j + 1] === '>') {
                    break;
                }
                $j++;
            }
            $attrName = substr($src, $attrStart, $j - $attrStart);
            if ($attrName === '') {
                $j++;
                continue;
            }
            $k = $j;
            while ($k < $len && ctype_space($src[$k])) {
                $k++;
            }
            $quote = null;
            $hasValue = false;
            $value = null;
            $vStart = null;
            $vEnd = null;
            if ($k < $len && $src[$k] === '=') {
                $hasValue = true;
                $k++;
                while ($k < $len && ctype_space($src[$k])) {
                    $k++;
                }
                if ($k < $len && ($src[$k] === '"' || $src[$k] === "'")) {
                    $quote = $src[$k];
                    $vStart = $k + 1;
                    $e = strpos($src, $quote, $vStart);
                    if ($e === false) {
                        $e = $len;
                    }
                    $value = substr($src, $vStart, $e - $vStart);
                    $vEnd = $e;
                    $j = min($len, $e + 1);
                } else {
                    $vStart = $k;
                    while ($k < $len && !ctype_space($src[$k]) && $src[$k] !== '>') {
                        $k++;
                    }
                    $value = substr($src, $vStart, $k - $vStart);
                    $vEnd = $k;
                    $j = $k;
                }
            }
            $attrs[] = [
                'name' => $attrName,
                'lower' => strtolower($attrName),
                'value' => $value,
                'hasValue' => $hasValue,
                'quote' => $quote,
                'start' => $attrStart,
                'end' => $j,
                'vstart' => $vStart,
                'vend' => $vEnd,
            ];
        }
        return [$attrs, $tagEnd, $selfClose];
    }

    /* ------------------------------------------------------------------ */
    /* Zugriff                                                             */
    /* ------------------------------------------------------------------ */

    public function nodeByPath(array $path): array
    {
        $list = $this->roots;
        $node = null;
        foreach ($path as $step) {
            $step = (int) $step;
            if (!isset($list[$step])) {
                throw new AppError('Element nicht gefunden (Pfad passt nicht zur Datei).', 409);
            }
            $node = $this->nodes[$list[$step]];
            $list = $node['children'];
        }
        if ($node === null) {
            throw new AppError('Elementpfad fehlt.', 400);
        }
        return $node;
    }

    public function pathOf(int $id): array
    {
        $path = [];
        $cur = $id;
        while ($cur >= 0) {
            array_unshift($path, $this->nodes[$cur]['index']);
            $cur = $this->nodes[$cur]['parent'];
        }
        return $path;
    }

    public function tagsOf(int $id): array
    {
        $tags = [];
        $cur = $id;
        while ($cur >= 0) {
            array_unshift($tags, $this->nodes[$cur]['tag']);
            $cur = $this->nodes[$cur]['parent'];
        }
        return $tags;
    }

    public function innerOf(array $node): string
    {
        if ($node['void']) {
            return '';
        }
        return substr($this->src, $node['innerStart'], $node['innerEnd'] - $node['innerStart']);
    }

    public function outerOf(array $node): string
    {
        return substr($this->src, $node['start'], $node['end'] - $node['start']);
    }

    public function textOf(array $node): string
    {
        $inner = $this->innerOf($node);
        $text = preg_replace('/<!--.*?-->/s', '', $inner) ?? $inner;
        $text = preg_replace('/<\?.*?\?>/s', '', $text) ?? $text;
        $text = preg_replace('/<[^>]*>/', '', $text) ?? $text;
        return html_entity_decode(trim($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function attr(array $node, string $name): ?string
    {
        foreach ($node['attrs'] as $a) {
            if ($a['lower'] === strtolower($name)) {
                return $a['value'];
            }
        }
        return null;
    }

    public function isDescendantOf(int $id, int $ancestor): bool
    {
        $cur = $this->nodes[$id]['parent'];
        while ($cur >= 0) {
            if ($cur === $ancestor) {
                return true;
            }
            $cur = $this->nodes[$cur]['parent'];
        }
        return false;
    }

    public function breadcrumbOf(int $id): array
    {
        $out = [];
        $cur = $id;
        while ($cur >= 0) {
            $n = $this->nodes[$cur];
            $out[] = [
                'path' => $this->pathOf($cur),
                'tag' => $n['tag'],
                'label' => $this->labelOf($n),
            ];
            $cur = $n['parent'];
        }
        return array_reverse($out);
    }

    private function labelOf(array $node): string
    {
        $id = $node['id'] ?? null;
        if ($id === null) {
            return '<' . $node['tag'] . '>';
        }
        $idAttr = $this->attr($node, 'id');
        $class = $this->attr($node, 'class');
        $label = '<' . $node['tag'] . '>';
        if ($idAttr) {
            $label .= '#' . $idAttr;
        } elseif ($class) {
            $first = preg_split('/\s+/', trim($class))[0] ?? '';
            if ($first !== '') {
                $label .= '.' . $first;
            }
        }
        if (isset(self::LOCKED[$node['tag']])) {
            return $label;
        }
        $text = $this->textOf($node);
        if ($text !== '') {
            $short = mb_substr($text, 0, 28);
            if (mb_strlen($text) > 28) {
                $short .= '...';
            }
            return $short;
        }
        return $label;
    }

    /** @return array<int,array> */
    public function childrenOf(array $node): array
    {
        $out = [];
        foreach ($node['children'] as $cid) {
            $out[] = $this->nodes[$cid];
        }
        return $out;
    }

    public function hasPhp(string $html): bool
    {
        return str_contains($html, '<?');
    }

    /* ------------------------------------------------------------------ */
    /* Elementinformationen                                                */
    /* ------------------------------------------------------------------ */

    public function infoFor(array $path): array
    {
        $node = $this->nodeByPath($path);
        $this->assertPathTags($path, $node);
        $inner = $this->innerOf($node);
        $children = [];
        foreach ($node['children'] as $cid) {
            $c = $this->nodes[$cid];
            $children[] = ['path' => $this->pathOf($cid), 'tag' => $c['tag'], 'label' => $this->labelOf($c)];
        }
        $attrs = [];
        foreach ($node['attrs'] as $a) {
            if (in_array($a['lower'], ['data-wfx-component', 'data-wfx-mode', 'data-wfx-props', 'data-wfx-tid', 'data-wfx-lock'], true)) {
                continue;
            }
            $attrs[$a['name']] = $a['value'];
        }
        $component = $this->componentInfo($node);
        return [
            'path' => $path,
            'tags' => $this->tagsOf($node['id']),
            'tag' => $node['tag'],
            'index' => $node['index'],
            'tid' => $this->attr($node, 'data-wfx-tid'),
            'attrs' => $attrs,
            'text' => $this->textOf($node),
            'innerHtml' => $inner,
            'hasChildren' => $node['children'] !== [],
            'children' => $children,
            'void' => $node['void'],
            'locked' => $this->isLocked($node),
            'lockReason' => $this->lockReason($node),
            'hasPhp' => $this->hasPhp($inner),
            'canEditText' => $node['children'] === [] && !$node['void'] && !isset(self::RAW[$node['tag']]) && !$this->hasPhp($inner) && !$this->isLocked($node),
            'breadcrumb' => $this->breadcrumbOf($node['id']),
            'component' => $component,
            'link' => $this->linkInfo($node),
            'warnings' => $this->warningsFor($node),
        ];
    }

    private function assertPathTags(array $path, array $node): void
    {
        $tags = $this->tagsOf($node['id']);
        $sent = array_map('strtolower', array_map('strval', $this->tagsSent));
        if ($sent !== [] && $sent !== $tags) {
            throw new AppError('Element hat sich geaendert (Pfad veraltet). Bitte Vorschau neu laden.', 409);
        }
    }

    /** @var string[] */
    public array $tagsSent = [];

    public function setExpectedTags(array $tags): void
    {
        $this->tagsSent = $tags;
    }

    public function isLocked(array $node): bool
    {
        return isset(self::LOCKED[$node['tag']]);
    }

    public function lockReason(array $node): string
    {
        if (isset(self::RAW[$node['tag']])) {
            return 'Der Inhalt von <' . $node['tag'] . '> wird nicht visuell bearbeitet (Skript/Stil).';
        }
        if (isset(self::LOCKED[$node['tag']])) {
            return 'Dieses Element gehoert zum Dokumentkopf und wird nicht visuell bearbeitet.';
        }
        return '';
    }

    private function warningsFor(array $node): array
    {
        $out = [];
        $inner = $this->innerOf($node);
        if ($this->hasPhp($inner)) {
            $out[] = 'Der Inhalt enthaelt PHP-Code. Visuelle Textaenderungen sind deaktiviert, damit kein PHP verloren geht.';
        }
        if ($node['tag'] === 'a' && ($this->attr($node, 'href') ?? '') === '') {
            $out[] = 'Der Link hat kein Ziel (href).';
        }
        if (in_array($node['tag'], ['img', 'area'], true) && !$this->attr($node, 'alt') && $this->attr($node, 'alt') !== '') {
            $out[] = 'Alternativtext fehlt.';
        }
        return $out;
    }

    public function componentInfo(array $node): ?array
    {
        $cur = $node;
        while (true) {
            $slug = $this->attr($cur, 'data-wfx-component');
            if ($slug !== null && $slug !== '') {
                $props = $this->attr($cur, 'data-wfx-props');
                $decoded = $props ? json_decode($props, true) : [];
                $relPath = $this->relativePath($node['id'], $cur['id']);
                return [
                    'slug' => $slug,
                    'mode' => $this->attr($cur, 'data-wfx-mode') === 'copy' ? 'copy' : 'global',
                    'instancePath' => $this->pathOf($cur['id']),
                    'instanceTags' => $this->tagsOf($cur['id']),
                    'relativePath' => $relPath,
                    'props' => is_array($decoded) ? $decoded : [],
                    'isRoot' => $cur['id'] === $node['id'],
                ];
            }
            if ($cur['parent'] < 0) {
                return null;
            }
            $cur = $this->nodes[$cur['parent']];
        }
    }

    /**
     * Naechstgelegener Link - das Element selbst oder ein Vorfahr.
     * Damit sind auch Elemente in einem <a> (Buttons, Icons, Text) bearbeitbar.
     */
    public function linkInfo(array $node): ?array
    {
        $cur = $node;
        $depth = 0;
        while (true) {
            if ($cur['tag'] === 'a') {
                return [
                    'path' => $this->pathOf($cur['id']),
                    'tags' => $this->tagsOf($cur['id']),
                    'isSelf' => $cur['id'] === $node['id'],
                    'depth' => $depth,
                    'href' => $this->attr($cur, 'href'),
                    'target' => $this->attr($cur, 'target'),
                    'rel' => $this->attr($cur, 'rel'),
                    'title' => $this->attr($cur, 'title'),
                    'ariaLabel' => $this->attr($cur, 'aria-label'),
                    'text' => $this->textOf($cur),
                    'label' => $this->labelOf($cur),
                ];
            }
            if ($cur['parent'] < 0) {
                return null;
            }
            $cur = $this->nodes[$cur['parent']];
            $depth++;
        }
    }

    public function relativePath(int $id, int $ancestor): array
    {
        $path = [];
        $cur = $id;
        while ($cur !== $ancestor) {
            if ($cur < 0) {
                return [];
            }
            array_unshift($path, $this->nodes[$cur]['index']);
            $cur = $this->nodes[$cur]['parent'];
        }
        return $path;
    }

    /* ------------------------------------------------------------------ */
    /* Textknoten einsammeln (fuer Uebersetzungen)                         */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int,array{path:array,tag:string,text:string,tid:?string,key:string}>
     */
    public function textNodes(): array
    {
        $out = [];
        foreach ($this->nodes as $node) {
            if ($node['void'] || $node['children'] !== []) {
                continue;
            }
            if (isset(self::LOCKED[$node['tag']]) || isset(self::RAW[$node['tag']])) {
                continue;
            }
            if ($this->inHead($node['id'])) {
                continue;
            }
            $inner = $this->innerOf($node);
            if ($this->hasPhp($inner) || str_contains($inner, '<')) {
                continue;
            }
            $text = trim($inner);
            if ($text === '') {
                continue;
            }
            $out[] = [
                'path' => $this->pathOf($node['id']),
                'tag' => $node['tag'],
                'text' => html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'tid' => $this->attr($node, 'data-wfx-tid'),
                'key' => sha1($node['tag'] . '|' . $text),
            ];
        }
        return $out;
    }

    private function inHead(int $id): bool
    {
        $cur = $id;
        while ($cur >= 0) {
            if ($this->nodes[$cur]['tag'] === 'head' || $this->nodes[$cur]['tag'] === 'title') {
                return true;
            }
            $cur = $this->nodes[$cur]['parent'];
        }
        return false;
    }

    /* ------------------------------------------------------------------ */
    /* Aenderungen                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<int,array> $ops
     * @return array{inverse:array,warnings:array,changed:bool}
     */
    public function applyOps(array $ops, bool $undo = false): array
    {
        $inverse = [];
        $warnings = [];
        $changed = false;
        foreach ($ops as $op) {
            if (!is_array($op)) {
                throw new AppError('Ungueltige Operation.');
            }
            $res = $this->applyOne($op, $undo);
            foreach ($res['inverse'] as $inv) {
                $inverse[] = $inv;
            }
            foreach ($res['warnings'] ?? [] as $w) {
                $warnings[] = $w;
            }
            if (!empty($res['changed'])) {
                $changed = true;
                $this->parse();
            }
        }
        return ['inverse' => $inverse, 'warnings' => $warnings, 'changed' => $changed];
    }

    private function applyOne(array $op, bool $undo): array
    {
        $kind = (string) ($op['kind'] ?? '');
        switch ($kind) {
            case 'text':
                return $this->opText($op);
            case 'attr':
                return $this->opAttr($op);
            case 'style':
                return $this->opStyle($op);
            case 'class':
                return $this->opClass($op);
            case 'insert':
                return $this->opInsert($op);
            case 'move':
                return $this->opMove($op);
            case 'delete':
                return $this->opDelete($op);
            case 'duplicate':
                return $this->opDuplicate($op);
            case '_inner':
                return $this->opInnerRaw($op, $undo);
            case '_outer':
                return $this->opOuterRaw($op, $undo);
            default:
                throw new AppError('Unbekannte Operation: ' . $kind);
        }
    }

    private function requireNode(array $op): array
    {
        $path = $op['path'] ?? null;
        if (!is_array($path)) {
            throw new AppError('Elementpfad fehlt.');
        }
        $node = $this->nodeByPath($path);
        $expected = $op['tags'] ?? null;
        if (is_array($expected) && $expected !== []) {
            $actual = $this->tagsOf($node['id']);
            if (array_map('strtolower', $expected) !== $actual) {
                throw new AppError('Element hat sich geaendert. Bitte Vorschau neu laden.', 409);
            }
        }
        return $node;
    }

    private function splice(int $offset, int $length, string $replacement): void
    {
        $this->src = substr($this->src, 0, $offset) . $replacement . substr($this->src, $offset + $length);
    }

    private function escText(string $value): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value);
    }

    private function escAttr(string $value): string
    {
        return str_replace(['"', '<', '>'], ['&quot;', '&lt;', '&gt;'], $value);
    }

    private function opText(array $op): array
    {
        $node = $this->requireNode($op);
        if ($this->isLocked($node)) {
            throw new AppError($this->lockReason($node) ?: 'Dieses Element kann nicht bearbeitet werden.', 422);
        }
        if ($node['void']) {
            throw new AppError('Dieses Element kann keinen Text enthalten.', 422);
        }
        if ($node['children'] !== []) {
            throw new AppError('Das Element enthaelt weitere Elemente. Bitte ein inneres Textelement auswaehlen.', 422);
        }
        $inner = $this->innerOf($node);
        if ($this->hasPhp($inner)) {
            throw new AppError('Der Text enthaelt PHP-Code und wird nicht ueberschrieben.', 422);
        }
        $value = (string) ($op['value'] ?? '');
        preg_match('/^(\s*)(.*?)(\s*)$/s', $inner, $m);
        $newInner = ($m[1] ?? '') . $this->escText($value) . ($m[3] ?? '');
        if ($newInner === $inner) {
            return ['inverse' => [], 'changed' => false];
        }
        $this->splice($node['innerStart'], $node['innerEnd'] - $node['innerStart'], $newInner);
        return [
            'inverse' => [[
                'kind' => '_inner',
                'path' => $this->pathOf($node['id']),
                'tags' => $this->tagsOf($node['id']),
                'html' => $inner,
                'raw' => true,
            ]],
            'changed' => true,
        ];
    }

    private function findAttr(array $node, string $lower): ?array
    {
        foreach ($node['attrs'] as $a) {
            if ($a['lower'] === $lower) {
                return $a;
            }
        }
        return null;
    }

    private function validateAttrName(string $name): string
    {
        if (!preg_match('/^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/', $name)) {
            throw new AppError('Ungueltiger Attributname.', 422);
        }
        $lower = strtolower($name);
        if (str_starts_with($lower, 'on')) {
            throw new AppError('Event-Handler-Attribute (on...) werden nicht unterstuetzt.', 422);
        }
        if (in_array($lower, ['srcdoc', 'formaction'], true)) {
            throw new AppError('Dieses Attribut ist aus Sicherheitsgruenden gesperrt.', 422);
        }
        return $name;
    }

    private function validateUrlValue(string $value): void
    {
        if (preg_match('/^\s*(javascript|vbscript)\s*:/i', $value)) {
            throw new AppError('Unsichere URL (javascript:) abgelehnt.', 422);
        }
        if (stripos($value, 'data:text/html') !== false) {
            throw new AppError('Unsichere data:-URL abgelehnt.', 422);
        }
    }

    private function opAttr(array $op): array
    {
        $node = $this->requireNode($op);
        $name = $this->validateAttrName((string) ($op['name'] ?? ''));
        $lower = strtolower($name);
        $value = $op['value'] ?? null;
        if ($value !== null) {
            $value = (string) $value;
            if (in_array($lower, ['href', 'src', 'action', 'formaction', 'poster'], true)) {
                $this->validateUrlValue($value);
            }
            if (str_contains($value, "\0")) {
                throw new AppError('Ungueltiger Attributwert.');
            }
        }
        $existing = $this->findAttr($node, $lower);
        $previous = $existing === null ? null : $existing['value'];
        $nodePath = $this->pathOf($node['id']);
        $nodeTags = $this->tagsOf($node['id']);
        $inverse = [[
            'kind' => 'attr',
            'path' => $nodePath,
            'tags' => $nodeTags,
            'name' => $existing['name'] ?? $name,
            'value' => $previous,
        ]];

        if ($value === null) {
            if ($existing === null) {
                return ['inverse' => [], 'changed' => false];
            }
            $start = $existing['start'];
            while ($start - 1 >= $node['start'] && ctype_space($this->src[$start - 1])) {
                $start--;
            }
            $this->splice($start, $existing['end'] - $start, '');
            return ['inverse' => $inverse, 'changed' => true];
        }

        if ($existing !== null) {
            if ($existing['hasValue'] && $existing['value'] === $value) {
                return ['inverse' => [], 'changed' => false];
            }
            $encoded = $this->escAttr($value);
            $canSpliceValue = $existing['hasValue'] && $existing['vstart'] !== null
                && ($existing['quote'] !== null || !preg_match('/[\s"\'=<>`]/', $encoded));
            if ($canSpliceValue) {
                $this->splice($existing['vstart'], $existing['vend'] - $existing['vstart'], $encoded);
            } else {
                $this->splice($existing['start'], $existing['end'] - $existing['start'], $name . '="' . $encoded . '"');
            }
            return ['inverse' => $inverse, 'changed' => true];
        }

        $pos = $node['openEnd'] - 1;
        if ($node['selfClosing']) {
            $pos -= 1;
        }
        $this->splice($pos, 0, ' ' . $name . '="' . $this->escAttr($value) . '"');
        return ['inverse' => $inverse, 'changed' => true];
    }

    private function parseStyle(string $raw): array
    {
        $parts = explode(';', $raw);
        $decls = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $pos = strpos($part, ':');
            if ($pos === false) {
                continue;
            }
            $prop = strtolower(trim(substr($part, 0, $pos)));
            $val = trim(substr($part, $pos + 1));
            if ($prop === '' || $val === '') {
                continue;
            }
            $decls[] = [$prop, $val];
        }
        return $decls;
    }

    private function buildStyle(array $decls): string
    {
        $out = '';
        foreach ($decls as [$prop, $val]) {
            $out .= $prop . ': ' . $val . '; ';
        }
        return trim($out);
    }

    private function opStyle(array $op): array
    {
        $node = $this->requireNode($op);
        $prop = strtolower(trim((string) ($op['prop'] ?? '')));
        if (!preg_match('/^-?[a-z][a-z0-9-]*$/', $prop)) {
            throw new AppError('Ungueltige CSS-Eigenschaft.', 422);
        }
        $value = $op['value'] ?? null;
        if ($value !== null) {
            $value = trim((string) $value);
            if ($value === '') {
                $value = null;
            } else {
                if (preg_match('/[;{}]/', $value) || preg_match('/expression\s*\(/i', $value) || preg_match('/javascript\s*:/i', $value)) {
                    throw new AppError('Ungueltiger CSS-Wert.', 422);
                }
            }
        }
        $styleAttr = $this->findAttr($node, 'style');
        $raw = $styleAttr['value'] ?? '';
        $decls = $this->parseStyle($raw);
        $found = false;
        $next = [];
        foreach ($decls as $d) {
            if ($d[0] === $prop) {
                $found = true;
                if ($value !== null) {
                    $next[] = [$prop, $value];
                }
                continue;
            }
            $next[] = $d;
        }
        if (!$found && $value !== null) {
            $next[] = [$prop, $value];
        }
        $newRaw = $this->buildStyle($next);
        $inverse = [[
            'kind' => 'attr',
            'path' => $this->pathOf($node['id']),
            'tags' => $this->tagsOf($node['id']),
            'name' => 'style',
            'value' => $styleAttr === null ? null : $raw,
        ]];
        if ($newRaw === trim($raw)) {
            return ['inverse' => [], 'changed' => false];
        }
        return $this->opAttr([
            'kind' => 'attr',
            'path' => $op['path'],
            'tags' => $op['tags'] ?? [],
            'name' => 'style',
            'value' => $newRaw === '' ? null : $newRaw,
        ]);
    }

    private function opClass(array $op): array
    {
        $node = $this->requireNode($op);
        $classAttr = $this->findAttr($node, 'class');
        $raw = $classAttr['value'] ?? '';
        $tokens = $raw === '' ? [] : preg_split('/\s+/', trim($raw));
        $tokens = array_values(array_filter($tokens, static fn($t) => $t !== ''));
        foreach ((array) ($op['remove'] ?? []) as $rm) {
            $rm = trim((string) $rm);
            if ($rm === '') {
                continue;
            }
            if (!preg_match('/^-?[A-Za-z_][A-Za-z0-9_-]*$/', $rm)) {
                throw new AppError('Ungueltiger Klassenname: ' . $rm, 422);
            }
            $tokens = array_values(array_filter($tokens, static fn($t) => $t !== $rm));
        }
        foreach ((array) ($op['add'] ?? []) as $add) {
            $add = trim((string) $add);
            if ($add === '') {
                continue;
            }
            if (!preg_match('/^-?[A-Za-z_][A-Za-z0-9_-]*$/', $add)) {
                throw new AppError('Ungueltiger Klassenname: ' . $add, 422);
            }
            if (!in_array($add, $tokens, true)) {
                $tokens[] = $add;
            }
        }
        $newRaw = implode(' ', $tokens);
        if ($newRaw === trim($raw)) {
            return ['inverse' => [], 'changed' => false];
        }
        return $this->opAttr([
            'kind' => 'attr',
            'path' => $op['path'],
            'tags' => $op['tags'] ?? [],
            'name' => 'class',
            'value' => $newRaw === '' ? null : $newRaw,
        ]);
    }

    public function sanitizeSnippet(string $html): string
    {
        if (str_contains($html, '<?')) {
            throw new AppError('Bausteine duerfen keinen PHP-Code enthalten.', 422);
        }
        if (preg_match('/<\s*(script|iframe|object|embed|link|base|meta)\b/i', $html)) {
            throw new AppError('Bausteine duerfen keine script-, iframe-, object-, embed-, link-, base- oder meta-Elemente enthalten.', 422);
        }
        $html = preg_replace('/\son[a-z0-9_-]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace('/(href|src|action)\s*=\s*("|\')?\s*javascript:[^"\'>\s]*/i', '$1="#"', $html) ?? $html;
        return trim($html);
    }

    public function canContain(string $parent, string $child): bool
    {
        static $block = ['div' => 1, 'p' => 1, 'ul' => 1, 'ol' => 1, 'li' => 1, 'section' => 1, 'article' => 1,
            'header' => 1, 'footer' => 1, 'main' => 1, 'nav' => 1, 'aside' => 1, 'h1' => 1, 'h2' => 1, 'h3' => 1,
            'h4' => 1, 'h5' => 1, 'h6' => 1, 'table' => 1, 'form' => 1, 'figure' => 1, 'blockquote' => 1, 'pre' => 1,
            'hr' => 1, 'dl' => 1, 'fieldset' => 1, 'details' => 1, 'iframe' => 1, 'video' => 1];
        static $interactive = ['a' => 1, 'button' => 1, 'input' => 1, 'select' => 1, 'textarea' => 1, 'form' => 1];
        $parent = strtolower($parent);
        $child = strtolower($child);

        if ($parent === 'p' && isset($block[$child]) && $child !== 'hr') {
            return false;
        }
        if ($parent === 'a' && $child === 'a') {
            return false;
        }
        if ($parent === 'button' && isset($interactive[$child])) {
            return false;
        }
        if (in_array($parent, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'label', 'strong', 'em', 'span', 'small', 'figcaption', 'p'], true) && isset($block[$child])) {
            return false;
        }
        if (in_array($parent, ['ul', 'ol'], true) && !in_array($child, ['li', 'script', 'template'], true)) {
            return false;
        }
        if ($parent === 'tr' && !in_array($child, ['td', 'th'], true)) {
            return false;
        }
        if ($parent === 'table' && !in_array($child, ['thead', 'tbody', 'tfoot', 'tr', 'caption', 'colgroup'], true)) {
            return false;
        }
        if ($parent === 'select' && !in_array($child, ['option', 'optgroup'], true)) {
            return false;
        }
        if ($parent === 'head' && !in_array($child, ['meta', 'link', 'title', 'style', 'script', 'base', 'noscript'], true)) {
            return false;
        }
        if (isset(self::VOID[$parent])) {
            return false;
        }
        return true;
    }

    private function childTagList(string $html): array
    {
        $doc = new HtmlDoc($html);
        $tags = [];
        foreach ($doc->roots as $rid) {
            $tags[] = $doc->nodes[$rid]['tag'];
        }
        return $tags;
    }

    private function opInsert(array $op): array
    {
        $parentPath = $op['parentPath'] ?? null;
        if (!is_array($parentPath)) {
            throw new AppError('Zielelement fehlt.');
        }
        $parent = $this->nodeByPath($parentPath);
        if (!empty($op['parentTags'])) {
            $actual = $this->tagsOf($parent['id']);
            if (array_map('strtolower', (array) $op['parentTags']) !== $actual) {
                throw new AppError('Zielelement hat sich geaendert. Bitte Vorschau neu laden.', 409);
            }
        }
        if ($parent['void'] || isset(self::RAW[$parent['tag']])) {
            throw new AppError('In dieses Element kann nichts eingefuegt werden.', 422);
        }
        if ($this->isLocked($parent) && empty($op['system'])) {
            throw new AppError($this->lockReason($parent), 422);
        }
        $html = $this->sanitizeSnippet((string) ($op['html'] ?? ''));
        if ($html === '') {
            throw new AppError('Es wurde kein Inhalt zum Einfuegen uebergeben.');
        }
        foreach ($this->childTagList($html) as $tag) {
            if (!$this->canContain($parent['tag'], $tag)) {
                throw new AppError('<' . $tag . '> darf nicht in <' . $parent['tag'] . '> eingefuegt werden.', 422);
            }
        }
        $count = count($parent['children']);
        $index = $op['index'] ?? null;
        $index = $index === null ? $count : (int) $index;
        $index = max(0, min($count, $index));
        $pos = $index >= $count ? $parent['innerEnd'] : $this->nodes[$parent['children'][$index]]['start'];
        $oldInner = $this->innerOf($parent);
        $this->splice($pos, 0, $html);
        return [
            'inverse' => [[
                'kind' => '_inner',
                'path' => $this->pathOf($parent['id']),
                'tags' => $this->tagsOf($parent['id']),
                'html' => $oldInner,
                'raw' => true,
            ]],
            'changed' => true,
        ];
    }

    private function opMove(array $op): array
    {
        $node = $this->requireNode($op);
        $fromParent = $node['parent'];
        if ($fromParent < 0) {
            throw new AppError('Das Wurzelelement kann nicht verschoben werden.', 422);
        }
        $target = $op['target'] ?? null;
        if (!is_array($target) || !isset($target['parent'])) {
            throw new AppError('Zielposition fehlt.');
        }
        $tp = $this->nodeByPath((array) $target['parent']);
        if ($tp['id'] === $node['id'] || $this->isDescendantOf($tp['id'], $node['id'])) {
            throw new AppError('Ein Element kann nicht in sich selbst verschoben werden.', 422);
        }
        if ($tp['void'] || isset(self::RAW[$tp['tag']]) || $this->isLocked($tp)) {
            throw new AppError('In dieses Zielelement kann nicht verschoben werden.', 422);
        }
        if (!$this->canContain($tp['tag'], $node['tag'])) {
            throw new AppError('<' . $node['tag'] . '> ist in <' . $tp['tag'] . '> nicht zulaessig.', 422);
        }
        $fromParentPath = $this->pathOf($fromParent);
        $fromIndex = $node['index'];
        $remaining = array_values(array_filter(
            $this->nodes[$fromParent]['children'],
            static fn($c) => $c !== $node['id']
        ));
        $index = $target['index'] ?? count($remaining);
        $index = (int) $index;
        $index = max(0, min(count($remaining), $index));
        if ($tp['id'] === $fromParent && $index === $fromIndex) {
            return ['inverse' => [], 'changed' => false];
        }
        $anchorId = $index < count($remaining) ? $remaining[$index] : null;
        $anchor = $anchorId === null ? $tp['innerEnd'] : $this->nodes[$anchorId]['start'];

        // Einrueckung am Ziel uebernehmen (Zeilenanfang, wenn nur Whitespace davor steht)
        $lineStart = $anchor;
        while ($lineStart > 0 && $this->src[$lineStart - 1] !== "\n") {
            $lineStart--;
        }
        if (trim(substr($this->src, $lineStart, $anchor - $lineStart)) === '') {
            $anchor = max($tp['innerStart'], $lineStart);
        }

        // Einrueckung des verschobenen Elements mitnehmen, damit die Formatierung erhalten bleibt
        $cutStart = $node['start'];
        $ws = $cutStart;
        while ($ws > 0 && ($this->src[$ws - 1] === ' ' || $this->src[$ws - 1] === "\t")) {
            $ws--;
        }
        if ($ws > 0 && $this->src[$ws - 1] === "\n") {
            $cutStart = $ws;
        }
        $cutEnd = $node['end'];
        if (substr($this->src, $cutEnd, 2) === "\r\n") {
            $cutEnd += 2;
        } elseif (isset($this->src[$cutEnd]) && $this->src[$cutEnd] === "\n") {
            $cutEnd += 1;
        }
        $piece = substr($this->src, $cutStart, $cutEnd - $cutStart);
        $len = $cutEnd - $cutStart;
        $this->splice($cutStart, $len, '');
        if ($anchor > $cutStart) {
            $anchor -= $len;
        }
        $this->splice($anchor, 0, $piece);

        return [
            'inverse' => [[
                'kind' => 'move',
                'path' => array_merge((array) $target['parent'], [$index]),
                'target' => ['parent' => $fromParentPath, 'index' => $fromIndex],
            ]],
            'changed' => true,
        ];
    }

    private function opDelete(array $op): array
    {
        $node = $this->requireNode($op);
        if (in_array($node['tag'], ['html', 'body', 'head'], true)) {
            throw new AppError('Dieses Element kann nicht geloescht werden.', 422);
        }
        if ($node['parent'] < 0) {
            throw new AppError('Das Wurzelelement kann nicht geloescht werden.', 422);
        }
        if ($this->findAttr($node, 'data-wfx-lock') !== null) {
            throw new AppError('Dieses Element ist gesperrt (data-wfx-lock).', 422);
        }
        $outer = $this->outerOf($node);
        if ($this->hasPhp($outer)) {
            throw new AppError('Das Element enthaelt PHP-Code. Loeschen ist gesperrt, damit keine Logik verloren geht.', 422);
        }
        $parent = $this->nodes[$node['parent']];
        $oldInner = $this->innerOf($parent);
        $this->splice($node['start'], $node['end'] - $node['start'], '');
        return [
            'inverse' => [[
                'kind' => '_inner',
                'path' => $this->pathOf($parent['id']),
                'tags' => $this->tagsOf($parent['id']),
                'html' => $oldInner,
                'raw' => true,
            ]],
            'changed' => true,
        ];
    }

    private function opDuplicate(array $op): array
    {
        $node = $this->requireNode($op);
        if ($node['parent'] < 0) {
            throw new AppError('Das Wurzelelement kann nicht dupliziert werden.', 422);
        }
        $outer = $this->outerOf($node);
        if ($this->hasPhp($outer)) {
            throw new AppError('Das Element enthaelt PHP-Code und wird nicht dupliziert.', 422);
        }
        $parent = $this->nodes[$node['parent']];
        $oldInner = $this->innerOf($parent);
        $this->splice($node['end'], 0, $outer);
        return [
            'inverse' => [[
                'kind' => '_inner',
                'path' => $this->pathOf($parent['id']),
                'tags' => $this->tagsOf($parent['id']),
                'html' => $oldInner,
                'raw' => true,
            ]],
            'changed' => true,
            'warnings' => ['Doppelte IDs im Dokument beachten.'],
        ];
    }

    private function opInnerRaw(array $op, bool $undo): array    {
        $node = $this->requireNode($op);
        if ($node['void']) {
            throw new AppError('Dieses Element hat keinen Inhalt.', 422);
        }
        $html = (string) ($op['html'] ?? '');
        $raw = (bool) ($op['raw'] ?? false);
        if ($raw) {
            if (!$undo) {
                throw new AppError('Rohe Inhaltseingriffe sind nur fuer Undo/Redo zulaessig.', 403);
            }
        } else {
            if ($node['tag'] !== 'style') {
                $html = $this->sanitizeSnippet($html);
                if ($html !== '') {
                    foreach ($this->childTagList($html) as $tag) {
                        if (!$this->canContain($node['tag'], $tag)) {
                            throw new AppError('<' . $tag . '> ist in <' . $node['tag'] . '> nicht zulaessig.', 422);
                        }
                    }
                }
            }
        }
        $oldInner = $this->innerOf($node);
        if ($oldInner === $html) {
            return ['inverse' => [], 'changed' => false];
        }
        $this->splice($node['innerStart'], $node['innerEnd'] - $node['innerStart'], $html);
        return [
            'inverse' => [[
                'kind' => '_inner',
                'path' => $this->pathOf($node['id']),
                'tags' => $this->tagsOf($node['id']),
                'html' => $oldInner,
                'raw' => true,
            ]],
            'changed' => true,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Hilfen fuer Komponenten                                             */
    /* ------------------------------------------------------------------ */

    /** Ersetzt ein komplettes Element (Systemoperation, z. B. Komponenten-Rerender). */
    private function opOuterRaw(array $op, bool $undo): array
    {
        $node = $this->requireNode($op);
        if ($node['parent'] < 0) {
            throw new AppError('Das Wurzelelement kann nicht ersetzt werden.', 422);
        }
        $html = trim((string) ($op['html'] ?? ''));
        if ($html === '') {
            throw new AppError('Ersatzinhalt fehlt.');
        }
        if (str_contains($html, '<?')) {
            throw new AppError('Ersatzinhalt darf keinen PHP-Code enthalten.', 422);
        }
        $candidate = new HtmlDoc($html);
        if (count($candidate->roots) !== 1 || $candidate->problems) {
            throw new AppError('Ersatzinhalt muss genau ein gueltiges Element sein.', 422);
        }
        $parent = $this->nodes[$node['parent']];
        $oldInner = $this->innerOf($parent);
        $this->splice($node['start'], $node['end'] - $node['start'], $html);
        return [
            'inverse' => [[
                'kind' => '_inner',
                'path' => $this->pathOf($parent['id']),
                'tags' => $this->tagsOf($parent['id']),
                'html' => $oldInner,
                'raw' => true,
            ]],
            'changed' => true,
        ];
    }

    /** Dokumentstruktur fuer den Strukturbaum (Drag & Drop). */
    public function tree(int $maxNodes = 900): array
    {
        $count = 0;
        $build = function (int $id) use (&$build, &$count, $maxNodes) {
            if ($count++ > $maxNodes) {
                return null;
            }
            $node = $this->nodes[$id];
            $children = [];
            if (!isset(self::RAW[$node['tag']])) {
                foreach ($node['children'] as $cid) {
                    $child = $build($cid);
                    if ($child !== null) {
                        $children[] = $child;
                    }
                }
            }
            return [
                'tag' => $node['tag'],
                'path' => $this->pathOf($id),
                'label' => $this->labelOf($node),
                'text' => $this->childrenText($node),
                'locked' => $this->isLocked($node),
                'className' => (string) ($this->attr($node, 'class') ?? ''),
                'children' => $children,
            ];
        };
        $roots = [];
        foreach ($this->roots as $rid) {
            $node = $build($rid);
            if ($node !== null) {
                $roots[] = $node;
            }
        }
        return $roots;
    }

    private function childrenText(array $node): string
    {
        if ($node['children'] !== []) {
            return '';
        }
        $text = $this->textOf($node);
        return mb_substr($text, 0, 60);
    }

    public function instanceRoots(string $slug): array
    {
        $out = [];
        foreach ($this->nodes as $node) {
            $s = $this->attr($node, 'data-wfx-component');
            if ($s === $slug) {
                $out[] = $node;
            }
        }
        return $out;
    }
}
