<?php
// Vorprüfung eingereichter Spiele: feste Regeln, die Auffälligkeiten als Hinweise melden (nichts wird abgelehnt).
// Pre-check of submitted games: fixed rules that report anomalies as hints (nothing is rejected).
//
// Jeder Hinweis: ['stufe' => 'rot'|'gelb'|'info', 'regel' => kurzer Schlüssel, 'text' => Satz für die Prüfseite].
//   rot  = spricht gegen das Übernehmen (Link zu Download-Seiten, Spam, leeres Bild …)
//   gelb = bitte ansehen (fehlende Sprache, vielleicht kein Spiel, Genre unbekannt …)
//   info = nur zur Einordnung (Titel gibt es schon im Bestand …)
// Anleitung: server/VORPRUEFUNG.md
declare(strict_types=1);
require_once __DIR__ . '/pruefen.php';
require_once __DIR__ . '/vergleich.php';

const VP_VERSION = 1;

// Genre-Teile, die die App kennt und übersetzt (ui/i18n.js, GENRE_EN). Genres stehen als „Ober / Unter“.
const VP_GENRES = ['Plattform', "Jump 'n' Run", "Shoot'em Up", 'Horizontal', 'Vertikal', 'Action', 'Labyrinth', 'Abenteuer',
    'Breakout Spiele', 'Text', 'Grafik', 'Strategie', 'Puzzle', 'Fallen', 'Überlegung', 'Rollenspiele', 'Rennen', 'Fahren',
    'Simulation', 'Sport', 'Shooter', "Beat'em Up", 'Kartenspiele', 'Brettspiele', 'Flipper', 'Gelegenheitsspiel',
    'Geschicklichkeit', 'Arcade', 'Sammlung', 'Lernspiel', 'Quiz', 'Kampf', 'Flug', 'Musik', 'Verschiedenes'];

// Bekannte Download-, ROM- und Warez-Seiten sowie Filehoster (Teil des Domainnamens genügt).
const VP_WAREZ = ['romsfun', 'romspedia', 'emuparadise', 'romsmania', 'romhustler', 'coolrom', 'freeroms', 'vimm.net',
    'loveroms', 'retrostic', 'romulation', 'wowroms', 'romsgames', 'planetemu', 'romstation', 'cdromance', 'edgeemu',
    'rapidgator', 'uploaded.net', 'mega.nz', 'mega.co.nz', 'mediafire', 'zippyshare', '1fichier', 'rutracker',
    'thepiratebay', 'piratebay', 'torrent', 'nyaa', 'uptobox', 'turbobit', 'nitroflare', 'katfile', 'filefactory',
    'sendspace', 'dropbox.com', 'drive.google', 'wetransfer', 'anonfiles', 'gofile', 'pixeldrain', 'krakenfiles'];
// Seiten der C64-Szene mit Downloads (keine Warez-Seiten, aber Hausregel: keine Links zu Cracks in Einträgen).
const VP_SZENE = ['csdb.dk', 'gb64.com', 'gamebase64', 'c64.com', 'lemon64', 'c64games.de', 'archive.org', 'itch.io',
    'commodore.software', 'c64-wiki', 'zzap64', 'mobygames', 'screenscraper', 'youtube', 'youtu.be', 'github'];

/** Text klein, ohne Akzente, nur Buchstaben/Ziffern/Leerzeichen. */
function vp_norm(string $s): string
{
    $s = mb_strtolower($s);
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'à' => 'a', 'á' => 'a',
        'ó' => 'o', 'ô' => 'o', 'í' => 'i', 'ñ' => 'n', 'ç' => 'c']);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';
    return trim($s);
}

/** Titel zum Vergleichen: ohne [Zusätze] und (Klammern), ohne Artikel. */
function vp_titel(string $t): string
{
    $t = preg_replace('/\s*[\[(][^\])]*[\])]/u', '', $t) ?? $t;
    $t = preg_replace('/\s+\+\s*[0-9a-z]*\s*$/iu', '', $t) ?? $t;
    $t = preg_replace('/\s+v?\d+(\.\d+)+[a-z]?\s*$/iu', '', $t) ?? $t;
    $t = vp_norm($t);
    return trim(preg_replace('/^(the|der|die|das) /', '', $t) ?? $t);
}

/** Anteil von Stoppwörtern je Sprache – grobe Spracherkennung ohne Erweiterung. */
function vp_sprache(string $text): array
{
    static $w = [
        'de' => ['der', 'die', 'das', 'und', 'ist', 'nicht', 'mit', 'ein', 'eine', 'auf', 'den', 'dem', 'sich', 'von', 'zu', 'im', 'wird', 'auch', 'als', 'werden'],
        'en' => ['the', 'and', 'is', 'of', 'to', 'in', 'with', 'a', 'an', 'on', 'for', 'you', 'your', 'it', 'by', 'are', 'from', 'as', 'game', 'be'],
        'fr' => ['le', 'la', 'les', 'et', 'des', 'du', 'un', 'une', 'est', 'dans', 'pour', 'vous', 'sur', 'au', 'qui', 'jeu', 'pas', 'votre'],
        'es' => ['el', 'los', 'las', 'y', 'es', 'del', 'una', 'por', 'con', 'para', 'juego', 'que', 'su', 'se'],
        'it' => ['il', 'gli', 'e', 'di', 'della', 'che', 'una', 'per', 'con', 'gioco', 'sono', 'nel'],
        'pt' => ['o', 'os', 'as', 'e', 'do', 'da', 'um', 'uma', 'com', 'para', 'jogo', 'que', 'nao'],
        'nl' => ['de', 'het', 'een', 'en', 'van', 'is', 'met', 'op', 'voor', 'spel', 'je', 'niet', 'zijn'],
        'hu' => ['a', 'az', 'es', 'egy', 'hogy', 'nem', 'jatek', 'meg', 'is', 'van'],
        'pl' => ['i', 'w', 'na', 'jest', 'sie', 'z', 'do', 'nie', 'gra', 'to'],
    ];
    $woerter = explode(' ', vp_norm($text));
    $n = max(1, count($woerter));
    $erg = [];
    foreach ($w as $sp => $liste) {
        $treffer = 0;
        foreach ($woerter as $x) {
            if (in_array($x, $liste, true)) {
                $treffer++;
            }
        }
        $erg[$sp] = $treffer / $n;
    }
    arsort($erg);
    return $erg;
}

