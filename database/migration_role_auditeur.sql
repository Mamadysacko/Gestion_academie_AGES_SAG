INSERT IGNORE INTO roles (code, nom) VALUES ('auditeur', 'Auditeur');

-- Permissions minimales pour le rôle auditeur
SET @aud_role_id = (SELECT id FROM roles WHERE code = 'auditeur' LIMIT 1);

INSERT IGNORE INTO role_permissions (role_id, module_code, action_code)
VALUES
  (@aud_role_id, 'evaluations',    'read'),
  (@aud_role_id, 'presences',      'read'),
  (@aud_role_id, 'emplois-temps',  'read');
