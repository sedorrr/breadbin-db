<?php
// E-Mail an die Prüfenden, wenn ein neuer Beitrag auf Freigabe wartet.
// Notification e-mail to the maintainer when a new contribution waits for review.
//
// Gegen Flut (Breadbin sendet große Beiträge in Teilen à 400 Spiele): Eine Nachricht geht nur hinaus, wenn vor
// diesem Beitrag nichts mehr wartete – oder die letzte Nachricht älter als ein Tag ist. Wer prüft und alles
// erledigt, bekommt beim nächsten Eingang wieder eine Nachricht.
declare(strict_types=1);
require_once __DIR__ . '/gemeinsam.php';

/** Adresse aus config.php (`benachrichtigung_an`), leer = abgeschaltet. */
function benachrichtigung_an(array $c): string
{
    $an = trim((string)($c['benachrichtigung_an'] ?? ''));
    return filter_var($an, FILTER_VALIDATE_EMAIL) ? $an : '';
}

function benachrichtigung_stand_pfad(array $c): string
{
    return $c['daten_dir'] . '/benachrichtigung.json';
}

/**
 * Nach dem Speichern eines Beitrags aufrufen. `$wartetenVorher` = Zahl der Beiträge mit Status „neu“ vor diesem.
 * Gibt zurück, ob eine Nachricht verschickt wurde. Fehler beim Versand ändern nichts an der Annahme.
 */
function melde_neuen_beitrag(array $c, int $wartetenVorher, int $id, int $spiele, string $titel, string $name): bool
{
    $an = benachrichtigung_an($c);
    if ($an === '') {
        return false;
    }
    $standPfad = benachrichtigung_stand_pfad($c);
    $stand = is_file($standPfad) ? json_decode((string)file_get_contents($standPfad), true) : null;
    $zuletzt = is_array($stand) ? (int)($stand['zuletzt'] ?? 0) : 0;
    if ($wartetenVorher > 0 && time() - $zuletzt < 86400) {
        return false;
    }
    $warten = (int)db()->query("SELECT COUNT(*) FROM beitraege WHERE status = 'neu'")->fetchColumn();
    $spieleWarten = (int)db()->query("SELECT COALESCE(SUM(spiele), 0) FROM beitraege WHERE status = 'neu'")->fetchColumn();

    $einzeilig = fn(string $s, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', $s)), 0, $max);
    $text = "Ein neuer Beitrag für die Breadbin-Gemeinschaftsdaten wartet auf Freigabe.\n\n"
        . "Beitrag #$id: $spiele " . ($spiele === 1 ? 'Spiel' : 'Spiele')
        . ($name !== '' ? ' · von ' . $einzeilig($name, 100) : '') . "\n"
        . 'Titel: ' . $einzeilig($titel, 400) . "\n\n"
        . "Insgesamt warten jetzt $warten " . ($warten === 1 ? 'Beitrag' : 'Beiträge') . " mit $spieleWarten Spielen.\n";
    $url = trim((string)($c['pruefseite_url'] ?? ''));
    if ($url !== '') {
        $text .= "\nPrüfseite: $url\n";
    }
    $text .= "\nWeitere Nachrichten kommen erst, wenn alles geprüft ist (oder nach einem Tag).\n";

    $von = trim((string)($c['benachrichtigung_von'] ?? ''));
    $kopf = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: Breadbin-Annahme',
    ];
    if (filter_var($von, FILTER_VALIDATE_EMAIL)) {
        $kopf[] = 'From: Breadbin <' . $von . '>';
    }
    $betreff = mb_encode_mimeheader("Breadbin: $warten " . ($warten === 1 ? 'Beitrag wartet' : 'Beiträge warten')
        . ' auf Freigabe', 'UTF-8', 'Q');
    $ok = false;
    try {
        $ok = @mail($an, $betreff, $text, implode("\r\n", $kopf));
    } catch (Throwable $e) {
        $ok = false;
    }
    if ($ok) {
        @file_put_contents($standPfad, json_encode(['zuletzt' => time(), 'beitrag' => $id]));
    }
    return $ok;
}
