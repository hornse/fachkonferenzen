<?php
/**
 * Fachkonferenzen – tests/sperrliste_test.php
 * Die Sperrliste im Router: was Apache sperrt, liefert auch php -S nicht aus
 *
 * Anlass: S63 (koordination, BEFUNDE-SAMMLUNG-2026-11). Der Router liefert
 * jede Datei unter frontend/ aus, ohne Liste der Endungen. Heute liegt dort
 * keine solche Datei (Server 03.11.2026: /.htaccess 404); lokal gemessen
 * gingen erfundene Punkt- und .bak-Dateien mit Inhalt hinaus.
 * Auftrag: koordination docs/AUFTRAG-router-sperrliste.md, Lauf 3.
 * Gebaut nach dem Vorbild aus projektstunden (Lauf 1).
 *
 * DIE LISTE IST ZWEIMAL DA – in der Apache-Vorlage und in
 * backend/sperrliste.php. Gemessen wird die zweite gegen die erste: Die
 * Endungen und die Punktregel werden aus der BEIGELEGTEN KOPIE
 * tests/daten/webroot.htaccess gelesen, nicht hier aufgezählt. Ob die Kopie
 * noch der Quelle entspricht, misst der Bestandslauf in koordination – nicht
 * diese Prüfung.
 *
 * Vier Teile:
 *   A  die Sperrfunktion, je Endung aus der Kopie, klein und GROSS
 *   B  die Punktregel, je Probe gegen den Ausdruck aus der Kopie
 *   C  die WIRKUNG: der Router (byte-gleiche Kopie in einem Probebaum) unter
 *      php -S mit Arbeitsverzeichnis / wie im Dienst, je Art eine Testdatei –
 *      erwartet 404 ohne Inhalt; Kontrollen 200. Dazu //x (parse_url liefert
 *      dort null) → 200 mit genau der Startseite, und /api/… erreicht den
 *      API-Zweig, auch mit gesperrter Endung.
 *   D  was nicht verlorengehen darf: jede verfolgte Datei unter frontend/
 *      durch den echten Router, 200 und Größe gleich der Datei
 *   E  Aufrufstelle und Reihenfolge im Quelltext (Tokenizer) – nur Rückfall
 *      zu C, misst Quelltext, nicht Wirkung
 *
 * Die Testdateinamen in C und die Kontrollen in A sind ERFUNDEN.
 *
 * Aufruf: php tests/sperrliste_test.php   → Exit-Code 0 = alles grün
 */

declare(strict_types=1);

ini_set('display_errors', 'stderr');

$fehler = 0;
function pruefe(string $name, bool $ok): void
{
    global $fehler;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . PHP_EOL;
    if (!$ok) $fehler++;
}
set_exception_handler(function (Throwable $e): void {
    echo '  ✗ Suite abgebrochen: ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
    exit(1);
});

$WURZEL = dirname(__DIR__);
$KOPIE  = __DIR__ . '/daten/webroot.htaccess';
$SPERR  = $WURZEL . '/backend/sperrliste.php';
$ROUTER = $WURZEL . '/backend/router.php';

// ------------------------------------------------------------------
// Die Kopie lesen
// ------------------------------------------------------------------
if (!is_file($KOPIE)) {
    pruefe('beigelegte Kopie tests/daten/webroot.htaccess vorhanden', false);
    exit(1);
}
$vorlage = (string)file_get_contents($KOPIE);

// <FilesMatch "(?i)(\.(save(\.[0-9]+)?|swp|…|sh)|~)$">
$endungen = [];
$unbekannt = [];
if (preg_match('#<FilesMatch "\(\?i\)\(\\\\\.\((.+)\)\|~\)\$">#', $vorlage, $m) === 1) {
    foreach (explode('|', $m[1]) as $teil) {
        if (preg_match('/^[a-z0-9]+$/', $teil) === 1) {
            $endungen[] = '.' . $teil;
        } elseif (preg_match('/^([a-z0-9]+)\(\\\\\.\[0-9\]\+\)\?$/', $teil, $n) === 1) {
            $endungen[] = '.' . $n[1];
            $endungen[] = '.' . $n[1] . '.7';      // .save.N, N erfunden
        } else {
            $unbekannt[] = $teil;
        }
    }
    $endungen[] = '~';
}
pruefe('Kopie: ' . count($endungen) . ' Endungen gelesen (null wäre ein Fehler)'
    . ($unbekannt ? ', unbekannte Form: ' . implode(' ', $unbekannt) : ''),
    count($endungen) > 0 && $unbekannt === []);

