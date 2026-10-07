<?php
/**
 * Modèle ParametreEvaluation (table : parametres)
 *
 * Les paramètres sont réutilisables et leur association aux évaluations
 * est stockée dans evaluation_parametres.
 */

require_once __DIR__ . '/Model.php';

class ParametreEvaluation extends Model
{
    protected string $table = 'parametres';

    protected array $fillable = [
        'libelle', 'bareme',
    ];

    public function getDatabase(): PDO
    {
        return $this->db;
    }

    /** Paramètres globaux et nombre d'évaluations auxquelles ils sont associés. */
    public function getAllWithRelations(): array
    {
        return $this->fetchAll(
            "SELECT p.*, COUNT(ep.id_evaluation) AS nombre_evaluations
             FROM {$this->table} p
             LEFT JOIN evaluation_parametres ep ON ep.id_parametre = p.id
             GROUP BY p.id
             ORDER BY p.libelle"
        );
    }

    /** Paramètres associés à une évaluation, dans l'ordre de saisie. */
    public function getByEvaluation(int $id_evaluation): array
    {
        return $this->fetchAll(
            "SELECT p.*, ep.id_evaluation, ep.ordre
             FROM evaluation_parametres ep
             JOIN {$this->table} p ON p.id = ep.id_parametre
             WHERE ep.id_evaluation = :id_evaluation
             ORDER BY ep.ordre, p.libelle",
            ['id_evaluation' => $id_evaluation]
        );
    }

    /** Associe un paramètre global à une évaluation ou met à jour son ordre. */
    public function attachToEvaluation(int $id_evaluation, int $id_parametre, int $ordre = 1): bool
    {
        if ($id_evaluation <= 0 || $id_parametre <= 0 || $ordre <= 0) {
            throw new InvalidArgumentException('Une évaluation, un paramètre et un ordre positifs sont requis.');
        }
        $stmt = $this->db->prepare(
            "INSERT INTO evaluation_parametres (id_evaluation, id_parametre, ordre)
             VALUES (:id_evaluation, :id_parametre, :ordre)
             ON DUPLICATE KEY UPDATE ordre = VALUES(ordre)"
        );

        return $stmt->execute([
            'id_evaluation' => $id_evaluation,
            'id_parametre' => $id_parametre,
            'ordre' => $ordre,
        ]);
    }

    /** Retire un paramètre d'une évaluation sans supprimer le paramètre global. */
    public function detachFromEvaluation(int $id_evaluation, int $id_parametre): bool
    {
        $hasGrades = $this->fetchOne(
            'SELECT 1 FROM notes WHERE id_evaluation = :evaluation AND id_parametre = :parameter LIMIT 1',
            ['evaluation' => $id_evaluation, 'parameter' => $id_parametre]
        );
        if ($hasGrades !== null) {
            throw new DomainException('Ce paramètre porte déjà des notes pour cette évaluation et ne peut pas être détaché.');
        }

        $stmt = $this->db->prepare(
            "DELETE FROM evaluation_parametres
             WHERE id_evaluation = :id_evaluation AND id_parametre = :id_parametre"
        );
        $stmt->execute([
            'id_evaluation' => $id_evaluation,
            'id_parametre' => $id_parametre,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function update(int $id, array $data): bool
    {
        if (isset($data['bareme'])) {
            $maxGrade = $this->fetchColumn(
                'SELECT MAX(note) FROM notes WHERE id_parametre = :id AND note IS NOT NULL',
                ['id' => $id]
            );
            if ($maxGrade !== false && $maxGrade !== null && (float) $data['bareme'] < (float) $maxGrade) {
                throw new DomainException('Le barème ne peut pas être inférieur à une note déjà enregistrée.');
            }
        }

        if (isset($data['libelle'])) {
            $data['libelle'] = trim((string) $data['libelle']);
            if ($data['libelle'] === '' || mb_strlen($data['libelle']) > 100) {
                throw new InvalidArgumentException('Le libellé doit contenir entre 1 et 100 caractères.');
            }
        }

        return parent::update($id, $data);
    }

    public function delete(int $id): bool
    {
        if ($this->fetchOne('SELECT 1 FROM notes WHERE id_parametre = :id LIMIT 1', ['id' => $id]) !== null) {
            throw new DomainException('Ce paramètre possède des notes. Supprimez ou archivez les notes avant de le supprimer.');
        }
        return parent::delete($id);
    }
}
