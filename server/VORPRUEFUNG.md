# Vorprüfung von Beiträgen

*English summary below.*

Damit vor dem Übernehmen nur noch die Auffälligkeiten anzusehen sind, gibt es zwei Stufen. Beide **markieren nur**:
Der Server nimmt weiter jeden gültigen Beitrag an, entschieden wird auf der Prüfseite.

| Stufe | Wo | Was |
| --- | --- | --- |
| a) Feste Regeln | `lib/vorpruefung.php`, angezeigt auf der Prüfseite | Hinweise je Spiel in Rot (spricht dagegen), Gelb (ansehen), Grau (zur Einordnung) |
| b) Durchsicht durch Claude | `public/api/durchsicht.php` + `tools/durchsicht.py` auf dem eigenen Rechner | Empfehlung je Spiel „übernehmen / nachbessern / ablehnen“ mit Begründung, auf der Prüfseite und im Artefakt-Bericht |

## a) Feste Regeln

Laufen bei jedem Aufruf der Prüfseite, in der **bearbeiteten** Fassung (wer auf der Prüfseite das Jahr korrigiert,
sieht den Hinweis danach verschwinden). Bildangaben werden je Beitrag in `daten/<datei>.bilder.json` zwischengespeichert.

| Regel | Stufe | Prüft |
| --- | --- | --- |
| `text_fehlt`, `text_de`, `text_en`, `text_kurz`, `text_gleich` | rot/gelb | Beschreibung fehlt ganz, eine Sprache fehlt, unter 80 Zeichen, DE = EN |
| `sprache` | gelb/rot | grobe Spracherkennung über Stoppwörter: DE-Text auf Englisch (gelb), Text auf Französisch, Spanisch … (rot), nichts erkennbar |
| `art`, `cracktro`, `vorab` | gelb/rot/info | Art richtig angegeben? (Feld `kind`, fehlend = Spiel; Werte game, demo, intro, music, diskmag, tool, graphics; für Nicht-Spiele `group`, `party` (Text ≤ 200), `place` (1–999) – wie die App, docs/Austauschformat.md). Als Spiel angegeben, aber Demo/Intro/Musik/Werkzeug/Diskmag/Grafik in Titel, Dateiname, Genre oder deutlich im Text → gelb; Nicht-Spiel mit Genres → gelb; unbekannte Art → gelb; Cracktro → rot (wird nicht aufgenommen); „(Demo)“/Preview = Probefassung eines Spiels → Info. Genre und Spielerzahl werden nur bei Spielen verlangt |
| `link` | rot/gelb/info | URLs, Domains, E-Mail-Adressen in jedem Feld; Download-/ROM-/Warez-Seiten und Filehoster rot (Liste `VP_WAREZ`), Szene- und Infoseiten gelb (`VP_SZENE`), bloßer Seitenname wie „itch.io“ ohne Link nur Info |
| `download`, `spam`, `beleidigung` | rot/gelb | Warez-Wörter (keygen, „hier herunterladen“ …), Werbung (viagra, „buy now“, promo code …), Schimpfwörter DE/EN |
| `unsinn` | gelb | wenig Buchstaben, Zeichenwiederholungen, überlange Wörter, nur Großbuchstaben |
| `wertung`, `note`, `kommentar` | gelb | Hausregel „Einträge bewerten nichts“: „eines der besten“, „großartig“, 9/10, „laut Kommentaren“ |
| `ki`, `ki_werkzeug` | rot/gelb | KI-Floskeln („as an AI“, „Hier ist eine Beschreibung“) rot; erwähnte KI-Werkzeuge (ChatGPT, Claude …) gelb |
| `jahr` | gelb/rot/info | fehlt, keine Jahreszahl, vor 1982, in der Zukunft (rot); weicht von anderen Fassungen im Bestand ab (Info) |
| `spieler` | gelb | Format (1, 1-2, 2-4) und höchstens 8 |
| `genre` | gelb | fehlt oder nicht aus der Genre-Liste der App (`VP_GENRES` = `GENRE_EN` in `ui/i18n.js`) |
| `titel_datei` | gelb/info | kein Titelwort, keine Abkürzung, kein gleicher Anfang im Dateinamen (bei 8.3-Namen nur Info) |
| `bestand`, `dublette` | info/gelb | Titel schon im Bestand; Text oder Bild gleicht einem **anderen** Titel im Bestand oder im selben Beitrag |
| `screenscraper` | rot | französischer Text zusammen mit ScreenScraper-Nummer |
| `ohne_bild` | gelb | weder `images` noch `more_images` (wie die App, `submission_gaps`); oben im Beitrag zählt die Prüfseite „N ohne Beschreibung · N ohne Bild · N Art prüfen“ |
| `bild` | rot/gelb/info | nicht lesbar, einfarbig/schwarz (rot, braucht GD), fast leer, 320×240 (ScreenScraper-Maß), sehr klein/groß, ungewöhnliche Maße, gleiches Bild zweimal |

