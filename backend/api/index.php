<?php
// ============================================================
// api/index.php – API-Router + alle Handler
// Routen siehe docs/INSTALL.md bzw. README.md
// ============================================================

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/auth/WebUntisAuth.php';
require __DIR__ . '/sync.php';

set_exception_handler(function (Throwable $e) {
    error_log('[API] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_err('Interner Fehler: ' . $e->getMessage(), 500);
});

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$seg    = array_values(array_filter(explode('/', substr($path, strlen('/api')))));
// Beispiel: /api/planungen/3/slots  ->  ['planungen','3','slots']

// ============================================================
// AUTH
// ============================================================
if ($seg === ['auth', 'login'] && $method === 'POST') {
    $b = body_json();
    $quelle = $b['quelle'] ?? 'webuntis';
    if ($quelle === 'lokal') {
        login_lokal((string)($b['email'] ?? ''), (string)($b['passwort'] ?? ''));
    }
    login_webuntis((string)($b['benutzername'] ?? ''), (string)($b['passwort'] ?? ''));
}

if ($seg === ['auth', 'logout'] && $method === 'POST') {
    session_destroy();
    json_out(['ok' => true]);
}

if ($seg === ['auth', 'me'] && $method === 'GET') {
    require_auth();
    json_out(current_user());
}

function login_lokal(string $email, string $passwort): void
{
    $st = db()->prepare("SELECT * FROM benutzer WHERE email = ? AND typ = 'lokal' AND aktiv = 1");
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u || !password_verify($passwort, $u['passwort_hash'] ?? '')) {
        json_err('E-Mail oder Passwort falsch', 401);
    }
    session_regenerate_id(true);
    $_SESSION['benutzer_id'] = (int)$u['id'];
    $_SESSION['lehrer_id']   = $u['lehrer_id'] !== null ? (int)$u['lehrer_id'] : null;
    $_SESSION['kuerzel']     = $u['kuerzel'];
    $_SESSION['name']        = $u['name'];
    $_SESSION['rolle']       = $u['rolle'];
    $_SESSION['auth_quelle'] = 'lokal';
    json_out(current_user());
}

function login_webuntis(string $benutzername, string $passwort): void
{
    $cfg = config('webuntis');
    if (!($cfg['enabled'] ?? false)) json_err('WebUntis-Login ist deaktiviert', 400);
    if ($benutzername === '' || $passwort === '') json_err('Benutzername und Passwort angeben', 400);

    $wu = new WebUntisAuth($cfg['base_url'], $cfg['school'], $cfg['client']);
    try {
        $auth = $wu->authenticate($benutzername, $passwort);
    } catch (RuntimeException $e) {
        json_err('WebUntis-Anmeldung fehlgeschlagen: ' . $e->getMessage(), 401);
    }

    $personType = (int)($auth['personType'] ?? 0);
    $personId   = (int)($auth['personId'] ?? 0);
    if (!in_array($personType, $cfg['allowed_person_types'], true)) {
        $wu->logout();
        json_err('Dieser Kontotyp ist für diese Anwendung nicht freigeschaltet', 403);
    }

    $kuerzel = null; $name = null; $rolle = 'lehrkraft'; $webuntisId = $personId;

    if ($personType === 2) {
        // Lehrkraft: Kürzel + Name aus getTeachers() (JSESSIONID nötig)
        try {
            foreach ($wu->getTeachers() as $t) {
                if ((int)($t['id'] ?? 0) === $personId) {
                    $kuerzel = $t['name'] ?? null;
                    $name    = trim(($t['foreName'] ?? '') . ' ' . ($t['longName'] ?? ''));
                    break;
                }
            }
        } catch (Throwable $e) { /* Name bleibt ggf. leer */ }
    } elseif ($personType === 16) {
        // WebUntis-Admin: personId = -1, KEIN Eintrag in getTeachers()
        // -> Kürzel aus Konfiguration, Name aus DB nachschlagen
        $rolle      = 'admin';
        $kuerzel    = $cfg['admin_kuerzel'][0] ?? null;
        $webuntisId = null;
    }
    $wu->logout();

    // Lehrer-Stammsatz suchen (per webuntis_id, sonst per Kürzel)
    $lehrer = null;
    if ($webuntisId !== null) {
        $st = db()->prepare('SELECT * FROM lehrer WHERE webuntis_id = ?');
        $st->execute([$webuntisId]);
        $lehrer = $st->fetch() ?: null;
    }
    if ($lehrer === null && $kuerzel !== null) {
        $st = db()->prepare('SELECT * FROM lehrer WHERE kuerzel = ?');
        $st->execute([$kuerzel]);
        $lehrer = $st->fetch() ?: null;
    }
    if ($lehrer !== null) {
        $kuerzel = $kuerzel ?? $lehrer['kuerzel'];
        if ($name === null || $name === '') {
            $name = trim(($lehrer['vorname'] ?? '') . ' ' . ($lehrer['nachname'] ?? ''));
        }
    }
    if ($kuerzel !== null && in_array($kuerzel, $cfg['admin_kuerzel'] ?? [], true)) {
        $rolle = 'admin';
    }

    session_regenerate_id(true);
    // WebUntis-Nutzer ohne lokalen Account: benutzer_id = 0 (NIE empty() prüfen!)
    $_SESSION['benutzer_id'] = 0;
    $_SESSION['lehrer_id']   = $lehrer !== null ? (int)$lehrer['id'] : null;
    $_SESSION['kuerzel']     = $kuerzel;
    $_SESSION['name']        = ($name !== null && $name !== '') ? $name : ($kuerzel ?? 'Unbekannt');
    $_SESSION['rolle']       = $rolle;
    $_SESSION['auth_quelle'] = 'webuntis';
    json_out(current_user());
}

