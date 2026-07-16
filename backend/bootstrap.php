<?php
// ============================================================
// bootstrap.php – Session, DB, JSON-Helfer, Auth-Guards
// Wird von api/index.php geladen. session_name() ist bereits
// in router.php gesetzt (kritisch, siehe CLAUDE.md).
// ============================================================

declare(strict_types=1);

$GLOBALS['config'] = require __DIR__ . '/config.php';

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,   // greift dank $_SERVER['HTTPS']='on' in router.php
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// ------------------------------------------------------------
function config(string $key = null)
{
    $c = $GLOBALS['config'];
    if ($key === null) return $c;
    foreach (explode('.', $key) as $part) {
        $c = $c[$part] ?? null;
        if ($c === null) return null;
    }
    return $c;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $d = config('db');
        $pdo = new PDO(
            "mysql:host={$d['host']};dbname={$d['name']};charset=utf8mb4",
            $d['user'],
            $d['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

// ------------------------------------------------------------
function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_err(string $msg, int $code = 400, array $extra = []): void
{
    json_out(array_merge(['fehler' => $msg], $extra), $code);
}

function body_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) json_err('Ungültiger JSON-Body', 400);
    return $data;
}

// ------------------------------------------------------------
// NIEMALS empty() – WebUntis-Lehrer können benutzer_id = 0 haben!
function require_auth(): void
{
    if (!isset($_SESSION['benutzer_id']) || $_SESSION['benutzer_id'] === null) {
        json_err('Nicht angemeldet', 401);
    }
}

function require_admin(): void
{
    require_auth();
    if (($_SESSION['rolle'] ?? '') !== 'admin') {
        json_err('Keine Berechtigung', 403);
    }
}

function current_user(): array
{
    return [
        'id'        => $_SESSION['benutzer_id'] ?? null,
        'lehrer_id' => $_SESSION['lehrer_id'] ?? null,
        'kuerzel'   => $_SESSION['kuerzel'] ?? null,
        'name'      => $_SESSION['name'] ?? null,
        'rolle'     => $_SESSION['rolle'] ?? null,
        'quelle'    => $_SESSION['auth_quelle'] ?? null,
    ];
}
