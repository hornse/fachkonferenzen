<?php
// ============================================================
// extractors.php – Auswertung von WebUntis-REST-Antworten
// VENDORED aus hornse/webuntis-client-php – dort ändern, hierher kopieren!
// ============================================================

declare(strict_types=1);

// ------------------------------------------------------------
// Moderner Endpunkt /api/rest/view/v1/timetable/entries
// (OHNE format-Parameter aufrufen -> Instanz-Standardformat).
// Jedes Positions-Element trägt current.type (CLASS/SUBJECT/
// ROOM/TEACHER/...) und beschreibt sich damit selbst – die
// Positionsnummern sind formatabhängig und werden IGNORIERT.
//
// Gewertet werden nur Unterrichts-Einträge (type enthält
// 'TEACHING') mit Status REGULAR oder CANCELLED (ein Ausfall
// ändert nichts daran, WER das Fach regulär unterrichtet).
// Vertretungen (Status SUBSTITUTION/CHANGED) werden übersprungen,
// damit der Vertreter das Fach nicht fälschlich zugeordnet bekommt.
//
// Rückgabe:
//   eintraege     – Zahl gewerteter Unterrichts-Einträge
//   fachKuerzel   – Menge der Fach-Kürzel (Schlüssel des Arrays)
//   paareExplizit – Menge "LehrerKrz|FachKrz" aus Einträgen, die
//                   selbst TEACHER-Elemente enthalten (Kopplungen)
// Die implizite Lehrkraft (die abgefragte Ressource) muss vom
// Aufrufer mit fachKuerzel kombiniert werden.
// ------------------------------------------------------------
function rest_unterricht_aus_entries($json): array
{
    $ergebnis = ['eintraege' => 0, 'fachKuerzel' => [], 'paareExplizit' => []];

    $lauf = function ($knoten) use (&$lauf, &$ergebnis): void {
        if (!is_array($knoten)) return;

        if (array_key_exists('position1', $knoten)) {
            $typ    = (string)($knoten['type'] ?? '');
            $status = (string)($knoten['status'] ?? 'REGULAR');
            if (stripos($typ, 'TEACHING') !== false
                && in_array($status, ['REGULAR', 'CANCELLED'], true)) {
                $faecher = []; $lehrer = [];
                for ($i = 1; $i <= 7; $i++) {
                    foreach ((array)($knoten['position' . $i] ?? []) as $el) {
                        $c = $el['current'] ?? null;
                        if (!is_array($c)) continue;   // removed wird bewusst ignoriert
                        $sn = (string)($c['shortName'] ?? '');
                        if ($sn === '') continue;
                        if (($c['type'] ?? '') === 'SUBJECT') $faecher[$sn] = true;
                        if (($c['type'] ?? '') === 'TEACHER') $lehrer[$sn] = true;
                    }
                }
                if ($faecher !== []) {
                    $ergebnis['eintraege']++;
                    // Werte = Anzahl Einträge (Stunden-Signal), array_keys bleibt kompatibel
                    foreach (array_keys($faecher) as $f) {
                        $ergebnis['fachKuerzel'][$f] = ($ergebnis['fachKuerzel'][$f] ?? 0) + 1;
                    }
                    foreach (array_keys($lehrer) as $l) {
                        foreach (array_keys($faecher) as $f) {
                            $ergebnis['paareExplizit']["$l|$f"] = ($ergebnis['paareExplizit']["$l|$f"] ?? 0) + 1;
                        }
                    }
                }
            }
            return;   // in Einträge nicht weiter absteigen
        }
        foreach ($knoten as $wert) {
            if (is_array($wert)) $lauf($wert);
        }
    };
    $lauf($json);
    return $ergebnis;
}

// ------------------------------------------------------------
// Liefert den ersten Eintrag mit position1 aus einer beliebig
// verschachtelten entries-Antwort (für Sondierung/Diagnose).
// ------------------------------------------------------------
function rest_erster_eintrag($json): ?array
{
    $treffer = null;
    $lauf = function ($knoten) use (&$lauf, &$treffer): void {
        if ($treffer !== null || !is_array($knoten)) return;
        if (isset($knoten['position1'])) { $treffer = $knoten; return; }
        foreach ($knoten as $wert) {
            if (is_array($wert)) $lauf($wert);
        }
    };
    $lauf($json);
    return $treffer;
}

// ------------------------------------------------------------
// Legacy-Endpunkt /api/public/timetable/weekly/data (formatId=1).
// Perioden unter data.result.data.elementPeriods, elements-Arrays
// mit {type, id, orgId}: type 2 = Lehrkraft, type 3 = Fach.
// Vertretung: orgId = reguläre Lehrkraft -> die zählt.
// Rückgabe: paare "lehrerWuId|fachWuId", perioden, namen[typ][id].
// ------------------------------------------------------------
function rest_paare_aus_weekly($json): array
{
    $paare = [];
    $perioden = 0;
    $namen = [2 => [], 3 => []];

    $daten = $json['data']['result']['data'] ?? null;
    if (!is_array($daten)) return ['paare' => [], 'perioden' => 0, 'namen' => $namen];

    foreach (($daten['elements'] ?? []) as $el) {
        $typ = (int)($el['type'] ?? 0);
        if (($typ === 2 || $typ === 3) && isset($el['id'])) {
            $namen[$typ][(int)$el['id']] = (string)($el['name'] ?? '');
        }
    }

    foreach (($daten['elementPeriods'] ?? []) as $periodenListe) {
        foreach ((array)$periodenListe as $periode) {
            $lehrer = []; $faecher = [];
            foreach (($periode['elements'] ?? []) as $el) {
                $typ = (int)($el['type'] ?? 0);
                $id  = (int)(($el['orgId'] ?? 0) > 0 ? $el['orgId'] : ($el['id'] ?? 0));
                if ($id <= 0) continue;
                if ($typ === 2) $lehrer[$id] = true;
                if ($typ === 3) $faecher[$id] = true;
            }
            if ($lehrer === [] || $faecher === []) continue;
            $perioden++;
            foreach (array_keys($lehrer) as $l) {
                foreach (array_keys($faecher) as $f) {
                    $paare["$l|$f"] = ($paare["$l|$f"] ?? 0) + 1;   // = Perioden-Zahl
                }
            }
        }
    }
    return ['paare' => $paare, 'perioden' => $perioden, 'namen' => $namen];
}
