<?php
/**
 * Modèle AnneeScolaire (table : annees_scolaires)
 */

require_once __DIR__ . '/Model.php';

class AnneeScolaire extends Model
{
    protected string $table = 'annees_scolaires';

    protected array $fillable = [
        'libelle', 'date_debut', 'date_fin', 'statut',
    ];

    /** Retourne l'année scolaire active (statut = 1). */
    public function getActive(): ?array
    {
        return $this->fetchOne(
            "SELECT * FROM {$this->table} WHERE statut = 1 ORDER BY date_debut DESC LIMIT 1"
        );
    }

    /**
     * Vérifie si un libellé existe déjà (contrainte uk_annees_libelle).
     * @param int|null $excludeId ID à exclure (utile en mise à jour).
     */
    public function existsByLibelle(string $libelle, ?int $excludeId = null): bool
    {
        $sql = "SELECT 1 AS ok FROM {$this->table} WHERE libelle = :libelle";
        $params = ['libelle' => $libelle];

        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params['exclude_id'] = $excludeId;
        }
        $sql .= " LIMIT 1";

        return $this->fetchOne($sql, $params) !== null;
    }

    /** Désactive toutes les années puis active celle donnée (une seule année active). */
    public function setActive(int $id): bool
    {
        $this->db->beginTransaction();
        try {
            $this->db->exec("UPDATE {$this->table} SET statut = 0");
            $stmt = $this->db->prepare("UPDATE {$this->table} SET statut = 1 WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $this->db->commit();
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function hasSessions(int $id): bool
    {
        return (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM sessions WHERE id_annee = :id',
            ['id' => $id]
        ) > 0;
    }
}
