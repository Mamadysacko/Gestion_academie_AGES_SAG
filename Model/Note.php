<?php
/**
 * Modèle Note (table : notes, clé primaire : id_note)
 *
 * Une note = la note d'une inscription pour un paramètre d'une évaluation.
 * Contrainte unique : (id_evaluation, id_parametre, id_inscription).
 */

require_once __DIR__ . '/Model.php';

class Note extends Model
{
    protected string $table = 'notes';
    protected string $primaryKey = 'id_note';

    protected array $fillable = [
        'id_evaluation', 'id_parametre', 'id_inscription', 'note', 'observation',
    ];

    public function create(array $data): int
    {
        $this->validateGradeData($data);
        return parent::create($data);
    }

    public function update(int $id, array $data): bool
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return false;
        }
        $this->validateGradeData(array_merge($existing, $data));
        return parent::update($id, $data);
    }

    /** Notes d'une inscription (avec évaluation, libellé et barème). */
    public function getByInscription(int $id_inscription): array
    {
        return $this->fetchAll(
            "SELECT n.*, p.libelle AS parametre_libelle, p.bareme, e.libelle AS evaluation_libelle
             FROM {$this->table} n
             JOIN parametres p ON p.id = n.id_parametre
             JOIN evaluations e ON e.id = n.id_evaluation
             WHERE n.id_inscription = :id_inscription
             ORDER BY e.date_evaluation, p.libelle",
            ['id_inscription' => $id_inscription]
        );
    }

    /** Notes d'un paramètre dans une évaluation (avec l'auditeur). */
    public function getByParametre(int $id_evaluation, int $id_parametre): array
    {
        return $this->fetchAll(
            "SELECT n.*,
                    p.libelle AS parametre_libelle, p.bareme,
                    a.matricule AS auditeur_matricule, a.nom AS auditeur_nom, a.prenom AS auditeur_prenom
             FROM {$this->table} n
             JOIN parametres p ON p.id = n.id_parametre
             JOIN inscriptions i ON i.id = n.id_inscription
             JOIN auditeurs a    ON a.id = i.id_auditeur
             WHERE n.id_evaluation = :id_evaluation AND n.id_parametre = :id_parametre
             ORDER BY a.nom, a.prenom",
            ['id_evaluation' => $id_evaluation, 'id_parametre' => $id_parametre]
        );
    }

    /** Notes de tous les inscrits à une évaluation. */
    public function getByEvaluation(int $id_evaluation): array
    {
        return $this->fetchAll(
            "SELECT n.*, p.libelle AS parametre_libelle, p.bareme,
                    e.libelle AS evaluation_libelle, e.date_evaluation,
                    e.id_session,
                    a.matricule AS auditeur_matricule, a.nom AS auditeur_nom, a.prenom AS auditeur_prenom
             FROM {$this->table} n
             JOIN parametres p ON p.id = n.id_parametre
             JOIN evaluations e ON e.id = n.id_evaluation
             JOIN inscriptions i ON i.id = n.id_inscription
             JOIN auditeurs a ON a.id = i.id_auditeur
             WHERE n.id_evaluation = :id_evaluation
             ORDER BY a.nom, a.prenom, p.libelle",
            ['id_evaluation' => $id_evaluation]
        );
    }

    /**
     * Insère ou met à jour une note (clé unique id_parametre + id_inscription).
     * La note est bornée entre 0 et le barème du paramètre.
     * @return bool true en cas de succès.
     */
    public function saveOrUpdate(
        int $id_evaluation,
        int $id_parametre,
        int $id_inscription,
        ?float $note,
        ?string $observation = null
    ): bool
    {
        $bareme = $this->fetchColumn(
            "SELECT p.bareme
             FROM parametres p
             JOIN evaluation_parametres ep ON ep.id_parametre = p.id
             JOIN evaluations e ON e.id = ep.id_evaluation
             JOIN inscriptions i ON i.id = :id_inscription
             JOIN emplois_du_temps edt ON edt.id = i.id_emploi_du_temps
             JOIN groupes g ON g.id = edt.id_groupe
             JOIN sessions s ON s.id = g.id_session AND s.id = e.id_session
             WHERE p.id = :id_parametre AND ep.id_evaluation = :id_evaluation",
            ['id_inscription' => $id_inscription, 'id_parametre' => $id_parametre, 'id_evaluation' => $id_evaluation]
        );

        if ($bareme === false) {
            throw new RuntimeException("Ce paramètre n'est pas associé à l'évaluation indiquée.");
        }
        if ($note !== null && ($note < 0 || $note > (float) $bareme)) {
            throw new InvalidArgumentException(
                "La note ({$note}) doit être comprise entre 0 et le barème ({$bareme})."
            );
        }

        $stmt = $this->db->prepare(
            "INSERT INTO {$this->table} (id_evaluation, id_parametre, id_inscription, note, observation)
             VALUES (:id_evaluation, :id_parametre, :id_inscription, :note, :observation)
             ON DUPLICATE KEY UPDATE
                note = VALUES(note),
                observation = VALUES(observation)"
        );

        return $stmt->execute([
            'id_evaluation' => $id_evaluation,
            'id_parametre'  => $id_parametre,
            'id_inscription' => $id_inscription,
            'note'          => $note,
            'observation'   => $observation,
        ]);
    }

    private function validateGradeData(array $data): void
    {
        foreach (['id_evaluation', 'id_parametre', 'id_inscription'] as $field) {
            if (empty($data[$field]) || !is_numeric($data[$field])) {
                throw new InvalidArgumentException("Le champ '{$field}' est requis.");
            }
        }

        $bareme = $this->fetchColumn(
            "SELECT p.bareme
             FROM parametres p
             JOIN evaluation_parametres ep ON ep.id_parametre = p.id
             JOIN evaluations e ON e.id = ep.id_evaluation
             JOIN inscriptions i ON i.id = :id_inscription
             JOIN emplois_du_temps edt ON edt.id = i.id_emploi_du_temps
             JOIN groupes g ON g.id = edt.id_groupe
             JOIN sessions s ON s.id = g.id_session AND s.id = e.id_session
             WHERE p.id = :id_parametre AND ep.id_evaluation = :id_evaluation",
            [
                'id_inscription' => (int) $data['id_inscription'],
                'id_parametre' => (int) $data['id_parametre'],
                'id_evaluation' => (int) $data['id_evaluation'],
            ]
        );
        if ($bareme === false) {
            throw new InvalidArgumentException("Ce paramètre n'est pas associé à l'évaluation indiquée.");
        }

        if ($data['note'] !== null && $data['note'] !== '' &&
            (!is_numeric($data['note']) || (float) $data['note'] < 0 || (float) $data['note'] > (float) $bareme)
        ) {
            throw new InvalidArgumentException("La note doit être comprise entre 0 et le barème ({$bareme}).");
        }
    }

    /**
     * Moyenne pondérée d'une inscription, ramenée sur 20 :
     * moyenne = SUM(note) / SUM(barème) * 20 (notes non nulles uniquement).
     */
    public function getMoyenneInscription(int $id_inscription, ?int $id_evaluation = null): array
    {
        $sql =
            "SELECT n.id_evaluation,
                    COUNT(n.id_note) AS nb_notes,
                    SUM(n.note) AS total_points,
                    SUM(p.bareme) AS total_bareme,
                    ROUND(SUM(n.note) / SUM(p.bareme) * 20, 2) AS moyenne_sur_20
             FROM {$this->table} n
             JOIN parametres p ON p.id = n.id_parametre
             WHERE n.id_inscription = :id_inscription
               AND n.note IS NOT NULL";
        $params = ['id_inscription' => $id_inscription];
        if ($id_evaluation !== null) {
            $sql .= " AND n.id_evaluation = :id_evaluation";
            $params['id_evaluation'] = $id_evaluation;
        }
        $sql .= " GROUP BY n.id_evaluation ORDER BY n.id_evaluation";

        return $this->fetchAll($sql, $params);
    }

    public function getCompleteAverage(int $id_inscription, int $id_evaluation): ?float
    {
        $result = $this->fetchOne(
            "SELECT COUNT(DISTINCT ep.id_parametre) AS total_criteres,
                    COUNT(DISTINCT CASE WHEN n.note IS NOT NULL THEN ep.id_parametre END) AS criteres_notes,
                    ROUND(SUM(n.note) / NULLIF(SUM(CASE WHEN n.note IS NOT NULL THEN p.bareme END), 0) * 20, 2) AS moyenne_sur_20
             FROM evaluations e
             JOIN inscriptions i ON i.id = :id_inscription
             JOIN emplois_du_temps edt ON edt.id = i.id_emploi_du_temps
             JOIN groupes g ON g.id = edt.id_groupe
             JOIN sessions s ON s.id = g.id_session AND s.id = e.id_session
             JOIN evaluation_parametres ep ON ep.id_evaluation = e.id
             JOIN parametres p ON p.id = ep.id_parametre
             LEFT JOIN {$this->table} n
               ON n.id_evaluation = e.id
              AND n.id_parametre = ep.id_parametre
              AND n.id_inscription = i.id
             WHERE e.id = :id_evaluation",
            ['id_inscription' => $id_inscription, 'id_evaluation' => $id_evaluation]
        );

        if ($result === null || (int) $result['total_criteres'] === 0
            || (int) $result['criteres_notes'] !== (int) $result['total_criteres']
            || $result['moyenne_sur_20'] === null
        ) {
            return null;
        }

        return (float) $result['moyenne_sur_20'];
    }
}
