<?php
// Gemeinsame Hilfen für API und Prüfseite. Shared helpers for API and review page.
declare(strict_types=1);

function konfig(): array
{
    static $c = null;
    if ($c === null) {
        $datei = __DIR__ . '/../config.php';
        if (!is_file($datei)) {
            http_response_code(500);
            exit("config.php fehlt / missing\n");
        }
        $c = require $datei;
    }
    return $c;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = konfig();
        $pdo = new PDO($c['db_dsn'], $c['db_user'], $c['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_antwort(int $status, array $daten): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
