#!/usr/bin/env python3
"""pouet.net-Angaben zu C64-Demos, Intros usw. – dritte Quelle nach Assembly64 (lokal) und CSDb, sparsam.

    python3 tools/pouet.py suche "Titel"     # C64-Produktionen zu einem Namen

Quelle: https://api.pouet.net/v1/search/prod/?q=… (JSON). Antworten liegen unter ~/.cache/breadbin-durchsicht/pouet/.
Mindestens 2 s zwischen zwei Abrufen, höchstens POUET_MAX (Standard 10) je Programmlauf. Übernommen werden nur
Fakten (Typ, Gruppen, Datum, Party, Wettbewerb, Platz, CSDb-Nummer) – keine Wertungen oder Kommentare.
"""
import json, os, re, sys, time, urllib.parse, urllib.request

ARBEIT = os.environ.get("BREADBIN_DURCHSICHT_DIR", os.path.join(os.path.expanduser("~"), ".cache", "breadbin-durchsicht"))
CACHE = os.path.join(ARBEIT, "pouet")
AGENT = "breadbin-durchsicht/1 (+https://breadbin.8bitcavern.de)"
MAX = int(os.environ.get("POUET_MAX", "10"))
_abrufe = 0
_zuletzt = 0.0
# pouet-Typ → Art (kind); None = Cracktro (wird nicht aufgenommen)
TYPEN = {"cracktro": None, "game": "game", "intro": "intro", "4k": "intro", "1k": "intro", "256b": "intro",
         "128b": "intro", "64k": "intro", "demo": "demo", "demotool": "tool", "tool": "tool", "musicdisk": "music",
         "music": "music", "procedural graphics": "graphics", "artpack": "graphics", "slideshow": "graphics",
         "diskmag": "diskmag", "report": "demo", "invitation": "demo", "wild": "demo", "dentro": "demo"}


def _abruf(url):
    global _abrufe, _zuletzt
    if _abrufe >= MAX:
        raise RuntimeError(f"Höchstzahl von {MAX} pouet-Abrufen je Lauf erreicht")
    warte = 2.0 - (time.time() - _zuletzt)
    if warte > 0:
        time.sleep(warte)
    _abrufe += 1
    _zuletzt = time.time()
    try:
        import durchsicht
        ctx = durchsicht.tls_kontext()
    except Exception:  # noqa: BLE001
        ctx = None
    req = urllib.request.Request(url, headers={"User-Agent": AGENT})
    with urllib.request.urlopen(req, timeout=30, context=ctx) as r:
        return json.loads(r.read())


def art_aus_typen(typen):
    for t in typen:
        t = (t or "").lower()
        if t in TYPEN:
            return TYPEN[t]
    return "demo" if typen else ""


def _prod(x):
    typen = [t for t in (x.get("types") or [x.get("type")]) if t]
    party = x.get("party") or {}
    datum = (x.get("releaseDate") or "").replace("-00", "")
    platz = str(x.get("party_place") or "")
    return {"id": int(x["id"]), "name": x.get("name", ""), "typen": typen, "art": art_aus_typen(typen),
            "gruppen": [g.get("name", "") for g in (x.get("groups") or []) if g.get("name")],
            "jahr": datum[:4] if datum[:4].isdigit() else "", "datum": datum,
            "party": " ".join(v for v in ((party.get("name") or "") if isinstance(party, dict) else "",
                                          str(x.get("party_year") or "")) if v).strip() if isinstance(party, dict) and party.get("name") else "",
            # party_compo ist bei pouet nur eine Nummer – nicht übernehmen
            "wettbewerb": "", "platz": platz if platz.isdigit() and platz != "0" else "",
            "csdb": int(x["csdb"]) if str(x.get("csdb") or "").isdigit() and int(x["csdb"]) > 0 else None,
            "url": f"https://www.pouet.net/prod.php?which={x['id']}"}


def suche(name):
    """C64-Produktionen zu einem Namen (zwischengespeichert)."""
    os.makedirs(CACHE, exist_ok=True)
    schluessel = re.sub(r"[^a-z0-9]+", "_", name.lower()).strip("_")[:80]
    p = os.path.join(CACHE, f"suche_{schluessel}.json")
    if os.path.isfile(p):
        return json.load(open(p, encoding="utf-8"))
    d = _abruf("https://api.pouet.net/v1/search/prod/?q=" + urllib.parse.quote(name))
    if d.get("error"):
        # Die Suche verträgt nicht jede Wortfolge: mit dem längsten Wort noch einmal, dann nach dem Namen filtern
        wort = max(re.findall(r"[A-Za-z0-9]+", name) or [name], key=len)
        d = _abruf("https://api.pouet.net/v1/search/prod/?q=" + urllib.parse.quote(wort)) if wort != name else {}
    roh = d.get("results") or []
    roh = list(roh.values()) if isinstance(roh, dict) else roh
    aus = [_prod(x) for x in roh
           if any("commodore 64" in (pl.get("name") or "").lower() for pl in (x.get("platforms") or {}).values())]
    json.dump(aus, open(p, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    return aus


def main():
    if len(sys.argv) < 3 or sys.argv[1] != "suche":
        sys.exit(__doc__)
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    for x in suche(" ".join(sys.argv[2:])):
        print(json.dumps(x, ensure_ascii=False))


if __name__ == "__main__":
    main()
