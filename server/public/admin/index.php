<?php
// Prüfseite: eingegangene Beiträge ansehen, annehmen oder ablehnen.
// Review page: look at incoming contributions, accept or reject them.
declare(strict_types=1);
require __DIR__ . '/../../lib/gemeinsam.php';
require __DIR__ . '/../../lib/pruefen.php';
require __DIR__ . '/../../lib/vergleich.php';
require __DIR__ . '/../../lib/beitrag.php';

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
    // Bild aus dem veröffentlichten Stand (Gegenüberstellung bisher/neu)
    if (isset($_GET['standbild'])) {
        $pfad = (string)$_GET['standbild'];
        $daten = preg_match('#\.(png|jpg|gif|webp)$#', $pfad, $m) ? stand_bild($c, $pfad) : null;
        if ($daten === null) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'][$m[1]]);
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
        [$pfad, $temp] = geprueftes_zip($c, $b);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="beitrag-' . (int)$b['id'] . ($temp ? '-geprueft' : '') . '.zip"');
        readfile($pfad);
        if ($temp) {
            unlink($pfad);
        }
        exit;
    }
    // Bearbeitung eines Spiels speichern bzw. verwerfen (nur solange der Beitrag neu ist)
    if ((isset($_POST['bearbeiten']) || isset($_POST['verwerfen'])) && isset($_POST['id'], $_POST['sha1'])
        && hash_equals($token, (string)($_POST['token'] ?? ''))) {
        $b = beitrag((int)$_POST['id']);
        $sha1 = (string)$_POST['sha1'];
        if ($b && $b['status'] === 'neu' && preg_match('/^[0-9a-f]{40}$/', $sha1)) {
            $ed = edits($c, $b);
            if (isset($_POST['verwerfen'])) {
                unset($ed[$sha1]);
            } else {
                $e = [];
                foreach (EDIT_FELDER as $f => $max) {
                    $e[$f] = mb_substr(trim(str_replace("\r", '', (string)($_POST[$f] ?? ''))), 0, $max);
                }
                if ($e['title'] === '') {
                    $_SESSION['meldung'] = 'Der Titel darf nicht leer sein – nichts gespeichert.';
                    header('Location: ./#s' . $sha1);
                    exit;
                }
                $genres = array_values(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 60), explode(',', (string)($_POST['genres'] ?? '')))));
                $e['genres'] = array_slice($genres, 0, 20);
                $e['drop_images'] = array_values(array_intersect(BB_KINDS, (array)($_POST['drop'] ?? [])));
                $ed[$sha1] = $e;
            }
            file_put_contents(edits_pfad($c, $b), json_encode($ed, JSON_UNESCAPED_UNICODE));
        }
        header('Location: ./#s' . $sha1);
        exit;
    }
    // Entscheidung speichern
    if (isset($_POST['entscheidung'], $_POST['id']) && hash_equals($token, (string)($_POST['token'] ?? ''))) {
        $b = beitrag((int)$_POST['id']);
        $status = $_POST['entscheidung'] === 'angenommen' ? 'angenommen' : 'abgelehnt';
        $notiz = mb_substr(trim((string)($_POST['notiz'] ?? '')), 0, 2000);
        if ($b && $status === 'angenommen') {
            // Nur die angehakten Spiele übernehmen; alle angehakt = ganzer Beitrag.
            $erg = pruefe_beitrag($c['daten_dir'] . '/' . $b['datei']);
            $alle = array_keys($erg['spiele']);
            $gewaehlt = array_values(array_intersect($alle, array_map('strval', (array)($_POST['spiele'] ?? []))));
            if (!$gewaehlt) {
                $status = 'abgelehnt';
            } elseif (count($gewaehlt) < count($alle)) {
                file_put_contents(auswahl_pfad($c, $b), json_encode($gewaehlt));
                $notiz = trim(count($gewaehlt) . ' von ' . count($alle) . ' Spielen übernommen. ' . $notiz);
            }
        }
        $st = db()->prepare('UPDATE beitraege SET status = ?, entschieden = NOW(), notiz = ? WHERE id = ?');
        $st->execute([$status, $notiz, (int)$_POST['id']]);
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
  .nimm { font-weight: 600; color: var(--accent); }
  .spiel.weg { opacity: .5; }
  .chip { font-size: 12px; padding: 1px 7px; border-radius: 9px; background: var(--line); color: var(--muted); }
  .bearbeiten summary { cursor: pointer; color: var(--accent); }
  .bearbeiten form { display: grid; gap: 6px; margin-top: 6px; }
  .bearbeiten label { display: grid; gap: 2px; font-size: 13px; color: var(--muted); }
  .bearbeiten .reihe { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
  .bearbeiten .meta label { display: inline-flex; gap: 4px; margin-right: 10px; }
  .bearbeiten input:not([type=checkbox]) { width: 100%; box-sizing: border-box; }
  .chip.neu { background: color-mix(in srgb, var(--ok) 20%, transparent); color: var(--ok); }
  .chip.geaendert { background: color-mix(in srgb, var(--accent) 22%, transparent); color: var(--accent); }
  .chip.gleich { background: var(--line); color: var(--muted); }
  .spiel.gleich > :not(.kopf):not(.nimm) { display: none; }
  .vergleich { border-collapse: collapse; width: 100%; font-size: 13px; }
  .vergleich th, .vergleich td { border-top: 1px solid var(--line); padding: 4px 6px; text-align: left; vertical-align: top; }
  .vergleich td { white-space: pre-wrap; width: 45%; }
  .vergleich th { width: 10%; color: var(--muted); font-weight: 500; }
  .vergleich del { color: var(--bad); text-decoration: none; } .vergleich ins { color: var(--ok); text-decoration: none; }
  .vergleich img { max-width: 160px; image-rendering: pixelated; border: 1px solid var(--line); }
  .zusammen { font-size: 13px; }
  .zahlen { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; }
  .zahl { border: 1px solid var(--line); border-radius: 8px; padding: 8px 10px; }
  .zahl b { display: block; font-size: 22px; color: var(--accent); font-variant-numeric: tabular-nums; }
  .zahl span { font-size: 12px; color: var(--muted); }
  .balken { display: grid; grid-template-columns: 60px 1fr 50px; gap: 6px; align-items: center; font-size: 13px; }
  .balken > span:first-child { overflow-wrap: anywhere; }
  .balken.breit { grid-template-columns: minmax(0, 290px) 1fr 60px; }
  .balken i { display: block; height: 8px; border-radius: 3px; background: var(--accent); min-width: 2px; }
  .statistik summary { cursor: pointer; font-weight: 600; }
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
    if (!empty($_SESSION['meldung'])): ?><p class="bad"><?= h($_SESSION['meldung']) ?></p><?php unset($_SESSION['meldung']); endif;
    $zeige = $_GET['status'] ?? 'neu';
    $zeige = in_array($zeige, ['neu', 'angenommen', 'abgelehnt'], true) ? $zeige : 'neu';
    $st = db()->prepare('SELECT * FROM beitraege WHERE status = ? ORDER BY eingang DESC LIMIT 50');
    $st->execute([$zeige]);
    $liste = $st->fetchAll();
    $stand = stand_spiele($c); ?>
  <?php if ($stand === null): ?><p class="meta bad">Vergleich mit dem Datenbestand nicht möglich: Stand nicht gefunden
    (<?= h(stand_pfad($c)) ?>; in config.php mit 'stand_zip' einstellbar).</p><?php endif ?>
  <p class="meta">
    <a href="?status=neu">Neu</a> · <a href="?status=angenommen">Angenommen</a> · <a href="?status=abgelehnt">Abgelehnt</a>
    · <a href="?abmelden=1">Abmelden</a>
  </p>
  <?php
    // Statistik: Eingang und veröffentlichter Stand.
    $z = db()->query("SELECT status, COUNT(*) n, COALESCE(SUM(spiele),0) s FROM beitraege GROUP BY status")->fetchAll();
    $nach = ['neu' => [0, 0], 'angenommen' => [0, 0], 'abgelehnt' => [0, 0]];
    foreach ($z as $r) { $nach[$r['status']] = [(int)$r['n'], (int)$r['s']]; }
    $info = db()->query("SELECT COUNT(DISTINCT ip_hash) leute, MAX(eingang) zuletzt,
        SUM(eingang >= NOW() - INTERVAL 30 DAY) monat FROM beitraege")->fetch();
    $fmt = []; $mitbild = 0; $titel = [];
    foreach ($stand ?? [] as $sp) {
        $f = (string)($sp['format'] ?? '?'); $fmt[$f] = ($fmt[$f] ?? 0) + 1;
        if (!empty($sp['images'])) { $mitbild++; }
        $titel[mb_strtolower(preg_replace('/\s*\[[^\]]*\]/u', '', (string)($sp['title'] ?? '')))] = true;
    }
    arsort($fmt);
    $max = $fmt ? max($fmt) : 1;
    $standzeit = is_file(stand_pfad($c)) ? date('d.m.Y H:i', (int)filemtime(stand_pfad($c))) : '–';
  ?>
  <details class="karte statistik" open>
    <summary>Statistik</summary>
    <p class="meta">Eingang</p>
    <div class="zahlen">
      <div class="zahl"><b><?= $nach['neu'][0] ?></b><span>neu (<?= $nach['neu'][1] ?> Spiele)</span></div>
      <div class="zahl"><b><?= $nach['angenommen'][0] ?></b><span>angenommen (<?= $nach['angenommen'][1] ?> Spiele)</span></div>
      <div class="zahl"><b><?= $nach['abgelehnt'][0] ?></b><span>abgelehnt</span></div>
      <div class="zahl"><b><?= (int)$info['monat'] ?></b><span>Beiträge in 30 Tagen</span></div>
      <div class="zahl"><b><?= (int)$info['leute'] ?></b><span>verschiedene Einsender</span></div>
      <div class="zahl"><b><?= $info['zuletzt'] ? h(date('d.m.', strtotime((string)$info['zuletzt']))) : '–' ?></b><span>letzter Eingang</span></div>
    </div>
    <?php if ($stand !== null): ?>
    <p class="meta">Veröffentlichter Stand vom <?= h($standzeit) ?></p>
    <div class="zahlen">
      <div class="zahl"><b><?= count($titel) ?></b><span>Spiele</span></div>
      <div class="zahl"><b><?= count($stand) ?></b><span>Dateien</span></div>
      <div class="zahl"><b><?= $mitbild ?></b><span>Dateien mit Bild</span></div>
    </div>
    <div style="margin-top:8px; display:grid; gap:4px">
      <?php foreach ($fmt as $f => $n): ?>
        <div class="balken"><span><?= h($f) ?></span><i style="width:<?= round($n / $max * 100) ?>%"></i><span><?= $n ?></span></div>
      <?php endforeach ?>
    </div>
    <?php endif ?>
    <p class="meta">Öffentliche Übersicht mit Suche: <a href="https://breadbindb.8bitcavern.de/">breadbindb.8bitcavern.de</a></p>
  </details>
  <?php
    // Nutzung (anonyme Tageszahlen aus lib/zaehler.php; Tabellen entstehen beim ersten Abruf)
    $nz = null;
    try {
        $q = db()->query("SELECT tag, art, schluessel, aufrufe, eindeutig FROM nutzung_tag
                           WHERE tag >= CURDATE() - INTERVAL 30 DAY ORDER BY tag DESC");
        $nz = $q->fetchAll();
        $gesamt = db()->query("SELECT art, schluessel, SUM(aufrufe) a FROM nutzung_tag WHERE art = 'download'
                               GROUP BY art, schluessel ORDER BY a DESC")->fetchAll();
    } catch (Throwable $e) { $nz = null; }
    if ($nz !== null):
        $tage = []; $vers = []; $dl30 = []; $web30 = 0;
        $woche = gmdate('Y-m-d', time() - 6 * 86400);
        foreach ($nz as $r) {
            $t = $r['tag'];
            $tage[$t] ??= ['inst' => 0, 'starts' => 0, 'web' => 0, 'dl' => 0];
            if ($r['art'] === 'app') {
                $tage[$t]['inst'] += (int)$r['eindeutig']; $tage[$t]['starts'] += (int)$r['aufrufe'];
                if ($t >= $woche) { $vers[$r['schluessel']] = max($vers[$r['schluessel']] ?? 0, (int)$r['eindeutig']); }
            } elseif ($r['art'] === 'webseite') {
                $tage[$t]['web'] += (int)$r['eindeutig']; $web30 += (int)$r['eindeutig'];
            } elseif ($r['art'] === 'download') {
                $tage[$t]['dl'] += (int)$r['aufrufe']; $dl30[$r['schluessel']] = ($dl30[$r['schluessel']] ?? 0) + (int)$r['aufrufe'];
            }
        }
        $heute = $tage[gmdate('Y-m-d')]['inst'] ?? 0;
        $w = array_filter($tage, fn($k) => $k >= $woche, ARRAY_FILTER_USE_KEY);
        $max7 = $w ? max(array_column($w, 'inst')) : 0;
        $schnitt7 = $w ? round(array_sum(array_column($w, 'inst')) / 7, 1) : 0;
        arsort($vers);
  ?>
  <details class="karte statistik" open>
    <summary>Nutzung</summary>
    <p class="meta">Anonyme Tageszahlen: Abrufe der Update-Prüfung durch die App (höchstens einer je Installation und Tag),
      Besuche der Webseite und Downloads. Gespeichert werden nur Zähler, keine IP-Adressen.</p>
    <div class="zahlen">
      <div class="zahl"><b><?= $heute ?></b><span>Installationen heute</span></div>
      <div class="zahl"><b><?= $max7 ?></b><span>höchstens an einem Tag (7 Tage)</span></div>
      <div class="zahl"><b><?= h((string)$schnitt7) ?></b><span>Schnitt je Tag (7 Tage)</span></div>
      <div class="zahl"><b><?= array_sum($dl30) ?></b><span>Downloads (30 Tage)</span></div>
      <div class="zahl"><b><?= $web30 ?></b><span>Webseitenbesuche (30 Tage)</span></div>
    </div>
    <?php if ($vers): ?>
    <p class="meta">Versionen (7 Tage, Installationen am stärksten Tag)</p>
    <div style="display:grid; gap:4px">
      <?php $mv = max($vers); foreach ($vers as $v => $n): ?>
        <div class="balken"><span><?= h((string)$v) ?></span><i style="width:<?= round($n / max(1, $mv) * 100) ?>%"></i><span><?= $n ?></span></div>
      <?php endforeach ?>
    </div>
    <?php endif ?>
    <?php if ($gesamt): ?>
    <p class="meta">Downloads je Paket (30 Tage / insgesamt)</p>
    <div style="display:grid; gap:4px">
      <?php $mg = max(array_map(fn($r) => (int)$r['a'], $gesamt)); foreach ($gesamt as $r): ?>
        <div class="balken breit"><span><?= h($r['schluessel']) ?></span><i style="width:<?= round((int)$r['a'] / max(1, $mg) * 100) ?>%"></i><span><?= (int)($dl30[$r['schluessel']] ?? 0) ?> / <?= (int)$r['a'] ?></span></div>
      <?php endforeach ?>
    </div>
    <?php endif ?>
    <?php if ($tage): ?>
    <p class="meta">Letzte Tage (Installationen · App-Starts · Webseite · Downloads)</p>
    <table class="meta" style="border-collapse:collapse">
      <?php foreach (array_slice($tage, 0, 14, true) as $t => $r): ?>
        <tr><td style="padding-right:12px"><?= h(date('d.m.', strtotime((string)$t))) ?></td><td style="padding-right:12px"><?= $r['inst'] ?></td>
          <td style="padding-right:12px"><?= $r['starts'] ?></td><td style="padding-right:12px"><?= $r['web'] ?></td><td><?= $r['dl'] ?></td></tr>
      <?php endforeach ?>
    </table>
    <?php else: ?><p class="meta">Noch keine Abrufe gezählt.</p><?php endif ?>
  </details>
  <?php endif ?>
  <?php if (!$liste): ?><p class="meta">Keine Beiträge mit Status „<?= h($zeige) ?>“.</p><?php endif ?>
  <?php foreach ($liste as $b):
      $pfad = $c['daten_dir'] . '/' . $b['datei'];
      $erg = is_file($pfad) ? pruefe_beitrag($pfad) : ['probleme' => ['Datei fehlt'], 'spiele' => []];
      $wahl = auswahl($c, $b);
      $ed = edits($c, $b);
      $neu = $b['status'] === 'neu';
      $vg = [];
      if ($stand !== null) {
          foreach ($erg['spiele'] as $sha1 => $orig) {
              $vg[$sha1] = vergleiche(mit_edits($orig, $ed[$sha1] ?? null), $stand[$sha1] ?? null);
          }
      }
      $zaehl = array_count_values(array_column($vg, 'art')); ?>
    <section class="karte">
      <strong>#<?= (int)$b['id'] ?></strong> · <?= h($b['eingang']) ?> · <?= (int)$b['spiele'] ?> Spiele
      <?php if ($b['name'] !== ''): ?>· von <?= h($b['name']) ?><?php endif ?>
      · <a href="?zip=<?= (int)$b['id'] ?>">ZIP herunterladen</a>
      <?php if ($vg): ?><p class="zusammen">Im Vergleich zum Datenbestand:
        <span class="chip neu"><?= (int)($zaehl['neu'] ?? 0) ?> neu</span>
        <span class="chip geaendert"><?= (int)($zaehl['geaendert'] ?? 0) ?> geändert</span>
        <span class="chip gleich"><?= (int)($zaehl['gleich'] ?? 0) ?> unverändert</span>
        <?php if (!empty($zaehl['gleich']) && $neu): ?><span class="meta">– unveränderte sind nicht angehakt und eingeklappt.</span><?php endif ?></p><?php endif ?>
      <?php if ($b['hinweis'] !== ''): ?><p><em><?= nl2br(h($b['hinweis'])) ?></em></p><?php endif ?>
      <?php foreach ($erg['probleme'] as $p): ?><p class="bad"><?= h($p) ?></p><?php endforeach ?>
      <?php foreach ($erg['spiele'] as $sha1 => $orig):
          $g = mit_edits($orig, $ed[$sha1] ?? null);
          $v = $vg[$sha1] ?? null;
          $art = $v['art'] ?? ''; ?>
        <div class="spiel<?= $wahl !== null && !in_array($sha1, $wahl, true) ? ' weg' : '' ?><?= $art === 'gleich' ? ' gleich' : '' ?>" id="s<?= h($sha1) ?>">
          <?php if ($neu): ?>
            <label class="nimm"><input type="checkbox" name="spiele[]" value="<?= h($sha1) ?>" form="f<?= (int)$b['id'] ?>" <?= $art === 'gleich' ? '' : 'checked' ?>> übernehmen</label>
          <?php elseif ($wahl !== null && !in_array($sha1, $wahl, true)): ?>
            <span class="meta bad">nicht übernommen</span>
          <?php endif ?>
          <div class="kopf"><strong><?= h($g['title'] ?? '') ?></strong>
            <?php if ($art): ?><span class="chip <?= h($art) ?>"><?= ['neu' => 'neu', 'gleich' => 'unverändert', 'geaendert' => 'geändert'][$art] ?></span><?php endif ?>
            <?php if (isset($ed[$sha1])): ?> <span class="chip">bearbeitet</span><?php endif ?>
            <span class="meta"><?= h(implode(' · ', array_filter([$g['year'] ?? '', $g['publisher'] ?? '', $g['developer'] ?? '', implode(', ', $g['genres'] ?? [])]))) ?></span></div>
          <div class="meta"><?= h(implode(', ', $g['files'] ?? [])) ?> · <?= h($g['format'] ?? '') ?> · SHA-1 <?= h($sha1) ?></div>
          <?php if ($art === 'geaendert'): ?>
            <details open><summary class="meta">Unterschiede zum Datenbestand</summary>
            <table class="vergleich"><tr><th></th><td class="meta">bisher</td><td class="meta">eingereicht</td></tr>
              <?php foreach ($v['felder'] as $f => [$alt, $neuw]): ?>
                <tr><th><?= h(VG_FELDER[$f]) ?></th><td><del><?= $alt === '' ? '–' : h($alt) ?></del></td><td><ins><?= $neuw === '' ? '–' : h($neuw) ?></ins></td></tr>
              <?php endforeach ?>
              <?php foreach ($v['bilder'] as $ba => [$alt, $neuw]): ?>
                <tr><th><?= h(VG_BILDARTEN[$ba]) ?></th>
                  <td><?= $alt === '' ? '<del>–</del>' : '<img src="?standbild=' . h(rawurlencode($alt)) . '" alt="bisher">' ?></td>
                  <td><?= $neuw === '' ? '<ins>– (fällt weg)</ins>' : '<img src="?id=' . (int)$b['id'] . '&amp;bild=' . h(rawurlencode($neuw)) . '" alt="neu">' ?></td></tr>
              <?php endforeach ?>
            </table></details>
          <?php endif ?>
          <?php [$tde, $ten] = texte($g); ?>
          <?php if ($tde !== ''): ?><div><span class="chip">DE</span> <?= nl2br(h($tde)) ?></div><?php endif ?>
          <?php if ($ten !== ''): ?><div lang="en"><span class="chip">EN</span> <?= nl2br(h($ten)) ?></div><?php endif ?>
          <div class="bilder"><?php foreach (($g['images'] ?? []) as $art => $bild): ?>
            <img src="?id=<?= (int)$b['id'] ?>&amp;bild=<?= h(rawurlencode($bild)) ?>" alt="<?= h($art) ?>" title="<?= h($art) ?>">
          <?php endforeach ?></div>
          <?php if ($neu): ?>
            <details class="bearbeiten">
              <summary>Bearbeiten</summary>
              <form method="post">
                <input type="hidden" name="token" value="<?= h($token) ?>">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <input type="hidden" name="sha1" value="<?= h($sha1) ?>">
                <label>Titel <input name="title" value="<?= h($g['title'] ?? '') ?>" maxlength="200" required></label>
                <div class="reihe">
                  <label>Jahr <input name="year" value="<?= h($g['year'] ?? '') ?>" maxlength="20"></label>
                  <label>Spieler <input name="players" value="<?= h($g['players'] ?? '') ?>" maxlength="50"></label>
                </div>
                <label>Herausgeber <input name="publisher" value="<?= h($g['publisher'] ?? '') ?>" maxlength="200"></label>
                <label>Entwickler <input name="developer" value="<?= h($g['developer'] ?? '') ?>" maxlength="200"></label>
                <label>Genres (mit Komma getrennt) <input name="genres" value="<?= h(implode(', ', $g['genres'] ?? [])) ?>"></label>
                <label>Beschreibung (Deutsch) <textarea name="synopsis" rows="5" maxlength="20000"><?= h($tde) ?></textarea></label>
                <label>Beschreibung (Englisch) <textarea name="synopsis_en" rows="5" maxlength="20000" lang="en"><?= h($ten) ?></textarea></label>
                <?php if (!empty($orig['images'])): ?>
                  <div class="meta">Bilder weglassen:
                    <?php foreach ($orig['images'] as $art => $bild): ?>
                      <label><input type="checkbox" name="drop[]" value="<?= h($art) ?>" <?= in_array($art, $ed[$sha1]['drop_images'] ?? [], true) ? 'checked' : '' ?>> <?= h(['sstitle' => 'Titelbild', 'ss' => 'Spielszene', 'box-2D' => 'Verpackung'][$art] ?? $art) ?></label>
                    <?php endforeach ?>
                  </div>
                <?php endif ?>
                <button name="bearbeiten" value="1">Änderungen speichern</button>
                <?php if (isset($ed[$sha1])): ?><button name="verwerfen" value="1" formnovalidate>Änderungen verwerfen</button><?php endif ?>
              </form>
            </details>
          <?php endif ?>
        </div>
      <?php endforeach ?>
      <?php if ($neu): ?>
        <form method="post" id="f<?= (int)$b['id'] ?>">
          <p class="meta">Haken weg = dieses Spiel nicht übernehmen. „Annehmen“ übernimmt nur die angehakten Spiele, in der bearbeiteten Fassung; der ZIP-Download enthält dann genau das.</p>
          <input type="hidden" name="token" value="<?= h($token) ?>">
          <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
          <textarea name="notiz" rows="2" placeholder="Notiz (z. B. Grund der Ablehnung)"></textarea>
          <button name="entscheidung" value="angenommen" class="ok">Annehmen</button>
          <button name="entscheidung" value="abgelehnt" class="bad">Ablehnen</button>
        </form>
      <?php else: ?>
        <p class="meta"><?= h($b['status']) ?> am <?= h((string)$b['entschieden']) ?><?= $b['notiz'] !== '' ? ': ' . h($b['notiz']) : '' ?><?= !empty($b['uebernommen']) ? ' · ins Daten-Repository übernommen am ' . h((string)$b['uebernommen']) : ($b['status'] === 'angenommen' ? ' · noch nicht im Daten-Repository' : '') ?></p>
      <?php endif ?>
    </section>
  <?php endforeach ?>
<?php endif ?>
</main></body>
</html>
