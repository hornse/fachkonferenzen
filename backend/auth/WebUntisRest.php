<?php
// ============================================================
// WebUntisRest.php – Client für die INTERNE WebUntis-REST-API
//
// ⚠️ BETA / UNDOKUMENTIERT: Diese API ist nicht offiziell
// freigegeben und kann sich mit jedem WebUntis-Update ändern.
// Der offizielle JSON-RPC-Weg bleibt der Standard; dieses Modul
// ist ein Experiment mit eingebauter Sondierung.
//
// Ablauf (Stand der Community-Dokumentation, Juli 2026):
//  1. Session per JSON-RPC authenticate -> JSESSIONID-Cookie
//  2. GET /WebUntis/api/token/new  (Cookie) -> JWT als Klartext
//  3. REST-Aufrufe mit "Authorization: Bearer <JWT>" und
//     optional "tenant-id" (aus /api/rest/view/v1/app/data)
// ============================================================

declare(strict_types=1);

class WebUntisRest
{
    private string $baseUrl;
    private string $school;
    private ?string $cookie   = null;   // "JSESSIONID=...; schoolname=_..."
    private ?string $jwt      = null;
    private ?string $tenantId = null;

    public function __construct(string $baseUrl, string $school)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->school  = $school;
    }

    /** Übernimmt den JSESSIONID-Cookie einer bestehenden JSON-RPC-Session. */
    public function mitSessionCookie(string $jsessionCookie): void
    {
        // schoolname-Cookie wird von manchen Instanzen zusätzlich verlangt
        $this->cookie = $jsessionCookie
            . '; schoolname=_' . base64_encode($this->school);
    }

    /** Holt das JWT (Schritt 2). Liefert true bei Erfolg. */
    public function tokenHolen(): bool
    {
        $r = $this->rohGet('/WebUntis/api/token/new');
        if ($r['status'] === 200 && $r['text'] !== '' && substr_count($r['text'], '.') === 2) {
            $this->jwt = trim($r['text']);
            return true;
        }
        return false;
    }

    /** Versucht, die tenant-id aus app/data zu ermitteln (optional). */
    public function tenantErmitteln(): void
    {
        $r = $this->get('/WebUntis/api/rest/view/v1/app/data');
        $j = $r['json'];
        if (!is_array($j)) return;
        foreach ([['tenant', 'id'], ['tenantId'], ['user', 'tenant', 'id']] as $pfad) {
            $wert = $j;
            foreach ($pfad as $k) { $wert = is_array($wert) ? ($wert[$k] ?? null) : null; }
            if ($wert !== null && $wert !== '') { $this->tenantId = (string)$wert; return; }
        }
    }

    /** GET mit Bearer-Auth. Liefert ['status','contentType','text','json']. */
    public function get(string $pfad, array $query = []): array
    {
        $extra = [];
        if ($this->jwt !== null)      $extra[] = 'Authorization: Bearer ' . $this->jwt;
        if ($this->tenantId !== null) $extra[] = 'tenant-id: ' . $this->tenantId;
        return $this->rohGet($pfad . ($query ? '?' . http_build_query($query) : ''), $extra);
    }

    private function rohGet(string $pfadMitQuery, array $extraHeader = []): array
    {
        $headers = array_merge(['Accept: application/json, text/plain'], $extraHeader);
        if ($this->cookie !== null) $headers[] = 'Cookie: ' . $this->cookie;

        $ch = curl_init($this->baseUrl . $pfadMitQuery);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
        ]);
        $text = curl_exec($ch);
        if ($text === false) {
            $fehler = curl_error($ch);
            curl_close($ch);
            return ['status' => 0, 'contentType' => '', 'text' => 'cURL: ' . $fehler, 'json' => null];
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ct     = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        $json = json_decode($text, true);
        return ['status' => $status, 'contentType' => $ct,
                'text' => $text, 'json' => is_array($json) ? $json : null];
    }
}

// ------------------------------------------------------------
// Liefert den ersten Eintrag mit position1 aus einer beliebig
// verschachtelten entries-Antwort (für Sondierungs-Berichte).
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
// Extraktor für den Legacy-Endpunkt /api/public/timetable/weekly/data
// (formatId=1). Perioden liegen unter data.result.data.elementPeriods,
// jede Periode hat ein elements-Array mit {type, id, orgId}:
//   type 2 = Lehrkraft, type 3 = Fach.
// Bei Vertretungen steht in id die Vertretung und in orgId die
// reguläre Lehrkraft -> für "wer unterrichtet was" zählt orgId.
// Rückgabe: ['paare' => ['lehrerWuId|fachWuId' => true],
//            'perioden' => n, 'namen' => [typ][id] => Kürzel]
// ------------------------------------------------------------
function rest_paare_aus_weekly($json): array
{
    $paare = [];
    $perioden = 0;
    $namen = [2 => [], 3 => []];

    $daten = $json['data']['result']['data'] ?? null;
    if (!is_array($daten)) return ['paare' => [], 'perioden' => 0, 'namen' => $namen];

    // Auflösungstabelle (für Berichte/Diagnose)
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
                // reguläre Zuordnung: orgId (falls Vertretung), sonst id
                $id  = (int)(($el['orgId'] ?? 0) > 0 ? $el['orgId'] : ($el['id'] ?? 0));
                if ($id <= 0) continue;
                if ($typ === 2) $lehrer[$id] = true;
                if ($typ === 3) $faecher[$id] = true;
            }
            if ($lehrer === [] || $faecher === []) continue;
            $perioden++;
            foreach (array_keys($lehrer) as $l) {
                foreach (array_keys($faecher) as $f) $paare["$l|$f"] = true;
            }
        }
    }
    return ['paare' => $paare, 'perioden' => $perioden, 'namen' => $namen];
}

function rest_paare_extrahieren($daten): array
{
    $paare = [];
    $eintraege = 0;

    $shortNames = function ($positionen): array {
        $namen = [];
        foreach ((array)$positionen as $p) {
            foreach (['current', 'removed'] as $zustand) {
                // "removed" bewusst NICHT werten – nur aktueller Stand
                if ($zustand === 'removed') continue;
                $sn = $p[$zustand]['shortName'] ?? $p['shortName'] ?? null;
                if (is_string($sn) && $sn !== '') $namen[] = $sn;
            }
        }
        return $namen;
    };

    $lauf = function ($knoten) use (&$lauf, &$paare, &$eintraege, $shortNames): void {
        if (!is_array($knoten)) return;

        // Variante A: modernes entries-Format (position1/position2)
        if (isset($knoten['position1']) && isset($knoten['position2'])) {
            $lehrer  = $shortNames($knoten['position1']);
            $faecher = $shortNames($knoten['position2']);
            if ($lehrer !== [] && $faecher !== []) {
                $eintraege++;
                foreach ($lehrer as $l) {
                    foreach ($faecher as $f) $paare["$l|$f"] = true;
                }
            }
        }
        // Variante B: Legacy weekly/data-Format (elements mit type)
        // dort stehen Perioden mit "elements": [{type:2,id..},{type:3,..}]
        // und die Kürzel in einer separaten elementMap -> hier nicht
        // auflösbar, wird von der Sondierung nur gemeldet.

        foreach ($knoten as $wert) {
            if (is_array($wert)) $lauf($wert);
        }
    };
    $lauf($daten);

    return ['paare' => $paare, 'eintraege' => $eintraege];
}
