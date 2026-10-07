<?php
/**
 * Routes Ressources Pédagogiques
 *
 * /formateurs → Formateurs (enseignants)
 * /salles     → Salles de cours
 */

// ── /formateurs ───────────────────────────────────────────────────────────────
if ($resource === 'formateurs') {
    $controller = new FormateurController();

    if ($method === 'GET') {
        if ($param1 === null) {
            $controller->getAll();
        } elseif ($param1 === 'actifs') {
            $controller->getActifs();
        } elseif ($param1 === 'matricule' && $param2 !== null) {
            $controller->getByMatricule($param2);
        } elseif ($param1 === 'email' && $param2 !== null) {
            $controller->getByEmail($param2);
        } elseif ($param1 === 'user' && is_numeric($param2)) {
            $controller->getByUser((int)$param2);
        } elseif (is_numeric($param1)) {
            $controller->getById((int)$param1);
        } else {
            throw new Exception("Route non trouvée.", 404);
        }

    } elseif ($method === 'POST') {
        if ($param1 === null) {
            $controller->create();
        } elseif (is_numeric($param1) && $param2 === 'toggle-actif') {
            $controller->toggleActif((int)$param1);
        } else {
            throw new Exception("Route non trouvée.", 404);
        }

    } elseif ($method === 'PUT' && is_numeric($param1)) {
        $controller->update((int)$param1);

    } elseif ($method === 'DELETE' && is_numeric($param1)) {
        $controller->delete((int)$param1);

    } else {
        throw new Exception("Méthode non autorisée.", 405);
    }
    return true;
}

// ── /salles ───────────────────────────────────────────────────────────────────
if ($resource === 'salles') {
    $controller = new SalleController();

    if ($method === 'GET') {
        if ($param1 === null) {
            $controller->getAll();
        } elseif ($param1 === 'disponibles') {
            $controller->getDisponibles();
        } elseif ($param1 === 'code' && $param2 !== null) {
            $controller->getByCode($param2);
        } elseif (is_numeric($param1)) {
            $controller->getById((int)$param1);
        } else {
            throw new Exception("Route non trouvée.", 404);
        }

    } elseif ($method === 'POST') {
        if ($param1 === null) {
            $controller->create();
        } elseif (is_numeric($param1) && $param2 === 'statut') {
            $controller->changeStatut((int)$param1);
        } else {
            throw new Exception("Route non trouvée.", 404);
        }

    } elseif ($method === 'PUT' && is_numeric($param1)) {
        $controller->update((int)$param1);

    } elseif ($method === 'DELETE' && is_numeric($param1)) {
        $controller->delete((int)$param1);

    } else {
        throw new Exception("Méthode non autorisée.", 405);
    }
    return true;
}

return false;
