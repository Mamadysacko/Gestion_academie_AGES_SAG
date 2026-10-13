<?php
/**
 * Routes d'Authentification
 *
 * POST /auth/login   → Authentification (email/password)
 * POST /auth/logout  → Déconnexion
 */

if ($resource !== 'auth') return false;

require_once __DIR__ . '/../../Controller/ProfileController.php';

$controller = new UserController();
$profileController = new ProfileController();

if ($method === 'GET' && $param1 === 'me') {
    $controller->currentSession();
} elseif ($method === 'GET' && $param1 === 'profile') {
    $profileController->getProfile();
} elseif ($method === 'PUT' && $param1 === 'profile') {
    $profileController->updateProfile();
} elseif ($method === 'GET' && $param1 === 'trainer-portal' && $param2 === null) {
    $profileController->getTrainerPortal();
} elseif ($method === 'GET' && $param1 === 'trainer-portal' && $param2 === 'feuille') {
    $profileController->trainerFeuille();
} elseif ($method === 'GET' && $param1 === 'trainer-portal' && $param2 === 'resultats' && is_numeric($param3)) {
    $profileController->trainerResultats((int) $param3);
} elseif ($method === 'POST' && $param1 === 'trainer-portal' && $param2 === 'presences-bulk') {
    $profileController->trainerMarkBulk();
} elseif ($method === 'POST' && $param1 === 'trainer-portal' && $param2 === 'presences') {
    $profileController->markTrainerPresence();
} elseif ($method === 'POST' && $param1 === 'trainer-portal' && $param2 === 'notes') {
    $profileController->saveTrainerGrade();
} elseif ($method === 'POST') {
    if ($param1 === 'login') {
        // POST /auth/login
        $controller->authenticate();
    } elseif ($param1 === 'logout') {
        $controller->logout();
    } else {
        throw new Exception("Route d'authentification inconnue.", 404);
    }
} else {
    throw new Exception("Méthode non autorisée sur /auth.", 405);
}

return true;
