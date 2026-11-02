#!/usr/bin/env python3
"""Baut aus dem Repository die Austauschdatei dist/breadbin-db.zip, die Breadbin einliest.

Builds dist/breadbin-db.zip (the exchange file Breadbin reads) from the repository.
"""
import json, os, sys, time, zipfile

ROOT = os.path.abspath(sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(__file__), ".."))
OUT = os.path.join(ROOT, "dist", "breadbin-db.zip")


def main():
    games = []
    for base in ("games", "images"):
        for dirpath, _, files in os.walk(os.path.join(ROOT, base)):
            for f in files:
                games.append(os.path.relpath(os.path.join(dirpath, f), ROOT).replace(os.sep, "/"))
    games.sort()
    count = sum(1 for p in games if p.startswith("games/") and p.endswith(".json"))
    manifest = json.load(open(os.path.join(ROOT, "breadbin-share.json"), encoding="utf-8"))
    manifest.update({"created": int(time.time()), "app": "breadbin-db", "games": count})
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with zipfile.ZipFile(OUT, "w") as z:
        z.writestr("breadbin-share.json", json.dumps(manifest, indent=2, ensure_ascii=False), zipfile.ZIP_DEFLATED)
        for p in games:
            method = zipfile.ZIP_STORED if p.startswith("images/") else zipfile.ZIP_DEFLATED
            z.write(os.path.join(ROOT, p), p, compress_type=method)
    print(f"{OUT}: {count} Spiele / games")


if __name__ == "__main__":
    main()
