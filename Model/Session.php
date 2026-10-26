<?php
/**
 * Modèle Session (table : sessions)
 */

require_once __DIR__ . '/Model.php';

class Session extends Model
{
    protected string $table = 'sessions';

    public const STATUTS = ['planifiee', 'ouverte', 'en_cours', 'terminee', 'annulee'];

    protected array $fillable = [
        'id_annee', 'id_type_formation', 'id_niveau',
        'code', 'libelle', 'date_debut', 'date_fin', 'statut',
    ];

    /** Sessions d'une année scolaire. */
    public function getByAnnee(int $id_annee): array
    {
        return $this->fetchAll(
            "SELECT * FROM {$this->table} WHERE id_annee = :id_annee ORDER BY date_debut",
            ['id_annee' => $id_annee]
        );
    }

    /** Sessions d'un type de formation. */
    public function getByTypeFormation(int $id_type): array
    {
        return $this->fetchAll(
            "SELECT * FROM {$this->table} WHERE id_type_formation = :id_type ORDER BY date_debut",
            ['id_type' => $id_type]
        );
    }

    /** Sessions d'un niveau. */
    public function getByNiveau(int $id_niveau): array
    {
        return $this->fetchAll(
            "SELECT * FROM {$this->table} WHERE id_niveau = :id_niveau ORDER BY date_debut",
            ['id_niveau' => $id_niveau]
        );
    }

    /**
     * Les dates d'une session doivent rester dans son année scolaire et contenir ses évaluations.
     * @return string|null message de refus
     */
    public function datesRefusal(int $idAnnee, string $debut, string $fin, ?int $sessionId = null): ?string
    {
        $year = $this->fetchOne('SELECT libelle, date_debut, date_fin FROM annees_scolaires WHERE id = :id', ['id' => $idAnnee]);
        if ($year === null) {
            return 'L’année scolaire sélectionnée est introuvable.';
        }
        if ($debut < $year['date_debut'] || $fin > $year['date_fin']) {
            return sprintf(
                'Les dates de la session doivent être comprises dans l’année scolaire « %s » (du %s au %s). Prolongez l’année si nécessaire.',
                $year['libelle'], $year['date_debut'], $year['date_fin']
            );
        }
        if ($sessionId !== null) {
            $outside = (int) $this->fetchColumn(
                'SELECT COUNT(*) FROM evaluations WHERE id_session = :id AND date_evaluation NOT BETWEEN :debut AND :fin',
                ['id' => $sessionId, 'debut' => $debut, 'fin' => $fin]
            );
            if ($outside > 0) {
                return "{$outside} évaluation(s) de cette session tomberaient hors de ses nouvelles dates : déplacez-les d’abord.";
            }
        }
        return null;
    }

    public function getGroupCount(int $id): int
    {
        return (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM groupes WHERE id_session = :id',
            ['id' => $id]
        );
    }

    /**
     * Change le statut d'une session.
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

    /** Session complète avec année scolaire, type de formation et niveau. */
    public function getWithRelations(int $id): ?array
    {
        return $this->fetchOne(
            "SELECT s.*,
                    a.libelle AS annee_libelle,
                    tf.libelle AS type_formation_libelle,
                    n.libelle AS niveau_libelle
             FROM {$this->table} s
             JOIN annees_scolaires a  ON a.id  = s.id_annee
             JOIN types_formations tf ON tf.id = s.id_type_formation
             JOIN niveaux n           ON n.id  = s.id_niveau
             WHERE s.id = :id
             LIMIT 1",
            ['id' => $id]
        );
    }

    /** Toutes les sessions avec leurs relations (année, type, niveau). */
    public function getAllWithRelations(): array
    {
        return $this->fetchAll(
            "SELECT s.*,
                    a.libelle AS annee_libelle,
                    tf.libelle AS type_formation_libelle,
                    n.libelle AS niveau_libelle
             FROM {$this->table} s
             JOIN annees_scolaires a  ON a.id  = s.id_annee
             JOIN types_formations tf ON tf.id = s.id_type_formation
             JOIN niveaux n           ON n.id  = s.id_niveau
             ORDER BY s.date_debut DESC"
        );
    }
}
