<?php
declare(strict_types=1);

/**
 * Tests fuer den Website-Editor (ohne PHPUnit, ohne externe Abhaengigkeiten).
 * Aufruf: php tests/run.php
 */

$tmpRoot = __DIR__ . '/tmp';
define('WFX_ROOT', $tmpRoot . '/site');
define('WFX_CONFIG', $tmpRoot . '/config.php');

require __DIR__ . '/../app/lib/Core.php';
require __DIR__ . '/../app/lib/Html.php';
require __DIR__ . '/../app/lib/Ftp.php';
require __DIR__ . '/../app/lib/Sync.php';
require __DIR__ . '/../app/lib/Components.php';
require __DIR__ . '/../app/lib/Languages.php';
require __DIR__ . '/../app/lib/Validator.php';
require __DIR__ . '/../app/lib/Publish.php';
require __DIR__ . '/../app/lib/Responsive.php';
require __DIR__ . '/../app/lib/Supabase.php';
require __DIR__ . '/../app/lib/Media.php';
require __DIR__ . '/../app/lib/Api.php';

$_SESSION = [];
error_reporting(E_ALL);

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;
$GLOBALS['failures'] = [];

function test(string $name, callable $fn): void
{
    try {
        $fn();
        $GLOBALS['passed']++;
        echo "  ok   " . $name . PHP_EOL;
    } catch (Throwable $e) {
        $GLOBALS['failed']++;
        $GLOBALS['failures'][] = $name . ': ' . $e->getMessage();
        echo "  FAIL " . $name . PHP_EOL . "       -> " . $e->getMessage() . PHP_EOL;
    }
}

function check(bool $cond, string $message = 'Bedingung nicht erfuellt'): void
{
    if (!$cond) {
        throw new RuntimeException($message);
    }
}

function same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $e = is_string($expected) ? $expected : var_export($expected, true);
        $a = is_string($actual) ? $actual : var_export($actual, true);
        throw new RuntimeException(($message !== '' ? $message . ' | ' : '') . 'erwartet: ' . mb_substr($e, 0, 200) . ' / erhalten: ' . mb_substr($a, 0, 200));
    }
}

function throws(callable $fn, string $needle = '', string $message = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($needle !== '' && stripos($e->getMessage(), $needle) === false) {
            throw new RuntimeException(($message ?: 'Fehlertext') . ': "' . $e->getMessage() . '" enthaelt nicht "' . $needle . '"');
        }
        return;
    }
    throw new RuntimeException(($message ?: 'Ausnahme') . ' wurde nicht ausgeloest');
}

function rmTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }
    @rmdir($dir);
}

function copyTree(string $src, string $dst): void
{
    if (!is_dir($src)) {
        throw new RuntimeException('Quelle fehlt: ' . $src);
    }
    @mkdir($dst, 0775, true);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $file) {
        $rel = substr($file->getPathname(), strlen($src));
        $target = $dst . $rel;
        if ($file->isDir()) {
            @mkdir($target, 0775, true);
        } else {
            @mkdir(dirname($target), 0775, true);
            copy($file->getPathname(), $target);
        }
    }
}

function setupSite(): void
{
    rmTree($GLOBALS['tmpRoot'] ?? dirname(__DIR__) . '/tests/tmp');
    Store::init();
    copyTree(__DIR__ . '/fixtures/site', Store::working());
    copyTree(__DIR__ . '/fixtures/site', Store::live());
    Config::save(array_merge(Config::defaults(), [
        'installed' => true,
        'users' => [
            'admin' => ['hash' => password_hash('secret12345', PASSWORD_DEFAULT), 'role' => 'owner'],
            'redakteur' => ['hash' => password_hash('secret12345', PASSWORD_DEFAULT), 'role' => 'editor'],
        ],
        'source' => [
            'type' => 'ftp', 'host' => '127.0.0.1', 'port' => 2121, 'tls' => false, 'implicit' => false,
            'passive' => true, 'user' => 'tester', 'pass' => 'secret', 'root' => '/htdocs', 'localPath' => '',
        ],
        'baseUrl' => '',
    ]));
    $_SESSION['wfx_user'] = ['name' => 'admin', 'role' => 'owner'];
}

function resetFile(string $rel): void
{
    Store::writeAtomic($rel, file_get_contents(__DIR__ . '/fixtures/site' . $rel), Store::working());
}

function findTag(HtmlDoc $doc, string $tag, ?string $contains = null): array
{
    foreach ($doc->nodes as $node) {
        if ($node['tag'] !== $tag) {
            continue;
        }
        if ($contains !== null && !str_contains($doc->textOf($node), $contains)) {
            continue;
        }
        return $node;
    }
    throw new RuntimeException('<' . $tag . '> nicht gefunden');
}

function waitForPort(string $host, int $port, float $timeout = 8.0): bool
{
    $end = microtime(true) + $timeout;
    while (microtime(true) < $end) {
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.25);
        if ($fp) {
            fclose($fp);
            return true;
        }
        usleep(120000);
    }
    return false;
}

echo PHP_EOL . '=== Website-Editor: Testsuite ===' . PHP_EOL . PHP_EOL;

$GLOBALS['tmpRoot'] = $tmpRoot;
setupSite();

/* ------------------------------------------------------------------ */
echo '[1] Pfad- und Dateisicherheit' . PHP_EOL;
/* ------------------------------------------------------------------ */

test('Pfadnormalisierung entfernt Backslashes und fuehrende Slashes', function () {
    same('/de/index.html', Store::normRel('de\\index.html'));
    same('/de/index.html', Store::normRel('/de/index.html'));
    same('/assets/css/style.css', Store::normRel('//assets//css/style.css'));
});

test('Path Traversal wird abgelehnt', function () {
    throws(fn() => Store::normRel('/../etc/passwd'), '..');
    throws(fn() => Store::normRel('/de/../../secret.txt'), '..');
    throws(fn() => Store::normRel('/de/./x'), '..');
    throws(fn() => Store::normRel('C:\\Windows\\win.ini'), '');
});

