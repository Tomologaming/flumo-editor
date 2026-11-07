<?php
declare(strict_types=1);
$user = Auth::user();
if ($user === null) {
    header('Location: /login');
    exit;
}
$perms = Auth::perms($user['role']);
$cfg = Config::all();
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Website-Editor</title>
<link rel="stylesheet" href="/public/editor.css">
</head>
<body>
<div id="app" class="app" data-role="<?= wfx_h($user['role']) ?>">

    <header class="topbar">
        <div class="brand">
            <span class="dot"></span>
            <span class="brand-name">Website-Editor</span>
            <span class="brand-sub" id="sourceInfo"><?= wfx_h(($cfg['source']['host'] ?? '') ?: 'lokale Quelle') ?></span>
        </div>

        <div class="page-picker">
            <label for="pageSelect" class="sr">Seite</label>
            <select id="pageSelect"></select>
            <span class="draft-badge hidden" id="draftBadge" title="Diese Seite hat unveroeffentlichte Aenderungen">Entwurf</span>
        </div>

        <div class="devices" role="group" aria-label="Vorschaugroesse">
            <button class="dev active" data-device="desktop" title="Desktop (1440 px)">Desktop</button>
            <button class="dev" data-device="tablet" title="Tablet (834 px)">Tablet</button>
            <button class="dev" data-device="phone" title="Smartphone (390 px)">Mobil</button>
        </div>

        <div class="tools">
            <button id="btnUndo" class="icon" title="Rueckgaengig (Strg+Z)" disabled>&#8630;</button>
            <button id="btnRedo" class="icon" title="Wiederholen (Strg+Shift+Z)" disabled>&#8631;</button>
            <button id="btnReload" class="icon" title="Vorschau neu laden">&#8635;</button>
            <button id="btnCompare" title="Vorher/Nachher vergleichen">Vergleich</button>
            <a class="icon help" href="/handbuch" target="_blank" rel="noopener" title="Handbuch und Tutorial">?</a>
            <button id="btnSave" class="primary" title="Offene Aenderungen als Entwurf sichern (Strg+S)">Entwurf speichern</button>
            <?php if (in_array('publish', $perms, true)): ?>
                <button id="btnPublish" class="publish" title="Aenderungen veroeffentlichen">Veroeffentlichen</button>
            <?php endif; ?>
            <form method="post" action="/logout" class="logout-form">
                <input type="hidden" name="_csrf" value="<?= wfx_h(Csrf::token()) ?>">
                <button type="submit" class="icon" title="Abmelden (<?= wfx_h($user['name']) ?>)">&#9099;</button>
            </form>
        </div>
    </header>

    <div class="workspace">
        <aside class="sidebar" id="sidebar">
            <nav class="tabs" id="tabs">
                <button class="tab active" data-tab="pages" title="Seiten">Seiten</button>
                <button class="tab" data-tab="components" title="Bausteine">Bausteine</button>
                <button class="tab" data-tab="media" title="Medienbibliothek">Medien</button>
                <button class="tab" data-tab="languages" title="Sprachen &amp; Uebersetzungen">Sprachen</button>
                <button class="tab" data-tab="publish" title="Veroeffentlichen">Livegang</button>
                <button class="tab" data-tab="backups" title="Backups &amp; Rollback">Backups</button>
                <button class="tab" data-tab="source" title="Quelltext">Code</button>
                <button class="tab" data-tab="settings" title="Einstellungen">Setup</button>
            </nav>
            <div class="panel" id="panel"></div>
        </aside>

        <section class="stage">
            <div class="crumbbar" id="breadcrumb">
                <span class="crumb-empty">Kein Element ausgewaehlt - im Vorschaufenster klicken.</span>
            </div>
            <div class="stage-scroll" id="stageScroll">
                <div class="device-frame desktop" id="deviceFrame">
                    <iframe id="preview" title="Website-Vorschau" src="about:blank"></iframe>
                </div>
            </div>
            <div class="statusbar">
                <span id="statusFile">Keine Seite geoeffnet</span>
                <span id="statusSelection"></span>
                <span class="spacer"></span>
                <span id="statusQueue" class="queue-idle">Gespeichert</span>
                <span id="statusPerm"><?= wfx_h($user['role']) ?></span>
            </div>
        </section>

        <aside class="inspector" id="inspector">
            <div class="inspector-empty">
                <p><strong>Element auswaehlen</strong></p>
                <p class="muted">Klicken Sie ein Element in der Vorschau an. Das Eigenschaften-Panel zeigt dann nur die
                    Eigenschaften, die fuer dieses Element tatsaechlich bearbeitet werden koennen.</p>
                <ul class="hints">
                    <li><kbd>Alt</kbd> + <kbd>&uarr;</kbd> waehlt das uebergeordnete Element</li>
                    <li><kbd>Alt</kbd> + <kbd>&darr;</kbd> waehlt das erste Kindelement</li>
                    <li><kbd>Esc</kbd> hebt die Auswahl auf</li>
                </ul>
            </div>
        </aside>
    </div>
</div>

<div id="modalRoot"></div>
<div id="toastRoot" class="toast-root"></div>

<script>
window.WFX = {
    csrf: <?= json_encode(Csrf::token()) ?>,
    user: <?= json_encode(['name' => $user['name'], 'role' => $user['role']], JSON_UNESCAPED_UNICODE) ?>,
    perms: <?= json_encode($perms) ?>,
    baseUrl: <?= json_encode($cfg['baseUrl'] ?? '') ?>
};
</script>
<script src="/public/editor.js"></script>
</body>
</html>
