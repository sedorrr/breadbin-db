<?php
// Durchsicht neuer Beiträge durch Claude (tools/durchsicht.py auf dem Rechner des Betreibers).
// Schutz: Kopfzeile „X-Breadbin-Token: <Schlüssel>“; in config.php steht nur die SHA-256 ('durchsicht_token_sha256').
// Eigener Schlüssel, getrennt vom Export: er kann nur lesen und Empfehlungen ablegen, nichts annehmen oder übernehmen.
// Ohne Eintrag ist die Adresse abgeschaltet (404).
//   GET  ?offen            → neue Beiträge mit Stand der Durchsicht (JSON, eine kleine Antwort – für die Abfrage alle 30 min)
//   GET  ?id=N             → Beitrag N: Spiele (bearbeitete Fassung), Hinweise der Vorprüfung, Vergleich mit dem Bestand
//   GET  ?id=N&bild=PFAD   → ein Bild aus Beitrag N
//   POST ?durchsicht=N     → Empfehlungen ablegen (JSON: {zusammenfassung, bericht, spiele: {sha1: {empfehlung, grund}}})
//   POST ?texte=N          → von Claude geschriebene Texte als Bearbeitung ablegen (JSON: {spiele: {sha1: {de, en}}});
//                            nur fehlende Sprachen werden gefüllt, Spiel bekommt „bearbeitet von Breadbin“
// Review endpoint for new contributions; separate read-only token that can only store recommendations.
declare(strict_types=1);
require __DIR__ . '/../../lib/beitrag.php';
require __DIR__ . '/../../lib/vorpruefung.php';

$c = konfig();
$soll = strtolower(trim((string)($c['durchsicht_token_sha256'] ?? '')));
if (!preg_match('/^[0-9a-f]{64}$/', $soll)) {
    json_antwort(404, ['ok' => false, 'fehler' => 'nicht eingerichtet / not configured']);
}
$schluessel = trim((string)($_SERVER['HTTP_X_BREADBIN_TOKEN'] ?? ''));
if (strlen($schluessel) < 32 || !hash_equals($soll, hash('sha256', $schluessel))) {
    sleep(2);
    json_antwort(403, ['ok' => false, 'fehler' => 'Schlüssel falsch / wrong token']);
}

