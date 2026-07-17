<?php
// ============================================================
// vorgaben.php – Fächer-Vorgaben (Regelwerk), Archiv, CSV
// Modell:
//   faecher        = aktueller Stand (was die App benutzt)
//   fach_vorgaben  = gewünschte Konfiguration (Regelwerk, mit
//                    Präfix-Mustern wie 'LZ*'; exakt schlägt
//                    Muster, längstes Muster gewinnt)
//   konfig_archiv  = Schnappschüsse des Stands (Wiederherstellen)
// CSV-Format (Import UND Export): Fachkürzel;aktiv;Fachgruppe
// ============================================================

declare(strict_types=1);

// ------------------------------------------------------------
// Standard-Regelwerk FRG / NRW-Gymnasium (Stand Juli 2026,
// aus den Kürzel-Konventionen der Schule abgeleitet)
// ------------------------------------------------------------
function standard_vorgaben(): array
{
    $inaktivExakt = ['AG', 'Beratung', 'BeCo', 'ESL', 'Hu', 'IdW', 'Instrumental',
                     'LR', 'Med', 'PX', 'FRG', 'RaN', 'R8', 'SuSRed', 'SV', 'SchSa',
                     'Schw', 'T', 'Gs', 'SpHelfer', 'SprBü', 'SprWebuntisBü', 'StGr',
                     'Wandertag'];
    $inaktivMuster = ['(*', 'LZ*', 'WP*', 'LI*', 'NW*'];  // Bereitschaften, Lernzeiten,
                                                          // Wahlpflicht, Litauisch (HSU),
                                                          // Naturwissenschaften (Diff)
    $gruppen = [
        'BI*' => 'Biologie',      'CH*' => 'Chemie',
        'D*'  => 'Deutsch',       'VXD*' => 'Deutsch',
        'E*'  => 'Englisch',      'EK*' => 'Erdkunde',
        'F*'  => 'Französisch',   'GE*' => 'Geschichte',
        'IF*' => 'Informatik',    'KU*' => 'Kunst',
        'L*'  => 'Latein',
        'M*'  => 'Mathematik',    'VXM*' => 'Mathematik',
        'MU*' => 'Musik',
        'PL*' => 'Philosophie',   'PP*' => 'Philosophie',
        'PH*' => 'Physik',
        'KR*' => 'Religion',      'ER*' => 'Religion',
        'SW*' => 'Sozialwissenschaften/Politik',
        'SoWi*' => 'Sozialwissenschaften/Politik',
        'PK*' => 'Sozialwissenschaften/Politik',
        'S*'  => 'Spanisch',      'SP*' => 'Sport',
    ];
    $regeln = [];
    foreach ($inaktivExakt  as $k) $regeln[] = ['kuerzel' => $k, 'aktiv' => 0, 'fachgruppe' => null];
    foreach ($inaktivMuster as $k) $regeln[] = ['kuerzel' => $k, 'aktiv' => 0, 'fachgruppe' => null];
    foreach ($gruppen as $k => $g) $regeln[] = ['kuerzel' => $k, 'aktiv' => 1, 'fachgruppe' => $g];
    return $regeln;
}

// ------------------------------------------------------------
// Matcher: exakter Treffer schlägt Muster, längstes Muster gewinnt
// ------------------------------------------------------------
function vorgabe_fuer(string $fachKuerzel, array $vorgaben): ?array
{
    $klein = function_exists('mb_strtolower')
        ? fn(string $s) => mb_strtolower($s) : fn(string $s) => strtolower($s);
    $k = $klein(trim($fachKuerzel));

    $bester = null; $besteLaenge = -1;
    foreach ($vorgaben as $v) {
        $muster = $klein(trim((string)$v['kuerzel']));
        if ($muster === '') continue;
        if (str_ends_with($muster, '*')) {
            $praefix = substr($muster, 0, -1);
            if (str_starts_with($k, $praefix) && strlen($praefix) > $besteLaenge) {
                $bester = $v; $besteLaenge = strlen($praefix);
            }
        } elseif ($muster === $k) {
            return $v;   // exakt gewinnt sofort
        }
    }
    return $bester;
}

