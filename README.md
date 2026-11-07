# Visueller Website-Editor (lokal, mit FTP-Anbindung)

Ein lokal laufender Website-Editor für statische und PHP-basierte Seiten. Er holt die
Live-Dateien per FTP (z. B. von InfinityFree) auf den PC, macht sie in einer echten
Browser-Vorschau visuell bearbeitbar und lädt geprüfte Änderungen wieder hoch.

Keine KI, keine API-Keys, kein Build-Schritt, keine externen Dienste. Nur PHP + Vanilla JS.

---

## Warum lokal und nicht auf dem Hosting

InfinityFree (und die meisten kostenlosen PHP-Hoster) erlaubt **keine ausgehenden
FTP-Verbindungen** und kein `proc_open`. Ein Editor, der dort liegt, könnte weder die
Dateien holen noch eine echte PHP-Vorschau erzeugen. Deshalb läuft der Editor auf dem PC
und spricht selbst per FTP mit dem Hosting. Vorteil: Die Live-Dateien bleiben die primäre
Datenquelle, und der Editor kann nichts auf dem Server beschädigen, was er nicht vorher
gesichert hat.

---

## Voraussetzungen

- PHP **8.1+** (getestet mit 8.5) mit den Erweiterungen `ftp`, `openssl`, `curl`, `dom`, `mbstring`
- Optional: Node.js (nur für die JavaScript-Syntaxprüfung vor dem Veröffentlichen)
- FileZilla o. Ä. wird **nicht** benötigt

---

## Start

```bat
start-editor.bat
```

oder manuell:

```bash
php -S 127.0.0.1:8899 -t . app/router.php
```

Danach <http://127.0.0.1:8899> öffnen. Beim ersten Start erscheint der Setup-Assistent:

1. **Quelle**: FTP-Zugangsdaten des Hostings (Host, Benutzer, Passwort, optional TLS,
   passiv/aktiv, Startverzeichnis z. B. `/htdocs`). Alternativ einen lokalen Ordner
   importieren, um den Editor ohne FTP auszuprobieren.
2. **Bestand laden**: Der Editor spiegelt alle Dateien nach `workspace/working`
   (Entwurf) und `workspace/live` (zuletzt veröffentlichter Stand).
3. **Konto anlegen**: Der erste Benutzer wird `owner` (darf alles).
4. **Basis-URL** (optional): z. B. `https://deine-site.infinityfreeapp.com` – damit prüft
   der Editor nach dem Veröffentlichen die tatsächlich erreichbaren Seiten.

Die Zugangsdaten liegen ausschließlich in `app/config.php` (wird mit Rechten `0600`
geschrieben, ist in `.gitignore` ausgeschlossen). Sie werden nie an den Browser gesendet.

### Variante B: `app/config.php` selbst anlegen

Wer die Zugangsdaten lieber in einer Datei einträgt, statt sie in den Assistenten zu tippen:

1. `app/config.example.php` nach `app/config.php` kopieren.
2. Alle `TODO`-Werte ersetzen. `installed` muss `true` sein und `users` mindestens ein Konto enthalten.
3. Hash für das Editor-Passwort erzeugen (das Klartext-Passwort wird nirgends gespeichert):

   ```bash
   php -r "echo password_hash('deinEditorPasswort', PASSWORD_DEFAULT), PHP_EOL;"
   ```

   Die Ausgabe in `users.<name>.hash` einsetzen.
4. `start-editor.bat`, anmelden und im Panel **Verbindung → Vom FTP laden** die Dateien holen.

Fehlt `app/config.php`, zeigt der Editor den Assistenten und schreibt die Datei selbst.

---

## Objekte auflisten statt alles laden

Der Editor muss den Bestand nicht mehr komplett herunterladen:

1. **Seiten → Bestand → „Server auflisten (ohne Download)“** liest nur die Verzeichnisstruktur
   per FTP (`MLSD`, sonst `LIST`) und legt sie unter `workspace/data/remote-index.json` ab.
