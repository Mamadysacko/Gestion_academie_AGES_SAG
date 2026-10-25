<?php

require_once __DIR__ . '/Model.php';

class Dashboard extends Model
{
    private const REPORT_TYPES = ['journalier', 'hebdomadaire', 'mensuel', 'annuel', 'session', 'periode'];

    // ─────────────────────────────────────────────────────────────────────────
    //  Point d'entrée principal
    // ─────────────────────────────────────────────────────────────────────────

    public function getDashboard(?int $yearId, ?int $sessionId, string $date): array
    {
        $this->assertDate($date);

        // Toutes les années scolaires (pour le sélecteur front)
        $years = $this->fetchAll(
            'SELECT id, libelle, date_debut, date_fin, statut
             FROM annees_scolaires
             ORDER BY statut DESC, date_debut DESC'
        );

        // Auto-sélection de l'année active si non fournie
        if ($yearId === null) {
            foreach ($years as $year) {
                if ((bool) $year['statut']) {
                    $yearId = (int) $year['id'];
                    break;
                }
            }
        }

        if ($yearId !== null && !$this->existsInTable('annees_scolaires', $yearId)) {
            throw new InvalidArgumentException('Année scolaire introuvable.');
        }
        if ($sessionId !== null && !$this->existsInTable('sessions', $sessionId)) {
            throw new InvalidArgumentException('Session introuvable.');
        }
        if ($yearId !== null && $sessionId !== null && !$this->sessionBelongsToYear($sessionId, $yearId)) {
            throw new InvalidArgumentException("La session sélectionnée ne correspond pas à l'année scolaire.");
        }

        $sessions = $this->getSessions($yearId);

        // ── Scope WHERE réutilisable ──
        // edt  : alias de emplois_du_temps (filtre e.id_annee ET s.id_annee)
        // sess : alias de sessions          (filtre s.id_annee)
        $edtScope  = $this->edtScopeWhere($yearId, $sessionId);   // filtre via e.id_annee + s.id
        $sessScope = $this->sessScopeWhere($yearId, $sessionId);  // filtre via s.id_annee + s.id

        // ── Inscriptions actives ──
        $enrolments = $this->fetchOne(
            "SELECT COUNT(*)                  AS total,
                    COUNT(DISTINCT i.id_auditeur)     AS auditeurs,
                    COUNT(DISTINCT e.id_groupe)       AS groupes
             FROM inscriptions i
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             WHERE i.statut = 'active'
               AND e.statut = 1
               AND g.statut = 1
               {$edtScope['sql']}",
            $edtScope['params']
        ) ?? [];

        // ── Groupes actifs distincts ──
        $groupCounts = $this->fetchOne(
            "SELECT COUNT(DISTINCT e.id_groupe) AS total
             FROM emplois_du_temps e
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             WHERE e.statut = 1
               AND g.statut = 1
               {$edtScope['sql']}",
            $edtScope['params']
        ) ?? [];

        // ── Sessions ──
        $sessionCounts = $this->fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN s.statut IN ('ouverte','en_cours')
                        AND :analysis_date BETWEEN s.date_debut AND s.date_fin
                        THEN 1 ELSE 0 END) AS en_cours
             FROM sessions s
             WHERE 1 = 1 {$sessScope['sql']}",
            array_merge(['analysis_date' => $date], $sessScope['params'])
        ) ?? [];

        // ── Ressources globales ──
        $resourceCounts = $this->fetchOne(
            "SELECT
                (SELECT COUNT(*) FROM formateurs WHERE actif = 1) AS formateurs_actifs,
                (SELECT COUNT(*) FROM salles)                     AS salles_total,
                (SELECT COUNT(*) FROM salles WHERE statut = 'disponible') AS salles_disponibles,
                (SELECT COUNT(*) FROM auditeurs)                  AS auditeurs_total"
        ) ?? [];

        $occupiedRooms = $this->fetchOne(
            "SELECT COUNT(DISTINCT e.id_salle) AS total
             FROM emplois_du_temps e
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             WHERE e.statut = 1
               {$edtScope['sql']}",
            $edtScope['params']
        ) ?? [];

        // ── Capacité groupes ──
        $capacity = $this->fetchOne(
            "SELECT COALESCE(SUM(g.capacite), 0) AS total,
                    COALESCE(SUM(CASE WHEN ac.inscrits > g.capacite
                        THEN ac.inscrits - g.capacite ELSE 0 END), 0) AS depassements
             FROM groupes g
             JOIN sessions s ON s.id = g.id_session
             LEFT JOIN (
                 SELECT e2.id_groupe, COUNT(i2.id) AS inscrits
                 FROM emplois_du_temps e2
                 JOIN inscriptions i2 ON i2.id_emploi_du_temps = e2.id AND i2.statut = 'active'
                 WHERE e2.statut = 1
                 GROUP BY e2.id_groupe
             ) ac ON ac.id_groupe = g.id
             WHERE g.statut = 1
               AND g.capacite IS NOT NULL
               {$sessScope['sql']}",
            $sessScope['params']
        ) ?? [];

        // ── Présences / notes / passages ──
        $attendance          = $this->attendanceTotals($date, $yearId, $sessionId);
        $attendanceTrend     = $this->attendanceTrend($date, $yearId, $sessionId, 7);
        $registrationTrend   = $this->registrationTrend($yearId, $sessionId);
        $departments         = $this->departmentDistribution($yearId, $sessionId);
        $levels              = $this->levelDistribution($yearId, $sessionId);
        $groups              = $this->groupDistribution($yearId, $sessionId);
        $passages            = $this->passageTotals($yearId, $sessionId);
        $planning            = $this->getPlanningForDate($date, $yearId, $sessionId);
        $upcomingEvaluations = $this->getUpcomingEvaluations($date, $yearId, $sessionId);
        $alerts              = $this->getAlerts($date, $yearId, $sessionId);
        $expected            = $this->expectedAttendance($date, $yearId, $sessionId);
        $results             = $this->resultsSummary($yearId, $sessionId, $sessions);
        if ($results['notes_manquantes_echues'] > 0) {
            $alerts[] = [
                'type'     => 'notes',
                'severite' => 'attention',
                'total'    => $results['notes_manquantes_echues'],
                'message'  => "{$results['notes_manquantes_echues']} note(s) manquante(s) pour des évaluations déjà passées.",
            ];
        }

        return [
            'filters' => [
                'date'      => $date,
                'id_annee'  => $yearId,
                'id_session'=> $sessionId,
                'annees'    => $years,
                'sessions'  => $sessions,
            ],
            'kpis' => [
                'inscriptions_actives'    => (int) ($enrolments['total']    ?? 0),
                'auditeurs_actifs'        => (int) ($enrolments['auditeurs'] ?? 0),
                'auditeurs_total'         => (int) ($resourceCounts['auditeurs_total'] ?? 0),
                'groupes_actifs'          => (int) ($groupCounts['total']   ?? 0),
                'sessions_total'          => (int) ($sessionCounts['total'] ?? 0),
                'sessions_en_cours'       => (int) ($sessionCounts['en_cours'] ?? 0),
                'formateurs_actifs'       => (int) ($resourceCounts['formateurs_actifs'] ?? 0),
                'salles_total'            => (int) ($resourceCounts['salles_total'] ?? 0),
                'salles_disponibles'      => (int) ($resourceCounts['salles_disponibles'] ?? 0),
                'salles_occupees'         => (int) ($occupiedRooms['total'] ?? 0),
                'capacite_totale_groupes' => (int) ($capacity['total']      ?? 0),
                'evaluations_a_venir'     => $this->getUpcomingEvaluationCount($date, $yearId, $sessionId),
                'evaluations_realisees'   => $this->doneEvaluationCount($date, $yearId, $sessionId),
                'taux_presence'           => $attendance['taux_presence'],
                'presences_du_jour'       => $attendance['total'],
                'admis'                   => (int) ($passages['admis']   ?? 0),
                'decisions_total'         => (int) ($passages['total']   ?? 0),
                'moyenne_passages'        => $passages['moyenne'] === null ? null : round((float) $passages['moyenne'], 2),
                'attendus_du_jour'        => $expected['attendus'],
                'pointes_du_jour'         => $expected['pointes'],
                'non_pointes_du_jour'     => $expected['attendus'] - $expected['pointes'],
                'resultats_admis'         => $results['admis'],
                'resultats_non_admis'     => $results['non_admis'],
                'resultats_en_attente'    => $results['en_attente'],
                'moyenne_generale'        => $results['moyenne'],
                'notes_manquantes'        => $results['notes_manquantes'],
            ],
            'resultats' => $results,
            'repartition_salles'        => $this->enrolmentBy('salle', $yearId, $sessionId),
            'repartition_sessions'      => $this->enrolmentBy('session', $yearId, $sessionId),
            'repartition_types_employes'=> $this->enrolmentBy('type_employe', $yearId, $sessionId),
            'presence_par_salle'        => $this->presenceBy('salle', null, null, $yearId, $sessionId),
            'presence_par_groupe'       => $this->presenceBy('groupe', null, null, $yearId, $sessionId),
            'presence_par_departement'  => $this->presenceBy('departement', null, null, $yearId, $sessionId),
            'presence_par_type_employe' => $this->presenceBy('type_employe', null, null, $yearId, $sessionId),
            'presence_jour'           => $attendance,
            'series' => [
                'presences'    => $attendanceTrend,
                'inscriptions' => $registrationTrend,
            ],
            'repartition_departements' => $departments,
            'repartition_niveaux'      => $levels,
            'repartition_groupes'      => $groups,
            'decisions'               => $this->getPassageBreakdown($yearId, $sessionId),
            'planning_jour'           => $planning,
            'evaluations_a_venir'     => $upcomingEvaluations,
            'alertes'                 => $alerts,
            'capacite' => [
                'places'      => (int) ($capacity['total']       ?? 0),
                'depassements'=> (int) ($capacity['depassements'] ?? 0),
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Rapport périodique
    // ─────────────────────────────────────────────────────────────────────────

    public function getReport(string $type, ?string $date, ?string $month, ?int $yearId, ?int $sessionId, array $extra = [], ?string $customStart = null, ?string $customEnd = null): array
    {
        if (!in_array($type, self::REPORT_TYPES, true)) {
            throw new InvalidArgumentException('Type de rapport invalide.');
        }
        if ($yearId !== null && !$this->existsInTable('annees_scolaires', $yearId)) {
            throw new InvalidArgumentException('Année scolaire introuvable.');
        }
        if ($sessionId !== null && !$this->existsInTable('sessions', $sessionId)) {
            throw new InvalidArgumentException('Session introuvable.');
        }
        if ($yearId !== null && $sessionId !== null && !$this->sessionBelongsToYear($sessionId, $yearId)) {
            throw new InvalidArgumentException("La session sélectionnée ne correspond pas à l'année scolaire.");
        }

        [$start, $end, $label] = $type === 'periode'
            ? $this->customPeriod($customStart, $customEnd)
            : $this->resolveReportPeriod($type, $date, $month, $yearId, $sessionId);
        $extra = array_filter(array_intersect_key($extra, array_flip(['id_groupe', 'id_salle', 'id_formateur'])));
        $edtScope = $this->edtScopeWhere($yearId, $sessionId, $extra);
        $params   = array_merge(['rpt_start' => $start, 'rpt_end' => $end], $edtScope['params']);

        $attendance = $this->fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN p.statut = 'present'        THEN 1 ELSE 0 END) AS present,
                    SUM(CASE WHEN p.statut = 'absent'         THEN 1 ELSE 0 END) AS absent,
                    SUM(CASE WHEN p.statut = 'permissionnaire' THEN 1 ELSE 0 END) AS permissionnaire,
                    SUM(CASE WHEN p.statut = 'malade'         THEN 1 ELSE 0 END) AS malade,
                    SUM(CASE WHEN p.statut = 'conge'          THEN 1 ELSE 0 END) AS conge
             FROM presences p
             JOIN inscriptions i ON i.id = p.id_inscription
             JOIN emplois_du_temps e ON e.id = COALESCE(p.id_emploi_du_temps, i.id_emploi_du_temps)
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             WHERE p.date_presence BETWEEN :rpt_start AND :rpt_end
               {$edtScope['sql']}",
            $params
        ) ?? [];

        $grouping  = in_array($type, ['annuel', 'session'], true)
            ? "DATE_FORMAT(p.date_presence, '%Y-%m')"
            : 'p.date_presence';
        $seriesRows = $this->fetchAll(
            "SELECT {$grouping} AS periode,
                    COUNT(*) AS total,
                    SUM(CASE WHEN p.statut = 'present'        THEN 1 ELSE 0 END) AS present,
                    SUM(CASE WHEN p.statut = 'absent'         THEN 1 ELSE 0 END) AS absent,
                    SUM(CASE WHEN p.statut = 'permissionnaire' THEN 1 ELSE 0 END) AS permissionnaire,
                    SUM(CASE WHEN p.statut = 'malade'         THEN 1 ELSE 0 END) AS malade,
                    SUM(CASE WHEN p.statut = 'conge'          THEN 1 ELSE 0 END) AS conge
             FROM presences p
             JOIN inscriptions i ON i.id = p.id_inscription
             JOIN emplois_du_temps e ON e.id = COALESCE(p.id_emploi_du_temps, i.id_emploi_du_temps)
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             WHERE p.date_presence BETWEEN :rpt_start AND :rpt_end
               {$edtScope['sql']}
             GROUP BY periode ORDER BY periode",
            $params
        );
        $series = $this->completeReportSeries($seriesRows, $type, $start, $end);

        $rows = $this->fetchAll(
            "SELECT d.code AS departement_code, d.libelle AS departement,
                    g.code AS groupe_code, g.libelle AS groupe,
                    COUNT(p.id) AS total,
                    SUM(CASE WHEN p.statut = 'present'        THEN 1 ELSE 0 END) AS present,
                    SUM(CASE WHEN p.statut = 'absent'         THEN 1 ELSE 0 END) AS absent,
                    SUM(CASE WHEN p.statut = 'permissionnaire' THEN 1 ELSE 0 END) AS permissionnaire,
                    SUM(CASE WHEN p.statut = 'malade'         THEN 1 ELSE 0 END) AS malade,
                    SUM(CASE WHEN p.statut = 'conge'          THEN 1 ELSE 0 END) AS conge
             FROM presences p
             JOIN inscriptions i ON i.id = p.id_inscription
             JOIN departements d ON d.id = i.id_departement
             JOIN emplois_du_temps e ON e.id = COALESCE(p.id_emploi_du_temps, i.id_emploi_du_temps)
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             WHERE p.date_presence BETWEEN :rpt_start AND :rpt_end
               {$edtScope['sql']}
             GROUP BY d.id, g.id
             ORDER BY d.libelle, g.libelle",
            $params
        );

        $registrations = $this->fetchOne(
            "SELECT
                SUM(CASE WHEN i.date_inscription BETWEEN :rpt_start AND :rpt_end THEN 1 ELSE 0 END) AS nouvelles,
                SUM(CASE WHEN i.statut = 'active' THEN 1 ELSE 0 END) AS actives
             FROM inscriptions i
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             WHERE 1 = 1 {$edtScope['sql']}",
            $params
        ) ?? [];

        $sessScope   = $this->sessScopeWhere($yearId, $sessionId);
        $evalParams  = array_merge(['rpt_start' => $start, 'rpt_end' => $end], $sessScope['params']);
        $evaluations = (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM evaluations ev
             JOIN sessions s ON s.id = ev.id_session
             WHERE ev.date_evaluation BETWEEN :rpt_start AND :rpt_end
               {$sessScope['sql']}",
            $evalParams
        );

        $passages = $this->fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN pn.decision = 'admis' THEN 1 ELSE 0 END) AS admis,
                    AVG(pn.moyenne) AS moyenne
             FROM passages_niveaux pn
             JOIN inscriptions i ON i.id = pn.id_inscription
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             WHERE pn.date_decision BETWEEN :rpt_start AND :rpt_end
               {$edtScope['sql']}",
            $params
        ) ?? [];

        $total = (int) ($attendance['total'] ?? 0);
        return [
            'periode'  => ['type' => $type, 'libelle' => $label, 'debut' => $start, 'fin' => $end],
            'filtres'  => ['id_annee' => $yearId, 'id_session' => $sessionId] + $extra,
            'synthese' => [
                'total_pointages'       => $total,
                'presents'              => (int) ($attendance['present']        ?? 0),
                'absents'               => (int) ($attendance['absent']         ?? 0),
                'permissionnaires'      => (int) ($attendance['permissionnaire'] ?? 0),
                'malades'               => (int) ($attendance['malade']         ?? 0),
                'conges'                => (int) ($attendance['conge']          ?? 0),
                'taux_presence'         => $total > 0
                    ? round(((int) $attendance['present'] / $total) * 100, 1)
                    : null,
                'nouvelles_inscriptions'=> (int) ($registrations['nouvelles']  ?? 0),
                'inscriptions_actives'  => (int) ($registrations['actives']    ?? 0),
                'evaluations'           => $evaluations,
                'decisions'             => (int) ($passages['total']  ?? 0),
                'admis'                 => (int) ($passages['admis']  ?? 0),
                'moyenne_passages'      => $passages['moyenne'] === null
                    ? null
                    : round((float) $passages['moyenne'], 2),
            ],
            'evolution' => $series,
            'groupes'   => $rows,
            'par_salle'        => $this->presenceBy('salle', $start, $end, $yearId, $sessionId, $extra),
            'par_groupe'       => $this->presenceBy('groupe', $start, $end, $yearId, $sessionId, $extra),
            'par_departement'  => $this->presenceBy('departement', $start, $end, $yearId, $sessionId, $extra),
            'par_type_employe' => $this->presenceBy('type_employe', $start, $end, $yearId, $sessionId, $extra),
            'par_auditeur'     => $this->presenceBy('auditeur', $start, $end, $yearId, $sessionId, $extra),
            'resultats'        => $this->reportResults($start, $end, $yearId, $sessionId, $extra),
            'options'          => $this->reportOptions($yearId, $sessionId),
        ];
    }

    /** Période libre (date de début et de fin). */
    private function customPeriod(?string $start, ?string $end): array
    {
        if ($start === null || $end === null) {
            throw new InvalidArgumentException('Indiquez la date de début et la date de fin de la période.');
        }
        $this->assertDate($start);
        $this->assertDate($end);
        if ($end < $start) {
            throw new InvalidArgumentException('La date de fin doit suivre la date de début.');
        }
        return [$start, $end, 'Du ' . (new DateTimeImmutable($start))->format('d/m/Y') . ' au ' . (new DateTimeImmutable($end))->format('d/m/Y')];
    }

    /**
     * Résultats des évaluations (mêmes calculs que le module Évaluations) des sessions
     * qui chevauchent la période, restreints aux filtres groupe / salle / formateur.
     */
    private function reportResults(string $start, string $end, ?int $yearId, ?int $sessionId, array $extra): array
    {
        require_once __DIR__ . '/Resultat.php';
        $sql = 'SELECT id FROM sessions s WHERE s.date_debut <= :rr_end AND s.date_fin >= :rr_start';
        $params = ['rr_start' => $start, 'rr_end' => $end];
        if ($sessionId !== null) {
            $sql = 'SELECT id FROM sessions s WHERE s.id = :rr_session';
            $params = ['rr_session' => $sessionId];
        } elseif ($yearId !== null) {
            $sql .= ' AND s.id_annee = :rr_year';
            $params['rr_year'] = $yearId;
        }
        $model = new Resultat();
        $sessions = [];
        foreach ($this->fetchAll($sql . ' ORDER BY s.date_debut', $params) as $row) {
            $result = $model->getSessionResults((int) $row['id'], $extra);
            if ($result['synthese']['effectif'] === 0) {
                continue;
            }
            $sessions[] = [
                'session' => $result['session'],
                'regles' => $result['regles'],
                'bareme' => $result['bareme'],
                'evaluations' => array_map(static fn($e) => array_diff_key($e, ['criteres' => true]), $result['evaluations']),
                'synthese' => $result['synthese'],
                'auditeurs' => array_map(static fn($r) => [
                    'matricule' => $r['auditeur_matricule'],
                    'nom' => $r['auditeur_nom'] . ' ' . $r['auditeur_prenom'],
                    'groupe' => $r['groupe_code'],
                    'salle' => $r['salle_code'],
                    'evaluations' => array_map(static fn($e) => ['numero' => $e['numero'], 'moyenne' => $e['moyenne'], 'appreciation' => $e['appreciation']], $r['evaluations']),
                    'moyenne_generale' => $r['moyenne_generale'],
                    'appreciation_generale' => $r['appreciation_generale'],
                    'taux_presence' => $r['presences']['taux'],
                    'resultat' => $r['resultat'],
                ], $result['auditeurs']),
            ];
        }
        return $sessions;
    }

    /** Listes de filtres du rapport (groupes, salles, formateurs du périmètre). */
    private function reportOptions(?int $yearId, ?int $sessionId): array
    {
        $scope = $this->edtScopeWhere($yearId, $sessionId);
        $base = "FROM emplois_du_temps e JOIN groupes g ON g.id = e.id_groupe JOIN sessions s ON s.id = g.id_session WHERE 1 = 1 {$scope['sql']}";
        return [
            'groupes' => $this->fetchAll("SELECT DISTINCT g.id, g.code, g.libelle {$base} ORDER BY g.code", $scope['params']),
            'salles' => $this->fetchAll("SELECT DISTINCT sa.id, sa.code, sa.libelle FROM salles sa WHERE sa.id IN (SELECT e.id_salle {$base}) ORDER BY sa.code", $scope['params']),
            'formateurs' => $this->fetchAll("SELECT DISTINCT f.id, f.nom, f.prenom FROM formateurs f WHERE f.id IN (SELECT e.id_formateur {$base}) ORDER BY f.nom", $scope['params']),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Helpers privés — données
    // ─────────────────────────────────────────────────────────────────────────

    private function attendanceTotals(string $date, ?int $yearId, ?int $sessionId): array
    {
        $scope = $this->edtScopeWhere($yearId, $sessionId);
        $row   = $this->fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN p.statut = 'present'        THEN 1 ELSE 0 END) AS present,
                    SUM(CASE WHEN p.statut = 'absent'         THEN 1 ELSE 0 END) AS absent,
                    SUM(CASE WHEN p.statut = 'permissionnaire' THEN 1 ELSE 0 END) AS permissionnaire,
                    SUM(CASE WHEN p.statut = 'malade'         THEN 1 ELSE 0 END) AS malade,
                    SUM(CASE WHEN p.statut = 'conge'          THEN 1 ELSE 0 END) AS conge
             FROM presences p
             JOIN inscriptions i ON i.id = p.id_inscription
             JOIN emplois_du_temps e ON e.id = COALESCE(p.id_emploi_du_temps, i.id_emploi_du_temps)
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             WHERE p.date_presence = :att_date
               {$scope['sql']}",
            array_merge(['att_date' => $date], $scope['params'])
        ) ?? [];
        $total   = (int) ($row['total']   ?? 0);
        $present = (int) ($row['present'] ?? 0);
        return [
            'total'           => $total,
            'present'         => $present,
            'absent'          => (int) ($row['absent']         ?? 0),
            'permissionnaire' => (int) ($row['permissionnaire'] ?? 0),
            'malade'          => (int) ($row['malade']         ?? 0),
            'conge'           => (int) ($row['conge']          ?? 0),
            'taux_presence'   => $total > 0 ? round(($present / $total) * 100, 1) : null,
        ];
    }

    private function attendanceTrend(string $date, ?int $yearId, ?int $sessionId, int $days): array
    {
        $end   = new DateTimeImmutable($date);
        $start = $end->modify('-' . ($days - 1) . ' days');
        $scope = $this->edtScopeWhere($yearId, $sessionId);
        $rows  = $this->fetchAll(
            "SELECT p.date_presence AS date,
                    COUNT(*) AS total,
                    SUM(CASE WHEN p.statut = 'present'        THEN 1 ELSE 0 END) AS present,
                    SUM(CASE WHEN p.statut = 'absent'         THEN 1 ELSE 0 END) AS absent,
                    SUM(CASE WHEN p.statut = 'permissionnaire' THEN 1 ELSE 0 END) AS permissionnaire,
                    SUM(CASE WHEN p.statut = 'malade'         THEN 1 ELSE 0 END) AS malade,
                    SUM(CASE WHEN p.statut = 'conge'          THEN 1 ELSE 0 END) AS conge
             FROM presences p
             JOIN inscriptions i ON i.id = p.id_inscription
             JOIN emplois_du_temps e ON e.id = COALESCE(p.id_emploi_du_temps, i.id_emploi_du_temps)
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             WHERE p.date_presence BETWEEN :trend_start AND :trend_end
               {$scope['sql']}
             GROUP BY p.date_presence
             ORDER BY p.date_presence",
            array_merge(
                ['trend_start' => $start->format('Y-m-d'), 'trend_end' => $end->format('Y-m-d')],
                $scope['params']
            )
        );

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row['date']] = $row;
        }

        $result = [];
        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
            $key = $cursor->format('Y-m-d');
            $row = $byDate[$key] ?? [];
            $result[] = [
                'date'           => $key,
                'label'          => $cursor->format('d/m'),
                'total'          => (int) ($row['total']          ?? 0),
                'present'        => (int) ($row['present']        ?? 0),
                'absent'         => (int) ($row['absent']         ?? 0),
                'permissionnaire'=> (int) ($row['permissionnaire'] ?? 0),
                'malade'         => (int) ($row['malade']         ?? 0),
                'conge'          => (int) ($row['conge']          ?? 0),
            ];
        }
        return $result;
    }

    private function registrationTrend(?int $yearId, ?int $sessionId): array
    {
        // Plage temporelle : année scolaire sélectionnée, ou les 12 derniers mois
        if ($yearId !== null) {
            $year = $this->fetchOne(
                'SELECT date_debut, date_fin FROM annees_scolaires WHERE id = :id',
                ['id' => $yearId]
            );
        }
        $rangeStart = $year['date_debut'] ?? (new DateTimeImmutable('first day of -11 months'))->format('Y-m-d');
        $rangeEnd   = $year['date_fin']   ?? date('Y-m-d');

        $scope  = $this->edtScopeWhere($yearId, $sessionId);
        $params = array_merge(['reg_start' => $rangeStart, 'reg_end' => $rangeEnd], $scope['params']);

        $rows = $this->fetchAll(
            "SELECT DATE_FORMAT(i.date_inscription, '%Y-%m') AS periode,
                    COUNT(*) AS total
             FROM inscriptions i
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             WHERE i.date_inscription BETWEEN :reg_start AND :reg_end
               {$scope['sql']}
             GROUP BY periode
             ORDER BY periode",
            $params
        );

        // Labels lisibles : "Jan 2026", "Fév 2026", …
        $monthNames = [
            '01' => 'Jan', '02' => 'Fév', '03' => 'Mar', '04' => 'Avr',
            '05' => 'Mai', '06' => 'Jun', '07' => 'Jul', '08' => 'Aoû',
            '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Déc',
        ];
        return array_map(static function (array $row) use ($monthNames): array {
            [$y, $m] = explode('-', $row['periode']);
            return [
                'periode' => $row['periode'],
                'label'   => ($monthNames[$m] ?? $m) . ' ' . $y,
                'total'   => (int) $row['total'],
            ];
        }, $rows);
    }

    private function departmentDistribution(?int $yearId, ?int $sessionId): array
    {
        $scope = $this->edtScopeWhere($yearId, $sessionId);
        return $this->fetchAll(
            "SELECT d.id, d.code, d.libelle,
                    COUNT(DISTINCT i.id_auditeur) AS total,
                    COUNT(DISTINCT i.id_auditeur) AS auditeurs,
                    COUNT(i.id)                  AS inscriptions
             FROM inscriptions i
             JOIN departements d ON d.id = i.id_departement
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             WHERE i.statut = 'active'
               AND e.statut = 1
               AND g.statut = 1
               {$scope['sql']}
             GROUP BY d.id
             ORDER BY total DESC, d.libelle",
            $scope['params']
        );
    }

    private function levelDistribution(?int $yearId, ?int $sessionId): array
    {
        $scope = $this->edtScopeWhere($yearId, $sessionId);
        return $this->fetchAll(
            "SELECT n.id, n.libelle,
                    COUNT(DISTINCT i.id_auditeur) AS auditeurs,
                    COUNT(i.id)                  AS inscriptions
             FROM inscriptions i
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             JOIN niveaux n ON n.id = s.id_niveau
             WHERE i.statut = 'active'
               AND e.statut = 1
               AND g.statut = 1
               {$scope['sql']}
             GROUP BY n.id, n.libelle
             ORDER BY auditeurs DESC, inscriptions DESC, n.libelle",
            $scope['params']
        );
    }

    private function groupDistribution(?int $yearId, ?int $sessionId): array
    {
        $scope = $this->edtScopeWhere($yearId, $sessionId);
        return $this->fetchAll(
            "SELECT g.id, g.code, g.libelle,
                    s.libelle AS session, n.libelle AS niveau,
                    COUNT(DISTINCT i.id_auditeur) AS auditeurs,
                    COUNT(i.id)                  AS inscriptions
             FROM inscriptions i
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             JOIN niveaux n ON n.id = s.id_niveau
             WHERE i.statut = 'active'
               AND e.statut = 1
               AND g.statut = 1
               {$scope['sql']}
             GROUP BY g.id, g.code, g.libelle, s.libelle, n.libelle
             ORDER BY auditeurs DESC, s.libelle, g.libelle",
            $scope['params']
        );
    }

    private function passageTotals(?int $yearId, ?int $sessionId): array
    {
        $scope = $this->edtScopeWhere($yearId, $sessionId);
        return $this->fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN pn.decision = 'admis' THEN 1 ELSE 0 END) AS admis,
                    AVG(pn.moyenne) AS moyenne
             FROM passages_niveaux pn
             JOIN inscriptions i ON i.id = pn.id_inscription
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             WHERE 1 = 1 {$scope['sql']}",
            $scope['params']
        ) ?? ['total' => 0, 'admis' => 0, 'moyenne' => null];
    }

    private function getPassageBreakdown(?int $yearId, ?int $sessionId): array
    {
        $scope = $this->edtScopeWhere($yearId, $sessionId);
        return $this->fetchAll(
            "SELECT pn.decision, COUNT(*) AS total
             FROM passages_niveaux pn
             JOIN inscriptions i ON i.id = pn.id_inscription
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             WHERE 1 = 1 {$scope['sql']}
             GROUP BY pn.decision
             ORDER BY pn.decision",
            $scope['params']
        );
    }

    /**
     * Retourne les emplois du temps actifs prévus à la date donnée.
     * On filtre par e.jour_semaine (la colonne authoritative de l'EDT),
     * et on s'assure que la date tombe dans la période de l'année scolaire.
     */
    private function getPlanningForDate(string $date, ?int $yearId, ?int $sessionId): array
    {
        $weekday = (int) (new DateTimeImmutable($date))->format('N'); // 1=Lun … 7=Dim
        if ($weekday > 5) {
            return []; // Week-end : aucun cours
        }

        $scope = $this->edtScopeWhere($yearId, $sessionId);

        // On vérifie aussi que la date est dans la plage de l'année scolaire
        $anneeJoin  = '';
        $anneeWhere = '';
        $anneeParams = [];
        if ($yearId !== null) {
            $anneeJoin  = 'JOIN annees_scolaires an ON an.id = e.id_annee';
            $anneeWhere = 'AND :planning_date BETWEEN an.date_debut AND an.date_fin';
            $anneeParams = ['planning_date' => $date];
        }

        return $this->fetchAll(
            "SELECT e.id,
                    g.code AS groupe_code, g.libelle AS groupe,
                    s.libelle AS session,
                    tf.libelle AS formation,
                    n.libelle AS niveau,
                    sa.code AS salle_code, sa.libelle AS salle,
                    CONCAT(f.prenom, ' ', f.nom) AS formateur,
                    COUNT(DISTINCT i.id) AS inscrits_actifs,
                    sa.capacite AS capacite
             FROM emplois_du_temps e
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             JOIN types_formations tf ON tf.id = s.id_type_formation
             JOIN niveaux n ON n.id = s.id_niveau
             JOIN salles sa ON sa.id = e.id_salle
             JOIN formateurs f ON f.id = e.id_formateur
             {$anneeJoin}
             LEFT JOIN emplois_du_temps ei ON ei.id_groupe = e.id_groupe AND ei.id_salle = e.id_salle
             LEFT JOIN inscriptions i ON i.id_emploi_du_temps = ei.id AND i.statut = 'active'
             WHERE e.statut = 1
               AND g.statut = 1
               AND e.jour_semaine = :planning_weekday
               {$anneeWhere}
               {$scope['sql']}
             GROUP BY e.id, g.code, g.libelle, s.libelle, tf.libelle, n.libelle,
                      sa.code, sa.libelle, sa.capacite, f.prenom, f.nom
             ORDER BY s.libelle, g.libelle",
            array_merge(
                ['planning_weekday' => $weekday],
                $anneeParams,
                $scope['params']
            )
        );
    }

    private function getUpcomingEvaluations(string $date, ?int $yearId, ?int $sessionId): array
    {
        $endDate = (new DateTimeImmutable($date))->modify('+30 days')->format('Y-m-d');
        $scope   = $this->sessScopeWhere($yearId, $sessionId);
        return $this->fetchAll(
            "SELECT ev.id, ev.libelle, ev.date_evaluation,
                    s.id AS id_session, s.libelle AS session,
                    COUNT(DISTINCT ep.id_parametre) AS criteres
             FROM evaluations ev
             JOIN sessions s ON s.id = ev.id_session
             LEFT JOIN evaluation_parametres ep ON ep.id_evaluation = ev.id
             WHERE ev.date_evaluation BETWEEN :eval_start AND :eval_end
               {$scope['sql']}
             GROUP BY ev.id
             ORDER BY ev.date_evaluation
             LIMIT 8",
            array_merge(['eval_start' => $date, 'eval_end' => $endDate], $scope['params'])
        );
    }

    private function getUpcomingEvaluationCount(string $date, ?int $yearId, ?int $sessionId): int
    {
        $endDate = (new DateTimeImmutable($date))->modify('+30 days')->format('Y-m-d');
        $scope   = $this->sessScopeWhere($yearId, $sessionId);
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM evaluations ev
             JOIN sessions s ON s.id = ev.id_session
             WHERE ev.date_evaluation BETWEEN :eval_cnt_start AND :eval_cnt_end
               {$scope['sql']}",
            array_merge(['eval_cnt_start' => $date, 'eval_cnt_end' => $endDate], $scope['params'])
        );
    }

    private function doneEvaluationCount(string $date, ?int $yearId, ?int $sessionId): int
    {
        $scope = $this->sessScopeWhere($yearId, $sessionId);
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM evaluations ev
             JOIN sessions s ON s.id = ev.id_session
             WHERE ev.date_evaluation <= :done_date {$scope['sql']}",
            array_merge(['done_date' => $date], $scope['params'])
        );
    }

    private function getAlerts(string $date, ?int $yearId, ?int $sessionId): array
    {
        $scope   = $this->edtScopeWhere($yearId, $sessionId);
        $weekday = (int) (new DateTimeImmutable($date))->format('N');

        $overCapacity = (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM (
                SELECT e.id
                FROM emplois_du_temps e
                JOIN groupes g ON g.id = e.id_groupe
                JOIN sessions s ON s.id = g.id_session
                JOIN salles sa ON sa.id = e.id_salle
                JOIN inscriptions i ON i.id_emploi_du_temps = e.id AND i.statut = 'active'
                WHERE e.statut = 1 AND g.statut = 1 AND sa.capacite IS NOT NULL
                  {$scope['sql']}
                GROUP BY e.id, sa.capacite
                HAVING COUNT(i.id) > sa.capacite
             ) oc",
            $scope['params']
        );

        // Groupes du jour sans pointage complet (semaine seulement)
        $missingAttendance = 0;
        if ($weekday >= 1 && $weekday <= 5) {
            $anneeJoin   = '';
            $anneeWhere  = '';
            $anneeParams = [];
            if ($yearId !== null) {
                $anneeJoin  = 'JOIN annees_scolaires an ON an.id = e.id_annee';
                $anneeWhere = 'AND :alert_date BETWEEN an.date_debut AND an.date_fin';
                $anneeParams = ['alert_date' => $date];
            }
            $missingAttendance = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM (
                    SELECT e.id
                    FROM emplois_du_temps e
                    JOIN groupes g ON g.id = e.id_groupe
                    JOIN sessions s ON s.id = g.id_session
                    {$anneeJoin}
                    JOIN emplois_du_temps ei ON ei.id_groupe = e.id_groupe AND ei.id_salle = e.id_salle
                    JOIN inscriptions i ON i.id_emploi_du_temps = ei.id AND i.statut = 'active'
                    LEFT JOIN presences p ON p.id_inscription = i.id AND p.date_presence = :missing_date
                    WHERE e.statut = 1
                      AND g.statut = 1
                      AND e.jour_semaine = :missing_weekday
                      {$anneeWhere}
                      {$scope['sql']}
                    GROUP BY e.id
                    HAVING COUNT(DISTINCT p.id) < COUNT(DISTINCT i.id)
                 ) miss",
                array_merge(
                    ['missing_date' => $date, 'missing_weekday' => $weekday],
                    $anneeParams,
                    $scope['params']
                )
            );
        }

        $alerts = [];
        if ($overCapacity > 0) {
            $alerts[] = [
                'type'     => 'capacite',
                'severite' => 'attention',
                'total'    => $overCapacity,
                'message'  => "{$overCapacity} salle(s) dépassent leur capacité du jour.",
            ];
        }
        if ($missingAttendance > 0) {
            $alerts[] = [
                'type'     => 'presences',
                'severite' => 'information',
                'total'    => $missingAttendance,
                'message'  => "{$missingAttendance} groupe(s) du jour n'ont pas encore de pointage complet.",
            ];
        }
        return $alerts;
    }

    /**
     * Effectifs actifs (auditeurs distincts) par salle, session ou type d'employé.
     * @param string $dimension 'salle' | 'session' | 'type_employe'
     */
    private function enrolmentBy(string $dimension, ?int $yearId, ?int $sessionId): array
    {
        $dimensions = [
            'salle' => ['sa.id AS id, sa.code, sa.libelle, sa.capacite', 'sa.id, sa.code, sa.libelle, sa.capacite', 'JOIN salles sa ON sa.id = e.id_salle'],
            'session' => ['s.id AS id, s.code, s.libelle, n.libelle AS niveau, s.statut', 's.id, s.code, s.libelle, n.libelle, s.statut', 'JOIN niveaux n ON n.id = s.id_niveau'],
            'type_employe' => ["COALESCE(te.id, 0) AS id, COALESCE(te.libelle, 'Non renseigné') AS libelle", 'COALESCE(te.id, 0), COALESCE(te.libelle, \'Non renseigné\')', 'JOIN auditeurs a ON a.id = i.id_auditeur LEFT JOIN types_employes te ON te.id = a.id_type_employe'],
        ];
        [$select, $groupBy, $join] = $dimensions[$dimension];
        $scope = $this->edtScopeWhere($yearId, $sessionId);
        return $this->fetchAll(
            "SELECT {$select}, COUNT(DISTINCT i.id_auditeur) AS auditeurs, COUNT(i.id) AS inscriptions
             FROM inscriptions i
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             {$join}
             WHERE i.statut = 'active' AND e.statut = 1 AND g.statut = 1 {$scope['sql']}
             GROUP BY {$groupBy}
             ORDER BY auditeurs DESC",
            $scope['params']
        );
    }

    /**
     * Statistiques de présence (taux de présence / d'absence) par salle, groupe,
     * département, type d'employé ou auditeur, sur une période facultative.
     */
    private function presenceBy(string $dimension, ?string $start, ?string $end, ?int $yearId, ?int $sessionId, array $extra = []): array
    {
        $dimensions = [
            'salle' => ['sa.id AS id, sa.code, sa.libelle', 'sa.id, sa.code, sa.libelle', 'JOIN salles sa ON sa.id = e.id_salle'],
            'groupe' => ['g.id AS id, g.code, g.libelle', 'g.id, g.code, g.libelle', ''],
            'departement' => ['d.id AS id, d.code, d.libelle', 'd.id, d.code, d.libelle', 'JOIN departements d ON d.id = i.id_departement'],
            'type_employe' => ["COALESCE(te.id, 0) AS id, COALESCE(te.libelle, 'Non renseigné') AS libelle", "COALESCE(te.id, 0), COALESCE(te.libelle, 'Non renseigné')", 'JOIN auditeurs a ON a.id = i.id_auditeur LEFT JOIN types_employes te ON te.id = a.id_type_employe'],
            'auditeur' => ['a.id AS id, a.matricule AS code, CONCAT(a.nom, \' \', a.prenom) AS libelle, g.code AS groupe_code, sa.code AS salle_code', 'a.id, a.matricule, a.nom, a.prenom, g.code, sa.code', 'JOIN auditeurs a ON a.id = i.id_auditeur JOIN salles sa ON sa.id = e.id_salle'],
        ];
        [$select, $groupBy, $join] = $dimensions[$dimension];
        $scope = $this->edtScopeWhere($yearId, $sessionId, $extra);
        $params = $scope['params'];
        $period = '';
        if ($start !== null && $end !== null) {
            $period = 'AND p.date_presence BETWEEN :pb_start AND :pb_end';
            $params += ['pb_start' => $start, 'pb_end' => $end];
        }
        $rows = $this->fetchAll(
            "SELECT {$select}, COUNT(*) AS total,
                    SUM(p.statut = 'present') AS present, SUM(p.statut = 'absent') AS absent,
                    SUM(p.statut = 'permissionnaire') AS permissionnaire, SUM(p.statut = 'malade') AS malade,
                    SUM(p.statut = 'conge') AS conge
             FROM presences p
             JOIN inscriptions i ON i.id = p.id_inscription
             JOIN emplois_du_temps e ON e.id = COALESCE(p.id_emploi_du_temps, i.id_emploi_du_temps)
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             {$join}
             WHERE 1 = 1 {$period} {$scope['sql']}
             GROUP BY {$groupBy}
             ORDER BY total DESC",
            $params
        );
        return array_map(static function (array $row): array {
            $total = (int) $row['total'];
            foreach (['total', 'present', 'absent', 'permissionnaire', 'malade', 'conge'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            $row['taux_presence'] = $total ? round($row['present'] / $total * 100, 1) : null;
            $row['taux_absence'] = $total ? round($row['absent'] / $total * 100, 1) : null;
            return $row;
        }, $rows);
    }

    /** Auditeurs attendus à la date (même règle que la feuille de pointage) et pointés. */
    private function expectedAttendance(string $date, ?int $yearId, ?int $sessionId): array
    {
        require_once __DIR__ . '/Presence.php';
        $sheet = (new Presence())->getFeuille($date, $sessionId ? ['id_session' => $sessionId] : []);
        $rows = array_filter($sheet['auditeurs'], static fn($r) => $yearId === null || (int) $r['id_annee'] === $yearId);
        return [
            'attendus' => count($rows),
            'pointes' => count(array_filter($rows, static fn($r) => $r['statut'] !== null)),
        ];
    }

    /** Synthèse des résultats (admis, non admis, en attente, moyennes) — mêmes calculs que le module Évaluations. */
    private function resultsSummary(?int $yearId, ?int $sessionId, array $sessions): array
    {
        require_once __DIR__ . '/Resultat.php';
        $resultModel = new Resultat();
        $ids = $sessionId !== null ? [$sessionId] : array_map(static fn($s) => (int) $s['id'], $sessions);
        $summary = ['admis' => 0, 'non_admis' => 0, 'en_attente' => 0, 'notes_manquantes' => 0,
            'notes_manquantes_echues' => 0, 'moyenne' => null, 'par_session' => []];
        $averages = [];
        $today = date('Y-m-d');
        foreach ($ids as $id) {
            $result = $resultModel->getSessionResults($id);
            $synthese = $result['synthese'];
            if ($synthese['effectif'] === 0) {
                continue;
            }
            foreach (['admis', 'non_admis', 'en_attente', 'notes_manquantes'] as $key) {
                $summary[$key] += $synthese[$key];
            }
            foreach ($result['evaluations'] as $index => $evaluation) {
                if ($evaluation['date_evaluation'] < $today) {
                    $stats = $synthese['par_evaluation'][$index];
                    $summary['notes_manquantes_echues'] += $stats['notes_attendues'] - $stats['notes_saisies'];
                }
            }
            foreach ($result['auditeurs'] as $row) {
                if ($row['moyenne_generale'] !== null) {
                    $averages[] = $row['moyenne_generale'];
                }
            }
            $summary['par_session'][] = [
                'id' => $id,
                'libelle' => $result['session']['libelle'],
                'effectif' => $synthese['effectif'],
                'moyenne' => $synthese['moyenne'],
                'admis' => $synthese['admis'],
                'non_admis' => $synthese['non_admis'],
                'en_attente' => $synthese['en_attente'],
            ];
        }
        $summary['moyenne'] = $averages ? round(array_sum($averages) / count($averages), 2) : null;
        return $summary;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Helpers privés — utilitaires
    // ─────────────────────────────────────────────────────────────────────────

    private function getSessions(?int $yearId): array
    {
        $sql    = 'SELECT id, id_annee, code, libelle, date_debut, date_fin, statut FROM sessions';
        $params = [];
        if ($yearId !== null) {
            $sql .= ' WHERE id_annee = :sess_year';
            $params['sess_year'] = $yearId;
        }
        $sql .= ' ORDER BY date_debut DESC, libelle';
        return $this->fetchAll($sql, $params);
    }

    /**
     * Scope via emplois_du_temps.id_annee (e) ET sessions (s).
     * À utiliser pour toutes les requêtes qui JOINent emplois_du_temps.
     */
    private function edtScopeWhere(?int $yearId, ?int $sessionId, array $extra = []): array
    {
        $parts  = [];
        $params = [];
        foreach (['id_groupe' => 'e.id_groupe', 'id_salle' => 'e.id_salle', 'id_formateur' => 'e.id_formateur'] as $key => $column) {
            if (!empty($extra[$key])) {
                $parts[] = "{$column} = :edt_scope_{$key}";
                $params["edt_scope_{$key}"] = (int) $extra[$key];
            }
        }
        if ($yearId !== null) {
            $parts[]            = 'e.id_annee = :edt_scope_year';
            $params['edt_scope_year'] = $yearId;
        }
        if ($sessionId !== null) {
            $parts[]              = 's.id = :edt_scope_session';
            $params['edt_scope_session'] = $sessionId;
        }
        return [
            'sql'    => $parts === [] ? '' : ' AND ' . implode(' AND ', $parts),
            'params' => $params,
        ];
    }

    /**
     * Scope via sessions uniquement (s.id_annee).
     * À utiliser pour les requêtes qui n'ont pas emplois_du_temps (évaluations, capacité…).
     */
    private function sessScopeWhere(?int $yearId, ?int $sessionId): array
    {
        $parts  = [];
        $params = [];
        if ($yearId !== null) {
            $parts[]             = 's.id_annee = :sess_scope_year';
            $params['sess_scope_year'] = $yearId;
        }
        if ($sessionId !== null) {
            $parts[]               = 's.id = :sess_scope_session';
            $params['sess_scope_session'] = $sessionId;
        }
        return [
            'sql'    => $parts === [] ? '' : ' AND ' . implode(' AND ', $parts),
            'params' => $params,
        ];
    }

    private function sessionBelongsToYear(int $sessionId, int $yearId): bool
    {
        return $this->fetchOne(
            'SELECT 1 AS found FROM sessions WHERE id = :sid AND id_annee = :yid',
            ['sid' => $sessionId, 'yid' => $yearId]
        ) !== null;
    }

    private function existsInTable(string $table, int $id): bool
    {
        if (!in_array($table, ['annees_scolaires', 'sessions'], true)) {
            throw new LogicException('Table de validation non autorisée.');
        }
        return $this->fetchOne(
            "SELECT 1 AS found FROM {$table} WHERE id = :id",
            ['id' => $id]
        ) !== null;
    }

    private function assertDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('La date doit respecter le format AAAA-MM-JJ.');
        }
    }

    private function resolveReportPeriod(
        string $type, ?string $date, ?string $month, ?int $yearId, ?int $sessionId
    ): array {
        if ($type === 'annuel') {
            if ($yearId === null) {
                throw new InvalidArgumentException('Choisissez une année scolaire pour le rapport annuel.');
            }
            $year = $this->fetchOne(
                'SELECT libelle, date_debut, date_fin FROM annees_scolaires WHERE id = :id',
                ['id' => $yearId]
            );
            if ($year === null) {
                throw new InvalidArgumentException('Année scolaire introuvable.');
            }
            return [$year['date_debut'], $year['date_fin'], 'Année scolaire ' . $year['libelle']];
        }

        if ($type === 'session') {
            if ($sessionId === null) {
                throw new InvalidArgumentException('Choisissez une session pour générer son rapport.');
            }
            $session = $this->fetchOne(
                'SELECT libelle, date_debut, date_fin, id_annee FROM sessions WHERE id = :id',
                ['id' => $sessionId]
            );
            if ($session === null) {
                throw new InvalidArgumentException('Session introuvable.');
            }
            if ($yearId !== null && (int) $session['id_annee'] !== $yearId) {
                throw new InvalidArgumentException("La session ne correspond pas à l'année scolaire.");
            }
            return [$session['date_debut'], $session['date_fin'], 'Session ' . $session['libelle']];
        }

        if ($type === 'mensuel') {
            if ($month === null || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                throw new InvalidArgumentException('Choisissez un mois valide au format AAAA-MM.');
            }
            $first = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
            if ($first === false) {
                throw new InvalidArgumentException('Mois invalide.');
            }
            $monthNames = [
                'janvier','février','mars','avril','mai','juin',
                'juillet','août','septembre','octobre','novembre','décembre',
            ];
            return [
                $first->format('Y-m-01'),
                $first->modify('last day of this month')->format('Y-m-d'),
                $monthNames[(int) $first->format('n') - 1] . ' ' . $first->format('Y'),
            ];
        }

        $date = $date ?? date('Y-m-d');
        $this->assertDate($date);
        $day = new DateTimeImmutable($date);
        if ($type === 'hebdomadaire') {
            $start = $day->modify('monday this week');
            $end   = $start->modify('+6 days');
            return [
                $start->format('Y-m-d'),
                $end->format('Y-m-d'),
                'Semaine du ' . $start->format('d/m/Y') . ' au ' . $end->format('d/m/Y'),
            ];
        }
        // journalier
        return [$date, $date, $day->format('d/m/Y')];
    }

    private function completeReportSeries(array $rows, string $type, string $start, string $end): array
    {
        $byPeriod = [];
        foreach ($rows as $row) {
            $byPeriod[$row['periode']] = $row;
        }

        $startDate = new DateTimeImmutable($start);
        $endDate   = new DateTimeImmutable($end);
        $result    = [];

        if (in_array($type, ['annuel', 'session'], true)) {
            $cursor    = $startDate->modify('first day of this month');
            $lastMonth = $endDate->modify('first day of this month');
            while ($cursor <= $lastMonth) {
                $key      = $cursor->format('Y-m');
                $result[] = $this->seriesPoint($key, $byPeriod[$key] ?? []);
                $cursor   = $cursor->modify('+1 month');
            }
            return $result;
        }

        for ($cursor = $startDate; $cursor <= $endDate; $cursor = $cursor->modify('+1 day')) {
            $key      = $cursor->format('Y-m-d');
            $result[] = $this->seriesPoint($key, $byPeriod[$key] ?? []);
        }
        return $result;
    }

    private function seriesPoint(string $key, array $row): array
    {
        // Générer un label lisible : "Jan 2026" pour YYYY-MM, "15/06" pour YYYY-MM-DD
        if (strlen($key) === 7) {
            [$y, $m] = explode('-', $key);
            $monthNames = [
                '01' => 'Jan', '02' => 'Fév', '03' => 'Mar', '04' => 'Avr',
                '05' => 'Mai', '06' => 'Jun', '07' => 'Jul', '08' => 'Aoû',
                '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Déc',
            ];
            $label = ($monthNames[$m] ?? $m) . ' ' . $y;
        } else {
            $label = (new DateTimeImmutable($key))->format('d/m');
        }

        return [
            'periode'        => $key,
            'label'          => $label,
            'total'          => (int) ($row['total']          ?? 0),
            'present'        => (int) ($row['present']        ?? 0),
            'absent'         => (int) ($row['absent']         ?? 0),
            'permissionnaire'=> (int) ($row['permissionnaire'] ?? 0),
            'malade'         => (int) ($row['malade']         ?? 0),
            'conge'          => (int) ($row['conge']          ?? 0),
        ];
    }
}