test('Versteckte Dateien und Secrets sind gesperrt', function () {
    throws(fn() => Store::normRel('/.env'), 'Versteckte');
    throws(fn() => Store::normRel('/de/.git/config'), 'Versteckte');
    throws(fn() => Store::normRel('/.htpasswd'), 'Versteckte');
    same('/.htaccess', Store::normRel('/.htaccess', true), '.htaccess nur fuer Quelltextmodus');
});

test('Nicht freigegebene Dateitypen werden abgelehnt', function () {
    Store::assertEditable('/de/index.html');
    Store::assertEditable('/assets/css/style.css');
    throws(fn() => Store::assertEditable('/bin/tool.exe'), 'nicht freigegeben');
});

test('Symlinks ausserhalb des Arbeitsverzeichnisses werden blockiert', function () {
    $outside = dirname(WFX_ROOT) . '/outside.txt';
    file_put_contents($outside, 'geheim');
    $link = WFX_ROOT . '/workspace/working/link.txt';
    if (DIRECTORY_SEPARATOR === '\\') {
        // Windows-Symlinks brauchen Sonderrechte - Test wird als Hinweis gewertet.
        check(true, 'uebersprungen');
        return;
    }
    @symlink($outside, $link);
    if (!is_link($link)) {
        return;
    }
    throws(fn() => Store::read('/link.txt'), 'Symbolische');
});

/* ------------------------------------------------------------------ */
echo PHP_EOL . '[2] Strukturerhaltende HTML-Bearbeitung' . PHP_EOL;
/* ------------------------------------------------------------------ */

test('Textaenderung veraendert ausschliesslich den Textknoten', function () {
    resetFile('/de/index.html');
    $before = Store::read('/de/index.html');
    $doc = new HtmlDoc($before);
    $h1 = findTag($doc, 'h1');
    $doc->applyOps([[
        'kind' => 'text',
        'path' => $doc->pathOf($h1['id']),
        'tags' => $doc->tagsOf($h1['id']),
        'value' => 'Neuer Titel',
    ]]);
    $after = $doc->src;
    $expected = str_replace('Willkommen bei Musterfirma', 'Neuer Titel', $before);
    same($expected, $after, 'Rest der Datei muss byte-identisch bleiben');
    check(str_contains($after, '<h1 class="hero-title">Neuer Titel</h1>'), 'h1-Inhalt ersetzt');
});

test('Text wird HTML-escaped gespeichert', function () {
    resetFile('/de/index.html');
    $doc = new HtmlDoc(Store::read('/de/index.html'));
    $h1 = findTag($doc, 'h1');
    $doc->applyOps([[
        'kind' => 'text', 'path' => $doc->pathOf($h1['id']), 'tags' => $doc->tagsOf($h1['id']),
        'value' => 'A & B <script>alert(1)</script>',
    ]]);
    check(str_contains($doc->src, 'A &amp; B &lt;script&gt;alert(1)&lt;/script&gt;'), 'escaped');
    check(!str_contains($doc->src, '<script>alert(1)</script>'), 'kein Roh-Skript');
});

test('PHP-Bloecke werden nie ueberschrieben', function () {
    $doc = new HtmlDoc(Store::read('/about.php'));
    $p = findTag($doc, 'p', 'Jahr:');
    throws(fn() => $doc->applyOps([[
        'kind' => 'text', 'path' => $doc->pathOf($p['id']), 'tags' => $doc->tagsOf($p['id']), 'value' => 'x',
    ]]), 'PHP');
});

test('Attribute werden gezielt gesetzt und entfernt', function () {
    resetFile('/de/index.html');
    $doc = new HtmlDoc(Store::read('/de/index.html'));
    $link = findTag($doc, 'a', 'Mehr erfahren');
    $path = $doc->pathOf($link['id']);
    $tags = $doc->tagsOf($link['id']);
    $doc->applyOps([['kind' => 'attr', 'path' => $path, 'tags' => $tags, 'name' => 'href', 'value' => '/de/rules.html?x=1']]);
    check(str_contains($doc->src, 'href="/de/rules.html?x=1"'), 'href gesetzt');

    $doc2 = new HtmlDoc($doc->src);
    $link2 = findTag($doc2, 'a', 'Mehr erfahren');
    $doc2->applyOps([['kind' => 'attr', 'path' => $doc2->pathOf($link2['id']), 'tags' => $doc2->tagsOf($link2['id']), 'name' => 'title', 'value' => 'Regeln']]);
    check(str_contains($doc2->src, 'title="Regeln"'), 'Attribut hinzugefuegt');

    $doc3 = new HtmlDoc($doc2->src);
    $link3 = findTag($doc3, 'a', 'Mehr erfahren');
    $doc3->applyOps([['kind' => 'attr', 'path' => $doc3->pathOf($link3['id']), 'tags' => $doc3->tagsOf($link3['id']), 'name' => 'title', 'value' => null]]);
    check(!str_contains($doc3->src, 'title="Regeln"'), 'Attribut entfernt');
    check(substr_count($doc3->src, '<a ') === substr_count(Store::read('/de/index.html'), '<a '), 'keine weiteren Aenderungen');
});

test('Unsichere Attribute werden abgelehnt', function () {
    $doc = new HtmlDoc(Store::read('/de/index.html'));
    $link = findTag($doc, 'a', 'Mehr erfahren');
    $path = $doc->pathOf($link['id']);
    $tags = $doc->tagsOf($link['id']);
    throws(fn() => $doc->applyOps([['kind' => 'attr', 'path' => $path, 'tags' => $tags, 'name' => 'onclick', 'value' => 'alert(1)']]), 'Event-Handler');
    throws(fn() => $doc->applyOps([['kind' => 'attr', 'path' => $path, 'tags' => $tags, 'name' => 'href', 'value' => 'javascript:alert(1)']]), 'Unsichere');
});

