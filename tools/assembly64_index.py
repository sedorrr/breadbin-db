#!/usr/bin/env python3
"""Index der lokalen Assembly64-Daten (CSDb-Demos, Musik, Grafik, Spielsammlungen) für die Durchsicht – nur lesen.

    python3 tools/assembly64_index.py bauen [--wurzel DIR]   # Dateien hashen (inkrementell), Index schreiben
    python3 tools/assembly64_index.py suche SHA1|TITEL       # Treffer anzeigen

Assembly64 lädt die Sammlungen in einen Ordner (Einstellung in ~/.assembly64/db/customStorageLocation.db) mit
Kategorien Demos/, Games/, Music/, Graphics/ und Quellen wie CSDB/, Gamebase/, OneLoad64/. `.releaselog.json` in den
Unterordnern nennt je Veröffentlichung CSDb-Nummer, Name, Gruppe, Jahr und den Pfad (bei Year-party-group:
Jahr/Party/Wettbewerb/Platz. Gruppe). Der Index (SQLite) liegt unter ~/.cache/breadbin-durchsicht/assembly64.sqlite,
außerhalb des Repositorys. Er dient nur als Werkzeug für die Prüfung; eine Anbindung in der App ist nicht geplant,
solange nicht geklärt ist, ob Fremdprogramme Assembly64-Daten nutzen dürfen.

Local index of the Assembly64 data (read-only) for reviewing contributions: SHA-1 and title → category, group, year,
party, CSDb id.
"""
import hashlib, json, os, re, sqlite3, sys, time

ARBEIT = os.environ.get("BREADBIN_DURCHSICHT_DIR", os.path.join(os.path.expanduser("~"), ".cache", "breadbin-durchsicht"))
INDEX = os.path.join(ARBEIT, "assembly64.sqlite")
ENDUNGEN = {"d64", "d71", "d81", "g64", "prg", "t64", "tap", "crt", "sid", "bin", "p00", "reu"}
KATEGORIEN = {"Demos": "demo", "Games": "game", "Music": "music", "Graphics": "graphics", "Intros": "intro",
              "Tools": "tool", "Discmags": "diskmag", "Misc": "misc"}
WEGLASSEN = {"tmp", "log", "created-images", "persistent", "Favourites"}


def wurzel_standard():
    p = os.path.join(os.path.expanduser("~"), ".assembly64", "db", "customStorageLocation.db")
    try:
        return open(p, encoding="utf-8").read().strip()
    except OSError:
        return None


def norm(t):
    t = (t or "").lower()
    for a, b in (("ä", "ae"), ("ö", "oe"), ("ü", "ue"), ("ß", "ss")):
        t = t.replace(a, b)
    t = re.sub(r"\s*[\[(][^\])]*[\])]", "", t)
    t = re.sub(r"\s+\+\s*[0-9a-z]*\s*$", "", t)
    t = re.sub(r"[^a-z0-9]+", " ", t).strip()
    return re.sub(r"^(the|der|die|das) ", "", t)


def oeffnen():
    os.makedirs(ARBEIT, exist_ok=True)
    con = sqlite3.connect(INDEX)
    con.executescript("""
CREATE TABLE IF NOT EXISTS dateien(pfad TEXT PRIMARY KEY, groesse INTEGER, mtime REAL, sha1 TEXT, art TEXT, quelle TEXT,
  titel TEXT, titel_norm TEXT, gruppe TEXT, jahr TEXT, party TEXT, wettbewerb TEXT, platz TEXT, csdb_id TEXT);
CREATE INDEX IF NOT EXISTS dateien_sha1 ON dateien(sha1);
CREATE INDEX IF NOT EXISTS dateien_titel ON dateien(titel_norm);
""")
    return con


def releaselogs(basis):
    """Alle .releaselog.json unter basis: absoluter Ordner der Veröffentlichung → Angaben."""
    karte = {}
    for dirpath, dirnames, files in os.walk(basis):
        dirnames[:] = [d for d in dirnames if d not in WEGLASSEN and not d.startswith(".")]
        if ".releaselog.json" in files:
            try:
                for e in json.load(open(os.path.join(dirpath, ".releaselog.json"), encoding="utf-8")):
                    if isinstance(e, dict) and e.get("path"):
                        karte[os.path.normpath(os.path.join(dirpath, e["path"]))] = e
            except (OSError, ValueError):
                pass
    return karte


