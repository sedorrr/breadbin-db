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
  notiz         TEXT NOT NULL                  -- Notiz der Prüfenden (z. B. Ablehnungsgrund)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX beitraege_status ON beitraege (status, eingang);
CREATE INDEX beitraege_ip ON beitraege (ip_hash, eingang);