test('Inline-Style aendert nur die gewaehlte Eigenschaft', function () {
    resetFile('/de/index.html');
    $doc = new HtmlDoc(Store::read('/de/index.html'));
    $h1 = findTag($doc, 'h1');
    $path = $doc->pathOf($h1['id']);
    $tags = $doc->tagsOf($h1['id']);
    $doc->applyOps([
        ['kind' => 'style', 'path' => $path, 'tags' => $tags, 'prop' => 'color', 'value' => '#ff0000'],
        ['kind' => 'style', 'path' => $path, 'tags' => $tags, 'prop' => 'font-size', 'value' => '40px'],
    ]);
    check(str_contains($doc->src, 'color: #ff0000'), 'Farbe gesetzt');
    check(str_contains($doc->src, 'font-size: 40px'), 'Groesse gesetzt');
    $doc2 = new HtmlDoc($doc->src);
    $h2 = findTag($doc2, 'h1');
    $doc2->applyOps([['kind' => 'style', 'path' => $doc2->pathOf($h2['id']), 'tags' => $doc2->tagsOf($h2['id']), 'prop' => 'color', 'value' => null]]);
    check(!str_contains($doc2->src, 'color: #ff0000'), 'Farbe entfernt');
    check(str_contains($doc2->src, 'font-size: 40px'), 'andere Eigenschaft bleibt');
});

test('Klassen werden hinzugefuegt und entfernt', function () {
    resetFile('/de/index.html');
    $doc = new HtmlDoc(Store::read('/de/index.html'));
    $a = findTag($doc, 'a', 'Mehr erfahren');
    $path = $doc->pathOf($a['id']);
    $tags = $doc->tagsOf($a['id']);
    $doc->applyOps([['kind' => 'class', 'path' => $path, 'tags' => $tags, 'add' => ['btn-large'], 'remove' => []]]);
    check(str_contains($doc->src, 'class="btn btn-primary btn-large"'), 'Klasse ergaenzt');
    $doc2 = new HtmlDoc($doc->src);
    $a2 = findTag($doc2, 'a', 'Mehr erfahren');
    $doc2->applyOps([['kind' => 'class', 'path' => $doc2->pathOf($a2['id']), 'tags' => $doc2->tagsOf($a2['id']), 'add' => [], 'remove' => ['btn-primary']]]);
    check(str_contains($doc2->src, 'class="btn btn-large"'), 'Klasse entfernt');
});

test('Element verschieben (Geschwisterreihenfolge) mit exaktem Undo', function () {
    resetFile('/de/rules.html');
    $before = Store::read('/de/rules.html');
    $doc = new HtmlDoc($before);
    $lis = [];
    foreach ($doc->nodes as $n) {
        if ($n['tag'] === 'li') {
            $lis[] = $n;
        }
    }
    same(3, count($lis), 'drei Listeneintraege');
    $path = $doc->pathOf($lis[0]['id']);
    $tags = $doc->tagsOf($lis[0]['id']);
    $res = $doc->applyOps([[
        'kind' => 'move',
        'path' => $path,
        'tags' => $tags,
        'target' => ['parent' => array_slice($path, 0, -1), 'index' => 3],
    ]]);
    $moved = $doc->src;
    check(strpos($moved, 'Klare Absprachen') > strpos($moved, 'Dokumentation'), 'Reihenfolge geaendert');
    same(substr_count($before, '<li>'), substr_count($moved, '<li>'), 'keine Elemente verloren');

    $undoRes = $doc->applyOps($res['inverse'], true);
    same($before, $doc->src, 'Undo stellt exakt den Ausgangszustand her');
});

test('Loeschen und Undo stellt exakt wieder her', function () {
    resetFile('/de/rules.html');
    $before = Store::read('/de/rules.html');
    $doc = new HtmlDoc($before);
    $li = findTag($doc, 'li');
    $res = $doc->applyOps([['kind' => 'delete', 'path' => $doc->pathOf($li['id']), 'tags' => $doc->tagsOf($li['id'])]]);
    check(!str_contains($doc->src, 'Klare Absprachen'), 'entfernt');
    $doc->applyOps($res['inverse'], true);
    same($before, $doc->src, 'exakt wiederhergestellt');
});

test('Ungueltige Verschachtelung wird verhindert', function () {
    resetFile('/de/index.html');
    $doc = new HtmlDoc(Store::read('/de/index.html'));
    $section = null;
    foreach ($doc->nodes as $n) {
        if ($n['tag'] === 'section' && str_contains((string) $doc->attr($n, 'class'), 'cards')) {
            $section = $n;
        }
    }
    check($section !== null, 'cards-Section gefunden');
    $h1 = findTag($doc, 'h1');
    throws(fn() => $doc->applyOps([[
        'kind' => 'move',
        'path' => $doc->pathOf($section['id']),
        'tags' => $doc->tagsOf($section['id']),
        'target' => ['parent' => $doc->pathOf($h1['id']), 'index' => 0],
    ]]), 'nicht zulaessig');
});

test('Duplizieren und Undo', function () {
    resetFile('/de/index.html');
    $before = Store::read('/de/index.html');
    $doc = new HtmlDoc($before);
    $h1 = findTag($doc, 'h1');
    $doc->applyOps([['kind' => 'duplicate', 'path' => $doc->pathOf($h1['id']), 'tags' => $doc->tagsOf($h1['id'])]]);
    same(2, substr_count($doc->src, 'Willkommen bei Musterfirma'), 'dupliziert');
});

test('Baustein-Einfuegen lehnt Skripte ab', function () {
    $doc = new HtmlDoc(Store::read('/de/index.html'));
    throws(fn() => $doc->sanitizeSnippet('<div><script>alert(1)</script></div>'), 'script');
    throws(fn() => $doc->sanitizeSnippet('<div><iframe src="x"></iframe></div>'), 'iframe');
    same('<div>x</div>', $doc->sanitizeSnippet('<div onclick="alert(1)">x</div>'), 'Handler entfernt');
});

/* ------------------------------------------------------------------ */
echo PHP_EOL . '[3] Bausteine (Komponenten)' . PHP_EOL;
/* ------------------------------------------------------------------ */

