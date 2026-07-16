<?php
// ============================================================
// router.php – Einstiegspunkt für den PHP built-in Server
// KRITISCH: Diese beiden Zeilen müssen GANZ OBEN stehen,
// vor jedem require. Uberspace terminiert SSL vor PHP.
// ============================================================
$_SERVER['HTTPS'] = 'on';          // sonst kein Secure-Flag am Session-Cookie
session_name('fako_session');      // sonst Session-Verlust nach Login

$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$root = __DIR__;                   // /home/hornse/fachkonferenzen/backend

// --- API ----------------------------------------------------
if (strpos($uri, '/api/') === 0 || $uri === '/api') {
    require $root . '/api/index.php';
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
    && strpos($path, $frontend) === 0 && is_file($path)) {
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
