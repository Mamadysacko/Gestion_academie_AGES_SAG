<?php
/**
 * Modèle Evaluation (table : evaluations)
 */

require_once __DIR__ . '/Model.php';

class Evaluation extends Model
{
    protected string $table = 'evaluations';

    protected array $fillable = [
        'id_session', 'numero', 'libelle', 'date_evaluation',
    ];

    /** Évaluations d'une session. */
    public function getBySession(int $id_session): array
    {
        return $this->fetchAll(
            "SELECT * FROM {$this->table} WHERE id_session = :id_session ORDER BY numero",
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
             ORDER BY s.date_debut DESC, e.id_session, e.numero"
        );
    }

    /** Niveau d'une session (pour appliquer la règle d'évaluation du niveau). */
    public function niveauOfSession(int $id_session): ?int
    {
        $value = $this->fetchColumn('SELECT id_niveau FROM sessions WHERE id = :id', ['id' => $id_session]);
        return $value === false ? null : (int) $value;
    }

    /** Numéros déjà utilisés dans une session. */
    public function usedNumbers(int $id_session, ?int $excludeId = null): array
    {
        $rows = $this->fetchAll(
            "SELECT numero FROM {$this->table} WHERE id_session = :id_session AND id <> :exclude",
            ['id_session' => $id_session, 'exclude' => (int) $excludeId]
        );
        return array_map(static fn($r) => (int) $r['numero'], $rows);
    }

    /**
     * Crée les évaluations manquantes (1 à N) d'une session, réparties dans sa période.
     * @return int Nombre d'évaluations créées.
     */
    public function generateForSession(int $id_session, int $count): int
    {
        $session = $this->fetchOne('SELECT date_debut, date_fin FROM sessions WHERE id = :id', ['id' => $id_session]);
        if ($session === null) {
            throw new InvalidArgumentException('Session introuvable.');
        }
        $used = $this->usedNumbers($id_session);
        $start = new DateTimeImmutable($session['date_debut']);
        $days = max(1, (int) $start->diff(new DateTimeImmutable($session['date_fin']))->days);

        $created = 0;
        for ($numero = 1; $numero <= $count; $numero++) {
            if (in_array($numero, $used, true)) {
                continue;
            }
            // Évaluation n placée à n/N de la période de la session.
            $date = $start->modify('+' . (int) floor($days * $numero / $count) . ' days')->format('Y-m-d');
            $this->create([
                'id_session' => $id_session,
                'numero' => $numero,
                'libelle' => "Évaluation {$numero}",
                'date_evaluation' => $date,
            ]);
            $created++;
        }
        return $created;
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