2. Die Seitenliste zeigt danach auch Objekte, die nur auf dem Server liegen – gekennzeichnet mit `⤓ Server`.
3. Beim Öffnen wird **nur diese Seite** geholt, zusammen mit den Dateien, auf die sie verweist
   (`src`, `href`, `poster`, `url(...)`; bei CSS eine Ebene tiefer für Schriften und Bilder).
   Grenzen: 40 Begleitdateien und 8 MB pro Seite.
4. Jede Änderung landet sofort in `workspace/working`; die Statusleiste zeigt „Gespeichert HH:MM:SS“.
   Im Code-Panel speichert der Editor automatisch (ohne Vollbackup je Tastendruck), bei
   `.php`/`.htaccess` bleibt die ausdrückliche Bestätigung bestehen.
5. „Alles herunterladen“ gibt es weiterhin – es überschreibt lokale, noch unveröffentlichte Entwürfe.

---

## Arbeitsweise: Entwurf, Live, Veröffentlichen, Rollback

```
FTP-Hosting  ──(Vom FTP laden)──►  workspace/working   ← der Editor bearbeitet nur hier
                                         │
                                    (Veröffentlichen)
                                         ▼
                              Backup → FTP-Upload → Nachprüfung
                                         │
                                         ▼
                              workspace/live  (Spiegel des Live-Stands)
```

- **Entwurf**: Jede Änderung wird sofort in `workspace/working` gespeichert. Die
  öffentliche Website bleibt dabei unberührt.
- **Live-Spiegel**: `workspace/live` ist der zuletzt veröffentlichte Stand. Der Editor
  vergleicht beide und zeigt an, was aussteht.
- **Veröffentlichen**: Vor dem Upload werden die betroffenen Remote-Dateien **und** die
  lokalen Dateien in `workspace/backups/<id>/` gesichert. Jeder Upload läuft über eine
  temporäre Datei + `RNFR/RNTO` (kein halb geschriebenes Ziel) und wird anschließend per
  Größe und SHA-1 (bis 512 KB) nachgeprüft. Danach prüft der Editor die Seiten über die
  Basis-URL, sofern eine hinterlegt ist.
- **Rollback**: Jedes Backup kann wiederhergestellt werden. Der aktuelle Zustand wird
  dabei erneut gesichert, die Wiederherstellung wird validiert und protokolliert.
- **Journal**: Alle Schreib- und Veröffentlichungsvorgänge landen in `workspace/journal.log`.

Was ein Backup erst zu einem Backup macht: Die Dateien werden zurückgelesen und geprüft –
„hochgeladen“ allein wird nicht als Erfolg gemeldet.

---

## Projektstruktur

```
app/
  router.php          Front-Controller (/api/*, /preview, /site/*, /public/*)
  bootstrap.php       Laden der Module, Session, Security-Header
  views/              setup.php, login.php, editor.php
  lib/
    Core.php          AppError, Store (Pfadprüfung, atomares Schreiben, Diff),
                      Config, Auth (Rollen, Login-Throttle), Csrf, Journal
    Html.php          Byte-Offset-HTML-Scanner + Bearbeitungsoperationen
    Ftp.php           eigener FTP-Client (Sockets, passiv/aktiv, TLS, MLSD/LIST)
    Sync.php          Pull vom Hosting, Push, Seiten-/Sprach-/Slug-Erkennung
    Components.php    Bausteine: Definition, Rendering, global/copy
    Languages.php     Sprachversionen, stabile data-wfx-tid, Übersetzungsstatus
    Responsive.php    Breakpoint-CSS (data-wfx-responsive) lesen/schreiben
    Validator.php     HTML/CSS/JS/PHP-Prüfung, Ressourcen- und Linkprüfung
    Publish.php       Änderungen, Prüfung, Backups, Publish, Rollback
    Api.php           alle JSON-Endpunkte
    Preview.php       Vorschau-Dokument + Asset-Auslieferung
  config.php          wird vom Setup erzeugt (nicht versioniert)
bin/php-render.php    isolierte PHP-Ausführung für die Vorschau
public/               editor.js, editor.css, overlay.js, overlay.css, auth.css
workspace/            working/, live/, backups/, data/, journal.log (nicht versioniert)
tests/                run.php, ftp-server.mjs, fixtures/
```

