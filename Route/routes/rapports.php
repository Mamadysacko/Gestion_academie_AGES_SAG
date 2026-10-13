<?php
/**
 * Rapports (catalogue de rapports générés à partir des données réelles)
 *
 * GET /rapports            → catalogue
 * GET /rapports/{code}     → rapport (filtres en query string)
 */

if ($resource !== 'rapports') return false;

if ($method !== 'GET') {
    throw new Exception("Méthode non autorisée.", 405);
}

$controller = new RapportController();
if ($param1 === null) {
    $controller->catalogue();
} else {
    $controller->generate($param1);
}

return true;
