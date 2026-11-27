<?php
// Kopie als config.php neben diese Datei legen und ausfüllen. config.php NIE ins Repository!
// Copy to config.php next to this file and fill in. NEVER commit config.php!
return [
    // MySQL/MariaDB aus dem Kundenmenü des Hosters
    'db_dsn'      => 'mysql:host=localhost;dbname=breadbin;charset=utf8mb4',
    'db_user'     => 'breadbin',
    'db_password' => 'geheim',

    // Passwort für die Prüfseite admin/ als Hash:
    //   php -r 'echo password_hash("dein Passwort", PASSWORD_DEFAULT), "\n";'
    'admin_hash'  => '',

    // Zufälliger Text (z. B. 40 Zeichen) für die IP-Prüfsummen der Eingangsbremse
    'salt'        => '',

    // Ordner für eingegangene Beiträge (außerhalb des Webroots oder per .htaccess gesperrt)
    'daten_dir'   => __DIR__ . '/daten',

    // Eingangsbremse: höchstens so viele Beiträge je Stunde und Absender (IP)
    'max_pro_stunde' => 5,
    // Höchstgröße eines Beitrags (ZIP) in Byte
    'max_bytes'   => 20 * 1024 * 1024,

    // Veröffentlichter Stand für den Vergleich auf der Prüfseite (neu / geändert / unverändert).
    // Standard: der Spiegel neben dem Annahme-Ordner (<web>/breadbindb/breadbin-db.zip). Nur nötig, wenn er woanders liegt.
    // 'stand_zip' => '/pfad/zu/breadbin-db.zip',

    // Abholung angenommener Beiträge durch GitHub (api/export.php, Workflow „Beiträge übernehmen“):
    // SHA-256 des Schlüssels, der als Secret BEITRAG_TOKEN im Daten-Repository liegt. Leer = abgeschaltet.
    //   printf %s 'schlüssel' | shasum -a 256
    // 'export_token_sha256' => '',

    // Durchsicht durch Claude (api/durchsicht.php, tools/durchsicht.py, siehe VORPRUEFUNG.md): SHA-256 eines eigenen
    // Schlüssels, der nur lesen und Empfehlungen ablegen kann. Leer = abgeschaltet.
    // 'durchsicht_token_sha256' => '',
    // Name für „bearbeitet von“, wenn der Betreiber auf der Prüfseite etwas ändert (Standard: Breadbin)
    // 'betreiber_name' => 'Breadbin',

    // E-Mail, wenn ein neuer Beitrag auf Freigabe wartet. Leer = keine Nachricht.
    // Eine Nachricht nur, wenn vorher nichts wartete (oder die letzte älter als ein Tag ist) – keine Flut.
    // 'benachrichtigung_an'  => 'du@example.org',
    // Absender; bei vielen Hostern muss er zur eigenen Domain gehören (z. B. breadbin@<deine-domain>).
    // 'benachrichtigung_von' => 'breadbin@example.org',
    // Link zur Prüfseite in der Nachricht (freiwillig)
    // 'pruefseite_url'       => 'https://example.org/breadbin/annahme/public/admin/',
];
