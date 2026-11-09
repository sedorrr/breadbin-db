#!/usr/bin/env python3
"""Baut die öffentliche Übersichtsseite der Gemeinschaftsdaten: dist/seite/index.html + spiele.json.

Die Seite zeigt eine Statistik und eine Suche; sie lädt nur Dateien vom eigenen Webspace (keine fremden Schriften,
keine Zähler). Bilder liegen daneben im Ordner images/ (lädt der Spiegel hoch).

Builds the public overview page of the community data: dist/seite/index.html + spiele.json (statistics and search,
no third-party requests). Images sit next to it in images/ (uploaded by the mirror workflow).
"""
import json, os, re, shutil, sys, time

ROOT = os.path.abspath(sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(__file__), ".."))
OUT = os.path.join(ROOT, "dist", "seite")


def key(title):
    """Gleiche Spiele zusammenfassen: Titel ohne Groß/klein, Satzzeichen und Klammerzusätze."""
    t = re.sub(r"\[[^\]]*\]", "", title.lower())
    return re.sub(r"[^a-z0-9äöüß]+", "", t)


def main():
    groups = {}
    files = 0
    for dirpath, _, names in os.walk(os.path.join(ROOT, "games")):
        for n in sorted(names):
            if not n.endswith(".json"):
                continue
            g = json.load(open(os.path.join(dirpath, n), encoding="utf-8"))
            files += 1
            k = key(g.get("title") or g["sha1"])
            e = groups.setdefault(k, {"t": g.get("title") or "?", "v": []})
            for src, dst in (("year", "y"), ("publisher", "p"), ("developer", "d"), ("players", "pl")):
                if g.get(src) and not e.get(dst):
                    e[dst] = g[src]
            # Beschreibungen wie in der App: `texts` vor `synopsis` (Einträge vor 0.8.0 haben nur `synopsis`).
            texts = g.get("texts") or {}
            en = (texts.get("en") or "").strip()
            de = (texts.get("de") or "").strip() or ((g.get("synopsis") or "").strip() if (g.get("synopsis") or "").strip() != en else "")
            if en and not e.get("se"):
                e["se"] = en
            if de and not e.get("s"):
                e["s"] = de
            if g.get("genres") and not e.get("g"):
                e["g"] = g["genres"]
            imgs = g.get("images") or {}
            for kind in ("sstitle", "ss"):
                if imgs.get(kind) and not e.get(kind):
                    e[kind] = imgs[kind]
            e["v"].append({"f": g.get("format", ""), "n": (g.get("files") or [""])[0], "h": g["sha1"],
                           "z": g.get("size", 0)})
    games = sorted(groups.values(), key=lambda e: e["t"].lower())
    manifest = json.load(open(os.path.join(ROOT, "breadbin-share.json"), encoding="utf-8"))
    data = {"created": int(time.time()), "license": manifest.get("license", "CC-BY-SA-4.0"),
            "files": files, "games": games}
    os.makedirs(OUT, exist_ok=True)
    with open(os.path.join(OUT, "spiele.json"), "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, separators=(",", ":"))
    shutil.copy(os.path.join(ROOT, "tools", "seite", "index.html"), os.path.join(OUT, "index.html"))
    print(f"{OUT}: {len(games)} Spiele, {files} Dateien / games, files")


if __name__ == "__main__":
    main()
