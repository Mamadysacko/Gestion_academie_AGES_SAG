<?php
/**
 * Routes Utilisateurs
 *
 * GET    /users                        → Liste tous les utilisateurs
 * GET    /users/{id}                   → Détail d'un utilisateur
 * GET    /users/email/{email}          → Recherche par email
 * GET    /users/role/{role_id}         → Filtrer par rôle
 * POST   /users                        → Créer un utilisateur
 * POST   /users/authenticate           → Authentification (alias legacy)
 * POST   /users/{id}/toggle-actif      → Basculer le statut actif/inactif
 * PUT    /users/{id}                   → Modifier un utilisateur
 * DELETE /users/{id}                   → Supprimer un utilisateur
 *
 * Routes Rôles
 * GET    /roles                        → Liste tous les rôles
 * GET    /roles/{id}                   → Détail d'un rôle
 * GET    /roles/code/{code}            → Rôle par code
 * POST   /roles                        → Créer un rôle
 * PUT    /roles/{id}                   → Modifier un rôle
 * DELETE /roles/{id}                   → Supprimer un rôle
 */

// ── /users ───────────────────────────────────────────────────────────────────
if ($resource === 'users') {
    $controller = new UserController();

    if ($method === 'GET') {
        if ($param1 === null) {
            $controller->getAll();
        } elseif ($param1 === 'email' && $param2 !== null) {
            $controller->getByEmail($param2);
        } elseif ($param1 === 'role' && is_numeric($param2)) {
            $controller->getByRole((int)$param2);
        } elseif (is_numeric($param1)) {
            $controller->getById((int)$param1);
        } else {
            throw new Exception("Route non trouvée.", 404);
        }

    } elseif ($method === 'POST') {
        if ($param1 === null) {
            $controller->create();
        } elseif ($param1 === 'authenticate') {
            $controller->authenticate();
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

// ── /roles ───────────────────────────────────────────────────────────────────
if ($resource === 'roles') {
    $controller = new RoleController();

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
        if ($param2 === 'permissions') {
            $controller->updatePermissions((int)$param1);
        } else {
            $controller->update((int)$param1);
        }

    } elseif ($method === 'DELETE' && is_numeric($param1)) {
        $controller->delete((int)$param1);

    } else {
        throw new Exception("Méthode non autorisée.", 405);
    }
    return true;
}

return false;
