#!/usr/bin/env python3
"""Prüft die Breadbin-Gemeinschaftsdatenbank (nur Python-Standardbibliothek).

Aufruf: python3 tools/pruefen.py [Wurzelordner]
Beendet sich mit 1, wenn etwas nicht stimmt, und nennt jede Stelle.
Checks the Breadbin community database; exits with 1 and lists every problem.
"""
import hashlib, json, os, re, sys

ROOT = sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(__file__), "..")
HEX = re.compile(r"^[0-9a-f]+$")
KINDS = {"sstitle", "ss", "box-2D"}
EXTS = {"png": b"\x89PNG", "jpg": b"\xff\xd8\xff", "gif": b"GIF8", "webp": b"RIFF"}
MAX_IMAGE = 8 * 1024 * 1024
MAX_JSON = 256 * 1024
TEXT_FIELDS = ["title", "year", "publisher", "developer", "players", "synopsis"]

problems = []


def bad(where, what):
    problems.append(f"{where}: {what}")


def is_hex(s, n):
    return isinstance(s, str) and len(s) == n and bool(HEX.match(s))


def check_manifest():
    path = os.path.join(ROOT, "breadbin-share.json")
    try:
        m = json.load(open(path, encoding="utf-8"))
    except Exception as e:  # noqa: BLE001
        bad("breadbin-share.json", f"nicht lesbar / unreadable: {e}")
        return
    if m.get("format") != "breadbin-share":
        bad("breadbin-share.json", "format muss „breadbin-share“ sein / must be breadbin-share")
    if m.get("version") != 1:
        bad("breadbin-share.json", "version muss 1 sein / must be 1")
    if m.get("license") != "CC-BY-SA-4.0":
        bad("breadbin-share.json", "license muss CC-BY-SA-4.0 sein / must be CC-BY-SA-4.0")


def check_games():
    used_images = set()
    games_dir = os.path.join(ROOT, "games")
    count = 0
    for sub in sorted(os.listdir(games_dir)) if os.path.isdir(games_dir) else []:
        subpath = os.path.join(games_dir, sub)
        if not os.path.isdir(subpath) or not is_hex(sub, 2):
            bad(f"games/{sub}", "nur Unterordner mit zwei Hex-Zeichen / only two-hex-digit folders")
            continue
        for name in sorted(os.listdir(subpath)):
            rel = f"games/{sub}/{name}"
            path = os.path.join(subpath, name)
            if not name.endswith(".json"):
                bad(rel, "nur .json-Dateien / only .json files")
                continue
            count += 1
            if os.path.getsize(path) > MAX_JSON:
                bad(rel, "größer als 256 KB / larger than 256 KB")
                continue
            try:
                g = json.load(open(path, encoding="utf-8"))
            except Exception as e:  # noqa: BLE001
                bad(rel, f"kein gültiges JSON / invalid JSON: {e}")
                continue
            sha1 = g.get("sha1")
            if not is_hex(sha1, 40):
                bad(rel, "sha1 fehlt oder ungültig (40 Hex-Zeichen, klein) / sha1 missing or invalid")
                continue
            if rel != f"games/{sha1[:2]}/{sha1}.json":
                bad(rel, f"Pfad passt nicht zur Prüfsumme, erwartet games/{sha1[:2]}/{sha1}.json / path does not match sha1")
            if g.get("version") != 1:
                bad(rel, "version muss 1 sein / must be 1")
            if not is_hex(g.get("md5", ""), 32):
                bad(rel, "md5 fehlt oder ungültig / md5 missing or invalid")
            if not is_hex(g.get("crc32", ""), 8):
                bad(rel, "crc32 fehlt oder ungültig (8 Hex-Zeichen) / crc32 missing or invalid")
            if not isinstance(g.get("size"), int) or g["size"] <= 0:
                bad(rel, "size muss eine positive Zahl sein / size must be a positive number")
            if not isinstance(g.get("title"), str) or not g["title"].strip():
                bad(rel, "title fehlt / title missing")
            for f in TEXT_FIELDS:
                if f in g and not isinstance(g[f], str):
                    bad(rel, f"{f} muss Text sein / must be a string")
            if not isinstance(g.get("genres", []), list) or not all(isinstance(x, str) for x in g.get("genres", [])):
                bad(rel, "genres muss eine Liste von Texten sein / must be a list of strings")
            if not isinstance(g.get("files", []), list):
                bad(rel, "files muss eine Liste sein / must be a list")
            sid = g.get("screenscraper_id", "")
            if sid and not (isinstance(sid, str) and sid.isdigit()):
                bad(rel, "screenscraper_id nur Ziffern / digits only")
            for kind, img in (g.get("images") or {}).items():
                if kind not in KINDS:
                    bad(rel, f"unbekannte Bildart {kind} / unknown picture type")
                m = re.match(r"^images/([0-9a-f]{2})/([0-9a-f]{40})\.(png|jpg|gif|webp)$", img or "")
                if not m or not m.group(2).startswith(m.group(1)):
                    bad(rel, f"ungültiger Bildpfad {img} / invalid picture path")
                    continue
                used_images.add(img)
                if not os.path.isfile(os.path.join(ROOT, img)):
                    bad(rel, f"Bild fehlt {img} / picture missing")
    return count, used_images


def check_images(used):
    images_dir = os.path.join(ROOT, "images")
    for sub in sorted(os.listdir(images_dir)) if os.path.isdir(images_dir) else []:
        if not os.path.isdir(os.path.join(images_dir, sub)) or not is_hex(sub, 2):
            bad(f"images/{sub}", "nur Unterordner mit zwei Hex-Zeichen / only two-hex-digit folders")
            continue
        for name in sorted(os.listdir(os.path.join(images_dir, sub))):
            rel = f"images/{sub}/{name}"
            path = os.path.join(images_dir, sub, name)
            stem, _, ext = name.partition(".")
            if ext not in EXTS:
                bad(rel, "nur png, jpg, gif, webp / only png, jpg, gif, webp")
                continue
            if os.path.getsize(path) > MAX_IMAGE:
                bad(rel, "größer als 8 MB / larger than 8 MB")
                continue
            data = open(path, "rb").read()
            if not data.startswith(EXTS[ext]) or (ext == "webp" and data[8:12] != b"WEBP"):
                bad(rel, "Inhalt passt nicht zur Endung / content does not match the extension")
            if hashlib.sha1(data).hexdigest() != stem or stem[:2] != sub:
                bad(rel, "Dateiname muss die SHA-1 des Inhalts sein / file name must be the SHA-1 of the content")
            if rel not in used:
                bad(rel, "wird von keinem Spiel benutzt / not used by any game")


def main():
    check_manifest()
    count, used = check_games()
    check_images(used)
    allowed = {"breadbin-share.json", "games", "images", "tools", "README.md", "README.de.md", "LICENSE",
               "CONTRIBUTING.md", ".github", ".gitignore", ".git", "dist"}
    for name in os.listdir(ROOT):
        if name not in allowed:
            bad(name, "unerwartete Datei oder Ordner / unexpected file or folder")
    if problems:
        print(f"{len(problems)} Problem(e) / problem(s):")
        for p in problems:
            print("  -", p)
        sys.exit(1)
    print(f"OK: {count} Spiele / games, {len(used)} Bilder / pictures")


if __name__ == "__main__":
    main()