### Warum kein DOMDocument

`DOMDocument` normalisiert HTML, entfernt Kommentare und zerstört PHP-Blöcke. Der Editor
nutzt deshalb einen eigenen Byte-Offset-Scanner (`HtmlDoc`) und ersetzt nur exakte
Teilstücke. Nicht ausgewählte Bereiche, PHP-Blöcke, Kommentare und Skripte bleiben
byte-genau erhalten – inklusive Einrückung bei Verschiebungen.

---

## Bedienung

- **Auswählen**: Klick in die Vorschau. `Alt + ↑` wählt das Elternelement, `Alt + ↓` das
  erste Kind, `Esc` hebt auf. Links werden im Editor nicht geöffnet.
- **Eigenschaften**: Das Panel zeigt nur, was für den Elementtyp sinnvoll ist – Inhalt,
  Link, Bild, Farben, Typografie, Abstände, Rahmen, Layout, Klassen, Attribute.
- **Undo/Redo**: `Strg+Z` / `Strg+Shift+Z`. Der Server berechnet zu jeder Operation die
  Inverse (bei `move` inklusive exakter Position), Undo stellt also byte-genau wieder her.
- **Struktur**: Dokumentbaum mit Drag & Drop; unzulässige Verschachtelungen werden
  abgelehnt, nicht stillschweigend ausgeführt.
- **Bausteine**: Element auswählen → „Als Baustein speichern“. Modi `global`
  (Änderung zieht alle Instanzen nach) und `copy` (eigenständige Kopie).
- **Sprachen**: Seiten zuordnen, stabile `data-wfx-tid` vergeben, Status je Sprache
  (fehlend/geändert/ok), Sprachvergleich nebeneinander, `hreflang`/Canonical-Anzeige.
  Es werden **keine** Übersetzungen erfunden – der Editor verwaltet und prüft nur.
- **Responsive**: Umschalter Desktop/Tablet/Mobil; Breakpoint-Regeln landen in einem
  eigenen `<style data-wfx-responsive>` und berühren bestehende Media Queries nicht.
- **Vergleich**: Vorher/Nachher je Datei (Diff gegen den Live-Stand).
- **Code**: kontrollierter Quelltext-Modus für HTML/CSS/JS/PHP-Dateien.

---

## Sicherheit

- Anmeldung mit Rollen (`owner`, `editor`, `viewer`), Login-Throttle, Session-Cookie
  `HttpOnly` + `SameSite=Lax`.
- **Jede** Dateioperation prüft serverseitig Login, Rolle und Pfad. Der Browser kann
  keinen Pfad außerhalb von `workspace/` adressieren (Path-Traversal, Symlinks, `.`/`..`,
  Nullbytes, Backslashes).
- CSRF-Token für alle schreibenden Requests; Schreibvorgänge sind auf „same origin“
  begrenzt.
- Änderungen an PHP-Dateien sind strukturell und brauchen eine zusätzliche Bestätigung;
  visuelle Bearbeitung lässt PHP-Blöcke unangetastet.
- Vorschau-Inhalte laufen im iframe mit `X-Frame-Options: SAMEORIGIN`.
- Keine Geheimnisse im Browser, im Repository oder in Logs.

---

## Tests

```bash
php tests/run.php
```

49 automatisierte Prüfungen (Stand: alle grün), darunter:

| Bereich | Inhalt |
| --- | --- |
| HTML-Engine | Text/Attribut/Style/Klasse, Insert/Delete/Duplicate/Move, Undo-Exaktheit, Verschachtelungsregeln, PHP-Blöcke bleiben erhalten |
| Bausteine | Definition, Rendering, globales `rerenderAll`, unabhängige Kopien, Ablehnung von `<script>` |
| Sprachen | Zuordnung, stabile IDs, Status „fehlend/geändert“, Vergleich, keine Textübernahme |
| Validierung | CSS-Syntax, fehlende Ressourcen, gültige Referenzen, PHP- und JS-Syntax, Publish-Blockade |
| Responsive | Breakpoint-Regel schreiben, lesen, entfernen |
| Rechte | Redakteur darf bearbeiten, aber nicht veröffentlichen; ohne Login 401 |
| FTP | echter FTP-Testserver (`tests/ftp-server.mjs`), Upload/Download/Größe/Löschen, Pull, Publish mit Nachprüfung, Rollback, inkrementeller Push, Startverzeichnis `/htdocs` (absolute vs. relative Pfade), Auflisten ohne Download und Einzelabruf beim Öffnen |
| Links / Backups / Medien | Link-Erkennung für Elemente in `<a>` (Buttons), Backups sortiert und nach Tag gruppiert, Backup-Ansicht ohne Wiederherstellung, Baustein-Export/-Import inkl. Ablehnung von PHP, Medien-Index mit Fundstellen (auch relative Pfade), Bild einfügen, Supabase aus = rein lokal |

