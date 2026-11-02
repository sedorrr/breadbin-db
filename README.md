# Breadbin community database

*Deutsch: [README.de.md](README.de.md)*

Game details for Commodore 64 games, written by Breadbin users – free to use under
[CC BY-SA 4.0](LICENSE). [Breadbin](https://8bitcavern.de) reads this database and matches every entry
to your files by checksum, whatever they are called and wherever they are stored.

## What is in here

- `games/<ab>/<sha1>.json` – one file per game file (disk image, cartridge, program), named after the
  SHA-1 of its content: checksums, known file names, title, year, publisher, developer, genres,
  players, description and the pictures used.
- `images/<ab>/<sha1>.<ext>` – pictures (title screen, in-game shot, box), named after the SHA-1 of
  the picture itself.
- `breadbin-share.json` – format and license of the data.

The same layout is used by Breadbin’s exchange files (Share → Save exchange file), so an exchange file
can be unpacked straight into this repository.

## Using the data

Breadbin downloads the current state with **Update community data**. You can also download the
exchange file from the release “stand” and read it in Breadbin with **Read exchange file …**.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). In short: write the details yourself in Breadbin, save an
exchange file, unpack it into a fork of this repository and open a pull request. Every contribution
is checked automatically (`tools/pruefen.py`).

Only content you created yourself is welcome: your own texts and your own screenshots. Texts and
pictures copied from ScreenScraper, MobyGames, box scans of other people or publishers’ artwork may
not be added.

## License

The data is licensed under the Creative Commons Attribution-ShareAlike 4.0 International license
([LICENSE](LICENSE)). Attribution: “Breadbin community database, https://github.com/sedorrr/breadbin-db”
and the contributors listed in the git history.
