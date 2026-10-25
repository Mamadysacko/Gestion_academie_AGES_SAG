<?php
/**
 * Modèle Position (table : positions)
 * Poste / fonction rattaché à chaque auditeur (id, libelle).
 */

require_once __DIR__ . '/Model.php';

class Position extends Model
{
    protected string $table = 'positions';

    protected array $fillable = [
        'libelle',
    ];

    /** Trouve une position par son libellé unique. */
    public function findByLibelle(string $libelle): ?array
    {
        return $this->fetchOne(
            "SELECT * FROM {$this->table} WHERE libelle = :libelle LIMIT 1",
            ['libelle' => $libelle]
        );
    }

    /** Positions avec le nombre d'auditeurs rattachés. */
    public function getAllWithCount(): array
    {
        return $this->fetchAll(
            "SELECT t.*, (SELECT COUNT(*) FROM auditeurs a WHERE a.id_position = t.id) AS nb_auditeurs
             FROM {$this->table} t
             ORDER BY t.libelle"
        );
    }
}
