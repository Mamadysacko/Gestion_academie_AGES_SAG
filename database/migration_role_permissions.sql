-- Persists module/action permissions for each role.
-- Safe to run repeatedly; existing roles and users are preserved.

CREATE TABLE IF NOT EXISTS `role_permissions` (
    `role_id` INT UNSIGNED NOT NULL,
    `module_code` VARCHAR(50) NOT NULL,
    `action_code` VARCHAR(20) NOT NULL,
    PRIMARY KEY (`role_id`, `module_code`, `action_code`),
    CONSTRAINT `fk_role_permissions_role`
        FOREIGN KEY (`role_id`)
        REFERENCES `roles` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial full access for the reserved admin role; existing role IDs are looked up by code.
INSERT IGNORE INTO `role_permissions` (`role_id`, `module_code`, `action_code`)
SELECT r.`id`, p.`module_code`, p.`action_code`
FROM `roles` r
CROSS JOIN (
    SELECT 'organisation-pedagogique' AS module_code, 'read' AS action_code
    UNION ALL SELECT 'organisation-pedagogique', 'create'
    UNION ALL SELECT 'organisation-pedagogique', 'update'
    UNION ALL SELECT 'organisation-pedagogique', 'delete'
    UNION ALL SELECT 'organisation-pedagogique', 'export'
    UNION ALL SELECT 'organisation-pedagogique', 'validate'
    UNION ALL SELECT 'emplois-temps', 'read'
    UNION ALL SELECT 'emplois-temps', 'create'
    UNION ALL SELECT 'emplois-temps', 'update'
    UNION ALL SELECT 'emplois-temps', 'delete'
    UNION ALL SELECT 'emplois-temps', 'export'
    UNION ALL SELECT 'emplois-temps', 'validate'
    UNION ALL SELECT 'auditeurs', 'read'
    UNION ALL SELECT 'auditeurs', 'create'
    UNION ALL SELECT 'auditeurs', 'update'
    UNION ALL SELECT 'auditeurs', 'delete'
    UNION ALL SELECT 'auditeurs', 'export'
    UNION ALL SELECT 'auditeurs', 'validate'
    UNION ALL SELECT 'formateurs', 'read'
    UNION ALL SELECT 'formateurs', 'create'
    UNION ALL SELECT 'formateurs', 'update'
    UNION ALL SELECT 'formateurs', 'delete'
    UNION ALL SELECT 'formateurs', 'export'
    UNION ALL SELECT 'formateurs', 'validate'
    UNION ALL SELECT 'salles', 'read'
    UNION ALL SELECT 'salles', 'create'
    UNION ALL SELECT 'salles', 'update'
    UNION ALL SELECT 'salles', 'delete'
    UNION ALL SELECT 'salles', 'export'
    UNION ALL SELECT 'salles', 'validate'
    UNION ALL SELECT 'evaluations', 'read'
    UNION ALL SELECT 'evaluations', 'create'
    UNION ALL SELECT 'evaluations', 'update'
    UNION ALL SELECT 'evaluations', 'delete'
    UNION ALL SELECT 'evaluations', 'export'
    UNION ALL SELECT 'evaluations', 'validate'
    UNION ALL SELECT 'presences', 'read'
    UNION ALL SELECT 'presences', 'create'
    UNION ALL SELECT 'presences', 'update'
    UNION ALL SELECT 'presences', 'delete'
    UNION ALL SELECT 'presences', 'export'
    UNION ALL SELECT 'presences', 'validate'
    UNION ALL SELECT 'utilisateurs', 'read'
    UNION ALL SELECT 'utilisateurs', 'create'
    UNION ALL SELECT 'utilisateurs', 'update'
    UNION ALL SELECT 'utilisateurs', 'delete'
    UNION ALL SELECT 'utilisateurs', 'export'
    UNION ALL SELECT 'utilisateurs', 'validate'
    UNION ALL SELECT 'parametres', 'read'
    UNION ALL SELECT 'parametres', 'create'
    UNION ALL SELECT 'parametres', 'update'
    UNION ALL SELECT 'parametres', 'delete'
    UNION ALL SELECT 'parametres', 'export'
    UNION ALL SELECT 'parametres', 'validate'
) p
WHERE r.`code` = 'admin';
