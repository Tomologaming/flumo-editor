<?php
declare(strict_types=1);

/**
 * Rendert eine PHP-Seite der Arbeitskopie in einem isolierten Prozess
 * und gibt ausschliesslich das erzeugte HTML aus.
 * Aufruf: php php-render.php <absfile> <docroot> <relpath>
 */

$file = $argv[1] ?? '';
$docroot = $argv[2] ?? '';
$rel = $argv[3] ?? '/';

if (!is_file($file)) {
    fwrite(STDERR, "Datei fehlt\n");
    exit(2);
}

$dir = dirname($file);
chdir($dir);

$_SERVER['DOCUMENT_ROOT'] = $docroot;
$_SERVER['SCRIPT_FILENAME'] = $file;
$_SERVER['SCRIPT_NAME'] = $rel;
$_SERVER['PHP_SELF'] = $rel;
$_SERVER['REQUEST_URI'] = $rel;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['QUERY_STRING'] = '';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET = [];
$_POST = [];
$_COOKIE = [];
$_REQUEST = [];

$notices = [];
set_error_handler(static function (int $no, string $msg, string $f = '', int $line = 0) use (&$notices) {
    $notices[] = $msg . ' (' . basename($f) . ':' . $line . ')';
    return true;
});

ob_start();
try {
    include $file;
} catch (Throwable $e) {
    $notices[] = get_class($e) . ': ' . $e->getMessage();
    if (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><div style="font:14px system-ui;padding:20px;background:#fef2f2;color:#7f1d1d">'
        . '<strong>PHP-Fehler bei der Vorschau</strong><p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p></div>';
    exit(1);
}
$out = (string) ob_get_clean();

if ($notices) {
    $comment = implode(' | ', array_slice($notices, 0, 10));
    $out .= "\n<!-- WFX-PHP-NOTICE: " . str_replace('--', '- -', $comment) . " -->";
}
echo $out;
