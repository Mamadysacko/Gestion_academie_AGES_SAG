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
 *    routes/planification.php → Formations (configuration) & planification automatique
 * ================================================================
 */

// ── 1. Bootstrap (CORS, contrôleurs, parsing URI) ────────────────────────────
require_once __DIR__ . '/routes/bootstrap.php';
require_once __DIR__ . '/../config/ResponseCache.php';

// Compression des réponses : les listes JSON se réduisent de 80 à 90 % sur le réseau.
if (extension_loaded('zlib') && !ini_get('zlib.output_compression')
    && str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip')) {
    ob_start('ob_gzhandler');
}

// ── 2. Journal des opérations : on capture la réponse des écritures ─────────
$journalWrite = $method !== 'GET';
$journalUser  = $journalWrite ? Authorization::currentUser() : null;
if ($journalWrite) {
    ob_start();
}

// ── 3. Dispatch vers le fichier de routes approprié ─────────────────────────
try {
    Authorization::authorizeRequest($resource, $method, $param1, $param2);

    // Le verrou de session PHP sérialise les requêtes d'un même navigateur : sans cette ligne,
    // les 10 requêtes envoyées en parallèle par un écran s'exécutent l'une après l'autre.
    // Seules les routes qui modifient la session (connexion, déconnexion, profil) la gardent ouverte.
    if ($resource !== 'auth' && !($resource === 'users' && $param1 === 'authenticate')) {
        session_write_close();
    }

    // Cache serveur des agrégats coûteux (tableau de bord : une cinquantaine de requêtes SQL).
    // Il est invalidé par toute écriture passant par cette API et expire de toute façon vite.
    $cacheKey = null;
    if ($method === 'GET' && in_array($resource, ['tableau-de-bord', 'rappels'], true) && Authorization::userId() !== null) {
        $cacheKey = ResponseCache::key((int) Authorization::userId(), (string) ($_SERVER['REQUEST_URI'] ?? ''));
        if (($cached = ResponseCache::read($cacheKey)) !== null) {
            echo $cached;
            exit;
        }
        ob_start();
    }

    $routeFiles = [
        __DIR__ . '/routes/auth.php',
        __DIR__ . '/routes/users.php',
        __DIR__ . '/routes/organisation.php',
        __DIR__ . '/routes/sessions.php',
        __DIR__ . '/routes/auditeurs.php',
        __DIR__ . '/routes/ressources.php',
        __DIR__ . '/routes/emplois.php',
        __DIR__ . '/routes/planification.php',
        __DIR__ . '/routes/evaluations.php',
        __DIR__ . '/routes/presences.php',
        __DIR__ . '/routes/dashboard.php',
        __DIR__ . '/routes/journal.php',
        __DIR__ . '/routes/rappels.php',
        __DIR__ . '/routes/rapports.php',
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

    if ($cacheKey !== null) {
        $body = (string) ob_get_contents();
        ob_end_flush();
        if ((http_response_code() ?: 200) === 200) {
            ResponseCache::write($cacheKey, $body);
        }
        $cacheKey = null;
    }

} catch (Exception $e) {
    if (!empty($cacheKey) && ob_get_level() > 0) {
        ob_end_clean();
    }
    $code = $e->getCode() ?: 500;

    // S'assurer que le code HTTP est dans une plage valide
    if ($code < 100 || $code >= 600) {
        $code = 500;
    }

    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

// ── 4. Enregistrement de l'opération dans le journal ────────────────────────
if ($journalWrite) {
    $output = (string) ob_get_contents();
    ob_end_flush();
    if ((http_response_code() ?: 200) < 400) {
        ResponseCache::invalidate();
    }
    try {
        require_once __DIR__ . '/../Model/Journal.php';
        $response = json_decode($output, true);
        $requestBody = (string) file_get_contents('php://input');
        $sentIdentity = json_decode($requestBody, true);
        (new Journal())->record([
            'user' => $journalUser ?? Authorization::currentUser(),
            'identifiant' => is_array($sentIdentity) ? ($sentIdentity['email'] ?? null) : null,
            'method' => $method,
            'action' => Journal::actionFor($method, $resource, $param1, $param2),
            'resource' => $resource,
            'resource_id' => is_numeric($param1) ? $param1 : (is_numeric($param2) ? $param2 : null),
            'path' => trim($uri, '/'),
            'code' => http_response_code() ?: 200,
            'message' => is_array($response) ? ($response['message'] ?? $response['error'] ?? null) : null,
            'body' => $requestBody,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $journalError) {
        error_log('Journal des opérations indisponible : ' . $journalError->getMessage());
    }
}
