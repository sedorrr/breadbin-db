<?php
// Beiträge lesen, Auswahl und Bearbeitungen der Prüfenden anwenden, geprüfte ZIP bauen (Prüfseite und Export).
// Contribution helpers shared by the review page and the export endpoint.
declare(strict_types=1);
require_once __DIR__ . '/gemeinsam.php';
require_once __DIR__ . '/pruefen.php';
require_once __DIR__ . '/vergleich.php';

function beitrag(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM beitraege WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

// Teilweise angenommen: Liste der übernommenen Spiele (SHA-1) neben der ZIP-Datei, sonst null (= alle).
function auswahl_pfad(array $c, array $b): string
{
    return $c['daten_dir'] . '/' . $b['datei'] . '.auswahl.json';
}

function auswahl(array $c, array $b): ?array
{
    $p = auswahl_pfad($c, $b);
    if (!is_file($p)) {
        return null;
    }
    $a = json_decode((string)file_get_contents($p), true);
    return is_array($a) ? $a : null;
}

// Feld in der geprüften ZIP mit den Bearbeitenden eines Spiels (immer ["Breadbin"], tools/uebernehmen.py → history).
const EDITED_BY_FELD = 'edited_by';

// Bearbeitungen der Prüfenden neben der ZIP-Datei: [sha1 => Felder]. Die eingegangene Datei bleibt unverändert.
const EDIT_FELDER = ['title' => 200, 'year' => 20, 'publisher' => 200, 'developer' => 200, 'players' => 50, 'synopsis' => 20000,
    'synopsis_en' => 20000, 'group' => 200, 'party' => 200];
// Art (Feld kind, leer/„game“ = Spiel) und Platzierung (1–999) – wie die App, docs/Austauschformat.md
const EDIT_ARTEN = ['game', 'demo', 'intro', 'music', 'diskmag', 'tool', 'graphics'];

function edits_pfad(array $c, array $b): string
{
    return $c['daten_dir'] . '/' . $b['datei'] . '.edits.json';
}

function edits(array $c, array $b): array
{
    $p = edits_pfad($c, $b);
    $e = is_file($p) ? json_decode((string)file_get_contents($p), true) : null;
    return is_array($e) ? $e : [];
}

// Spiel mit den Bearbeitungen: leere Felder fallen weg, abgewählte Bilder ebenso.
function mit_edits(array $g, ?array $e): array
{
    if (!$e) {
        return $g;
    }
    // Beschreibungen: „synopsis“ = Deutsch, „synopsis_en“ = Englisch; zurück ins Format über setze_texte().
    if (array_key_exists('synopsis', $e) || array_key_exists('synopsis_en', $e)) {
        [$de, $en] = texte($g);
        $g = setze_texte($g, array_key_exists('synopsis', $e) ? (string)$e['synopsis'] : $de,
            array_key_exists('synopsis_en', $e) ? (string)$e['synopsis_en'] : $en);
    }
    foreach (array_diff(array_keys(EDIT_FELDER), ['synopsis', 'synopsis_en']) as $f) {
        if (array_key_exists($f, $e)) {
            if ($e[$f] === '') {
                unset($g[$f]);
            } else {
                $g[$f] = $e[$f];
            }
        }
    }
    if (array_key_exists('kind', $e)) {
        if ($e['kind'] === '' || $e['kind'] === 'game') {
            unset($g['kind']);
        } else {
            $g['kind'] = (string)$e['kind'];
        }
    }
    if (array_key_exists('place', $e)) {
        if ($e['place'] === '' || $e['place'] === null) {
            unset($g['place']);
        } else {
            $g['place'] = (int)$e['place'];
        }
    }
    if (array_key_exists('genres', $e)) {
        if ($e['genres']) {
            $g['genres'] = $e['genres'];
        } else {
            unset($g['genres']);
        }
    }
    foreach (($e['drop_images'] ?? []) as $art) {
        unset($g['images'][$art], $g['more_images'][$art]);
    }
    foreach (['images', 'more_images'] as $feld) {
        if (isset($g[$feld]) && !$g[$feld]) {
            unset($g[$feld]);
        }
    }
    return $g;
}

// ZIP nur mit den gewählten Spielen (null = alle), in der bearbeiteten Fassung, mit den noch benutzten Bildern
// (Kopf bleibt). Gibt den Pfad einer Temp-Datei zurück.
function zip_auswahl(string $quelle, array $erg, ?array $sha1s, array $edits = []): string
{
    $praefix = $erg['praefix'] ?? '';
    $sha1s ??= array_keys($erg['spiele']);
    $bilder = [];
    foreach ($sha1s as $sha1) {
        $g = mit_edits($erg['spiele'][$sha1] ?? [], $edits[$sha1] ?? null);
        foreach (bb_alle_bilder($g) as $bild) {
            $bilder[$bild] = true;
        }
    }
    $ziel = tempnam(sys_get_temp_dir(), 'bb');
    $ein = new ZipArchive();
    $ein->open($quelle, ZipArchive::RDONLY);
    $aus = new ZipArchive();
    $aus->open($ziel, ZipArchive::OVERWRITE);
    for ($i = 0; $i < $ein->numFiles; $i++) {
        $name = (string)$ein->getNameIndex($i);
        if (str_ends_with($name, '/') || ($praefix !== '' && !str_starts_with($name, $praefix))) {
            continue;
        }
        $rel = substr($name, strlen($praefix));
        $t = [];
        $nimm = $rel === 'breadbin-share.json'
            || (preg_match('#^games/[0-9a-f]{2}/([0-9a-f]{40})\.json$#', $rel, $t) && in_array($t[1], $sha1s, true))
            || isset($bilder[$rel]);
        if ($nimm) {
            $daten = (string)$ein->getFromIndex($i);
            if (!empty($t[1]) && str_starts_with($rel, 'games/')) {
                // Vom Einsender mitgeschickte Herkunft und „bearbeitet von“ immer entfernen; edited_by setzt nur die
                // Prüfseite (Bearbeitungen des Betreibers und von Claude, in den Daten immer „Breadbin“).
                $g = mit_edits((array)json_decode($daten, true), $edits[$t[1]] ?? null);
                unset($g['history'], $g[EDITED_BY_FELD]);
                if (!empty($edits[$t[1]]['bearbeitet_von'])) {
                    $g[EDITED_BY_FELD] = array_values(array_unique(array_map('strval', (array)$edits[$t[1]]['bearbeitet_von'])));
                }
                $daten = json_encode($g, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            }
            $aus->addFromString($rel, $daten);
        }
    }
    $aus->close();
    $ein->close();
    return $ziel;
}

/** Spalte „uebernommen“ (ins Daten-Repository übernommen) bei Bedarf anlegen – ältere Datenbanken haben sie nicht. */
function sorge_fuer_uebernommen(): void
{
    static $fertig = false;
    if ($fertig) {
        return;
    }
    $da = db()->query("SHOW COLUMNS FROM beitraege LIKE 'uebernommen'")->fetch();
    if (!$da) {
        db()->exec('ALTER TABLE beitraege ADD COLUMN uebernommen DATETIME NULL');
    }
    $fertig = true;
}

// Aufräumen: Dateien erledigter Beiträge (abgelehnt oder ins Daten-Repository übernommen) werden nicht mehr gebraucht.
// Die Zeile in der Datenbank bleibt (Statistik, Liste); die Prüfseite zeigt dann „Datei gelöscht“.
function beitrag_dateien(array $c, array $b): array
{
    $basis = $c['daten_dir'] . '/' . basename((string)$b['datei']);
    return array_values(array_filter([$basis, $basis . '.auswahl.json', $basis . '.edits.json', $basis . '.bilder.json',
        $basis . '.claude.json', $basis . '.auftraege.json'], 'is_file'));
}

/** Erledigte Beiträge, deren Dateien noch auf dem Webspace liegen: [Anzahl, Bytes, Beiträge]. */
function erledigte_mit_dateien(array $c): array
{
    sorge_fuer_uebernommen();
    $rows = db()->query("SELECT * FROM beitraege WHERE status = 'abgelehnt'
        OR (status = 'angenommen' AND uebernommen IS NOT NULL)")->fetchAll();
    $treffer = [];
    $bytes = 0;
    foreach ($rows as $b) {
        $dateien = beitrag_dateien($c, $b);
        if ($dateien) {
            $treffer[] = $b;
            foreach ($dateien as $d) {
                $bytes += (int)filesize($d);
            }
        }
    }
    return [count($treffer), $bytes, $treffer];
}

/** Löscht die Dateien aller erledigten Beiträge. [aufgeräumte Beiträge, freigegebene Bytes, Beiträge mit Fehler] */
function erledigte_aufraeumen(array $c): array
{
    [, , $liste] = erledigte_mit_dateien($c);
    $n = 0;
    $bytes = 0;
    $fehler = 0;
    foreach ($liste as $b) {
        $ok = true;
        foreach (beitrag_dateien($c, $b) as $d) {
            $groesse = (int)filesize($d);
            if (@unlink($d)) {
                $bytes += $groesse;
            } else {
                $ok = false;
            }
        }
        $ok ? $n++ : $fehler++;
    }
    return [$n, $bytes, $fehler];
}

/**
 * Geprüfte Fassung eines Beitrags als Datei: immer neu gebaut (Auswahl, Bearbeitungen, mitgeschickte `history`/
 * `edited_by` entfernt). [Pfad, temporär?]
 */
function geprueftes_zip(array $c, array $b): array
{
    $quelle = $c['daten_dir'] . '/' . $b['datei'];
    return [zip_auswahl($quelle, pruefe_beitrag($quelle), auswahl($c, $b), edits($c, $b)), true];
}