function vp_hinweis(array &$h, string $stufe, string $regel, string $text): void
{
    foreach ($h as $x) {
        if ($x['regel'] === $regel && $x['text'] === $text) {
            return;
        }
    }
    $h[] = ['stufe' => $stufe, 'regel' => $regel, 'text' => $text];
}

/** Sucht Links, Domains, E-Mail-Adressen, Spam und Beleidigungen in einem Text. Für Spielfelder und Beitragsangaben. */
function vp_inhalt(string $feld, string $text, array &$h): void
{
    if (trim($text) === '') {
        return;
    }
    $klein = mb_strtolower($text);
    $domains = [];
    if (preg_match_all('#\b(?:https?://|www\.)\S+|\b[a-z0-9][a-z0-9-]{1,62}(?:\.[a-z0-9-]{2,62})*\.(?:com|net|org|de|ru|info|io|xyz|to|cc|me|ws|biz|tk|nz|co|uk|eu|fr|it|nl|pl|hu|se|dk|at|ch|app|site|online|top|link|ly|gg|tv)\b(?:/\S*)?#iu', $text, $m)) {
        $domains = array_unique($m[0]);
    }
    foreach ($domains as $d) {
        $dk = mb_strtolower($d);
        $art = 'gelb';
        $was = 'Link/Adresse';
        foreach (VP_WAREZ as $w) {
            if (str_contains($dk, $w)) {
                $art = 'rot';
                $was = 'Link zu Download-/Warez-Seite';
            }
        }
        if ($art === 'gelb') {
            foreach (VP_SZENE as $w) {
                if (str_contains($dk, $w)) {
                    $was = 'Link (Szene-/Infoseite, Hausregel: keine Links in Einträgen)';
                    // „erschien auf itch.io“ ist eine Plattformangabe, kein Link
                    if (!preg_match('#^(https?://|www\.)|/#', $dk)) {
                        $art = 'info';
                        $was = 'Seitenname ohne Link';
                    }
                }
            }
        }
        vp_hinweis($h, $art, 'link', "$feld: $was: " . mb_substr($d, 0, 80));
    }
    if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $text, $m)) {
        vp_hinweis($h, 'gelb', 'link', "$feld: E-Mail-Adresse: " . $m[0]);
    }
    if (preg_match('/\b(warez|keygen|serial ?key|nocd|no-cd crack|download (here|now|link)|direct download|hier herunterladen|vollversion gratis)\b/iu', $klein, $m)) {
        vp_hinweis($h, 'rot', 'download', "$feld: Download-/Warez-Wort „{$m[0]}“");
    } elseif ($domains && preg_match('/\b(free download|kostenlos herunterladen|full version|download)\b/iu', $klein, $m)) {
        vp_hinweis($h, 'gelb', 'download', "$feld: „{$m[0]}“ zusammen mit einem Link");
    }
    if (preg_match('/\b(viagra|cialis|porn\w*|xxx|sex ?cam|onlyfans|forex|kredit sofort|click here|klicken sie hier|buy now|jetzt kaufen|promo ?code|gutscheincode|seo service|casino bonus|free spins|whatsapp \+?\d)\b/iu', $klein, $m)) {
        vp_hinweis($h, 'rot', 'spam', "$feld: Werbe-/Spamwort „{$m[0]}“");
    }
    if (preg_match('/\b(fuck\w*|shit\w*|cunt|bitch\w*|asshole|motherfucker|nigg\w*|faggot|retard\w*|arschloch|wichser|hurensohn|fotze|missgeburt|spast\w*|schwuchtel|neger|kanake|heil hitler|sieg heil|88 ?hh)\b/iu', $klein, $m)) {
        vp_hinweis($h, 'rot', 'beleidigung', "$feld: beleidigendes Wort „{$m[0]}“");
    }
    // Zeichensalat: lange Wiederholungen, kaum Buchstaben, sehr lange „Wörter“, alles groß
    $buchst = preg_match_all('/\p{L}/u', $text);
    $len = mb_strlen($text);
    if ($len >= 30 && $buchst / $len < 0.55) {
        vp_hinweis($h, 'gelb', 'unsinn', "$feld: wenig Buchstaben – Zeichensalat?");
    }
    if (preg_match('/(.)\1{6,}/u', $text) || preg_match('/\b\p{L}{35,}\b/u', $text)) {
        vp_hinweis($h, 'gelb', 'unsinn', "$feld: Zeichenwiederholung oder überlanges Wort – Unsinn?");
    }
    if ($len >= 40 && mb_strtoupper($text) === $text && $buchst > 20) {
        vp_hinweis($h, 'gelb', 'unsinn', "$feld: nur Großbuchstaben");
    }
}

/** Bewertungen, Meinungen, Kommentar-Angaben und KI-Floskeln im Beschreibungstext. */
function vp_wertung(string $sp, string $text, array &$h): void
{
    $muster = [
        'wertung' => '/\b(beste[nrs]?(?![- ](?:bekannt|verkauf))|großartig\w*|genial\w*|fantastisch\w*|hervorragend\w*|meisterwerk\w*|meisterhaft|kultspiel|muss man (gespielt|haben)|unbedingt (spielen|anschauen)|empfehlenswert|sehr zu empfehlen|lohnt sich|top-?spiel|spitzenspiel|süchtig machend|macht (riesig |viel |großen )?spaß|langweilig\w*|enttäuschend\w*|miserabel|grottig|schlecht gemacht|wunderschön\w*|atemberaubend\w*|must-?have|masterpiece|awesome|great game|one of the best(?![- ](?:known|selling|remembered))|best games?(?! of the year awards)|highly recommended|a must|brilliant\w*|excellent|superb|addictive|boring|disappointing|terrible|stunning|beautiful graphics|gorgeous|fun to play|lots of fun|worth (playing|a try)|underrated|overrated|unterschätzt|überbewertet)\b/iu',
        'note' => '/\b(\d{1,2}(?:[.,]\d)?\s?(?:\/|von|out of)\s?(?:10|100|5)\b|\d\s?sterne|\d\s?stars|wertung:|rating:|score:|punkte von)/iu',
        'kommentar' => '/\b(laut (den )?kommentar\w*|in den kommentaren|kommentatoren|nutzer (meinen|sagen|schreiben)|users? (say|said|report)|commenters?|according to (the )?comments|in the comments|reviews? (say|praise)|kritiker (loben|meinen))\b/iu',
        'ki_werkzeug' => '/\b(chatgpt|gpt-?\d[\w.]*|claude|gemini|copilot|midjourney|stable diffusion|dall-?e|elevenlabs|suno)\b/iu',
        'ki' => '/\b(als (ein )?ki\b|als sprachmodell|ki-generiert|von einer ki|as an ai|ai language model|language model|ai-generated|generated by ai|i hope this helps|ich hoffe,? (das|dies) hilft|hier ist (eine|die) (beschreibung|zusammenfassung)|here is (a|the) (description|summary)|certainly!|gerne!|in summary,|zusammenfassend lässt sich)\b/iu',
    ];
    $namen = ['wertung' => 'Bewertung/Meinung', 'note' => 'Note/Wertung', 'kommentar' => 'Angabe aus Kommentaren',
        'ki' => 'KI-Floskel', 'ki_werkzeug' => 'KI-Werkzeug erwähnt (Mitwirkende?)'];
    foreach ($muster as $regel => $re) {
        if (preg_match($re, $text, $m)) {
            vp_hinweis($h, $regel === 'ki' ? 'rot' : 'gelb', $regel,
                "Text $sp: {$namen[$regel]} „" . mb_substr($m[0], 0, 60) . '“' . ($regel === 'ki_werkzeug' ? '' : ' (Hausregel: Einträge bewerten nichts)'));
        }
    }
}

