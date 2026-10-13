<?php
/**
 * Rappels (points importants à traiter, selon le rôle)
 *
 * GET /rappels
 */

if ($resource !== 'rappels') return false;

if ($method !== 'GET' || $param1 !== null) {
    throw new Exception("Méthode non autorisée.", 405);
}

$user = Authorization::currentUser();
if ($user === null) {
    throw new RuntimeException('Authentification requise.', 401);
}

require_once __DIR__ . '/../../Model/Rappel.php';
echo json_encode(['success' => true, 'data' => (new Rappel())->getRappels($user)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

return true;
