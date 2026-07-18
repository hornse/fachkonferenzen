<?php
// ============================================================
// sync.php – Überführt WebUntis-Daten (Lehrer, Fächer, Räume,
// Lehrer-Fach-Paare) in die Datenbank. Wird von api/index.php
// geladen; als eigenes Modul direkt testbar.
//
// Robustheit:
//  * Doppelte Kürzel in den WebUntis-Antworten (z. B. zweimal
//    Fach "(B)") werden auf DENSELBEN lokalen Datensatz
//    zusammengeführt – beide WebUntis-IDs zeigen dann darauf.
//  * Kürzel-Vergleich case-insensitiv, weil die DB-Kollation
//    (utf8mb4_unicode_ci) "m" und "M" als gleich behandelt.
//  * Quelle 'manuell'/'csv' und gesperrte Zuordnungen werden
//    nie entfernt.
// ============================================================

declare(strict_types=1);

function sync_anwenden(array $teachers, array $subjects, array $rooms, array $paarKeys, string $modus): array
{
    $pdo = db();
    $anwenden = $modus === 'uebernehmen';
    if ($anwenden) $pdo->beginTransaction();

    $klein = function_exists('mb_strtolower')
        ? fn(string $s) => mb_strtolower($s)
        : fn(string $s) => strtolower($s);

    $stat = [
        'lehrer_neu' => 0, 'faecher_neu' => 0, 'raeume_neu' => 0,
        'zuordnungen_neu' => 0, 'zuordnungen_entfernt' => 0,
        'zuordnungen_gesamt_webuntis' => count($paarKeys),
        'duplikate' => ['lehrer' => [], 'faecher' => [], 'raeume' => []],
    ];

    // --------------------------------------------------------
    // Generischer Upsert je Tabelle. Liefert Map webuntis_id -> lokale id.
    // In der Vorschau werden neue Zeilen NICHT eingefügt; sie erhalten den
    // Platzhalter 0 (echte AUTO_INCREMENT-ids beginnen bei 1).
    // --------------------------------------------------------
    $upsert = function (string $tabelle, array $eintraege, string $statNeu, string $statDup,
                        callable $einfuegen, callable $aktualisieren)
                        use ($pdo, $anwenden, $klein, &$stat): array {
        $map = [];   // webuntis_id -> lokale id (bzw. 0 in der Vorschau für neue)
        $byWuId = []; $byKrz = [];
        foreach ($pdo->query("SELECT id, webuntis_id, kuerzel FROM `$tabelle`")->fetchAll() as $r) {
            if ($r['webuntis_id'] !== null) $byWuId[(int)$r['webuntis_id']] = (int)$r['id'];
            $byKrz[$klein($r['kuerzel'])] = (int)$r['id'];
        }

        foreach ($eintraege as $e) {
            $wid = (int)($e['id'] ?? 0);
            $krz = trim((string)($e['name'] ?? ''));
            if ($wid <= 0 || $krz === '') continue;
            $k = $klein($krz);

            if (isset($byWuId[$wid])) {
                // per WebUntis-ID bekannt
                $id = $byWuId[$wid];
                if ($anwenden && $id > 0) $aktualisieren($id, $e);
            } elseif (isset($byKrz[$k])) {
                // Kürzel existiert bereits (DB oder früher in DIESEM Lauf)
                // -> zusammenführen statt zweite Zeile anlegen
                $id = $byKrz[$k];
                $stat['duplikate'][$statDup][] = $krz;
                if ($anwenden && $id > 0) {
                    // webuntis_id nur setzen, falls noch leer (erste ID gewinnt)
                    $pdo->prepare("UPDATE `$tabelle` SET webuntis_id = COALESCE(webuntis_id, ?)
                                    WHERE id = ?")->execute([$wid, $id]);
                }
            } else {
                // wirklich neu
                $stat[$statNeu]++;
                $id = $anwenden ? $einfuegen($e) : 0;   // 0 = Platzhalter in der Vorschau
                $byKrz[$k] = $id;
            }

            $byWuId[$wid] = $id;
            if ($id > 0) $map[$wid] = $id;
        }
        $stat['duplikate'][$statDup] = array_values(array_unique($stat['duplikate'][$statDup]));
        return $map;
    };

    $mapLehrer = $upsert('lehrer', $teachers, 'lehrer_neu', 'lehrer',
        function (array $t) use ($pdo): int {
            $pdo->prepare('INSERT INTO lehrer (kuerzel, vorname, nachname, webuntis_id, aktiv)
                           VALUES (?,?,?,?,1)')
                ->execute([trim((string)$t['name']), $t['foreName'] ?? '', $t['longName'] ?? '', (int)$t['id']]);
            return (int)$pdo->lastInsertId();
        },
        function (int $id, array $t) use ($pdo): void {
            $pdo->prepare('UPDATE lehrer SET vorname = ?, nachname = ?, aktiv = 1 WHERE id = ?')
                ->execute([$t['foreName'] ?? '', $t['longName'] ?? '', $id]);
        });

    $mapFach = $upsert('faecher', $subjects, 'faecher_neu', 'faecher',
        function (array $s) use ($pdo): int {
            $krz = trim((string)$s['name']);
            $pdo->prepare('INSERT INTO faecher (kuerzel, name, webuntis_id, aktiv)
                           VALUES (?,?,?,1)')
                ->execute([$krz, ($s['longName'] ?? '') !== '' ? $s['longName'] : $krz, (int)$s['id']]);
            return (int)$pdo->lastInsertId();
        },
        function (int $id, array $s) use ($pdo): void { /* Name/Gruppe bleiben, wie der Admin sie pflegt */ });

    $upsert('raeume', $rooms, 'raeume_neu', 'raeume',
        function (array $r) use ($pdo): int {
            $krz = trim((string)$r['name']);
            $pdo->prepare('INSERT INTO raeume (kuerzel, name, webuntis_id, aktiv) VALUES (?,?,?,1)')
                ->execute([$krz, ($r['longName'] ?? '') !== '' ? $r['longName'] : $krz, (int)$r['id']]);
            return (int)$pdo->lastInsertId();
        },
        function (int $id, array $r): void { /* nichts zu aktualisieren */ });

    // --------------------------------------------------------
    // Zuordnungen diffen ("webuntisLehrerId|webuntisFachId")
    // --------------------------------------------------------
    $ziel = [];  // "lehrerId|fachId" => true (lokale IDs)
    $unaufgeloest = 0;
    foreach ($paarKeys as $key) {
        [$twid, $swid] = array_map('intval', explode('|', $key));
        $lid = $mapLehrer[$twid] ?? null;
        $fid = $mapFach[$swid] ?? null;
        if ($lid === null || $fid === null) { $unaufgeloest++; continue; }
        $ziel["$lid|$fid"] = true;
    }

    $bestehend = $pdo->query('SELECT id, lehrer_id, fach_id, quelle, gesperrt, ausgeschlossen FROM lehrer_fach')->fetchAll();
    $bestehendKeys = [];
    foreach ($bestehend as $r) $bestehendKeys[$r['lehrer_id'] . '|' . $r['fach_id']] = $r;

    $neu = []; $entfernt = [];
    foreach (array_keys($ziel) as $key) {
        if (!isset($bestehendKeys[$key])) $neu[] = $key;
    }
    foreach ($bestehend as $r) {
        $key = $r['lehrer_id'] . '|' . $r['fach_id'];
        // Nur webuntis-Zuordnungen entfernen; manuell/csv/gesperrt bleiben
        // immer, ausgeschlossene ebenfalls (Sperrvermerk gegen Wiederanlage)
        if ($r['quelle'] === 'webuntis' && !(int)$r['gesperrt']
            && !(int)($r['ausgeschlossen'] ?? 0) && !isset($ziel[$key])) {
            $entfernt[] = (int)$r['id'];
        }
    }
    $stat['zuordnungen_neu']          = count($neu);
    $stat['zuordnungen_entfernt']     = count($entfernt);
    $stat['zuordnungen_unaufgeloest'] = $unaufgeloest;

    if ($anwenden) {
        $ins = $pdo->prepare(
            "INSERT INTO lehrer_fach (lehrer_id, fach_id, quelle, gesperrt)
             VALUES (?,?,'webuntis',0)
             ON DUPLICATE KEY UPDATE lehrer_id = lehrer_id");
        foreach ($neu as $key) { [$lid, $fid] = explode('|', $key); $ins->execute([(int)$lid, (int)$fid]); }
        if ($entfernt !== []) {
            $in = implode(',', array_fill(0, count($entfernt), '?'));
            $pdo->prepare("DELETE FROM lehrer_fach WHERE id IN ($in)")->execute($entfernt);
        }
        $pdo->commit();
    }
    return $stat;
}
