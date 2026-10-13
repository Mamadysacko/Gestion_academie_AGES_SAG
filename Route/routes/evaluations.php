<?php
/**
 * Routes Évaluations, Notes & Passages de Niveau
 *
 * /evaluations              → Évaluations
 * /parametres               → Paramètres partagés et associations aux évaluations
 * /notes                    → Notes des auditeurs
 * /passages-niveaux         → Décisions de passage de niveau
 */

// ── /resultats ────────────────────────────────────────────────────────────────
if ($resource === 'resultats') {
    $controller = new ResultatController();

    if ($method === 'GET' && $param1 === 'session' && is_numeric($param2)) {
        $controller->getSession((int)$param2);
    } elseif ($method === 'GET' && $param1 === 'auditeur' && is_numeric($param2)) {
        $controller->getAuditeur((int)$param2);
    } elseif ($method === 'POST' && $param1 === 'valider' && is_numeric($param2)) {
        $controller->valider((int)$param2);
    } else {
        throw new Exception("Route non trouvée.", 404);
    }
    return true;
}

// ── /regles-evaluation ────────────────────────────────────────────────────────
if ($resource === 'regles-evaluation') {
    $controller = new ResultatController();

    if ($method === 'GET' && $param1 === null) {
        $controller->getRegles();
    } elseif ($method === 'PUT' && $param1 === null) {
        $controller->updateRegles();
    } else {
        throw new Exception("Méthode non autorisée.", 405);
    }
    return true;
}

// ── /regles-passage et /baremes-appreciation (CRUD des paramètres) ─────────────
if ($resource === 'regles-passage' || $resource === 'baremes-appreciation') {
    $controller = new ResultatController();
    $table = $resource === 'regles-passage' ? 'regles' : 'bareme';

    if ($method === 'GET' && $param1 === null) {
        $controller->crud($table, 'list');
    } elseif ($method === 'POST' && $param1 === null) {
        $controller->crud($table, 'create');
    } elseif ($method === 'PUT' && is_numeric($param1)) {
        $controller->crud($table, 'update', (int)$param1);
    } elseif ($method === 'DELETE' && is_numeric($param1)) {
        $controller->crud($table, 'delete', (int)$param1);
    } else {
        throw new Exception("Méthode non autorisée.", 405);
    }
    return true;
}

// ── /evaluations ──────────────────────────────────────────────────────────────
if ($resource === 'evaluations') {
    $controller = new EvaluationController();

    if ($method === 'GET') {
        if ($param1 === null) {
            $controller->getAll();
        } elseif ($param1 === 'session' && is_numeric($param2)) {
            $controller->getBySession((int)$param2);
        } elseif (is_numeric($param1)) {
            $controller->getById((int)$param1);
        } else {
            throw new Exception("Route non trouvée.", 404);
        }

    } elseif ($method === 'POST' && $param1 === null) {
        $controller->create();

    } elseif ($method === 'POST' && $param1 === 'generer' && is_numeric($param2)) {
        $controller->generer((int)$param2);

    } elseif ($method === 'PUT' && is_numeric($param1)) {
        $controller->update((int)$param1);

    } elseif ($method === 'DELETE' && is_numeric($param1)) {
        $controller->delete((int)$param1);

    } else {
        throw new Exception("Méthode non autorisée.", 405);
    }
    return true;
}

// ── /parametres-evaluations (alias: /parametres-evaluation) ─────────────────
if ($resource === 'parametres') {
    $controller = new ParametreEvaluationController();

    if ($method === 'GET') {
        if ($param1 === null) {
            $controller->getAll();
        } elseif ($param1 === 'evaluation' && is_numeric($param2) && $param3 === null) {
            $controller->getByEvaluation((int)$param2);
        } elseif (is_numeric($param1)) {
            $controller->getById((int)$param1);
        } else {
            throw new Exception("Route non trouvée.", 404);
        }

    } elseif ($method === 'POST' && $param1 === null) {
        $controller->create();
    } elseif ($method === 'POST' && $param1 === 'evaluation' && is_numeric($param2) && $param3 === null) {
        $controller->attachToEvaluation((int)$param2);

    } elseif ($method === 'PUT' && is_numeric($param1)) {
        $controller->update((int)$param1);

    } elseif ($method === 'DELETE' && $param1 === 'evaluation' && is_numeric($param2) && is_numeric($param3)) {
        $controller->detachFromEvaluation((int)$param2, (int)$param3);

    } elseif ($method === 'DELETE' && is_numeric($param1)) {
        $controller->delete((int)$param1);

    } else {
        throw new Exception("Méthode non autorisée.", 405);
    }
    return true;
}

// Compatibilité temporaire pour les anciens clients d'API.
if ($resource === 'parametres-evaluations' || $resource === 'parametres-evaluation') {
    $controller = new ParametreEvaluationController();

    if ($method === 'GET' && $param1 === null) {
        $controller->getAll();
    } elseif ($method === 'GET' && $param1 === 'evaluation' && is_numeric($param2)) {
        $controller->getByEvaluation((int)$param2);
    } elseif ($method === 'GET' && is_numeric($param1)) {
        $controller->getById((int)$param1);
    } elseif ($method === 'POST' && $param1 === null) {
        $controller->create();
    } elseif ($method === 'PUT' && is_numeric($param1)) {
        $controller->update((int)$param1);
    } elseif ($method === 'DELETE' && is_numeric($param1)) {
        $controller->delete((int)$param1);
    } else {
        throw new Exception("Route non trouvée.", 404);
    }
    return true;
}

// ── /notes ────────────────────────────────────────────────────────────────────
if ($resource === 'notes') {
    $controller = new NoteController();

    if ($method === 'GET') {
        if ($param1 === null) {
            $controller->getAll();
        } elseif ($param1 === 'inscription' && is_numeric($param2)) {
            $controller->getByInscription((int)$param2);
        } elseif ($param1 === 'parametre' && is_numeric($param2)) {
            $controller->getByParametre((int)$param2);
        } elseif ($param1 === 'evaluation' && is_numeric($param2) && $param3 === null) {
            $controller->getByEvaluation((int)$param2);
        } elseif ($param1 === 'evaluation' && is_numeric($param2) && $param3 === 'parametre' && is_numeric($param4)) {
            $controller->getByParametre((int)$param4, (int)$param2);
        } elseif ($param1 === 'moyenne' && is_numeric($param2)) {
            $controller->getMoyenne((int)$param2, is_numeric($param3) ? (int)$param3 : null);
        } elseif (is_numeric($param1)) {
            $controller->getById((int)$param1);
        } else {
            throw new Exception("Route non trouvée.", 404);
        }

    } elseif ($method === 'POST') {
        if ($param1 === null) {
            $controller->create();
        } elseif ($param1 === 'save-or-update') {
            $controller->saveOrUpdate();
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

// ── /passages-niveaux ─────────────────────────────────────────────────────────
if ($resource === 'passages-niveaux') {
    $controller = new PassageNiveauController();

    if ($method === 'GET') {
        if ($param1 === null) {
            $controller->getAll();
        } elseif ($param1 === 'inscription' && is_numeric($param2)) {
            $controller->getByInscription((int)$param2);
        } elseif (is_numeric($param1)) {
            $controller->getById((int)$param1);
        } else {
            throw new Exception("Route non trouvée.", 404);
        }

    } elseif ($method === 'POST') {
        if ($param1 === null) {
            $controller->create();
        } elseif ($param1 === 'decide') {
            $controller->decide();
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
