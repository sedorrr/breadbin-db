<?php
// Vergleich eingereichter Spiele mit dem veröffentlichten Stand der Gemeinschaftsdatenbank (breadbin-db.zip).
// Compares submitted games with the published state of the community database.
declare(strict_types=1);

// Felder, die verglichen werden (Dateinamen und Prüfsummen außer SHA-1 nicht: sie beschreiben dieselbe Datei).
const VG_FELDER = [
    'title' => 'Titel', 'year' => 'Jahr', 'publisher' => 'Herausgeber', 'developer' => 'Entwickler',
    'genres' => 'Genres', 'players' => 'Spieler', 'synopsis' => 'Beschreibung', 'screenscraper_id' => 'ScreenScraper-Nr.',
];
const VG_BILDARTEN = ['sstitle' => 'Titelbild', 'ss' => 'Spielszene', 'box-2D' => 'Verpackung'];

/** Pfad des Stands: `stand_zip` aus config.php, sonst der Spiegel neben dem Annahme-Ordner. */
function stand_pfad(array $c): string
{
    // Aufbau auf dem Webspace: <web>/breadbin/annahme/{lib,public,daten} und <web>/breadbindb/breadbin-db.zip
    return (string)($c['stand_zip'] ?? (__DIR__ . '/../../../breadbindb/breadbin-db.zip'));
}

/**
 * Spiele des Stands: [sha1 => Spiel-Array] oder null, wenn der Stand nicht lesbar ist.
 * Zwischengespeichert in daten/stand-cache.json, solange sich das ZIP nicht ändert.
 */
function stand_spiele(array $c): ?array
{
    static $spiele = false;
    if ($spiele !== false) {
        return $spiele;
    }
    $pfad = stand_pfad($c);
    if (!is_file($pfad)) {
        return $spiele = null;
    }
    $schluessel = filemtime($pfad) . '-' . filesize($pfad);
    $cache = $c['daten_dir'] . '/stand-cache.json';
    if (is_file($cache)) {
        $d = json_decode((string)file_get_contents($cache), true);
        if (is_array($d) && ($d['schluessel'] ?? '') === $schluessel && is_array($d['spiele'] ?? null)) {
            return $spiele = $d['spiele'];
        }
    }
    $zip = new ZipArchive();
    if ($zip->open($pfad, ZipArchive::RDONLY) !== true) {
        return $spiele = null;
    }
    $spiele = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if (preg_match('#(?:^|/)games/[0-9a-f]{2}/([0-9a-f]{40})\.json$#', $name, $t)) {
            $g = json_decode((string)$zip->getFromIndex($i), true);
            if (is_array($g)) {
                $spiele[$t[1]] = $g;
            }
        }
    }
    $zip->close();
    @file_put_contents($cache, json_encode(['schluessel' => $schluessel, 'spiele' => $spiele], JSON_UNESCAPED_UNICODE));
    return $spiele;
}

/** Wert eines Feldes zum Vergleichen und Anzeigen (Genres als Text, fehlend = ''). */
function vg_wert(array $g, string $feld): string
{
    $v = $g[$feld] ?? '';
    if (is_array($v)) {
        $v = implode(', ', $v);
    }
    return trim(str_replace("\r", '', (string)$v));
}

/**
 * Vergleicht ein eingereichtes Spiel mit dem Stand.
 * Rückgabe: ['art' => 'neu'|'gleich'|'geaendert', 'felder' => [feld => [alt, neu]], 'bilder' => [art => [alt, neu]]]
 * Bilder heißen nach ihrem Inhalt (Prüfsumme) – gleicher Pfad = gleiches Bild.
 */
function vergleiche(array $neu, ?array $alt): array
{
    if ($alt === null) {
        return ['art' => 'neu', 'felder' => [], 'bilder' => []];
    }
    $felder = [];
    foreach (array_keys(VG_FELDER) as $f) {
        $a = vg_wert($alt, $f);
        $n = vg_wert($neu, $f);
        if ($a !== $n) {
            $felder[$f] = [$a, $n];
        }
    }
    $bilder = [];
    foreach (array_keys(VG_BILDARTEN) as $art) {
        $a = (string)($alt['images'][$art] ?? '');
        $n = (string)($neu['images'][$art] ?? '');
        if ($a !== $n) {
            $bilder[$art] = [$a, $n];
        }
    }
    return ['art' => ($felder || $bilder) ? 'geaendert' : 'gleich', 'felder' => $felder, 'bilder' => $bilder];
}

/** Bild aus dem Stand lesen (für die Gegenüberstellung alt/neu); null, wenn nicht vorhanden. */
function stand_bild(array $c, string $bild): ?string
{
    if (!preg_match('#^images/[0-9a-f]{2}/[0-9a-f]{40}\.(png|jpg|gif|webp)$#', $bild)) {
        return null;
    }
    $zip = new ZipArchive();
    if ($zip->open(stand_pfad($c), ZipArchive::RDONLY) !== true) {
        return null;
    }
    $daten = null;
    for ($i = 0; $i < $zip->numFiles && $daten === null; $i++) {
        $n = (string)$zip->getNameIndex($i);
        if ($n === $bild || str_ends_with($n, '/' . $bild)) {
            $daten = (string)$zip->getFromIndex($i);
        }
    }
    $zip->close();
    return $daten;
}