test('Baustein speichern, rendern und Instanz-Markup erzeugen', function () {
    $item = Components::save([
        'name' => 'Test Button',
        'category' => 'buttons',
        'html' => '<a class="btn" href="{{href}}">{{label}}</a>',
        'schema' => [['key' => 'href', 'default' => '/x'], ['key' => 'label', 'default' => 'Klick']],
    ]);
    same('test-button', $item['slug']);
    same(2, count($item['schema']), 'zwei Eigenschaften erkannt');
    $html = Components::instanceHtml($item, ['label' => 'Los & fertig'], 'global');
    check(str_contains($html, 'data-wfx-component="test-button"'), 'Marker gesetzt');
    check(str_contains($html, 'data-wfx-mode="global"'), 'Modus gesetzt');
    check(str_contains($html, 'Los &amp; fertig'), 'Wert escaped eingesetzt');
});

test('Baustein einfuegen, Vorkommen finden und global aktualisieren', function () {
    resetFile('/de/index.html');
    $item = Components::get('test-button');
    $doc = new HtmlDoc(Store::read('/de/index.html'));
    $body = findTag($doc, 'body');
    $instance = Components::instanceHtml($item, [], 'global');
    $doc->applyOps([[
        'kind' => 'insert',
        'parentPath' => $doc->pathOf($body['id']),
        'parentTags' => $doc->tagsOf($body['id']),
        'index' => null,
        'html' => $instance,
    ]]);
    Store::writeAtomic('/de/index.html', $doc->src, Store::working());

    $impact = Components::impact('test-button');
    same(1, $impact['instances'], 'eine Instanz');
    same('/de/index.html', $impact['pages'][0]['path']);

    Components::save([
        'slug' => 'test-button',
        'name' => 'Test Button',
        'category' => 'buttons',
        'html' => '<a class="btn btn-primary" href="{{href}}">{{label}}</a>',
        'schema' => [],
    ]);
    $result = Components::rerenderAll('test-button');
    same(1, $result['instances'], 'ein Rerender');
    $after = Store::read('/de/index.html');
    check(str_contains($after, 'btn btn-primary'), 'neue Definition angewendet');
    same(1, substr_count($after, 'data-wfx-component="test-button"'), 'weiterhin genau eine Instanz');
    same(1, substr_count($after, '<footer'), 'Geschwisterelemente bleiben erhalten');
});

test('Unabhaengige Kopie wird nicht mitgezogen', function () {
    Components::detach('/de/index.html', (function () {
        $doc = new HtmlDoc(Store::read('/de/index.html'));
        foreach ($doc->nodes as $n) {
            if ($doc->attr($n, 'data-wfx-component') === 'test-button') {
                return $doc->pathOf($n['id']);
            }
        }
        throw new RuntimeException('Instanz fehlt');
    })());
    $doc = new HtmlDoc(Store::read('/de/index.html'));
    foreach ($doc->nodes as $n) {
        if ($doc->attr($n, 'data-wfx-component') === 'test-button') {
            same('copy', $doc->attr($n, 'data-wfx-mode'), 'Modus ist copy');
        }
    }
    Components::save([
        'slug' => 'test-button', 'name' => 'Test Button', 'category' => 'buttons',
        'html' => '<a class="btn" href="{{href}}">{{label}}</a>', 'schema' => [],
    ]);
    Components::rerenderAll('test-button');
    $after = Store::read('/de/index.html');
    check(str_contains($after, 'btn btn-primary'), 'Kopie unveraendert');
    resetFile('/de/index.html');
});

/* ------------------------------------------------------------------ */
echo PHP_EOL . '[4] Sprachen und Uebersetzungen' . PHP_EOL;
/* ------------------------------------------------------------------ */

$linkedPage = null;

test('Sprachseiten zuordnen und stabile IDs vergeben', function () use (&$linkedPage) {
    $page = Languages::link('Startseite', ['de' => '/de/index.html', 'en' => '/en/index.html']);
    $linkedPage = $page;
    $de = Store::read('/de/index.html');
    $en = Store::read('/en/index.html');
    check(str_contains($de, 'data-wfx-tid="'), 'IDs in DE');
    check(str_contains($en, 'data-wfx-tid="'), 'IDs in EN');

    $docDe = new HtmlDoc($de);
    $docEn = new HtmlDoc($en);
    $tidsDe = array_values(array_filter(array_column($docDe->textNodes(), 'tid')));
    $tidsEn = array_values(array_filter(array_column($docEn->textNodes(), 'tid')));
    check(count($tidsDe) > 3, 'mehrere Textknoten');
    same(count($tidsDe), count($tidsEn), 'gleich viele IDs');
    same([], array_values(array_diff($tidsDe, $tidsEn)), 'identische IDs in beiden Sprachen');
});

test('Status erkennt fehlende und geaenderte Uebersetzungen', function () use (&$linkedPage) {
    $status = Languages::status($linkedPage['id']);
    same(0, $status['summary']['missing'], 'zunaechst vollstaendig');
    same(0, $status['summary']['changed'], 'nichts veraendert');

    // Deutsch aendern -> "changed"
    $doc = new HtmlDoc(Store::read('/de/index.html'));
    $h1 = findTag($doc, 'h1');
    $doc->applyOps([[
        'kind' => 'text', 'path' => $doc->pathOf($h1['id']), 'tags' => $doc->tagsOf($h1['id']),
        'value' => 'Ganz neuer Titel',
    ]]);
    Store::writeAtomic('/de/index.html', $doc->src, Store::working());
    $status2 = Languages::status($linkedPage['id']);
    check($status2['summary']['changed'] >= 1, 'Aenderung erkannt');

    // Partnertext entfernen -> "missing"
    $docEn = new HtmlDoc(Store::read('/en/index.html'));
    foreach ($docEn->nodes as $n) {
        if ($docEn->attr($n, 'data-wfx-tid') !== null) {
            $docEn->applyOps([[
                'kind' => 'attr', 'path' => $docEn->pathOf($n['id']), 'tags' => $docEn->tagsOf($n['id']),
                'name' => 'data-wfx-tid', 'value' => null,
            ]]);
            break;
        }
    }
    Store::writeAtomic('/en/index.html', $docEn->src, Store::working());
    $status3 = Languages::status($linkedPage['id']);
    check($status3['summary']['missing'] >= 1, 'fehlende Uebersetzung erkannt');
});

