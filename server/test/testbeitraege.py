#!/usr/bin/env python3
"""Baut Testbeiträge für die Vorprüfung (server/VORPRUEFUNG.md), je Fall ein Spiel, nur Standardbibliothek.

    python3 server/test/testbeitraege.py <Zielordner> [--app-db <spiele.sqlite>]

Erzeugt <Ziel>/sauber.zip (unauffällig) und <Ziel>/faelle.zip (alle Auffälligkeiten) sowie <Ziel>/erwartet.json
(Titel → Regeln, die mindestens anschlagen müssen). Mit --app-db wird zusätzlich ein Spiel mit einem
ScreenScraper-Text aus der eigenen Breadbin-Bibliothek eingebaut (für tools/durchsicht.py; landet nie im Repository).
Builds test contributions for the pre-check, one game per case.
"""
import argparse, hashlib, json, os, random, sqlite3, struct, sys, zipfile, zlib

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))


def png(b, h, muster):
    """PNG (RGB) erzeugen: muster(x, y) -> (r, g, b)."""
    roh = b"".join(b"\0" + b"".join(bytes(muster(x, y)) for x in range(b)) for y in range(h))

    def chunk(t, d):
        return struct.pack(">I", len(d)) + t + d + struct.pack(">I", zlib.crc32(t + d) & 0xFFFFFFFF)
    return (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", b, h, 8, 2, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress(roh, 9)) + chunk(b"IEND", b""))


C64 = [(0, 0, 0), (255, 255, 255), (136, 57, 50), (103, 182, 189), (139, 63, 150), (85, 160, 73), (64, 49, 141),
       (191, 206, 114), (139, 84, 41), (87, 66, 0), (184, 105, 98), (80, 80, 80), (120, 120, 120), (148, 224, 137),
       (120, 105, 196), (159, 159, 159)]


def szene(b, h, saat):
    r = random.Random(saat)
    kacheln = [[r.choice(C64) for _ in range(b // 8 + 1)] for _ in range(h // 8 + 1)]
    return png(b, h, lambda x, y: kacheln[y // 8][x // 8])


def spiel_sha1(name):
    return hashlib.sha1(name.encode()).hexdigest()


class Beitrag:
    def __init__(self):
        self.spiele, self.bilder = {}, {}

    def bild(self, daten, endung="png"):
        s = hashlib.sha1(daten).hexdigest()
        pfad = f"images/{s[:2]}/{s}.{endung}"
        self.bilder[pfad] = daten
        return pfad

    def spiel(self, schluessel, **felder):
        sha1 = felder.pop("sha1", None) or spiel_sha1(schluessel)
        g = {"version": 1, "sha1": sha1, "md5": hashlib.md5(sha1.encode()).hexdigest(),
             "crc32": f"{zlib.crc32(sha1.encode()) & 0xFFFFFFFF:08x}", "size": 174848, "format": "D64"}
        de, en = felder.pop("de", None), felder.pop("en", None)
        g.update(felder)
        if en:
            g["texts"] = {"de": de, "en": en} if de else {"en": en}
            g["synopsis"] = de or en
        elif de:
            g["synopsis"] = de
        self.spiele[sha1] = g
        return sha1

    def schreiben(self, pfad):
        with zipfile.ZipFile(pfad, "w", zipfile.ZIP_DEFLATED) as z:
            z.writestr("breadbin-share.json", json.dumps({"format": "breadbin-share", "version": 1, "created": 1,
                                                          "app": "Breadbin/0.9.4", "license": "CC-BY-SA-4.0",
                                                          "games": len(self.spiele)}))
            for sha1, g in self.spiele.items():
                z.writestr(f"games/{sha1[:2]}/{sha1}.json", json.dumps(g, ensure_ascii=False, indent=2))
            for p, d in self.bilder.items():
                z.writestr(p, d)


DE = ("Ein kleiner Roboter erkundet eine verlassene Raumstation und sammelt Energiezellen, um die Reaktoren wieder "
      "anzuwerfen. Die Räume sind über Aufzüge verbunden; Wachdrohnen patrouillieren auf festen Bahnen. Gesteuert wird "
      "mit dem Joystick in Port 2.")
EN = ("A small robot explores an abandoned space station and collects energy cells to restart the reactors. The rooms "
      "are linked by lifts; guard drones patrol on fixed paths. It is controlled with a joystick in port 2.")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("ziel")
    ap.add_argument("--app-db")
    a = ap.parse_args()
    os.makedirs(a.ziel, exist_ok=True)
    erwartet = {}

    # 1. Unauffälliger Beitrag
    s = Beitrag()
    s.spiel("robo", title="Robo Station", files=["robostation.d64"], year="2025", publisher="Testsoft",
            developer="T. Tester", genres=["Plattform / Jump 'n' Run"], players="1", de=DE, en=EN,
            images={"sstitle": s.bild(szene(384, 272, 1)), "ss": s.bild(szene(320, 200, 2))})
    s.schreiben(os.path.join(a.ziel, "sauber.zip"))
    erwartet["Robo Station"] = []

    # 2. Ein Spiel je Auffälligkeit
    f = Beitrag()
    gut = dict(year="2025", publisher="Testsoft", genres=["Action"], players="1")

    def fall(titel, regeln, **felder):
        werte = {**gut, "title": titel, **felder}
        werte.setdefault("files", [titel.lower().replace(" ", "_") + ".d64"])
        f.spiel(titel, **werte)
        erwartet[titel] = regeln

    fall("Ohne Text", ["text_fehlt"])
    fall("Auch ohne Text", ["text_fehlt"])
    fall("Nur Deutsch", ["text_en"], de=DE)
    fall("Nur Englisch", ["text_de"], en=EN)
    fall("Edge of Disgrace", ["art"], files=["edge_of_disgrace_demo.d64"], genres=["Verschiedenes"], de=DE, en=EN)
    fall("Echte Demo", [], kind="demo", files=["echte_demo.d64"], genres=[], players="", group="Testgruppe",
         party="Testparty 2025", place=2, developer="Code: A, Grafik: B, Musik: C", de=DE, en=EN)
    fall("Demo mit Spielern", ["art"], kind="demo", files=["demo_mit_spielern.d64"], genres=[], players="1", place=0,
         group="Testgruppe", de=DE, en=EN)
    fall("Demo mit Genre", ["art"], kind="demo", files=["demo_mit_genre.d64"], de=DE, en=EN)
    fall("Unbekannte Art", ["art"], kind="spielchen", de=DE, en=EN)
    fall("Fairlight Crack Intro", ["cracktro"], files=["fairlight_cracktro.prg"], de=DE, en=EN)
    fall("Sound Waves", ["art"], files=["musicdisk.prg"], de=DE,
         en="This is a music disk by a well-known group, with twelve SID tunes and a scrolling text. " + EN)
    fall("Link Runner", ["link", "download"], publisher="www.example-soft.com",
         de=DE + " Kostenlos herunterladen: https://romsfun.com/roms/c64/link-runner und csdb.dk/release/?id=1",
         en=EN + " Free download at https://mega.nz/file/abc")
    fall("Spam Attack", ["spam", "beleidigung"], de=DE + " Jetzt kaufen im Casino, Arschloch!", en=EN + " Buy now, bitcoin!")
    fall("Kauderwelsch", ["unsinn", "sprache"], de="asdfghjkl qwertzuiopasdfghjklyxcvbnmqwertzuiopasdfghjkl !!!!!!!!!!! 12345 678",
         en="Le robot explore la station et le joueur doit trouver les cellules pour le vaisseau dans le jeu, avec des pièges.")
    fall("Turrican Plus", ["jahr", "spieler", "genre", "titel_datei"], files=["boulder.d64"], year="1979", players="viele",
         genres=["Fighting", "Action / Kampfsport"], de=DE, en=EN)
    fall("Zukunft", ["jahr"], year="2099", de=DE, en=EN)
    fall("Lobeshymne", ["wertung", "note", "ki", "kommentar"],
         de="Eines der besten Spiele aller Zeiten, absolut empfehlenswert – 9/10. Laut Kommentaren ein Meisterwerk. " + DE,
         en="As an AI language model I think this is one of the best games ever. " + EN)
    fall("Gleich", ["text_gleich"], de=EN, en=EN)

    # Dublette: Text und Bild eines vorhandenen Spiels aus dem Bestand
    bestand = None
    for dirpath, _, names in os.walk(os.path.join(ROOT, "games")):
        for n in sorted(names):
            g = json.load(open(os.path.join(dirpath, n), encoding="utf-8"))
            if g.get("texts", {}).get("de") and len(g["texts"]["de"]) > 200 and g.get("images", {}).get("sstitle"):
                bestand = g
                break
        if bestand:
            break
    if bestand:
        bild = open(os.path.join(ROOT, bestand["images"]["sstitle"]), "rb").read()
        endung = bestand["images"]["sstitle"].rsplit(".", 1)[1]
        fall("Kopierspiel", ["dublette"], de=bestand["texts"]["de"], en=EN, images={"sstitle": f.bild(bild, endung)})
        # Bekanntes Spiel (gleiche SHA-1) mit geändertem Jahr → „geändert“ auf der Prüfseite
        f.spiel("x", **{k: v for k, v in bestand.items() if k not in ("texts", "synopsis", "version", "md5", "crc32", "size", "format", "year")},
                year="2024", de=bestand["texts"]["de"], en=bestand["texts"].get("en", EN))
        erwartet[bestand["title"]] = []

    schwarz = f.bild(png(320, 200, lambda x, y: (0, 0, 0)))
    ss = f.bild(szene(320, 240, 5))
    fall("Bilderprobe", ["bild"], de=DE, en=EN, images={"sstitle": schwarz, "ss": ss, "box-2D": ss})
    fall("Französisch", ["screenscraper", "sprache"], screenscraper_id="12345",
         de="Le joueur dirige un petit robot dans une station spatiale abandonnée et doit récupérer les cellules d'énergie pour le jeu.")

    if a.app_db:
        con = sqlite3.connect(f"file:{a.app_db}?mode=ro", uri=True)
        r = con.execute("select title, synopsis, game_id from metadata where provider='screenscraper' and length(synopsis) > 300 "
                        "order by sha1 limit 1").fetchone()
        if r:
            fall("SS-Kopie " + r[0], ["ss_lokal"], files=[r[0].lower().replace(" ", "") + ".d64"], de=r[1], en=EN,
                 screenscraper_id=r[2] or "")

    f.schreiben(os.path.join(a.ziel, "faelle.zip"))
    json.dump(erwartet, open(os.path.join(a.ziel, "erwartet.json"), "w"), ensure_ascii=False, indent=2)
    print(f"{len(s.spiele)} + {len(f.spiele)} Spiele in {a.ziel}")


if __name__ == "__main__":
    main()
