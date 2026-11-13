<?php
// Zählt einen Download anonym und leitet auf die Datei der Programm-Webseite weiter.
// Counts a download anonymously and redirects to the file on the program website.
declare(strict_types=1);

$ziel = 'https://breadbin.8bitcavern.de/download/';
$f = (string)($_GET['f'] ?? '');
$lib = __DIR__ . '/../breadbin/annahme/lib/zaehler.php';
$erlaubt = ['Breadbin_universal.dmg', 'Breadbin_x64-setup.exe', 'Breadbin_x64_en-US.msi',
            'Breadbin_amd64.AppImage', 'Breadbin_amd64.deb', 'Breadbin.x86_64.rpm'];
if (!in_array($f, $erlaubt, true)) {
    http_response_code(404);
    exit("Unbekannte Datei / unknown file\n");
}
header('Location: ' . $ziel . rawurlencode($f), true, 302);
header('Cache-Control: no-store');
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
if (is_file($lib)) {
    require_once $lib;
    zaehle('download', $f);
}