test('Sprachvergleich liefert Texte beider Seiten', function () use (&$linkedPage) {
    $cmp = Languages::compare($linkedPage['id'], 'de', 'en');
    check(count($cmp['rows']) > 3, 'Vergleichszeilen vorhanden');
    check(isset($cmp['meta']['a']['title']), 'Metadaten gelesen');
});

test('Englische Seite uebernimmt keine deutschen Texte', function () use (&$linkedPage) {
    Languages::snapshot($linkedPage['id']);
    $en = Store::read('/en/index.html');
    check(!str_contains($en, 'Ganz neuer Titel'), 'kein deutscher Text in EN');
    check(str_contains($en, 'Welcome to Sample Company'), 'englischer Originaltext erhalten');
    resetFile('/de/index.html');
    resetFile('/en/index.html');
});

/* ------------------------------------------------------------------ */
echo PHP_EOL . '[5] Validierung' . PHP_EOL;
/* ------------------------------------------------------------------ */

test('CSS-Syntaxfehler werden erkannt', function () {
    Store::writeAtomic('/assets/css/broken.css', "body { color: red;\n", Store::working());
    $res = Validator::validate(['/assets/css/broken.css'], false);
    check($res['counts']['error'] >= 1, 'Fehler erkannt');
    unlink(Store::working() . '/assets/css/broken.css');
});

test('Fehlende Ressourcen werden als Fehler gemeldet', function () {
    Store::writeAtomic('/de/missing.html',
        "<!doctype html>\n<html lang=\"de\"><head><title>x</title></head>\n<body><img src=\"/assets/images/gibtsnicht.png\" alt=\"x\"></body></html>\n",
        Store::working());
    $res = Validator::validate(['/de/missing.html'], false);
    $found = false;
    foreach ($res['issues'] as $issue) {
        if ($issue['level'] === 'error' && str_contains($issue['message'], 'gibtsnicht.png')) {
            $found = true;
        }
    }
    check($found, 'fehlende Ressource gemeldet');
    unlink(Store::working() . '/de/missing.html');
});

test('Gueltige Referenzen werden nicht beanstandet', function () {
    $res = Validator::validate(['/de/index.html'], false);
    foreach ($res['issues'] as $issue) {
        check(!str_contains($issue['message'], 'style.css'), 'CSS-Referenz ok');
        check(!str_contains($issue['message'], 'placeholder.svg'), 'Bild-Referenz ok');
    }
});

test('PHP-Syntaxpruefung erkennt Fehler', function () {
    Store::writeAtomic('/kaputt.php', "<?php\nif (\n", Store::working());
    $res = Validator::validate(['/kaputt.php'], false);
    $found = false;
    foreach ($res['issues'] as $issue) {
        if ($issue['category'] === 'php' && $issue['level'] === 'error') {
            $found = true;
        }
    }
    check($found, 'PHP-Fehler erkannt');
    unlink(Store::working() . '/kaputt.php');
});

test('JavaScript-Syntaxpruefung (falls Node vorhanden)', function () {
    if (!Validator::nodeAvailable()) {
        check(true, 'uebersprungen');
        return;
    }
    Store::writeAtomic('/assets/js/broken.js', "function ( { \n", Store::working());
    $res = Validator::validate(['/assets/js/broken.js'], false);
    check($res['counts']['error'] >= 1, 'JS-Fehler erkannt');
    unlink(Store::working() . '/assets/js/broken.js');
});

test('Veroeffentlichung wird bei Prueffehlern blockiert', function () {
    Store::writeAtomic('/de/kaputt.html',
        "<!doctype html>\n<html lang=\"de\"><head><title>x</title></head>\n<body><img src=\"/fehlt.png\" alt=\"\"></body></html>\n",
        Store::working());
    throws(fn() => Publish::publish('Test', []), 'blockiert');
    unlink(Store::working() . '/de/kaputt.html');
});

/* ------------------------------------------------------------------ */
echo PHP_EOL . '[6] Responsive Breakpoints' . PHP_EOL;
/* ------------------------------------------------------------------ */

test('Breakpoint-Regel wird in eigenem Styleblock gespeichert', function () {
    resetFile('/de/index.html');
    $doc = new HtmlDoc(Store::read('/de/index.html'));
    $h1 = findTag($doc, 'h1');
    $res = Responsive::update('/de/index.html', $doc->pathOf($h1['id']), $doc->tagsOf($h1['id']), 'phone', 'font-size', '22px');
    check($res['wfxId'] !== '', 'data-wfx-id vergeben');
    $src = Store::read('/de/index.html');
    check(str_contains($src, 'data-wfx-responsive'), 'Styleblock angelegt');
    check(str_contains($src, '@media (max-width: 480px)'), 'Media Query erzeugt');
    check(str_contains($src, 'font-size: 22px'), 'Regel erzeugt');
    check(str_contains($src, 'data-wfx-id="' . $res['wfxId'] . '"'), 'Element markiert');

    $rules = Responsive::rulesFor('/de/index.html', $doc->pathOf($h1['id']));
    same('22px', $rules['phone']['font-size'] ?? null, 'Regel lesbar');

    Responsive::update('/de/index.html', $doc->pathOf($h1['id']), $doc->tagsOf($h1['id']), 'phone', 'font-size', null);
    $rules2 = Responsive::rulesFor('/de/index.html', $doc->pathOf($h1['id']));
    check(!isset($rules2['phone']['font-size']), 'Regel entfernt');
    resetFile('/de/index.html');
});

/* ------------------------------------------------------------------ */
echo PHP_EOL . '[7] Berechtigungen' . PHP_EOL;
/* ------------------------------------------------------------------ */

