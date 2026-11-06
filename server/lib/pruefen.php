<?php
// Prüft einen Beitrag (Breadbin-Austauschdatei als ZIP) nach denselben Regeln wie tools/pruefen.py.
// Checks a contribution (Breadbin exchange file as ZIP) by the same rules as tools/pruefen.py.
declare(strict_types=1);

const BB_KINDS = ['sstitle', 'ss', 'box-2D'];
const BB_MAGIC = ['png' => "\x89PNG", 'jpg' => "\xFF\xD8\xFF", 'gif' => 'GIF8', 'webp' => 'RIFF'];
const BB_MAX_JSON = 262144;
const BB_MAX_IMAGE = 8388608;
const BB_MAX_GAMES = 500;

function ist_hex(mixed $s, int $n): bool
{
    return is_string($s) && strlen($s) === $n && ctype_xdigit($s) && strtolower($s) === $s;
}

/**
 * Prüft die ZIP-Datei. Gibt ['probleme' => [...], 'spiele' => [sha1 => Spiel-Array], 'bilder' => [Pfad, ...]] zurück.
 */
function pruefe_beitrag(string $zipPfad): array
{
    $probleme = [];
    $spiele = [];
    $bilder = [];
    $zip = new ZipArchive();
    if ($zip->open($zipPfad, ZipArchive::RDONLY) !== true) {
        return ['probleme' => ['Kein gültiges ZIP / not a valid ZIP'], 'spiele' => [], 'bilder' => []];
    }
    // Äußerer Ordner wie beim Herunterladen eines Repositorys als ZIP
    $praefix = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = (string)$zip->getNameIndex($i);
        if (preg_match('#^([^/]+/)?breadbin-share\.json$#', $n, $m)) {
            $kandidat = $m[1] ?? '';
            if ($praefix === '' || strlen($kandidat) < strlen($praefix)) {
                $praefix = $kandidat;
            }
        }
    }
    $kopf = $zip->getFromName($praefix . 'breadbin-share.json');
    $m = $kopf === false ? null : json_decode($kopf, true);
    if (!is_array($m) || ($m['format'] ?? '') !== 'breadbin-share' || ($m['version'] ?? 0) !== 1) {
        $probleme[] = 'breadbin-share.json fehlt oder ist ungültig / missing or invalid';
    }

    $benutzt = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $name = (string)$stat['name'];
        if (str_ends_with($name, '/') || $name === $praefix . 'breadbin-share.json') {
            continue;
        }
        if ($praefix !== '' && !str_starts_with($name, $praefix)) {
            $probleme[] = "$name: außerhalb des Datenordners / outside the data folder";
            continue;
        }
        $rel = substr($name, strlen($praefix));
        if (preg_match('#^games/([0-9a-f]{2})/([0-9a-f]{40})\.json$#', $rel, $t)) {
            if ($stat['size'] > BB_MAX_JSON) {
                $probleme[] = "$rel: größer als 256 KB / larger than 256 KB";
                continue;
            }
            $g = json_decode((string)$zip->getFromIndex($i), true);
            if (!is_array($g)) {
                $probleme[] = "$rel: kein gültiges JSON / invalid JSON";
                continue;
            }
            $fehler = pruefe_spiel($rel, $g, $t[2]);
            array_push($probleme, ...$fehler);
            foreach (($g['images'] ?? []) as $bild) {
                if (is_string($bild)) {
                    $benutzt[$bild] = true;
                }
            }
            if (!$fehler) {
                $spiele[$t[2]] = $g;
            }
        } elseif (preg_match('#^images/([0-9a-f]{2})/([0-9a-f]{40})\.(png|jpg|gif|webp)$#', $rel, $t)) {
            if ($stat['size'] > BB_MAX_IMAGE) {
                $probleme[] = "$rel: größer als 8 MB / larger than 8 MB";
                continue;
            }
            $daten = (string)$zip->getFromIndex($i);
            if (!str_starts_with($daten, BB_MAGIC[$t[3]]) || ($t[3] === 'webp' && substr($daten, 8, 4) !== 'WEBP')) {
                $probleme[] = "$rel: Inhalt passt nicht zur Endung / content does not match the extension";
            }
            if (sha1($daten) !== $t[2] || substr($t[2], 0, 2) !== $t[1]) {
                $probleme[] = "$rel: Name muss die SHA-1 des Inhalts sein / name must be the SHA-1 of the content";
            }
            $bilder[] = $rel;
        } else {
            $probleme[] = "$rel: unerwartete Datei / unexpected file";
        }
    }
    foreach (array_keys($benutzt) as $b) {
        if (!in_array($b, $bilder, true)) {
            $probleme[] = "$b: Bild fehlt / picture missing";
        }
    }
    foreach ($bilder as $b) {
        if (!isset($benutzt[$b])) {
            $probleme[] = "$b: wird von keinem Spiel benutzt / not used by any game";
        }
    }
    if (count($spiele) > BB_MAX_GAMES) {
        $probleme[] = 'Zu viele Spiele in einem Beitrag / too many games in one contribution';
    }
    if (!$spiele && !$probleme) {
        $probleme[] = 'Keine Spiele im Beitrag / no games in the contribution';
    }
    $zip->close();
    return ['probleme' => $probleme, 'spiele' => $spiele, 'bilder' => $bilder, 'praefix' => $praefix];
}

function pruefe_spiel(string $rel, array $g, string $sha1Pfad): array
{
    $p = [];
    if (!ist_hex($g['sha1'] ?? null, 40) || $g['sha1'] !== $sha1Pfad) {
        $p[] = "$rel: sha1 passt nicht zum Dateinamen / sha1 does not match the file name";
    }
    if (($g['version'] ?? null) !== 1) {
        $p[] = "$rel: version muss 1 sein / must be 1";
    }
    if (!ist_hex($g['md5'] ?? null, 32)) {
        $p[] = "$rel: md5 fehlt oder ungültig / md5 missing or invalid";
    }
    if (!ist_hex($g['crc32'] ?? null, 8)) {
        $p[] = "$rel: crc32 fehlt oder ungültig / crc32 missing or invalid";
    }
    if (!is_int($g['size'] ?? null) || $g['size'] <= 0) {
        $p[] = "$rel: size muss eine positive Zahl sein / size must be a positive number";
    }
    if (!is_string($g['title'] ?? null) || trim($g['title']) === '') {
        $p[] = "$rel: title fehlt / title missing";
    }
    foreach (['year', 'publisher', 'developer', 'players', 'synopsis'] as $f) {
        if (isset($g[$f]) && !is_string($g[$f])) {
            $p[] = "$rel: $f muss Text sein / must be a string";
        }
    }
    if (isset($g['genres']) && (!is_array($g['genres']) || array_filter($g['genres'], fn($x) => !is_string($x)))) {
        $p[] = "$rel: genres muss eine Liste von Texten sein / must be a list of strings";
    }
    $sid = $g['screenscraper_id'] ?? '';
    if ($sid !== '' && !(is_string($sid) && ctype_digit($sid))) {
        $p[] = "$rel: screenscraper_id nur Ziffern / digits only";
    }
    foreach (($g['images'] ?? []) as $art => $bild) {
        if (!in_array($art, BB_KINDS, true)) {
            $p[] = "$rel: unbekannte Bildart $art / unknown picture type";
        }
        if (!is_string($bild) || !preg_match('#^images/([0-9a-f]{2})/\1[0-9a-f]{38}\.(png|jpg|gif|webp)$#', $bild)) {
            $p[] = "$rel: ungültiger Bildpfad / invalid picture path";
        }
    }
    return $p;
}
