#!/usr/bin/env python3
"""Baut aus Durchsicht-Ordnern (tools/durchsicht.py holen) eine Berichtsseite für das Artefakt „Beitragsdurchsicht“.

    python3 tools/durchsicht_bericht.py AUSGABE.html [--hinweis TEXT] ORDNER [ORDNER …]

Jeder Ordner enthält beitrag.json (von „holen“) und empfehlung.json (von Claude geschrieben, gleiches Format wie für
„senden“: {zusammenfassung, spiele: {sha1: {empfehlung, grund}}}). Titelbilder werden klein eingebettet.
Builds the phone-friendly report page from review folders.
"""
import base64, html, json, os, sys

PRUEFSEITE = os.environ.get("BREADBIN_PRUEFSEITE_URL", "https://8bitcavern.de/breadbin/db/breadbin/annahme/public/admin/")
EMPF = {"uebernehmen": ("übernehmen", "ok"), "nachbessern": ("nachbessern", "warn"), "ablehnen": ("ablehnen", "bad")}
MAX_BILDER = 80

CSS = """
<title>Beitragsdurchsicht</title>
<meta name="claude:viewport-fit-sides" content="cover">
<style>
/* Layout: eine Spalte, je Beitrag ein Block, je Spiel eine Zeile mit Empfehlung vorn – wie die Prüfseite */
:root { --bg: #f6f5f2; --panel: #ffffff; --fg: #1d1c22; --muted: #6b6977; --line: #e2e0e8; --accent: #4b3fa0;
  --ok: #2c7a4c; --warn: #9a6a00; --bad: #b3261e;
  --sans: "IBM Plex Sans", system-ui, sans-serif; --mono: "IBM Plex Mono", ui-monospace, monospace; }
@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { --bg: #17161c; --panel: #201f27; --fg: #e8e6ef;
  --muted: #9a97a8; --line: #34323e; --accent: #8b7ff0; --ok: #72cc97; --warn: #e8b84a; --bad: #ff8a80; color-scheme: dark } }
:root[data-theme="dark"] { --bg: #17161c; --panel: #201f27; --fg: #e8e6ef; --muted: #9a97a8; --line: #34323e;
  --accent: #8b7ff0; --ok: #72cc97; --warn: #e8b84a; --bad: #ff8a80; color-scheme: dark }
body { background: var(--bg); color: var(--fg); font: 15px/1.5 var(--sans); padding-block: 20px 40px;
  padding-left: max(16px, env(safe-area-inset-left, 0px)); padding-right: max(16px, env(safe-area-inset-right, 0px)); }
main { max-width: 860px; margin: 0 auto; display: grid; gap: 20px; }
h1 { font-size: 24px; margin: 0; text-wrap: balance; } h2 { font-size: 18px; margin: 0; }
.meta { color: var(--muted); font-size: 13px; }
.beitrag { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 14px; display: grid; gap: 10px; min-width: 0; }
.zahlen { display: flex; flex-wrap: wrap; gap: 6px; }
.pill { font-size: 12px; font-weight: 600; padding: 2px 9px; border-radius: 10px; white-space: nowrap; }
.pill.ok { color: var(--ok); background: color-mix(in srgb, var(--ok) 15%, transparent); }
.pill.warn { color: var(--warn); background: color-mix(in srgb, var(--warn) 17%, transparent); }
.pill.bad { color: var(--bad); background: color-mix(in srgb, var(--bad) 15%, transparent); }
.spiel { display: grid; grid-template-columns: 96px 1fr; gap: 10px; border-top: 1px solid var(--line); padding-top: 10px; }
.spiel img, .spiel .leer { width: 96px; aspect-ratio: 8 / 5; object-fit: cover; image-rendering: pixelated; border-radius: 4px;
  border: 1px solid var(--line); background: var(--bg); max-width: 100%; }
.spiel > div { min-width: 0; display: grid; gap: 3px; }
.kopf { display: flex; flex-wrap: wrap; gap: 6px; align-items: baseline; }
.datei { font: 12px var(--mono); color: var(--muted); overflow-wrap: anywhere; }
ul { margin: 0; padding-left: 18px; font-size: 13px; } li.rot { color: var(--bad); } li.gelb { color: var(--warn); }
a { color: var(--accent); }
@media (max-width: 480px) { .spiel { grid-template-columns: 64px 1fr; } .spiel img, .spiel .leer { width: 64px; } }
</style>
"""


def e(s):
    return html.escape(str(s or ""))


def bild(g):
    for art in ("sstitle", "ss", "box-2D"):
        b = (g.get("bilder") or {}).get(art)
        if b and b.get("datei") and os.path.isfile(b["datei"]) and os.path.getsize(b["datei"]) < 200_000:
            typ = {"png": "png", "jpg": "jpeg", "gif": "gif", "webp": "webp"}[b["datei"].rsplit(".", 1)[1]]
            return f'<img src="data:image/{typ};base64,{base64.b64encode(open(b["datei"], "rb").read()).decode()}" alt="">'
    return '<span class="leer"></span>'