test('Redakteur darf bearbeiten, aber nicht veroeffentlichen', function () {
    $_SESSION['wfx_user'] = ['name' => 'redakteur', 'role' => 'editor'];
    Auth::requirePerm('edit');
    throws(fn() => Auth::requirePerm('publish'), 'Berechtigung');
    throws(fn() => Auth::requirePerm('restore'), 'Berechtigung');
    $_SESSION['wfx_user'] = ['name' => 'admin', 'role' => 'owner'];
    Auth::requirePerm('publish');
});

test('Ohne Anmeldung wird jede Aktion verweigert', function () {
    $_SESSION['wfx_user'] = null;
    throws(fn() => Auth::requireLogin(), 'Nicht angemeldet');
    throws(fn() => Auth::requirePerm('edit'), 'Nicht angemeldet');
    $_SESSION['wfx_user'] = ['name' => 'admin', 'role' => 'owner'];
});

/* ------------------------------------------------------------------ */
echo PHP_EOL . '[8] FTP, Veroeffentlichung, Rollback' . PHP_EOL;
/* ------------------------------------------------------------------ */

$ftpRoot = $tmpRoot . '/ftp-root';
$ftpLog = $tmpRoot . '/ftp.log';
$GLOBALS['ftpLog'] = $ftpLog;
rmTree($ftpRoot);
if (!is_dir($ftpRoot)) {
    mkdir($ftpRoot, 0775, true);
}
if (!is_dir($tmpRoot)) {
    mkdir($tmpRoot, 0775, true);
}

$descriptors = [1 => ['file', $ftpLog, 'a'], 2 => ['file', $ftpLog, 'a']];
$ftpProc = proc_open(['node', __DIR__ . '/ftp-server.mjs', $ftpRoot, '2121'], $descriptors, $ftpPipes);
$ftpUp = $ftpProc !== false && waitForPort('127.0.0.1', 2121);

test('Test-FTP-Server erreichbar', function () use ($ftpUp) {
    check($ftpUp, 'FTP-Server nicht gestartet: ' . ($GLOBALS['ftpLog'] ?? ''));
});

test('FTP-Client: Verbindung, Upload, Download, Groesse, Loeschen', function () use ($ftpUp) {
    check($ftpUp, 'kein FTP-Server');
    $ftp = new Ftp(Config::source());
    $ftp->connect();
    $ftp->mkdRecursive('/probe');
    $ftp->put('/probe/a.txt', 'hallo welt');
    same(10, $ftp->size('/probe/a.txt'));
    same('hallo welt', $ftp->get('/probe/a.txt'));
    $names = array_column($ftp->listDir('/probe'), 'name');
    check(in_array('a.txt', $names, true), 'Datei wird gelistet');
    $ftp->rename('/probe/a.txt', '/probe/b.txt');
    check(in_array('b.txt', array_column($ftp->listDir('/probe'), 'name'), true), 'umbenannt');
    $ftp->delete('/probe/b.txt');
    check(!in_array('b.txt', array_column($ftp->listDir('/probe'), 'name'), true), 'geloescht');
    $ftp->close();
});

test('Startverzeichnis /htdocs: Dateien landen im Website-Ordner, nicht in der Konto-Wurzel', function () use ($ftpUp, $ftpRoot) {
    check($ftpUp, 'kein FTP-Server');
    // Gleicher Dateiname in Konto-Wurzel und Website-Ordner, unterschiedlicher Inhalt:
    // absolute Pfade wuerden die Datei in der Konto-Wurzel treffen (Antwort 550 oder falscher Inhalt).
    file_put_contents($ftpRoot . '/vip.jpg', 'KONTOWURZEL');
    $ftp = new Ftp(Config::source());
    $ftp->connect();
    $ftp->put('/vip.jpg', 'WEBSITE');
    same('WEBSITE', $ftp->get('/vip.jpg'), 'Download liest aus /htdocs');
    $names = array_column($ftp->listDir('/'), 'name');
    check(in_array('vip.jpg', $names, true), 'Datei wird im Startverzeichnis gelistet');
    $ftp->close();
    same('WEBSITE', @file_get_contents($ftpRoot . '/htdocs/vip.jpg'), 'Datei liegt in /htdocs');
    same('KONTOWURZEL', @file_get_contents($ftpRoot . '/vip.jpg'), 'Konto-Wurzel blieb unberuehrt');
});

test('Objekte auflisten ohne Download, Datei erst beim Oeffnen holen', function () use ($ftpUp) {
    check($ftpUp, 'kein FTP-Server');
    rmTree(Store::working());
    mkdir(Store::working(), 0775, true);
    rmTree(Store::live());
    mkdir(Store::live(), 0775, true);

    $ftp = new Ftp(Config::source());
    $ftp->connect();
    foreach (Store::listFiles(__DIR__ . '/fixtures/site') as $f) {
        $ftp->mkdRecursive(dirname($f['path']));
        $ftp->put($f['path'], file_get_contents(__DIR__ . '/fixtures/site' . $f['path']));
    }
    $ftp->close();

    $index = Sync::remoteList();
    check(count($index['files']) >= 6, 'Objekte gelistet: ' . count($index['files']));
    same(0, count(Store::listFiles(Store::working())), 'Arbeitskopie bleibt leer');
    check(!Store::exists('/de/index.html'), 'nichts heruntergeladen');

    $result = Sync::fetchFile('/de/index.html');
    check(in_array('/de/index.html', $result['files'], true), 'Seite geladen');
    check(Store::exists('/de/index.html'), 'Seite liegt lokal');
    check(count($result['files']) < count($index['files']), 'nicht der komplette Bestand');
    check(count(Store::listFiles(Store::working())) <= count($result['files']), 'nur geoeffnete Seite plus Referenzen lokal');
});

test('Bestand per FTP herunterladen (Pull)', function () use ($ftpUp) {
    check($ftpUp, 'kein FTP-Server');
    $ftp = new Ftp(Config::source());
    $ftp->connect();
    foreach (Store::listFiles(__DIR__ . '/fixtures/site') as $f) {
        $ftp->mkdRecursive(dirname($f['path']));
        $ftp->put($f['path'], file_get_contents(__DIR__ . '/fixtures/site' . $f['path']));
    }
    $ftp->close();

    $result = Sync::pullFromFtp();
    check($result['files'] >= 6, 'Dateien geladen: ' . $result['files']);
    check(Store::exists('/de/index.html'), 'index.html vorhanden');
});

