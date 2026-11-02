<?php
// Prüfseite: eingegangene Beiträge ansehen, annehmen oder ablehnen.
// Review page: look at incoming contributions, accept or reject them.
declare(strict_types=1);
require __DIR__ . '/../../lib/gemeinsam.php';
require __DIR__ . '/../../lib/pruefen.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
$c = konfig();
$token = $_SESSION['token'] ??= bin2hex(random_bytes(16));

// Anmelden / abmelden
if (isset($_POST['passwort'])) {
    if ($c['admin_hash'] !== '' && password_verify((string)$_POST['passwort'], $c['admin_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
    } else {
        sleep(2);
        $fehler = 'Passwort falsch';
    }
}
if (isset($_GET['abmelden'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}
$admin = !empty($_SESSION['admin']);

function beitrag(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM beitraege WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

if ($admin) {
    // Bild oder ZIP aus einem Beitrag ausliefern
    if (isset($_GET['bild'], $_GET['id'])) {
        $b = beitrag((int)$_GET['id']);
        $pfad = (string)$_GET['bild'];
        if (!$b || !preg_match('#^images/[0-9a-f]{2}/[0-9a-f]{40}\.(png|jpg|gif|webp)$#', $pfad, $m)) {
            http_response_code(404);
            exit;
        }
        $zip = new ZipArchive();
        $zip->open($c['daten_dir'] . '/' . $b['datei'], ZipArchive::RDONLY);
        $daten = false;
        for ($i = 0; $i < $zip->numFiles && $daten === false; $i++) {
            $n = (string)$zip->getNameIndex($i);
            if ($n === $pfad || str_ends_with($n, '/' . $pfad)) {
                $daten = $zip->getFromIndex($i);
            }
        }
        if ($daten === false) {
            http_response_code(404);
            exit;
        }
        $typ = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'][$m[1]];
        header("Content-Type: $typ");
        header('X-Content-Type-Options: nosniff');
        echo $daten;
        exit;
    }
    if (isset($_GET['zip'])) {
        $b = beitrag((int)$_GET['zip']);
        if (!$b) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="beitrag-' . (int)$b['id'] . '.zip"');
        readfile($c['daten_dir'] . '/' . $b['datei']);
        exit;
    }
    // Entscheidung speichern
    if (isset($_POST['entscheidung'], $_POST['id']) && hash_equals($token, (string)($_POST['token'] ?? ''))) {
        $status = $_POST['entscheidung'] === 'angenommen' ? 'angenommen' : 'abgelehnt';
        $st = db()->prepare('UPDATE beitraege SET status = ?, entschieden = NOW(), notiz = ? WHERE id = ?');
        $st->execute([$status, mb_substr(trim((string)($_POST['notiz'] ?? '')), 0, 2000), (int)$_POST['id']]);
        header('Location: ./');
        exit;
    }
}
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Breadbin – Beiträge prüfen</title>
<style>
  :root { --bg: #f6f5f2; --panel: #fff; --text: #1d1c22; --muted: #6b6977; --line: #e2e0e8; --accent: #4b3fa0; --ok: #2c7a4c; --bad: #b3261e; }
  @media (prefers-color-scheme: dark) { :root { --bg: #17161c; --panel: #201f27; --text: #e8e6ef; --muted: #9a97a8; --line: #34323e; --accent: #8b7ff0; --ok: #72cc97; --bad: #ff8a80; } }
  body { background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, sans-serif; margin: 0; padding: 16px; }
  main { max-width: 960px; margin: 0 auto; display: grid; gap: 16px; }
  .karte { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 14px; }
  .meta { color: var(--muted); font-size: 13px; }
  .spiel { border-top: 1px solid var(--line); padding-top: 10px; margin-top: 10px; display: grid; gap: 6px; }
  .bilder img { max-width: 240px; image-rendering: pixelated; border: 1px solid var(--line); margin-right: 6px; }
  button, input, textarea { font: inherit; }
  .ok { color: var(--ok); } .bad { color: var(--bad); }
  a { color: var(--accent); }
  textarea { width: 100%; box-sizing: border-box; }
</style>
</head>
<body><main>
<h1>Breadbin – Beiträge prüfen</h1>
<?php if (!$admin): ?>
  <form method="post" class="karte">
    <?php if (!empty($fehler)): ?><p class="bad"><?= h($fehler) ?></p><?php endif ?>
    <label>Passwort <input type="password" name="passwort" autofocus></label>
    <button>Anmelden</button>
  </form>
<?php else:
    $zeige = $_GET['status'] ?? 'neu';
    $zeige = in_array($zeige, ['neu', 'angenommen', 'abgelehnt'], true) ? $zeige : 'neu';
    $st = db()->prepare('SELECT * FROM beitraege WHERE status = ? ORDER BY eingang DESC LIMIT 50');
    $st->execute([$zeige]);
    $liste = $st->fetchAll(); ?>
  <p class="meta">
    <a href="?status=neu">Neu</a> · <a href="?status=angenommen">Angenommen</a> · <a href="?status=abgelehnt">Abgelehnt</a>
    · <a href="?abmelden=1">Abmelden</a>
  </p>
  <?php if (!$liste): ?><p class="meta">Keine Beiträge mit Status „<?= h($zeige) ?>“.</p><?php endif ?>
  <?php foreach ($liste as $b):
      $pfad = $c['daten_dir'] . '/' . $b['datei'];
      $erg = is_file($pfad) ? pruefe_beitrag($pfad) : ['probleme' => ['Datei fehlt'], 'spiele' => []]; ?>
    <section class="karte">
      <strong>#<?= (int)$b['id'] ?></strong> · <?= h($b['eingang']) ?> · <?= (int)$b['spiele'] ?> Spiele
      <?php if ($b['name'] !== ''): ?>· von <?= h($b['name']) ?><?php endif ?>
      · <a href="?zip=<?= (int)$b['id'] ?>">ZIP herunterladen</a>
      <?php if ($b['hinweis'] !== ''): ?><p><em><?= nl2br(h($b['hinweis'])) ?></em></p><?php endif ?>
      <?php foreach ($erg['probleme'] as $p): ?><p class="bad"><?= h($p) ?></p><?php endforeach ?>
      <?php foreach ($erg['spiele'] as $sha1 => $g): ?>
        <div class="spiel">
          <div><strong><?= h($g['title'] ?? '') ?></strong>
            <span class="meta"><?= h(implode(' · ', array_filter([$g['year'] ?? '', $g['publisher'] ?? '', $g['developer'] ?? '', implode(', ', $g['genres'] ?? [])]))) ?></span></div>
          <div class="meta"><?= h(implode(', ', $g['files'] ?? [])) ?> · <?= h($g['format'] ?? '') ?> · SHA-1 <?= h($sha1) ?></div>
          <?php if (!empty($g['synopsis'])): ?><div><?= nl2br(h($g['synopsis'])) ?></div><?php endif ?>
          <div class="bilder"><?php foreach (($g['images'] ?? []) as $art => $bild): ?>
            <img src="?id=<?= (int)$b['id'] ?>&amp;bild=<?= h(rawurlencode($bild)) ?>" alt="<?= h($art) ?>" title="<?= h($art) ?>">
          <?php endforeach ?></div>
        </div>
      <?php endforeach ?>
      <?php if ($b['status'] === 'neu'): ?>
        <form method="post">
          <input type="hidden" name="token" value="<?= h($token) ?>">
          <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
          <textarea name="notiz" rows="2" placeholder="Notiz (z. B. Grund der Ablehnung)"></textarea>
          <button name="entscheidung" value="angenommen" class="ok">Annehmen</button>
          <button name="entscheidung" value="abgelehnt" class="bad">Ablehnen</button>
        </form>
      <?php else: ?>
        <p class="meta"><?= h($b['status']) ?> am <?= h((string)$b['entschieden']) ?><?= $b['notiz'] !== '' ? ': ' . h($b['notiz']) : '' ?></p>
      <?php endif ?>
    </section>
  <?php endforeach ?>
<?php endif ?>
</main></body>
</html>
