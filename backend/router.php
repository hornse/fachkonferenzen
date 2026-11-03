<?php
// ============================================================
// router.php – Einstiegspunkt für den PHP built-in Server
// KRITISCH: Diese beiden Zeilen müssen GANZ OBEN stehen,
// vor jedem require. Uberspace terminiert SSL vor PHP.
// ============================================================
$_SERVER['HTTPS'] = 'on';          // sonst kein Secure-Flag am Session-Cookie
session_name('fako_session');      // sonst Session-Verlust nach Login

// ?: '/' – parse_url liefert für "//x" null (der Teil nach // gilt als
// Host). Ohne Rückfall wäre das mit der Sperrfunktion ein TypeError (500).
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = __DIR__;                   // /home/hornse/fachkonferenzen/backend

// --- API ----------------------------------------------------
if (strpos($uri, '/api/') === 0 || $uri === '/api') {
    require $root . '/api/index.php';
    return true;
}

// --- Sperrliste ---------------------------------------------
// Was Apache sperrt (Editor-Reste, Daten, Einstellungen, Doku, Skripte,
// Punktdateien), liefert auch dieser Router nicht aus. Nach dem API-Zweig,
// vor jeder Dateiauslieferung und vor dem SPA-Rückfall: immer 404, nie 403,
// gleich ob die Datei existiert. Die Liste steht in sperrliste.php;
// tests/sperrliste_test.php misst sie gegen die Apache-Vorlage.
require_once $root . '/sperrliste.php';
if (fk_pfad_gesperrt($uri)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Nicht gefunden';
    return true;
}

// --- Statische Frontend-Dateien -----------------------------
$frontend = dirname($root) . '/frontend';
$mime = [
    'html' => 'text/html; charset=utf-8',
    'js'   => 'application/javascript; charset=utf-8',
    'css'  => 'text/css; charset=utf-8',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'svg'  => 'image/svg+xml',
    'ico'  => 'image/x-icon',
    'woff2'=> 'font/woff2',
];

$path = realpath($frontend . $uri);
if ($uri !== '/' && $path !== false
    && strpos($path, $frontend . DIRECTORY_SEPARATOR) === 0 && is_file($path)) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($mime[$ext] ?? 'application/octet-stream'));
    readfile($path);
    return true;
}

// --- SPA-Fallback: alles andere bekommt die index.html ------
// (Pfade mit Punkt = vermutlich fehlende Datei -> 404)
if (strpos(basename($uri), '.') !== false) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Nicht gefunden';
    return true;
}

header('Content-Type: text/html; charset=utf-8');
readfile($frontend . '/index.html');
return true;
