-- =====================================================================
--  Migration : Espace Auditeur — comptes, notifications, rôle
--  Date : 2026-11-18
--  Appliquée par : database/apply_auditeur_migration.php
-- =====================================================================

USE academie_langues3;

-- 1. Colonne user_id dans auditeurs (liaison auditeur ↔ compte utilisateur)
ALTER TABLE auditeurs
  ADD COLUMN IF NOT EXISTS `user_id` INT UNSIGNED NULL,
  ADD UNIQUE KEY IF NOT EXISTS `uk_auditeurs_user` (`user_id`);

-- La contrainte FK n'existe peut-être pas encore
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auditeurs'
    AND CONSTRAINT_NAME = 'fk_auditeurs_user');
SET @sql_fk = IF(@fk_exists = 0,
  'ALTER TABLE auditeurs ADD CONSTRAINT fk_auditeurs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1');
PREPARE stmt FROM @sql_fk; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Colonne first_login dans users (popup 1ère connexion)
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS `first_login` TINYINT(1) NOT NULL DEFAULT 0;

-- 3. Table notifications
CREATE TABLE IF NOT EXISTS `notifications` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Rôle auditeur (si absent)
INSERT IGNORE INTO roles (code, nom) VALUES ('auditeur', 'Auditeur');

-- 5. Permissions minimales pour le rôle auditeur
SET @aud_role_id = (SELECT id FROM roles WHERE code = 'auditeur' LIMIT 1);
INSERT IGNORE INTO role_permissions (role_id, module_code, action_code) VALUES
  (@aud_role_id, 'evaluations', 'read'),
  (@aud_role_id, 'presences', 'read'),
  (@aud_role_id, 'emplois-temps', 'read');