Der FTP-Test startet einen echten FTP-Server auf Port 2121 – kein Mock. Damit sind
Verbindungsaufbau, passive Datenverbindung, `STOR`/`RETR`/`RNFR`/`RNTO`/`SIZE` und die
Nachprüfung nach dem Upload real geprüft.

### Manuell im Browser geprüft (Test A, B, E, F, G, H)

- Anmeldung, Editor lädt Seitenbaum, Vorschau rendert PHP-Seite korrekt
- `h1` ausgewählt → Text im Panel geändert → Vorschau aktualisiert sofort
- Änderung landet in `workspace/working/about.php`, PHP-Block in Zeile 10 unverändert
- Nach Neuladen der Seite ist die Änderung noch da; der Live-Spiegel bleibt unverändert
- Undo in der Sitzung stellt Text in Vorschau **und** Datei zurück
- Livegang-Panel: „Änderungen (1) · 0 Fehler · 0 Warnungen“, Publish-Aktionen vorhanden
- Geräteumschalter: Mobil 390 px, Tablet/Desktop nutzen die volle Panelbreite

---

## Links, Medien, Backups, Bausteine

**Links in Buttons.** Der Inspektor zeigt den Link-Bereich jetzt auch dann, wenn das angeklickte
Element *innerhalb* eines `<a>` liegt (Text, `<span>`, Icon). Bearbeitet wird immer der Anker selbst;
ein Hinweis nennt das übergeordnete Element, „Link markieren“ springt dorthin. Zusätzlich: Seitenauswahl
statt Tippfehler, `target` mit automatischem `rel="noopener"`, Titel-Tooltip, Link entfernen.

**Rechtsklick in der Vorschau** öffnet ein Kontextmenü: Eigenschaften, Link bearbeiten, nach oben/unten,
duplizieren, Bild aus der Bibliothek einsetzen, als Baustein speichern, löschen, HTML kopieren.

