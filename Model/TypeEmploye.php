<?php
/**
 * Modèle TypeEmploye (table : types_employes)
 * Catégorie professionnelle rattachée à chaque auditeur.
 */

require_once __DIR__ . '/Model.php';

class TypeEmploye extends Model
{
    protected string $table = 'types_employes';

    protected array $fillable = [
        'libelle',
    ];

    /** Trouve un type d'employé par son libellé unique. */
    public function findByLibelle(string $libelle): ?array
    {
        return $this->fetchOne(
            "SELECT * FROM {$this->table} WHERE libelle = :libelle LIMIT 1",
            ['libelle' => $libelle]
        );
    }

    /** Types avec le nombre d'auditeurs rattachés. */
    public function getAllWithCount(): array
    {
        return $this->fetchAll(
            "SELECT t.*, (SELECT COUNT(*) FROM auditeurs a WHERE a.id_type_employe = t.id) AS nb_auditeurs
             FROM {$this->table} t
             ORDER BY t.libelle"
        );
    }
}
