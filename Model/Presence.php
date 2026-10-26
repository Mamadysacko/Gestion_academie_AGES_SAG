<?php
/**
 * Modèle Presence (table : presences)
 *
 * Une présence par inscription et par date (clé unique id_inscription + date_presence).
 * Le jour de la semaine se déduit de date_presence ; seuls les jours de passage du groupe sont acceptés.
 */

require_once __DIR__ . '/Model.php';

class Presence extends Model
{
    protected string $table = 'presences';

    public const STATUTS = ['present', 'absent', 'permissionnaire', 'malade', 'conge'];
    /** Statuts qui couvrent une période : leurs dates de début et de fin sont obligatoires. */
    public const PERIOD_STATUTS = ['conge', 'permissionnaire'];

    /**
     * Texte de la colonne « Observation » des rapports : la période d'un congé ou d'une permission,
     * suivie de l'observation saisie. Renvoie null s'il n'y a rien à afficher.
     */
    public static function observationLabel(?string $statut, ?string $debut, ?string $fin, ?string $observation): ?string
    {
        $parts = [];
        if (in_array($statut, self::PERIOD_STATUTS, true) && $debut !== null && $fin !== null) {
            $format = static function (string $value): string {
                $date = DateTime::createFromFormat('Y-m-d', substr($value, 0, 10));
                return $date ? $date->format('d/m/Y') : $value;
            };
            $label = $statut === 'conge' ? 'Congé' : 'Permission';
            $parts[] = $debut === $fin
                ? $label . ' le ' . $format($debut)
                : $label . ' du ' . $format($debut) . ' au ' . $format($fin);
        }
        if ($observation !== null && trim($observation) !== '') {
            $parts[] = trim($observation);
        }
        return $parts ? implode(' — ', $parts) : null;
    }

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
             JOIN v_formateurs f ON f.id = p.id_formateur
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

    public function hasAttendanceOnDateForTrainer(int $id_inscription, string $date, int $id_formateur): bool
    {
        return (int) $this->fetchColumn(
            "SELECT COUNT(*)
             FROM {$this->table} p
             JOIN emplois_du_temps e ON e.id = p.id_emploi_du_temps
             WHERE p.id_inscription = :id_inscription
               AND p.date_presence = :date_presence
               AND e.id_formateur = :id_formateur",
            [
                'id_inscription' => $id_inscription,
                'date_presence' => $date,
                'id_formateur' => $id_formateur,
            ]
        ) > 0;
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
     * Feuille de pointage d'une date : auditeurs attendus (inscription active, créneau du jour
     * de la semaine, date comprise dans la session et l'année) et leur pointage éventuel.
     * @param array $filters id_session, id_groupe, id_salle, id_formateur (facultatifs)
     */
    public function getFeuille(string $date, array $filters = []): array
    {
        $parsed = DateTime::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('La date doit respecter le format AAAA-MM-JJ.');
        }
        $weekday = (int) $parsed->format('N');
        if ($weekday > 5) {
            return ['date' => $date, 'jour_semaine' => $weekday, 'jour_de_cours' => false, 'auditeurs' => []];
        }

        $where = [];
        $params = ['date1' => $date, 'date2' => $date, 'date3' => $date, 'date4' => $date, 'weekday' => $weekday];
        foreach (['id_session' => 'i.id_session', 'id_groupe' => 'e.id_groupe', 'id_salle' => 'e.id_salle', 'id_formateur' => 'e.id_formateur'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[] = "{$column} = :{$key}";
                $params[$key] = (int) $filters[$key];
            }
        }

        $rows = $this->fetchAll(
            "SELECT i.id AS id_inscription, e.id AS id_emploi_du_temps, i.id_session, i.id_annee,
                    a.id AS id_auditeur, a.matricule AS auditeur_matricule, a.nom AS auditeur_nom, a.prenom AS auditeur_prenom,
                    a.sexe AS auditeur_sexe, a.telephone AS auditeur_telephone, po.libelle AS auditeur_position,
                    te.libelle AS type_employe_libelle, d.code AS departement_code, d.libelle AS departement_libelle,
                    n.libelle AS niveau_libelle, e.jour_semaine,
                    e.id_groupe, g.code AS groupe_code, g.libelle AS groupe_libelle, e.heure_debut, e.heure_fin,
                    s.code AS session_code, s.libelle AS session_libelle,
                    e.id_salle, sa.code AS salle_code, sa.libelle AS salle_libelle,
                    e.id_formateur, f.nom AS formateur_nom, f.prenom AS formateur_prenom,
                    p.id AS id_presence, p.statut, p.observation, p.conge_date_debut, p.conge_date_fin,
                    (SELECT CONCAT(c.statut, '|', c.conge_date_debut, '|', c.conge_date_fin) FROM presences c
                      WHERE c.id_inscription = i.id AND c.statut IN ('conge', 'permissionnaire')
                        AND :date3 BETWEEN c.conge_date_debut AND c.conge_date_fin
                      ORDER BY c.conge_date_debut DESC LIMIT 1) AS conge_en_cours
             FROM inscriptions i
             JOIN auditeurs a ON a.id = i.id_auditeur
             LEFT JOIN types_employes te ON te.id = a.id_type_employe LEFT JOIN positions po ON po.id = a.id_position
             JOIN departements d ON d.id = i.id_departement
             JOIN emplois_du_temps ei ON ei.id = i.id_emploi_du_temps
             -- Créneau du jour : même groupe, même salle, jour de passage demandé.
             JOIN emplois_du_temps e ON e.id_groupe = ei.id_groupe AND e.id_salle = ei.id_salle AND e.jour_semaine = :weekday
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = i.id_session
             JOIN niveaux n ON n.id = s.id_niveau
             JOIN annees_scolaires an ON an.id = i.id_annee
             JOIN salles sa ON sa.id = e.id_salle
             JOIN v_formateurs f ON f.id = e.id_formateur
             LEFT JOIN presences p ON p.id_inscription = i.id AND p.date_presence = :date4
             WHERE i.statut = 'active' AND e.statut = 1 AND g.statut = 1
               AND :date1 BETWEEN s.date_debut AND s.date_fin
               AND :date2 BETWEEN an.date_debut AND an.date_fin"
            . ($where ? ' AND ' . implode(' AND ', $where) : '')
            . ' ORDER BY s.code, g.code, sa.code, a.nom, a.prenom',
            $params
        );

        foreach ($rows as &$row) {
            if ($row['conge_en_cours'] !== null) {
                [$kind, $start, $end] = explode('|', $row['conge_en_cours']);
                $row['conge_en_cours'] = ['type' => $kind, 'debut' => $start, 'fin' => $end];
            }
        }
        unset($row);

        return ['date' => $date, 'jour_semaine' => $weekday, 'jour_de_cours' => true, 'auditeurs' => $rows];
    }