// <If "%{REQUEST_URI} =~ m#/\.(?!well-known(/|$))#">
$punktRegel = null;
if (preg_match('/<If "%\{REQUEST_URI\} =~ m#(.+)#">/', $vorlage, $m) === 1) {
    $punktRegel = '#' . $m[1] . '#';
}
pruefe('Kopie: Punktregel gelesen' . ($punktRegel !== null ? " ($punktRegel)" : ''),
    $punktRegel !== null && @preg_match($punktRegel, '') !== false);

// ------------------------------------------------------------------
// A – die Sperrfunktion
// ------------------------------------------------------------------
if (is_file($SPERR)) require_once $SPERR;
$hatFunktion = is_callable('fk_pfad_gesperrt');
pruefe('backend/sperrliste.php definiert fk_pfad_gesperrt()', $hatFunktion);
$gesperrt = fn(string $p): bool => $hatFunktion ? fk_pfad_gesperrt($p) : false;

foreach ($endungen as $e) {
    pruefe("Sperrfunktion: $e gesperrt (klein und GROSS)",
        $gesperrt('/probe' . $e) && $gesperrt('/PROBE' . strtoupper($e)));
}
foreach (['/app.js', '/index.html', '/vendor/ci-css/ci-tokens.css',
          '/probe.bak.css', '/bak', '/endung-sh'] as $p) {
    pruefe("Sperrfunktion: $p frei", $hatFunktion && !$gesperrt($p));
}

// ------------------------------------------------------------------
// B – die Punktregel, gegen den Ausdruck der Kopie
// ------------------------------------------------------------------
$proben = ['/.htaccess', '/a/.git/config', '/x/.DS_Store', '/..',
           '/.well-known/probe.css', '/.well-known', '/.well-knownx/y',
           '/a/b.css', '/a.b/c.css'];
$ausgaenge = [];
foreach ($proben as $p) {
    $soll = $punktRegel !== null && preg_match($punktRegel, $p) === 1;
    $ausgaenge[$soll ? 'gesperrt' : 'frei'] = true;
    pruefe("Punktregel wie Vorlage: $p " . ($soll ? 'gesperrt' : 'frei'),
        $punktRegel !== null && $hatFunktion && $gesperrt($p) === $soll);
}
// Ein Vergleich prüft vorher seine Operanden: beide Ausgänge kommen vor.
pruefe('Punktregel: die Proben enthalten gesperrte und freie Pfade',
    isset($ausgaenge['gesperrt'], $ausgaenge['frei']));