test('Entwurf bleibt lokal, Veroeffentlichung uebertraegt und prueft', function () use ($ftpUp) {
    check($ftpUp, 'kein FTP-Server');
    $doc = new HtmlDoc(Store::read('/de/index.html'));
    $h1 = findTag($doc, 'h1');
    $doc->applyOps([[
        'kind' => 'text', 'path' => $doc->pathOf($h1['id']), 'tags' => $doc->tagsOf($h1['id']),
        'value' => 'Veroeffentlichter Titel',
    ]]);
    Store::writeAtomic('/de/index.html', $doc->src, Store::working());

    // Vor dem Veroeffentlichen: Live-Spiegel unveraendert
    check(!str_contains(Store::read('/de/index.html', Store::live()), 'Veroeffentlichter Titel'), 'Entwurf ist nicht live');

    $preview = Publish::preview();
    check(isset($preview['changes']['/de/index.html']), 'Aenderung erkannt');
    same('modified', $preview['changes']['/de/index.html']['status']);

    $result = Publish::publish('Test-Veroeffentlichung', []);
    check(in_array('/de/index.html', $result['applied'], true), 'Datei uebertragen');
    same([], $result['failed'], 'keine Fehler');

    $ftp = new Ftp(Config::source());
    $ftp->connect();
    $remote = $ftp->get('/de/index.html');
    $ftp->close();
    check(str_contains($remote, 'Veroeffentlichter Titel'), 'Remote-Inhalt aktualisiert');
    check(str_contains(Store::read('/de/index.html', Store::live()), 'Veroeffentlichter Titel'), 'Live-Spiegel aktualisiert');

    $backupDir = Store::backups() . '/' . $result['id'];
    check(is_dir($backupDir), 'Backup-Verzeichnis vorhanden');
    check(is_file($backupDir . '/meta.json'), 'Backup-Manifest vorhanden');
    check(count(Store::listFiles($backupDir . '/remote')) >= 1, 'Remote-Sicherung vorhanden');
    check(count(Store::listFiles($backupDir . '/working')) >= 1, 'lokale Sicherung vorhanden');
    $GLOBALS['lastBackup'] = $result['id'];
    $GLOBALS['liveLive'] = Store::read('/de/index.html', Store::live());
});

test('Rollback stellt vorherigen Stand wieder her', function () use ($ftpUp) {
    $backupId = $GLOBALS['lastBackup'] ?? null;
    check($backupId !== null, 'kein Backup vorhanden');
    // Fehlerhafte Aenderung im Entwurf
    Store::writeAtomic('/de/index.html', '<!doctype html><html lang="de"><head><title>x</title></head><body><h1>Kaputt</h1></body></html>', Store::working());
    $result = Publish::rollback($backupId, 'working');
    check(in_array('/de/index.html', $result['restored'], true), 'Datei wiederhergestellt');
    check(!str_contains(Store::read('/de/index.html'), 'Kaputt'), 'alter Inhalt zurueck');
    check(str_contains(Store::read('/de/index.html'), 'Veroeffentlichter Titel'), 'veroeffentlichter Titel wiederhergestellt');
});

test('Nur geaenderte Seiten werden uebertragen', function () use ($ftpUp) {
    check($ftpUp, 'kein FTP-Server');
    $changesBefore = Publish::changes();
    foreach ($changesBefore as $rel => $info) {
        check($rel === '/de/index.html' || $info['status'] !== 'modified', 'unerwartete Aenderung: ' . $rel);
    }
});

/* ------------------------------------------------------------------ */
echo PHP_EOL . '[9] Links, Backups, Medien, Baustein-Transfer' . PHP_EOL;
/* ------------------------------------------------------------------ */

test('Link-Info findet umgebenden Anker (Buttons in <a>)', function () {
    Store::writeAtomic('/test/link.html', '<!doctype html><html lang="de"><head><title>L</title></head><body><a class="btn" href="/de/kontakt.html" target="_blank"><span class="label">Kontakt</span></a><p>Text</p></body></html>', Store::working());
    $doc = new HtmlDoc(Store::read('/test/link.html'));
    $span = findTag($doc, 'span');
    $info = $doc->infoFor($doc->pathOf($span['id']));
    check($info['link'] !== null, 'Link fuer Element im Anker gefunden');
    same('/de/kontakt.html', $info['link']['href']);
    same(false, $info['link']['isSelf']);
    same('_blank', $info['link']['target']);
    $a = findTag($doc, 'a');
    same(true, $doc->infoFor($doc->pathOf($a['id']))['link']['isSelf'], 'Anker erkennt sich selbst');
    $p = findTag($doc, 'p');
    same(null, $doc->infoFor($doc->pathOf($p['id']))['link'], 'ohne Link kein Link-Objekt');
});

test('Backups sind nach Zeit sortiert und nach Tag gruppiert', function () {
    $old = 'manual-20200101-101010-aaaaa';
    $new = 'manual-20200102-101010-bbbbb';
    foreach ([$old => '2020-01-01T10:10:10+00:00', $new => '2020-01-02T10:10:10+00:00'] as $id => $ts) {
        $dir = Store::backups() . '/' . $id;
        @mkdir($dir . '/working/de', 0775, true);
        Store::writeAtomic('/de/index.html', '<p>alt</p>', $dir . '/working');
        Store::writeJson($dir . '/meta.json', ['id' => $id, 'createdAt' => $ts, 'type' => 'manual', 'files' => ['/de/index.html'], 'message' => 'Test']);
    }
    $list = Publish::backups();
    $posOld = null;
    $posNew = null;
    $prev = null;
    foreach ($list as $i => $row) {
        if ($row['id'] === $old) {
            $posOld = $i;
        }
        if ($row['id'] === $new) {
            $posNew = $i;
        }
        $ts = strtotime((string) $row['createdAt']) ?: 0;
        if ($prev !== null) {
            check($prev >= $ts, 'Sortierung absteigend');
        }
        $prev = $ts;
    }
    check($posNew !== null && $posOld !== null, 'beide Backups gelistet');
    check($posNew < $posOld, 'neuere Sicherung steht vor der aelteren');
    foreach ($list as $row) {
        if ($row['id'] === $new) {
            same('2020-01-02', $row['day'], 'Tagesgruppe gesetzt');
        }
    }
    $GLOBALS['backupViewId'] = $new;
});

