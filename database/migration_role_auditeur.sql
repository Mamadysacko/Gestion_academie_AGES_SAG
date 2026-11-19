INSERT IGNORE INTO roles (code, nom) VALUES ('auditeur', 'Auditeur');

-- Permissions minimales pour le rôle auditeur
-- INSERT ... SELECT évite la dépendance à une variable de session (@aud_role_id),
-- qui peut être NULL si le runner MySQL exécute chaque instruction dans une connexion séparée.
INSERT IGNORE INTO role_permissions (role_id, module_code, action_code)
SELECT r.id, 'evaluations',   'read' FROM roles r WHERE r.code = 'auditeur'
UNION ALL
SELECT r.id, 'presences',     'read' FROM roles r WHERE r.code = 'auditeur'
UNION ALL
SELECT r.id, 'emplois-temps', 'read' FROM roles r WHERE r.code = 'auditeur';