Gegen den eigenen Bestand (3 444 Einträge, 2026-11-27) geprüft: Die verbleibenden Treffer sind überwiegend echt
(98 Einträge ohne Beschreibung, gemischte Genre-Namen aus GB64-NFOs, „gilt als eines der besten …“ in älteren Texten).

Listen und Schwellen stehen oben in `lib/vorpruefung.php`. Nach einer Regeländerung `VP_VERSION` erhöhen, wenn
sich die Bildprüfung ändert (dann werden die Bild-Zwischenspeicher neu gebaut).

## b) Durchsicht durch Claude

Ablauf: Eine geplante Aufgabe in der Claude-App (`breadbin-beitragsdurchsicht`, alle 30 Minuten, solange der Mac
läuft) fragt den Server mit **einer** Anfrage, ob ein noch nicht durchgesehener Beitrag wartet. Wenn nicht, endet
sie sofort. Wenn ja, folgt sie `tools/durchsicht-auftrag.md`: Beitrag holen, ScreenScraper-Abgleich mit der eigenen
Bibliothek, beurteilen (sparsam CSDb), Empfehlungen an den Server schicken, Bericht als Artefakt veröffentlichen
(Adresse im Text der geplanten Aufgabe). Die Prüfseite zeigt die Empfehlung an jedem Spiel; bei
„ablehnen“ ist der Haken „übernehmen“ schon entfernt.

### Fehlende Texte (Nutzerwunsch 2026-11-27)

- **Eine Sprache fehlt:** Die Durchsicht übersetzt den vorhandenen Text und legt ihn als Bearbeitung ab
  (`POST ?texte=N`). Der Server füllt nur leere Sprachen, nie wird ein vorhandener Text ersetzt. Auf der Prüfseite
  steht „Text von Claude ergänzt (EN)“ und am Spiel „bearbeitet von Breadbin“.
- **Beide Texte fehlen:** Die Durchsicht schreibt nichts von sich aus, sondern empfiehlt „nachbessern“. Auf der
  Prüfseite steht am Spiel der Knopf **„Texte von Claude schreiben lassen“**. Er legt einen Auftrag ab
  (`daten/<datei>.auftraege.json`), `?offen` meldet ihn, und der nächste Lauf (spätestens nach etwa 30 Minuten) schreibt
  DE und EN nach `tools/durchsicht-auftrag.md` (Schritt 4b). Auf der Prüfseite steht dann „Texte von Claude
  geschrieben – bitte sichten“. Ändern über „Bearbeiten“, freigeben mit „Annehmen“.
- **Viele Spiele ohne Text:** Oben im Beitrag steht dann „N Spiele ohne Beschreibung: Texte für alle von Claude
  schreiben lassen“ – ein Klick erteilt die Aufträge für alle Spiele ohne deutschen und englischen Text.
- **Art auf der Prüfseite ändern:** Das Formular „Bearbeiten“ hat Felder für Art (Spiel, Demo, Intro, Musik, Diskmag,
  Werkzeug, Grafik), Gruppe, Party und Platz (1–999). Spiel = Feld `kind` fehlt. Genres und Spielerzahl bei
  Nicht-Spielen leeren, sonst bleibt der Hinweis stehen.
- **Fehlende Angaben bei Demos usw. (Nutzerwunsch 2026-11-27):** `tools/durchsicht.py holen` schlägt für
  Nicht-Spiele fehlende Art, Gruppe, Party, Platz, Jahr und Mitwirkende vor – zuerst aus den lokalen Assembly64-Daten,
  dann aus CSDb (Webservice, `tools/csdb.py`), zuletzt aus pouet.net (`tools/pouet.py`); beide zwischengespeichert unter
  `~/.cache/breadbin-durchsicht/`, 2 s Pause, Obergrenze je Lauf (`CSDB_MAX` 15, `POUET_MAX` 10). Claude prüft und legt
  sie mit `POST ?felder=N` ab: nur leere Felder, Art nur statt „Spiel“. Die Prüfseite zeigt „Angaben von Claude
  ergänzt“ samt Quellen.
