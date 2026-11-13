-- Breadbin-Gemeinschaftsdatenbank: Eingang von Beiträgen (MySQL/MariaDB, utf8mb4).
-- Breadbin community database: incoming contributions.

CREATE TABLE IF NOT EXISTS beitraege (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  eingang       DATETIME NOT NULL,
  status        ENUM('neu', 'angenommen', 'abgelehnt') NOT NULL DEFAULT 'neu',
  datei         VARCHAR(80) NOT NULL,          -- Name der ZIP-Datei im Ordner daten/
  groesse       INT UNSIGNED NOT NULL,
  spiele        INT UNSIGNED NOT NULL,         -- Zahl der Spiele im Beitrag
  titel         TEXT NOT NULL,                 -- Titel der Spiele, zur Übersicht
  name          VARCHAR(100) NOT NULL DEFAULT '', -- freiwillig: Name für die Namensnennung
  hinweis       TEXT NOT NULL,                 -- freiwillig: Nachricht an die Prüfenden
  ip_hash       CHAR(64) NOT NULL,             -- SHA-256 der IP mit Salz, nur für die Eingangsbremse
  entschieden   DATETIME NULL,
  notiz         TEXT NOT NULL,                 -- Notiz der Prüfenden (z. B. Ablehnungsgrund)
  uebernommen   DATETIME NULL                  -- vom Workflow „Beiträge übernehmen“ ins Daten-Repository geholt
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX beitraege_status ON beitraege (status, eingang);
CREATE INDEX beitraege_ip ON beitraege (ip_hash, eingang);

-- Anonyme Nutzungszählung (lib/zaehler.php legt die Tabellen auch selbst an).
-- Anonymous usage counting (lib/zaehler.php also creates these tables itself).
CREATE TABLE IF NOT EXISTS nutzung_tag (
  tag        DATE NOT NULL,
  art        VARCHAR(20) NOT NULL,     -- app, webseite, download
  schluessel VARCHAR(80) NOT NULL,     -- App-Version bzw. Dateiname
  aufrufe    INT UNSIGNED NOT NULL DEFAULT 0,
  eindeutig  INT UNSIGNED NOT NULL DEFAULT 0,  -- verschiedene Absender an diesem Tag
  PRIMARY KEY (tag, art, schluessel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tagesprüfsummen (IP + täglich wechselnder Wert), werden nach einem Tag gelöscht.
CREATE TABLE IF NOT EXISTS nutzung_einmal (
  tag        DATE NOT NULL,
  art        VARCHAR(20) NOT NULL,
  schluessel VARCHAR(80) NOT NULL,
  hash       CHAR(64) NOT NULL,
  PRIMARY KEY (tag, art, schluessel, hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
