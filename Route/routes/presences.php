<?php
/**
 * Routes Présences
 *
 * GET    /presences                             → Liste complète
 * GET    /presences/inscription/{id}            → Par inscription
 * GET    /presences/date/{date}                 → Par date (YYYY-MM-DD)
 * GET    /presences/formateur/{id}              → Par formateur
 * GET    /presences/emploi-du-temps/{id}        → Par séance
 * GET    /presences/statistiques/{id}           → Stats d'une inscription
 * GET    /presences/{id}                        → Détail
 * POST   /presences                             → Créer
 * POST   /presences/mark                        → Marquer présence en masse
 * PUT    /presences/{id}                        → Modifier
 * DELETE /presences/{id}                        → Supprimer
 */

if ($resource !== 'presences') return false;

$controller = new PresenceController();

if ($method === 'GET') {
    if ($param1 === null) {
        $controller->getAll();
    } elseif ($param1 === 'inscription' && is_numeric($param2)) {
        $controller->getByInscription((int)$param2);
    } elseif ($param1 === 'date' && $param2 !== null) {
        $controller->getByDate($param2);
    } elseif ($param1 === 'formateur' && is_numeric($param2)) {
        $controller->getByFormateur((int)$param2);
    } elseif ($param1 === 'emploi-du-temps' && is_numeric($param2)) {
        $controller->getByEmploiDuTemps((int)$param2);
    } elseif ($param1 === 'statistiques' && is_numeric($param2)) {
        $controller->getStatistiques((int)$param2);
    } elseif (is_numeric($param1)) {
        $controller->getById((int)$param1);
    } else {
        throw new Exception("Route non trouvée.", 404);
    }

} elseif ($method === 'POST') {
    if ($param1 === null) {
        $controller->create();
    } elseif ($param1 === 'mark') {
        $controller->markPresence();
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
