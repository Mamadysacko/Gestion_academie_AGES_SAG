<?php
/**
 * Modèle User (table : users)
 */

require_once __DIR__ . '/Model.php';

class User extends Model
{
    protected string $table = 'users';

    protected array $fillable = [
        'nom', 'prenom', 'email', 'telephone', 'password', 'role_id', 'actif', 'last_login',
    ];

    /** Crée un utilisateur ; le mot de passe est toujours enregistré haché. */
    public function create(array $data): int
    {
        if (isset($data['password'])) {
            $data['password'] = self::hashPassword((string) $data['password']);
        }
        return parent::create($data);
    }

    /** Empreinte password_hash() ; une valeur déjà hachée est conservée telle quelle. */
    public static function hashPassword(string $password): string
    {
        return password_get_info($password)['algo'] !== null ? $password : password_hash($password, PASSWORD_DEFAULT);
    }

    /** Téléphone comparable : sans espaces, tirets, points ni « + » (comme la colonne telephone_normalise). */
    public static function normalizePhone(string $phone): string
    {
        return str_replace([' ', '-', '.', '+'], '', trim($phone));
    }

    /**
     * Met à jour un utilisateur. Un mot de passe vide est ignoré afin de conserver
     * le mot de passe actuel.
     */
    public function update(int $id, array $data): bool
    {
        if (array_key_exists('password', $data)) {
            if ($data['password'] === '' || $data['password'] === null) {
                unset($data['password']);
            } else {
                $data['password'] = self::hashPassword((string) $data['password']);
            }
        }
        return parent::update($id, $data);
    }

    /** Tous les utilisateurs avec le libellé de leur rôle. */
    public function getAllWithRole(): array
    {
        return $this->fetchAll(
            "SELECT u.id, u.nom, u.prenom, u.email, u.telephone, u.actif,
                    u.last_login, u.created_at, u.updated_at, u.role_id,
                    r.code AS role_code, r.nom AS role_nom,
                    (SELECT f.id FROM formateurs f WHERE f.user_id = u.id ORDER BY f.id LIMIT 1) AS formateur_id,
                    (SELECT f.matricule FROM formateurs f WHERE f.user_id = u.id ORDER BY f.id LIMIT 1) AS formateur_matricule
             FROM {$this->table} u
             JOIN roles r ON r.id = u.role_id
             ORDER BY u.nom, u.prenom"
        );
    }

    /** Trouve un utilisateur par son email. */
    public function findByEmail(string $email): ?array
    {
        return $this->fetchOne(
            "SELECT * FROM {$this->table} WHERE email = :email LIMIT 1",
            ['email' => $email]
        );
    }

    /**
     * Ferme toutes les sessions d'un compte (nouveau mot de passe défini par un administrateur) :
     * jetons de renouvellement révoqués, jetons d'accès invalidés par la version du compte.
     */
    public function revokeSessions(int $id): void
    {
        require_once __DIR__ . '/RefreshToken.php';
        (new RefreshToken())->revokeAllForUser($id, 'mot_de_passe');
        $this->db->prepare("UPDATE {$this->table} SET token_version = token_version + 1 WHERE id = :id")->execute(['id' => $id]);
    }

    /** Compte utilisant ce numéro, quelle que soit sa présentation (espaces, tirets…). */
    public function findByPhone(string $phone): ?array
    {
        $normalized = self::normalizePhone($phone);
        if ($normalized === '') {
            return null;
        }
        return $this->fetchOne(
            "SELECT id, nom, prenom FROM {$this->table} WHERE telephone_normalise = :phone LIMIT 1",
            ['phone' => $normalized]
        );
    }

