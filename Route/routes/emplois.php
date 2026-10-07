<?php
/**
 * Routes Emplois du Temps
 *
 * GET    /emplois-du-temps                          → Liste complète
 * GET    /emplois-du-temps/planning-complet         → Planning enrichi
 * GET    /emplois-du-temps/groupe/{id}              → Par groupe
 * GET    /emplois-du-temps/salle/{id}               → Par salle
 * GET    /emplois-du-temps/formateur/{id}           → Par formateur
 * GET    /emplois-du-temps/jour/{jour}              → Par jour
 * GET    /emplois-du-temps/annee/{id}               → Par année scolaire
 * GET    /emplois-du-temps/{id}                     → Détail
 * POST   /emplois-du-temps                          → Créer
 * POST   /emplois-du-temps/check-disponibilite      → Vérifier disponibilité
 * PUT    /emplois-du-temps/{id}                     → Modifier
 * DELETE /emplois-du-temps/{id}                     → Supprimer
 */

if ($resource !== 'emplois-du-temps') return false;

$controller = new EmploiDuTempsController();

if ($method === 'GET') {
    if ($param1 === null) {
        $controller->getAll();
    } elseif ($param1 === 'planning-complet') {
        $controller->getPlanningComplet();
    } elseif ($param1 === 'groupe' && is_numeric($param2)) {
        $controller->getByGroupe((int)$param2);
    } elseif ($param1 === 'salle' && is_numeric($param2)) {
        $controller->getBySalle((int)$param2);
    } elseif ($param1 === 'formateur' && is_numeric($param2)) {
        $controller->getByFormateur((int)$param2);
    } elseif ($param1 === 'jour' && is_numeric($param2)) {
        $controller->getByJour((int)$param2);
    } elseif ($param1 === 'annee' && is_numeric($param2)) {
        $controller->getByAnnee((int)$param2);
    } elseif (is_numeric($param1)) {
        $controller->getById((int)$param1);
    } else {
        throw new Exception("Route non trouvée.", 404);
    }

} elseif ($method === 'POST') {
    if ($param1 === null) {
        $controller->create();
    } elseif ($param1 === 'check-disponibilite') {
        $controller->checkDisponibilite();
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
