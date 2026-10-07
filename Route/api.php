<?php
/**
 * ================================================================
 *  AGES API - Point d'entrée principal
 * ================================================================
 *
 *  Base URL : http://localhost/Backend_AGES/Route
 *
 *  Fonctionnalités regroupées dans des fichiers séparés :
 *    routes/auth.php          → Authentification (/auth/login)
 *    routes/users.php         → Utilisateurs & Rôles
 *    routes/organisation.php  → Années scolaires, Départements, Niveaux, Types de formation
 *    routes/sessions.php      → Sessions & Groupes
 *    routes/auditeurs.php     → Auditeurs & Inscriptions
 *    routes/ressources.php    → Formateurs & Salles
 *    routes/emplois.php       → Emplois du temps
 *    routes/evaluations.php   → Évaluations, Notes, Passages de niveau
 *    routes/presences.php     → Présences
 * ================================================================
 */

// ── 1. Bootstrap (CORS, contrôleurs, parsing URI) ────────────────────────────
require_once __DIR__ . '/routes/bootstrap.php';

// ── 2. Dispatch vers le fichier de routes approprié ─────────────────────────
try {
    Authorization::authorizeRequest($resource, $method, $param1, $param2);

    $routeFiles = [
        __DIR__ . '/routes/auth.php',
        __DIR__ . '/routes/users.php',
        __DIR__ . '/routes/organisation.php',
        __DIR__ . '/routes/sessions.php',
        __DIR__ . '/routes/auditeurs.php',
        __DIR__ . '/routes/ressources.php',
        __DIR__ . '/routes/emplois.php',
        __DIR__ . '/routes/evaluations.php',
        __DIR__ . '/routes/presences.php',
    ];

    $handled = false;
    foreach ($routeFiles as $routeFile) {
        // Chaque fichier de route utilise les variables $method, $resource, $param1, $param2
        // Il retourne true si la route a été gérée, false sinon
        $result = require $routeFile;
        if ($result === true) {
            $handled = true;
            break;
        }
    }

    if (!$handled) {
        throw new Exception("Resource '{$resource}' non trouvée.", 404);
    }

} catch (Exception $e) {
    $code = $e->getCode() ?: 500;

    // S'assurer que le code HTTP est dans une plage valide
    if ($code < 100 || $code >= 600) {
        $code = 500;
    }

    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