// Art eines Eintrags (Nutzerentscheidung 2026-11-27): Demos, Intros usw. werden aufgenommen, aber als solche
// gekennzeichnet; Genres gelten nur für Spiele. Cracktros werden nicht aufgenommen.
// Feldname und Werte werden mit dem App-Teil abgestimmt (Sitzung „Breadbin: fehlende Funktionen prüfen“).
const ART_FELD = 'kind';
const VP_ARTEN = ['game' => 'Spiel', 'demo' => 'Demo', 'intro' => 'Intro', 'music' => 'Musik', 'diskmag' => 'Diskmag',
    'tool' => 'Werkzeug', 'graphics' => 'Grafik'];

/** Angegebene Art eines Eintrags ('game', wenn das Feld fehlt). */
function vp_art_von(array $g): string
{
    $a = strtolower(trim((string)($g[ART_FELD] ?? '')));
    return $a === '' ? 'game' : $a;
}

/** Prüft, ob die angegebene Art zu Titel, Dateiname, Genre und Text passt. */
function vp_art(array $g, string $de, string $en, array &$h): void
{
    $art = vp_art_von($g);
    if (!isset(VP_ARTEN[$art])) {
        vp_hinweis($h, 'gelb', 'art', "Art „{$art}“ unbekannt (erlaubt: " . implode(', ', array_keys(VP_ARTEN)) . ')');
    }
    $muster = [
        'cracktro' => '/\b(crack ?intros?|cracktros?)\b/iu',
        'demo' => '/\b(demo|demos|onefiler|one-?filer|rolling demo|megademo|trackmo)\b/iu',
        'intro' => '/\b(intro|intros|4k|1k|256b|512b)\b/iu',
        'music' => '/\b(music ?disk|musik ?disk|musicdisk|sid ?tunes?|tunes|soundtrack|music collection|musiksammlung)\b/iu',
        'tool' => '/\b(tool|tools|utility|utilities|editor|assembler|monitor|copier|kopierprogramm|packer|cruncher)\b/iu',
        'diskmag' => '/\b(disk ?mag|diskmag|magazin|magazine|newsletter)\b/iu',
        'graphics' => '/\b(slide ?show|picture|pictures|bild(er)?sammlung|grafik ?sammlung|koala|art ?disk|gfx ?collection|charset|font)\b/iu',
    ];
    $quellen = [
        'Titel' => (string)($g['title'] ?? ''),
        'Datei' => preg_replace('/[_.]/', ' ', implode(' ', (array)($g['files'] ?? []))) ?? '',
        'Genre' => implode(' ', (array)($g['genres'] ?? [])),
    ];
    $gefunden = [];
    // „(Demo)“ bzw. „(Demo 0.9)“ im Titel = Probefassung eines Spiels, keine Szene-Demo
    if (preg_match('/[\[(]\s*(demo|preview)\b[^\])]*[\])]/iu', $quellen['Titel'], $m)) {
        vp_hinweis($h, 'info', 'vorab', "Probefassung eines Spiels („{$m[0]}“) – Art bleibt Spiel");
        $quellen['Titel'] = str_replace($m[0], '', $quellen['Titel']);
    }
    foreach ($quellen as $wo => $text) {
        foreach ($muster as $a => $re) {
            if (preg_match($re, $text, $m)) {
                $gefunden[$a][] = "$wo „{$m[0]}“";
            }
        }
    }
    // Im Text nur deutliche Aussagen, sonst meldet jede „Demo-Version“-Erwähnung.
    $reText = [
        'demo' => '/\b(this (is|was) a demo\b|(ist|war) eine demo\b|demo (by|von) )/iu',
        'intro' => '/\b(this (is|was) an? (\d+k |256b )?intro|(ist|war) ein (\d+k-)?intro)/iu',
        'music' => '/\b(this (is|was) a music ?disk|(ist|war) eine musikdisk)/iu',
        'tool' => '/\b(this (is|was) a (tool|utility)|(ist|war) ein (werkzeug|hilfsprogramm))/iu',
        'diskmag' => '/\b(this (is|was) a diskmag|(ist|war) ein diskmag)/iu',
        'graphics' => '/\b(this (is|was) a slide ?show|(ist|war) eine diashow)/iu',
        'party' => '/\b(released at the .{1,30} (party|compo)|auf der .{1,30}(party|compo) (veröffentlicht|erschienen))/iu',
    ];
    foreach (['DE' => $de, 'EN' => $en] as $sp => $t) {
        foreach ($reText as $a => $re) {
            if ($t !== '' && preg_match($re, $t, $m)) {
                $gefunden[$a][] = "Text $sp „" . mb_substr($m[0], 0, 50) . '“';
            }
        }
    }
    if (isset($gefunden['cracktro'])) {
        vp_hinweis($h, 'rot', 'cracktro', 'Cracktro? ' . implode(', ', $gefunden['cracktro']) . ' – Cracktros werden nicht aufgenommen');
    }
    if (isset($gefunden['cracktro'])) {
        unset($gefunden['intro']);
    }
    unset($gefunden['cracktro']);
    $party = $gefunden['party'] ?? null;
    unset($gefunden['party']);
    if ($art === 'game') {
        foreach ($gefunden as $a => $wo) {
            vp_hinweis($h, 'gelb', 'art', 'Als Spiel angegeben, aber Hinweis auf ' . VP_ARTEN[$a] . ' (' . implode(', ', $wo) . ') – Art prüfen');
        }
        if ($party && !$gefunden) {
            vp_hinweis($h, 'info', 'art', 'Party-Veröffentlichung (' . implode(', ', $party) . ') – Spiel oder Demo?');
        }
    } else {
        foreach ($gefunden as $a => $wo) {
            if ($a !== $art && !($art === 'demo' && $a === 'intro') && !($art === 'intro' && $a === 'demo')) {
                vp_hinweis($h, 'info', 'art', 'Als ' . (VP_ARTEN[$art] ?? $art) . ' angegeben, Hinweis auf ' . VP_ARTEN[$a] . ' (' . implode(', ', $wo) . ')');
            }
        }
        if (!empty($g['genres'])) {
            vp_hinweis($h, 'gelb', 'art', 'Als ' . (VP_ARTEN[$art] ?? $art) . ' angegeben, hat aber Genres – Genres gelten nur für Spiele (oder ist es doch ein Spiel?)');
        }
        if (trim((string)($g['players'] ?? '')) !== '') {
            vp_hinweis($h, 'gelb', 'art', 'Als ' . (VP_ARTEN[$art] ?? $art) . ' angegeben, hat aber eine Spielerzahl – gilt nur für Spiele');
        }
        if (trim((string)($g['group'] ?? '')) === '') {
            vp_hinweis($h, 'info', 'art', 'Gruppe fehlt (Feld group)');
        }
    }
    // Felder für Nicht-Spiele (wie die App, docs/Austauschformat.md): group, party (Text ≤ 200), place (1–999)
    foreach (['group', 'party'] as $f) {
        if (isset($g[$f]) && !is_string($g[$f])) {
            vp_hinweis($h, 'gelb', 'art', "Feld $f muss Text sein");
        } elseif (isset($g[$f])) {
            if (mb_strlen($g[$f]) > 200) {
                vp_hinweis($h, 'gelb', 'art', "Feld $f länger als 200 Zeichen (die App liest das nicht ein)");
            }
            vp_inhalt($f === 'group' ? 'Gruppe' : 'Party', $g[$f], $h);
        }
    }
    if (isset($g['place']) && !(is_int($g['place']) && $g['place'] >= 1 && $g['place'] <= 999)) {
        vp_hinweis($h, 'gelb', 'art', 'Platz (place) muss eine ganze Zahl von 1 bis 999 sein');
    }
    if ($art === 'game' && (isset($g['party']) || isset($g['place']))) {
        vp_hinweis($h, 'info', 'art', 'Spiel mit Party/Platz – Wettbewerbsbeitrag (Game Compo) oder doch eine Demo?');
    }
    // Vorabfassungen sind Spiele, sollen aber als solche benannt sein.
    if ($art === 'game' && preg_match('/\b(preview|vorschau|work ?in ?progress|wip|(?:beta|alpha)(?= ?(?:version|fassung|v?\d|\)|\]))|prototyp\w*|prototype|unfinished|unvollständig|test ?version|testfassung|vorabfassung|vorabversion)\b/iu',
            $quellen['Titel'] . ' ' . $quellen['Datei'], $m)) {
        vp_hinweis($h, 'info', 'vorab', "Vorabfassung („{$m[0]}“) – im Titel als solche benannt?");
    }
}

