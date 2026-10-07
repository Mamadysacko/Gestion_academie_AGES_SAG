<?php
/**
 * Modèle PassageNiveau (table : passages_niveaux)
 *
 * Décision de fin de formation pour une inscription
 * (contrainte unique sur id_inscription : une seule décision par inscription).
 */

require_once __DIR__ . '/Model.php';
require_once __DIR__ . '/Note.php';

class PassageNiveau extends Model
{
    protected string $table = 'passages_niveaux';

    public const DECISIONS = ['admis', 'non_admis', 'redouble', 'abandonne'];

    protected array $fillable = [
        'id_inscription', 'niveau_depart', 'niveau_arrivee',
        'decision', 'moyenne', 'date_decision', 'observation',
    ];

    public function getAllWithDetails(): array
    {
        return $this->fetchAll(
            "SELECT p.*,
                    i.statut AS inscription_statut,
                    a.matricule AS auditeur_matricule,
                    a.nom AS auditeur_nom, a.prenom AS auditeur_prenom,
                    e.id AS id_emploi_du_temps, e.id_annee, e.id_groupe,
                    g.code AS groupe_code, g.libelle AS groupe_libelle,
                    s.id AS id_session, s.libelle AS session_libelle,
                    s.id_niveau AS niveau_session,
                    nd.libelle AS niveau_depart_libelle,
                    na.libelle AS niveau_arrivee_libelle
             FROM {$this->table} p
             JOIN inscriptions i ON i.id = p.id_inscription
             JOIN auditeurs a ON a.id = i.id_auditeur
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             JOIN niveaux nd ON nd.id = p.niveau_depart
             LEFT JOIN niveaux na ON na.id = p.niveau_arrivee
             ORDER BY p.date_decision DESC, a.nom, a.prenom"
        );
    }

    /** Décision de passage associée à une inscription. */
    public function getByInscription(int $id_inscription): ?array
    {
        return $this->fetchOne(
            "SELECT p.*,
                    nd.libelle AS niveau_depart_libelle,
                    na.libelle AS niveau_arrivee_libelle
             FROM {$this->table} p
             JOIN niveaux nd      ON nd.id = p.niveau_depart
             LEFT JOIN niveaux na ON na.id = p.niveau_arrivee
             WHERE p.id_inscription = :id_inscription
             LIMIT 1",
            ['id_inscription' => $id_inscription]
        );
    }

    /**
     * Enregistre (ou met à jour) la décision de passage d'une inscription.
     *
     * Le niveau de départ est déduit automatiquement du parcours :
     * inscription -> emploi du temps -> groupe -> session -> niveau.
     *
     * @param string   $decision        'admis' | 'non_admis' | 'redouble' | 'abandonne'
     * @param float|null $moyenne       Moyenne finale (ex. issue de Note::getMoyenneInscription).
     * @param int|null   $niveau_arrivee Niveau d'arrivée (requis si 'admis').
     * @throws InvalidArgumentException|RuntimeException
     */
    public function decide(
        int $id_inscription,
        int $id_evaluation,
        string $decision,
        ?int $niveau_arrivee = null,
        ?string $observation = null
    ): bool
    {
        $this->assertIn($decision, self::DECISIONS, 'decision');

        if ($decision === 'admis' && ($niveau_arrivee === null || $niveau_arrivee <= 0)) {
            throw new InvalidArgumentException("Sélectionnez un niveau d'arrivée pour une décision 'admis'.");
        }
        if ($decision !== 'admis' && $niveau_arrivee !== null) {
            throw new InvalidArgumentException("Le niveau d'arrivée doit être vide pour cette décision.");
        }
        if ($observation !== null && mb_strlen($observation) > 255) {
            throw new InvalidArgumentException("L'observation ne peut pas dépasser 255 caractères.");
        }

        $context = $this->fetchOne(
            "SELECT s.id_niveau, g.id_session
             FROM inscriptions i
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN groupes g          ON g.id = e.id_groupe
             JOIN sessions s         ON s.id = g.id_session
             WHERE i.id = :id",
            ['id' => $id_inscription]
        );

        if ($context === null) {
            throw new RuntimeException("Inscription introuvable : {$id_inscription}");
        }

        $evaluationSession = $this->fetchColumn(
            'SELECT id_session FROM evaluations WHERE id = :id',
            ['id' => $id_evaluation]
        );
        if ($evaluationSession === false || (int) $evaluationSession !== (int) $context['id_session']) {
            throw new InvalidArgumentException('L’évaluation doit appartenir à la session de l’inscription.');
        }

        if ($niveau_arrivee !== null && $niveau_arrivee === (int) $context['id_niveau']) {
            throw new InvalidArgumentException('Le niveau d’arrivée doit être différent du niveau de départ.');
        }
        if ($niveau_arrivee !== null && $this->fetchOne(
            'SELECT id FROM niveaux WHERE id = :id LIMIT 1',
            ['id' => $niveau_arrivee]
        ) === null) {
            throw new InvalidArgumentException('Le niveau d’arrivée sélectionné est introuvable.');
        }

        $moyenne = (new Note())->getCompleteAverage($id_inscription, $id_evaluation);
        if ($moyenne === null) {
            throw new InvalidArgumentException('Toutes les notes des critères de cette évaluation doivent être saisies avant la décision.');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO {$this->table}
                (id_inscription, niveau_depart, niveau_arrivee, decision, moyenne, date_decision, observation)
             VALUES
                (:id_inscription, :niveau_depart, :niveau_arrivee, :decision, :moyenne, CURDATE(), :observation)
             ON DUPLICATE KEY UPDATE
                niveau_depart  = VALUES(niveau_depart),
                niveau_arrivee = VALUES(niveau_arrivee),
                decision       = VALUES(decision),
                moyenne        = VALUES(moyenne),
                date_decision  = VALUES(date_decision),
                observation    = VALUES(observation)"
        );

        return $stmt->execute([
            'id_inscription' => $id_inscription,
            'niveau_depart'  => (int) $context['id_niveau'],
            'niveau_arrivee' => $niveau_arrivee,
            'decision'       => $decision,
            'moyenne'        => $moyenne,
            'observation'    => $observation,
        ]);
    }
}
