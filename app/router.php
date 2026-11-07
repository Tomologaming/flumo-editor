<?php
declare(strict_types=1);

/**
 * Front-Controller fuer den lokalen Editor-Server (php -S).
 */

$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$uri = preg_replace('#/+#', '/', $uri) ?: '/';

// Statische Dateien des Editors direkt ausliefern
if (str_starts_with($uri, '/public/')) {
    return false;
}

require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (str_starts_with($uri, '/api/')) {
    Api::dispatch($method, $uri);
    exit;
}

try {
    if ($uri === '/setup') {
        require __DIR__ . '/views/setup.php';
        exit;
    }
    if ($uri === '/login') {
        require __DIR__ . '/views/login.php';
        exit;
    }
    if ($uri === '/logout') {
        if ($method === 'POST') {
            Csrf::check();
            Auth::logout();
        }
        header('Location: /login');
        exit;
    }
    if ($uri === '/preview') {
        require __DIR__ . '/lib/Preview.php';
        Preview::serve((string) ($_GET['path'] ?? ''), (string) ($_GET['variant'] ?? 'draft'));
        exit;
    }
    if ($uri === '/handbuch') {
        Auth::requireLogin();
        require __DIR__ . '/views/handbuch.php';
        exit;
    }
    if ($uri === '/') {
        if (!Config::installed()) {
            header('Location: /setup');
            exit;
        }
        if (Auth::user() === null) {
            header('Location: /login');
            exit;
        }
        require __DIR__ . '/views/editor.php';
        exit;
    }
    if ($uri === '/healthz') {
        header('Content-Type: text/plain');
        echo 'ok';
        exit;
    }

    // Alles andere: Datei aus der Arbeitskopie ausliefern (Vorschau-Assets,
    // absolute Pfade wie /assets/css/style.css).
    require __DIR__ . '/lib/Preview.php';
    Preview::serveAsset($uri);
} catch (AppError $e) {
    http_response_code($e->status);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Fehler: ' . $e->getMessage();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Interner Fehler: ' . $e->getMessage();
}
