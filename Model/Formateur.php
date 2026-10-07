<?php
/**
 * Modèle Formateur (table : formateurs)
 */

require_once __DIR__ . '/Model.php';

class Formateur extends Model
{
    protected string $table = 'formateurs';

    protected array $fillable = [
        'user_id', 'matricule', 'nom', 'prenom', 'password', 'telephone', 'email', 'actif',
    ];

    /** Crée un formateur en enregistrant le mot de passe tel que fourni. */
    public function create(array $data): int
    {
        return parent::create($data);
    }

    /**
     * Met à jour un formateur. Un mot de passe vide est ignoré afin de conserver
     * le mot de passe actuel.
     */
    public function update(int $id, array $data): bool
    {
        if (array_key_exists('password', $data)) {
            if ($data['password'] === '' || $data['password'] === null) {
                unset($data['password']);
            }
        }
        return parent::update($id, $data);
    }

    /** Trouve un formateur par son matricule unique. */
    public function findByMatricule(string $matricule): ?array
    {
        return $this->fetchOne(
            "SELECT * FROM {$this->table} WHERE matricule = :matricule LIMIT 1",
            ['matricule' => $matricule]
        );
    }

    /** Trouve un formateur par son email unique. */
    public function findByEmail(string $email): ?array
    {
        return $this->fetchOne(
            "SELECT * FROM {$this->table} WHERE email = :email LIMIT 1",
            ['email' => $email]
        );
    }

    /** Retourne les formateurs actifs (actif = 1). */
    public function getActifs(): array
    {
        return $this->fetchAll(
            "SELECT id, user_id, matricule, nom, prenom, telephone, email, actif, created_at, updated_at
             FROM {$this->table} WHERE actif = 1 ORDER BY nom, prenom"
        );
    }

    public function findById(int $id): ?array
    {
        return $this->fetchOne(
            "SELECT id, user_id, matricule, nom, prenom, telephone, email, actif, created_at, updated_at
             FROM {$this->table} WHERE id = :id LIMIT 1",
            ['id' => $id]
        );
    }

    public function getAllWithoutPasswords(): array
    {
        return $this->fetchAll(
            "SELECT id, user_id, matricule, nom, prenom, telephone, email, actif, created_at, updated_at
             FROM {$this->table} ORDER BY nom, prenom"
        );
    }

    public function isUsedInPlanning(int $id): bool
    {
        return (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM emplois_du_temps WHERE id_formateur = :id',
            ['id' => $id]
        ) > 0;
    }

    public function getDatabase(): PDO
    {
        return $this->db;
    }

    public function getUserId(int $id): ?int
    {
        $userId = $this->fetchColumn(
            "SELECT user_id FROM {$this->table} WHERE id = :id LIMIT 1",
            ['id' => $id]
        );
        return $userId === false || $userId === null ? null : (int) $userId;
    }

    /** Active / désactive un formateur. Retourne le nouvel état (1/0) ou null si introuvable. */
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

    /** Retourne le formateur lié à un compte utilisateur (user_id). */
    public function getByUser(int $user_id): ?array
    {
        return $this->fetchOne(
            "SELECT * FROM {$this->table} WHERE user_id = :user_id LIMIT 1",
            ['user_id' => $user_id]
        );
    }
}