    /**
     * Pointe plusieurs inscriptions à la même date. Un auditeur en congé sur cette date
     * reste automatiquement « congé » (avec sa période) lorsqu'on le marque présent.
     * @return array{saved:int, errors:array}
     */
    public function markBulk(string $date, string $statut, array $inscriptionIds, ?int $trainerId = null): array
    {
        $this->assertIn($statut, self::STATUTS, 'statut');
        if (in_array($statut, self::PERIOD_STATUTS, true)) {
            throw new InvalidArgumentException('Un congé ou une permission se saisit individuellement avec ses dates de début et de fin.');
        }
        $saved = 0;
        $errors = [];
        foreach (array_unique(array_map('intval', $inscriptionIds)) as $id) {
            try {
                $context = $this->fetchOne(
                    "SELECT e.id_formateur,
                            (SELECT CONCAT(c.statut, '|', c.conge_date_debut, '|', c.conge_date_fin) FROM presences c
                              WHERE c.id_inscription = i.id AND c.statut IN ('conge', 'permissionnaire')
                                AND :date BETWEEN c.conge_date_debut AND c.conge_date_fin
                              ORDER BY c.conge_date_debut DESC LIMIT 1) AS conge
                     FROM inscriptions i JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
                     WHERE i.id = :id",
                    ['id' => $id, 'date' => $date]
                );
                if ($context === null) {
                    throw new DomainException('Inscription introuvable.');
                }
                if ($trainerId !== null && (int) $context['id_formateur'] !== $trainerId) {
                    throw new DomainException('Cet auditeur ne fait pas partie de vos classes.');
                }
                $finalStatus = $statut;
                $start = $end = null;
                if ($context['conge'] !== null && $statut === 'present') {
                    // Un auditeur en congé ou en permission sur cette date garde son statut et sa période.
                    [$finalStatus, $start, $end] = explode('|', $context['conge']);
                }
                $this->markPresence($id, $date, $finalStatus, (int) $context['id_formateur'], null, null, $start, $end);
                $saved++;
            } catch (InvalidArgumentException | DomainException $e) {
                $errors[] = ['id_inscription' => $id, 'error' => $e->getMessage()];
            }
        }
        return ['saved' => $saved, 'errors' => $errors];
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
        if ($date > date('Y-m-d')) {
            throw new DomainException('Un pointage ne peut pas être enregistré pour une date future.');
        }
        if (strlen((string) $observation) > 255) {
            throw new InvalidArgumentException('L’observation ne peut pas dépasser 255 caractères.');
        }
        if (in_array($statut, self::PERIOD_STATUTS, true)) {
            $kindLabel = $statut === 'conge' ? 'un congé' : 'une permission';
            if ($congeDateDebut === null || $congeDateFin === null) {
                throw new InvalidArgumentException('Les dates de début et de fin sont requises pour ' . $kindLabel . '.');
            }
            $start = DateTime::createFromFormat('!Y-m-d', $congeDateDebut);
            $end = DateTime::createFromFormat('!Y-m-d', $congeDateFin);
            if (!$start || !$end || $start->format('Y-m-d') !== $congeDateDebut || $end->format('Y-m-d') !== $congeDateFin || $end < $start) {
                throw new InvalidArgumentException('La période de ' . ($statut === 'conge' ? 'congé' : 'permission') . ' est invalide.');
            }
        } else {
            $congeDateDebut = null;
            $congeDateFin = null;
        }

        $schedule = $this->fetchOne(
            "SELECT i.statut AS inscription_statut,
                    e.id AS id_edt, e.id_formateur, e.jour_semaine, e.statut AS edt_actif,
                    g.jour_semaine AS groupe_jour_semaine,
                    an.date_debut AS annee_date_debut, an.date_fin AS annee_date_fin,
                    se.date_debut AS session_date_debut, se.date_fin AS session_date_fin
             FROM inscriptions i
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN groupes g ON g.id = e.id_groupe
             JOIN annees_scolaires an ON an.id = e.id_annee
             JOIN sessions se ON se.id = i.id_session
             WHERE i.id = :id_inscription",
            ['id_inscription' => $id_inscription]
        );
        if ($schedule === null || $schedule['inscription_statut'] !== 'active') {
            throw new DomainException('Seules les inscriptions actives peuvent être pointées.');
        }
        if (!(bool) $schedule['edt_actif']) {
            throw new DomainException('L’emploi du temps de cette inscription est inactif.');
        }
        $requestedEdt = ($id_edt === null || $id_edt <= 0) ? null : $id_edt;
        if ($id_formateur <= 0) {
            $id_formateur = (int) $schedule['id_formateur'];
        } elseif ($id_formateur !== (int) $schedule['id_formateur']) {
            throw new DomainException('Le formateur ne correspond pas au créneau attribué.');
        }
        if ($date < $schedule['annee_date_debut'] || $date > $schedule['annee_date_fin']) {
            throw new DomainException('La date choisie ne se trouve pas dans la période de l’année scolaire.');
        }
        if ($date < $schedule['session_date_debut'] || $date > $schedule['session_date_fin']) {
            throw new DomainException('La date choisie est en dehors de la période de la session.');
        }
        $attendanceDate = DateTime::createFromFormat('!Y-m-d', $date);
        if (!$attendanceDate) {
            throw new InvalidArgumentException('La date de présence est invalide.');
        }
        $weekday = (int) $attendanceDate->format('N');
        // Créneau de la classe (même groupe, même salle) pour ce jour de passage.
        $classRows = $this->fetchAll(
            "SELECT e2.id, e2.jour_semaine, e2.statut FROM emplois_du_temps e2
             JOIN emplois_du_temps e ON e.id = :edt
             WHERE e2.id_groupe = e.id_groupe AND e2.id_salle = e.id_salle",
            ['edt' => (int) $schedule['id_edt']]
        );
        $dayEdt = null;
        foreach ($classRows as $row) {
            if ((int) $row['jour_semaine'] === $weekday && (int) $row['statut'] === 1) {
                $dayEdt = (int) $row['id'];
            }
        }
        if ($dayEdt === null) {
            throw new DomainException('La date choisie ne correspond à aucun jour de passage du groupe.');
        }
        if ($requestedEdt !== null && !in_array($requestedEdt, array_map(static fn($r) => (int) $r['id'], $classRows), true)) {
            throw new DomainException('L’emploi du temps ne correspond pas à celui de l’inscription.');
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
            'id_edt'         => $dayEdt,
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
            if (!in_array($data['statut'], self::PERIOD_STATUTS, true)) {
                $data['conge_date_debut'] = null;
                $data['conge_date_fin'] = null;
            }
        }
        if (isset($data['observation']) && strlen((string) $data['observation']) > 255) {
            throw new InvalidArgumentException('L’observation ne peut pas dépasser 255 caractères.');
        }
        if (in_array($data['statut'] ?? null, self::PERIOD_STATUTS, true) || isset($data['conge_date_debut']) || isset($data['conge_date_fin'])) {
            $startValue = $data['conge_date_debut'] ?? null;
            $endValue = $data['conge_date_fin'] ?? null;
            if ($startValue === null || $endValue === null) {
                throw new InvalidArgumentException('Les dates de début et de fin sont requises pour un congé ou une permission.');
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
