<?php
/**
 * Routes espace auditeur : /auditeur/*
 *
 * GET  /auditeur/profil              → infos personnelles
 * PUT  /auditeur/profil              → modifier infos + mot de passe
 * GET  /auditeur/notes               → notes par évaluation
 * GET  /auditeur/emploi-du-temps     → emploi du temps
 * GET  /auditeur/presences           → historique + taux
 * GET  /auditeur/notifications       → liste notifications
 * PUT  /auditeur/notifications/{id}/lu   → marquer lue
 * PUT  /auditeur/notifications/tout-lire → marquer toutes lues
 * GET  /auditeur/bulletin            → bulletin de notes
 * GET  /auditeur/dashboard           → résumé
 * GET  /auditeur/alertes             → alertes de prévention
 */

if ($resource === 'auditeur') {
    $controller = new AuditeurEspaceController();

    if ($method === 'GET') {
        if ($param1 === 'profil') {
            $controller->getProfil();
        } elseif ($param1 === 'notes') {
            $controller->getNotes();
        } elseif ($param1 === 'emploi-du-temps') {
            $controller->getEmploiDuTemps();
        } elseif ($param1 === 'presences') {
            $controller->getPresences();
        } elseif ($param1 === 'notifications') {
            $controller->getNotifications();
        } elseif ($param1 === 'bulletin') {
            $controller->getBulletin();
        } elseif ($param1 === 'alertes') {
            $controller->getAlertes();
        } elseif ($param1 === 'dashboard') {
            $controller->getDashboard();
        } else {
            throw new Exception("Route non trouvée.", 404);
        }
    } elseif ($method === 'PUT') {
        if ($param1 === 'profil') {
            $controller->updateProfil();
        } elseif ($param1 === 'notifications' && is_numeric($param2) && $param3 === 'lu') {
            $controller->markNotificationRead((int) $param2);
        } elseif ($param1 === 'notifications' && $param2 === 'tout-lire') {
            $controller->markAllNotificationsRead();
        } else {
            throw new Exception("Route non trouvée.", 404);
        }
    } else {
        throw new Exception("Méthode non autorisée.", 405);
    }
    return true;
}

return false;
