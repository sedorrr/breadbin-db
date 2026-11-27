#!/usr/bin/env python3
"""CSDb-Angaben zu Demos, Intros, Musik usw. für die Durchsicht – sparsam und zwischengespeichert (nur Standardbibliothek).

    python3 tools/csdb.py release 105907        # Angaben eines Releases (Webservice, XML)
    python3 tools/csdb.py suche "Titel"         # Release-Nummern zu einem Namen (Suchseite)

Quelle: https://csdb.dk/webservice/?type=release&id=N (strukturiert: Typ, Datum, Party/Wettbewerb/Platz, Gruppen,
Mitwirkende, Dateinamen). Antworten liegen unter ~/.cache/breadbin-durchsicht/csdb/ und werden nicht erneut geholt.
Zwischen zwei Abrufen mindestens 2 s, höchstens CSDB_MAX (Standard 15) Abrufe je Programmlauf.
Only facts are taken over (no ratings, comments or trivia) – house rule of the community data.
"""
import html, json, os, re, sys, time, urllib.error, urllib.parse, urllib.request
import xml.etree.ElementTree as ET

ARBEIT = os.environ.get("BREADBIN_DURCHSICHT_DIR", os.path.join(os.path.expanduser("~"), ".cache", "breadbin-durchsicht"))
CACHE = os.path.join(ARBEIT, "csdb")
AGENT = "breadbin-durchsicht/1 (+https://breadbin.8bitcavern.de)"
MAX = int(os.environ.get("CSDB_MAX", "15"))
_abrufe = 0
_zuletzt = 0.0

# CSDb-Typ → Art im Austauschformat (kind); None = nicht aufnehmen (Cracktro), "game" = Spiel
TYPEN = [
    (r"crack ?intro|cracktro", None),
    (r"game", "game"),
    (r"intro", "intro"),
    (r"demo", "demo"),
    (r"music|sid", "music"),
    (r"graphics|gfx|picture", "graphics"),
    (r"diskmag|magazine|paper", "diskmag"),
    (r"tool|utility|editor", "tool"),
]
# Bezeichnungen der Mitwirkenden auf Deutsch, Reihenfolge wie in den eigenen Einträgen
ROLLEN = [("Code", "Code"), ("Graphics", "Grafik"), ("Music", "Musik"), ("Concept", "Konzept"), ("Idea", "Idee"),
          ("Design", "Design"), ("Text", "Text"), ("Loader", "Lader"), ("Packing", "Packer"), ("Charset", "Zeichensatz"),
          ("Logo", "Logo"), ("Video", "Video")]


def art_aus_typ(typ):
    t = (typ or "").lower()
    for muster, art in TYPEN:
        if re.search(muster, t):
            return art
    return "demo" if t else ""


def _abruf(url):
    global _abrufe, _zuletzt
    if _abrufe >= MAX:
        raise RuntimeError(f"Höchstzahl von {MAX} CSDb-Abrufen je Lauf erreicht")
    warte = 2.0 - (time.time() - _zuletzt)
    if warte > 0:
        time.sleep(warte)
    _abrufe += 1
    _zuletzt = time.time()
    req = urllib.request.Request(url, headers={"User-Agent": AGENT})
    try:
        import durchsicht  # gleicher TLS-Kontext (python.org-Python auf macOS)
        ctx = durchsicht.tls_kontext()
    except Exception:  # noqa: BLE001
        ctx = None
    with urllib.request.urlopen(req, timeout=30, context=ctx) as r:
        return r.geturl(), r.read()


def _text(e, pfad):
    x = e.find(pfad)
    return (x.text or "").strip() if x is not None and x.text else ""


