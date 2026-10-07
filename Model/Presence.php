<?php
/**
 * Modèle Presence (table : presences)
 *
 * Une présence par inscription et par date (clé unique id_inscription + date_presence).
 * La colonne jour_semaine_presence est générée automatiquement par MySQL :
 * elle ne doit jamais être insérée ni mise à jour.
 */

require_once __DIR__ . '/Model.php';

class Presence extends Model
{
    protected string $table = 'presences';

    public const STATUTS = ['present', 'absent', 'permissionnaire', 'malade', 'conge'];

    protected array $fillable = [
        'id_inscription', 'id_emploi_du_temps', 'id_formateur',
        'date_presence', 'statut', 'conge_date_debut', 'conge_date_fin', 'observation',
    ];

    /** Historique enrichi avec les références du registre pédagogique. */
    public function getAllWithDetails(): array
    {
        return $this->fetchAll(
            "SELECT p.*,
                    i.id_auditeur, i.id_departement, i.statut AS inscription_statut,
                    e.id_annee, e.id_groupe, e.id_salle,
                    g.code AS groupe_code, g.libelle AS groupe_libelle,
                    s.code AS session_code, s.libelle AS session_libelle,
                    n.libelle AS niveau_libelle,
                    d.code AS departement_code, d.libelle AS departement_libelle,
                    a.matricule AS auditeur_matricule, a.nom AS auditeur_nom, a.prenom AS auditeur_prenom,
                    a.telephone AS auditeur_telephone, a.email AS auditeur_email,
                    sa.code AS salle_code, sa.libelle AS salle_libelle,
                    f.matricule AS formateur_matricule, f.nom AS formateur_nom, f.prenom AS formateur_prenom
             FROM {$this->table} p
             JOIN inscriptions i ON i.id = p.id_inscription
             JOIN auditeurs a ON a.id = i.id_auditeur
             JOIN departements d ON d.id = i.id_departement
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             JOIN niveaux n ON n.id = s.id_niveau
             JOIN salles sa ON sa.id = e.id_salle
             JOIN formateurs f ON f.id = p.id_formateur
             ORDER BY p.date_presence DESC, a.nom, a.prenom"
        );
    }

    /* ------------------------------------------------------------------
     | Recherches filtrées
     |------------------------------------------------------------------ */

    /** Présences d'une inscription, de la plus récente à la plus ancienne. */
    public function getByInscription(int $id_inscription): array
    {
        return $this->fetchAll(
            "SELECT * FROM {$this->table}
             WHERE id_inscription = :id_inscription
             ORDER BY date_presence DESC",
            ['id_inscription' => $id_inscription]
        );
    }

    /** Présences d'une date donnée (avec l'auditeur). */
    public function getByDate(string $date): array
    {
        return $this->fetchAll(
            "SELECT p.*,
                    a.matricule AS auditeur_matricule, a.nom AS auditeur_nom, a.prenom AS auditeur_prenom
             FROM {$this->table} p
             JOIN inscriptions i ON i.id = p.id_inscription
             JOIN auditeurs a    ON a.id = i.id_auditeur
             WHERE p.date_presence = :date
             ORDER BY a.nom, a.prenom",
            ['date' => $date]
        );
    }

    /** Présences enregistrées par un formateur. */
    public function getByFormateur(int $id_formateur): array
    {
        return $this->fetchAll(
            "SELECT * FROM {$this->table}
             WHERE id_formateur = :id_formateur
             ORDER BY date_presence DESC",
            ['id_formateur' => $id_formateur]
        );
    }

    /** Présences liées à un emploi du temps. */
    public function getByEmploiDuTemps(int $id_edt): array
    {
        return $this->fetchAll(
            "SELECT p.*,
                    a.matricule AS auditeur_matricule, a.nom AS auditeur_nom, a.prenom AS auditeur_prenom
             FROM {$this->table} p
             JOIN inscriptions i ON i.id = p.id_inscription
             JOIN auditeurs a    ON a.id = i.id_auditeur
             WHERE p.id_emploi_du_temps = :id_edt
             ORDER BY p.date_presence DESC, a.nom, a.prenom",
            ['id_edt' => $id_edt]
        );
    }

    /**
     * Marque la présence d'un auditeur pour une date donnée.
     * Insère ou met à jour (clé unique id_inscription + date_presence).
     *
     * @param string $date Format 'YYYY-MM-DD'.
     * @throws InvalidArgumentException si le statut est invalide.
     */
    public function markPresence(
        int $id_inscription,
        string $date,
        string $statut,
        int $id_formateur,
        ?int $id_edt = null,
        ?string $observation = null,
        ?string $congeDateDebut = null,
        ?string $congeDateFin = null
    ): bool
    {
        $this->assertIn($statut, self::STATUTS, 'statut');
        $parsedDate = DateTime::createFromFormat('!Y-m-d', $date);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('La date de présence doit respecter le format AAAA-MM-JJ.');
        }
        if (strlen((string) $observation) > 255) {
            throw new InvalidArgumentException('L’observation ne peut pas dépasser 255 caractères.');
        }
        if ($statut === 'conge') {
            if ($congeDateDebut === null || $congeDateFin === null) {
                throw new InvalidArgumentException('Les dates de début et de fin sont requises pour un congé.');
            }
            $start = DateTime::createFromFormat('!Y-m-d', $congeDateDebut);
            $end = DateTime::createFromFormat('!Y-m-d', $congeDateFin);
            if (!$start || !$end || $start->format('Y-m-d') !== $congeDateDebut || $end->format('Y-m-d') !== $congeDateFin || $end < $start) {
                throw new InvalidArgumentException('La période de congé est invalide.');
            }
        } else {
            $congeDateDebut = null;
            $congeDateFin = null;
        }

