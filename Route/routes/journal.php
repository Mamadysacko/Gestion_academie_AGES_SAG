<?php
/**
 * Journal des opérations
 *
 * GET /journal?q=&ressource=&action=&succes=&debut=&fin=&limit=
 */

if ($resource !== 'journal') return false;

if ($method !== 'GET' || $param1 !== null) {
    throw new Exception("Méthode non autorisée.", 405);
}

require_once __DIR__ . '/../../Model/Journal.php';
$filters = array_intersect_key($_GET, array_flip(['q', 'ressource', 'action', 'succes', 'debut', 'fin']));
$limit = (int) ($_GET['limit'] ?? 300);
echo json_encode([
    'success' => true,
    'data' => (new Journal())->search($filters, $limit ?: 300),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

return true;
