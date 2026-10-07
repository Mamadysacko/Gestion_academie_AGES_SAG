<?php
/**
 * Modèle Departement (table : departements)
 */

require_once __DIR__ . '/Model.php';

class Departement extends Model
{
    protected string $table = 'departements';

    protected array $fillable = [
        'code', 'libelle',
    ];

    /** Trouve un département par son code unique. */
    public function findByCode(string $code): ?array
    {
        return $this->fetchOne(
            "SELECT * FROM {$this->table} WHERE code = :code LIMIT 1",
            ['code' => $code]
        );
    }
}
