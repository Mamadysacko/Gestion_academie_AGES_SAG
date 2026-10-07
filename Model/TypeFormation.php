<?php
/**
 * Modèle TypeFormation (table : types_formations)
 */

require_once __DIR__ . '/Model.php';

class TypeFormation extends Model
{
    protected string $table = 'types_formations';

    protected array $fillable = [
        'libelle',
    ];

    /** Trouve un type de formation par son libellé unique. */
    public function findByLibelle(string $libelle): ?array
    {
        return $this->fetchOne(
            "SELECT * FROM {$this->table} WHERE libelle = :libelle LIMIT 1",
            ['libelle' => $libelle]
        );
    }
}
