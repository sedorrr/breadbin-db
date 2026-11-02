# Breadbin-Gemeinschaftsdatenbank

*English: [README.md](README.md)*

Angaben zu Commodore-64-Spielen, geschrieben von Breadbin-Nutzern – frei nutzbar unter
[CC BY-SA 4.0](LICENSE). [Breadbin](https://8bitcavern.de) liest diese Datenbank und ordnet jeden
Eintrag über die Prüfsumme deinen Dateien zu, egal wie sie heißen und wo sie liegen.

## Was hier liegt

- `games/<ab>/<sha1>.json` – je Spieldatei (Disk-Abbild, Modul, Programm) eine Datei, benannt nach der
  SHA-1 ihres Inhalts: Prüfsummen, bekannte Dateinamen, Titel, Jahr, Herausgeber, Entwickler, Genres,
  Spieler, Beschreibung und die verwendeten Bilder.
- `images/<ab>/<sha1>.<ext>` – Bilder (Titelbild, Spielszene, Verpackung), benannt nach der SHA-1 des
  Bildes selbst.
- `breadbin-share.json` – Format und Lizenz der Daten.

Breadbins Austauschdateien (Teilen → Austauschdatei speichern) haben denselben Aufbau; eine
Austauschdatei lässt sich also direkt in dieses Repository auspacken.

## Daten nutzen

Breadbin lädt den aktuellen Stand mit **Gemeinschaftsdaten aktualisieren**. Alternativ die
Austauschdatei aus dem Release „stand“ herunterladen und in Breadbin mit **Austauschdatei einlesen …**
öffnen.

## Mitmachen

Siehe [CONTRIBUTING.md](CONTRIBUTING.md). Kurz: Angaben in Breadbin selbst schreiben, Austauschdatei
speichern, in einen Fork dieses Repositorys auspacken und einen Pull Request stellen. Jeder Beitrag wird
automatisch geprüft (`tools/pruefen.py`).

Willkommen ist nur Selbsterstelltes: eigene Texte und eigene Bildschirmfotos. Texte und Bilder von
ScreenScraper, MobyGames, fremde Verpackungsscans oder Grafiken der Herausgeber dürfen nicht hinein.

## Lizenz

Die Daten stehen unter der Creative-Commons-Lizenz Namensnennung – Weitergabe unter gleichen
Bedingungen 4.0 International ([LICENSE](LICENSE)). Namensnennung: „Breadbin-Gemeinschaftsdatenbank,
https://github.com/sedorrr/breadbin-db“ und die Beitragenden laut Git-Verlauf.
