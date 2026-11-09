#!/usr/bin/env python3
"""Holt angenommene Beiträge vom Annahme-Server und übernimmt sie ins Repository (nur Standardbibliothek).

Aufruf (Workflow „Beiträge übernehmen“):
    BEITRAG_URL=https://…/api/export.php BEITRAG_TOKEN=… python3 tools/uebernehmen.py [--push]
Zum Testen ohne Server:
    python3 tools/uebernehmen.py --zip beitrag-7-geprueft.zip --id 7 [--name "…"]

Je Beitrag: ZIP laden, nur games/xx/<sha1>.json und images/xx/<sha1>.<endung> übernehmen (alles andere wird
übergangen), Bilder ohne Spiel entfernen, tools/pruefen.py laufen lassen, committen. Scheitert die Prüfung, wird
der Beitrag zurückgerollt und übersprungen. Mit --push: am Ende pushen und erst danach die Beiträge auf dem Server
als übernommen vermerken.

Pulls accepted contributions from the submission server into the repository; one commit per contribution,
rolled back if tools/pruefen.py fails; with --push, contributions are marked as imported only after the push.
"""
import argparse, hashlib, io, json, os, re, subprocess, sys, urllib.request, zipfile

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
GAME = re.compile(r"^games/([0-9a-f]{2})/([0-9a-f]{40})\.json$")
IMAGE = re.compile(r"^images/([0-9a-f]{2})/([0-9a-f]{40})\.(png|jpg|gif|webp)$")
AGENT = "breadbin-uebernehmen/1"


def git(*args, check=True):
    return subprocess.run(["git", *args], cwd=ROOT, check=check, capture_output=True, text=True).stdout


def anfrage(pfad, methode="GET"):
    url = os.environ["BEITRAG_URL"] + pfad
    req = urllib.request.Request(url, method=methode, headers={
        "X-Breadbin-Token": os.environ["BEITRAG_TOKEN"], "User-Agent": AGENT})
    with urllib.request.urlopen(req, timeout=120) as r:
        return r.read()


def zusammenfassung(text):
    print(text)
    pfad = os.environ.get("GITHUB_STEP_SUMMARY")
    if pfad:
        with open(pfad, "a", encoding="utf-8") as f:
            f.write(text + "\n")


def benutzte_bilder():
    used = set()
    for dirpath, _, names in os.walk(os.path.join(ROOT, "games")):
        for n in names:
            if n.endswith(".json"):
                try:
                    g = json.load(open(os.path.join(dirpath, n), encoding="utf-8"))
                except Exception:  # noqa: BLE001 – meldet pruefen.py
                    continue
                used.update((g.get("images") or {}).values())
    return used


def einspielen(daten):
    """Spielt eine Austauschdatei ein. Gibt (neu, geändert, unverändert, bilder) zurück."""
    z = zipfile.ZipFile(io.BytesIO(daten))
    namen = z.namelist()
    praefix = next((n[: -len("breadbin-share.json")] for n in namen if re.match(r"^([^/]+/)?breadbin-share\.json$", n)),
                   None)
    if praefix is None:
        raise ValueError("breadbin-share.json fehlt / missing")
    manifest = json.loads(z.read(praefix + "breadbin-share.json"))
    if manifest.get("format") != "breadbin-share" or manifest.get("license") != "CC-BY-SA-4.0":
        raise ValueError("kein Breadbin-Austauschformat unter CC BY-SA 4.0 / not a CC BY-SA 4.0 Breadbin exchange file")
    neu = geaendert = gleich = bilder = 0
    for n in namen:
        if not n.startswith(praefix) or n.endswith("/"):
            continue
        rel = n[len(praefix):]
        inhalt = z.read(n)
        if m := GAME.match(rel):
            if m.group(2)[:2] != m.group(1) or json.loads(inhalt).get("sha1") != m.group(2):
                raise ValueError(f"{rel}: Prüfsumme passt nicht zum Pfad / sha1 does not match the path")
        elif m := IMAGE.match(rel):
            if hashlib.sha1(inhalt).hexdigest() != m.group(2) or m.group(2)[:2] != m.group(1):
                raise ValueError(f"{rel}: Dateiname ist nicht die SHA-1 des Inhalts / name is not the content's SHA-1")
        else:
            continue  # breadbin-share.json und alles Fremde bleiben draußen
        ziel = os.path.join(ROOT, *rel.split("/"))
        alt = open(ziel, "rb").read() if os.path.isfile(ziel) else None
        if rel.startswith("images/"):
            bilder += alt is None
        elif alt is None:
            neu += 1
        elif json.loads(alt) == json.loads(inhalt):
            gleich += 1
        else:
            geaendert += 1
        if alt != inhalt:
            os.makedirs(os.path.dirname(ziel), exist_ok=True)
            with open(ziel, "wb") as f:
                f.write(inhalt)
    # Bilder, die nach dem Austausch kein Spiel mehr benutzt, entfernen (pruefen.py verlangt das)
    used = benutzte_bilder()
    for dirpath, _, names in os.walk(os.path.join(ROOT, "images")):
        for n in names:
            rel = os.path.relpath(os.path.join(dirpath, n), ROOT).replace(os.sep, "/")
            if rel not in used:
                os.remove(os.path.join(ROOT, rel))
    return neu, geaendert, gleich, bilder


