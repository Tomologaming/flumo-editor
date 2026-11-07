<?php
/**
 * VORLAGE fuer app/config.php
 * ---------------------------------------------------------------
 * 1. Diese Datei kopieren:  app/config.example.php  ->  app/config.php
 * 2. Unten alle mit TODO markierten Werte eintragen.
 * 3. Passwort-Hash erzeugen (Wert NICHT im Klartext eintragen):
 *      php -r "echo password_hash('deinEditorPasswort', PASSWORD_DEFAULT), PHP_EOL;"
 * 4. Datei niemals in ein Repository legen oder weitergeben.
 *
 * app/config.php steht in .gitignore und wird vom Editor nie
 * ueber den lokalen Server ausgeliefert.
 */
return [
    // Muss true sein, sonst zeigt der Editor wieder den Setup-Assistenten.
    'installed' => true,
    'installedAt' => '2026-01-01T00:00:00+00:00', // beliebig, nur Info

    // Zugang zum Editor selbst (nicht der FTP-Zugang!).
    // username => ['hash' => ..., 'role' => 'owner'|'editor'|'viewer', 'createdAt' => ...]
    'users' => [
        'admin' => [
            'hash' => 'TODO: Ausgabe von password_hash() hier einsetzen',
            'role' => 'owner',
            'createdAt' => '2026-01-01T00:00:00+00:00',
        ],
    ],

    // Datenquelle: FTP-Server des Webspace.
    'source' => [
        'type' => 'ftp',        // 'ftp' oder 'local'
        'host' => 'ftpupload.net', // TODO: FTP-Host von InfinityFree
        'port' => 21,
        'tls' => false,         // true = explizites FTPS (AUTH TLS)
        'passive' => true,      // passiv ist Pflicht, aktiv funktioniert hinter NAT nicht
        'implicit' => false,    // true nur bei implizitem FTPS (Port 990)
        'user' => 'TODO: FTP-Benutzername (z. B. if0_12345678)',
        'pass' => 'TODO: FTP-Passwort',
        'root' => '/htdocs',    // Startverzeichnis auf dem Server
        'localPath' => '',      // nur bei type = 'local' relevant
    ],

    // Oeffentliche Adresse der Website - wird nach dem Veroeffentlichen
    // fuer die Live-Nachpruefung aufgerufen.
    'baseUrl' => 'https://deine-seite.infinityfreeapp.com',

    // PHP-Seiten lokal ausfuehren (echte Vorschau). Fuehrt Website-Code
    // auf diesem PC aus - nur fuer Seiten einschalten, denen du vertraust.
    'renderPhp' => true,

    // Beim Veroeffentlichen zusaetzlich pruefen, ob die Datei auf dem
    // Server angekommen ist (Groesse + SHA-1).
    'remoteSyncOnPublish' => true,

    // Optional: Medienbibliothek in die Cloud spiegeln (Bilder + Fundstellen).
    // Ohne diese Angaben arbeitet die Bibliothek rein lokal.
    // Der Service-Role-Key gehoert NUR hierher - nie in den Browser.
    'supabase' => [
        'enabled' => false,     // true = beim Hochladen/Einsetzen automatisch spiegeln
        'url' => '',            // TODO: https://xxxx.supabase.co
        'key' => '',            // TODO: Service-Role-Key (Project Settings -> API)
        'bucket' => 'media',    // Storage-Bucket fuer die Bilder
        'table' => 'media',     // Tabelle fuer Pfad, Groesse, MIME und Fundstellen
    ],

    'lastPull' => null,
    'lastPublish' => null,
];