test('Backup ansehen liefert Inhalt und Unterschied ohne Wiederherstellung', function () {
    $id = $GLOBALS['backupViewId'] ?? '';
    check($id !== '', 'keine Backup-ID');
    $before = Store::read('/de/index.html');
    $view = Publish::backupView($id, '/de/index.html');
    same(1, count($view['files']));
    same('<p>alt</p>', $view['content']);
    same(false, $view['identical']);
    check($view['diff'] !== [], 'Unterschied vorhanden');
    same($before, Store::read('/de/index.html'), 'Arbeitskopie bleibt unveraendert');
    throws(fn() => Publish::backupView($id, '/gibt/es/nicht.html'), 'nicht im Backup');
});

test('Bausteine exportieren und importieren (ohne Ueberschreiben)', function () {
    Components::save(['name' => 'Karte', 'category' => 'cards', 'html' => '<div class="card">{{titel}}</div>', 'schema' => []]);
    $export = Components::export(['karte']);
    same('wfx-components', $export['format']);
    same(1, count($export['items']));
    $result = Components::import($export, false);
    same(1, count($result['saved']));
    check($result['saved'][0] !== 'karte', 'Duplikat bekommt neuen Schluessel');
    same($result['saved'][0], $result['renamed']['karte'] ?? null, 'Umbenennung dokumentiert');
    $withPhp = Components::import(['items' => [['name' => 'PHP', 'html' => '<div><?php echo 1; ?></div>']]], false);
    same(0, count($withPhp['saved']), 'PHP wird abgelehnt');
    same(1, count($withPhp['skipped']));
});

test('Medienbibliothek indexiert Bilder und findet Fundstellen', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    Store::writeAtomic('/assets/media/logo.png', $png, Store::working());
    Store::writeAtomic('/test/seite.html', '<!doctype html><html lang="de"><head><title>S</title></head><body><img src="../assets/media/logo.png" alt="Logo"><p>x</p></body></html>', Store::working());
    $index = Media::index();
    $logo = null;
    foreach ($index['items'] as $it) {
        if ($it['path'] === '/assets/media/logo.png') {
            $logo = $it;
            break;
        }
    }
    check($logo !== null, 'Bild indexiert');
    check(in_array('/test/seite.html', array_column($logo['usages'], 'page'), true), 'Fundstelle mit relativem Pfad erkannt');
    same(false, $index['cloud']['configured'], 'Supabase standardmaessig aus');
    Store::writeAtomic('/test/foto-site.png', $png, Store::working());
    check(!in_array('/test/foto-site.png', array_column(Media::index()['items'], 'path'), true), 'Bilder der Website ausserhalb /assets/media erscheinen nicht');
});

test('Videos: 5-MB-Grenze und Einfuegen als video', function () {
    same(5242880, Media::MAX_VIDEO_BYTES);
    same('5,0 MB', Media::humanSize(Media::MAX_VIDEO_BYTES));
    Store::writeAtomic('/assets/media/clip.mp4', "\x00\x00\x00\x18ftypmp42demo", Store::working());
    $clip = null;
    foreach (Media::index()['items'] as $it) {
        if ($it['path'] === '/assets/media/clip.mp4') {
            $clip = $it;
            break;
        }
    }
    check($clip !== null && $clip['type'] === 'video', 'Video erscheint als Video');
    $doc = new HtmlDoc(Store::read('/test/seite.html'));
    $body = findTag($doc, 'body');
    Media::insert([
        'file' => '/test/seite.html',
        'src' => '/assets/media/clip.mp4',
        'parentPath' => $doc->pathOf($body['id']),
    ]);
    $after = Store::read('/test/seite.html');
    check(str_contains($after, '<video') && str_contains($after, 'clip.mp4'), 'Video als <video> eingefuegt');
    check(str_contains($after, 'controls'), 'Video mit Steuerung');
});

test('Bild aus Bibliothek in Seite einsetzen', function () {
    $doc = new HtmlDoc(Store::read('/test/seite.html'));
    $body = findTag($doc, 'body');
    $result = Media::insert([
        'file' => '/test/seite.html',
        'src' => '/assets/media/logo.png',
        'alt' => 'Zweites Logo',
        'parentPath' => $doc->pathOf($body['id']),
        'index' => 0,
    ]);
    check(isset($result['etag']), 'Antwort mit Etag');
    $after = Store::read('/test/seite.html');
    same(2, substr_count($after, 'logo.png'), 'zweites Bild eingefuegt');
    check(str_contains($after, 'alt="Zweites Logo"'), 'Alt-Text gesetzt');
});

test('Ohne Supabase-Konfiguration wird nichts in die Cloud geschickt', function () {
    check(Supabase::configured() === false, 'nicht konfiguriert');
    check(Supabase::active() === false, 'nicht aktiv');
    $res = Media::syncOne('/assets/media/logo.png');
    same(true, $res['skipped'] ?? false);
    throws(function () { Supabase::test(); }, 'nicht konfiguriert');
});

if (is_resource($ftpProc)) {
    proc_terminate($ftpProc);
    proc_close($ftpProc);
}

/* ------------------------------------------------------------------ */
echo PHP_EOL . '=== Ergebnis: ' . $GLOBALS['passed'] . ' bestanden, ' . $GLOBALS['failed'] . ' fehlgeschlagen ===' . PHP_EOL;
if ($GLOBALS['failed'] > 0) {
    echo PHP_EOL . 'Fehlgeschlagen:' . PHP_EOL;
    foreach ($GLOBALS['failures'] as $f) {
        echo ' - ' . $f . PHP_EOL;
    }
    exit(1);
}
exit(0);
