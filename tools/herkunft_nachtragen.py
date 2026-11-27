#!/usr/bin/env python3
"""Trägt die Herkunft (Feld `history`) für vorhandene Spiele aus der Git-Geschichte nach (einmalig, nur Standardbibliothek).

Aufruf: python3 tools/herkunft_nachtragen.py [--trocken]

Je Spieldatei: der Commit, der sie angelegt hat, ergibt „added“, jeder spätere Commit mit inhaltlicher Änderung
„changed“ (reine Schreibweise wie Zeilenenden zählt nicht). Datum = Commit-Datum. Commits „Beitrag N übernommen“
liefern die Beitragsnummer und den Einsender („Eingesandt von / contributed by: …“, Schreibvarianten über
tools/einsender.json; ohne Namen „anonym“); alle übrigen Commits zählen als „Breadbin“.
Dateien, die schon eine `history` haben, bleiben unverändert.

Back-fills the `history` field of existing games from the git history (one-off).
"""
import json, os, re, subprocess, sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
sys.path.insert(0, os.path.join(ROOT, "tools"))
from uebernehmen import einsender, json_text, ohne_herkunft  # noqa: E402

BEITRAG = re.compile(r"^Beitrag (\d+) übernommen")
NAME = re.compile(r"Eingesandt von / contributed by:\s*(.+)")


def git(*args):
    return subprocess.run(["git", *args], cwd=ROOT, check=True, capture_output=True, text=True).stdout


def inhalt(commit, pfad):
    try:
        return ohne_herkunft(json.loads(git("show", f"{commit}:{pfad}")))
    except (subprocess.CalledProcessError, ValueError):
        return None


def main():
    trocken = "--trocken" in sys.argv
    log = git("log", "--reverse", "--date=short", "--format=%x1e%H%x1f%ad%x1f%s%x1f%b%x1f", "--name-status",
              "--", "games")
    verlauf = {}  # pfad -> [eintrag]
    for block in log.split("\x1e")[1:]:
        h, datum, betreff, rumpf, rest = block.split("\x1f", 4)
        m = BEITRAG.match(betreff)
        n = NAME.search(rumpf)
        wer = einsender(n.group(1)) if n else ("anonym" if m else "Breadbin")
        if m and not n and betreff.startswith("Beitrag 3 übernommen: 80"):
            wer = "Breadbin"  # von Hand übernommen (2026-11-06), Einsendung des Betreibers
        for zeile in rest.strip().splitlines():
            teile = zeile.split("\t")
            if len(teile) < 2 or not teile[-1].startswith("games/"):
                continue
            status, pfad = teile[0][0], teile[-1]
            if status == "D":
                verlauf.pop(pfad, None)
                continue
            eintrag = {"date": datum, "by": wer}
            if m:
                eintrag["contribution"] = int(m.group(1))
            if pfad not in verlauf:
                verlauf[pfad] = [{**eintrag, "action": "added"}]
            elif status == "M" and inhalt(h, pfad) != inhalt(h + "^", pfad):
                verlauf[pfad].append({**eintrag, "action": "changed"})
    geschrieben = 0
    for pfad, hist in sorted(verlauf.items()):
        datei = os.path.join(ROOT, pfad)
        if not os.path.isfile(datei):
            continue
        g = json.load(open(datei, encoding="utf-8"))
        if g.get("history"):
            continue
        g["history"] = hist
        geschrieben += 1
        if not trocken:
            with open(datei, "wb") as f:
                f.write(json_text(g))
    wer = {}
    for hist in verlauf.values():
        for e in hist:
            wer[e["by"]] = wer.get(e["by"], 0) + 1
    print(f"{geschrieben} Spiele mit Herkunft {'(trocken)' if trocken else 'geschrieben'}; Einträge je Name: {wer}")


if __name__ == "__main__":
    main()