- **Bearbeitet von:** Bearbeitungen durch den Betreiber und durch Claude werden in den Daten immer als „Breadbin“
  geführt (Nutzerentscheidung 2026-11-27; `betreiber_name` in config.php, Standard Breadbin). `<datei>.edits.json` führt
  `bearbeitet_von`; der Betreiber wird nur bei echter Änderung eingetragen. Die geprüfte ZIP (Download und
  `api/export.php`) wird immer neu gebaut: Vom Einsender mitgeschickte `history`/`edited_by` fallen weg, bearbeitete
  Spiele bekommen `edited_by: ["Breadbin"]`, und `tools/uebernehmen.py` hängt das an den Herkunftseintrag in `history`.

### Bericht als gelesen markieren (Nutzerwunsch 2026-11-27)

Jeder Beitrag im Bericht (Artefakt „Beitragsdurchsicht“) hat den Knopf „Als gelesen markieren“. Das Artefakt hat dafür
die Fähigkeiten `db` (Regel: `gelesen` lesen alle mit Zugriff, schreiben nur der Besitzer) und `user`; eine Markierung
ist das Dokument `gelesen/b<Nr>` = `{gelesen, zeit, stand}`. Die geplante Aufgabe liest die Sammlung vor jedem Bericht
(ArtifactData `list`) und gibt sie `durchsicht_bericht.py --gelesen` mit: Gelesene Beiträge stehen eingeklappt unten;
kam seit dem Lesen etwas hinzu (jüngerer Zeitstempel bei Durchsicht, Texten oder Angaben), gelten sie wieder als
ungelesen. In der Abschlussmeldung zählen gelesene ohne Änderung nur noch am Rande.

`public/api/durchsicht.php` (eigener Schlüssel, kann nur lesen, Empfehlungen und fehlende Texte ablegen):

| Aufruf | Antwort |
| --- | --- |
| `GET ?offen` | neue Beiträge mit `durchgesehen` und Zahl `warten` |
| `GET ?id=N` | Spiele (bearbeitete Fassung), Hinweise, Vergleich mit dem Bestand, Bildangaben |
| `GET ?id=N&bild=images/…` | ein Bild des Beitrags |
| `POST ?durchsicht=N` | JSON `{zusammenfassung, bericht, spiele: {sha1: {empfehlung, grund}}}` → `daten/<datei>.claude.json` |
| `POST ?felder=N` | JSON `{spiele: {sha1: {kind, group, party, place, year, developer, quellen}}}` → nur leere Felder |
| `POST ?texte=N` | JSON `{spiele: {sha1: {de, en}}}` → Bearbeitung mit „bearbeitet von Breadbin“, erledigt Textaufträge |

**Assembly64 (lokal, nur lesen):** `tools/assembly64_index.py bauen` hasht die Dateien, die die App Assembly64 in
ihren Datenordner geladen hat (Ordner laut `~/.assembly64/db/customStorageLocation.db`; Demos/CSDB, Music/CSDB,
Graphics/CSDB, Games/CSDB, Gamebase, OneLoad64, Preservers …), und liest aus `.releaselog.json` und den Pfaden
CSDb-Nummer, Titel, Gruppe, Jahr, Party, Wettbewerb und Platz. Index: `~/.cache/breadbin-durchsicht/assembly64.sqlite`
(inkrementell, neu bauen nach neuen Downloads in Assembly64). `durchsicht.py holen` sucht jedes Spiel darin über die
Prüfsumme, sonst über den Titel, und meldet „Art prüfen“, wenn die Art laut Assembly64 nicht zur angegebenen passt.
Erst danach fragt die Durchsicht sparsam bei CSDb nach. Nur ein Werkzeug für die Prüfung – in der App bleibt
Assembly64 außen vor, bis geklärt ist, ob Fremdprogramme die Daten nutzen dürfen.

