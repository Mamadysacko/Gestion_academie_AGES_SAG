<?php
/**
 * Amorçage de PHPUnit.
 *
 * Il vise TOUJOURS la base de test : la variable AGES_DB_NAME est lue par config/config.php.
 * Aucune connexion MySQL n'est ouverte ici — les tests unitaires n'ont donc pas besoin de la base ;
 * les tests d'intégration la préparent eux-mêmes (tests/Support/ControllerTestCase.php).
 */

putenv('AGES_DB_NAME=academie_langues3_test');

require_once __DIR__ . '/../vendor/autoload.php';

// config.php démarre la session PHP ; PHPUnit a déjà affiché sa bannière, ce que PHP signale par un
// avertissement sans importance dans ce contexte : on l'ignore seulement pendant ce chargement.
set_error_handler(static fn(int $no, string $message): bool => str_contains($message, 'Session cannot be started'));
require_once __DIR__ . '/../config/config.php';
restore_error_handler();

if (DB_NAME !== 'academie_langues3_test') {
    fwrite(STDERR, 'ARRÊT : les tests doivent viser academie_langues3_test, pas « ' . DB_NAME . " ».\n");
    exit(2);
}

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../Controller/Authorization.php';

// Même chargement à la demande que Route/routes/bootstrap.php (contrôleurs puis modèles).
spl_autoload_register(static function (string $class): void {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $class)) {
        return;
    }
    foreach ([__DIR__ . '/../Controller/', __DIR__ . '/../Model/'] as $dir) {
        if (is_file($dir . $class . '.php')) {
            require_once $dir . $class . '.php';
            return;
        }
    }
});

// Classes d'aide des tests (sans espace de noms, un fichier par classe).
require_once __DIR__ . '/Support/PhpInput.php';
require_once __DIR__ . '/Support/ControllerTestCase.php';