// ============================================================
// STAMMDATEN (Admin) – lehrer, faecher, fachgruppen, raeume
// ============================================================
if (($seg[0] ?? '') === 'lehrer') {
    require_admin();
    if ($method === 'GET' && count($seg) === 1) {
        json_out(db()->query('SELECT * FROM lehrer ORDER BY kuerzel')->fetchAll());
    }
    if ($method === 'POST' && $seg === ['lehrer', 'bulk-aktiv']) {
        bulk_aktiv('lehrer');
    }
    if ($method === 'POST' && count($seg) === 1) {
        $b = body_json();
        $st = db()->prepare('INSERT INTO lehrer (kuerzel, vorname, nachname, webuntis_id, aktiv)
                             VALUES (?,?,?,?,1)');
        $st->execute([req($b,'kuerzel'), $b['vorname'] ?? '', $b['nachname'] ?? '',
                      $b['webuntis_id'] ?? null]);
        json_out(['id' => (int)db()->lastInsertId()], 201);
    }
    if ($method === 'PATCH' && count($seg) === 2) {
        patch_row('lehrer', (int)$seg[1], ['kuerzel','vorname','nachname','webuntis_id','aktiv']);
    }
}

if (($seg[0] ?? '') === 'faecher') {
    require_auth();
    if ($method === 'GET' && count($seg) === 1) {
        json_out(db()->query(
            'SELECT f.*, g.name AS gruppe_name
               FROM faecher f LEFT JOIN fachgruppen g ON g.id = f.gruppe_id
              ORDER BY f.name')->fetchAll());
    }
    require_admin();
    if ($method === 'POST' && $seg === ['faecher', 'bulk-aktiv']) {
        bulk_aktiv('faecher');
    }
    if ($method === 'POST' && count($seg) === 1) {
        $b = body_json();
        $st = db()->prepare('INSERT INTO faecher (kuerzel, name, webuntis_id, gruppe_id, aktiv)
                             VALUES (?,?,?,?,1)');
        $st->execute([req($b,'kuerzel'), req($b,'name'),
                      $b['webuntis_id'] ?? null, $b['gruppe_id'] ?? null]);
        json_out(['id' => (int)db()->lastInsertId()], 201);
    }
    if ($method === 'PATCH' && count($seg) === 2) {
        patch_row('faecher', (int)$seg[1], ['kuerzel','name','webuntis_id','gruppe_id','aktiv']);
    }
}

if (($seg[0] ?? '') === 'fachgruppen') {
    require_admin();
    if ($method === 'GET' && count($seg) === 1) {
        json_out(db()->query('SELECT * FROM fachgruppen ORDER BY name')->fetchAll());
    }
    if ($method === 'POST' && count($seg) === 1) {
        $b = body_json();
        $st = db()->prepare('INSERT INTO fachgruppen (name) VALUES (?)');
        $st->execute([req($b,'name')]);
        json_out(['id' => (int)db()->lastInsertId()], 201);
    }
    if ($method === 'PATCH' && count($seg) === 2) {
        patch_row('fachgruppen', (int)$seg[1], ['name']);
    }
    if ($method === 'DELETE' && count($seg) === 2) {
        db()->prepare('UPDATE faecher SET gruppe_id = NULL WHERE gruppe_id = ?')->execute([(int)$seg[1]]);
        db()->prepare('DELETE FROM fachgruppen WHERE id = ?')->execute([(int)$seg[1]]);
        json_out(['ok' => true]);
    }
}

if (($seg[0] ?? '') === 'raeume') {
    require_admin();
    if ($method === 'GET' && count($seg) === 1) {
        json_out(db()->query('SELECT * FROM raeume ORDER BY kuerzel')->fetchAll());
    }
    if ($method === 'POST' && count($seg) === 1) {
        $b = body_json();
        $st = db()->prepare('INSERT INTO raeume (kuerzel, name, webuntis_id, aktiv) VALUES (?,?,?,1)');
        $st->execute([req($b,'kuerzel'), $b['name'] ?? '', $b['webuntis_id'] ?? null]);
        json_out(['id' => (int)db()->lastInsertId()], 201);
    }
    if ($method === 'PATCH' && count($seg) === 2) {
        patch_row('raeume', (int)$seg[1], ['kuerzel','name','webuntis_id','aktiv']);
    }
}

// ============================================================
// LEHRER-FACH-ZUORDNUNG
// ============================================================
if (($seg[0] ?? '') === 'lehrer-fach') {
    require_admin();
    if ($method === 'GET' && count($seg) === 1) {
        json_out(db()->query(
            'SELECT lf.*, l.kuerzel AS lehrer_kuerzel, l.vorname, l.nachname,
                    f.kuerzel AS fach_kuerzel, f.name AS fach_name
               FROM lehrer_fach lf
               JOIN lehrer  l ON l.id = lf.lehrer_id
               JOIN faecher f ON f.id = lf.fach_id
              ORDER BY l.kuerzel, f.name')->fetchAll());
    }
    if ($method === 'POST' && count($seg) === 1) {
        $b = body_json();
        $st = db()->prepare(
            "INSERT INTO lehrer_fach (lehrer_id, fach_id, quelle, gesperrt)
             VALUES (?,?,'manuell',0)
             ON DUPLICATE KEY UPDATE quelle = 'manuell'");
        $st->execute([(int)req($b,'lehrer_id'), (int)req($b,'fach_id')]);
        json_out(['ok' => true], 201);
    }
    if ($method === 'PATCH' && count($seg) === 2) {
        patch_row('lehrer_fach', (int)$seg[1], ['gesperrt','quelle']);
    }
    if ($method === 'DELETE' && count($seg) === 2) {
        db()->prepare('DELETE FROM lehrer_fach WHERE id = ?')->execute([(int)$seg[1]]);
        json_out(['ok' => true]);
    }
}

// ============================================================
// WEBUNTIS-SYNC
// Body: { benutzername, passwort, von: 'YYYY-MM-DD', bis: 'YYYY-MM-DD',
//         modus: 'vorschau' | 'uebernehmen' }
// Zugangsdaten werden NICHT gespeichert, nur für diesen Sync benutzt.
// ============================================================
if ($seg === ['sync', 'webuntis'] && $method === 'POST') {
    require_admin();
    // Auch bei Proxy-Timeout/Verbindungsabbruch zu Ende laufen:
    ignore_user_abort(true);
    set_time_limit(0);

    $b   = body_json();
    $cfg = config('webuntis');
    $von = preg_replace('/\D/', '', (string)req($b, 'von'));
    $bis = preg_replace('/\D/', '', (string)req($b, 'bis'));
    if (strlen($von) !== 8 || strlen($bis) !== 8) json_err('von/bis im Format YYYY-MM-DD angeben');
    $modus = ($b['modus'] ?? 'vorschau') === 'uebernehmen' ? 'uebernehmen' : 'vorschau';

    // Übernehmen nutzt die Daten der letzten Vorschau (max. 15 Min. alt,
    // gleicher Zeitraum) -> kein zweiter langer WebUntis-Abruf nötig.
    $cache = $_SESSION['sync_cache'] ?? null;
    $cacheGueltig = is_array($cache)
        && ($cache['von'] ?? '') === $von && ($cache['bis'] ?? '') === $bis
        && (time() - ($cache['zeit'] ?? 0)) < 900;

    if ($modus === 'uebernehmen' && $cacheGueltig) {
        $teachers   = $cache['teachers'];
        $subjects   = $cache['subjects'];
        $rooms      = $cache['rooms'];
        $paarKeys   = $cache['paare'];
        $fehler     = $cache['fehler'];
        $datenquelle = 'vorschau_zwischenspeicher';
    } else {
        $wu = new WebUntisAuth($cfg['base_url'], $cfg['school'], $cfg['client']);
        try {
            $wu->authenticate((string)req($b, 'benutzername'), (string)req($b, 'passwort'));
            $teachers = $wu->getTeachers();
            $subjects = $wu->getSubjects();
            $rooms    = [];
            try { $rooms = $wu->getRooms(); } catch (Throwable $e) { /* optional */ }

            // Lehrer-Fach-Paare aus dem Stundenplan: 1 Aufruf pro Fach (type=3)
            $paare  = [];   // "webuntisLehrerId|webuntisFachId" => true
            $fehler = [];
            foreach ($subjects as $s) {
                $sid = (int)$s['id'];
                try {
                    foreach ($wu->getTimetable(3, $sid, $von, $bis) as $periode) {
                        if (($periode['lstype'] ?? 'ls') !== 'ls') continue; // nur Unterricht
                        foreach (($periode['te'] ?? []) as $te) {
                            $tid = (int)($te['id'] ?? 0);
                            if ($tid > 0) $paare["$tid|$sid"] = true;
                        }
                    }
                } catch (Throwable $e) {
                    $fehler[] = 'Fach ' . ($s['name'] ?? $sid) . ': ' . $e->getMessage();
                }
            }
            $paarKeys = array_keys($paare);
        } finally {
            $wu->logout();
        }
        $datenquelle = 'webuntis_live';
    }

    if ($modus === 'vorschau') {
        $_SESSION['sync_cache'] = [
            'von' => $von, 'bis' => $bis, 'zeit' => time(),
            'teachers' => $teachers, 'subjects' => $subjects, 'rooms' => $rooms,
            'paare' => $paarKeys, 'fehler' => $fehler,
        ];
    }

    $ergebnis = sync_anwenden($teachers, $subjects, $rooms, $paarKeys, $modus);
    $ergebnis['zeitraum']    = ['von' => $von, 'bis' => $bis];
    $ergebnis['fehler']      = $fehler;
    $ergebnis['modus']       = $modus;
    $ergebnis['datenquelle'] = $datenquelle;

    if ($modus === 'uebernehmen') {
        unset($_SESSION['sync_cache']);
        $st = db()->prepare('INSERT INTO sync_protokoll (kuerzel, aktion, details) VALUES (?,?,?)');
        $st->execute([$_SESSION['kuerzel'] ?? '?', 'webuntis_sync',
                      json_encode($ergebnis, JSON_UNESCAPED_UNICODE)]);
    }
    json_out($ergebnis);
}

// ============================================================
// CSV-IMPORT (Fallback): Zeilen "Kuerzel;Fachkuerzel"
// Body: { csv: "Hor;M\nHor;IF\nMei;PH\n..." }
// ============================================================
if ($seg === ['import', 'csv'] && $method === 'POST') {
    require_admin();
    $b = body_json();
    $zeilen = preg_split('/\r\n|\r|\n/', (string)req($b, 'csv'));

    // mb_strtolower nur nutzen, wenn mbstring vorhanden (robust ggü. PHP-Setup)
    $klein = function_exists('mb_strtolower')
        ? fn(string $s) => mb_strtolower($s)
        : fn(string $s) => strtolower($s);

    $lehrer = []; $faecher = [];
    foreach (db()->query('SELECT id, kuerzel FROM lehrer')->fetchAll() as $r)
        $lehrer[$klein($r['kuerzel'])] = (int)$r['id'];
    foreach (db()->query('SELECT id, kuerzel FROM faecher')->fetchAll() as $r)
        $faecher[$klein($r['kuerzel'])] = (int)$r['id'];

    $ins = db()->prepare(
        "INSERT INTO lehrer_fach (lehrer_id, fach_id, quelle, gesperrt)
         VALUES (?,?,'csv',0)
         ON DUPLICATE KEY UPDATE lehrer_id = lehrer_id");

    $ok = 0; $uebersprungen = [];
    foreach ($zeilen as $nr => $zeile) {
        $zeile = trim($zeile);
        if ($zeile === '' || str_starts_with($zeile, '#')) continue;
        $teile = preg_split('/[;,\t]/', $zeile);
        if (count($teile) < 2) { $uebersprungen[] = 'Zeile ' . ($nr + 1) . ': Format'; continue; }
        $lid = $lehrer[$klein(trim($teile[0]))] ?? null;
        $fid = $faecher[$klein(trim($teile[1]))] ?? null;
        if ($lid === null) { $uebersprungen[] = 'Zeile ' . ($nr + 1) . ': Lehrer "' . trim($teile[0]) . '" unbekannt'; continue; }
        if ($fid === null) { $uebersprungen[] = 'Zeile ' . ($nr + 1) . ': Fach "' . trim($teile[1]) . '" unbekannt'; continue; }
        $ins->execute([$lid, $fid]);
        $ok++;
    }
    json_out(['importiert' => $ok, 'uebersprungen' => $uebersprungen]);
}

// ============================================================
// PLANUNGEN
// ============================================================
if (($seg[0] ?? '') === 'planungen') {
    require_auth();

    if ($method === 'GET' && count($seg) === 1) {
        require_admin();
        json_out(db()->query(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM slots s WHERE s.planung_id = p.id)       AS slot_anzahl,
                    (SELECT COUNT(*) FROM konferenzen k WHERE k.planung_id = p.id) AS konferenz_anzahl
               FROM planungen p ORDER BY p.id DESC')->fetchAll());
    }

    if ($method === 'POST' && count($seg) === 1) {
        require_admin();
        $b = body_json();
        $typ = in_array($b['typ'] ?? '', ['paed_tag', 'zeitraum'], true) ? $b['typ'] : 'zeitraum';
        $st = db()->prepare(
            "INSERT INTO planungen (titel, typ, schuljahr, status) VALUES (?,?,?,'entwurf')");
        $st->execute([req($b,'titel'), $typ, $b['schuljahr'] ?? '']);
        json_out(['id' => (int)db()->lastInsertId()], 201);
    }

    $pid = isset($seg[1]) ? (int)$seg[1] : null;

    if ($pid !== null && $method === 'GET' && count($seg) === 2) {
        require_admin();
        json_out(planung_detail($pid));
    }

    if ($pid !== null && $method === 'PATCH' && count($seg) === 2) {
        require_admin();
        patch_row('planungen', $pid, ['titel', 'typ', 'schuljahr', 'status']);
    }

    if ($pid !== null && $method === 'DELETE' && count($seg) === 2) {
        require_admin();
        db()->prepare('DELETE FROM planungen WHERE id = ?')->execute([$pid]);
        json_out(['ok' => true]);
    }

    // ---- Slots ----
    if ($pid !== null && ($seg[2] ?? '') === 'slots') {
        require_admin();
        if ($method === 'POST' && count($seg) === 3) {
            $b = body_json();
            $st = db()->prepare(
                'INSERT INTO slots (planung_id, datum, beginn, ende, bezeichnung) VALUES (?,?,?,?,?)');
            $st->execute([$pid, req($b,'datum'), req($b,'beginn'), req($b,'ende'),
                          $b['bezeichnung'] ?? '']);
            json_out(['id' => (int)db()->lastInsertId()], 201);
        }
        if ($method === 'PATCH' && count($seg) === 4) {
            patch_row('slots', (int)$seg[3], ['datum','beginn','ende','bezeichnung']);
        }
        if ($method === 'DELETE' && count($seg) === 4) {
            db()->prepare('UPDATE konferenzen SET slot_id = NULL WHERE slot_id = ?')->execute([(int)$seg[3]]);
            db()->prepare('DELETE FROM slots WHERE id = ? AND planung_id = ?')->execute([(int)$seg[3], $pid]);
            json_out(['ok' => true]);
        }
    }

    // ---- Konferenzen ----
    if ($pid !== null && ($seg[2] ?? '') === 'konferenzen') {
        require_admin();
        if ($method === 'POST' && count($seg) === 3) {
            $b = body_json();
            if (!empty($b['alle_faecher'])) {
                // Bulk: je Fach ohne Gruppe eine Konferenz, je Gruppe eine Konferenz
                $n = 0;
                $insF = db()->prepare(
                    'INSERT INTO konferenzen (planung_id, fach_id) VALUES (?,?)');
                $insG = db()->prepare(
                    'INSERT INTO konferenzen (planung_id, gruppe_id) VALUES (?,?)');
                foreach (db()->query(
                    'SELECT id FROM faecher WHERE aktiv = 1 AND gruppe_id IS NULL')->fetchAll() as $f) {
                    if (!konferenz_existiert($pid, (int)$f['id'], null)) { $insF->execute([$pid, $f['id']]); $n++; }
                }
                foreach (db()->query(
                    'SELECT DISTINCT g.id FROM fachgruppen g
                       JOIN faecher f ON f.gruppe_id = g.id AND f.aktiv = 1')->fetchAll() as $g) {
                    if (!konferenz_existiert($pid, null, (int)$g['id'])) { $insG->execute([$pid, $g['id']]); $n++; }
                }
                json_out(['angelegt' => $n], 201);
            }
            $fachId   = isset($b['fach_id'])   ? (int)$b['fach_id']   : null;
            $gruppeId = isset($b['gruppe_id']) ? (int)$b['gruppe_id'] : null;
            if (($fachId === null) === ($gruppeId === null)) {
                json_err('Genau eines angeben: fach_id ODER gruppe_id');
            }
            if (konferenz_existiert($pid, $fachId, $gruppeId)) json_err('Konferenz existiert bereits', 409);
            $st = db()->prepare(
                'INSERT INTO konferenzen (planung_id, fach_id, gruppe_id) VALUES (?,?,?)');
            $st->execute([$pid, $fachId, $gruppeId]);
            json_out(['id' => (int)db()->lastInsertId()], 201);
        }
        if ($method === 'PATCH' && count($seg) === 4) {
            patch_row('konferenzen', (int)$seg[3], ['slot_id','raum_id','notiz']);
        }
        if ($method === 'DELETE' && count($seg) === 4) {
            db()->prepare('DELETE FROM konferenzen WHERE id = ? AND planung_id = ?')
                ->execute([(int)$seg[3], $pid]);
            json_out(['ok' => true]);
        }
    }

    // ---- Konflikte ----
    if ($pid !== null && $seg === ['planungen', (string)$pid, 'konflikte'] && $method === 'GET') {
        require_admin();
        json_out(konflikte_berechnen($pid));
    }

    // ---- Automatische Berechnung ----
    if ($pid !== null && $seg === ['planungen', (string)$pid, 'berechnen'] && $method === 'POST') {
        require_admin();
        $b = body_json();
        json_out(plan_berechnen($pid,
            (bool)($b['alles_neu'] ?? false),
            (bool)($b['raeume_vorschlagen'] ?? false)));
    }
}

function konferenz_existiert(int $pid, ?int $fachId, ?int $gruppeId): bool
{
    $st = db()->prepare(
        'SELECT COUNT(*) c FROM konferenzen
          WHERE planung_id = ? AND fach_id <=> ? AND gruppe_id <=> ?');
    $st->execute([$pid, $fachId, $gruppeId]);
    return (int)$st->fetch()['c'] > 0;
}

function planung_detail(int $pid): array
{
    $st = db()->prepare('SELECT * FROM planungen WHERE id = ?');
    $st->execute([$pid]);
    $p = $st->fetch();
    if (!$p) json_err('Planung nicht gefunden', 404);

    $st = db()->prepare('SELECT * FROM slots WHERE planung_id = ? ORDER BY datum, beginn');
    $st->execute([$pid]);
    $p['slots'] = $st->fetchAll();

    $st = db()->prepare(
        'SELECT k.*,
                COALESCE(g.name, f.name)     AS einheit_name,
                f.kuerzel                    AS fach_kuerzel,
                r.kuerzel                    AS raum_kuerzel
           FROM konferenzen k
           LEFT JOIN faecher     f ON f.id = k.fach_id
           LEFT JOIN fachgruppen g ON g.id = k.gruppe_id
           LEFT JOIN raeume      r ON r.id = k.raum_id
          WHERE k.planung_id = ?
          ORDER BY einheit_name');
    $st->execute([$pid]);
    $p['konferenzen'] = $st->fetchAll();

    foreach ($p['konferenzen'] as &$k) {
        $k['lehrer'] = array_values(einheit_lehrer($k));
    }
    unset($k);

    $p['konflikte'] = konflikte_berechnen($pid);
    return $p;
}

/** Lehrer einer Konferenz-Einheit: kuerzel-Liste (Gruppe = Vereinigung ihrer Fächer). */
function einheit_lehrer(array $konferenz): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = ['fach' => [], 'gruppe' => []];
        $rows = db()->query(
            'SELECT lf.fach_id, f.gruppe_id, l.id AS lehrer_id, l.kuerzel
               FROM lehrer_fach lf
               JOIN lehrer  l ON l.id = lf.lehrer_id AND l.aktiv = 1
               JOIN faecher f ON f.id = lf.fach_id')->fetchAll();
        foreach ($rows as $r) {
            $cache['fach'][(int)$r['fach_id']][(int)$r['lehrer_id']] = $r['kuerzel'];
            if ($r['gruppe_id'] !== null) {
                $cache['gruppe'][(int)$r['gruppe_id']][(int)$r['lehrer_id']] = $r['kuerzel'];
            }
        }
    }
    if ($konferenz['fach_id'] !== null) {
        return $cache['fach'][(int)$konferenz['fach_id']] ?? [];
    }
    return $cache['gruppe'][(int)$konferenz['gruppe_id']] ?? [];
}

function slots_ueberlappen(array $a, array $b): bool
{
    return $a['datum'] === $b['datum']
        && $a['beginn'] < $b['ende']
        && $b['beginn'] < $a['ende'];
}

function konflikte_berechnen(int $pid): array
{
    $st = db()->prepare('SELECT * FROM slots WHERE planung_id = ?');
    $st->execute([$pid]);
    $slots = [];
    foreach ($st->fetchAll() as $s) $slots[(int)$s['id']] = $s;

    $st = db()->prepare(
        'SELECT k.*, COALESCE(g.name, f.name) AS einheit_name
           FROM konferenzen k
           LEFT JOIN faecher f     ON f.id = k.fach_id
           LEFT JOIN fachgruppen g ON g.id = k.gruppe_id
          WHERE k.planung_id = ? AND k.slot_id IS NOT NULL');
    $st->execute([$pid]);
    $konfs = $st->fetchAll();

    $konflikte = [];
    $n = count($konfs);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $sa = $slots[(int)$konfs[$i]['slot_id']] ?? null;
            $sb = $slots[(int)$konfs[$j]['slot_id']] ?? null;
            if ($sa === null || $sb === null || !slots_ueberlappen($sa, $sb)) continue;
            $gemeinsam = array_intersect_key(einheit_lehrer($konfs[$i]), einheit_lehrer($konfs[$j]));
            if ($gemeinsam === []) continue;
            $konflikte[] = [
                'konferenz_a' => ['id' => (int)$konfs[$i]['id'], 'name' => $konfs[$i]['einheit_name']],
                'konferenz_b' => ['id' => (int)$konfs[$j]['id'], 'name' => $konfs[$j]['einheit_name']],
                'slot_a'      => (int)$konfs[$i]['slot_id'],
                'slot_b'      => (int)$konfs[$j]['slot_id'],
                'lehrer'      => array_values($gemeinsam),
            ];
        }
    }
    return $konflikte;
}

/**
 * Automatische Slot-Zuweisung per DSATUR-artiger Heuristik.
 * paed_tag: möglichst früh packen (wenige Schienen nutzen)
 * zeitraum: gleichmäßig auf die Slots verteilen
 * Bereits zugewiesene Konferenzen bleiben fixiert (außer alles_neu = true).
 */
function plan_berechnen(int $pid, bool $allesNeu, bool $raeumeVorschlagen): array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM planungen WHERE id = ?');
    $st->execute([$pid]);
    $planung = $st->fetch();
    if (!$planung) json_err('Planung nicht gefunden', 404);

    $st = $pdo->prepare('SELECT * FROM slots WHERE planung_id = ? ORDER BY datum, beginn');
    $st->execute([$pid]);
    $slots = $st->fetchAll();
    if ($slots === []) json_err('Diese Planung hat noch keine Zeitslots');
    $slotIds = array_map(fn($s) => (int)$s['id'], $slots);

    // Überlappungsmatrix der Slots (gleicher Tag + Zeitüberschneidung)
    $ueberlappt = [];
    foreach ($slots as $a) {
        foreach ($slots as $b) {
            $ueberlappt[(int)$a['id']][(int)$b['id']] = slots_ueberlappen($a, $b);
        }
    }
    // Ein Slot "kollidiert" mit sich selbst immer:
    foreach ($slotIds as $id) $ueberlappt[$id][$id] = true;

    $st = $pdo->prepare(
        'SELECT k.*, COALESCE(g.name, f.name) AS einheit_name
           FROM konferenzen k
           LEFT JOIN faecher f     ON f.id = k.fach_id
           LEFT JOIN fachgruppen g ON g.id = k.gruppe_id
          WHERE k.planung_id = ?');
    $st->execute([$pid]);
    $konfs = $st->fetchAll();
    if ($konfs === []) json_err('Diese Planung hat noch keine Konferenzen');

    $lehrerVon = []; $zuweisung = []; $fixiert = [];
    foreach ($konfs as $k) {
        $id = (int)$k['id'];
        $lehrerVon[$id] = array_keys(einheit_lehrer($k));   // lehrer_ids
        if (!$allesNeu && $k['slot_id'] !== null) {
            $zuweisung[$id] = (int)$k['slot_id'];
            $fixiert[$id]   = true;
        }
    }

    // Konfliktgraph
    $nachbarn = [];
    $ids = array_map(fn($k) => (int)$k['id'], $konfs);
    foreach ($ids as $a) $nachbarn[$a] = [];
    for ($i = 0; $i < count($ids); $i++) {
        for ($j = $i + 1; $j < count($ids); $j++) {
            $a = $ids[$i]; $b = $ids[$j];
            if (array_intersect($lehrerVon[$a], $lehrerVon[$b]) !== []) {
                $nachbarn[$a][] = $b;
                $nachbarn[$b][] = $a;
            }
        }
    }

    $offen = array_values(array_filter($ids, fn($id) => !isset($zuweisung[$id])));
    $namen = [];
    foreach ($konfs as $k) $namen[(int)$k['id']] = $k['einheit_name'];

    $unplatzierbar = [];
    while ($offen !== []) {
        // DSATUR: Einheit mit den meisten bereits blockierten Slots zuerst,
        // bei Gleichstand die mit den meisten Konflikt-Nachbarn.
        usort($offen, function ($a, $b) use ($nachbarn, $zuweisung, $ueberlappt, $slotIds) {
            return [blockierte_slots($b, $nachbarn, $zuweisung, $ueberlappt, $slotIds), count($nachbarn[$b])]
               <=> [blockierte_slots($a, $nachbarn, $zuweisung, $ueberlappt, $slotIds), count($nachbarn[$a])];
        });
        $id = array_shift($offen);

        // Machbare Slots ermitteln
        $machbar = [];
        foreach ($slotIds as $sid) {
            $frei = true;
            foreach ($nachbarn[$id] as $nb) {
                if (isset($zuweisung[$nb]) && $ueberlappt[$zuweisung[$nb]][$sid]) { $frei = false; break; }
            }
            if ($frei) $machbar[] = $sid;
        }
        if ($machbar === []) {
            $blocker = [];
            foreach ($nachbarn[$id] as $nb) {
                if (isset($zuweisung[$nb])) $blocker[] = $namen[$nb];
            }
            $unplatzierbar[] = ['id' => $id, 'name' => $namen[$id],
                                'grund' => 'Kein Slot frei, Konflikte mit: ' . implode(', ', array_unique($blocker))];
            continue;
        }

        if ($planung['typ'] === 'paed_tag') {
            // packen: frühester machbarer Slot
            $zuweisung[$id] = $machbar[0];
        } else {
            // verteilen: machbarer Slot mit den wenigsten Konferenzen
            $last = array_count_values(array_values($zuweisung));
            usort($machbar, fn($x, $y) =>
                [($last[$x] ?? 0), array_search($x, $slotIds)]
                <=> [($last[$y] ?? 0), array_search($y, $slotIds)]);
            $zuweisung[$id] = $machbar[0];
        }
    }

    // Speichern
    $pdo->beginTransaction();
    $upd = $pdo->prepare('UPDATE konferenzen SET slot_id = ? WHERE id = ?');
    foreach ($zuweisung as $id => $sid) {
        if (isset($fixiert[$id])) continue;
        $upd->execute([$sid, $id]);
    }
    if ($allesNeu) {
        // Nicht platzierte verlieren ggf. ihre alte Zuweisung
        $upd0 = $pdo->prepare('UPDATE konferenzen SET slot_id = NULL WHERE id = ?');
        foreach ($unplatzierbar as $u) $upd0->execute([$u['id']]);
    }

    // Raumvorschläge (nur wo raum_id noch leer ist)
    $raumStat = null;
    if ($raeumeVorschlagen) {
        $raeume = $pdo->query('SELECT id FROM raeume WHERE aktiv = 1 ORDER BY kuerzel')->fetchAll();
        $raumIds = array_map(fn($r) => (int)$r['id'], $raeume);
        $belegt = [];  // slot_id => [raum_id => true] (inkl. überlappender Slots)
        $st = $pdo->prepare(
            'SELECT id, slot_id, raum_id FROM konferenzen
              WHERE planung_id = ? AND slot_id IS NOT NULL');
        $st->execute([$pid]);
        $alle = $st->fetchAll();
        foreach ($alle as $k) {
            if ($k['raum_id'] !== null) $belegt[(int)$k['slot_id']][(int)$k['raum_id']] = true;
        }
        $vergeben = 0; $ohneRaum = 0;
        $updR = $pdo->prepare('UPDATE konferenzen SET raum_id = ? WHERE id = ?');
        foreach ($alle as $k) {
            if ($k['raum_id'] !== null) continue;
            $sid = (int)$k['slot_id'];
            $gefunden = null;
            foreach ($raumIds as $rid) {
                $frei = true;
                foreach ($slotIds as $anderer) {
                    if ($ueberlappt[$sid][$anderer] && isset($belegt[$anderer][$rid])) { $frei = false; break; }
                }
                if ($frei) { $gefunden = $rid; break; }
            }
            if ($gefunden !== null) {
                $updR->execute([$gefunden, (int)$k['id']]);
                $belegt[$sid][$gefunden] = true;
                $vergeben++;
            } else {
                $ohneRaum++;
            }
        }
        $raumStat = ['vergeben' => $vergeben, 'ohne_raum' => $ohneRaum];
    }
    $pdo->commit();

    return [
        'zugewiesen'     => count($zuweisung) - count($fixiert),
        'fixiert'        => count($fixiert),
        'unplatzierbar'  => $unplatzierbar,
        'raeume'         => $raumStat,
        'konflikte'      => konflikte_berechnen($pid),
    ];
}

function blockierte_slots(int $id, array $nachbarn, array $zuweisung, array $ueberlappt, array $slotIds): int
{
    $blockiert = 0;
    foreach ($slotIds as $sid) {
        foreach ($nachbarn[$id] as $nb) {
            if (isset($zuweisung[$nb]) && $ueberlappt[$zuweisung[$nb]][$sid]) { $blockiert++; break; }
        }
    }
    return $blockiert;
}

// ============================================================
// MEINE TERMINE (Lehrkraft): nur veröffentlichte Planungen,
// nur Konferenzen der eigenen Fächer
// ============================================================
if ($seg === ['meine-termine'] && $method === 'GET') {
    require_auth();
    $lehrerId = $_SESSION['lehrer_id'] ?? null;
    if ($lehrerId === null) {
        json_out(['termine' => [], 'hinweis' =>
            'Für dein Kürzel ist noch kein Stammsatz hinterlegt. Bitte beim Administrator melden.']);
    }
    json_out(['termine' => termine_fuer_lehrer((int)$lehrerId)]);
}

function termine_fuer_lehrer(int $lehrerId): array
{
    $st = db()->prepare(
        "SELECT p.titel AS planung, p.typ,
                COALESCE(g.name, f2.name) AS konferenz,
                s.datum, s.beginn, s.ende, s.bezeichnung AS slot,
                r.kuerzel AS raum
           FROM konferenzen k
           JOIN planungen p ON p.id = k.planung_id AND p.status = 'veroeffentlicht'
           JOIN slots     s ON s.id = k.slot_id
           LEFT JOIN faecher     f2 ON f2.id = k.fach_id
           LEFT JOIN fachgruppen g  ON g.id  = k.gruppe_id
           LEFT JOIN raeume      r  ON r.id  = k.raum_id
          WHERE k.slot_id IS NOT NULL
            AND (
                 k.fach_id IN (SELECT fach_id FROM lehrer_fach WHERE lehrer_id = ?)
              OR k.gruppe_id IN (
                     SELECT DISTINCT f.gruppe_id
                       FROM lehrer_fach lf JOIN faecher f ON f.id = lf.fach_id
                      WHERE lf.lehrer_id = ? AND f.gruppe_id IS NOT NULL)
            )
          ORDER BY s.datum, s.beginn");
    $st->execute([$lehrerId, $lehrerId]);
    return $st->fetchAll();
}

// ============================================================
// iCAL
//   GET /api/mein-ical                     -> persönliche Abo-URL (Token)
//   GET /api/ical/persoenlich/{token}.ics  -> Kalender-Feed (ohne Login!)
//   GET /api/ical/planung/{id}.ics         -> ganze veröffentlichte Planung
// ============================================================
if ($seg === ['mein-ical'] && $method === 'GET') {
    require_auth();
    $lehrerId = $_SESSION['lehrer_id'] ?? null;
    if ($lehrerId === null) json_err('Kein Lehrer-Stammsatz vorhanden', 404);
    $st = db()->prepare('SELECT ical_token FROM lehrer WHERE id = ?');
    $st->execute([$lehrerId]);
    $token = $st->fetch()['ical_token'] ?? null;
    if ($token === null || $token === '') {
        $token = bin2hex(random_bytes(20));
        db()->prepare('UPDATE lehrer SET ical_token = ? WHERE id = ?')->execute([$token, $lehrerId]);
    }
    json_out(['url' => config('app.base_url') . '/api/ical/persoenlich/' . $token . '.ics']);
}

if (($seg[0] ?? '') === 'ical' && $method === 'GET' && count($seg) === 3) {
    if ($seg[1] === 'persoenlich' && str_ends_with($seg[2], '.ics')) {
        $token = substr($seg[2], 0, -4);
        $st = db()->prepare('SELECT id FROM lehrer WHERE ical_token = ? AND ical_token <> \'\'');
        $st->execute([$token]);
        $l = $st->fetch();
        if (!$l) { http_response_code(404); exit('Nicht gefunden'); }
        ical_ausgeben(termine_fuer_lehrer((int)$l['id']), 'Fachkonferenzen – persönlich');
    }
    if ($seg[1] === 'planung' && str_ends_with($seg[2], '.ics')) {
        $pid = (int)$seg[2];
        $st = db()->prepare("SELECT * FROM planungen WHERE id = ? AND status = 'veroeffentlicht'");
        $st->execute([$pid]);
        $p = $st->fetch();
        if (!$p) { http_response_code(404); exit('Nicht gefunden'); }
        $st = db()->prepare(
            "SELECT p.titel AS planung, COALESCE(g.name, f.name) AS konferenz,
                    s.datum, s.beginn, s.ende, s.bezeichnung AS slot, r.kuerzel AS raum
               FROM konferenzen k
               JOIN planungen p ON p.id = k.planung_id
               JOIN slots     s ON s.id = k.slot_id
               LEFT JOIN faecher     f ON f.id = k.fach_id
               LEFT JOIN fachgruppen g ON g.id = k.gruppe_id
               LEFT JOIN raeume      r ON r.id = k.raum_id
              WHERE k.planung_id = ? AND k.slot_id IS NOT NULL
              ORDER BY s.datum, s.beginn");
        $st->execute([$pid]);
        ical_ausgeben($st->fetchAll(), $p['titel']);
    }
}

function ical_ausgeben(array $termine, string $kalenderName): void
{
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="fachkonferenzen.ics"');
    $z = ["BEGIN:VCALENDAR", "VERSION:2.0",
          "PRODID:-//FRG Duesseldorf//Fachkonferenzen//DE",
          "X-WR-CALNAME:" . ical_text($kalenderName)];
    foreach ($termine as $t) {
        $start = str_replace('-', '', $t['datum']) . 'T' . str_replace(':', '', substr($t['beginn'], 0, 5)) . '00';
        $ende  = str_replace('-', '', $t['datum']) . 'T' . str_replace(':', '', substr($t['ende'],   0, 5)) . '00';
        $z[] = 'BEGIN:VEVENT';
        $z[] = 'UID:' . md5(json_encode($t)) . '@fachkonferenzen.hornse.de';
        $z[] = 'DTSTART;TZID=Europe/Berlin:' . $start;
        $z[] = 'DTEND;TZID=Europe/Berlin:'   . $ende;
        $z[] = 'SUMMARY:' . ical_text('Fachkonferenz ' . $t['konferenz']);
        if (!empty($t['raum']))  $z[] = 'LOCATION:'    . ical_text($t['raum']);
        $z[] = 'DESCRIPTION:' . ical_text($t['planung'] . (!empty($t['slot']) ? ' – ' . $t['slot'] : ''));
        $z[] = 'END:VEVENT';
    }
    $z[] = 'END:VCALENDAR';
    echo implode("\r\n", $z) . "\r\n";
    exit;
}

function ical_text(string $s): string
{
    return str_replace([',', ';', "\n"], ['\,', '\;', '\n'], $s);
}

// ============================================================
// Generische Helfer
// ============================================================
function req(array $b, string $key)
{
    if (!isset($b[$key]) || $b[$key] === '') json_err("Feld '$key' fehlt");
    return $b[$key];
}

/** Massenaktion: aktiv-Flag für viele Zeilen setzen. Body: {ids: [], aktiv: 0|1} */
function bulk_aktiv(string $tabelle): void
{
    $b = body_json();
    $ids = array_values(array_filter(array_map('intval', (array)($b['ids'] ?? []))));
    $aktiv = (int)($b['aktiv'] ?? 0) === 1 ? 1 : 0;
    if ($ids === []) json_err('ids fehlt oder leer');
    $in = implode(',', array_fill(0, count($ids), '?'));
    db()->prepare("UPDATE `$tabelle` SET aktiv = $aktiv WHERE id IN ($in)")->execute($ids);
    json_out(['geaendert' => count($ids), 'aktiv' => $aktiv]);
}

function patch_row(string $tabelle, int $id, array $erlaubteFelder): void
{
    $b = body_json();
    $sets = []; $werte = [];
    foreach ($erlaubteFelder as $feld) {
        if (array_key_exists($feld, $b)) {
            $sets[]  = "`$feld` = ?";
            $werte[] = $b[$feld];
        }
    }
    if ($sets === []) json_err('Keine änderbaren Felder im Body');
    $werte[] = $id;
    db()->prepare("UPDATE `$tabelle` SET " . implode(', ', $sets) . ' WHERE id = ?')->execute($werte);
    json_out(['ok' => true]);
}

// ------------------------------------------------------------
json_err('Unbekannte Route: ' . $method . ' ' . $path, 404);