/** Titel passend zum Dateinamen? Großzügig: ein Titelwort im Dateinamen, Abkürzung oder gleicher Anfang genügt. */
function vp_titel_datei(string $titel, array $dateien): bool
{
    $t = vp_titel($titel);
    if ($t === '' || !$dateien) {
        return true;
    }
    $tz = str_replace(' ', '', $t);
    $woerter = array_filter(explode(' ', $t), fn($w) => strlen($w) >= 3 && !in_array($w, ['and', 'und', 'the', 'der', 'die', 'das', 'von', 'of'], true));
    $initialen = implode('', array_map(fn($w) => $w[0], explode(' ', $t)));
    // Römische Zahlen und Ziffern gleichsetzen
    $roem = ['ii' => '2', 'iii' => '3', 'iv' => '4', 'v' => '5'];
    foreach ($dateien as $d) {
        $dz = str_replace(' ', '', vp_norm(preg_replace('/\.[a-z0-9]{1,4}$/i', '', (string)$d) ?? (string)$d));
        if ($dz === '') {
            continue;
        }
        if (str_contains($dz, $tz) || str_contains($tz, $dz) || substr($dz, 0, 4) === substr($tz, 0, 4)) {
            return true;
        }
        foreach ($woerter as $w) {
            if (str_contains($dz, $w) || str_contains($dz, $roem[$w] ?? "\0")) {
                return true;
            }
        }
        if (strlen($initialen) >= 2 && str_contains($dz, $initialen)) {
            return true;
        }
        // 8.3-Namen: Anfang mit 3 Buchstaben
        if (strlen($dz) <= 8 && strlen($dz) >= 3 && str_starts_with($tz, substr($dz, 0, 3))) {
            return true;
        }
    }
    return false;
}

/** Bild ansehen: Format, Maße, leer/einfarbig. Ergebnis wird je Bild (Name = Prüfsumme) zwischengespeichert. */
function vp_bild_info(string $daten): array
{
    $info = ['ok' => true, 'typ' => '', 'b' => 0, 'h' => 0, 'farben' => null, 'anteil' => null, 'hell' => null];
    $gi = @getimagesizefromstring($daten);
    if ($gi === false) {
        return ['ok' => false] + $info;
    }
    $info['typ'] = (string)($gi['mime'] ?? '');
    $info['b'] = (int)$gi[0];
    $info['h'] = (int)$gi[1];
    if (function_exists('imagecreatefromstring')) {
        $im = @imagecreatefromstring($daten);
        if ($im !== false) {
            // Stichprobe 48×30 Punkte: Zahl der Farben, Anteil der häufigsten, mittlere Helligkeit
            $zaehl = [];
            $summe = 0;
            $n = 0;
            for ($y = 0; $y < 30; $y++) {
                for ($x = 0; $x < 48; $x++) {
                    $px = imagecolorat($im, (int)(($x + .5) * $info['b'] / 48), (int)(($y + .5) * $info['h'] / 30));
                    $rgb = imageistruecolor($im) ? $px & 0xFFFFFF : (function () use ($im, $px) {
                        $c = imagecolorsforindex($im, $px);
                        return ($c['red'] << 16) | ($c['green'] << 8) | $c['blue'];
                    })();
                    $zaehl[$rgb] = ($zaehl[$rgb] ?? 0) + 1;
                    $summe += (($rgb >> 16) & 255) + (($rgb >> 8) & 255) + ($rgb & 255);
                    $n++;
                }
            }
            imagedestroy($im);
            $info['farben'] = count($zaehl);
            $info['anteil'] = max($zaehl) / $n;
            $info['hell'] = $summe / $n / 3;
        }
    }
    return $info;
}

