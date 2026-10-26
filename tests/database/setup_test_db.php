<?php
/**
 * Construit la base de test : (re)crée `academie_langues3_test`, y copie la STRUCTURE de la vraie base
 * (tables, index, clés étrangères, vues, déclencheurs — la vraie base n'est jamais modifiée) puis charge
 * fixtures.sql. AGES_SCHEMA_SOURCE permet de copier la structure d'une autre base (ex. une copie sur
 * laquelle une migration est essayée avant d'être appliquée).
 *
 *   php tests/database/setup_test_db.php        (reconstruit seulement la base de test)
 *
 * Appelée automatiquement par tests/run_controller_tests.php.
 */

const TEST_DB_NAME = 'academie_langues3_test';

function buildTestDatabase(): array
{
    require_once __DIR__ . '/../../config/config.php';

    $liveName = getenv('AGES_SCHEMA_SOURCE') ?: 'academie_langues3';
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
    // Vues et déclencheurs : sans DEFINER ni nom de base, pour être recréés dans la base de test.
    $portable = static fn(string $sql): string => str_replace("`{$liveName}`.", '', preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s*/', '', $sql));
    $views = [];
    foreach ($server->query("SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = '{$liveName}' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN) as $view) {
        $views[] = $portable($server->query("SHOW CREATE VIEW `{$liveName}`.`{$view}`")->fetch()['Create View']);
    }
    $triggers = [];
    foreach ($server->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '{$liveName}' ORDER BY TRIGGER_NAME")->fetchAll(PDO::FETCH_COLUMN) as $trigger) {
        $triggers[] = $portable($server->query("SHOW CREATE TRIGGER `{$liveName}`.`{$trigger}`")->fetch()['SQL Original Statement']);
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
    foreach ($views as $sql) {
        $server->exec($sql);
    }

    // 3. Données de test
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(__DIR__ . '/fixtures.sql'));
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $statement) {
        $server->exec($statement);
    }
    $server->exec('SET FOREIGN_KEY_CHECKS = 1');
    // Déclencheurs après les données de test : ils contrôlent ensuite chaque écriture des tests.
    foreach ($triggers as $sql) {
        $server->exec($sql);
    }

    return ['tables' => count($tables), 'instructions' => count($statements)];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $result = buildTestDatabase();
    echo "Base « " . TEST_DB_NAME . " » prête : {$result['tables']} tables, {$result['instructions']} instructions de données.\n";
}