/** Neuer Beitrag mit vorhandener Datei, sonst 404. */
function neuer_beitrag(array $c, int $id): array
{
    $b = beitrag($id);
    if (!$b || $b['status'] !== 'neu' || !is_file($c['daten_dir'] . '/' . $b['datei'])) {
        json_antwort(404, ['ok' => false, 'fehler' => 'kein offener Beitrag / no open contribution']);
    }
    return $b;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['durchsicht'])) {
    $b = neuer_beitrag($c, (int)$_GET['durchsicht']);
    $roh = file_get_contents('php://input', false, null, 0, 1048576);
    $daten = json_decode((string)$roh, true);
    if (!is_array($daten)) {
        json_antwort(400, ['ok' => false, 'fehler' => 'kein JSON / not JSON']);
    }
    $erg = pruefe_beitrag($c['daten_dir'] . '/' . $b['datei']);
    [$ds, $fehler] = durchsicht_bereinigen($daten, array_map('strval', array_keys($erg['spiele'])));
    if ($ds === null) {
        json_antwort(422, ['ok' => false, 'fehler' => $fehler]);
    }
    file_put_contents(durchsicht_pfad($c, $b), json_encode($ds, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    json_antwort(200, ['ok' => true, 'id' => (int)$b['id'], 'spiele' => count($ds['spiele'])]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['texte'])) {
    $b = neuer_beitrag($c, (int)$_GET['texte']);
    $daten = json_decode((string)file_get_contents('php://input', false, null, 0, 1048576), true);
    if (!is_array($daten) || !is_array($daten['spiele'] ?? null)) {
        json_antwort(400, ['ok' => false, 'fehler' => 'JSON {spiele: {sha1: {de, en}}} erwartet']);
    }
    [$n, $weg] = claude_texte_ablegen($c, $b, pruefe_beitrag($c['daten_dir'] . '/' . $b['datei']), $daten['spiele']);
    json_antwort($n ? 200 : 422, ['ok' => $n > 0, 'id' => (int)$b['id'], 'texte' => $n, 'uebersprungen' => $weg]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_antwort(405, ['ok' => false, 'fehler' => 'nur GET oder POST ?durchsicht / GET or POST ?durchsicht only']);
}

if (isset($_GET['offen'])) {
    $zeilen = db()->query("SELECT id, eingang, spiele, datei FROM beitraege WHERE status = 'neu' ORDER BY id")->fetchAll();
    $aus = [];
    foreach ($zeilen as $z) {
        if (!is_file($c['daten_dir'] . '/' . $z['datei'])) {
            continue;
        }
        $ds = durchsicht($c, $z);
        $aus[] = ['id' => (int)$z['id'], 'eingang' => $z['eingang'], 'spiele' => (int)$z['spiele'],
            'durchgesehen' => $ds !== null, 'durchsicht_zeit' => $ds['zeit'] ?? null,
            'textauftraege' => count(auftraege($c, $z))];
    }
    json_antwort(200, ['ok' => true, 'beitraege' => $aus,
        'warten' => count(array_filter($aus, fn($x) => !$x['durchgesehen'] || $x['textauftraege'] > 0))]);
}

if (isset($_GET['id'])) {
    $b = neuer_beitrag($c, (int)$_GET['id']);
    $pfad = $c['daten_dir'] . '/' . $b['datei'];
    $erg = pruefe_beitrag($pfad);
    if (isset($_GET['bild'])) {
        $bild = (string)$_GET['bild'];
        if (!in_array($bild, $erg['bilder'], true) || !preg_match('#\.(png|jpg|gif|webp)$#', $bild, $m)) {
            json_antwort(404, ['ok' => false, 'fehler' => 'Bild nicht im Beitrag / picture not in contribution']);
        }
        $zip = new ZipArchive();
        $zip->open($pfad, ZipArchive::RDONLY);
        $daten = $zip->getFromName(($erg['praefix'] ?? '') . $bild);
        $zip->close();
        header('Content-Type: ' . ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'][$m[1]]);
        echo (string)$daten;
        exit;
    }
    $ed = edits($c, $b);
    $stand = stand_spiele($c);
    $vp = vorpruefe_beitrag($c, $b, $erg, $ed, $stand);
    $bildInfo = vp_bilder_beitrag($c, $b, $erg);
    $spiele = [];
    foreach ($erg['spiele'] as $sha1 => $orig) {
        $g = mit_edits($orig, $ed[$sha1] ?? null);
        [$de, $en] = texte($g);
        $vg = $stand === null ? null : vergleiche($g, $stand[$sha1] ?? null);
        $spiele[] = [
            'sha1' => (string)$sha1, 'title' => $g['title'] ?? '', 'files' => $g['files'] ?? [], 'format' => $g['format'] ?? '',
            'size' => $g['size'] ?? null, 'year' => $g['year'] ?? '', 'publisher' => $g['publisher'] ?? '',
            'developer' => $g['developer'] ?? '', 'genres' => $g['genres'] ?? [], 'players' => $g['players'] ?? '',
            'screenscraper_id' => $g['screenscraper_id'] ?? '', 'text_de' => $de, 'text_en' => $en,
            'art' => vp_art_von($g),
            // weitere Felder (z. B. Gruppe, Party, Platz bei Nicht-Spielen), wie eingesandt
            'zusatz' => array_diff_key($g, array_flip(['version', 'sha1', 'md5', 'crc32', 'size', 'format', 'files', 'title',
                'year', 'publisher', 'developer', 'genres', 'players', 'synopsis', 'texts', 'images', 'more_images',
                'screenscraper_id', 'history', EDITED_BY_FELD, ART_FELD])),
            'bilder' => array_map(fn($p) => ['pfad' => $p] + ($bildInfo[$p] ?? []), bb_alle_bilder($g)),
            'bearbeitet' => isset($ed[$sha1]),
            'bearbeitet_von' => $ed[$sha1]['bearbeitet_von'] ?? [],
            'claude_texte' => $ed[$sha1]['claude_texte'] ?? null,
            'textauftrag' => in_array((string)$sha1, auftraege($c, $b), true),
            'bestand' => $vg === null ? null : ['art' => $vg['art'], 'felder' => $vg['felder'], 'bilder' => array_keys($vg['bilder'])],
            'hinweise' => $vp['spiele'][$sha1] ?? [],
        ];
    }
    json_antwort(200, ['ok' => true, 'id' => (int)$b['id'], 'eingang' => $b['eingang'], 'name' => $b['name'],
        'hinweis' => $b['hinweis'], 'angaben' => $vp['angaben'], 'zahl' => $vp['zahl'], 'spiele' => $spiele,
        'durchsicht' => durchsicht($c, $b)]);
}

json_antwort(400, ['ok' => false, 'fehler' => '?offen, ?id=N oder POST ?durchsicht=N']);