/** Hinweise zu den Bildern eines Spiels. $bildInfo: Pfad => vp_bild_info(). */
function vp_bilder(array $g, array $bildInfo, array &$h): void
{
    $namen = VG_BILDARTEN;
    $gesehen = [];
    foreach (bb_alle_bilder($g) as $art => $pfad) {
        $name = $namen[$art] ?? $art;
        if (isset($gesehen[$pfad])) {
            vp_hinweis($h, 'gelb', 'bild', "$name ist dasselbe Bild wie {$gesehen[$pfad]}");
        }
        $gesehen[$pfad] = $name;
        $i = $bildInfo[$pfad] ?? null;
        if ($i === null) {
            continue;
        }
        if (!$i['ok']) {
            vp_hinweis($h, 'rot', 'bild', "$name: Bild lässt sich nicht lesen");
            continue;
        }
        $b = $i['b'];
        $ho = $i['h'];
        if ($art !== 'box-2D') {
            if ($b === 320 && $ho === 240) {
                vp_hinweis($h, 'gelb', 'bild', "$name: 320×240 – so liefert ScreenScraper seine Bilder (eigene Aufnahme?)");
            } elseif ($b < 300 || $ho < 190) {
                vp_hinweis($h, 'gelb', 'bild', "$name: sehr klein ({$b}×{$ho})");
            } elseif ($b > 2000 || $ho > 2000) {
                vp_hinweis($h, 'gelb', 'bild', "$name: sehr groß ({$b}×{$ho}) – Foto statt Bildschirmaufnahme?");
            } elseif (!in_array("{$b}x{$ho}", ['320x200', '384x272', '403x284', '384x247', '402x292', '360x240', '384x288', '640x400', '768x544', '768x576', '640x480', '720x576', '1280x800', '1920x1080'], true)) {
                vp_hinweis($h, 'info', 'bild', "$name: ungewöhnliche Maße {$b}×{$ho}");
            }
        }
        if ($i['farben'] !== null) {
            if ($i['farben'] <= 1) {
                vp_hinweis($h, 'rot', 'bild', "$name: einfarbig – leeres oder schwarzes Bild");
            } elseif ($i['anteil'] > 0.97) {
                vp_hinweis($h, 'gelb', 'bild', "$name: fast einfarbig – leeres Bild oder nur Rahmen?");
            } elseif ($i['hell'] < 6) {
                vp_hinweis($h, 'gelb', 'bild', "$name: fast schwarz");
            }
        }
    }
}

/** Verzeichnis des Bestands für Dubletten: Titel, Texte, Bilder. Einmal je Seitenaufruf gebaut. */
function vp_index(?array $stand): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $idx = ['titel' => [], 'text' => [], 'bild' => []];
    foreach ($stand ?? [] as $sha1 => $g) {
        $t = vp_titel((string)($g['title'] ?? ''));
        $idx['titel'][$t][] = ['sha1' => $sha1, 'year' => (string)($g['year'] ?? ''), 'title' => (string)($g['title'] ?? '')];
        foreach (texte($g) as $tx) {
            $n = vp_norm($tx);
            if (strlen($n) >= 120) {
                $idx['text'][md5($n)][$t] = (string)($g['title'] ?? '');
            }
        }
        foreach (bb_alle_bilder($g) as $p) {
            $idx['bild'][$p][$t] = (string)($g['title'] ?? '');
        }
    }
    return $cache = $idx;
}

/**
 * Alle Hinweise zu einem (ggf. bearbeiteten) Spiel.
 * $andere: [sha1 => Spiel] der übrigen Spiele im selben Beitrag (für Dubletten innerhalb des Beitrags).
 */