def zuruecksetzen():
    git("checkout", "--", "games", "images", check=False)
    git("clean", "-fdq", "--", "games", "images", check=False)


def uebernehmen(nr, daten, name=""):
    """Ein Beitrag → ein Commit. Gibt True zurück, wenn etwas committet wurde oder nichts zu tun war."""
    try:
        neu, geaendert, gleich, bilder = einspielen(daten)
    except Exception as e:  # noqa: BLE001
        zuruecksetzen()
        zusammenfassung(f"- ✗ Beitrag {nr}: nicht lesbar / unreadable: {e}")
        return False
    pr = subprocess.run([sys.executable, os.path.join(ROOT, "tools", "pruefen.py"), ROOT],
                        capture_output=True, text=True)
    if pr.returncode != 0:
        zuruecksetzen()
        zusammenfassung(f"- ✗ Beitrag {nr}: Prüfung gescheitert / check failed\n```\n{pr.stdout[-3000:]}\n```")
        return False
    git("add", "-A", "games", "images")
    if not git("status", "--porcelain", "--", "games", "images").strip():
        zusammenfassung(f"- = Beitrag {nr}: schon vollständig im Repository / already in the repository ({gleich} Spiele)")
        return True
    name = re.sub(r"[\r\n]+", " ", name).strip()[:100]
    titel = f"Beitrag {nr} übernommen: {neu} neue, {geaendert} geänderte Spiele, {bilder} neue Bilder"
    text = titel + (f"\n\nEingesandt von / contributed by: {name}" if name else "")
    git("-c", "user.name=Breadbin", "-c", "user.email=breadbin@8bitcavern.de", "commit", "-q", "-m", text)
    zusammenfassung(f"- ✓ {titel}" + (f" ({name})" if name else ""))
    return True


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--push", action="store_true", help="am Ende pushen und auf dem Server vermerken")
    ap.add_argument("--zip", help="lokale Austauschdatei statt Server (Test)")
    ap.add_argument("--id", type=int, default=0)
    ap.add_argument("--name", default="")
    a = ap.parse_args()
    if git("status", "--porcelain", "--", "games", "images").strip():
        sys.exit("games/ oder images/ hat nicht committete Änderungen / uncommitted changes in games/ or images/")

    if a.zip:
        ok = uebernehmen(a.id, open(a.zip, "rb").read(), a.name)
        sys.exit(0 if ok else 1)

    for var in ("BEITRAG_URL", "BEITRAG_TOKEN"):
        if not os.environ.get(var):
            sys.exit(f"{var} fehlt / missing")
    liste = json.loads(anfrage("?liste")).get("beitraege", [])
    if not liste:
        print("Keine neuen angenommenen Beiträge / no new accepted contributions")
        return
    zusammenfassung(f"### Beiträge übernehmen ({len(liste)})")
    start = git("rev-parse", "HEAD").strip()
    erledigt, fehler = [], 0
    for b in liste:
        nr = int(b["id"])
        try:
            daten = anfrage(f"?id={nr}")
        except Exception as e:  # noqa: BLE001
            zusammenfassung(f"- ✗ Beitrag {nr}: Abruf gescheitert / download failed: {e}")
            fehler += 1
            continue
        if uebernehmen(nr, daten, b.get("name") or ""):
            erledigt.append(nr)
        else:
            fehler += 1
    neue_commits = git("rev-parse", "HEAD").strip() != start
    if a.push and erledigt:
        if neue_commits:
            git("push", "-q")
        for nr in erledigt:
            antwort = json.loads(anfrage(f"?uebernommen={nr}", "POST"))
            if not antwort.get("ok"):
                zusammenfassung(f"- ⚠ Beitrag {nr}: auf dem Server nicht vermerkt / not marked on the server")
    out = os.environ.get("GITHUB_OUTPUT")
    if out:
        with open(out, "a") as f:
            f.write(f"geaendert={'1' if neue_commits else '0'}\n")
    sys.exit(1 if fehler else 0)


if __name__ == "__main__":
    main()
