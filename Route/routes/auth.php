<?php
/**
 * Routes d'Authentification
 *
 * POST /auth/login   → Authentification (email/password)
 * POST /auth/logout  → Déconnexion
 */

if ($resource !== 'auth') return false;

$controller = new UserController();

if ($method === 'GET' && $param1 === 'me') {
    $controller->currentSession();
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