def main():
    aus, ordner = sys.argv[1], sys.argv[2:]
    hinweis = ""
    if ordner[:1] == ["--hinweis"]:
        hinweis, ordner = ordner[1], ordner[2:]
    teile, bilder, sichten = [], 0, []
    gesamt = {"uebernehmen": 0, "nachbessern": 0, "ablehnen": 0}
    for o in ordner:
        b = json.load(open(os.path.join(o, "beitrag.json"), encoding="utf-8"))
        em = json.load(open(os.path.join(o, "empfehlung.json"), encoding="utf-8"))
        zahl = {"uebernehmen": 0, "nachbessern": 0, "ablehnen": 0}
        zeilen = []
        reihenfolge = {"ablehnen": 0, "nachbessern": 1, "uebernehmen": 2}
        spiele = sorted(b["spiele"], key=lambda g: (reihenfolge.get(em["spiele"].get(g["sha1"], {}).get("empfehlung"), 3),
                                                    g["title"].lower()))
        for g in spiele:
            x = em["spiele"].get(g["sha1"], {})
            k = x.get("empfehlung", "")
            if k in zahl:
                zahl[k] += 1
            name, klasse = EMPF.get(k, ("ohne Empfehlung", "warn"))
            ct = g.get("claude_texte") or {}
            sprachen = ", ".join(sp.upper() for sp in ("de", "en") if ct.get(sp))
            if any(ct.get(sp) == "neu" for sp in ("de", "en")):
                texthinweis = f'<div class="pill warn" style="justify-self:start">Texte von Claude geschrieben ({sprachen}) – bitte auf der Prüfseite sichten</div>'
                sichten.append(g["title"])
            elif sprachen:
                texthinweis = f'<div class="pill ok" style="justify-self:start">{sprachen} von Claude ergänzt</div>'
            elif g.get("textauftrag"):
                texthinweis = '<div class="pill warn" style="justify-self:start">Textauftrag offen</div>'
            else:
                texthinweis = ""
            hinweise = "".join(f'<li class="{e(h["stufe"])}">{e(h["text"])}</li>' for h in g["hinweise"] if h["stufe"] != "info")
            bestand = (g.get("bestand") or {}).get("art", "")
            img = bild(g) if bilder < MAX_BILDER else '<span class="leer"></span>'
            bilder += 1
            zeilen.append(f"""<div class="spiel" id="s{e(g['sha1'])}">{img}<div>
  <div class="kopf"><span class="pill {klasse}">{e(name)}</span><strong>{e(g['title'])}</strong>
    <span class="meta">{e(' · '.join(filter(None, [g.get('year'), g.get('publisher'), ', '.join(g.get('genres') or []),
                                                  {'neu': 'neu', 'geaendert': 'ändert Bestand', 'gleich': 'unverändert'}.get(bestand, '')])))}</span></div>
  <div class="datei">{e(', '.join(g.get('files') or []))}</div>
  {texthinweis}
  {f'<div>{e(x.get("grund"))}</div>' if x.get('grund') else ''}
  {f'<ul>{hinweise}</ul>' if hinweise else ''}
</div></div>""")
        for k in gesamt:
            gesamt[k] += zahl[k]
        von = f" · von {e(b['name'])}" if b.get("name") else ""
        teile.append(f"""<section class="beitrag"><h2>Beitrag #{b['id']}</h2>
<div class="meta">{e(b['eingang'])} · {len(b['spiele'])} Spiele{von} · <a href="{e(PRUEFSEITE)}" target="_blank" rel="noopener">Prüfseite öffnen</a></div>
<div class="zahlen"><span class="pill ok">{zahl['uebernehmen']} übernehmen</span><span class="pill warn">{zahl['nachbessern']} nachbessern</span>
<span class="pill bad">{zahl['ablehnen']} ablehnen</span></div>
{f'<div>{e(em.get("zusammenfassung"))}</div>' if em.get('zusammenfassung') else ''}
{''.join(f'<div class="meta" style="color:var(--bad)">{e(h["text"])}</div>' for h in b.get('angaben') or [])}
{'' if b.get('screenscraper_abgleich') else '<div class="meta">ScreenScraper-Abgleich fehlte (keine eigene Bibliothek gefunden).</div>'}
{''.join(zeilen)}</section>""")
    stand = max((json.load(open(os.path.join(o, "beitrag.json"))).get("eingang", "") for o in ordner), default="")
    seite = CSS + f"""<main>
<header><h1>Beitragsdurchsicht</h1>
<p class="meta">Empfehlungen von Claude zu Beiträgen, die auf der Prüfseite warten (letzter Eingang {e(stand)}).
Entschieden wird auf der Prüfseite; dort stehen dieselben Empfehlungen an jedem Spiel.</p>
{f'<p class="pill warn" style="display:inline-block;white-space:normal">{e(hinweis)}</p>' if hinweis else ''}
<div class="zahlen"><span class="pill ok">{gesamt['uebernehmen']} übernehmen</span><span class="pill warn">{gesamt['nachbessern']} nachbessern</span>
<span class="pill bad">{gesamt['ablehnen']} ablehnen</span></div>
{f'<p><strong>Zur Sichtung:</strong> neue Texte von Claude bei {e(", ".join(sichten))}.</p>' if sichten else ''}</header>
{''.join(teile) if teile else '<p>Zurzeit wartet kein Beitrag.</p>'}
</main>
"""
    open(aus, "w", encoding="utf-8").write(seite)
    print(f"{aus}: {len(ordner)} Beiträge")


if __name__ == "__main__":
    main()
