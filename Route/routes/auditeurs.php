<?php
/**
 * Routes Auditeurs & Inscriptions
 *
 * /auditeurs    → Gestion des auditeurs (apprenants)
 * /inscriptions → Inscriptions aux groupes
 * /types-employes → Types d'employés des auditeurs
 * /positions → Positions (postes) des auditeurs
 */

// ── /types-employes ──────────────────────────────────────────────────────────
if ($resource === 'types-employes') {
    $controller = new TypeEmployeController();

    if ($method === 'GET' && $param1 === null) {
        $controller->getAll();
    } elseif ($method === 'GET' && is_numeric($param1)) {
        $controller->getById((int)$param1);
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

// ── /positions ───────────────────────────────────────────────────────────────
if ($resource === 'positions') {
    $controller = new PositionController();

    if ($method === 'GET' && $param1 === null) {
        $controller->getAll();
    } elseif ($method === 'GET' && is_numeric($param1)) {
        $controller->getById((int)$param1);
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

// ── /auditeurs ───────────────────────────────────────────────────────────────
if ($resource === 'auditeurs') {
    $controller = new AuditeurController();

    if ($method === 'GET') {
        if ($param1 === null) {
            $controller->getAll();
        } elseif ($param1 === 'matricule' && $param2 !== null) {
            $controller->getByMatricule($param2);
        } elseif ($param1 === 'search' && $param2 !== null) {
            $controller->search(urldecode($param2));
        } elseif (is_numeric($param1) && $param2 === 'dossier') {
            $controller->dossier((int)$param1);
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

// ── /inscriptions ─────────────────────────────────────────────────────────────
if ($resource === 'inscriptions') {
    $controller = new InscriptionController();

    if ($method === 'GET') {
        if ($param1 === null) {
            $controller->getAll();
        } elseif ($param1 === 'auditeur' && is_numeric($param2)) {
            $controller->getByAuditeur((int)$param2);
        } elseif ($param1 === 'groupe' && is_numeric($param2)) {
            $controller->getByGroupe((int)$param2);
        } elseif ($param1 === 'departement' && is_numeric($param2)) {
            $controller->getByDepartement((int)$param2);
        } elseif (is_numeric($param1)) {
            if ($param2 === 'details') {
                $controller->getWithDetails((int)$param1);
            } else {
                $controller->getById((int)$param1);
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

return false;
