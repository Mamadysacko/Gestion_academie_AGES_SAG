<?php
/**
 * Modèle Salle (table : salles)
 */

require_once __DIR__ . '/Model.php';

class Salle extends Model
{
    protected string $table = 'salles';

    public const STATUTS = ['disponible', 'indisponible'];

    protected array $fillable = [
        'code', 'libelle', 'capacite', 'statut',
    ];

    /** Trouve une salle par son code unique. */
    public function findByCode(string $code): ?array
    {
        return $this->fetchOne(
            "SELECT * FROM {$this->table} WHERE code = :code LIMIT 1",
            ['code' => $code]
        );
    }

    /** Retourne les salles disponibles. */
    public function getDisponibles(): array
    {
        return $this->fetchAll(
            "SELECT * FROM {$this->table} WHERE statut = 'disponible' ORDER BY code"
        );
    }

    /**
     * Supprime une salle et ses rattachements aux formations (salles dédiées / partagées).
     * Si des créneaux l'utilisent encore, la base refuse et rien n'est supprimé.
     */
    public function delete(int $id): bool
    {
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare('DELETE FROM formation_salles WHERE id_salle = :id')->execute(['id' => $id]);
            $deleted = parent::delete($id);
            if ($ownTx) {
                $this->db->commit();
            }
            return $deleted;
        } catch (Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function isUsedInPlanning(int $id): bool
    {
        return (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM emplois_du_temps WHERE id_salle = :id',
            ['id' => $id]
        ) > 0;
    }

    public function getPeakActiveEnrollment(int $id): int
    {
        return (int) $this->fetchColumn(
            "SELECT COALESCE(MAX(enrollment_count), 0)
             FROM (
                 SELECT COUNT(i.id) AS enrollment_count
                 FROM emplois_du_temps e
                 LEFT JOIN inscriptions i
                   ON i.id_emploi_du_temps = e.id AND i.statut = 'active'
                 WHERE e.id_salle = :id AND e.statut = 1
                 GROUP BY e.id
             ) room_enrollments",
            ['id' => $id]
        );
    }

    public function hasActiveSchedules(int $id): bool
    {
        return (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM emplois_du_temps WHERE id_salle = :id AND statut = 1',
            ['id' => $id]
        ) > 0;
    }

    /**
     * Change le statut d'une salle ('disponible' | 'indisponible').
     * @throws InvalidArgumentException si le statut est invalide.
     */
    public function changeStatut(int $id, string $statut): bool
    {
        $this->assertIn($statut, self::STATUTS, 'statut');

        $stmt = $this->db->prepare(
            "UPDATE {$this->table} SET statut = :statut WHERE id = :id"
        );
        return $stmt->execute(['statut' => $statut, 'id' => $id]);
    }
}
