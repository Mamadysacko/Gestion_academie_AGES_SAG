<?php

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../config/Jwt.php';

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
        'planification' => 'emplois-temps',
        'formations' => 'organisation-pedagogique',
        'auditeurs' => 'auditeurs',
        'inscriptions' => 'auditeurs',
        'types-employes' => 'auditeurs',
        'positions' => 'auditeurs',
        'formateurs' => 'formateurs',
        'salles' => 'salles',
        'evaluations' => 'evaluations',
        'parametres' => 'evaluations',
        'parametres-evaluations' => 'evaluations',
        'parametres-evaluation' => 'evaluations',
        'notes' => 'evaluations',
        'passages-niveaux' => 'evaluations',
        'resultats' => 'evaluations',
        'regles-evaluation' => 'evaluations',
        'regles-passage' => 'evaluations',
        'baremes-appreciation' => 'evaluations',
        'presences' => 'presences',
        'tableau-de-bord' => 'organisation-pedagogique',
        'users' => 'utilisateurs',
        'roles' => 'utilisateurs',
        'journal' => 'utilisateurs',
        'rapports' => 'organisation-pedagogique',
    ];

    /** Routes POST /planification/{verbe} qui ne modifient rien (droit de lecture suffisant). */
    public const PLANNING_READ_ONLY = ['simuler', 'verifier', 'alternatives'];

    /** Module de permission dédié à l'approbation des passages de niveau (action : validate). */
    public const PASSAGE_MODULE = 'passages-niveau';

    /** Écritures qui décident ou annulent un passage de niveau. */
    public static function isPassageApproval(string $resource, string $method, ?string $param1): bool
    {
        if ($resource === 'resultats') {
            return $method === 'POST' && in_array($param1, ['valider', 'annuler'], true);
        }
        return $resource === 'passages-niveaux' && $method !== 'GET';
    }

    /** Jeton Bearer déjà vérifié pendant cette requête : [en-tête brut, revendications | null, erreur | null]. */
    private static ?array $bearer = null;

    /** En-tête « Authorization: Bearer … » (Apache le masque parfois : plusieurs sources sont lues). */
    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($header === null && function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }
        if (!is_string($header) || !preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $match)) {
            return null;
        }
        return $match[1];
    }

    /** Revendications du jeton d'accès présenté (null si absent) ; mémorisées pour la requête. */
    private static function bearerClaims(): ?array
    {
        $token = self::bearerToken();
        if ($token === null) {
            return null;
        }
        if (self::$bearer === null || self::$bearer[0] !== $token) {
            try {
                $claims = Jwt::decode($token);
                if (($claims['typ'] ?? null) !== 'access' || !isset($claims['sub'])) {
                    throw new UnexpectedValueException('Ce jeton n’est pas un jeton d’accès.');
                }
                self::$bearer = [$token, $claims, null];
            } catch (UnexpectedValueException $e) {
                self::$bearer = [$token, null, $e->getMessage()];
            }
        }
        return self::$bearer[1];
    }

    /** Message d'erreur du jeton Bearer présenté (null s'il est valide ou absent). */
    public static function bearerError(): ?string
    {
        if (self::bearerToken() === null) {
            return null;
        }
        self::bearerClaims();
        return self::$bearer[2];
    }

    /** Identifiant de l'utilisateur authentifié : jeton JWT d'abord, cookie de session ensuite. */
    public static function userId(): ?int
    {
        if (self::bearerToken() !== null) {
            $claims = self::bearerClaims();
            return $claims === null ? null : (int) $claims['sub'];
        }
        if (AUTH_SESSION_FALLBACK && !empty($_SESSION['user_id'])) {
            return (int) $_SESSION['user_id'];
        }
        return null;
    }

    /**
     * Émet un jeton d'accès (courte durée) et un jeton de renouvellement (longue durée, révocable).
     * @return array{access_token:string, token_type:string, expires_in:int, refresh_token:string, refresh_expires_in:int}
     */
    public static function issueTokens(array $user, ?string $refreshToken = null): array
    {
        require_once __DIR__ . '/../Model/RefreshToken.php';
        $access = Jwt::encode([
            'typ' => 'access',
            'sub' => (int) $user['id'],
            'role' => $user['role_code'] ?? null,
            'ver' => (int) ($user['token_version'] ?? 0),
        ], JWT_ACCESS_TTL);

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => JWT_ACCESS_TTL,
            'refresh_token' => $refreshToken ?? (new RefreshToken())->issue((int) $user['id']),
            'refresh_expires_in' => JWT_REFRESH_TTL,
        ];
    }

    /** Oublie le jeton mémorisé (tests, ou changement d'en-tête dans le même processus). */
    public static function resetRequestState(): void
    {
        self::$bearer = null;
    }

    public static function currentUser(): ?array
    {
        $userId = self::userId();
        if ($userId === null) {
            return null;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT u.id, u.nom, u.prenom, u.email, u.telephone, u.actif, u.token_version,
                    u.last_login, u.created_at, u.updated_at, u.role_id,
                    r.code AS role_code, r.nom AS role_nom,
                    (SELECT f.id FROM formateurs f WHERE f.user_id = u.id ORDER BY f.id LIMIT 1) AS formateur_id,
                    (SELECT f.matricule FROM formateurs f WHERE f.user_id = u.id ORDER BY f.id LIMIT 1) AS formateur_matricule
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user || !(bool) $user['actif']) {
            if (self::bearerToken() === null) {
                self::logout();
            }
            return null;
        }
        // Jeton émis avant une déconnexion globale ou un changement de mot de passe : refusé.
        $claims = self::bearerToken() !== null ? self::bearerClaims() : null;
        if ($claims !== null && (int) ($claims['ver'] ?? 0) !== (int) $user['token_version']) {
            self::$bearer[1] = null;
            self::$bearer[2] = 'Jeton révoqué : reconnectez-vous.';
            return null;
        }
        unset($user['token_version']);

        $user['permissions'] = self::permissionsForRole((int) $user['role_id']);
        return $user;
    }

    /** L'utilisateur courant (administrateur ou rôle autorisé) dispose-t-il de cette action ? */
    public static function can(string $module, string $action): bool
    {
        $user = self::currentUser();
        if ($user === null) {
            return false;
        }
        return $user['role_code'] === 'admin' || in_array($action, $user['permissions'][$module] ?? [], true);
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
            $tokenError = self::bearerError();
            throw new RuntimeException($tokenError !== null ? "Jeton d’accès refusé : {$tokenError}" : 'Authentification requise.', 401);
        }

        if ($user['role_code'] === 'admin') {
            return;
        }

        // Approbation des passages de niveau : réservée à l'administrateur, ou aux rôles auxquels
        // il a donné le droit « Valider » du module « Passages de niveau ».
        if (self::isPassageApproval($resource, $method, $param1)) {
            if (!in_array('validate', $user['permissions'][self::PASSAGE_MODULE] ?? [], true)) {
                throw new RuntimeException('L’approbation des passages de niveau est réservée à l’administrateur et aux utilisateurs à qui ce droit a été accordé.', 403);
            }
            return;
        }

        if ($resource === 'notes' && $user['role_code'] === 'formateur') {
            throw new RuntimeException(
                'Les formateurs doivent saisir les notes depuis leur espace, où les inscriptions sont limitées à leurs classes.',
                403
            );
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
        // Simulations et vérifications de planning : calculs sans écriture.
        if ($resource === 'planification' && in_array($param1, self::PLANNING_READ_ONLY, true)) {
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
