<?php
// Anonyme Nutzungszählung (Tageszahlen). Anonymous usage counting (daily totals).
//
// Gespeichert werden nur Zähler je Tag, Art und Schlüssel (z. B. app/0.8.2, download/Breadbin_universal.dmg).
// Für „eindeutig“ wird je Tag eine Prüfsumme aus IP und einem täglich wechselnden Wert gebildet; sie liegt
// nur bis zum Folgetag in nutzung_einmal und lässt sich danach keiner Adresse mehr zuordnen. IP-Adressen
// selbst werden nie gespeichert.
// Only per-day counters are stored. Daily-rotating hashes for unique counts are deleted after one day;
// IP addresses are never stored.
declare(strict_types=1);

require_once __DIR__ . '/gemeinsam.php';

/** Zählt einen Abruf. Fehler (Datenbank nicht erreichbar …) werden geschluckt: Zählen darf nie stören. */
function nutzung_tabellen(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS nutzung_tag (
        tag DATE NOT NULL, art VARCHAR(20) NOT NULL, schluessel VARCHAR(80) NOT NULL,
        aufrufe INT UNSIGNED NOT NULL DEFAULT 0, eindeutig INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (tag, art, schluessel)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS nutzung_einmal (
        tag DATE NOT NULL, art VARCHAR(20) NOT NULL, schluessel VARCHAR(80) NOT NULL, hash CHAR(64) NOT NULL,
        PRIMARY KEY (tag, art, schluessel, hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function zaehle(string $art, string $schluessel): void
{
    // konfig() bricht bei fehlender config.php mit exit ab – hier vorher prüfen
    if (!is_file(__DIR__ . '/../config.php')) {
        return;
    }
    try {
        $c = konfig();
        $pdo = db();
        $tag = gmdate('Y-m-d');
        $schluessel = substr(preg_replace('/[^A-Za-z0-9._+-]/', '', $schluessel), 0, 80);
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $hash = hash('sha256', ($c['salt'] ?? '') . '|' . $tag . '|' . $art . '|' . $ip);

        try {
            $pdo->query('SELECT 1 FROM nutzung_tag LIMIT 1');
        } catch (PDOException $e) {
            nutzung_tabellen($pdo);   // beim ersten Mal anlegen
        }
        $neu = $pdo->prepare('INSERT IGNORE INTO nutzung_einmal (tag, art, schluessel, hash) VALUES (?, ?, ?, ?)');
        $neu->execute([$tag, $art, $schluessel, $hash]);
        $eindeutig = $neu->rowCount() > 0 ? 1 : 0;

        $pdo->prepare('INSERT INTO nutzung_tag (tag, art, schluessel, aufrufe, eindeutig) VALUES (?, ?, ?, 1, ?)
                       ON DUPLICATE KEY UPDATE aufrufe = aufrufe + 1, eindeutig = eindeutig + VALUES(eindeutig)')
            ->execute([$tag, $art, $schluessel, $eindeutig]);

        // Prüfsummen von vorgestern und älter entfernen (gelegentlich, um Abfragen zu sparen)
        if (random_int(1, 50) === 1) {
            $pdo->prepare('DELETE FROM nutzung_einmal WHERE tag < ?')
                ->execute([gmdate('Y-m-d', time() - 86400)]);
        }
    } catch (Throwable $e) {
        // still
    }
}

/** Erlaubte Download-Dateien (feste Namen auf der Programm-Webseite). */
function download_dateien(): array
{
    return [
        'Breadbin_universal.dmg', 'Breadbin_x64-setup.exe', 'Breadbin_x64_en-US.msi',
        'Breadbin_amd64.AppImage', 'Breadbin_amd64.deb', 'Breadbin.x86_64.rpm',
    ];
}
