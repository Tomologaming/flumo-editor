<?php
declare(strict_types=1);

/**
 * Preview - liefert Seiten der Arbeitskopie (Entwurf) oder des Live-Spiegels
 * in einem iframe-tauglichen Dokument mit Editier-Overlay.
 */
final class Preview
{
    public static function serve(string $path, string $variant = 'draft'): void
    {
        Auth::requireLogin();
        $file = Store::normRel($path);
        $ext = Store::extOf($file);
        if (!in_array($ext, ['html', 'htm', 'php'], true)) {
            throw new AppError('Nur HTML- und PHP-Seiten koennen in der Vorschau geoeffnet werden.', 415);
        }
        Store::assertEditable($file);
        $base = $variant === 'live' ? Store::live() : Store::working();
        if (!Store::exists($file, $base)) {
            throw new AppError('Seite existiert in dieser Variante nicht: ' . $file, 404);
        }
        $raw = Store::read($file, $base);
        $notice = '';
        if ($ext === 'php') {
            $render = (bool) (Config::all()['renderPhp'] ?? true);
            if (!$render) {
                $notice = 'PHP-Ausfuehrung ist deaktiviert - angezeigt wird der Quelltext.';
                $raw = self::escapeForDisplay($raw);
            } else {
                [$ok, $out, $error] = self::renderPhp($file, $base);
                if (!$ok) {
                    $notice = 'PHP-Vorschau fehlgeschlagen: ' . $error;
                    $raw = self::escapeForDisplay($raw);
                } else {
                    $raw = $out;
                }
            }
        }
        $html = self::inject($raw, $file, $variant, $notice);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, must-revalidate');
        header('X-Frame-Options: SAMEORIGIN');
        echo $html;
    }

    private static function escapeForDisplay(string $src): string
    {
        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Quelltext</title></head><body style="margin:0;background:#0f172a;color:#cbd5e1;font:13px/1.5 ui-monospace,Consolas,monospace"><pre style="padding:16px;white-space:pre-wrap">'
            . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre></body></html>';
    }

    /** @return array{0:bool,1:string,2:string} */
    public static function renderPhp(string $file, string $base): array
    {
        $script = dirname(__DIR__, 2) . '/bin/php-render.php';
        if (!is_file($script)) {
            return [false, '', 'Renderer fehlt'];
        }
        try {
            $abs = Store::resolveUnder($base, $file);
        } catch (Throwable $e) {
            return [false, '', $e->getMessage()];
        }
        $root = realpath($base) ?: $base;
        $cmd = [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'max_execution_time=10', $script, $abs, $root, $file];
        $res = Validator::run($cmd, 12);
        $out = $res['out'];
        if ($res['code'] !== 0 && trim($out) === '') {
            return [false, '', trim($res['err']) !== '' ? mb_substr(trim($res['err']), 0, 300) : 'Ausgabe leer (Code ' . $res['code'] . ')'];
        }
        if (stripos($out, 'Fatal error') !== false) {
            $pos = stripos($out, 'Fatal error');
            return [false, '', mb_substr(strip_tags(substr($out, $pos, 400)), 0, 300)];
        }
        return [true, $out, ''];
    }

    private static function inject(string $html, string $file, string $variant, string $notice): string
    {
        $dir = Store::dirOf($file);
        $baseHref = '/site' . ($dir === '/' ? '' : $dir) . '/';
        $token = Csrf::token();
        $config = json_encode([
            'file' => $file,
            'variant' => $variant,
            'notice' => $notice,
            'base' => $baseHref,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $head = '<base href="' . htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8') . '">' . "\n"
            . '<script>window.__WFX_PAGE = ' . $config . ';</script>' . "\n"
            . '<link rel="stylesheet" href="/public/overlay.css">' . "\n";

        if (preg_match('/<head\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            $html = substr($html, 0, $pos) . "\n" . $head . substr($html, $pos);
        } elseif (preg_match('/<html\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            $html = substr($html, 0, $pos) . "\n<head>\n" . $head . "</head>\n" . substr($html, $pos);
        } else {
            $html = "<!doctype html>\n<html lang=\"de\">\n<head>\n<meta charset=\"utf-8\">\n" . $head . "</head>\n<body>\n" . $html . "\n</body>\n</html>";
        }

        $banner = '';
        if ($notice !== '') {
            $banner = '<div id="wfx-notice" style="position:fixed;left:0;right:0;top:0;z-index:2147483000;background:#7c2d12;color:#fff;font:13px/1.4 system-ui,sans-serif;padding:6px 10px">'
                . htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        $script = '<script src="/public/overlay.js"></script>';
        if (preg_match('/<\/body>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1];
            $html = substr($html, 0, $pos) . $banner . $script . substr($html, $pos);
        } else {
            $html .= $banner . $script;
        }
        return $html;
    }

    /** Liefert eine Datei aus der Arbeitskopie als statische Ressource. */
    public static function serveAsset(string $uri): void
    {
        Auth::requireLogin();
        $rel = $uri === '/' ? '' : $uri;
        try {
            $rel = Store::normRel($rel === '' ? '/index.html' : $rel, true);
        } catch (Throwable $e) {
            self::notFound($uri);
            return;
        }
        $file = Store::resolveUnder(Store::working(), $rel);
        if (!is_file($file) && !str_ends_with($file, '/')) {
            $alt = $file . '/index.html';
            if (is_file($alt)) {
                $file = $alt;
            }
        }
        if (!is_file($file)) {
            self::notFound($uri);
            return;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = self::mime($ext);
        header('Content-Type: ' . $mime);
        header('Cache-Control: no-store');
        header('Content-Length: ' . filesize($file));
        readfile($file);
    }

    private static function notFound(string $uri): void
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><body style="font:14px system-ui;padding:24px;color:#334155">'
            . '<strong>Nicht gefunden:</strong> ' . htmlspecialchars($uri, ENT_QUOTES, 'UTF-8')
            . '<p style="color:#64748b">Diese Ressource existiert nicht in der Arbeitskopie. '
            . 'Bitte pruefen Sie den Pfad oder laden Sie den Bestand erneut herunter.</p></body>';
    }

    public static function mime(string $ext): string
    {
        $map = [
            'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8',
            'css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8',
            'mjs' => 'application/javascript; charset=utf-8', 'json' => 'application/json; charset=utf-8',
            'xml' => 'application/xml; charset=utf-8', 'txt' => 'text/plain; charset=utf-8',
            'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
            'mp3' => 'audio/mpeg', 'pdf' => 'application/pdf',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }
}
