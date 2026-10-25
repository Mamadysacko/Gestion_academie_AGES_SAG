<?php
/**
 * Routes Formations (configuration de référence) & Planification automatique
 *
 * /formations     → passages hebdomadaires, groupes, salles dédiées, historique des versions
 * /planification  → simulation, application, reprise de session, conflits, alternatives, synthèse
 */

// ── /formations ──────────────────────────────────────────────────────────────
if ($resource === 'formations') {
    $controller = new FormationController();

    if ($method === 'GET' && $param1 === null) {
        $controller->getAll();
    } elseif ($method === 'GET' && is_numeric($param1) && $param2 === null) {
        $controller->getById((int) $param1);
    } elseif ($method === 'GET' && is_numeric($param1) && $param2 === 'historique') {
        $controller->getHistory((int) $param1);
    } elseif ($method === 'GET' && is_numeric($param1) && $param2 === 'salles') {
        $controller->getRooms((int) $param1);
    } elseif ($method === 'PUT' && is_numeric($param1) && $param2 === 'configuration') {
        $controller->updateConfiguration((int) $param1);
    } elseif ($method === 'PUT' && is_numeric($param1) && $param2 === 'salles') {
        $controller->setRooms((int) $param1);
    } else {
        throw new Exception('Route de formation inconnue.', 404);
    }
    return true;
}

// ── /planification ───────────────────────────────────────────────────────────
if ($resource === 'planification') {
    $controller = new PlanificationController();
    $id = is_numeric($param2) ? (int) $param2 : null;

    if ($method === 'GET') {
        match (true) {
            $param1 === 'synthese' => $controller->synthese(),
            $param1 === 'conflits' => $controller->conflits(),
            $param1 === 'occupation' => $controller->occupation(),
            $param1 === 'generations' && $id !== null => $controller->generations($id),
            default => throw new Exception('Route de planification inconnue.', 404),
        };
    } elseif ($method === 'POST') {
        match (true) {
            $param1 === 'simuler' && $id !== null => $controller->simuler($id),
            $param1 === 'appliquer' && $id !== null => $controller->appliquer($id),
            $param1 === 'reprendre' && $id !== null => $controller->reprendre($id),
            $param1 === 'adopter-configuration' && $id !== null => $controller->adopterConfiguration($id),
            $param1 === 'verifier' => $controller->verifier(),
            $param1 === 'alternatives' => $controller->alternatives(),
            default => throw new Exception('Route de planification inconnue.', 404),
        };
    } elseif ($method === 'PUT' && $param1 === 'passages' && $id !== null) {
        $controller->passages($id);
    } else {
        throw new Exception('Méthode non autorisée.', 405);
    }
    return true;
}

return false;
