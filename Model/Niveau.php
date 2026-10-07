<?php
/**
 * Modèle Niveau (table : niveaux)
 */

require_once __DIR__ . '/Model.php';

class Niveau extends Model
{
    protected string $table = 'niveaux';

    protected array $fillable = [
        'libelle',
    ];

    /** Trouve un niveau par son libellé unique. */
    public function findByLibelle(string $libelle): ?array
    {
        return $this->fetchOne(
            "SELECT * FROM {$this->table} WHERE libelle = :libelle LIMIT 1",
            ['libelle' => $libelle]
        );
    }
}
