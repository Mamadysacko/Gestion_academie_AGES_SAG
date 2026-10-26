<?php
/**
 * Régénère « DB Gestion Academie.sql » (structure seule) à partir de la base configurée.
 *
 *   php database/export_schema.php
 *
 * Le fichier est lu par le centre d'aide du frontend (tools/featuresPlugin.js) et sert
 * à une installation neuve. Les compteurs AUTO_INCREMENT ne sont pas repris.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Script réservé à la ligne de commande.');
}

require_once __DIR__ . '/../config/Database.php';

$db = Database::getInstance();
$tables = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);

$out = "-- =====================================================================\n"
    . '--  Structure de la base ' . DB_NAME . ' (MySQL 8) — générée le ' . date('Y-m-d H:i') . "\n"
    . "--  Schéma seul (sans données). Lu par tools/featuresPlugin.js (centre d'aide).\n"
    . "--  Pour une nouvelle installation :  mysql -uroot -p < \"DB Gestion Academie.sql\"\n"
    . "--  Régénération : php database/export_schema.php\n"
    . "-- =====================================================================\n\n"
    . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n";

foreach ($tables as $table) {
    $create = $db->query("SHOW CREATE TABLE `{$table}`")->fetch()['Create Table'];
    $create = preg_replace('/ AUTO_INCREMENT=\d+/', '', $create);
    $out .= "\n--\n-- Table structure for table `{$table}`\n--\n\nDROP TABLE IF EXISTS `{$table}`;\n{$create};\n";
}
// Vues et déclencheurs, sans DEFINER ni nom de base (portables d'une installation à l'autre).
$portable = static fn(string $sql): string => str_replace('`' . DB_NAME . '`.', '', preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s*/', '', $sql));
foreach ($db->query("SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN) as $view) {
    $create = $portable($db->query("SHOW CREATE VIEW `{$view}`")->fetch()['Create View']);
    $out .= "\n--\n-- View structure for view `{$view}`\n--\n\nDROP VIEW IF EXISTS `{$view}`;\n{$create};\n";
}
$triggers = $db->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY EVENT_OBJECT_TABLE, TRIGGER_NAME")->fetchAll(PDO::FETCH_COLUMN);
if ($triggers !== []) {
    $out .= "\n--\n-- Déclencheurs de cohérence\n--\n\nDELIMITER $$\n";
    foreach ($triggers as $trigger) {
        $create = $portable($db->query("SHOW CREATE TRIGGER `{$trigger}`")->fetch()['SQL Original Statement']);
        $out .= "\nDROP TRIGGER IF EXISTS `{$trigger}`$$\n{$create}$$\n";
    }
    $out .= "\nDELIMITER ;\n";
}
$out .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";

file_put_contents(__DIR__ . '/DB Gestion Academie.sql', $out);
echo count($tables) . " tables exportées dans « DB Gestion Academie.sql ».\n";