def release(rid):
    """Angaben eines Releases als dict (zwischengespeichert)."""
    rid = str(int(rid))
    os.makedirs(CACHE, exist_ok=True)
    p = os.path.join(CACHE, f"{rid}.json")
    if os.path.isfile(p):
        return json.load(open(p, encoding="utf-8"))
    _, roh = _abruf(f"https://csdb.dk/webservice/?type=release&id={rid}&depth=2")
    r = ET.fromstring(roh).find("Release")
    if r is None:
        return None
    jahr = _text(r, "ReleaseYear")
    datum = "-".join(x for x in (jahr, _text(r, "ReleaseMonth").zfill(2) if _text(r, "ReleaseMonth") else "",
                                 _text(r, "ReleaseDay").zfill(2) if _text(r, "ReleaseDay") else "") if x)
    gruppen = [_text(g, "Name") for g in r.findall("ReleasedBy/Group") if _text(g, "Name")]
    gruppen += [_text(h, "Handle") for h in r.findall("ReleasedBy/Handle") if _text(h, "Handle")]
    mitwirkende = {}
    # Ein mehrfach genannter Name steht ab dem zweiten Mal nur als Nummer da – über die erste Nennung auflösen.
    namen = {_text(h, "ID"): _text(h, "Handle") for h in r.iter("Handle") if _text(h, "ID") and _text(h, "Handle")}
    for c in r.findall("Credits/Credit"):
        rolle = _text(c, "CreditType")
        name = _text(c, "Handle/Handle") or namen.get(_text(c, "Handle/ID"), "")
        if rolle and name:
            mitwirkende.setdefault(rolle, [])
            if name not in mitwirkende[rolle]:
                mitwirkende[rolle].append(name)
    dateien = [os.path.basename(urllib.parse.urlparse(_text(d, "Link")).path)
               for d in r.findall("DownloadLinks/DownloadLink") if _text(d, "Link")]
    a = r.find("Achievement")
    d = {"id": int(rid), "name": _text(r, "Name"), "aka": _text(r, "AKA"), "typ": _text(r, "Type"),
         "art": art_aus_typ(_text(r, "Type")), "jahr": jahr, "datum": datum,
         "party": _text(r, "ReleasedAt/Event/Name"), "ort": _text(r, "ReleasedAt/Event/City"),
         "wettbewerb": _text(a, "Compo") if a is not None else "", "platz": _text(a, "Place") if a is not None else "",
         "gruppen": gruppen, "mitwirkende": mitwirkende, "dateien": [x for x in dateien if x],
         "url": f"https://csdb.dk/release/?id={rid}"}
    json.dump(d, open(p, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    return d


def suche(name):
    """Release-Nummern zu einem Namen: [(id, name)] – höchstens 10. Ein eindeutiger Treffer leitet direkt weiter."""
    os.makedirs(CACHE, exist_ok=True)
    schluessel = re.sub(r"[^a-z0-9]+", "_", name.lower()).strip("_")[:80]
    p = os.path.join(CACHE, f"suche_{schluessel}.json")
    if os.path.isfile(p):
        return [tuple(x) for x in json.load(open(p, encoding="utf-8"))]
    url, roh = _abruf("https://csdb.dk/search/?seinsel=releases&search=" + urllib.parse.quote(name))
    m = re.search(r"release/\?id=(\d+)", url)
    if m:
        treffer = [(int(m.group(1)), name)]
    else:
        t = html.unescape(roh.decode("utf-8", "replace"))
        treffer = [(int(i), n) for i, n in re.findall(r'<a href="/release/\?id=(\d+)">([^<]+)</a>', t)][:10]
    json.dump(treffer, open(p, "w", encoding="utf-8"), ensure_ascii=False)
    return treffer


def mitwirkende_text(r, sprache="de"):
    """„Code: A, B · Grafik: C · Musik: D“ (Deutsch) bzw. englisch."""
    teile, gesehen = [], set()
    for en, de in ROLLEN:
        if r["mitwirkende"].get(en):
            teile.append(f"{de if sprache == 'de' else en}: {', '.join(r['mitwirkende'][en])}")
            gesehen.add(en)
    for en, namen in r["mitwirkende"].items():
        if en not in gesehen:
            teile.append(f"{en}: {', '.join(namen)}")
    return " · ".join(teile)


def vorschlag(r, g):
    """Felder, die im Eintrag g fehlen und die CSDb-Release r belegt (nur Fakten)."""
    v = {}
    if r["art"] and r["art"] != "game" and (g.get("art") or "game") == "game":
        v["kind"] = r["art"]
    leer = lambda f: not str((g.get("zusatz") or {}).get(f) or g.get(f) or "").strip()  # noqa: E731
    if r["gruppen"] and leer("group"):
        v["group"] = ", ".join(r["gruppen"])
    if r["party"] and leer("party"):
        v["party"] = r["party"]
    if r["platz"].isdigit() and leer("place"):
        v["place"] = int(r["platz"])
    if r["jahr"] and not str(g.get("year") or "").strip():
        v["year"] = r["jahr"]
    if r["mitwirkende"] and not str(g.get("developer") or "").strip():
        v["developer"] = mitwirkende_text(r)
    return v


def main():
    if len(sys.argv) < 3 or sys.argv[1] not in ("release", "suche"):
        sys.exit(__doc__)
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    if sys.argv[1] == "release":
        print(json.dumps(release(sys.argv[2]), ensure_ascii=False, indent=1))
    else:
        for i, n in suche(" ".join(sys.argv[2:])):
            print(i, n)


if __name__ == "__main__":
    main()
