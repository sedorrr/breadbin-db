<?php
// Liefert version.json aus und zählt dabei anonym die Abrufe der App (User-Agent „Breadbin/<Version>“).
// Die Webseite holt dieselbe Datei – sie zählt als „webseite“. Siehe server/lib/zaehler.php.
// Serves version.json and anonymously counts app requests. See server/lib/zaehler.php.
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache');
readfile(__DIR__ . '/version.json');

// Antwort zuerst ausliefern, dann zählen
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    flush();
}
$lib = __DIR__ . '/../breadbin/annahme/lib/zaehler.php';
if (is_file($lib)) {
    require_once $lib;
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (preg_match('#^Breadbin/([0-9][0-9A-Za-z.+-]{0,30})#', $ua, $m)) {
        zaehle('app', $m[1]);
    } elseif (isset($_SERVER['HTTP_ORIGIN'])) {
        zaehle('webseite', 'besuch');
    }
}
