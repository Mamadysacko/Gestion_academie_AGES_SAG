<?php
/**
 * Modèle Evaluation (table : evaluations)
 */

require_once __DIR__ . '/Model.php';

class Evaluation extends Model
{
    protected string $table = 'evaluations';

    protected array $fillable = [
        'id_session', 'libelle', 'date_evaluation',
    ];

    /** Évaluations d'une session. */
    public function getBySession(int $id_session): array
    {
        return $this->fetchAll(
            "SELECT * FROM {$this->table} WHERE id_session = :id_session ORDER BY date_evaluation",
            ['id_session' => $id_session]
        );
    }

    /** Évaluation avec le détail de sa session. */
    public function getAllWithSession(): array
    {
        return $this->fetchAll(
            "SELECT e.*, s.code AS session_code, s.libelle AS session_libelle,
                    s.id_annee, s.id_niveau, s.statut AS session_statut
             FROM {$this->table} e
             JOIN sessions s ON s.id = e.id_session
             ORDER BY e.date_evaluation DESC, e.libelle"
        );
    }

    public function getWithSession(int $id): ?array
    {
        return $this->fetchOne(
            "SELECT e.*, s.code AS session_code, s.libelle AS session_libelle,
                    s.id_annee, s.id_niveau, s.statut AS session_statut
             FROM {$this->table} e
             JOIN sessions s ON s.id = e.id_session
             WHERE e.id = :id
             LIMIT 1",
            ['id' => $id]
        );
    }
}