function vorpruefe_spiel(array $g, string $sha1, ?array $stand, array $bildInfo, array $andere = []): array
{
    $h = [];
    [$de, $en] = texte($g);
    $titel = (string)($g['title'] ?? '');

    // 1. Beschreibung
    if ($de === '' && $en === '') {
        vp_hinweis($h, 'rot', 'text_fehlt', 'Beschreibung fehlt ganz');
    } elseif ($de === '') {
        vp_hinweis($h, 'gelb', 'text_de', 'Deutsche Beschreibung fehlt');
    } elseif ($en === '') {
        vp_hinweis($h, 'gelb', 'text_en', 'Englische Beschreibung fehlt');
    }
    if ($de !== '' && $en !== '' && vp_norm($de) === vp_norm($en)) {
        vp_hinweis($h, 'gelb', 'text_gleich', 'Deutsche und englische Beschreibung sind gleich');
    }
    foreach (['DE' => $de, 'EN' => $en] as $sp => $t) {
        if ($t === '') {
            continue;
        }
        if (mb_strlen($t) < 80) {
            vp_hinweis($h, 'gelb', 'text_kurz', "Text $sp sehr kurz (" . mb_strlen($t) . ' Zeichen)');
        }
        if (mb_strlen($t) >= 60) {
            $s = vp_sprache($t);
            $erste = array_key_first($s);
            $soll = strtolower($sp);
            if ($s[$erste] < 0.04) {
                vp_hinweis($h, 'gelb', 'sprache', "Text $sp: Sprache nicht erkennbar – Unsinn oder Wortliste?");
            } elseif ($erste !== $soll && $s[$erste] > ($s[$soll] ?? 0) * 1.5) {
                vp_hinweis($h, $erste === 'de' || $erste === 'en' ? 'gelb' : 'rot', 'sprache',
                    "Text $sp scheint " . ['de' => 'Deutsch', 'en' => 'Englisch', 'fr' => 'Französisch', 'es' => 'Spanisch',
                        'it' => 'Italienisch', 'pt' => 'Portugiesisch', 'nl' => 'Niederländisch', 'hu' => 'Ungarisch',
                        'pl' => 'Polnisch'][$erste] . ' zu sein');
            }
        }
        vp_wertung($sp, $t, $h);
        vp_inhalt("Text $sp", $t, $h);
    }

    // 2. Felder: Links, Spam, Unsinn
    foreach (['title' => 'Titel', 'publisher' => 'Herausgeber', 'developer' => 'Entwickler', 'year' => 'Jahr',
                 'players' => 'Spieler'] as $f => $name) {
        if (is_string($g[$f] ?? null)) {
            vp_inhalt($name, $g[$f], $h);
        }
    }
    foreach ((array)($g['genres'] ?? []) as $ge) {
        vp_inhalt('Genre', (string)$ge, $h);
    }

    // 3. Kein Spiel?
    vp_art($g, $de, $en, $h);
    $istSpiel = vp_art_von($g) === 'game';

    // 4. Plausibilität
    $jahr = trim((string)($g['year'] ?? ''));
    $heute = (int)date('Y');
    if ($jahr === '') {
        vp_hinweis($h, 'gelb', 'jahr', 'Jahr fehlt');
    } elseif (!preg_match('/^(19|20)\d\d$/', $jahr)) {
        vp_hinweis($h, 'gelb', 'jahr', "Jahr „{$jahr}“ ist keine Jahreszahl");
    } elseif ((int)$jahr < 1982) {
        vp_hinweis($h, 'gelb', 'jahr', "Jahr $jahr liegt vor dem C64 (1982) – Jahr der C64-Fassung?");
    } elseif ((int)$jahr > $heute + 1) {
        vp_hinweis($h, 'rot', 'jahr', "Jahr $jahr liegt in der Zukunft");
    }
    $sp = trim((string)($g['players'] ?? ''));
    if ($sp === '') {
        if ($istSpiel) {
            vp_hinweis($h, 'info', 'spieler', 'Spielerzahl fehlt');
        }
    } elseif (!preg_match('/^(\d{1,2})(-(\d{1,2}))?$/', $sp, $m)) {
        vp_hinweis($h, 'gelb', 'spieler', "Spieler „{$sp}“ – erwartet z. B. 1, 1-2, 2-4");
    } elseif ((int)$m[1] < 1 || (int)($m[3] ?? $m[1]) > 8 || (isset($m[3]) && (int)$m[3] < (int)$m[1])) {
        vp_hinweis($h, 'gelb', 'spieler', "Spielerzahl $sp unplausibel");
    }
    $genres = (array)($g['genres'] ?? []);
    if (!$genres && $istSpiel) {
        vp_hinweis($h, 'gelb', 'genre', 'Genre fehlt');
    }
    foreach ($genres as $ge) {
        $teile = array_map('trim', explode(' / ', (string)$ge));
        $fremd = array_filter($teile, fn($t) => !in_array($t, VP_GENRES, true));
        if ($fremd) {
            vp_hinweis($h, 'gelb', 'genre', "Genre „{$ge}“ steht nicht in der Genre-Liste der App");
        }
    }
    if (!vp_titel_datei($titel, (array)($g['files'] ?? []))) {
        $kurz = !array_filter((array)($g['files'] ?? []), fn($d) => strlen((string)preg_replace('/\.[^.]*$/', '', (string)$d)) > 8);
        vp_hinweis($h, $kurz ? 'info' : 'gelb', 'titel_datei', 'Titel „' . $titel . '“ passt nicht erkennbar zum Dateinamen ' . implode(', ', (array)($g['files'] ?? [])));
    }

    // 5. Dubletten und Abgleich mit dem Bestand
    $idx = vp_index($stand);
    $tn = vp_titel($titel);
    $gleiche = array_filter($idx['titel'][$tn] ?? [], fn($x) => $x['sha1'] !== $sha1);
    if ($gleiche) {
        $jahre = array_values(array_unique(array_filter(array_column($gleiche, 'year'))));
        vp_hinweis($h, 'info', 'bestand', 'Titel steht schon ' . count($gleiche) . '× im Bestand (andere Fassungen)'
            . ($jahre ? ', Jahr dort: ' . implode('/', $jahre) : ''));
        if ($jahr !== '' && $jahre && !in_array($jahr, $jahre, true)) {
            vp_hinweis($h, 'info', 'jahr', "Jahr $jahr weicht vom Bestand ab (" . implode('/', $jahre) . ')');
        }
    }
    foreach (['DE' => $de, 'EN' => $en] as $spr => $t) {
        $n = vp_norm($t);
        if (strlen($n) < 120) {
            continue;
        }
        $imBestand = array_values(array_filter($idx['text'][md5($n)] ?? [], fn($k) => (string)$k !== $tn, ARRAY_FILTER_USE_KEY));
        $imBeitrag = [];
        foreach ($andere as $osha => $og) {
            $ot = vp_titel((string)($og['title'] ?? ''));
            $ot = $ot === '' ? '-' : $ot;
            if ($osha !== $sha1 && $ot !== $tn && in_array($n, array_map('vp_norm', texte($og)), true)) {
                $imBeitrag[$ot] = (string)($og['title'] ?? '');
            }
        }
        foreach (['im Bestand' => $imBestand, 'im selben Beitrag' => array_values($imBeitrag)] as $wo => $liste) {
            if ($liste) {
                $namen = '„' . implode('“, „', array_slice($liste, 0, 3)) . '“' . (count($liste) > 3 ? ' und ' . (count($liste) - 3) . ' weiteren' : '');
                vp_hinweis($h, 'gelb', 'dublette', "Text $spr gleicht dem Text von $namen $wo");
            }
        }
    }
    foreach (bb_alle_bilder($g) as $art => $p) {
        foreach ($idx['bild'][$p] ?? [] as $ft => $fremdTitel) {
            if ((string)$ft !== $tn) {
                vp_hinweis($h, 'gelb', 'dublette', (VG_BILDARTEN[$art] ?? $art) . " ist schon Bild von „{$fremdTitel}“ im Bestand");
            }
        }
    }
    if (($g['screenscraper_id'] ?? '') !== '' && $de !== '' && $en === '' && vp_sprache($de)['fr'] > 0.05) {
        vp_hinweis($h, 'rot', 'screenscraper', 'Französischer Text mit ScreenScraper-Nummer – von ScreenScraper kopiert?');
    }

    // 6. Bilder
    vp_bilder($g, $bildInfo, $h);

    $rang = ['rot' => 0, 'gelb' => 1, 'info' => 2];
    usort($h, fn($a, $b) => $rang[$a['stufe']] <=> $rang[$b['stufe']]);
    return $h;
}

/** Hinweise zu den Angaben des Einsenders (Name, Nachricht). */
function vorpruefe_angaben(array $b): array
{
    $h = [];
    vp_inhalt('Name des Einsenders', (string)($b['name'] ?? ''), $h);
    vp_inhalt('Nachricht des Einsenders', (string)($b['hinweis'] ?? ''), $h);
    return $h;
}