// ------------------------------------------------------------------
// php -S starten und abfragen
// ------------------------------------------------------------------
function freier_port(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
    if ($s === false) throw new RuntimeException("kein freier Port: $es");
    $name = (string)stream_socket_get_name($s, false);
    fclose($s);
    return (int)substr($name, strrpos($name, ':') + 1);
}
/** @return array{0: resource, 1: int} */
function server_starten(string $wurzel): array
{
    // Arbeitsverzeichnis / wie im Dienst (ini ohne directory=, gemessen
    // 03.11.2026 am laufenden Prozess), Router über seinen absoluten Pfad.
    $port = freier_port();
    $p = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$wurzel/backend/router.php"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'],
         2 => ['file', '/dev/null', 'w']], $rohre, '/');
    if (!is_resource($p)) throw new RuntimeException("php -S in $wurzel nicht gestartet");
    for ($i = 0; $i < 50; $i++) {
        $f = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
        if ($f !== false) { fclose($f); return [$p, $port]; }
        usleep(100000);
    }
    proc_terminate($p);
    throw new RuntimeException("php -S in $wurzel antwortet nicht auf Port $port");
}
/** Rohe Anfrage: die URI geht unverändert hinaus. @return array{0:int,1:string} */
function abrufen(int $port, string $uri): array
{
    $f = fsockopen('127.0.0.1', $port, $en, $es, 5);
    if ($f === false) throw new RuntimeException("Abruf $uri: $es");
    fwrite($f, "GET $uri HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
    $roh = (string)stream_get_contents($f);
    fclose($f);
    [$kopf, $rumpf] = array_pad(explode("\r\n\r\n", $roh, 2), 2, '');
    preg_match('#^HTTP/\S+ (\d{3})#', $kopf, $m);
    return [(int)($m[1] ?? 0), $rumpf];
}
function baum_loeschen(string $d): void
{
    if (!is_dir($d)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $x) { $x->isDir() ? rmdir((string)$x) : unlink((string)$x); }
    rmdir($d);
}

// ------------------------------------------------------------------
// C – die Wirkung: der Router im Probebaum unter php -S
// ------------------------------------------------------------------
// Der Probebaum hat denselben Aufbau wie das Projekt (backend/, frontend/),
// der Router und die Sperrdatei sind byte-gleiche Kopien. Der Router findet
// frontend/ über dirname(__DIR__) und landet so im Probebaum.
$T = realpath(sys_get_temp_dir()) . '/fk-sperrliste-' . getmypid();
baum_loeschen($T);
mkdir("$T/backend/api", 0700, true);
mkdir("$T/frontend", 0700, true);
copy($ROUTER, "$T/backend/router.php");
if (is_file($SPERR)) copy($SPERR, "$T/backend/sperrliste.php");
// Erfundene Stellvertreter mit Kennung: die Startseite und der API-Zweig.
file_put_contents("$T/frontend/index.html", 'SPERRPROBE-STARTSEITE');
file_put_contents("$T/backend/api/index.php", '<?php echo "SPERRPROBE-API";');

$dateien = [];          // Pfad unter frontend/ => Kennung im Inhalt
$nr = 0;
foreach ($endungen as $e) {
    $dateien['klein' . $e] = 'SPERRPROBE-' . (++$nr);
    $dateien['GROSS' . strtoupper($e)] = 'SPERRPROBE-' . (++$nr);
}
foreach (['.htaccess', 'a/.git/config', 'unter/.versteckt/datei.css',
          '.well-known/probe.css', 'kontrolle.css'] as $p) {
    $dateien[$p] = 'SPERRPROBE-' . (++$nr);
}
foreach ($dateien as $p => $kennung) {
    if (!is_dir(dirname("$T/frontend/$p"))) mkdir(dirname("$T/frontend/$p"), 0700, true);
    file_put_contents("$T/frontend/$p", $kennung);
}

/** 404 und keine Zeichenfolge aus der Datei (REIHENREGELN 6: Inhalt, nicht nur Code). */
$verweigert = function (int $port, string $uri, string $kennung): bool {
    [$code, $rumpf] = abrufen($port, $uri);
    return $code === 404 && !str_contains($rumpf, $kennung);
};
$geliefert = function (int $port, string $uri, string $kennung): bool {
    [$code, $rumpf] = abrufen($port, $uri);
    return $code === 200 && $rumpf === $kennung;
};

$server = null;
try {
    [$server, $port] = server_starten($T);
    pruefe('Probebaum: php -S gestartet', true);
    foreach ($endungen as $e) {
        $k = 'klein' . $e;
        $g = 'GROSS' . strtoupper($e);
        pruefe("Router: $e → 404 ohne Inhalt (klein und GROSS)",
            $verweigert($port, "/$k", $dateien[$k]) && $verweigert($port, "/$g", $dateien[$g]));
    }
    foreach (['.htaccess', 'a/.git/config', 'unter/.versteckt/datei.css'] as $p) {
        pruefe("Router: /$p → 404 ohne Inhalt", $verweigert($port, "/$p", $dateien[$p]));
    }
    // Kodiert: php -S reicht die URI roh durch (beobachtet 02.11.2026), realpath
    // findet keine Datei, der SPA-Rückfall antwortet – je nach Basisname 200 mit
    // index.html oder 404. Gemessen wird deshalb nur: kein Inhalt aus der Datei.
    $ohneInhalt = function (int $port, string $uri, string $kennung): bool {
        [, $rumpf] = abrufen($port, $uri);
        return !str_contains($rumpf, $kennung);
    };
    pruefe('Router: /%2Ehtaccess (kodierter Punkt) → kein Inhalt aus der Datei',
        $ohneInhalt($port, '/%2Ehtaccess', $dateien['.htaccess']));
    pruefe('Router: /klein.%62ak (kodierte Endung) → kein Inhalt aus der Datei',
        $ohneInhalt($port, '/klein.%62ak', $dateien['klein.bak']));
    pruefe('Router: /.well-known/probe.css → 200 mit Inhalt (Ausnahme)',
        $geliefert($port, '/.well-known/probe.css', $dateien['.well-known/probe.css']));
    pruefe('Router: /kontrolle.css → 200 mit Inhalt (Kontrolle)',
        $geliefert($port, '/kontrolle.css', $dateien['kontrolle.css']));
    // parse_url('//x', PHP_URL_PATH) ist null. Erwartet: 200 mit GENAU der
    // Startseite – kein 500 aus der Sperrfunktion, kein Deprecated im Rumpf.
    pruefe('Router: //x (Pfad null) → 200 mit genau der Startseite',
        $geliefert($port, '//x', 'SPERRPROBE-STARTSEITE'));
    pruefe('Router: //x/.htaccess (Pfad /.htaccess) → 404 ohne Inhalt',
        $verweigert($port, '//x/.htaccess', $dateien['.htaccess']));
    // Der API-Zweig steht vor der Sperre: ein freies Segment mit gesperrter
    // Endung geht an die API, nicht in die 404.
    pruefe('Router: /api/probe.bak → API-Zweig (vor der Sperre)',
        $geliefert($port, '/api/probe.bak', 'SPERRPROBE-API'));
} catch (RuntimeException $e) {
    pruefe('Probebaum: ' . $e->getMessage(), false);
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    baum_loeschen($T);
}

// ------------------------------------------------------------------
// D – was nicht verlorengehen darf: der echte Router, die echten Dateien
// ------------------------------------------------------------------
$verfolgt = array_values(array_filter(explode("\n", (string)shell_exec(
    'git -C ' . escapeshellarg($WURZEL) . ' ls-files frontend 2>/dev/null'))));
$server = null;
try {
    [$server, $port] = server_starten($WURZEL);
    $falsch = [];
    foreach ($verfolgt as $f) {
        [$code, $rumpf] = abrufen($port, substr($f, strlen('frontend')));
        if ($code !== 200 || strlen($rumpf) !== filesize("$WURZEL/$f")) {
            $falsch[] = "$f: $code, " . strlen($rumpf) . ' Bytes';
        }
    }
    pruefe('echter Router: alle ' . count($verfolgt) . ' verfolgten Dateien unter frontend/ 200,'
        . ' Größe gleich der Datei (null wäre ein Fehler)',
        count($verfolgt) > 0 && $falsch === []);
    foreach ($falsch as $z) echo "      $z" . PHP_EOL;
} catch (RuntimeException $e) {
    pruefe('echter Router: ' . $e->getMessage(), false);
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
}

// ------------------------------------------------------------------
// E – Aufrufstelle und Reihenfolge (Quelltext; die Wirkung misst C)
// ------------------------------------------------------------------
$code = [];
foreach (token_get_all((string)file_get_contents($ROUTER)) as $t) {
    if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) continue;
    $code[] = is_array($t) ? $t[1] : $t;
}
$aufrufe = [];
$ersterReadfile = null;
foreach ($code as $i => $w) {
    $folgt = $code[$i + 1] ?? '';
    if ($w === 'fk_pfad_gesperrt' && $folgt === '(') $aufrufe[] = $i;
    if ($w === 'readfile' && $folgt === '(' && $ersterReadfile === null) $ersterReadfile = $i;
}
pruefe('router.php ruft fk_pfad_gesperrt() genau einmal (Code, nicht Kommentar/Zeichenkette): '
    . count($aufrufe), count($aufrufe) === 1);
pruefe('router.php: die Sperre steht vor dem ersten readfile() (Reihenfolge im Quelltext)',
    count($aufrufe) === 1 && $ersterReadfile !== null && $aufrufe[0] < $ersterReadfile);

exit($fehler === 0 ? 0 : 1);
