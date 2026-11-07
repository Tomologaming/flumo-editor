<?php
declare(strict_types=1);
/** Erstinstallation: Zugang + Quelle (FTP oder lokales Verzeichnis). */

if (Config::installed()) {
    header('Location: /login');
    exit;
}

$errors = [];
$done = false;
$post = $_POST;
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

if ($isPost) {
    try {
        Csrf::check();
        $action = (string) ($post['action'] ?? 'install');
        $source = [
            'type' => ($post['sourceType'] ?? 'ftp') === 'local' ? 'local' : 'ftp',
            'host' => trim((string) ($post['ftpHost'] ?? '')),
            'port' => max(1, min(65535, (int) ($post['ftpPort'] ?? 21))),
            'tls' => !empty($post['ftpTls']),
            'passive' => true,
            'implicit' => false,
            'user' => (string) ($post['ftpUser'] ?? ''),
            'pass' => (string) ($post['ftpPass'] ?? ''),
            'root' => trim((string) ($post['ftpRoot'] ?? '/htdocs')),
            'localPath' => trim((string) ($post['localPath'] ?? '')),
        ];

        if ($action === 'testftp') {
            $ftp = new Ftp($source);
            $result = $ftp->test();
            $errors[] = 'Verbindung erfolgreich: ' . $result['pwd'] . ' - ' . $result['count'] . ' Eintraege'
                . ($result['sample'] ? ' (' . implode(', ', array_slice($result['sample'], 0, 8)) . ')' : '');
        } else {
            $username = trim((string) ($post['username'] ?? ''));
            $password = (string) ($post['password'] ?? '');
            $password2 = (string) ($post['password2'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9._-]{3,40}$/', $username)) {
                $errors[] = 'Benutzername: 3-40 Zeichen (Buchstaben, Zahlen, . _ -).';
            }
            if (strlen($password) < 10) {
                $errors[] = 'Das Passwort muss mindestens 10 Zeichen lang sein.';
            }
            if ($password !== $password2) {
                $errors[] = 'Die Passwoerter stimmen nicht ueberein.';
            }
            if ($errors === []) {
                $cfg = Config::defaults();
                $cfg['installed'] = true;
                $cfg['installedAt'] = date('c');
                $cfg['users'] = [$username => ['hash' => Auth::hashPassword($password), 'role' => 'owner', 'createdAt' => date('c')]];
                $cfg['source'] = $source;
                $cfg['baseUrl'] = rtrim(trim((string) ($post['baseUrl'] ?? '')), '/');
                $cfg['renderPhp'] = !empty($post['renderPhp']);
                Config::save($cfg);
                Store::init();
                Journal::write('install', $username, ['source' => $source['type']]);

                if (!empty($post['pullNow'])) {
                    try {
                        if ($source['type'] === 'ftp') {
                            Sync::pullFromFtp();
                        } elseif ($source['localPath'] !== '') {
                            Sync::importLocal($source['localPath']);
                        }
                    } catch (Throwable $e) {
                        Journal::write('install-pull-failed', $username, ['error' => $e->getMessage()]);
                        $_SESSION['install_notice'] = 'Installation abgeschlossen, aber der Import schlug fehl: ' . $e->getMessage()
                            . ' - Sie koennen ihn im Editor unter "Verbindung" wiederholen.';
                    }
                }
                header('Location: /login');
                exit;
            }
        }
    } catch (AppError $e) {
        $errors[] = $e->getMessage();
    } catch (Throwable $e) {
        $errors[] = 'Fehler: ' . $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Website-Editor einrichten</title>
<link rel="stylesheet" href="/public/auth.css">
</head>
<body>
<main class="card">
    <h1>Website-Editor einrichten</h1>
    <p class="lead">Der Editor laeuft lokal auf diesem PC. Er holt die Dateien per FTP von Ihrem Webspace,
        bearbeitet sie in einer lokalen Arbeitskopie und laedt sie erst beim Veroeffentlichen zurueck.</p>

    <?php foreach ($errors as $err): ?>
        <div class="msg <?= str_starts_with($err, 'Verbindung erfolgreich') ? 'ok' : 'err' ?>"><?= wfx_h($err) ?></div>
    <?php endforeach; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= wfx_h(Csrf::token()) ?>">
        <h2>1. Zugang zum Editor</h2>
        <div class="grid2">
            <label>Benutzername
                <input name="username" value="<?= wfx_h($post['username'] ?? 'admin') ?>" required>
            </label>
            <label>Passwort (min. 10 Zeichen)
                <input type="password" name="password" required minlength="10">
            </label>
        </div>
        <label>Passwort wiederholen
            <input type="password" name="password2" required minlength="10">
        </label>

        <h2>2. Datenquelle</h2>
        <div class="radios">
            <label><input type="radio" name="sourceType" value="ftp" <?= ($post['sourceType'] ?? 'ftp') === 'ftp' ? 'checked' : '' ?>> FTP-Server (empfohlen)</label>
            <label><input type="radio" name="sourceType" value="local" <?= ($post['sourceType'] ?? '') === 'local' ? 'checked' : '' ?>> Lokales Verzeichnis (z. B. FileZilla-Export)</label>
        </div>

        <fieldset>
            <legend>FTP</legend>
            <div class="grid2">
                <label>Host
                    <input name="ftpHost" value="<?= wfx_h($post['ftpHost'] ?? 'ftpupload.net') ?>" placeholder="ftpupload.net">
                </label>
                <label>Port
                    <input name="ftpPort" type="number" value="<?= wfx_h($post['ftpPort'] ?? 21) ?>">
                </label>
            </div>
            <div class="grid2">
                <label>Benutzer
                    <input name="ftpUser" value="<?= wfx_h($post['ftpUser'] ?? '') ?>">
                </label>
                <label>Passwort
                    <input type="password" name="ftpPass" value="">
                </label>
            </div>
            <label>Startverzeichnis auf dem Server
                <input name="ftpRoot" value="<?= wfx_h($post['ftpRoot'] ?? '/htdocs') ?>" placeholder="/htdocs">
            </label>
            <label class="check"><input type="checkbox" name="ftpTls" value="1" <?= !empty($post['ftpTls']) ? 'checked' : '' ?>> Explizites TLS (FTPS) verwenden</label>
            <p class="hint">Passwoerter werden nur in <code>app/config.php</code> auf diesem PC gespeichert (Dateirechte 0600),
                niemals in der Website oder im Repository. Bei InfinityFree lautet der Host meist <code>ftpupload.net</code>,
                das Startverzeichnis <code>/htdocs</code>.</p>
        </fieldset>

        <fieldset>
            <legend>Lokales Verzeichnis</legend>
            <label>Pfad
                <input name="localPath" value="<?= wfx_h($post['localPath'] ?? '') ?>" placeholder="C:\Users\...\htdocs">
            </label>
        </fieldset>

        <h2>3. Oeffentliche Adresse</h2>
        <label>Basis-URL der Website (fuer Live-Pruefung nach dem Veroeffentlichen)
            <input name="baseUrl" value="<?= wfx_h($post['baseUrl'] ?? '') ?>" placeholder="https://meine-seite.infinityfreeapp.com">
        </label>

        <label class="check"><input type="checkbox" name="renderPhp" value="1" <?= empty($post) || !empty($post['renderPhp']) ? 'checked' : '' ?>>
            PHP-Seiten lokal ausfuehren (echte Vorschau; fuehrt Website-Code auf diesem PC aus)</label>
        <label class="check"><input type="checkbox" name="pullNow" value="1" <?= empty($post) || !empty($post['pullNow']) ? 'checked' : '' ?>>
            Dateien direkt jetzt herunterladen</label>

        <div class="actions">
            <button type="submit" name="action" value="testftp" formnovalidate>FTP-Verbindung testen</button>
            <button type="submit" name="action" value="install" class="primary">Editor einrichten</button>
        </div>
    </form>
</main>
</body>
</html>
