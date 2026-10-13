<?php
/**
 * Routes Sessions & Groupes
 *
 * /sessions → Sessions de formation
 * /groupes  → Groupes d'apprenants
 */

// ── /sessions ────────────────────────────────────────────────────────────────
if ($resource === 'sessions') {
    $controller = new SessionController();

    if ($method === 'GET') {
        if ($param1 === null) {
            $controller->getAll();
        } elseif ($param1 === 'annee' && is_numeric($param2)) {
            $controller->getByAnnee((int)$param2);
        } elseif ($param1 === 'type-formation' && is_numeric($param2)) {
            $controller->getByTypeFormation((int)$param2);
        } elseif ($param1 === 'niveau' && is_numeric($param2)) {
            $controller->getByNiveau((int)$param2);
        } elseif (is_numeric($param1)) {
            if ($param2 === 'relations') {
                $controller->getWithRelations((int)$param1);
            } elseif ($param2 === null) {
                $controller->getById((int)$param1);
            } else {
                throw new Exception("Route non trouvée.", 404);
            }
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

// ── /groupes ─────────────────────────────────────────────────────────────────
if ($resource === 'groupes') {
    $controller = new GroupeController();

    if ($method === 'GET') {
        if ($param1 === null) {
            $controller->getAll();
        } elseif ($param1 === 'session' && is_numeric($param2)) {
            $controller->getBySession((int)$param2);
        } elseif ($param1 === 'jour' && is_numeric($param2)) {
            $controller->getByJour((int)$param2);
        } elseif (is_numeric($param1)) {
            if ($param2 === 'session') {
                $controller->getWithSession((int)$param1);
            } elseif ($param2 === 'places-restantes') {
                $controller->getPlacesRestantes((int)$param1);
            } elseif ($param2 === 'salles') {
                $controller->getSalles((int)$param1);
            } elseif ($param2 === null) {
                $controller->getById((int)$param1);
            } else {
                throw new Exception("Route non trouvée.", 404);
            }
        } else {
            throw new Exception("Route non trouvée.", 404);
        }

    } elseif ($method === 'POST' && $param1 === null) {
        $controller->create();

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