/**
 * Bildangaben aller Bilder eines Beitrags, zwischengespeichert in <datei>.bilder.json (Bilder heißen nach ihrer
 * Prüfsumme, ändern sich also nie).
 */
function vp_bilder_beitrag(array $c, array $b, array $erg): array
{
    $quelle = $c['daten_dir'] . '/' . $b['datei'];
    $cachePfad = $quelle . '.bilder.json';
    $cache = is_file($cachePfad) ? (json_decode((string)file_get_contents($cachePfad), true) ?: []) : [];
    if (($cache['v'] ?? 0) !== VP_VERSION) {
        $cache = ['v' => VP_VERSION, 'bilder' => []];
    }
    $fehlt = array_diff($erg['bilder'] ?? [], array_keys($cache['bilder']));
    if ($fehlt && is_file($quelle)) {
        $zip = new ZipArchive();
        if ($zip->open($quelle, ZipArchive::RDONLY) === true) {
            $praefix = $erg['praefix'] ?? '';
            foreach ($fehlt as $rel) {
                $d = $zip->getFromName($praefix . $rel);
                $cache['bilder'][$rel] = $d === false ? ['ok' => false] : vp_bild_info($d);
            }
            $zip->close();
            @file_put_contents($cachePfad, json_encode($cache));
        }
    }
    return $cache['bilder'];
}

/**
 * Vorprüfung eines ganzen Beitrags (in der bearbeiteten Fassung).
 * Rückgabe: ['angaben' => Hinweise zum Einsender, 'spiele' => [sha1 => Hinweise], 'zahl' => ['rot' => n, 'gelb' => n]]
 */
function vorpruefe_beitrag(array $c, array $b, array $erg, array $edits, ?array $stand): array
{
    $bildInfo = vp_bilder_beitrag($c, $b, $erg);
    $spiele = [];
    foreach ($erg['spiele'] as $sha1 => $orig) {
        $spiele[$sha1] = mit_edits($orig, $edits[$sha1] ?? null);
    }
    $out = ['angaben' => vorpruefe_angaben($b), 'spiele' => [], 'zahl' => ['rot' => 0, 'gelb' => 0, 'sauber' => 0]];
    foreach ($spiele as $sha1 => $g) {
        $h = vorpruefe_spiel($g, (string)$sha1, $stand, $bildInfo, $spiele);
        $out['spiele'][$sha1] = $h;
        $stufen = array_column($h, 'stufe');
        if (in_array('rot', $stufen, true)) {
            $out['zahl']['rot']++;
        } elseif (in_array('gelb', $stufen, true)) {
            $out['zahl']['gelb']++;
        } else {
            $out['zahl']['sauber']++;
        }
    }
    return $out;
}

// ---- Durchsicht durch Claude: Empfehlung je Spiel, gespeichert in <datei>.claude.json ----

const VP_EMPFEHLUNGEN = ['uebernehmen' => 'übernehmen', 'nachbessern' => 'nachbessern', 'ablehnen' => 'ablehnen'];

function durchsicht_pfad(array $c, array $b): string
{
    return $c['daten_dir'] . '/' . basename((string)$b['datei']) . '.claude.json';
}

function durchsicht(array $c, array $b): ?array
{
    $p = durchsicht_pfad($c, $b);
    $d = is_file($p) ? json_decode((string)file_get_contents($p), true) : null;
    return is_array($d) ? $d : null;
}

/** Prüft und bereinigt eine eingesandte Durchsicht. Gibt [bereinigt, Fehler] zurück. */
function durchsicht_bereinigen(array $roh, array $sha1s): array
{
    $spiele = [];
    foreach ((array)($roh['spiele'] ?? []) as $sha1 => $e) {
        if (!in_array($sha1, $sha1s, true) || !is_array($e)) {
            continue;
        }
        $emp = (string)($e['empfehlung'] ?? '');
        if (!isset(VP_EMPFEHLUNGEN[$emp])) {
            return [null, "Empfehlung „{$emp}“ unbekannt (übernehmen/nachbessern/ablehnen: uebernehmen, nachbessern, ablehnen)"];
        }
        $spiele[$sha1] = ['empfehlung' => $emp, 'grund' => mb_substr(trim((string)($e['grund'] ?? '')), 0, 1500)];
    }
    if (!$spiele) {
        return [null, 'Keine Empfehlung zu einem Spiel dieses Beitrags'];
    }
    return [[
        'zeit' => gmdate('Y-m-d\TH:i:s\Z'),
        'zusammenfassung' => mb_substr(trim((string)($roh['zusammenfassung'] ?? '')), 0, 3000),
        'bericht' => preg_match('#^https://claude\.ai/[A-Za-z0-9/_-]+$#', (string)($roh['bericht'] ?? '')) ? (string)$roh['bericht'] : '',
        'spiele' => $spiele,
    ], null];
}

// ---- Texte von Claude: Ergänzung einer fehlenden Sprache und neue Texte auf Auftrag ----
// Die Texte landen als Bearbeitung in <datei>.edits.json (wie Bearbeitungen auf der Prüfseite), dazu je Spiel
// `bearbeitet_von` und `claude_texte` ({de|en: 'ergaenzt'|'neu', zeit}). Nutzerentscheidung 2026-11-27: Bearbeitungen
// durch den Betreiber UND durch Claude werden in den Daten immer als „Breadbin“ geführt, nie als „Claude“.
// Aufträge („Texte von Claude schreiben lassen“ auf der Prüfseite) stehen in <datei>.auftraege.json als Liste von SHA-1.

/** Name für „bearbeitet von“ in den Daten (config.php: betreiber_name, Standard Breadbin). */
function vp_bearbeiter(array $c): string
{
    return (string)($c['betreiber_name'] ?? 'Breadbin');
}

function auftraege_pfad(array $c, array $b): string
{
    return $c['daten_dir'] . '/' . basename((string)$b['datei']) . '.auftraege.json';
}

function auftraege(array $c, array $b): array
{
    $p = auftraege_pfad($c, $b);
    $a = is_file($p) ? json_decode((string)file_get_contents($p), true) : null;
    return is_array($a) ? array_values(array_filter($a, fn($x) => is_string($x) && preg_match('/^[0-9a-f]{40}$/', $x))) : [];
}

