#!/usr/bin/env python3
"""Durchsicht neuer Beiträge durch Claude – Werkzeug für den Rechner des Betreibers (nur Standardbibliothek).

Ablauf (Anleitung: server/VORPRUEFUNG.md):
    python3 tools/durchsicht.py offen                 # Beiträge, die auf Durchsicht warten; Exit 0 = welche, 3 = keine
    python3 tools/durchsicht.py holen N [--ziel DIR]  # Beitrag N als JSON (+ Bilder) holen, ScreenScraper-Abgleich
                                                      # (Standard-Ziel: ~/.cache/breadbin-durchsicht/N)
    python3 tools/durchsicht.py senden N DATEI.json   # Empfehlungen an die Prüfseite schicken
    python3 tools/durchsicht.py texte N DATEI.json    # von Claude geschriebene Texte als Bearbeitung ablegen
                                                      # (JSON {spiele: {sha1: {de, en}}}, nur fehlende Sprachen)

Schlüssel: Umgebungsvariable BREADBIN_DURCHSICHT_TOKEN oder macOS-Schlüsselbund
(`security add-generic-password -a breadbin -s breadbin-durchsicht -w <Schlüssel>`). Adresse:
BREADBIN_DURCHSICHT_URL (Standard: Annahme-Server auf 8bitcavern.de). Eigene Breadbin-Bibliothek für den
ScreenScraper-Abgleich: BREADBIN_APP_DB (Standard: Datenordner der App unter macOS/Linux/Windows).

Die ScreenScraper-Prüfung geht nur hier, nicht auf dem Server: Die eigene Bibliothek enthält die Texte, die die App
von ScreenScraper geholt hat. Ein eingereichter Text, der einem davon weitgehend gleicht, ist nicht teilbar.
Review helper for new contributions; one request per call, so it is safe for the hoster's rate limits.
"""
import argparse, json, os, re, sqlite3, subprocess, sys, urllib.error, urllib.parse, urllib.request

# Arbeitsordner außerhalb des Repositorys (tools/pruefen.py duldet keine fremden Ordner im Repo).
ARBEIT = os.environ.get("BREADBIN_DURCHSICHT_DIR", os.path.join(os.path.expanduser("~"), ".cache", "breadbin-durchsicht"))
STANDARD_URL = "https://8bitcavern.de/breadbin/db/breadbin/annahme/public/api/durchsicht.php"
AGENT = "breadbin-durchsicht/1"


def schluessel():
    t = os.environ.get("BREADBIN_DURCHSICHT_TOKEN", "").strip()
    if not t and sys.platform == "darwin":
        r = subprocess.run(["security", "find-generic-password", "-a", "breadbin", "-s", "breadbin-durchsicht", "-w"],
                           capture_output=True, text=True)
        t = r.stdout.strip()
    if not t:
        sys.exit("Kein Schlüssel: BREADBIN_DURCHSICHT_TOKEN setzen oder im Schlüsselbund ablegen (siehe VORPRUEFUNG.md)")
    return t