        $schedule = $this->fetchOne(
            "SELECT i.statut AS inscription_statut,
                    e.id AS id_edt, e.id_formateur, e.jour_semaine, e.statut AS edt_actif
             FROM inscriptions i
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             WHERE i.id = :id_inscription",
            ['id_inscription' => $id_inscription]
        );
        if ($schedule === null || $schedule['inscription_statut'] !== 'active') {
            throw new DomainException('Seules les inscriptions actives peuvent être pointées.');
        }
        if (!(bool) $schedule['edt_actif']) {
            throw new DomainException('L’emploi du temps de cette inscription est inactif.');
        }
        if ($id_edt !== null && $id_edt !== (int) $schedule['id_edt']) {
            throw new DomainException('L’emploi du temps ne correspond pas à celui de l’inscription.');
        }
        if ($id_formateur !== (int) $schedule['id_formateur']) {
            throw new DomainException('Le formateur ne correspond pas au créneau attribué.');
        }
        if ((int) $parsedDate->format('N') !== (int) $schedule['jour_semaine']) {
            throw new DomainException('La date choisie ne correspond pas au jour hebdomadaire du groupe.');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO {$this->table}
                (id_inscription, id_emploi_du_temps, id_formateur, date_presence, statut,
                 conge_date_debut, conge_date_fin, observation)
             VALUES
                (:id_inscription, :id_edt, :id_formateur, :date_presence, :statut,
                 :conge_date_debut, :conge_date_fin, :observation)
             ON DUPLICATE KEY UPDATE
                id_emploi_du_temps = VALUES(id_emploi_du_temps),
                id_formateur       = VALUES(id_formateur),
                statut             = VALUES(statut),
                conge_date_debut   = VALUES(conge_date_debut),
                conge_date_fin     = VALUES(conge_date_fin),
                observation        = VALUES(observation)"
        );

        return $stmt->execute([
            'id_inscription' => $id_inscription,
            'id_edt'         => (int) $schedule['id_edt'],
            'id_formateur'   => $id_formateur,
            'date_presence'  => $date,
            'statut'         => $statut,
            'conge_date_debut' => $congeDateDebut,
            'conge_date_fin' => $congeDateFin,
            'observation'    => $observation,
        ]);
    }

    /** Met à jour les champs modifiables d'un pointage existant, sans déplacer ses liens. */
    public function updateAttendance(int $id, array $data): bool
    {
        if (isset($data['statut'])) {
            $this->assertIn((string) $data['statut'], self::STATUTS, 'statut');
            if ($data['statut'] !== 'conge') {
                $data['conge_date_debut'] = null;
                $data['conge_date_fin'] = null;
            }
        }
        if (isset($data['observation']) && strlen((string) $data['observation']) > 255) {
            throw new InvalidArgumentException('L’observation ne peut pas dépasser 255 caractères.');
        }
        if (($data['statut'] ?? null) === 'conge' || isset($data['conge_date_debut']) || isset($data['conge_date_fin'])) {
            $startValue = $data['conge_date_debut'] ?? null;
            $endValue = $data['conge_date_fin'] ?? null;
            if ($startValue === null || $endValue === null) {
                throw new InvalidArgumentException('Les dates de début et de fin sont requises pour un congé.');
            }
            $start = DateTime::createFromFormat('!Y-m-d', (string) $startValue);
            $end = DateTime::createFromFormat('!Y-m-d', (string) $endValue);
            if (!$start || !$end || $start->format('Y-m-d') !== $startValue || $end->format('Y-m-d') !== $endValue || $end < $start) {
                throw new InvalidArgumentException('La période de congé est invalide.');
            }
        }
        return $this->update($id, $data);
    }

    /**
     * Statistiques de présence d'une inscription :
     * total, effectifs par statut et taux de présence (%).
     */
    public function getStatistiques(int $id_inscription): array
    {
        $row = $this->fetchOne(
            "SELECT COUNT(*)                                          AS total,
                    SUM(statut = 'present')                           AS presents,
                    SUM(statut = 'absent')                            AS absents,
                    SUM(statut = 'permissionnaire')                   AS permissionnaires,
                    SUM(statut = 'malade')                            AS malades,
                    SUM(statut = 'conge')                             AS conges,
                    ROUND(100 * SUM(statut = 'present') / COUNT(*), 2) AS taux_presence
             FROM {$this->table}
             WHERE id_inscription = :id_inscription",
            ['id_inscription' => $id_inscription]
        );

        // Aucune présence enregistrée : retourne des compteurs à zéro.
        if ($row === null || (int) $row['total'] === 0) {
            return [
                'total' => 0, 'presents' => 0, 'absents' => 0,
                'permissionnaires' => 0, 'malades' => 0, 'conges' => 0,
                'taux_presence' => null,
            ];
        }

        return array_map(static fn($v) => $v === null ? 0 : (is_numeric($v) ? $v + 0 : $v), $row);
    }
}
