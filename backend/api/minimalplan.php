<?php
// ============================================================
// minimalplan.php – minimale Slot-Anzahl (Graphfärbung, exakt)
//
// Reine Rechenfunktionen ohne Datenbank:
//   $einheiten = [konferenzId => [lehrerId, ...]]
// Konfliktgraph: Kante, wenn zwei Einheiten eine Lehrkraft teilen.
//
// minimalplan_rechnen() liefert:
//   k        – minimale Slot-Anzahl
//   farben   – [konferenzId => 0..k-1]
//   clique   – Einheiten-IDs einer paarweise kollidierenden Gruppe
//              (Untergrenze und Begründung; |clique| <= k)
//   exakt    – true = vollständige Suche (k ist bewiesen minimal),
//              false = Heuristik-Ergebnis (Sicherheitsabbruch griff)
// ============================================================

declare(strict_types=1);

function minimalplan_nachbarn(array $einheiten): array
{
    $ids = array_keys($einheiten);
    $nachbarn = [];
    foreach ($ids as $a) $nachbarn[$a] = [];
    for ($i = 0; $i < count($ids); $i++) {
        for ($j = $i + 1; $j < count($ids); $j++) {
            $a = $ids[$i]; $b = $ids[$j];
            if (array_intersect($einheiten[$a], $einheiten[$b]) !== []) {
                $nachbarn[$a][] = $b;
                $nachbarn[$b][] = $a;
            }
        }
    }
    return $nachbarn;
}

/** Gierige Max-Clique (Untergrenze + Begründung). */
function minimalplan_clique(array $nachbarn): array
{
    $beste = [];
    $sortiert = array_keys($nachbarn);
    usort($sortiert, fn($a, $b) => count($nachbarn[$b]) <=> count($nachbarn[$a]));

    foreach (array_slice($sortiert, 0, 12) as $start) {   // mehrere Startpunkte
        $clique = [$start];
        foreach ($sortiert as $kandidat) {
            if ($kandidat === $start) continue;
            $passt = true;
            foreach ($clique as $mitglied) {
                if (!in_array($kandidat, $nachbarn[$mitglied], true)) { $passt = false; break; }
            }
            if ($passt) $clique[] = $kandidat;
        }
        if (count($clique) > count($beste)) $beste = $clique;
    }
    return $beste;
}

/** Vollständiges Backtracking: sind die Knoten mit k Farben färbbar? */
function minimalplan_faerbbar(array $ids, array $nachbarn, int $k, int &$budget): ?array
{
    $farben = [];
    $n = count($ids);

    $suche = function (int $pos) use (&$suche, &$farben, $ids, $nachbarn, $k, $n, &$budget): bool {
        if ($pos === $n) return true;
        if (--$budget <= 0) return false;      // Sicherheitsabbruch
        $id = $ids[$pos];

        $belegt = [];
        $maxVergeben = -1;
        foreach ($farben as $andereId => $f) {
            if ($f > $maxVergeben) $maxVergeben = $f;
        }
        foreach ($nachbarn[$id] as $nb) {
            if (isset($farben[$nb])) $belegt[$farben[$nb]] = true;
        }
        // Symmetriebrechung: höchstens eine "neue" Farbe probieren
        $obergrenze = min($k - 1, $maxVergeben + 1);
        for ($f = 0; $f <= $obergrenze; $f++) {
            if (isset($belegt[$f])) continue;
            $farben[$id] = $f;
            if ($suche($pos + 1)) return true;
            unset($farben[$id]);
            if ($budget <= 0) return false;
        }
        return false;
    };

    return $suche(0) ? $farben : null;
}

/** DSATUR-Heuristik als Obergrenze/Fallback. */
function minimalplan_dsatur(array $ids, array $nachbarn): array
{
    $farben = [];
    $offen = $ids;
    while ($offen !== []) {
        usort($offen, function ($a, $b) use ($nachbarn, $farben) {
            $sat = function ($id) use ($nachbarn, $farben) {
                $s = [];
                foreach ($nachbarn[$id] as $nb) if (isset($farben[$nb])) $s[$farben[$nb]] = true;
                return count($s);
            };
            return [$sat($b), count($nachbarn[$b])] <=> [$sat($a), count($nachbarn[$a])];
        });
        $id = array_shift($offen);
        $belegt = [];
        foreach ($nachbarn[$id] as $nb) if (isset($farben[$nb])) $belegt[$farben[$nb]] = true;
        for ($f = 0; ; $f++) {
            if (!isset($belegt[$f])) { $farben[$id] = $f; break; }
        }
    }
    return $farben;
}

function minimalplan_rechnen(array $einheiten): array
{
    $ids = array_keys($einheiten);
    if ($ids === []) return ['k' => 0, 'farben' => [], 'clique' => [], 'exakt' => true];

    $nachbarn = minimalplan_nachbarn($einheiten);
    $clique   = minimalplan_clique($nachbarn);
    $unten    = max(1, count($clique));

    // Obergrenze per DSATUR
    $heuristik = minimalplan_dsatur($ids, $nachbarn);
    $oben = $heuristik === [] ? 1 : max($heuristik) + 1;

    // Suchreihenfolge: Grad absteigend (beschleunigt Backtracking massiv)
    $sortiert = $ids;
    usort($sortiert, fn($a, $b) => count($nachbarn[$b]) <=> count($nachbarn[$a]));

    for ($k = $unten; $k < $oben; $k++) {
        $budget = 2_000_000;
        $farben = minimalplan_faerbbar($sortiert, $nachbarn, $k, $budget);
        if ($farben !== null) {
            return ['k' => $k, 'farben' => $farben, 'clique' => $clique, 'exakt' => true];
        }
        if ($budget <= 0) {
            // Sicherheitsabbruch: Heuristik-Ergebnis zurückgeben
            return ['k' => $oben, 'farben' => $heuristik, 'clique' => $clique, 'exakt' => false];
        }
    }
    // DSATUR-Ergebnis ist bereits minimal (alle kleineren k widerlegt)
    return ['k' => $oben, 'farben' => $heuristik, 'clique' => $clique, 'exakt' => true];
}