// ------------------------------------------------------------
// Vorgaben auf die faecher-Tabelle anwenden.
// $nurKuerzel: nur diese Fächer anfassen (z. B. neue aus dem Sync);
// null = alle. Legt fehlende Fachgruppen an.
// ------------------------------------------------------------
function vorgaben_anwenden(?array $nurKuerzel = null): array
{
    $pdo = db();
    $vorgaben = $pdo->query('SELECT kuerzel, aktiv, fachgruppe FROM fach_vorgaben')->fetchAll();
    if ($vorgaben === []) return ['geaendert' => 0, 'ohne_vorgabe' => 0, 'gruppen_neu' => 0,
                                  'hinweis' => 'Keine Vorgaben vorhanden'];

    $gruppenId = [];
    foreach ($pdo->query('SELECT id, name FROM fachgruppen')->fetchAll() as $g) {
        $gruppenId[$g['name']] = (int)$g['id'];
    }

    $klein = function_exists('mb_strtolower')
        ? fn(string $s) => mb_strtolower($s) : fn(string $s) => strtolower($s);
    $nurSet = null;
    if ($nurKuerzel !== null) {
        $nurSet = [];
        foreach ($nurKuerzel as $k) $nurSet[$klein(trim((string)$k))] = true;
    }

    $stat = ['geaendert' => 0, 'ohne_vorgabe' => 0, 'gruppen_neu' => 0];
    $upd = $pdo->prepare('UPDATE faecher SET aktiv = ?, gruppe_id = ? WHERE id = ?');
    $insG = $pdo->prepare('INSERT INTO fachgruppen (name) VALUES (?)');

    foreach ($pdo->query('SELECT id, kuerzel, aktiv, gruppe_id FROM faecher')->fetchAll() as $f) {
        if ($nurSet !== null && !isset($nurSet[$klein($f['kuerzel'])])) continue;
        $v = vorgabe_fuer($f['kuerzel'], $vorgaben);
        if ($v === null) { $stat['ohne_vorgabe']++; continue; }

        $gruppeId = null;
        $gName = trim((string)($v['fachgruppe'] ?? ''));
        if ($gName !== '') {
            if (!isset($gruppenId[$gName])) {
                $insG->execute([$gName]);
                $gruppenId[$gName] = (int)$pdo->lastInsertId();
                $stat['gruppen_neu']++;
            }
            $gruppeId = $gruppenId[$gName];
        }
        $aktivNeu = (int)$v['aktiv'];
        if ((int)$f['aktiv'] !== $aktivNeu
            || (string)$f['gruppe_id'] !== (string)$gruppeId) {
            $upd->execute([$aktivNeu, $gruppeId, (int)$f['id']]);
            $stat['geaendert']++;
        }
    }
    return $stat;
}

// ------------------------------------------------------------
// Schnappschuss des aktuellen faecher-Stands ins Archiv
// ------------------------------------------------------------
function archiv_sichern(string $grund): int
{
    $stand = db()->query(
        'SELECT f.kuerzel, f.aktiv, g.name AS fachgruppe
           FROM faecher f LEFT JOIN fachgruppen g ON g.id = f.gruppe_id
          ORDER BY f.kuerzel')->fetchAll();
    $st = db()->prepare('INSERT INTO konfig_archiv (kuerzel, grund, daten) VALUES (?,?,?)');
    $st->execute([$_SESSION['kuerzel'] ?? '?', $grund,
                  json_encode($stand, JSON_UNESCAPED_UNICODE)]);
    return (int)db()->lastInsertId();
}

// ------------------------------------------------------------
// CSV: "Fachkürzel;aktiv;Fachgruppe" -> Zeilenobjekte
// (auch , oder Tab als Trenner, # = Kommentar, Kopfzeile erlaubt)
// ------------------------------------------------------------
function vorgaben_csv_parsen(string $csv): array
{
    $zeilenOk = []; $uebersprungen = [];
    foreach (preg_split('/\r\n|\r|\n/', $csv) as $nr => $zeile) {
        $zeile = trim($zeile);
        if ($zeile === '' || str_starts_with($zeile, '#')) continue;
        $teile = array_map('trim', preg_split('/[;,\t]/', $zeile));
        if (count($teile) < 2) { $uebersprungen[] = 'Zeile ' . ($nr + 1) . ': Format'; continue; }
        if ($nr === 0 && !is_numeric($teile[1])) continue;   // Kopfzeile
        $zeilenOk[] = ['kuerzel' => $teile[0],
                       'aktiv' => (int)$teile[1] === 1 ? 1 : 0,
                       'fachgruppe' => ($teile[2] ?? '') !== '' ? $teile[2] : null];
    }
    return ['zeilen' => $zeilenOk, 'uebersprungen' => $uebersprungen];
}

/** Zeilen ins Regelwerk übernehmen (Upsert per Kürzel). */
function vorgaben_upsert(array $zeilen): int
{
    $st = db()->prepare(
        'INSERT INTO fach_vorgaben (kuerzel, aktiv, fachgruppe) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE aktiv = VALUES(aktiv), fachgruppe = VALUES(fachgruppe)');
    foreach ($zeilen as $z) $st->execute([$z['kuerzel'], $z['aktiv'], $z['fachgruppe']]);
    return count($zeilen);
}
