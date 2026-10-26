<?php
/**
 * Modèle Formateur (table : formateurs, lecture : vue v_formateurs)
 *
 * Un formateur est un compte utilisateur (`users` : nom, prénom, contacts, mot de passe, actif)
 * complété de ce qui lui est propre (`formateurs` : matricule, salle attitrée). La vue
 * `v_formateurs` réunit les deux avec les colonnes historiques (nom, prenom, telephone, email, actif).
 */

require_once __DIR__ . '/Model.php';

class Formateur extends Model
{
    protected string $table = 'formateurs';

    /** Vue de lecture : fiche formateur + identité du compte. */
    public const VIEW = 'v_formateurs';

    /** Seules les colonnes propres au formateur ; l'identité s'écrit dans `users`. */
    protected array $fillable = ['user_id', 'matricule', 'id_salle'];

    /** Colonnes d'identité, enregistrées dans le compte utilisateur lié. */
    public const IDENTITY_FIELDS = ['nom', 'prenom', 'telephone', 'email', 'actif', 'password'];

    private const TRAINER_COLUMNS = 'f.id, f.user_id, f.matricule, f.nom, f.prenom, f.telephone, f.email,
        f.id_salle, f.actif, f.created_at, f.updated_at,
        sa.code AS salle_code, sa.libelle AS salle_libelle, sa.capacite AS salle_capacite';

    /** Fiche complète (identité comprise) par identifiant. */
    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM ' . self::VIEW . ' WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    /**
     * Met à jour la fiche (matricule, salle) et l'identité du compte lié (nom, contacts, actif,
     * mot de passe). Retourne true si au moins une valeur a été enregistrée.
     */
    public function update(int $id, array $data): bool
    {
        $updated = parent::update($id, $data);
        $identity = array_intersect_key($data, array_flip(self::IDENTITY_FIELDS));
        $userId = $this->getUserId($id);
        if ($identity !== [] && $userId !== null) {
            require_once __DIR__ . '/User.php';
            $updated = (new User())->update($userId, $identity) || $updated;
        }
        return $updated;
    }

    /** Trouve un formateur par son matricule unique. */
    public function findByMatricule(string $matricule): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM ' . self::VIEW . ' WHERE matricule = :matricule LIMIT 1',
            ['matricule' => $matricule]
        );
    }

    /** Trouve un formateur par l'e-mail (unique) de son compte. */
    public function findByEmail(string $email): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM ' . self::VIEW . ' WHERE email = :email LIMIT 1',
            ['email' => $email]
        );
    }

    /** Retourne les formateurs actifs (actif = 1). */
    public function getActifs(): array
    {
        return $this->fetchAll(
            "SELECT " . self::TRAINER_COLUMNS . "
             FROM " . self::VIEW . " f
             LEFT JOIN salles sa ON sa.id = f.id_salle
             WHERE f.actif = 1
             ORDER BY f.nom, f.prenom"
        );
    }

    public function findById(int $id): ?array
    {
        return $this->fetchOne(
            "SELECT " . self::TRAINER_COLUMNS . "
             FROM " . self::VIEW . " f
             LEFT JOIN salles sa ON sa.id = f.id_salle
             WHERE f.id = :id
             LIMIT 1",
            ['id' => $id]
        );
    }

    public function getAllWithoutPasswords(): array
    {
        return $this->fetchAll(
            "SELECT " . self::TRAINER_COLUMNS . "
             FROM " . self::VIEW . " f
             LEFT JOIN salles sa ON sa.id = f.id_salle
             ORDER BY f.nom, f.prenom"
        );
    }

    public function findBySalle(int $id_salle): ?array
    {
        return $this->fetchOne(
            "SELECT " . self::TRAINER_COLUMNS . "
             FROM " . self::VIEW . " f
             LEFT JOIN salles sa ON sa.id = f.id_salle
             WHERE f.id_salle = :id_salle
             LIMIT 1",
            ['id_salle' => $id_salle]
        );
    }

    /**
     * Vérifie qu'un formateur peut être rattaché à une salle unique.
     * @return string|null Message d'erreur, ou null si le rattachement est valide.
     */
    public function validateSalleAssignment(int $id_formateur, ?int $id_salle, bool $persistIfFree = false): ?string
    {
        if ($id_salle === null) {
            return null;
        }

        $trainer = $this->find($id_formateur);
        if ($trainer === null) {
            return 'Le formateur sélectionné est introuvable.';
        }

        $room = $this->fetchOne(
            'SELECT id, statut FROM salles WHERE id = :id LIMIT 1',
            ['id' => $id_salle]
        );
        if ($room === null) {
            return 'La salle sélectionnée est introuvable.';
        }

        $occupant = $this->findBySalle($id_salle);
        if ($occupant !== null && (int) $occupant['id'] !== $id_formateur) {
            return 'Cette salle a déjà un formateur attitré. Un formateur appartient à une seule salle.';
        }

        $currentSalle = $trainer['id_salle'] !== null ? (int) $trainer['id_salle'] : null;
        if ($currentSalle !== null && $currentSalle !== $id_salle) {
            return 'Ce formateur est déjà rattaché à une autre salle. Il y reste tous les jours de la semaine.';
        }

        $foreignRoom = $this->fetchOne(
            "SELECT sa.code
             FROM emplois_du_temps e
             JOIN salles sa ON sa.id = e.id_salle
             WHERE e.id_formateur = :id_formateur AND e.id_salle <> :id_salle
             LIMIT 1",
            ['id_formateur' => $id_formateur, 'id_salle' => $id_salle]
        );
        if ($foreignRoom !== null) {
            return 'Ce formateur a déjà des créneaux dans une autre salle. Réaffectez-les avant de changer de salle.';
        }

        $foreignTrainer = $this->fetchOne(
            "SELECT f.nom, f.prenom
             FROM emplois_du_temps e
             JOIN v_formateurs f ON f.id = e.id_formateur
             WHERE e.id_salle = :id_salle AND e.id_formateur <> :id_formateur
             LIMIT 1",
            ['id_salle' => $id_salle, 'id_formateur' => $id_formateur]
        );
        if ($foreignTrainer !== null) {
            return 'Cette salle est déjà occupée par un autre formateur sur l’emploi du temps.';
        }

        if ($persistIfFree && $currentSalle === null) {
            $this->update($id_formateur, ['id_salle' => $id_salle]);
        }

        return null;
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

    /**
     * Active / désactive un formateur : c'est son compte qui est activé ou désactivé.
     * Retourne le nouvel état (1/0) ou null si introuvable.
     */
    public function toggleActif(int $id): ?int
    {
        $stmt = $this->db->prepare(
            "UPDATE users u JOIN {$this->table} f ON f.user_id = u.id SET u.actif = 1 - u.actif WHERE f.id = :id"
        );
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() === 0) {
            return null;
        }
        return (int) $this->fetchColumn(
            'SELECT actif FROM ' . self::VIEW . ' WHERE id = :id',
            ['id' => $id]
        );
    }

    /** Retourne le formateur lié à un compte utilisateur (user_id). */
    public function getByUser(int $user_id): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM ' . self::VIEW . ' WHERE user_id = :user_id LIMIT 1',
            ['user_id' => $user_id]
        );
    }
}