Der ScreenScraper-Abgleich geht nur lokal: `tools/durchsicht.py holen` vergleicht jeden Text über 5-Wort-Schindeln mit
allen ScreenScraper-Texten der eigenen Breadbin-Bibliothek (`metadata.provider = screenscraper*`, ~18 000 Texte).
Gegenprobe: Keiner der 6 790 eigenen Texte im Bestand schlägt an, eine kopierte Beschreibung erreicht 100 %.

### Einrichten (einmalig)

1. Schlüssel erzeugen und im Schlüsselbund ablegen (er erscheint dabei nicht auf dem Bildschirm):
   ```bash
   security add-generic-password -a breadbin -s breadbin-durchsicht -w "$(openssl rand -hex 32)"
   ```
2. Prüfsumme für `config.php` ausgeben:
   ```bash
   security find-generic-password -a breadbin -s breadbin-durchsicht -w | tr -d '\n' | shasum -a 256
   ```
   In `config.php` auf dem Webspace eintragen: `'durchsicht_token_sha256' => '<die 64 Zeichen>',`
3. Server hochladen: Workflow „Server hochladen“ (`server.yml`) im Daten-Repo starten (lädt `lib/` und `public/`).
4. Prüfen (eine Anfrage): `python3 tools/durchsicht.py offen`
5. Geplante Aufgabe auf „alle 30 Minuten“ stellen (Claude-App → Geplante Aufgaben → `breadbin-beitragsdurchsicht`).

Auf GD achten: Ohne die PHP-Erweiterung GD fällt nur die Prüfung „schwarz/leer“ weg, alles andere läuft.

### Lokal testen

```bash
docker build -t vp-php - <<'EOF'
FROM php:8.3-apache
RUN apt-get update -qq && apt-get install -y -qq libzip-dev libpng-dev libjpeg-dev libwebp-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp && docker-php-ext-install pdo_mysql zip gd
EOF
```

Dazu MariaDB 11, `schema.sql` einspielen, eine Test-`config.php` mit `daten_dir`, `stand_zip` und
`durchsicht_token_sha256`, `lib/` und `public/` einbinden. Testbeiträge (je Fall ein Spiel, `erwartet.json` nennt die
Regeln, die anschlagen müssen):

```bash
python3 server/test/testbeitraege.py /tmp/tb --app-db ~/Library/Application\ Support/de.dersedor.breadbin/spiele.sqlite
curl -F datei=@/tmp/tb/faelle.zip http://localhost:8093/public/api/beitrag.php
BREADBIN_DURCHSICHT_URL=http://localhost:8093/public/api/durchsicht.php BREADBIN_DURCHSICHT_TOKEN=… python3 tools/durchsicht.py holen 1
```

Getestet 2026-11-27 (PHP 8.3, MariaDB 11): Texte ergänzen (vorhandene bleiben), Textauftrag über die Prüfseite →
`offen` meldet ihn → Texte abgelegt → Auftrag erledigt, „bearbeitet von“ nach Änderung durch den Betreiber
(unverändertes Speichern trägt niemanden ein), `edited_by` in der geprüften ZIP; alle 17 Fälle, sauberer Beitrag ohne Hinweis, Prüfseite mit Hinweisen,
Claude-Empfehlungen, „nur auffällige zeigen“, Bearbeiten lässt Hinweise verschwinden, Aufräumen löscht die neuen
Begleitdateien, falsche Schlüssel (403) und ungültige Empfehlungen (422). Auf dem Webspace seit 2026-11-27 hochgeladen (server.yml), `durchsicht.py offen` antwortet; geplante Aufgabe läuft alle 30 Minuten.

---

## English summary

Two stages, both only flag (nothing is rejected on upload). **a)** `lib/vorpruefung.php` runs fixed rules on every game
of a contribution (missing or one-language descriptions, demos/intros/music/tools, links and warez/filehoster domains,
spam, slurs, gibberish, other languages, ratings and AI phrases, year/players/genre plausibility, title vs. file name,
duplicates against the published data, ScreenScraper hints, blank/black/odd-sized pictures) and shows red/yellow/grey
hints on the review page. **b)** A scheduled Claude task polls `public/api/durchsicht.php?offen` every 30 minutes (one
request); when a contribution waits, it follows `tools/durchsicht-auftrag.md`, compares texts with the ScreenScraper
texts of the local Breadbin library, writes a recommendation per game (accept / fix / reject) back to the review page
and publishes a report artifact. The review endpoint has its own token (`durchsicht_token_sha256`) that can only read
and store recommendations.
