<?php

require_once __DIR__ . '/../config/Database.php';

final class Authorization
{
    private const RESOURCE_MODULES = [
        'annees-scolaires' => 'organisation-pedagogique',
        'departements' => 'organisation-pedagogique',
        'niveaux' => 'organisation-pedagogique',
        'types-formations' => 'organisation-pedagogique',
        'types-formation' => 'organisation-pedagogique',
        'sessions' => 'organisation-pedagogique',
        'groupes' => 'organisation-pedagogique',
        'emplois-du-temps' => 'emplois-temps',
        'auditeurs' => 'auditeurs',
        'inscriptions' => 'auditeurs',
        'formateurs' => 'formateurs',
        'salles' => 'salles',
        'evaluations' => 'evaluations',
        'parametres' => 'evaluations',
        'parametres-evaluations' => 'evaluations',
        'parametres-evaluation' => 'evaluations',
        'notes' => 'evaluations',
        'passages-niveaux' => 'evaluations',
        'presences' => 'presences',
        'users' => 'utilisateurs',
        'roles' => 'utilisateurs',
    ];

    public static function currentUser(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT u.id, u.nom, u.prenom, u.email, u.telephone, u.actif,
                    u.last_login, u.role_id, r.code AS role_code, r.nom AS role_nom,
                    (SELECT f.id FROM formateurs f WHERE f.user_id = u.id ORDER BY f.id LIMIT 1) AS formateur_id,
                    (SELECT f.matricule FROM formateurs f WHERE f.user_id = u.id ORDER BY f.id LIMIT 1) AS formateur_matricule
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => (int) $_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user || !(bool) $user['actif']) {
            self::logout();
            return null;
        }

        $user['permissions'] = self::permissionsForRole((int) $user['role_id']);
        return $user;
    }

    public static function permissionsForRole(int $roleId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT module_code, action_code FROM role_permissions WHERE role_id = :role_id'
        );
        $stmt->execute(['role_id' => $roleId]);

        $permissions = [];
        foreach ($stmt->fetchAll() as $permission) {
            $permissions[$permission['module_code']][] = $permission['action_code'];
        }

        return $permissions;
    }

    public static function authorizeRequest(string $resource, string $method, ?string $param1, ?string $param2): void
    {
        if ($resource === 'auth' || ($resource === 'users' && $param1 === 'authenticate')) {
            return;
        }

        $module = self::RESOURCE_MODULES[$resource] ?? null;
        if ($module === null) {
            return;
        }

        $user = self::currentUser();
        if ($user === null) {
            throw new RuntimeException('Authentification requise.', 401);
        }

        if ($user['role_code'] === 'admin') {
            return;
        }

        $action = self::actionForRequest($method, $resource, $param1, $param2);
        if (!in_array($action, $user['permissions'][$module] ?? [], true)) {
            throw new RuntimeException('Accès refusé pour cette action.', 403);
        }
    }

    private static function actionForRequest(string $method, string $resource, ?string $param1, ?string $param2): string
    {
        if ($method === 'GET') {
            return 'read';
        }
        if ($method === 'DELETE') {
            return 'delete';
        }
        if ($method === 'POST' && in_array($param1, ['decide', 'validate', 'valider', 'cloturer'], true)) {
            return 'validate';
        }
        if ($method === 'POST' && $resource === 'parametres' && $param1 === 'evaluation') {
            return 'update';
        }
        if ($method === 'PUT' || ($method === 'POST' && (
            in_array($param1, ['save-or-update', 'statut'], true) ||
            $param2 === 'statut' ||
            $param2 === 'toggle-actif'
        ))) {
            return 'update';
        }
        if ($method === 'POST') {
            return 'create';
        }

        return 'read';
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