def angaben(wurzel, pfad, logs):
    """Kategorie, Quelle und Angaben zu einer Datei aus Pfad und releaselog."""
    teile = os.path.relpath(pfad, wurzel).split(os.sep)
    art = KATEGORIEN.get(teile[0], teile[0].lower())
    quelle = teile[1] if len(teile) > 2 else ""
    a = {"art": art, "quelle": quelle, "titel": "", "gruppe": "", "jahr": "", "party": "", "wettbewerb": "", "platz": "",
         "csdb_id": ""}
    d = os.path.dirname(pfad)
    while len(d) > len(wurzel):
        e = logs.get(d)
        if e:
            a.update(titel=e.get("name") or "", gruppe=e.get("group") or "", jahr=str(e.get("year") or ""),
                     csdb_id=str(e.get("id") or "") if e.get("type") == "csdb" else "")
            break
        d = os.path.dirname(d)
    rest = teile[2:-1]
    # Year-party-group: Jahr/Party/Wettbewerb/NN. Gruppe
    if len(teile) > 2 and teile[2] == "Year-party-group" and len(rest) >= 5:
        a["jahr"] = a["jahr"] or rest[1]
        a["party"], a["wettbewerb"] = rest[2], rest[3]
        m = re.match(r"^(\d+)\.\s+(.*)$", rest[4])
        if m:
            a["platz"] = str(int(m.group(1)))
            a["gruppe"] = a["gruppe"] or m.group(2)
        if re.search(r"intro", a["wettbewerb"], re.I) and art == "demo":
            a["art"] = "intro"
    elif len(teile) > 2 and teile[2] == "All" and len(rest) >= 3:
        a["gruppe"] = a["gruppe"] or rest[2]
        a["titel"] = a["titel"] or (rest[3] if len(rest) > 3 else "")
    if not a["titel"]:
        a["titel"] = re.sub(r"^\d+\.\s+", "", rest[-1]) if rest else os.path.splitext(teile[-1])[0]
    return a


def bauen(wurzel):
    con = oeffnen()
    bekannt = {p: (g, m) for p, g, m in con.execute("SELECT pfad, groesse, mtime FROM dateien")}
    logs = releaselogs(wurzel)
    gesehen, neu, start = set(), 0, time.time()
    for dirpath, dirnames, files in os.walk(wurzel):
        dirnames[:] = [d for d in dirnames if d not in WEGLASSEN and not d.startswith(".")]
        for f in files:
            if f.rsplit(".", 1)[-1].lower() not in ENDUNGEN:
                continue
            p = os.path.join(dirpath, f)
            try:
                st = os.stat(p)
            except OSError:
                continue
            gesehen.add(p)
            if bekannt.get(p) == (st.st_size, st.st_mtime):
                continue
            try:
                h = hashlib.sha1(open(p, "rb").read()).hexdigest()
            except OSError:
                continue
            a = angaben(wurzel, p, logs)
            con.execute("INSERT OR REPLACE INTO dateien VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                        (p, st.st_size, st.st_mtime, h, a["art"], a["quelle"], a["titel"], norm(a["titel"]), a["gruppe"],
                         a["jahr"], a["party"], a["wettbewerb"], a["platz"], a["csdb_id"]))
            neu += 1
            if neu % 2000 == 0:
                con.commit()
                print(f"  {neu} Dateien gehasht ({time.time() - start:.0f} s)", flush=True)
    weg = [p for p in bekannt if p not in gesehen]
    con.executemany("DELETE FROM dateien WHERE pfad = ?", [(p,) for p in weg])
    con.commit()
    n = con.execute("SELECT COUNT(*) FROM dateien").fetchone()[0]
    print(f"Index: {n} Dateien ({neu} neu/geändert, {len(weg)} entfernt) → {INDEX}")


def suche(con, sha1=None, titel=None, grenze=5):
    """Treffer zu Prüfsumme und/oder Titel als Liste von dicts (Prüfsumme zuerst)."""
    spalten = ["pfad", "art", "quelle", "titel", "gruppe", "jahr", "party", "wettbewerb", "platz", "csdb_id"]
    aus = []
    if sha1:
        for r in con.execute(f"SELECT {','.join(spalten)} FROM dateien WHERE sha1 = ? LIMIT ?", (sha1, grenze)):
            aus.append({"treffer": "pruefsumme", **dict(zip(spalten, r))})
    if titel and not aus:
        for r in con.execute(f"SELECT {','.join(spalten)} FROM dateien WHERE titel_norm = ? GROUP BY art, gruppe, csdb_id "
                             "LIMIT ?", (norm(titel), grenze)):
            aus.append({"treffer": "titel", **dict(zip(spalten, r))})
    return aus


def main():
    if len(sys.argv) < 2 or sys.argv[1] not in ("bauen", "suche"):
        sys.exit(__doc__)
    if sys.argv[1] == "bauen":
        w = sys.argv[3] if len(sys.argv) > 3 and sys.argv[2] == "--wurzel" else wurzel_standard()
        if not w or not os.path.isdir(w):
            sys.exit(f"Assembly64-Ordner nicht gefunden ({w}) – Laufwerk eingehängt?")
        bauen(w)
    else:
        q = " ".join(sys.argv[2:])
        con = oeffnen()
        ist_sha = bool(re.fullmatch(r"[0-9a-f]{40}", q))
        for t in suche(con, q if ist_sha else None, None if ist_sha else q, 20):
            print(json.dumps(t, ensure_ascii=False))


if __name__ == "__main__":
    main()
