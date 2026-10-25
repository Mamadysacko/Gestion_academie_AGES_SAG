<?php
/**
 * Modèle Role (table : roles)
 */

require_once __DIR__ . '/Model.php';

class Role extends Model
{
    protected string $table = 'roles';

    protected array $fillable = [
        'code', 'nom',
    ];

    /** Trouve un rôle par son code unique (ex. 'admin', 'scolarite'). */
    public function findByCode(string $code): ?array
    {
        return $this->fetchOne(
            "SELECT * FROM {$this->table} WHERE code = :code LIMIT 1",
            ['code' => $code]
        );
    }

    public function getAllWithPermissions(): array
    {
        $roles = $this->fetchAll(
            "SELECT r.*, COUNT(u.id) AS users_count
             FROM {$this->table} r
             LEFT JOIN users u ON u.role_id = r.id
             GROUP BY r.id
             ORDER BY r.nom ASC"
        );

        foreach ($roles as &$role) {
            $role['permissions'] = $this->getPermissionsMap((int) $role['id']);
        }
        unset($role);

        return $roles;
    }

    public function getPermissionsMap(int $roleId): array
    {
        $rows = $this->fetchAll(
            'SELECT module_code, action_code FROM role_permissions WHERE role_id = :role_id',
            ['role_id' => $roleId]
        );

        $permissions = [];
        foreach ($rows as $row) {
            $permissions[$row['module_code']][] = $row['action_code'];
        }

        return $permissions;
    }

    public function savePermissions(int $roleId, array $permissions): void
    {
        $moduleActions = [
            'organisation-pedagogique' => ['read', 'create', 'update', 'delete', 'export', 'validate'],
            'emplois-temps' => ['read', 'create', 'update', 'delete', 'export', 'validate'],
            'auditeurs' => ['read', 'create', 'update', 'delete', 'export', 'validate'],
            'formateurs' => ['read', 'create', 'update', 'delete', 'export', 'validate'],
            'salles' => ['read', 'create', 'update', 'delete', 'export', 'validate'],
            'evaluations' => ['read', 'create', 'update', 'delete', 'export', 'validate'],
            'presences' => ['read', 'create', 'update', 'delete', 'export', 'validate'],
            'utilisateurs' => ['read', 'create', 'update', 'delete', 'export', 'validate'],
            'parametres' => ['read', 'create', 'update', 'delete', 'export', 'validate'],
            'passages-niveau' => ['validate'],
        ];

        $db = $this->db;
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            $db->beginTransaction();
        }
        try {
            $delete = $db->prepare('DELETE FROM role_permissions WHERE role_id = :role_id');
            $delete->execute(['role_id' => $roleId]);
            $insert = $db->prepare(
                'INSERT INTO role_permissions (role_id, module_code, action_code)
                 VALUES (:role_id, :module_code, :action_code)'
            );

            foreach ($permissions as $module => $actions) {
                if (!isset($moduleActions[$module]) || !is_array($actions)) {
                    throw new InvalidArgumentException("Module de permission invalide : {$module}.");
                }

                foreach (array_unique($actions) as $action) {
                    if (!is_string($action) || !in_array($action, $moduleActions[$module], true)) {
                        throw new InvalidArgumentException("Action de permission invalide pour {$module}.");
                    }
                    $insert->execute([
                        'role_id' => $roleId,
                        'module_code' => $module,
                        'action_code' => $action,
                    ]);
                }
            }

            if ($ownsTransaction) {
                $db->commit();
            }
        } catch (Throwable $error) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    public function getDatabase(): PDO
    {
        return $this->db;
    }

    public function hasUsers(int $roleId): bool
    {
        return (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM users WHERE role_id = :role_id',
            ['role_id' => $roleId]
        ) > 0;
    }

    public function isReserved(int $roleId): bool
    {
        return $this->fetchOne(
            "SELECT 1 FROM {$this->table} WHERE id = :id AND code = 'admin' LIMIT 1",
            ['id' => $roleId]
        ) !== null;
    }

    public function findByNom(string $nom): ?array
    {
        return $this->fetchOne(
            "SELECT * FROM {$this->table} WHERE nom = :nom LIMIT 1",
            ['nom' => $nom]
        );
    }
}