def anfrage(abfrage, daten=None, roh=False):
    url = os.environ.get("BREADBIN_DURCHSICHT_URL", STANDARD_URL) + "?" + abfrage
    req = urllib.request.Request(url, data=daten, method="POST" if daten is not None else "GET", headers={
        "X-Breadbin-Token": schluessel(), "User-Agent": AGENT, "Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            inhalt = r.read()
    except urllib.error.HTTPError as e:
        inhalt = e.read()
        try:
            fehler = json.loads(inhalt).get("fehler", "")
        except Exception:  # noqa: BLE001
            fehler = inhalt[:200].decode("utf-8", "replace")
        sys.exit(f"HTTP {e.code}: {fehler}")
    return inhalt if roh else json.loads(inhalt)


# ---- ScreenScraper-Abgleich mit der eigenen Bibliothek ----

def app_db():
    p = os.environ.get("BREADBIN_APP_DB")
    if p:
        return p
    home = os.path.expanduser("~")
    for kand in (os.path.join(home, "Library/Application Support/de.dersedor.breadbin/spiele.sqlite"),
                 os.path.join(os.environ.get("XDG_DATA_HOME", os.path.join(home, ".local/share")), "de.dersedor.breadbin/spiele.sqlite"),
                 os.path.join(os.environ.get("APPDATA", ""), "de.dersedor.breadbin/spiele.sqlite")):
        if os.path.isfile(kand):
            return kand
    return None


def woerter(t):
    t = t.lower()
    for a, b in (("ä", "ae"), ("ö", "oe"), ("ü", "ue"), ("ß", "ss")):
        t = t.replace(a, b)
    return re.findall(r"[a-z0-9]+", t)


def schindeln(t, n=5):
    w = woerter(t)
    return {" ".join(w[i:i + n]) for i in range(max(0, len(w) - n + 1))}


class SSTexte:
    """Alle ScreenScraper-Texte der eigenen Bibliothek, über 5-Wort-Schindeln durchsuchbar."""

    def __init__(self, pfad):
        con = sqlite3.connect(f"file:{pfad}?mode=ro", uri=True)
        self.texte = []
        self.index = {}
        for sha1, gid, titel, syn in con.execute(
                "SELECT sha1, game_id, title, synopsis FROM metadata WHERE provider LIKE 'screenscraper%' "
                "AND synopsis IS NOT NULL AND length(synopsis) >= 80"):
            i = len(self.texte)
            self.texte.append((sha1, gid or "", titel or "", syn))
            for s in schindeln(syn):
                self.index.setdefault(s, []).append(i)
        con.close()

    def treffer(self, text, grenze=0.35):
        """Bester ScreenScraper-Text, von dem mindestens `grenze` der Schindeln des eingereichten Texts stammen."""
        eigen = schindeln(text)
        if len(eigen) < 8:
            return None
        zaehl = {}
        for s in eigen:
            for i in self.index.get(s, ()):
                zaehl[i] = zaehl.get(i, 0) + 1
        if not zaehl:
            return None
        i, n = max(zaehl.items(), key=lambda x: x[1])
        anteil = n / len(eigen)
        if anteil < grenze:
            return None
        sha1, gid, titel, syn = self.texte[i]
        return {"anteil": round(anteil, 2), "titel": titel, "game_id": gid, "sha1": sha1, "auszug": syn[:200]}


# ---- Abgleich mit den lokalen Assembly64-Daten (tools/assembly64_index.py) ----

ARTNAMEN = {"game": "Spiel", "demo": "Demo", "intro": "Intro", "music": "Musik", "diskmag": "Diskmag", "tool": "Werkzeug",
            "graphics": "Grafik", "misc": "Sonstiges"}


def assembly64():
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    import assembly64_index as a64
    if not os.path.isfile(a64.INDEX):
        return None, None
    return a64, a64.oeffnen()


def a64_hinweise(g, treffer):
    """Hinweise aus den Assembly64-Treffern: Art laut CSDb/Sammlung gegen die angegebene Art."""
    h = []
    angegeben = g.get("art") or "game"
    pruef = [t for t in treffer if t["treffer"] == "pruefsumme"]
    if pruef:
        t = pruef[0]
        wo = ", ".join(x for x in (t["gruppe"], t["jahr"], t["party"] and f"{t['party']} {t['wettbewerb']} Platz {t['platz']}".strip(),
                                    t["csdb_id"] and f"CSDb {t['csdb_id']}") if x)
        laut = f"Assembly64 ({t['quelle'] or 'Sammlung'}): „{t['titel']}“" + (f" – {wo}" if wo else "")
        art = t["art"] if t["art"] in ARTNAMEN else "misc"
        if art != angegeben and not ({art, angegeben} <= {"demo", "intro"}):
            h.append({"stufe": "gelb", "regel": "art_a64",
                      "text": f"Angegeben: {ARTNAMEN.get(angegeben, angegeben)}, laut {laut} aber Art „{ARTNAMEN[art]}“ – Art prüfen"})
        else:
            h.append({"stufe": "info", "regel": "art_a64", "text": f"Datei bekannt: {laut} ({ARTNAMEN[art]})"})
    elif treffer:
        # gleiche Veröffentlichung nur einmal nennen, die mit den meisten Angaben zuerst
        eindeutig = {}
        for t in sorted(treffer, key=lambda t: -sum(bool(t[k]) for k in ("gruppe", "jahr", "csdb_id"))):
            eindeutig.setdefault((t["art"], t["titel"].lower()), t)
        namen = "; ".join(f"{ARTNAMEN.get(t['art'], t['art'])} „{t['titel']}“ {t['gruppe']} {t['jahr']}".strip()
                          for t in list(eindeutig.values())[:3])
        arten = {t["art"] for t in treffer}
        if angegeben not in arten and not (angegeben in ("demo", "intro") and arten & {"demo", "intro"}):
            h.append({"stufe": "gelb", "regel": "art_a64",
                      "text": f"Angegeben: {ARTNAMEN.get(angegeben, angegeben)}, gleicher Titel in Assembly64 aber nur als: {namen} – Art prüfen (andere Datei)"})
        else:
            h.append({"stufe": "info", "regel": "art_a64", "text": f"Gleicher Titel in Assembly64 (nicht dieselbe Datei): {namen}"})
    return h


# ---- Befehle ----

def offen(_a):
    d = anfrage("offen")
    warten = [b for b in d["beitraege"] if not b["durchgesehen"] or b.get("textauftraege")]
    for b in d["beitraege"]:
        auf = f"  TEXTAUFTRÄGE: {b['textauftraege']}" if b.get("textauftraege") else ""
        print(f"#{b['id']}  {b['eingang']}  {b['spiele']} Spiele  "
              + ("durchgesehen " + (b["durchsicht_zeit"] or "") if b["durchgesehen"] else "WARTET auf Durchsicht") + auf)
    if not warten:
        print("Nichts zu tun: kein Beitrag wartet auf Durchsicht.")
        return 3
    return 0


def holen(a):
    d = anfrage(f"id={a.id}")
    ziel = a.ziel or os.path.join(ARBEIT, str(a.id))
    os.makedirs(os.path.join(ziel, "bilder"), exist_ok=True)
    pfad = app_db()
    ss = SSTexte(pfad) if pfad and not a.ohne_ss else None
    a64, a64con = assembly64()
    for g in d["spiele"]:
        if a64con:
            g["assembly64"] = a64.suche(a64con, g["sha1"], g["title"])
            g["hinweise"][0:0] = [x for x in a64_hinweise(g, g["assembly64"]) if x["stufe"] != "info"]
            g["hinweise"] += [x for x in a64_hinweise(g, g["assembly64"]) if x["stufe"] == "info"]
        g["screenscraper"] = {}
        if ss:
            for sp in ("de", "en"):
                t = ss.treffer(g.get(f"text_{sp}") or "")
                if t:
                    g["screenscraper"][sp] = t
                    g["hinweise"].insert(0, {"stufe": "rot", "regel": "ss_lokal",
                                             "text": f"Text {sp.upper()} gleicht zu {int(t['anteil'] * 100)} % dem ScreenScraper-Text "
                                                     f"von „{t['titel']}“ (nicht teilbar)"})
        if not a.ohne_bilder:
            for art, b in (g.get("bilder") or {}).items():
                datei = os.path.join(ziel, "bilder", f"{g['sha1'][:8]}-{art}.{b['pfad'].rsplit('.', 1)[1]}")
                if not os.path.exists(datei):
                    open(datei, "wb").write(anfrage(f"id={a.id}&bild={urllib.parse.quote(b['pfad'])}", roh=True))
                b["datei"] = datei
    d["screenscraper_abgleich"] = bool(ss)
    d["assembly64_abgleich"] = bool(a64con)
    json.dump(d, open(os.path.join(ziel, "beitrag.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=2)
    rot = sum(1 for g in d["spiele"] if any(h["stufe"] == "rot" for h in g["hinweise"]))
    gelb = sum(1 for g in d["spiele"] if not any(h["stufe"] == "rot" for h in g["hinweise"])
               and any(h["stufe"] == "gelb" for h in g["hinweise"]))
    print(f"Beitrag #{a.id}: {len(d['spiele'])} Spiele, {rot} rot, {gelb} gelb → {ziel}/beitrag.json"
          + ("" if ss else "  (ohne ScreenScraper-Abgleich: keine Bibliothek gefunden)")
          + ("" if a64con else "  (ohne Assembly64-Abgleich: tools/assembly64_index.py bauen)"))
    return 0


def senden(a):
    daten = json.load(open(a.datei, encoding="utf-8"))
    r = anfrage(f"durchsicht={a.id}", json.dumps(daten, ensure_ascii=False).encode())
    print(f"Durchsicht für Beitrag #{r['id']} abgelegt ({r['spiele']} Spiele) – steht jetzt auf der Prüfseite.")
    return 0


def texte(a):
    daten = json.load(open(a.datei, encoding="utf-8"))
    r = anfrage(f"texte={a.id}", json.dumps(daten, ensure_ascii=False).encode())
    print(f"{r['texte']} Texte für Beitrag #{r['id']} abgelegt (auf der Prüfseite als „bearbeitet von Breadbin“).")
    for w in r.get("uebersprungen") or []:
        print("  übersprungen:", w)
    return 0


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="befehl", required=True)
    sub.add_parser("offen").set_defaults(f=offen)
    h = sub.add_parser("holen")
    h.add_argument("id", type=int)
    h.add_argument("--ziel")
    h.add_argument("--ohne-bilder", action="store_true")
    h.add_argument("--ohne-ss", action="store_true")
    h.set_defaults(f=holen)
    s = sub.add_parser("senden")
    s.add_argument("id", type=int)
    s.add_argument("datei")
    s.set_defaults(f=senden)
    t = sub.add_parser("texte")
    t.add_argument("id", type=int)
    t.add_argument("datei")
    t.set_defaults(f=texte)
    a = ap.parse_args()
    sys.exit(a.f(a))


if __name__ == "__main__":
    main()
