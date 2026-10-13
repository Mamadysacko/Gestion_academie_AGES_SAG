<?php

if ($resource !== 'tableau-de-bord') {
    return false;
}

$controller = new DashboardController();
if ($method !== 'GET') {
    throw new Exception('Méthode non autorisée.', 405);
}

if ($param1 === null) {
    $controller->overview();
} elseif ($param1 === 'rapport' && $param2 === null) {
    $controller->report();
} else {
    throw new Exception('Route non trouvée.', 404);
}

return true;
