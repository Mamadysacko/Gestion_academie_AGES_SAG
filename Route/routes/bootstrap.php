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
header('Content-Type: application/json; charset=utf-8');

// Réponse aux requêtes OPTIONS (preflight CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Chargement des contrôleurs ───────────────────────────────────────────────
$controllersDir = __DIR__ . '/../../Controller/';
require_once $controllersDir . 'AnneeScolaireController.php';
require_once $controllersDir . 'RoleController.php';
require_once $controllersDir . 'UserController.php';
require_once $controllersDir . 'Authorization.php';
require_once $controllersDir . 'DepartementController.php';
require_once $controllersDir . 'NiveauController.php';
require_once $controllersDir . 'TypeFormationController.php';
require_once $controllersDir . 'FormateurController.php';
require_once $controllersDir . 'SalleController.php';
require_once $controllersDir . 'SessionController.php';
require_once $controllersDir . 'GroupeController.php';
require_once $controllersDir . 'AuditeurController.php';
require_once $controllersDir . 'InscriptionController.php';
require_once $controllersDir . 'EmploiDuTempsController.php';
require_once $controllersDir . 'EvaluationController.php';
require_once $controllersDir . 'ParametreEvaluationController.php';
require_once $controllersDir . 'NoteController.php';
require_once $controllersDir . 'PassageNiveauController.php';
require_once $controllersDir . 'PresenceController.php';

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