    /**
     * Authentifie un utilisateur par email ou téléphone + mot de passe.
     * @return array|null L'utilisateur (sans le hash) si succès, null sinon.
     */
    public function authenticate(string $identifier, string $password): ?array
    {
        $user = $this->fetchOne(
            "SELECT u.*, r.code AS role_code, r.nom AS role_nom
             FROM {$this->table} u
             JOIN roles r ON r.id = u.role_id
             WHERE u.email = :email OR u.telephone_normalise = :telephone
             LIMIT 1",
            ['email' => mb_strtolower($identifier), 'telephone' => self::normalizePhone($identifier)]
        );

        if ($user === null || !(bool) $user['actif'] || !password_verify($password, (string) $user['password'])) {
            return null;
        }
        if (password_needs_rehash((string) $user['password'], PASSWORD_DEFAULT)) {
            $this->db->prepare("UPDATE {$this->table} SET password = :hash WHERE id = :id")
                ->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => (int) $user['id']]);
        }

        $this->updateLastLogin((int) $user['id']);
        $user['permissions'] = $this->getPermissionsForRole((int) $user['role_id']);
        unset($user['password']);

        return $user;
    }

    public function getPermissionsForRole(int $roleId): array
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

    public function roleExists(int $roleId): bool
    {
        return $this->fetchOne('SELECT 1 FROM roles WHERE id = :id LIMIT 1', ['id' => $roleId]) !== null;
    }

    public function getRoleCode(int $roleId): ?string
    {
        $code = $this->fetchColumn('SELECT code FROM roles WHERE id = :id LIMIT 1', ['id' => $roleId]);
        return $code === false ? null : (string) $code;
    }

    public function getPasswordForSync(int $userId): ?string
    {
        $password = $this->fetchColumn(
            'SELECT password FROM users WHERE id = :id LIMIT 1',
            ['id' => $userId]
        );
        return $password === false ? null : (string) $password;
    }

    public function getDatabase(): PDO
    {
        return $this->db;
    }

    public function findWithRole(int $id): ?array
    {
        return $this->fetchOne(
            "SELECT u.id, u.nom, u.prenom, u.email, u.telephone, u.actif, u.token_version,
                    u.last_login, u.created_at, u.updated_at, u.role_id,
                    r.code AS role_code, r.nom AS role_nom,
                    (SELECT f.id FROM formateurs f WHERE f.user_id = u.id ORDER BY f.id LIMIT 1) AS formateur_id,
                    (SELECT f.matricule FROM formateurs f WHERE f.user_id = u.id ORDER BY f.id LIMIT 1) AS formateur_matricule
             FROM {$this->table} u
             JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id LIMIT 1",
            ['id' => $id]
        );
    }

    public function isLastActiveAdmin(int $id): bool
    {
        $user = $this->findWithRole($id);
        if (!$user || $user['role_code'] !== 'admin' || !(bool) $user['actif']) {
            return false;
        }
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM {$this->table} u
             JOIN roles r ON r.id = u.role_id
             WHERE r.code = 'admin' AND u.actif = 1"
        ) <= 1;
    }

    /** Met à jour la date de dernière connexion. */
    public function updateLastLogin(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE {$this->table} SET last_login = NOW() WHERE id = :id"
        );
        return $stmt->execute(['id' => $id]);
    }

    /** Retourne les utilisateurs d'un rôle donné. */
    public function getByRole(int $role_id): array
    {
        return $this->fetchAll(
            "SELECT u.id, u.nom, u.prenom, u.email, u.telephone, u.actif, u.last_login, u.role_id
             FROM {$this->table} u
             WHERE u.role_id = :role_id
             ORDER BY u.nom, u.prenom",
            ['role_id' => $role_id]
        );
    }

    /** Active / désactive un utilisateur. Retourne le nouvel état (1/0) ou null si introuvable. */
    public function toggleActif(int $id): ?int
    {
        $stmt = $this->db->prepare(
            "UPDATE {$this->table} SET actif = 1 - actif WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() === 0) {
            return null;
        }
        return (int) $this->fetchColumn(
            "SELECT actif FROM {$this->table} WHERE id = :id",
            ['id' => $id]
        );
    }
}
