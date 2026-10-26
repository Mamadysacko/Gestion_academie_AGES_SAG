<?php
/**
 * Applique un fichier de migration sur la base configurée (config/config.php).
 *
 *   php database/apply_migration.php migration_2026_10_planification_jwt.sql
 *   php database/apply_migration.php migration_2026_10_hachage_mots_de_passe.php
 *   php database/apply_migration.php rollback_xxx.sql --force     (rejoue même si déjà enregistrée)
 *
 * - .sql : instructions séparées par « ; » en fin de ligne ; la directive « DELIMITER $$ » permet
 *   d'écrire des déclencheurs (corps contenant des « ; »).
 * - .php : le fichier retourne une fonction function (PDO $db): string, exécutée dans une transaction.
 *
 * Chaque migration n'est appliquée qu'une fois : son nom est enregistré dans `schema_migrations`
 * (un fichier rollback_* retire l'enregistrement de la migration qu'il annule).
 * Remarque : MySQL valide implicitement chaque ALTER/CREATE (DDL) ; en cas d'échec au milieu,
 * restaurez la sauvegarde faite avant la migration (database/backups/).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Script réservé à la ligne de commande.');
}

require_once __DIR__ . '/../config/Database.php';

$file = $argv[1] ?? '';
$force = in_array('--force', $argv, true);
$path = is_file($file) ? $file : __DIR__ . '/' . $file;
if ($file === '' || !is_file($path)) {
    fwrite(STDERR, "Usage : php database/apply_migration.php <fichier.sql|fichier.php> [--force]\n");
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
if ($done->fetchColumn() && !$force) {
    echo "Déjà appliquée : {$name}\n";
    exit(0);
}

if (str_ends_with($name, '.php')) {
    $migration = require $path;
    $summary = Database::transaction(static fn(PDO $pdo) => $migration($pdo));
    $db->prepare('INSERT IGNORE INTO schema_migrations (nom) VALUES (:nom)')->execute(['nom' => $name]);
    echo "Migration appliquée : {$name} — {$summary}\n";
    exit(0);
}

$statements = splitStatements((string) file_get_contents($path));
foreach ($statements as $index => $statement) {
    try {
        $db->exec($statement);
    } catch (PDOException $e) {
        fwrite(STDERR, sprintf("Échec à l'instruction %d/%d : %s\n---\n%s\n", $index + 1, count($statements), $e->getMessage(), mb_substr($statement, 0, 600)));
        exit(2);
    }
}

if (str_starts_with($name, 'rollback_')) {
    $target = 'migration_' . substr($name, strlen('rollback_'));
    $db->prepare('DELETE FROM schema_migrations WHERE nom = :nom')->execute(['nom' => $target]);
    echo "Retour arrière appliqué : {$name} (" . count($statements) . " instructions, {$target} retirée du registre)\n";
} else {
    $db->prepare('INSERT IGNORE INTO schema_migrations (nom) VALUES (:nom)')->execute(['nom' => $name]);
    echo "Migration appliquée : {$name} (" . count($statements) . " instructions)\n";
}

/** Découpe un script SQL en instructions, en respectant la directive DELIMITER. */
function splitStatements(string $sql): array
{
    $statements = [];
    $delimiter = ';';
    $buffer = '';
    foreach (preg_split('/\R/', $sql) as $line) {
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match)) {
            $delimiter = $match[1];
            continue;
        }
        if ($buffer === '' && preg_match('/^\s*(--|$)/', $line)) {
            continue; // commentaire ou ligne vide hors instruction
        }
        $trimmed = rtrim($line);
        if (str_ends_with($trimmed, $delimiter)) {
            $buffer .= substr($trimmed, 0, -strlen($delimiter));
            $statement = trim(preg_replace('/^\s*--.*$/m', '', $buffer));
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
        } else {
            $buffer .= $line . "\n";
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }
    return $statements;
}
