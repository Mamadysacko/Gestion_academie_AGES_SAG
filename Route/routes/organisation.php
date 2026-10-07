<?php
/**
 * Routes Organisation Académique
 *
 * /annees-scolaires  → Années scolaires
 * /departements      → Départements
 * /niveaux           → Niveaux
 * /types-formations  → Types de formation
 */

// ── /annees-scolaires ────────────────────────────────────────────────────────
if ($resource === 'annees-scolaires') {
    $controller = new AnneeScolaireController();

    if ($method === 'GET') {
        if ($param1 === null) {
            $controller->getAll();
        } elseif ($param1 === 'active') {
            $controller->getActive();
        } elseif (is_numeric($param1)) {
            $controller->getById((int)$param1);
        } else {
            throw new Exception("Route non trouvée.", 404);
        }

    } elseif ($method === 'POST') {
        if ($param1 === null) {
            $controller->create();
        } elseif (is_numeric($param1) && $param2 === 'activate') {
            $controller->activate((int)$param1);
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

// ── /departements ────────────────────────────────────────────────────────────
if ($resource === 'departements') {
    $controller = new DepartementController();

    if ($method === 'GET') {
        if ($param1 === null) {
            $controller->getAll();
        } elseif ($param1 === 'code' && $param2 !== null) {
            $controller->getByCode($param2);
        } elseif (is_numeric($param1)) {
            $controller->getById((int)$param1);
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

// ── /niveaux ─────────────────────────────────────────────────────────────────
if ($resource === 'niveaux') {
    $controller = new NiveauController();

    if ($method === 'GET') {
        if ($param1 === null) {
            $controller->getAll();
        } elseif ($param1 === 'libelle' && $param2 !== null) {
            $controller->getByLibelle(urldecode($param2));
        } elseif (is_numeric($param1)) {
            $controller->getById((int)$param1);
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

// ── /types-formations (alias: /types-formation) ───────────────────────────────
if ($resource === 'types-formations' || $resource === 'types-formation') {
    $controller = new TypeFormationController();

    if ($method === 'GET') {
        if ($param1 === null) {
            $controller->getAll();
        } elseif ($param1 === 'libelle' && $param2 !== null) {
            $controller->getByLibelle(urldecode($param2));
        } elseif (is_numeric($param1)) {
            $controller->getById((int)$param1);
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
