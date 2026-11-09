<?php
// Abholung angenommener Beiträge durch das Daten-Repository (GitHub Action „Beiträge übernehmen“).
// Schutz: Kopfzeile „X-Breadbin-Token: <Schlüssel>“ (oder „Authorization: Bearer …“, das manche PHP-Umgebungen
// verschlucken); in config.php steht nur die SHA-256 des Schlüssels
// ('export_token_sha256'). Ohne diesen Eintrag ist die Adresse abgeschaltet (404).
//   GET  ?liste            → angenommene, noch nicht übernommene Beiträge (JSON)
//   GET  ?id=N             → geprüfte Fassung des Beitrags N (ZIP, mit Auswahl und Bearbeitungen der Prüfseite)
//   POST ?uebernommen=N    → Beitrag N als übernommen vermerken
// Pull endpoint for accepted contributions, used by the data repository's GitHub Action. Bearer token, only its
// SHA-256 is stored in config.php; without it the endpoint is disabled.
declare(strict_types=1);
require __DIR__ . '/../../lib/beitrag.php';

$c = konfig();
$soll = strtolower(trim((string)($c['export_token_sha256'] ?? '')));
if (!preg_match('/^[0-9a-f]{64}$/', $soll)) {
    json_antwort(404, ['ok' => false, 'fehler' => 'nicht eingerichtet / not configured']);
}
$schluessel = trim((string)($_SERVER['HTTP_X_BREADBIN_TOKEN'] ?? ''));
if ($schluessel === '' && preg_match('/^Bearer\s+(\S+)$/', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m)) {
    $schluessel = $m[1];
}
if (strlen($schluessel) < 32 || !hash_equals($soll, hash('sha256', $schluessel))) {
    sleep(2); // Raten bremsen / slow down guessing
    json_antwort(403, ['ok' => false, 'fehler' => 'Schlüssel falsch / wrong token']);
}

sorge_fuer_uebernommen();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['uebernommen'])) {
    $st = db()->prepare("UPDATE beitraege SET uebernommen = NOW() WHERE id = ? AND status = 'angenommen'");
    $st->execute([(int)$_GET['uebernommen']]);
    json_antwort(200, ['ok' => $st->rowCount() === 1, 'id' => (int)$_GET['uebernommen']]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_antwort(405, ['ok' => false, 'fehler' => 'nur GET oder POST ?uebernommen / GET or POST ?uebernommen only']);
}

if (isset($_GET['liste'])) {
    $zeilen = db()->query("SELECT id, eingang, entschieden, spiele, name FROM beitraege
        WHERE status = 'angenommen' AND uebernommen IS NULL ORDER BY id")->fetchAll();
    foreach ($zeilen as &$z) {
        $z['id'] = (int)$z['id'];
        $z['spiele'] = (int)$z['spiele'];
    }
    json_antwort(200, ['ok' => true, 'beitraege' => $zeilen]);
}

if (isset($_GET['id'])) {
    $b = beitrag((int)$_GET['id']);
    if (!$b || $b['status'] !== 'angenommen') {
        json_antwort(404, ['ok' => false, 'fehler' => 'kein angenommener Beitrag / no accepted contribution']);
    }
    [$pfad, $temp] = geprueftes_zip($c, $b);
    if (!is_file($pfad)) {
        json_antwort(410, ['ok' => false, 'fehler' => 'Datei fehlt / file missing']);
    }
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($pfad));
    header('Content-Disposition: attachment; filename="beitrag-' . (int)$b['id'] . '-geprueft.zip"');
    readfile($pfad);
    if ($temp) {
        @unlink($pfad);
    }
    exit;
}

json_antwort(400, ['ok' => false, 'fehler' => '?liste, ?id=N oder POST ?uebernommen=N']);