**Medienbibliothek** (Klick auf „Medien" öffnet ein Fenster): In der Bibliothek stehen ausschließlich eigene
Uploads aus `/assets/media/` – die Bilder der Website werden bewusst nicht gelistet. Fotos wandelt der Browser
auf dem eigenen PC zu **WebP** (Canvas, längste Kante 2560 px, Qualität 0.85); hochgeladen wird nur das fertige
WebP. GIF bleibt unverändert (Animation), SVG wird nicht gerastert. **Videos** (MP4/WebM) bis **5 MB** werden
ohne Umwandlung abgelegt – größere Dateien lehnen Browser und Server ab. Zu jeder Datei stehen die Fundstellen
(welche Seite, wie oft). „Einsetzen" ersetzt bei gewähltem `<img>` die Quelle, sonst wird ein `<img>` bzw.
`<video controls>` an der Auswahl eingefügt.

**Supabase (optional).** Ist es aktiv, werden Bilddateien in einen Storage-Bucket geladen und je Bild ein
Datensatz mit Pfad, Größe, MIME und Fundstellen (`usages`, JSON) in eine Tabelle geschrieben.
Konfiguration im Reiter „Setup"; der Service-Key liegt nur in `app/config.php` (0600) und wird nie an den
Browser geschickt. SQL und Schritt-für-Schritt-Anleitung: **Handbuch** (`/handbuch`, Fragezeichen oben).
Ohne Konfiguration bleibt alles lokal – es geht nichts verloren.

**Bausteine** lassen sich im Reiter „Bausteine" neu programmieren (HTML mit Platzhaltern wie `{{titel}}`),
als JSON exportieren (einzeln oder alle) und wieder importieren. Gleichnamige Bausteine werden beim Import
nicht überschrieben, sondern mit Suffix ergänzt; HTML mit PHP wird abgelehnt.

**Backups** sind nach Tag gruppiert (neueste zuerst). „Ansehen" zeigt Dateiliste, Unterschied zum aktuellen
Stand und den gesicherten Quelltext – schreibgeschützt. Erst „Wiederherstellen" ändert Dateien und sichert
vorher den aktuellen Zustand.

---

## Bekannte Einschränkungen (ehrlich)

- **Einzelabruf kennt nur statische Verweise**: Beim Öffnen werden Dateien aus `src`/`href`/`url(...)`
  mitgeholt. Pfade, die erst per JavaScript oder PHP zusammengesetzt werden, erkennt der Editor nicht –
  solche Dateien fehlen in der Vorschau, bis sie einmal über „Alles herunterladen“ lokal liegen.
- **Obergrenzen beim Einzelabruf**: 40 Begleitdateien bzw. 8 MB pro Seite; größere Medien bleiben auf dem Server.

1. **Das Repository war leer.** Es gab kein Bestandsprojekt und keinen vorhandenen Editor
   zu verbessern – der Editor wurde neu gebaut. Die bestehende Live-Website wird über
   „Vom FTP laden“ importiert; es wurde nichts an einer fremden Website verändert.
2. **Kein GitHub-Sync.** GitHub als Backup ist nicht implementiert. Backups liegen lokal
   in `workspace/backups/`. Ein Push müsste über Git im Arbeitsordner oder über einen
   späteren API-Token ergänzt werden.
3. **Mehrere Dateien sind nicht atomar.** Ein Publish schreibt Datei für Datei. Bricht er
   ab, bleibt ein Teil aktualisiert; das Backup erlaubt dann einen Rollback. Auf Shared
   Hosting ist eine echte Transaktion über mehrere Dateien nicht möglich.
4. **PHP-Vorschau führt Site-Code lokal aus** (`proc_open`, Zeitlimit 12 s). Nur für
   vertrauenswürdigen eigenen Code geeignet; abschaltbar über `renderPhp`.
5. **Undo/Redo lebt in der Sitzung.** Nach einem Neuladen ist der Verlauf leer (die
   Dateien selbst sind natürlich gespeichert).
6. **Grenzen**: Dateien > 1,5 MB sind nicht visuell editierbar, Uploads > 2 MB werden
   abgelehnt, ein Pull ist auf 4000 Dateien / 200 MB begrenzt.
7. **Tablet/Desktop unterscheiden sich erst ab genügend Fensterbreite**, weil die Vorschau
   auf die Panelbreite begrenzt ist (`max-width: 100%`).
8. **Live-Prüfung nach dem Publish** funktioniert nur mit hinterlegter Basis-URL und
   Internetzugang; ohne URL wird das ehrlich als „nicht geprüft“ gemeldet.
9. **Keine automatisierten visuellen Regressionstests.** Screenshot-Vergleiche werden
   nicht behauptet; die Prüfungen sind strukturell und syntaktisch.
10. **Übersetzungen werden nicht erzeugt.** Der Editor ordnet zu, zeigt Status und
    vergleicht – übersetzen muss ein Mensch.
11. **Sprachzuordnung ist ein manueller Schritt.** Ohne `data-wfx-tid` oder gespeicherte
    Zuordnung erscheinen Elemente als „fehlend“, statt falsch gepaart zu werden.
12. **FTP-Zugangsdaten im Klartext** in `app/config.php` (Rechte `0600`). Für einen
    lokalen Einzelplatz-Arbeitsplatz angemessen, für Mehrbenutzer nicht.

---

## Dateien, die nicht ins Repository gehören

`.gitignore` schließt `app/config.php` und `workspace/` aus. Beide enthalten Zugangsdaten
bzw. Kundendaten und werden beim Setup neu erzeugt.
