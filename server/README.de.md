# Server für 8bitcavern.de (Stufe 3) – Vorbereitung

*English summary below.*

Zwei Teile, beide für gewöhnlichen Webspace mit PHP (ab 8.1, mit `pdo_mysql` und `zip`) und MySQL/MariaDB –
ohne eigenen Dienst und ohne Shell.

## 3a: Spiegel der Daten (ohne Programmierung auf dem Server)

Der Workflow `.github/workflows/spiegel.yml` legt nach jeder Änderung `breadbin-db.zip` und `stand.json` per
FTPS auf den Webspace. Dazu im Daten-Repository unter *Settings → Secrets and variables → Actions* anlegen:

| Secret | Beispiel |
| --- | --- |
| `SPIEGEL_HOST` | FTP-Server laut Kundenmenü des Hosters |
| `SPIEGEL_USER` | FTP-Benutzer (am besten einer nur für diesen Ordner) |
| `SPIEGEL_PASSWORD` | dessen Passwort |
| `SPIEGEL_DIR` | Zielordner, so wie er nach der FTP-Anmeldung heißt (z. B. `/breadbindb`) |

Dazu die Variable (nicht Secret) `SPIEGEL_URL` = öffentliche Adresse der Datei, z. B.
`https://8bitcavern.de/breadbin/db/breadbindb/breadbin-db.zip`; der Lauf lädt sie danach herunter und vergleicht.
Breadbin nutzt diese Adresse ab 0.5.7 von selbst. Fehlen die Secrets, überspringt der Workflow den Schritt.
Nie `pwd` oder ausführliche lftp-Ausgaben ins Log schreiben: lftp zeigt dabei die Adresse samt Passwort
URL-kodiert, und GitHub blendet diese Form nicht aus.

## 3b: Beiträge direkt annehmen

| Datei | Zweck |
| --- | --- |
| `public/api/beitrag.php` | nimmt Beiträge an (POST, Feld `datei` = Austauschdatei, freiwillig `name`, `hinweis`); `GET` zeigt die Grenzen |
| `public/admin/index.php` | Prüfseite: Beiträge mit Texten und Bildern ansehen, Spiele einzeln übernehmen oder weglassen, Angaben bearbeiten und Bilder abwählen (gespeichert neben dem Beitrag, das Original bleibt), annehmen oder ablehnen, geprüfte ZIP herunterladen; vergleicht jedes Spiel mit dem veröffentlichten Stand (neu / geändert mit Feldvergleich und Bildern bisher–eingereicht / unverändert – diese sind nicht angehakt) |
| `lib/vergleich.php` | Vergleich mit dem Stand: liest `breadbin-db.zip` des Spiegels (Standard `../../breadbindb/breadbin-db.zip` vom Annahme-Ordner aus, sonst `stand_zip` in config.php), Zwischenspeicher `daten/stand-cache.json` |
| `lib/pruefen.php` | Prüfregeln, dieselben wie `tools/pruefen.py` |
| `lib/gemeinsam.php` | Datenbank, Einstellungen |
| `schema.sql` | Tabelle `beitraege` |
| `config.example.php` | Vorlage für `config.php` (Zugangsdaten – nie ins Repository) |

**Einrichten**

1. Im Kundenmenü eine MySQL-Datenbank anlegen und `schema.sql` einspielen (phpMyAdmin → Importieren).
2. `config.example.php` als `config.php` kopieren und ausfüllen: Datenbank, Salz (zufälliger Text) und
   `admin_hash` – den erzeugt man mit `php -r 'echo password_hash("Passwort", PASSWORD_DEFAULT);'` oder einem
   kleinen PHP-Skript, das man danach wieder löscht.
3. Den Ordner `server/` als `/breadbin/` hochladen. Die `.htaccess`-Dateien sperren `lib/`, `daten/`,
   `config.php` und `schema.sql` (Apache 2.4). Besser noch: `lib/`, `daten/` und `config.php` eine Ebene über
   das Webroot legen, wenn der Hoster das erlaubt, und die Pfade in `public/…/*.php` anpassen.
4. Prüfen: `https://8bitcavern.de/breadbin/public/api/beitrag.php` zeigt die Grenzen als JSON,
   `…/config.php` und `…/lib/gemeinsam.php` antworten mit 403.
5. Angenommene Beiträge: ZIP herunterladen, in die Arbeitskopie des Daten-Repositorys auspacken, committen.
   (Automatisch ins Repository schreiben könnte der Server später mit einem GitHub-Token – bewusst noch nicht.)

**Bei 8bitcavern.de (2026-11-06):** liegt unter `https://8bitcavern.de/breadbin/db/breadbin/annahme/` (FTP-Konto des
Spiegels: `/breadbin/annahme`). Programmdateien (`lib/`, `public/`) lädt der Workflow `server.yml` („Run workflow“)
hoch und macht `daten/` für PHP beschreibbar; `config.php` legt nur der Betreiber selbst ab. Breadbin schickt
Beiträge ab 0.5.8 von sich aus dorthin.

**Schutz:** nur gültige Beiträge werden gespeichert (gleiche Regeln wie im Repository), höchstens 5 Beiträge je
Stunde und Absender (IP nur als gesalzene Prüfsumme gespeichert), höchstens 20 MB, Prüfseite mit Passwort,
Sitzungs-Cookie `HttpOnly`/`SameSite=Strict`, CSRF-Schutz bei Entscheidungen, `noindex`.

**Getestet** lokal mit Docker (PHP 8.3 + Apache, MariaDB 11): gültiger Beitrag, GitHub-ZIP mit äußerem Ordner,
kaputter Beitrag (genaue Fehlerliste), Eingangsbremse (429), gesperrte Dateien (403), Prüfseite mit Anmeldung,
Bildern, Annehmen und Ablehnen. Auf dem echten Webspace noch nicht.

---

## English summary

Stage 3 for a plain PHP + MySQL web space. **3a:** the workflow `spiegel.yml` uploads the built
`breadbin-db.zip` via FTPS when the secrets `SPIEGEL_HOST/USER/PASSWORD/DIR` are set; Breadbin then reads
`https://<domain>/breadbin/db/breadbin-db.zip`. **3b:** `public/api/beitrag.php` accepts contributions
(exchange file as `datei`), validates them with the same rules as `tools/pruefen.py` and stores valid ones;
`public/admin/` lets the maintainer review, accept or reject them. Setup: import `schema.sql`, copy
`config.example.php` to `config.php`, upload `server/` as `/breadbin/`. Tested locally with Docker
(PHP 8.3, MariaDB 11), not yet on the real web space.
