<?php
/**
 * Classe de base abstraite pour tous les modèles.
 * Fournit le CRUD générique sécurisé (requêtes préparées PDO)
 * ainsi que des helpers de requêtage.
 */

require_once __DIR__ . '/../config/Database.php';

abstract class Model
{
    protected PDO $db;

    /** Nom de la table (à définir dans chaque modèle enfant). */
    protected string $table;

    /** Clé primaire (défaut : id ; ex. notes => id_note). */
    protected string $primaryKey = 'id';

    /** Colonnes autorisées en insertion / mise à jour (protection mass-assignment). */
    protected array $fillable = [];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /* ------------------------------------------------------------------
     | CRUD générique
     |------------------------------------------------------------------ */

    /**
     * Retourne toutes les lignes de la table.
     * @param string|null $orderBy Ex. "nom ASC" (jamais issu d'une saisie utilisateur).
     */
    public function getAll(?string $orderBy = null): array
    {
        $sql = "SELECT * FROM {$this->table}";
        if ($orderBy !== null) {
            $sql .= " ORDER BY {$orderBy}";
        }
        return $this->db->query($sql)->fetchAll();
    }

    /** Trouve une ligne par sa clé primaire. Retourne null si absente. */
    public function find(int $id): ?array
    {
        return $this->fetchOne(
            "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = :id LIMIT 1",
            ['id' => $id]
        );
    }

    /**
     * Insère une ligne (colonnes filtrées via $fillable).
     * @return int ID auto-incrémenté de la ligne créée.
     */
    public function create(array $data): int
    {
        $data = $this->filterData($data);
        if (empty($data)) {
            throw new InvalidArgumentException("Aucune donnée valide à insérer dans {$this->table}.");
        }

        $cols = array_keys($data);
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $this->table,
            implode(', ', $cols),
            implode(', ', array_map(static fn(string $c): string => ':' . $c, $cols))
        );

        $this->db->prepare($sql)->execute($data);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Met à jour une ligne par sa clé primaire (colonnes filtrées via $fillable).
     * @return bool true si la requête a été exécutée avec des données valides.
     */
    public function update(int $id, array $data): bool
    {
        $data = $this->filterData($data);
        if (empty($data)) {
            return false;
        }

        $set = implode(', ', array_map(static fn(string $c): string => "{$c} = :{$c}", array_keys($data)));
        $sql = "UPDATE {$this->table} SET {$set} WHERE {$this->primaryKey} = :__pk_id";
        $data['__pk_id'] = $id;

        return $this->db->prepare($sql)->execute($data);
    }

    /** Supprime une ligne par sa clé primaire. Retourne true si une ligne a été supprimée. */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE {$this->primaryKey} = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /* ------------------------------------------------------------------
     | Helpers
     |------------------------------------------------------------------ */

    /** Nombre total de lignes de la table. */
    public function count(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM {$this->table}")->fetchColumn();
    }

    /** Vérifie l'existence d'une ligne par sa clé primaire. */
    public function exists(int $id): bool
    {
        return $this->fetchOne(
            "SELECT 1 AS ok FROM {$this->table} WHERE {$this->primaryKey} = :id LIMIT 1",
            ['id' => $id]
        ) !== null;
    }

    /** Ne conserve que les colonnes déclarées dans $fillable. */
    protected function filterData(array $data): array
    {
        return array_intersect_key($data, array_flip($this->fillable));
    }

    /** Exécute une requête préparée et retourne la première ligne (ou null). */
    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Exécute une requête préparée et retourne toutes les lignes. */
    protected function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Retourne la valeur d'une seule colonne (première ligne). */
    protected function fetchColumn(string $sql, array $params = [])
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /**
     * Valide qu'une valeur appartient à une liste autorisée (enums SQL).
     * @throws InvalidArgumentException
     */
    protected function assertIn(string $value, array $allowed, string $field): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(
                "Valeur invalide pour {$field} : '{$value}'. Valeurs attendues : " . implode(', ', $allowed)
            );
        }
    }
}
