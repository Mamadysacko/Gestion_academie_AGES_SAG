<?php
/**
 * Applique un fichier de migration SQL sur la base configurée (config/config.php).
 *
 *   php database/apply_migration.php migration_2026_10_planification_jwt.sql
 *
 * Chaque migration n'est appliquée qu'une fois : son nom est enregistré dans `schema_migrations`.
 * Les instructions sont séparées par un « ; » en fin de ligne.
 * Remarque : MySQL valide implicitement chaque ALTER/CREATE (DDL) ; en cas d'échec au milieu,
 * restaurez la sauvegarde faite avant la migration (database/backups/).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Script réservé à la ligne de commande.');
}

require_once __DIR__ . '/../config/Database.php';

$file = $argv[1] ?? '';
$path = is_file($file) ? $file : __DIR__ . '/' . $file;
if ($file === '' || !is_file($path)) {
    fwrite(STDERR, "Usage : php database/apply_migration.php <fichier.sql>\n");
    exit(1);
}

$db = Database::getInstance();
$db->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        nom varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
        applied_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$name = basename($path);
$done = $db->prepare('SELECT 1 FROM schema_migrations WHERE nom = :nom');
$done->execute(['nom' => $name]);
if ($done->fetchColumn()) {
    echo "Déjà appliquée : {$name}\n";
    exit(0);
}

$sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($path));
$statements = array_values(array_filter(array_map('trim', preg_split('/;\s*$/m', $sql))));

foreach ($statements as $index => $statement) {
    try {
        $db->exec($statement);
    } catch (PDOException $e) {
        fwrite(STDERR, sprintf("Échec à l'instruction %d/%d : %s\n---\n%s\n", $index + 1, count($statements), $e->getMessage(), mb_substr($statement, 0, 600)));
        exit(2);
    }
}

$db->prepare('INSERT INTO schema_migrations (nom) VALUES (:nom)')->execute(['nom' => $name]);
echo "Migration appliquée : {$name} (" . count($statements) . " instructions)\n";
