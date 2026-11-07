<?php
declare(strict_types=1);
/**
 * Handbuch und Tutorial - eigenstaendige Seite (Login erforderlich).
 * Bewusst ohne externe Ressourcen, damit sie auch offline funktioniert.
 */
$user = Auth::user();
if ($user === null) {
    header('Location: /login');
    exit;
}
$sections = [
    'start' => 'In 5 Minuten loslegen',
    'seiten' => 'Seiten, Server und Dateien',
    'bearbeiten' => 'Visuell bearbeiten',
    'links' => 'Links und Buttons',
    'medien' => 'Medienbibliothek',
    'supabase' => 'Supabase verbinden',
    'bausteine' => 'Bausteine',
    'backups' => 'Backups ansehen',
    'livegang' => 'Veröffentlichen',
    'tasten' => 'Tastenkürzel',
    'probleme' => 'Wenn etwas klemmt',
];
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Handbuch - Website-Editor</title>
<style>
    :root { --ink:#0f172a; --ink2:#334155; --muted:#64748b; --line:#e2e8f0; --accent:#2563eb; --soft:#eff6ff; }
    * { box-sizing: border-box; }
    body { margin:0; font:15px/1.65 system-ui, -apple-system, Segoe UI, sans-serif; color:var(--ink); background:#f8fafc; }
    header.top { background:#fff; border-bottom:1px solid var(--line); padding:14px 22px; display:flex; align-items:center; gap:12px; position:sticky; top:0; z-index:5; }
    header.top strong { font-size:15px; }
    header.top .sub { color:var(--muted); font-size:12px; }
    header.top a.btn { margin-left:auto; text-decoration:none; background:var(--accent); color:#fff; padding:7px 12px; border-radius:7px; font-size:13px; font-weight:600; }
    .layout { display:grid; grid-template-columns:250px minmax(0,1fr); gap:28px; max-width:1180px; margin:0 auto; padding:24px 22px 80px; }
    nav.toc { position:sticky; top:70px; align-self:start; background:#fff; border:1px solid var(--line); border-radius:10px; padding:12px; }
    nav.toc a { display:block; padding:5px 8px; border-radius:6px; color:var(--ink2); text-decoration:none; font-size:13px; }
    nav.toc a:hover { background:var(--soft); color:var(--accent); }
    main { background:#fff; border:1px solid var(--line); border-radius:12px; padding:26px 30px; }
    h1 { margin:0 0 6px; font-size:26px; }
    h2 { margin:34px 0 10px; font-size:19px; padding-top:8px; border-top:1px solid var(--line); }
    h2:first-of-type { border-top:0; margin-top:18px; }
    h3 { margin:18px 0 6px; font-size:15px; }
    p, li { color:var(--ink2); }
    code, kbd { font:12.5px/1.5 ui-monospace, Consolas, monospace; background:#f1f5f9; border:1px solid var(--line); border-radius:4px; padding:1px 5px; }
    pre { background:#0f172a; color:#e2e8f0; padding:12px 14px; border-radius:8px; overflow:auto; font:12.5px/1.6 ui-monospace, Consolas, monospace; }
    .note { background:var(--soft); border-left:3px solid var(--accent); padding:9px 12px; border-radius:0 8px 8px 0; margin:10px 0; }
    .warn { background:#fffbeb; border-left:3px solid #d97706; padding:9px 12px; border-radius:0 8px 8px 0; margin:10px 0; }
    table { border-collapse:collapse; width:100%; margin:10px 0; font-size:13.5px; }
    th, td { border:1px solid var(--line); padding:6px 9px; text-align:left; vertical-align:top; }
    th { background:#f8fafc; }
    .steps { counter-reset:step; list-style:none; padding:0; }
    .steps li { counter-increment:step; position:relative; padding:0 0 14px 40px; }
    .steps li::before { content:counter(step); position:absolute; left:0; top:0; width:26px; height:26px; border-radius:50%; background:var(--accent); color:#fff; font:600 13px/26px system-ui; text-align:center; }
    @media (max-width: 820px) { .layout { grid-template-columns:1fr; } nav.toc { position:static; } main { padding:18px; } }
</style>
</head>
<body>
<header class="top">
    <strong>Website-Editor</strong>
    <span class="sub">Handbuch und Tutorial</span>
    <a class="btn" href="/">Zurück zum Editor</a>
</header>

<div class="layout">
    <nav class="toc">
        <?php foreach ($sections as $id => $label): ?>
            <a href="#<?= wfx_h($id) ?>"><?= wfx_h($label) ?></a>
        <?php endforeach; ?>
        <a href="/logout" onclick="return false;" style="display:none"></a>
    </nav>

    <main>
        <h1>Handbuch</h1>
        <p class="sub">Angemeldet als <strong><?= wfx_h($user['name']) ?></strong> (<?= wfx_h($user['role']) ?>). Alles hier gilt für den lokalen Editor auf diesem Rechner – die Website selbst ändert sich erst beim Veröffentlichen.</p>

        <h2 id="start">In 5 Minuten loslegen</h2>
        <ol class="steps">
            <li><strong>Bestand auflisten.</strong> Reiter „Seiten" → „Server auflisten (ohne Download)". Der Editor liest nur die Dateiliste per FTP; heruntergeladen wird nichts.</li>
            <li><strong>Seite öffnen.</strong> Eine Seite mit dem Vermerk <code>⤓ Server</code> anklicken. Jetzt wird genau diese Seite samt Bildern, CSS und Skripten geholt.</li>
            <li><strong>Element anklicken.</strong> Im Vorschaufenster ein Element anklicken – rechts erscheinen nur die Eigenschaften, die dieses Element wirklich hat.</li>
            <li><strong>Ändern.</strong> Text tippen, Farbe wählen, Link setzen. Jede Änderung wird sofort auf der Platte gespeichert; die Statuszeile zeigt „Gespeichert HH:MM:SS".</li>
            <li><strong>Veröffentlichen.</strong> Reiter „Livegang" prüft und überträgt per FTP. Vorher wird automatisch ein Backup angelegt.</li>
        </ol>
        <div class="note">Nichts ist „nur im Speicher": Alles, was Sie sehen, liegt in <code>workspace/working/</code>. Ein Neuladen des Browsers verliert keine Arbeit.</div>

        <h2 id="seiten">Seiten, Server und Dateien</h2>
        <h3>Arbeitskopie und Live-Spiegel</h3>
        <ul>
            <li><code>workspace/working/</code> – Ihr Entwurf. Hier wird bearbeitet.</li>
            <li><code>workspace/live/</code> – der zuletzt veröffentlichte Stand. Dient nur dem Vergleich.</li>
            <li>Der FTP-Server – die echte Website. Wird nur beim Veröffentlichen beschrieben.</li>
        </ul>
        <h3>Drei Wege, Dateien zu bekommen</h3>
        <table>
            <tr><th>Weg</th><th>Was passiert</th></tr>
            <tr><td>Server auflisten</td><td>Nur die Dateiliste (Größe, Pfad). Kein Download. Ergebnis in <code>workspace/data/remote-index.json</code>.</td></tr>
            <tr><td>Seite öffnen (⤓ Server)</td><td>Diese Seite plus ihre Verweise (Bilder, CSS, Skripte; bei CSS eine Ebene tiefer). Grenzen: 40 Begleitdateien, 8 MB.</td></tr>
            <tr><td>Alles herunterladen</td><td>Kompletter Abgleich. Überschreibt lokale, noch nicht veröffentlichte Entwürfe – vorher sichern.</td></tr>
        </table>
        <div class="warn">Pfade, die erst per JavaScript oder PHP zusammengesetzt werden, erkennt der Einzelabruf nicht. Fehlt ein Bild in der Vorschau, einmal „Alles herunterladen" nutzen.</div>

        <h2 id="bearbeiten">Visuell bearbeiten</h2>
        <p>Der Inspektor rechts zeigt immer nur, was zum angeklickten Element passt:</p>
        <ul>
            <li><strong>Inhalt</strong> – Text direkt ändern (nicht bei Elementen mit Kindelementen oder PHP).</li>
            <li><strong>Typografie, Abstand &amp; Rahmen, Layout</strong> – Inline-Stile; leere Felder bedeuten „automatisch" (der aktuelle Wert steht als Platzhalter).</li>
            <li><strong>Responsive</strong> – eigene Regeln für Tablet/Smartphone, gespeichert in einem <code>&lt;style data-wfx-responsive&gt;</code>-Block.</li>
            <li><strong>Klassen, Attribute</strong> – gezielt einzelne Klassen oder Attribute ändern.</li>
            <li><strong>Aktionen</strong> – verschieben, duplizieren, löschen, als Baustein speichern.</li>
        </ul>
        <h3>Rechtsklick</h3>
        <p>Rechtsklick in die Vorschau öffnet ein Menü: Eigenschaften, Link bearbeiten, nach oben/unten, duplizieren, Bild einsetzen, als Baustein speichern, löschen, HTML kopieren. Das Menü arbeitet auf dem Element, das Sie angeklickt haben.</p>

        <h2 id="links">Links und Buttons</h2>
        <p>Ein Button ist fast immer ein <code>&lt;a class="btn"&gt;</code> oder ein Element <em>innerhalb</em> eines Links. Deshalb zeigt der Editor den Link-Bereich jetzt auch dann, wenn Sie auf den Text oder ein Icon im Button klicken – die Felder bearbeiten immer das zugehörige <code>&lt;a&gt;</code>.</p>
        <table>
            <tr><th>Feld</th><th>Bedeutung</th></tr>
            <tr><td>Ziel (href)</td><td><code>/de/kontakt.html</code> (intern), <code>https://…</code> (extern), <code>mailto:</code>, <code>tel:</code> oder <code>#anker</code>.</td></tr>
            <tr><td>Seite wählen</td><td>Setzt das Ziel auf eine Seite Ihres Projekts – ohne Tippfehler.</td></tr>
            <tr><td>Öffnen</td><td>Gleiches Fenster oder neues Fenster/Tab (setzt automatisch <code>rel="noopener"</code>).</td></tr>
            <tr><td>Link entfernen</td><td>Nimmt nur das Ziel weg, das Element bleibt stehen.</td></tr>
        </table>
        <div class="note">Steht über den Feldern „Dieses Element liegt in einem Link", ändern Sie den umgebenden Link. Mit „Link markieren" springt die Auswahl direkt auf das <code>&lt;a&gt;</code>.</div>

        <h2 id="medien">Medienbibliothek</h2>
        <p>Ein Klick auf „Medien" öffnet ein Fenster zum Hochladen. In der Bibliothek stehen ausschließlich Ihre eigenen Uploads aus <code>/assets/media/</code> – die Bilder der Website selbst erscheinen dort nicht.</p>
        <ul>
            <li><strong>Hochladen</strong> – Dateien hineinziehen oder anklicken. Fotos werden auf Ihrem PC im Browser zu <strong>WebP</strong> umgewandelt (längste Kante 2560 px), hochgeladen wird nur das fertige WebP. GIF bleibt unverändert (Animation), SVG wird nicht umgewandelt.</li>
            <li><strong>Videos</strong> – MP4/WebM bis <strong>5 MB</strong>, ohne Umwandlung. Größere Dateien werden abgelehnt; schneiden oder komprimieren Sie sie vorher.</li>
            <li><strong>Einsetzen</strong> – ersetzt bei ausgewähltem Bild die Quelle, sonst wird ein <code>&lt;img&gt;</code> bzw. <code>&lt;video controls&gt;</code> an der Auswahl eingefügt.</li>
            <li><strong>Fundstellen</strong> – die Chips zeigen, auf welchen Seiten ein Bild verwendet wird (Klick öffnet die Seite).</li>
            <li><strong>Pfad kopieren</strong> – für die Verwendung in CSS oder eigenen Bausteinen.</li>
        </ul>

        <h2 id="supabase">Supabase verbinden</h2>
        <p>Die Bibliothek funktioniert vollständig ohne Cloud. Supabase ist optional und speichert zwei Dinge: die Bilddateien (Storage-Bucket) und je Bild die Fundstellen (Tabelle, Spalte <code>usages</code>).</p>
        <ol class="steps">
            <li>Im Supabase-Projekt einen Bucket anlegen, z. B. <code>media</code>.</li>
            <li>Tabelle anlegen (SQL-Editor):</li>
        </ol>
        <pre>create table if not exists public.media (
  path text primary key,
  bucket text not null,
  storage_path text not null,
  size bigint not null,
  mime text,
  usages jsonb not null default '[]'::jsonb,
  updated_at timestamptz not null default now()
);

alter table public.media enable row level security;
-- Der Editor greift serverseitig mit dem Service-Key zu; keine öffentliche Policy nötig.</pre>
        <ol class="steps" start="3" style="counter-reset:step 2">
            <li>Im Editor: Reiter „Setup" → Abschnitt „Supabase" → URL (<code>https://…supabase.co</code>), Service-Key, Bucket, Tabelle eintragen, „Aktiv" anhaken, speichern.</li>
            <li>„Verbindung testen" – prüft Tabelle und Bucket. Danach in „Medien" → „Mit Supabase abgleichen".</li>
        </ol>
        <div class="warn">Der Service-Key liegt ausschließlich in <code>app/config.php</code> (Dateirechte 0600) und wird nie an den Browser geschickt. Geben Sie ihn nicht in Chat, Tickets oder Screenshots weiter. Ohne gültigen Schlüssel bleibt alles lokal – es gehen keine Daten verloren.</div>

        <h2 id="bausteine">Bausteine</h2>
        <p>Bausteine sind wiederverwendbare HTML-Schnipsel mit Platzhaltern wie <code>{{titel}}</code>.</p>
        <ul>
            <li><strong>Neu programmieren</strong> – Reiter „Bausteine" → „+ Neuer Baustein": Name, Kategorie, HTML. Platzhalter werden automatisch zu Eigenschaften.</li>
            <li><strong>Aus Auswahl</strong> – Element in der Vorschau wählen → „Als Baustein speichern".</li>
            <li><strong>Einfügen</strong> – „Einfügen" fragt Eigenschaften ab; „globale Instanz" folgt späteren Änderungen der Definition.</li>
            <li><strong>Export / Import</strong> – JSON-Dateien. Ein Export lässt sich in ein anderes Projekt importieren; gleichnamige Bausteine werden dabei nicht überschrieben, sondern ergänzt.</li>
        </ul>

        <h2 id="backups">Backups ansehen</h2>
        <p>Der Reiter „Backups" gruppiert alle Sicherungen nach Tag (neueste zuerst). „Ansehen" öffnet eine schreibgeschützte Ansicht: Dateiliste, Unterschied zum aktuellen Stand und der gespeicherte Quelltext. Dabei wird <em>nichts</em> verändert.</p>
        <p>Erst „Wiederherstellen" schreibt Dateien zurück – und sichert vorher den aktuellen Zustand als eigenes Backup.</p>

        <h2 id="livegang">Veröffentlichen</h2>
        <ol class="steps">
            <li>Reiter „Livegang" öffnen: Liste der geänderten Dateien, Prüfergebnisse (HTML/CSS/JS/PHP-Syntax, fehlende Verweise).</li>
            <li>Fehler blockieren die Veröffentlichung – Warnungen nicht.</li>
            <li>„Veröffentlichen" überträgt nur die geänderten Dateien, prüft die Übertragung und ruft danach die öffentlichen Seiten auf (falls Basis-URL gesetzt).</li>
            <li>Schlägt etwas fehl, bleibt der Live-Spiegel unverändert und der Fehler steht im Ergebnis.</li>
        </ol>

        <h2 id="tasten">Tastenkürzel</h2>
        <table>
            <tr><th>Kürzel</th><th>Wirkung</th></tr>
            <tr><td><kbd>Alt</kbd> + <kbd>↑</kbd></td><td>übergeordnetes Element auswählen</td></tr>
            <tr><td><kbd>Alt</kbd> + <kbd>↓</kbd></td><td>erstes Kindelement auswählen</td></tr>
            <tr><td><kbd>Esc</kbd></td><td>Auswahl aufheben / Dialog schließen</td></tr>
            <tr><td><kbd>Strg</kbd> + <kbd>Z</kbd></td><td>rückgängig</td></tr>
            <tr><td><kbd>Strg</kbd> + <kbd>Shift</kbd> + <kbd>Z</kbd></td><td>wiederholen</td></tr>
            <tr><td><kbd>Strg</kbd> + <kbd>S</kbd></td><td>offene Änderungen prüfen und sichern</td></tr>
        </table>

        <h2 id="probleme">Wenn etwas klemmt</h2>
        <h3>„Datei konnte nicht geladen werden: 550 …"</h3>
        <p>Der FTP-Pfad lag außerhalb des Startordners. Der Editor arbeitet jetzt mit Pfaden relativ zum Startordner (<code>/htdocs</code>). Prüfen Sie im Reiter Setup den Startordner und testen Sie die Verbindung.</p>
        <h3>Ein Bild fehlt in der Vorschau</h3>
        <p>Der Einzelabruf hat den Verweis nicht erkannt (z. B. per JavaScript gesetzt). Einmal „Alles herunterladen" oder das Bild in der Medienbibliothek hochladen.</p>
        <h3>Änderung erscheint nicht</h3>
        <p>Statuszeile ansehen: „Gespeichert HH:MM:SS" heißt, die Datei liegt auf der Platte. Dann Vorschau neu laden (↻). Bei Konflikten meldet der Editor „Die Datei wurde zwischenzeitlich geändert".</p>
        <h3>Anmeldung gesperrt</h3>
        <p>Nach 8 Fehlversuchen ist der Zugang für 5 Minuten gesperrt. Warten oder den Editor neu starten.</p>
        <h3>Wo liegen meine Daten?</h3>
        <p>Entwurf, Backups und Protokoll liegen in <code>workspace/</code>, Zugangsdaten in <code>app/config.php</code>. Beides ist für den Webserver gesperrt und von der Versionierung ausgenommen.</p>
    </main>
</div>
</body>
</html>
