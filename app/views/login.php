<?php
declare(strict_types=1);

if (!Config::installed()) {
    header('Location: /setup');
    exit;
}
if (Auth::user() !== null) {
    header('Location: /');
    exit;
}

$error = '';
$notice = $_SESSION['install_notice'] ?? '';
unset($_SESSION['install_notice']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Csrf::check();
        Auth::attempt((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
        header('Location: /');
        exit;
    } catch (AppError $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Anmelden - Website-Editor</title>
<link rel="stylesheet" href="/public/auth.css">
</head>
<body>
<main class="card narrow">
    <h1>Website-Editor</h1>
    <p class="lead">Anmeldung fuer den lokalen Editor.</p>
    <?php if ($notice): ?><div class="msg ok"><?= wfx_h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="msg err"><?= wfx_h($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= wfx_h(Csrf::token()) ?>">
        <label>Benutzername
            <input name="username" required autofocus value="<?= wfx_h($_POST['username'] ?? '') ?>">
        </label>
        <label>Passwort
            <input type="password" name="password" required>
        </label>
        <div class="actions">
            <button class="primary" type="submit">Anmelden</button>
        </div>
    </form>
</main>
</body>
</html>
