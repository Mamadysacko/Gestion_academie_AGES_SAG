<?php
/**
 * Construit la base de test : (re)crée `academie_langues3_test`, y copie la STRUCTURE de la vraie base
 * (tables, index, clés étrangères — la vraie base n'est jamais modifiée) puis charge fixtures.sql.
 *
 *   php tests/database/setup_test_db.php        (reconstruit seulement la base de test)
 *
 * Appelée automatiquement par tests/run_controller_tests.php.
 */

const TEST_DB_NAME = 'academie_langues3_test';

function buildTestDatabase(): array
{
    require_once __DIR__ . '/../../config/config.php';

    $liveName = 'academie_langues3';
    if (TEST_DB_NAME === $liveName || !str_ends_with(TEST_DB_NAME, '_test')) {
        throw new RuntimeException('Garde-fou : la base de test doit finir par « _test » et différer de la vraie base.');
    }

    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    $server = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, $options);

    // 1. Structure de la vraie base (lecture seule)
    $tables = $server->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$liveName}' AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    if (!$tables) {
        throw new RuntimeException("La base « {$liveName} » est introuvable ou vide : impossible de copier sa structure.");
    }
    $definitions = [];
    foreach ($tables as $table) {
        $definitions[$table] = $server->query("SHOW CREATE TABLE `{$liveName}`.`{$table}`")->fetch()['Create Table'];
    }

    // 2. Base de test neuve
    $server->exec('DROP DATABASE IF EXISTS `' . TEST_DB_NAME . '`');
    $server->exec('CREATE DATABASE `' . TEST_DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $server->exec('USE `' . TEST_DB_NAME . '`');
    $server->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($definitions as $sql) {
        // Les compteurs d'identifiants de la vraie base ne sont pas repris : les ids de test repartent de 1.
        $server->exec(preg_replace('/ AUTO_INCREMENT=\d+/', '', $sql));
    }

    // 3. Données de test
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(__DIR__ . '/fixtures.sql'));
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $statement) {
        $server->exec($statement);
    }
    $server->exec('SET FOREIGN_KEY_CHECKS = 1');

    return ['tables' => count($tables), 'instructions' => count($statements)];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $result = buildTestDatabase();
    echo "Base « " . TEST_DB_NAME . " » prête : {$result['tables']} tables, {$result['instructions']} instructions de données.\n";
}
