<?php
/**
 * Bootstrap - Configuration globale des routes
 * Définit les headers CORS, charge les contrôleurs et parse l'URI
 */

// ── Session, CORS & JSON ─────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost',
    'http://127.0.0.1',
];
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400'); // le navigateur mémorise la pré-requête CORS un jour
header('Content-Type: application/json; charset=utf-8');

// Réponse aux requêtes OPTIONS (preflight CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Chargement des contrôleurs ───────────────────────────────────────────────
// Seuls Authorization et la base sont chargés d'avance ; chaque contrôleur est chargé
// à la demande par l'autoloader (une requête n'en utilise qu'un ou deux sur vingt-trois).
$controllersDir = __DIR__ . '/../../Controller/';
require_once $controllersDir . 'Authorization.php';
spl_autoload_register(static function (string $class) use ($controllersDir): void {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $class)) {
        return;
    }
    foreach ([$controllersDir, __DIR__ . '/../../Model/'] as $dir) {
        if (is_file($dir . $class . '.php')) {
            require_once $dir . $class . '.php';
            return;
        }
    }
});

// ── Parsing de l'URI ─────────────────────────────────────────────────────────
$method   = $_SERVER['REQUEST_METHOD'];
$uri      = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri      = trim($uri, '/');

// Suppression du préfixe de base (Backend_AGES/Route avec ou sans api.php)
$uri = preg_replace('#^.*?Backend_AGES/Route/(api\.php/?)?#i', '', $uri);
$uri = preg_replace('#^api\.php/?#i', '', $uri);

// Découpage en segments
$segments  = explode('/', $uri);
$resource  = $segments[0] ?? '';
$param1    = $segments[1] ?? null;
$param2    = $segments[2] ?? null;
$param3    = $segments[3] ?? null;
$param4    = $segments[4] ?? null;