function auftraege_speichern(array $c, array $b, array $liste): void
{
    $liste = array_values(array_unique($liste));
    if ($liste) {
        file_put_contents(auftraege_pfad($c, $b), json_encode($liste));
    } elseif (is_file(auftraege_pfad($c, $b))) {
        unlink(auftraege_pfad($c, $b));
    }
}

/** Fügt einen Namen zu `bearbeitet_von` einer Bearbeitung hinzu. */
function bearbeitet_von(array $e, string $wer): array
{
    $liste = is_array($e['bearbeitet_von'] ?? null) ? $e['bearbeitet_von'] : [];
    if (!in_array($wer, $liste, true)) {
        $liste[] = $wer;
    }
    $e['bearbeitet_von'] = $liste;
    return $e;
}

/**
 * Legt von Claude geschriebene Texte als Bearbeitung ab. $texte: [sha1 => ['de' => …, 'en' => …]].
 * Füllt nur Sprachen, die in der aktuellen (bearbeiteten) Fassung leer sind – nie wird ein vorhandener Text ersetzt.
 * Rückgabe: [abgelegt (Zahl Sprachen), übersprungen (Liste von Gründen)].
 */
function claude_texte_ablegen(array $c, array $b, array $erg, array $texte): array
{
    $ed = edits($c, $b);
    $auf = auftraege($c, $b);
    $n = 0;
    $weg = [];
    foreach ($texte as $sha1 => $t) {
        if (!isset($erg['spiele'][$sha1]) || !is_array($t)) {
            $weg[] = "$sha1: nicht im Beitrag";
            continue;
        }
        [$de, $en] = texte(mit_edits($erg['spiele'][$sha1], $ed[$sha1] ?? null));
        $vorher = ['de' => $de, 'en' => $en];
        $e = $ed[$sha1] ?? [];
        $neu = $de === '' && $en === '';
        $gesetzt = false;
        foreach (['de' => 'synopsis', 'en' => 'synopsis_en'] as $sp => $feld) {
            $text = trim(str_replace("\r", '', (string)($t[$sp] ?? '')));
            if ($text === '') {
                continue;
            }
            if ($vorher[$sp] !== '') {
                $weg[] = "$sha1: Text " . strtoupper($sp) . ' ist schon vorhanden';
                continue;
            }
            $e[$feld] = mb_substr($text, 0, EDIT_FELDER[$feld]);
            // Die andere Sprache unverändert festhalten, damit mit_edits() sie nicht verliert.
            $anderes = $sp === 'de' ? 'synopsis_en' : 'synopsis';
            if (!array_key_exists($anderes, $e)) {
                $e[$anderes] = $vorher[$sp === 'de' ? 'en' : 'de'];
            }
            $e['claude_texte'][$sp] = $neu ? 'neu' : 'ergaenzt';
            $n++;
            $gesetzt = true;
        }
        if ($gesetzt) {
            $e['claude_texte']['zeit'] = gmdate('Y-m-d\TH:i:s\Z');
            $ed[$sha1] = bearbeitet_von($e, vp_bearbeiter($c));
            $auf = array_values(array_diff($auf, [$sha1]));
        }
    }
    file_put_contents(edits_pfad($c, $b), json_encode($ed, JSON_UNESCAPED_UNICODE));
    auftraege_speichern($c, $b, $auf);
    return [$n, $weg];
}

/**
 * Legt fehlende Angaben (aus Assembly64, CSDb, pouet) als Bearbeitung ab. $felder: [sha1 => ['kind'?, 'group'?,
 * 'party'?, 'place'?, 'year'?, 'developer'?, 'quellen'?]]. Gefüllt wird nur, was in der aktuellen Fassung leer ist; die
 * Art nur, wenn bisher „Spiel“ gilt (dann fallen Genres und Spielerzahl weg). Rückgabe: [Zahl Felder, übersprungen].
 */
function claude_felder_ablegen(array $c, array $b, array $erg, array $felder): array
{
    $ed = edits($c, $b);
    $n = 0;
    $weg = [];
    foreach ($felder as $sha1 => $f) {
        if (!isset($erg['spiele'][$sha1]) || !is_array($f)) {
            $weg[] = "$sha1: nicht im Beitrag";
            continue;
        }
        $g = mit_edits($erg['spiele'][$sha1], $ed[$sha1] ?? null);
        $e = $ed[$sha1] ?? [];
        $gesetzt = [];
        $kind = strtolower(trim((string)($f['kind'] ?? '')));
        if ($kind !== '') {
            if (!in_array($kind, EDIT_ARTEN, true) || $kind === 'game') {
                $weg[] = "$sha1: Art „{$kind}“ nicht erlaubt";
            } elseif (vp_art_von($g) !== 'game') {
                $weg[] = "$sha1: Art ist schon angegeben";
            } else {
                $e['kind'] = $kind;
                $e['genres'] = [];
                $e['players'] = '';
                $gesetzt[] = 'Art';
            }
        }
        foreach (['group' => 'Gruppe', 'party' => 'Party', 'year' => 'Jahr', 'developer' => 'Mitwirkende'] as $feld => $name) {
            $wert = trim(str_replace(["\r", "\n"], ' ', (string)($f[$feld] ?? '')));
            if ($wert === '') {
                continue;
            }
            if (trim((string)($g[$feld] ?? '')) !== '') {
                $weg[] = "$sha1: $name ist schon angegeben";
                continue;
            }
            $e[$feld] = mb_substr($wert, 0, $feld === 'year' ? 20 : 200);
            $gesetzt[] = $name;
        }
        if (isset($f['place']) && $f['place'] !== '') {
            $p = (int)$f['place'];
            if ($p < 1 || $p > 999) {
                $weg[] = "$sha1: Platz außerhalb 1–999";
            } elseif (isset($g['place'])) {
                $weg[] = "$sha1: Platz ist schon angegeben";
            } else {
                $e['place'] = $p;
                $gesetzt[] = 'Platz';
            }
        }
        if ($gesetzt) {
            $e['claude_felder'] = ['felder' => array_values(array_unique(array_merge($e['claude_felder']['felder'] ?? [], $gesetzt))),
                'quellen' => mb_substr(trim((string)($f['quellen'] ?? '')), 0, 300), 'zeit' => gmdate('Y-m-d\TH:i:s\Z')];
            $ed[$sha1] = bearbeitet_von($e, vp_bearbeiter($c));
            $n += count($gesetzt);
        }
    }
    file_put_contents(edits_pfad($c, $b), json_encode($ed, JSON_UNESCAPED_UNICODE));
    return [$n, $weg];
}
