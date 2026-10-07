<?php
/**
 * Modèle Auditeur (table : auditeurs)
 */

require_once __DIR__ . '/Model.php';

class Auditeur extends Model
{
    protected string $table = 'auditeurs';

    public const SEXES = ['M', 'F'];

    protected array $fillable = [
        'matricule', 'nom', 'prenom', 'sexe', 'telephone', 'email',
    ];

    /** Trouve un auditeur par son matricule unique. */
    public function findByMatricule(string $matricule): ?array
    {
        return $this->fetchOne(
            "SELECT * FROM {$this->table} WHERE matricule = :matricule LIMIT 1",
            ['matricule' => $matricule]
        );
    }

    /**
     * Recherche des auditeurs par nom, prénom ou matricule (LIKE %term%).
     */
    public function search(string $term): array
    {
        return $this->fetchAll(
            "SELECT * FROM {$this->table}
             WHERE nom LIKE :term
                OR prenom LIKE :term
                OR matricule LIKE :term
             ORDER BY nom, prenom",
            ['term' => '%' . $term . '%']
        );
    }
}
