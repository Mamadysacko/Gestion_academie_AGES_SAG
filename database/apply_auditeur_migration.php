<?php
/**
 * Applique la migration pour l'espace auditeur.
 * Usage : php database/apply_auditeur_migration.php
 */

require_once __DIR__ . '/../config/config.php';

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$migrationName = 'migration_auditeur_comptes';

// Vérifier si déjà appliquée
$check = $pdo->prepare("SELECT 1 FROM schema_migrations WHERE nom = :nom LIMIT 1");
$check->execute(['nom' => $migrationName]);
if ($check->fetch()) {
    echo "Migration '{$migrationName}' déjà appliquée.\n";
    exit(0);
}

echo "Application de la migration '{$migrationName}'...\n";

// Exécuter les commandes individuellement (PDO ne supporte pas les multi-statements avec PREPARE/EXECUTE)
try {
    $pdo->beginTransaction();

    // 1. user_id dans auditeurs
    $cols = $pdo->query("SHOW COLUMNS FROM auditeurs LIKE 'user_id'")->fetch();
    if (!$cols) {
        $pdo->exec("ALTER TABLE auditeurs ADD COLUMN `user_id` INT UNSIGNED NULL");
        echo "  ✓ Colonne auditeurs.user_id ajoutée\n";
    }

    // Vérifier si l'index unique existe
    $idx = $pdo->query("SHOW INDEX FROM auditeurs WHERE Key_name = 'uk_auditeurs_user'")->fetch();
    if (!$idx) {
        $pdo->exec("ALTER TABLE auditeurs ADD UNIQUE KEY `uk_auditeurs_user` (`user_id`)");
        echo "  ✓ Index unique uk_auditeurs_user ajouté\n";
    }

    // Vérifier la FK
    $fkCheck = $pdo->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = 'auditeurs' AND CONSTRAINT_NAME = 'fk_auditeurs_user'"
    );
    $fkCheck->execute(['schema' => DB_NAME]);
    if (!$fkCheck->fetch()) {
        $pdo->exec("ALTER TABLE auditeurs ADD CONSTRAINT fk_auditeurs_user
                     FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE");
        echo "  ✓ FK fk_auditeurs_user ajoutée\n";
    }

    // 2. first_login dans users
    $cols2 = $pdo->query("SHOW COLUMNS FROM users LIKE 'first_login'")->fetch();
    if (!$cols2) {
        $pdo->exec("ALTER TABLE users ADD COLUMN `first_login` TINYINT(1) NOT NULL DEFAULT 0");
        echo "  ✓ Colonne users.first_login ajoutée\n";
    }

    // 3. Table notifications
    $tableExists = $pdo->query("SHOW TABLES LIKE 'notifications'")->fetch();
    if (!$tableExists) {
        $pdo->exec("CREATE TABLE `notifications` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `type` ENUM('info','alerte_note','alerte_presence','alerte_evaluation','bulletin') NOT NULL DEFAULT 'info',
            `titre` VARCHAR(200) COLLATE utf8mb4_unicode_ci NOT NULL,
            `message` TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
            `lu` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_notif_user_lu` (`user_id`, `lu`),
            CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "  ✓ Table notifications créée\n";
    }

    // 4. Rôle auditeur
    $pdo->exec("INSERT IGNORE INTO roles (code, nom) VALUES ('auditeur', 'Auditeur')");
    echo "  ✓ Rôle 'auditeur' assuré\n";

    // 5. Permissions auditeur
    $roleId = $pdo->query("SELECT id FROM roles WHERE code = 'auditeur' LIMIT 1")->fetchColumn();
    if ($roleId) {
        $perms = [
            ['evaluations', 'read'],
            ['presences', 'read'],
            ['emplois-temps', 'read'],
        ];
        $ins = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, module_code, action_code) VALUES (:rid, :mod, :act)");
        foreach ($perms as [$mod, $act]) {
            $ins->execute(['rid' => $roleId, 'mod' => $mod, 'act' => $act]);
        }
        echo "  ✓ Permissions auditeur configurées\n";
    }

    // Enregistrer la migration
    $pdo->prepare("INSERT INTO schema_migrations (nom) VALUES (:nom)")->execute(['nom' => $migrationName]);

    $pdo->commit();
    echo "\n✅ Migration '{$migrationName}' appliquée avec succès.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n❌ Erreur : " . $e->getMessage() . "\n";
    exit(1);
}
