<?php
// Nimmt einen Beitrag an: POST (multipart/form-data) mit „datei“ = Breadbin-Austauschdatei (ZIP),
// freiwillig „name“ (für die Namensnennung) und „hinweis“. Antwort JSON.
// Accepts a contribution: POST with “datei” = Breadbin exchange file (ZIP), optional “name”, “hinweis”.
declare(strict_types=1);
require __DIR__ . '/../../lib/gemeinsam.php';
require __DIR__ . '/../../lib/pruefen.php';

$c = konfig();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_antwort(200, [
        'dienst' => 'breadbin-beitrag',
        'version' => 1,
        'max_bytes' => $c['max_bytes'],
        'feld' => 'datei',
    ]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_antwort(405, ['ok' => false, 'fehler' => 'Nur POST / POST only']);
}

$ipHash = hash('sha256', $c['salt'] . ($_SERVER['REMOTE_ADDR'] ?? ''));
$st = db()->prepare('SELECT COUNT(*) FROM beitraege WHERE ip_hash = ? AND eingang > (NOW() - INTERVAL 1 HOUR)');
$st->execute([$ipHash]);
if ((int)$st->fetchColumn() >= $c['max_pro_stunde']) {
    json_antwort(429, ['ok' => false, 'fehler' => 'Zu viele Beiträge, bitte später erneut / too many contributions, try again later']);
}

$f = $_FILES['datei'] ?? null;
if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_antwort(400, ['ok' => false, 'fehler' => 'Datei „datei“ fehlt oder Hochladen gescheitert / file missing or upload failed']);
}
if ($f['size'] > $c['max_bytes']) {
    json_antwort(413, ['ok' => false, 'fehler' => 'Beitrag zu groß / contribution too large']);
}

if (!is_dir($c['daten_dir'])) {
    @mkdir($c['daten_dir'], 0700, true);
}
$name = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.zip';
$ziel = $c['daten_dir'] . '/' . $name;
if (!@move_uploaded_file($f['tmp_name'], $ziel)) {
    // Grund ohne Serverpfade nennen, damit sich die Einrichtung prüfen lässt.
    $grund = !is_dir($c['daten_dir']) ? 'Ordner daten/ fehlt / folder daten/ missing'
        : (!is_writable($c['daten_dir']) ? 'Ordner daten/ nicht beschreibbar / folder daten/ not writable'
        : (!is_uploaded_file($f['tmp_name']) ? 'Hochgeladene Datei fehlt (upload_tmp_dir?) / uploaded file missing'
        : 'Verschieben gescheitert / move failed'));
    json_antwort(500, ['ok' => false, 'fehler' => 'Speichern gescheitert: ' . $grund]);
}

$ergebnis = pruefe_beitrag($ziel);
if ($ergebnis['probleme']) {
    unlink($ziel);
    json_antwort(422, ['ok' => false, 'fehler' => 'Beitrag nicht gültig / contribution not valid', 'probleme' => array_slice($ergebnis['probleme'], 0, 50)]);
}

$titel = implode(', ', array_slice(array_unique(array_map(fn($g) => (string)$g['title'], $ergebnis['spiele'])), 0, 30));
$ins = db()->prepare('INSERT INTO beitraege (eingang, datei, groesse, spiele, titel, name, hinweis, ip_hash, notiz)
                      VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, \'\')');
$ins->execute([
    $name,
    (int)$f['size'],
    count($ergebnis['spiele']),
    $titel,
    mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100),
    mb_substr(trim((string)($_POST['hinweis'] ?? '')), 0, 2000),
    $ipHash,
]);
json_antwort(201, ['ok' => true, 'id' => (int)db()->lastInsertId(), 'spiele' => count($ergebnis['spiele'])]);
