<?php
/**
 * Planification automatique des sessions (groupes × passages × salles dédiées).
 *
 * Responsabilités :
 *   - construire le contexte d'une session : configuration figée de la session, salles utilisables
 *     par sa formation, réservations des autres sessions dont les dates chevauchent, groupes déjà en place ;
 *   - simuler (PlanningSolver) puis appliquer une solution dans une transaction verrouillée ;
 *   - reprendre la configuration d'une session précédente pour une nouvelle session ;
 *   - détecter les conflits, proposer des créneaux alternatifs, produire la synthèse du calendrier.
 *
 * Un « créneau » (emplois_du_temps) = un groupe, une salle (et son formateur titulaire), un jour et un horaire.
 */

require_once __DIR__ . '/Model.php';
require_once __DIR__ . '/FormationConfig.php';
require_once __DIR__ . '/PlanningSolver.php';
require_once __DIR__ . '/PlanningException.php';

class Planification extends Model
{
    protected string $table = 'emplois_du_temps';

    /** Sessions dont les créneaux occupent réellement les salles. */
    public const SESSIONS_ACTIVES = ['planifiee', 'ouverte', 'en_cours'];
    public const MODES = ['completer', 'regenerer'];
    private const LOCK = 'ages_planification';
    private const DAYS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi'];

    private FormationConfig $formations;

    public function __construct()
    {
        parent::__construct();
        $this->formations = new FormationConfig();
    }

    /* ==================================================================
     | Contexte
     |================================================================== */

    public function getSession(int $sessionId): array
    {
        $session = $this->fetchOne(
            "SELECT s.*, tf.libelle AS formation_libelle, tf.couleur AS formation_couleur
             FROM sessions s JOIN types_formations tf ON tf.id = s.id_type_formation
             WHERE s.id = :id",
            ['id' => $sessionId]
        );
        if ($session === null) {
            throw new DomainException('Session introuvable.', 404);
        }
        return $session;
    }

    /** Configuration applicable à la session : son instantané, sinon la configuration actuelle de la formation. */
    public function sessionConfig(array $session): array
    {
        $snapshot = is_string($session['config_formation'] ?? null) ? json_decode($session['config_formation'], true) : null;
        if (is_array($snapshot) && isset($snapshot['passages_semaine'])) {
            return $snapshot + ['version' => (int) $session['config_version']];
        }
        $formation = $this->formations->find((int) $session['id_type_formation']);
        return $this->formations->snapshot($formation) + ['version' => (int) $formation['config_version']];
    }

    /** Fige dans la session la configuration actuelle de sa formation (création ou adoption explicite). */
    public function snapshotSession(int $sessionId): array
    {
        $session = $this->getSession($sessionId);
        $formation = $this->formations->find((int) $session['id_type_formation']);
        $config = $this->formations->snapshot($formation);
        $this->db->prepare(
            'UPDATE sessions SET config_formation = :config, config_version = :version WHERE id = :id'
        )->execute([
            'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
            'version' => (int) $formation['config_version'],
            'id' => $sessionId,
        ]);
        return $config + ['version' => (int) $formation['config_version']];
    }

    /**
     * Salles utilisables par une formation : celles qui lui sont dédiées ou partagées avec elle ;
     * à défaut de rattachement, les salles qui ne sont dédiées à aucune formation.
     * Une salle indisponible ou sans formateur titulaire actif est écartée (avec la raison).
     */
    public function usableRooms(int $formationId): array
    {
        $hasRooms = (int) $this->fetchColumn('SELECT COUNT(*) FROM formation_salles WHERE id_type_formation = :f', ['f' => $formationId]) > 0;
        $rows = $this->fetchAll(
            "SELECT sa.id, sa.code, sa.libelle, sa.capacite, sa.statut,
                    fs.mode, COALESCE(fs.priorite, 999) AS priorite,
                    f.id AS id_formateur, f.actif AS formateur_actif, CONCAT(f.prenom, ' ', f.nom) AS formateur
             FROM salles sa
             LEFT JOIN formation_salles fs ON fs.id_salle = sa.id AND fs.id_type_formation = :f1
             LEFT JOIN formation_salles own ON own.id_salle = sa.id AND own.mode = 'dediee'
             LEFT JOIN v_formateurs f ON f.id_salle = sa.id
             WHERE " . ($hasRooms ? 'fs.id_salle IS NOT NULL' : 'own.id_salle IS NULL') . "
             ORDER BY COALESCE(fs.priorite, 999), sa.code",
            ['f1' => $formationId]
        );

        $usable = [];
        $excluded = [];
        foreach ($rows as $row) {
            $reason = null;
            if ($row['statut'] !== 'disponible') {
                $reason = 'salle indisponible';
            } elseif ($row['id_formateur'] === null) {
                $reason = 'aucun formateur titulaire';
            } elseif (!(bool) $row['formateur_actif']) {
                $reason = 'formateur titulaire inactif';
            }
            if ($reason === null) {
                $usable[] = $row;
            } else {
                $excluded[] = ['id' => (int) $row['id'], 'code' => $row['code'], 'raison' => $reason];
            }
        }
        usort($usable, static fn($a, $b) => (int) $a['priorite'] <=> (int) $b['priorite'] ?: strnatcasecmp($a['code'], $b['code']));
        return ['salles' => $usable, 'exclues' => $excluded, 'salles_configurees' => $hasRooms];
    }

    /** Créneaux actifs des AUTRES sessions dont les dates chevauchent celles de la session. */
    public function externalBookings(array $session): array
    {
        return $this->fetchAll(
            "SELECT e.id, e.id_salle, e.id_formateur, e.jour_semaine, e.heure_debut, e.heure_fin,
                    g.code AS groupe_code, s.code AS session_code
             FROM emplois_du_temps e
             JOIN sessions s ON s.id = e.id_session
             JOIN groupes g ON g.id = e.id_groupe
             WHERE e.statut = 1 AND g.statut = 1 AND s.id <> :id
               AND s.statut IN ('planifiee', 'ouverte', 'en_cours')
               AND s.date_debut <= :fin AND s.date_fin >= :debut",
            ['id' => (int) $session['id'], 'debut' => $session['date_debut'], 'fin' => $session['date_fin']]
        );
    }

    /** Groupes actifs de la session avec leurs passages, créneaux et verrouillage (historique présent). */
    public function sessionGroups(int $sessionId): array
    {
        $groups = $this->fetchAll(
            "SELECT g.* FROM groupes g WHERE g.id_session = :id AND g.statut = 1 ORDER BY g.code",
            ['id' => $sessionId]
        );
        $rows = $this->fetchAll(
            "SELECT e.*,
                    (SELECT COUNT(*) FROM inscriptions i WHERE i.id_emploi_du_temps = e.id) AS nb_inscriptions,
                    (SELECT COUNT(*) FROM presences p WHERE p.id_emploi_du_temps = e.id) AS nb_presences
             FROM emplois_du_temps e
             JOIN groupes g ON g.id = e.id_groupe
             WHERE g.id_session = :id AND e.statut = 1
             ORDER BY e.jour_semaine, e.id_salle",
            ['id' => $sessionId]
        );
        $passages = $this->fetchAll(
            "SELECT gp.* FROM groupe_passages gp JOIN groupes g ON g.id = gp.id_groupe
             WHERE g.id_session = :id ORDER BY gp.jour_semaine",
            ['id' => $sessionId]
        );

        foreach ($groups as &$group) {
            $id = (int) $group['id'];
            $group['creneaux'] = array_values(array_filter($rows, static fn($r) => (int) $r['id_groupe'] === $id));
            $group['passages'] = array_values(array_filter($passages, static fn($p) => (int) $p['id_groupe'] === $id));
            $group['jours'] = array_values(array_unique(array_map(static fn($r) => (int) $r['jour_semaine'], $group['creneaux'])));
            sort($group['jours']);
            $firstDay = $group['jours'][0] ?? null;
            $group['salles'] = array_values(array_unique(array_map(
                static fn($r) => (int) $r['id_salle'],
                array_filter($group['creneaux'], static fn($r) => (int) $r['jour_semaine'] === $firstDay)
            )));
            $group['verrouille'] = array_sum(array_map(static fn($r) => (int) $r['nb_inscriptions'] + (int) $r['nb_presences'], $group['creneaux'])) > 0;
        }
        unset($group);
        return $groups;
    }

    /* ==================================================================
     | Simulation et application
     |================================================================== */

    /**
     * Prépare l'entrée du moteur.
     * Options : mode (completer|regenerer), creer_groupes (bool), id_session_source (préférences).
     */
    public function buildContext(int $sessionId, array $options = []): array
    {
        $session = $this->getSession($sessionId);
        if (!in_array($session['statut'], self::SESSIONS_ACTIVES, true)) {
            throw new DomainException('Seule une session planifiée, ouverte ou en cours peut être planifiée.', 409);
        }
        $mode = $options['mode'] ?? 'completer';
        if (!in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('Mode invalide : « completer » (garder l’existant) ou « regenerer » (recalculer les groupes sans historique).');
        }
        $config = $this->sessionConfig($session);
        $rooms = $this->usableRooms((int) $session['id_type_formation']);
        $groups = $this->sessionGroups($sessionId);
        $preferences = $this->preferencesFrom((int) ($options['id_session_source'] ?? $session['id_session_source'] ?? 0));

        $solverGroups = [];
        foreach ($groups as $group) {
            $fixed = $group['creneaux'] !== [] && ($group['verrouille'] || $mode === 'completer');
            $first = $group['creneaux'][0] ?? null;
            $solverGroups[] = [
                'cle' => 'g' . $group['id'],
                'id' => (int) $group['id'],
                'code' => $group['code'],
                'fixe' => $fixed,
                'jours' => $fixed ? $group['jours'] : [],
                'salles' => $fixed ? $group['salles'] : [],
                'creneau' => $fixed && $first ? ['debut' => $first['heure_debut'], 'fin' => $first['heure_fin']] : null,
                // Sans historique, le groupe garde de préférence ses jours et salles actuels (moins de changements).
                'preferences' => $preferences[$group['code']] ?? ['jours' => $group['jours'], 'salles' => $group['salles']],
            ];
        }

        $newGroups = [];
        if (($options['creer_groupes'] ?? true) && count($groups) < $config['nb_groupes']) {
            $codes = array_map(static fn($g) => mb_strtoupper($g['code']), $this->fetchAll('SELECT code FROM groupes WHERE id_session = :id', ['id' => $sessionId]));
            $n = 1;
            while (count($groups) + count($newGroups) < $config['nb_groupes']) {
                $code = 'G' . $n++;
                if (in_array($code, $codes, true)) {
                    continue;
                }
                $newGroups[] = ['cle' => 'new:' . $code, 'code' => $code, 'libelle' => 'Groupe ' . substr($code, 1)];
                $solverGroups[] = ['cle' => 'new:' . $code, 'code' => $code, 'preferences' => $preferences[$code] ?? []];
            }
        }

        $occupations = array_map(static fn($b) => [
            'id_salle' => (int) $b['id_salle'], 'id_formateur' => (int) $b['id_formateur'], 'jour' => (int) $b['jour_semaine'],
            'debut' => $b['heure_debut'], 'fin' => $b['heure_fin'], 'libelle' => $b['session_code'] . ' · ' . $b['groupe_code'],
        ], $this->externalBookings($session));

        $input = [
            'jours' => FormationConfig::daysOf($config),
            'creneaux' => FormationConfig::slotsOf($config),
            'passages' => (int) $config['passages_semaine'],
            'salles_par_groupe' => (int) $config['salles_par_groupe'],
            'espacer' => (bool) $config['espacer_passages'],
            'salles' => array_map(static fn($r) => ['id' => (int) $r['id'], 'code' => $r['code'], 'priorite' => (int) $r['priorite'], 'formateur' => (int) $r['id_formateur']], $rooms['salles']),
            'occupations' => $occupations,
            'groupes' => $solverGroups,
            'max_solutions' => (int) ($options['max_solutions'] ?? 25),
        ];

        return compact('session', 'config', 'rooms', 'groups', 'newGroups', 'input', 'mode', 'occupations');
    }

    public function simulate(int $sessionId, array $options = []): array
    {
        $context = $this->buildContext($sessionId, $options);
        $result = (new PlanningSolver($context['input']))->solve();
        return $this->present($context, $result);
    }

    /**
     * Applique une solution (index `solution` de la simulation, recalculée sur les données à jour),
     * dans une transaction protégée par un verrou applicatif.
     * Options : mode, solution, accepter_partiel, creer_groupes.
     */
    public function apply(int $sessionId, array $options, ?int $userId, string $origin = 'automatique'): array
    {
        $this->acquireLock();
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $context = $this->buildContext($sessionId, $options);
            $result = (new PlanningSolver($context['input']))->solve();
            $presented = $this->present($context, $result);

            $index = max(0, (int) ($options['solution'] ?? 0));
            if ($result['complete']) {
                $plan = $result['solutions'][$index]['affectations'] ?? $result['solutions'][0]['affectations'];
            } elseif (!empty($options['accepter_partiel']) && !empty($result['partielle'])) {
                $plan = $result['partielle'];
            } else {
                throw new PlanningException('Aucune affectation complète sans conflit n’est possible.', $presented);
            }

            $generationId = $this->logGeneration($sessionId, $context['mode'], $options, $userId);
            [$created, $removed, $groupsCreated] = $this->write($context, $plan, $generationId, $origin);
            $this->db->prepare(
                "UPDATE planification_generations SET statut = 'appliquee', resultat = :resultat, nb_crees = :c, nb_supprimes = :d WHERE id = :id"
            )->execute([
                'resultat' => json_encode(['complete' => $result['complete'], 'affectations' => $plan, 'diagnostic' => $result['diagnostic']], JSON_UNESCAPED_UNICODE),
                'c' => $created, 'd' => $removed, 'id' => $generationId,
            ]);

            if ($ownTransaction) {
                $this->db->commit();
            }
            return [
                'id_generation' => $generationId,
                'complete' => $result['complete'],
                'creneaux_crees' => $created,
                'creneaux_supprimes' => $removed,
                'groupes_crees' => $groupsCreated,
                'non_places' => $presented['non_places'],
                'diagnostic' => $presented['diagnostic'],
            ];
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Reprend la configuration d'une session précédente de la même formation :
     * groupes (codes, libellés, capacités, horaires, jours), puis planification adaptée aux
     * disponibilités de la nouvelle période (mêmes jours et salles quand c'est possible).
     */
    public function reprendre(int $sessionId, ?int $sourceId, bool $generate, ?int $userId): array
    {
        $session = $this->getSession($sessionId);
        $source = $sourceId ? $this->getSession($sourceId) : $this->previousSession($session);
        if ($source !== null && (int) $source['id_type_formation'] !== (int) $session['id_type_formation']) {
            throw new DomainException('La session source doit appartenir à la même formation.', 409);
        }
        if ($source !== null && (int) $source['id'] === $sessionId) {
            throw new DomainException('Une session ne peut pas reprendre sa propre configuration.', 409);
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $copied = [];
            if ($source !== null) {
                $this->db->prepare('UPDATE sessions SET id_session_source = :src WHERE id = :id')->execute(['src' => (int) $source['id'], 'id' => $sessionId]);
                $existing = array_map(static fn($r) => mb_strtoupper($r['code']), $this->fetchAll('SELECT code FROM groupes WHERE id_session = :id', ['id' => $sessionId]));
                $config = $this->sessionConfig($session);
                foreach ($this->fetchAll('SELECT * FROM groupes WHERE id_session = :id AND statut = 1 ORDER BY code', ['id' => (int) $source['id']]) as $group) {
                    if (in_array(mb_strtoupper($group['code']), $existing, true) || count($existing) >= $config['nb_groupes']) {
                        continue;
                    }
                    $this->db->prepare(
                        'INSERT INTO groupes (id_session, code, libelle, jour_semaine, heure_debut, heure_fin, capacite, statut)
                         VALUES (:s, :code, :libelle, :jour, :debut, :fin, :capacite, 1)'
                    )->execute([
                        's' => $sessionId, 'code' => $group['code'], 'libelle' => $group['libelle'], 'jour' => $group['jour_semaine'],
                        'debut' => $group['heure_debut'], 'fin' => $group['heure_fin'], 'capacite' => $group['capacite'],
                    ]);
                    $newId = (int) $this->db->lastInsertId();
                    $this->db->prepare(
                        'INSERT INTO groupe_passages (id_groupe, jour_semaine, heure_debut, heure_fin, ordre)
                         SELECT :new, jour_semaine, heure_debut, heure_fin, ordre FROM groupe_passages WHERE id_groupe = :old'
                    )->execute(['new' => $newId, 'old' => (int) $group['id']]);
                    $existing[] = mb_strtoupper($group['code']);
                    $copied[] = ['id' => $newId, 'code' => $group['code'], 'libelle' => $group['libelle']];
                }
            }

            $planning = null;
            if ($generate && in_array($session['statut'], self::SESSIONS_ACTIVES, true)) {
                try {
                    $planning = $this->apply($sessionId, [
                        'mode' => 'regenerer', 'accepter_partiel' => true, 'creer_groupes' => true,
                        'id_session_source' => $source['id'] ?? null,
                    ], $userId, 'reprise');
                } catch (PlanningException $e) {
                    $planning = ['complete' => false, 'diagnostic' => $e->details['diagnostic'] ?? null, 'non_places' => $e->details['non_places'] ?? []];
                }
            }

            if ($ownTransaction) {
                $this->db->commit();
            }
            return [
                'session_source' => $source === null ? null : ['id' => (int) $source['id'], 'code' => $source['code'], 'libelle' => $source['libelle']],
                'groupes_repris' => $copied,
                'planification' => $planning,
            ];
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Dernière session de la même formation commencée avant celle-ci. */
    public function previousSession(array $session): ?array
    {
        return $this->fetchOne(
            "SELECT * FROM sessions
             WHERE id_type_formation = :tf AND id <> :id AND date_debut <= :debut
             ORDER BY date_debut DESC, id DESC LIMIT 1",
            ['tf' => (int) $session['id_type_formation'], 'id' => (int) $session['id'], 'debut' => $session['date_debut']]
        );
    }

    /* ==================================================================
     | Modifications manuelles contrôlées
     |================================================================== */

    /**
     * Conflits d'un créneau envisagé (création ou modification manuelle).
     * $slot : id_session, id_groupe, id_salle, id_formateur, jour_semaine, heure_debut, heure_fin.
     * @return array<int,array{type:string,message:string,id:?int}>
     */
    public function slotConflicts(array $slot, ?int $excludeId = null): array
    {
        $session = $this->getSession((int) $slot['id_session']);
        $params = [
            'jour' => (int) $slot['jour_semaine'], 'debut' => $slot['heure_debut'], 'fin' => $slot['heure_fin'],
            'sdebut' => $session['date_debut'], 'sfin' => $session['date_fin'],
            'salle' => (int) $slot['id_salle'], 'formateur' => (int) $slot['id_formateur'],
            'exclude' => (int) $excludeId, 'groupe' => (int) $slot['id_groupe'],
        ];
        $rows = $this->fetchAll(
            "SELECT e.id, e.id_salle, e.id_formateur, e.id_groupe, e.heure_debut, e.heure_fin,
                    g.code AS groupe_code, s.code AS session_code, sa.code AS salle_code,
                    CONCAT(f.prenom, ' ', f.nom) AS formateur
             FROM emplois_du_temps e
             JOIN sessions s ON s.id = e.id_session
             JOIN groupes g ON g.id = e.id_groupe
             JOIN salles sa ON sa.id = e.id_salle
             JOIN v_formateurs f ON f.id = e.id_formateur
             WHERE e.statut = 1 AND g.statut = 1 AND e.id <> :exclude
               AND s.statut IN ('planifiee', 'ouverte', 'en_cours')
               AND e.jour_semaine = :jour AND e.heure_debut < :fin AND :debut < e.heure_fin
               AND s.date_debut <= :sfin AND s.date_fin >= :sdebut
               AND (e.id_salle = :salle OR e.id_formateur = :formateur OR e.id_groupe = :groupe)",
            $params
        );

        $day = self::DAYS[(int) $slot['jour_semaine']] ?? '?';
        $conflicts = [];
        foreach ($rows as $row) {
            $where = sprintf('%s · %s, %s %s–%s', $row['session_code'], $row['groupe_code'], $day, substr($row['heure_debut'], 0, 5), substr($row['heure_fin'], 0, 5));
            if ((int) $row['id_salle'] === (int) $slot['id_salle']) {
                $conflicts[] = ['type' => 'salle', 'id' => (int) $row['id'], 'message' => "La salle {$row['salle_code']} est déjà occupée ({$where})."];
            } elseif ((int) $row['id_formateur'] === (int) $slot['id_formateur']) {
                $conflicts[] = ['type' => 'formateur', 'id' => (int) $row['id'], 'message' => "Le formateur {$row['formateur']} est déjà affecté ({$where})."];
            } elseif ((int) $row['id_groupe'] === (int) $slot['id_groupe'] && ($row['heure_debut'] !== $slot['heure_debut'] || $row['heure_fin'] !== $slot['heure_fin'])) {
                $conflicts[] = ['type' => 'groupe', 'id' => (int) $row['id'], 'message' => "Le groupe {$row['groupe_code']} a déjà cours ce {$day} à un autre horaire (" . substr($row['heure_debut'], 0, 5) . '–' . substr($row['heure_fin'], 0, 5) . ') : toutes ses salles suivent le même horaire.'];
            }
        }

        $refusal = $this->formations->roomRefusal((int) $session['id_type_formation'], (int) $slot['id_salle']);
        if ($refusal !== null) {
            $conflicts[] = ['type' => 'salle_dediee', 'id' => null, 'message' => $refusal];
        }
        return $conflicts;
    }

    /**
     * Le jour demandé est-il un passage du groupe ? Sinon, peut-il en devenir un sans dépasser
     * le nombre de passages prévu ? Retourne [heure_debut, heure_fin, nouveau_passage] ou un message d'erreur.
     */
    public function passageFor(int $groupId, int $day): array|string
    {
        $group = $this->fetchOne(
            'SELECT g.*, s.config_formation, s.config_version, s.id_type_formation, s.id AS sid
             FROM groupes g JOIN sessions s ON s.id = g.id_session WHERE g.id = :id',
            ['id' => $groupId]
        );
        if ($group === null) {
            return 'Groupe introuvable.';
        }
        $passages = $this->fetchAll('SELECT * FROM groupe_passages WHERE id_groupe = :id ORDER BY jour_semaine', ['id' => $groupId]);
        foreach ($passages as $passage) {
            if ((int) $passage['jour_semaine'] === $day) {
                return [$passage['heure_debut'], $passage['heure_fin'], false];
            }
        }
        if ($passages === [] && (int) $group['jour_semaine'] === $day) {
            return [$group['heure_debut'], $group['heure_fin'], true];
        }
        $config = $this->sessionConfig($this->getSession((int) $group['sid']));
        if (count($passages) >= (int) $config['passages_semaine']) {
            $days = implode(', ', array_map(static fn($p) => self::DAYS[(int) $p['jour_semaine']] ?? '?', $passages));
            return sprintf(
                'Le groupe %s a déjà ses %d passage(s) hebdomadaire(s) (%s), conformément à la configuration de la formation. Modifiez ses passages pour changer de jour.',
                $group['code'], count($passages), $days
            );
        }
        if (!in_array($day, FormationConfig::daysOf($config), true)) {
            return sprintf('Le %s n’est pas un jour autorisé pour cette formation.', self::DAYS[$day] ?? '?');
        }
        $start = $passages[0]['heure_debut'] ?? $group['heure_debut'];
        $end = $passages[0]['heure_fin'] ?? $group['heure_fin'];
        return [$start, $end, true];
    }

    /** Ajoute le passage d'un groupe s'il n'existe pas encore (appelé lors d'une affectation manuelle). */
    public function ensurePassage(int $groupId, int $day, string $start, string $end): void
    {
        $this->db->prepare(
            'INSERT IGNORE INTO groupe_passages (id_groupe, jour_semaine, heure_debut, heure_fin, ordre)
             SELECT :id, :jour, :debut, :fin, COALESCE(MAX(ordre), 0) + 1 FROM groupe_passages WHERE id_groupe = :id2'
        )->execute(['id' => $groupId, 'jour' => $day, 'debut' => $start, 'fin' => $end, 'id2' => $groupId]);
    }

    /**
     * Redéfinit les jours de passage d'un groupe : les salles du groupe sont reproduites sur les
     * nouveaux jours (après vérification des conflits) et retirées des jours abandonnés.
     */
    public function setPassages(int $groupId, array $days, ?string $start, ?string $end, ?int $userId): array
    {
        $days = array_values(array_unique(array_map('intval', $days)));
        sort($days);
        $this->acquireLock();
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            $group = $this->fetchOne('SELECT * FROM groupes WHERE id = :id FOR UPDATE', ['id' => $groupId]);
            if ($group === null) {
                throw new DomainException('Groupe introuvable.', 404);
            }
            $session = $this->getSession((int) $group['id_session']);
            $config = $this->sessionConfig($session);
            if ($days === [] || array_filter($days, static fn($d) => $d < 1 || $d > 5)) {
                throw new InvalidArgumentException('Indiquez au moins un jour entre lundi (1) et vendredi (5).');
            }
            if (count($days) > (int) $config['passages_semaine']) {
                throw new DomainException(sprintf('La formation prévoit %d passage(s) par semaine : %d jour(s) demandés.', $config['passages_semaine'], count($days)), 409);
            }
            $allowed = FormationConfig::daysOf($config);
            if (array_diff($days, $allowed)) {
                throw new DomainException('Un des jours demandés n’est pas autorisé pour cette formation.', 409);
            }
            $start = $start ? PlanningSolver::time($start) : $group['heure_debut'];
            $end = $end ? PlanningSolver::time($end) : $group['heure_fin'];
            if ($end <= $start) {
                throw new InvalidArgumentException('L’heure de fin doit être postérieure à l’heure de début.');
            }

            $rows = $this->fetchAll('SELECT * FROM emplois_du_temps WHERE id_groupe = :id AND statut = 1 ORDER BY jour_semaine', ['id' => $groupId]);
            $rooms = [];
            foreach ($rows as $row) {
                $rooms[(int) $row['id_salle']] = (int) $row['id_formateur'];
            }

            $removed = 0;
            foreach ($rows as $row) {
                $keep = in_array((int) $row['jour_semaine'], $days, true);
                if ($keep && ($row['heure_debut'] !== $start || $row['heure_fin'] !== $end)) {
                    $conflicts = $this->slotConflicts(['id_session' => $session['id'], 'id_groupe' => $groupId, 'id_salle' => $row['id_salle'], 'id_formateur' => $row['id_formateur'], 'jour_semaine' => $row['jour_semaine'], 'heure_debut' => $start, 'heure_fin' => $end], (int) $row['id']);
                    $this->throwConflicts($conflicts, $groupId);
                    $this->db->prepare('UPDATE emplois_du_temps SET heure_debut = :d, heure_fin = :f WHERE id = :id')->execute(['d' => $start, 'f' => $end, 'id' => $row['id']]);
                }
                if (!$keep) {
                    $removed += $this->removeRow((int) $row['id']);
                }
            }

            $created = 0;
            $existingDays = array_unique(array_map(static fn($r) => (int) $r['jour_semaine'], $rows));
            foreach (array_diff($days, $existingDays) as $day) {
                foreach ($rooms as $roomId => $trainerId) {
                    $slot = ['id_session' => (int) $session['id'], 'id_groupe' => $groupId, 'id_salle' => $roomId, 'id_formateur' => $trainerId, 'jour_semaine' => $day, 'heure_debut' => $start, 'heure_fin' => $end];
                    $this->throwConflicts($this->slotConflicts($slot), $groupId);
                    $this->insertRow($session, $slot, 'manuelle', null);
                    $created++;
                }
            }

            $this->db->prepare('DELETE FROM groupe_passages WHERE id_groupe = :id')->execute(['id' => $groupId]);
            foreach ($days as $order => $day) {
                $this->db->prepare('INSERT INTO groupe_passages (id_groupe, jour_semaine, heure_debut, heure_fin, ordre) VALUES (:g, :j, :d, :f, :o)')
                    ->execute(['g' => $groupId, 'j' => $day, 'd' => $start, 'f' => $end, 'o' => $order + 1]);
            }
            $this->db->prepare('UPDATE groupes SET jour_semaine = :j, heure_debut = :d, heure_fin = :f WHERE id = :id')
                ->execute(['j' => $days[0], 'd' => $start, 'f' => $end, 'id' => $groupId]);

            if ($ownTx) {
                $this->db->commit();
            }
            return ['jours' => $days, 'heure_debut' => substr($start, 0, 5), 'heure_fin' => substr($end, 0, 5), 'creneaux_crees' => $created, 'creneaux_retires' => $removed];
        } catch (Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Créneaux alternatifs pour un créneau en conflit (ou pour placer un groupe) :
     *   - autres salles libres au même jour et horaire ;
     *   - autres jours où toutes les salles du groupe sont libres (déplacer le passage) ;
     *   - autres créneaux horaires du même jour, si la formation en définit plusieurs.
     */
    public function alternatives(int $groupId, ?int $rowId = null, ?int $day = null, ?int $requestedRoom = null): array
    {
        $group = $this->fetchOne('SELECT * FROM groupes WHERE id = :id', ['id' => $groupId]);
        if ($group === null) {
            throw new DomainException('Groupe introuvable.', 404);
        }
        $session = $this->getSession((int) $group['id_session']);
        $config = $this->sessionConfig($session);
        $rooms = $this->usableRooms((int) $session['id_type_formation'])['salles'];
        $rows = $this->fetchAll('SELECT * FROM emplois_du_temps WHERE id_groupe = :id AND statut = 1', ['id' => $groupId]);
        $row = null;
        foreach ($rows as $candidate) {
            if ($rowId !== null && (int) $candidate['id'] === $rowId) {
                $row = $candidate;
            }
        }
        $day = $row !== null ? (int) $row['jour_semaine'] : ($day ?? (int) $group['jour_semaine']);
        $start = $row['heure_debut'] ?? $group['heure_debut'];
        $end = $row['heure_fin'] ?? $group['heure_fin'];
        $groupRooms = array_values(array_unique(array_map(static fn($r) => (int) $r['id_salle'], array_filter($rows, static fn($r) => (int) $r['jour_semaine'] === $day))));
        $groupDays = array_values(array_unique(array_map(static fn($r) => (int) $r['jour_semaine'], $rows)));
        $ownIds = array_map(static fn($r) => (int) $r['id'], $rows);

        $isFree = function (array $room, int $d, string $s, string $e) use ($session, $groupId, $ownIds): bool {
            $conflicts = $this->slotConflicts(['id_session' => $session['id'], 'id_groupe' => $groupId, 'id_salle' => $room['id'], 'id_formateur' => $room['id_formateur'], 'jour_semaine' => $d, 'heure_debut' => $s, 'heure_fin' => $e]);
            foreach ($conflicts as $c) {
                if ($c['id'] === null || !in_array($c['id'], $ownIds, true)) {
                    return false;
                }
            }
            return true;
        };

        $sameSlot = [];
        foreach ($rooms as $room) {
            if (!in_array((int) $room['id'], $groupRooms, true) && $isFree($room, $day, $start, $end)) {
                $sameSlot[] = ['id_salle' => (int) $room['id'], 'salle_code' => $room['code'], 'id_formateur' => (int) $room['id_formateur'], 'formateur' => $room['formateur'], 'jour_semaine' => $day, 'heure_debut' => substr($start, 0, 5), 'heure_fin' => substr($end, 0, 5)];
            }
        }

        $byId = [];
        foreach ($rooms as $room) {
            $byId[(int) $room['id']] = $room;
        }
        // Un groupe garde les mêmes salles à chaque passage : déplacer un jour déplace toutes ses salles.
        $roomsToMove = $groupRooms !== [] ? $groupRooms : ($row !== null ? [(int) $row['id_salle']] : ($requestedRoom ? [$requestedRoom] : []));
        $otherDays = [];
        foreach (FormationConfig::daysOf($config) as $d) {
            if (in_array($d, $groupDays, true)) {
                continue;
            }
            $free = array_filter($roomsToMove, fn($id) => isset($byId[$id]) && $isFree($byId[$id], $d, $start, $end));
            if ($roomsToMove !== [] && count($free) === count($roomsToMove)) {
                $otherDays[] = ['jour_semaine' => $d, 'jour' => self::DAYS[$d], 'heure_debut' => substr($start, 0, 5), 'heure_fin' => substr($end, 0, 5), 'salles' => array_values($roomsToMove)];
            }
        }

        $otherSlots = [];
        foreach (FormationConfig::slotsOf($config) as $slot) {
            $s = PlanningSolver::time($slot['debut']);
            $e = PlanningSolver::time($slot['fin']);
            if ($s === $start) {
                continue;
            }
            $free = array_filter($roomsToMove, fn($id) => isset($byId[$id]) && $isFree($byId[$id], $day, $s, $e));
            if ($roomsToMove !== [] && count($free) === count($roomsToMove)) {
                $otherSlots[] = ['jour_semaine' => $day, 'heure_debut' => $slot['debut'], 'heure_fin' => $slot['fin']];
            }
        }

        return [
            'groupe' => ['id' => $groupId, 'code' => $group['code'], 'libelle' => $group['libelle']],
            'jour_semaine' => $day,
            'autres_salles' => $sameSlot,
            'autres_jours' => $otherDays,
            'autres_horaires' => $otherSlots,
            'message' => ($sameSlot || $otherDays || $otherSlots) ? null : 'Aucune alternative libre : ajoutez des salles à la formation, autorisez un partage de salle ou un autre jour.',
        ];
    }

    /* ==================================================================
     | Conflits et synthèse
     |================================================================== */

    /** Conflits et incohérences des créneaux actifs (filtrés par année et/ou session). */
    public function conflicts(?int $anneeId = null, ?int $sessionId = null): array
    {
        $scope = '';
        $params = [];
        if ($anneeId !== null) {
            $scope .= ' AND (a.id_annee = :annee1 OR b.id_annee = :annee2)';
            $params += ['annee1' => $anneeId, 'annee2' => $anneeId];
        }
        if ($sessionId !== null) {
            $scope .= ' AND (a.id_session = :session1 OR b.id_session = :session2)';
            $params += ['session1' => $sessionId, 'session2' => $sessionId];
        }
        $pairs = $this->fetchAll(
            "SELECT a.id AS id_a, b.id AS id_b, a.jour_semaine, a.id_session AS session_id_a, b.id_session AS session_id_b,
                    (a.id_salle = b.id_salle) AS meme_salle,
                    sa1.code AS salle_a, sa2.code AS salle_b,
                    CONCAT(fa.prenom, ' ', fa.nom) AS formateur,
                    ga.code AS groupe_a, gb.code AS groupe_b, s1.code AS session_a, s2.code AS session_b,
                    a.heure_debut AS debut_a, a.heure_fin AS fin_a, b.heure_debut AS debut_b, b.heure_fin AS fin_b
             FROM emplois_du_temps a
             JOIN emplois_du_temps b ON b.id > a.id AND b.statut = 1 AND b.jour_semaine = a.jour_semaine
                  AND (b.id_salle = a.id_salle OR b.id_formateur = a.id_formateur)
                  AND a.heure_debut < b.heure_fin AND b.heure_debut < a.heure_fin
             JOIN sessions s1 ON s1.id = a.id_session JOIN sessions s2 ON s2.id = b.id_session
             JOIN groupes ga ON ga.id = a.id_groupe JOIN groupes gb ON gb.id = b.id_groupe
             JOIN salles sa1 ON sa1.id = a.id_salle JOIN salles sa2 ON sa2.id = b.id_salle
             JOIN v_formateurs fa ON fa.id = a.id_formateur
             WHERE a.statut = 1 AND ga.statut = 1 AND gb.statut = 1
               AND s1.statut IN ('planifiee', 'ouverte', 'en_cours') AND s2.statut IN ('planifiee', 'ouverte', 'en_cours')
               AND s1.date_debut <= s2.date_fin AND s2.date_debut <= s1.date_fin {$scope}",
            $params
        );

        $result = [];
        foreach ($pairs as $p) {
            $day = self::DAYS[(int) $p['jour_semaine']] ?? '?';
            $a = "{$p['session_a']} · {$p['groupe_a']} (" . substr($p['debut_a'], 0, 5) . '–' . substr($p['fin_a'], 0, 5) . ')';
            $b = "{$p['session_b']} · {$p['groupe_b']} (" . substr($p['debut_b'], 0, 5) . '–' . substr($p['fin_b'], 0, 5) . ')';
            $common = ['gravite' => 'erreur', 'ids' => [(int) $p['id_a'], (int) $p['id_b']], 'sessions' => array_values(array_unique([(int) $p['session_id_a'], (int) $p['session_id_b']]))];
            $result[] = (bool) $p['meme_salle']
                ? ['type' => 'salle', 'message' => "Salle {$p['salle_a']} réservée deux fois le {$day} : {$a} et {$b}."] + $common
                : ['type' => 'formateur', 'message' => "{$p['formateur']} affecté(e) à deux cours simultanés le {$day} : {$a} en {$p['salle_a']} et {$b} en {$p['salle_b']}."] + $common;
        }

        return array_merge($result, $this->ruleViolations($anneeId, $sessionId));
    }

    /**
     * Synthèse du calendrier : séances programmées / réalisées / restantes, passages manquants,
     * conflits et occupation des salles par jour et horaire.
     */
    public function synthese(?int $anneeId, ?int $sessionId, string $today): array
    {
        $where = ["s.statut IN ('planifiee', 'ouverte', 'en_cours', 'terminee')"];
        $params = [];
        if ($anneeId !== null) {
            $where[] = 's.id_annee = :annee';
            $params['annee'] = $anneeId;
        }
        if ($sessionId !== null) {
            $where[] = 's.id = :session';
            $params['session'] = $sessionId;
        }
        $sessions = $this->fetchAll(
            "SELECT s.*, tf.libelle AS formation_libelle, tf.couleur AS formation_couleur
             FROM sessions s JOIN types_formations tf ON tf.id = s.id_type_formation
             WHERE " . implode(' AND ', $where) . ' ORDER BY s.date_debut, s.code',
            $params
        );

        $conflicts = $this->conflicts($anneeId, $sessionId);
        $summaries = [];
        $totals = ['seances_programmees' => 0, 'seances_realisees' => 0, 'seances_restantes' => 0, 'seances_manquantes' => 0, 'passages_requis' => 0, 'passages_planifies' => 0, 'conflits' => 0];

        foreach ($sessions as $session) {
            $config = $this->sessionConfig($session);
            $groups = $this->sessionGroups((int) $session['id']);
            $weeks = [];
            foreach (range(1, 5) as $d) {
                $weeks[$d] = self::countWeekday($session['date_debut'], $session['date_fin'], $d);
            }
            // Séances réalisées : du début de la session à la veille (ou à la fin de la session si elle est passée).
            $lastPast = min(date('Y-m-d', strtotime($today . ' -1 day')), $session['date_fin']);
            $past = [];
            foreach (range(1, 5) as $d) {
                $past[$d] = self::countWeekday($session['date_debut'], $lastPast, $d);
            }

            $planned = 0;
            $done = 0;
            $placed = 0;
            $incomplete = [];
            foreach ($groups as $group) {
                $count = min(count($group['jours']), (int) $config['passages_semaine']);
                $placed += $count;
                if ($count < (int) $config['passages_semaine']) {
                    $incomplete[] = ['id' => (int) $group['id'], 'code' => $group['code'], 'passages' => count($group['jours'])];
                }
                foreach ($group['jours'] as $d) {
                    $planned += $weeks[$d];
                    $done += $past[$d];
                }
            }
            $expectedPerGroup = (int) round(array_sum($weeks) / 5 * (int) $config['passages_semaine']);
            $required = count($groups) * (int) $config['passages_semaine'];
            $sessionConflicts = count(array_filter($conflicts, static fn($c) => in_array((int) $session['id'], $c['sessions'], true) && $c['gravite'] === 'erreur'));

            $summary = [
                'id_session' => (int) $session['id'],
                'code' => $session['code'],
                'libelle' => $session['libelle'],
                'formation' => $session['formation_libelle'],
                'couleur' => $session['formation_couleur'],
                'statut' => $session['statut'],
                'date_debut' => $session['date_debut'],
                'date_fin' => $session['date_fin'],
                'configuration' => $config,
                'groupes' => count($groups),
                'groupes_prevus' => (int) $config['nb_groupes'],
                'passages_requis' => $required,
                'passages_planifies' => $placed,
                'groupes_incomplets' => $incomplete,
                'seances_programmees' => $planned,
                'seances_realisees' => $done,
                'seances_restantes' => max(0, $planned - $done),
                'seances_manquantes' => max(0, $expectedPerGroup * count($groups) - $planned),
                'conflits' => $sessionConflicts,
            ];
            $summaries[] = $summary;
            foreach (['seances_programmees', 'seances_realisees', 'seances_restantes', 'seances_manquantes', 'passages_requis', 'passages_planifies'] as $key) {
                $totals[$key] += $summary[$key];
            }
        }
        $totals['conflits'] = count(array_filter($conflicts, static fn($c) => $c['gravite'] === 'erreur'));
        $totals['avertissements'] = count($conflicts) - $totals['conflits'];

        return [
            'totaux' => $totals,
            'sessions' => $summaries,
            'occupation' => $this->occupation($anneeId, $sessionId, $today),
            'conflits' => $conflicts,
        ];
    }

    /** Occupation des salles : salle × jour → créneaux (sessions actives à la date de référence ou filtrées). */
    public function occupation(?int $anneeId, ?int $sessionId, string $date): array
    {
        $where = ["e.statut = 1", "g.statut = 1", "s.statut IN ('planifiee', 'ouverte', 'en_cours')"];
        $params = [];
        if ($sessionId !== null) {
            $where[] = 's.id = :session';
            $params['session'] = $sessionId;
        } else {
            $where[] = ':date1 <= s.date_fin';
            $params['date1'] = $date;
            if ($anneeId !== null) {
                $where[] = 's.id_annee = :annee';
                $params['annee'] = $anneeId;
            }
        }
        $rows = $this->fetchAll(
            "SELECT e.id, e.id_salle, e.jour_semaine, e.heure_debut, e.heure_fin, e.id_formateur,
                    g.id AS id_groupe, g.code AS groupe_code, s.id AS id_session, s.code AS session_code,
                    s.date_debut, s.date_fin, tf.id AS id_formation, tf.libelle AS formation, tf.couleur
             FROM emplois_du_temps e
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = e.id_session
             JOIN types_formations tf ON tf.id = s.id_type_formation
             WHERE " . implode(' AND ', $where) . ' ORDER BY e.jour_semaine, e.heure_debut',
            $params
        );
        $rooms = $this->fetchAll(
            "SELECT sa.id, sa.code, sa.libelle, sa.capacite, sa.statut,
                    own.id_type_formation AS id_formation_proprietaire, tf.libelle AS formation_proprietaire,
                    CONCAT(f.prenom, ' ', f.nom) AS formateur
             FROM salles sa
             LEFT JOIN formation_salles own ON own.id_salle = sa.id AND own.mode = 'dediee'
             LEFT JOIN types_formations tf ON tf.id = own.id_type_formation
             LEFT JOIN v_formateurs f ON f.id_salle = sa.id
             ORDER BY sa.code"
        );
        usort($rooms, static fn($a, $b) => strnatcasecmp($a['code'], $b['code']));

        $grid = [];
        foreach ($rooms as $room) {
            $cells = [];
            foreach (range(1, 5) as $d) {
                $cells[$d] = array_values(array_filter($rows, static fn($r) => (int) $r['id_salle'] === (int) $room['id'] && (int) $r['jour_semaine'] === $d));
            }
            $grid[] = $room + ['jours' => $cells, 'jours_occupes' => count(array_filter($cells))];
        }
        $occupied = array_sum(array_map(static fn($r) => $r['jours_occupes'], $grid));
        $available = count(array_filter($rooms, static fn($r) => $r['statut'] === 'disponible')) * 5;
        return [
            'salles' => $grid,
            'taux_occupation' => $available > 0 ? round($occupied / $available * 100, 1) : 0,
            'cellules_occupees' => $occupied,
            'cellules_disponibles' => $available,
        ];
    }

    /* ==================================================================
     | Interne
     |================================================================== */

    /** Règles métier non respectées par les créneaux existants (avertissements et erreurs). */
    private function ruleViolations(?int $anneeId, ?int $sessionId): array
    {
        $where = ["s.statut IN ('planifiee', 'ouverte', 'en_cours')"];
        $params = [];
        if ($anneeId !== null) {
            $where[] = 's.id_annee = :annee';
            $params['annee'] = $anneeId;
        }
        if ($sessionId !== null) {
            $where[] = 's.id = :session';
            $params['session'] = $sessionId;
        }
        $sessions = $this->fetchAll('SELECT s.* FROM sessions s WHERE ' . implode(' AND ', $where), $params);
        $owners = $this->formations->roomOwners();
        $shares = [];
        foreach ($this->fetchAll('SELECT id_type_formation, id_salle FROM formation_salles') as $link) {
            $shares[(int) $link['id_type_formation']][(int) $link['id_salle']] = true;
        }

        $issues = [];
        foreach ($sessions as $session) {
            $config = $this->sessionConfig($session);
            $sid = (int) $session['id'];
            $rows = $this->fetchAll(
                "SELECT e.*, g.code AS groupe_code, sa.code AS salle_code, sa.statut AS salle_statut, sa.capacite,
                        f.actif AS formateur_actif, f.id_salle AS formateur_salle,
                        (SELECT COUNT(*) FROM inscriptions i JOIN emplois_du_temps e2 ON e2.id = i.id_emploi_du_temps
                          WHERE e2.id_groupe = e.id_groupe AND e2.id_salle = e.id_salle AND i.statut = 'active') AS inscrits
                 FROM emplois_du_temps e
                 JOIN groupes g ON g.id = e.id_groupe
                 JOIN salles sa ON sa.id = e.id_salle
                 JOIN v_formateurs f ON f.id = e.id_formateur
                 WHERE e.id_session = :sid AND e.statut = 1 AND g.statut = 1",
                ['sid' => $sid]
            );
            foreach ($rows as $row) {
                $label = "{$session['code']} · {$row['groupe_code']} · {$row['salle_code']} (" . (self::DAYS[(int) $row['jour_semaine']] ?? '?') . ')';
                $owner = $owners[(int) $row['id_salle']] ?? null;
                if ($owner !== null && $owner['id'] !== (int) $session['id_type_formation'] && empty($shares[(int) $session['id_type_formation']][(int) $row['id_salle']])) {
                    $issues[] = ['type' => 'salle_dediee', 'gravite' => 'erreur', 'ids' => [(int) $row['id']], 'sessions' => [$sid], 'message' => "{$label} : salle dédiée à « {$owner['libelle']} » sans autorisation de partage."];
                }
                if ($row['salle_statut'] !== 'disponible') {
                    $issues[] = ['type' => 'salle', 'gravite' => 'erreur', 'ids' => [(int) $row['id']], 'sessions' => [$sid], 'message' => "{$label} : la salle est indisponible."];
                }
                if (!(bool) $row['formateur_actif']) {
                    $issues[] = ['type' => 'formateur', 'gravite' => 'erreur', 'ids' => [(int) $row['id']], 'sessions' => [$sid], 'message' => "{$label} : le formateur est inactif."];
                }
                if ($row['capacite'] !== null && (int) $row['inscrits'] > (int) $row['capacite']) {
                    $issues[] = ['type' => 'capacite', 'gravite' => 'avertissement', 'ids' => [(int) $row['id']], 'sessions' => [$sid], 'message' => "{$label} : {$row['inscrits']} auditeurs pour {$row['capacite']} places."];
                }
            }
            foreach ($this->sessionGroups($sid) as $group) {
                $count = count($group['jours']);
                if ($count === 0) {
                    $issues[] = ['type' => 'groupe', 'gravite' => 'avertissement', 'ids' => [], 'sessions' => [$sid], 'id_groupe' => (int) $group['id'], 'message' => "{$session['code']} · {$group['code']} : aucune salle affectée."];
                } elseif ($count !== (int) $config['passages_semaine']) {
                    $issues[] = ['type' => 'passages', 'gravite' => 'avertissement', 'ids' => [], 'sessions' => [$sid], 'id_groupe' => (int) $group['id'], 'message' => sprintf('%s · %s : %d passage(s) planifié(s) sur %d prévu(s) par semaine.', $session['code'], $group['code'], $count, $config['passages_semaine'])];
                }
                if ($count > 0 && count($group['salles']) < (int) $config['salles_par_groupe']) {
                    $issues[] = ['type' => 'salles', 'gravite' => 'avertissement', 'ids' => [], 'sessions' => [$sid], 'id_groupe' => (int) $group['id'], 'message' => sprintf('%s · %s : %d salle(s) occupée(s) sur %d prévue(s) par la configuration.', $session['code'], $group['code'], count($group['salles']), $config['salles_par_groupe'])];
                }
                $times = array_unique(array_map(static fn($r) => $r['jour_semaine'] . '|' . $r['heure_debut'], $group['creneaux']));
                $byDay = [];
                foreach ($times as $t) {
                    [$d] = explode('|', $t);
                    $byDay[$d] = ($byDay[$d] ?? 0) + 1;
                }
                foreach ($byDay as $d => $n) {
                    if ($n > 1) {
                        $issues[] = ['type' => 'groupe', 'gravite' => 'erreur', 'ids' => [], 'sessions' => [$sid], 'id_groupe' => (int) $group['id'], 'message' => "{$session['code']} · {$group['code']} : plusieurs horaires différents le " . (self::DAYS[(int) $d] ?? '?') . '.'];
                    }
                }
            }
        }
        return $issues;
    }

    /** Résultat lisible : ajoute libellés, formateurs et différences avec l'existant. */
    private function present(array $context, array $result): array
    {
        $rooms = [];
        foreach ($context['rooms']['salles'] as $room) {
            $rooms[(int) $room['id']] = $room;
        }
        $groupsByKey = [];
        foreach ($context['groups'] as $group) {
            $groupsByKey['g' . $group['id']] = $group;
        }
        $enrich = function (array $assignments) use ($rooms, $groupsByKey): array {
            return array_map(function ($a) use ($rooms, $groupsByKey) {
                $group = $groupsByKey[$a['cle']] ?? null;
                $before = $group ? array_map(static fn($r) => (int) $r['jour_semaine'] . ':' . (int) $r['id_salle'], $group['creneaux']) : [];
                $after = [];
                foreach ($a['jours'] as $d) {
                    foreach ($a['salles'] as $s) {
                        $after[] = "{$d}:{$s}";
                    }
                }
                return $a + [
                    'id_groupe' => $group ? (int) $group['id'] : null,
                    'libelle' => $group['libelle'] ?? ('Groupe ' . substr($a['code'], 1)),
                    'nouveau_groupe' => $group === null,
                    'jours_libelles' => array_map(static fn($d) => self::DAYS[$d] ?? '?', $a['jours']),
                    'salles_detail' => array_map(static fn($id) => ['id' => $id, 'code' => $rooms[$id]['code'] ?? (string) $id, 'formateur' => $rooms[$id]['formateur'] ?? null, 'capacite' => $rooms[$id]['capacite'] ?? null], $a['salles']),
                    'a_creer' => count(array_diff($after, $before)),
                    'a_retirer' => count(array_diff($before, $after)),
                ];
            }, $assignments);
        };
        foreach ($result['solutions'] as &$solution) {
            $solution['affectations'] = $enrich($solution['affectations']);
        }
        unset($solution);
        if ($result['partielle'] !== null) {
            $result['partielle'] = $enrich($result['partielle']);
        }

        return $result + [
            'session' => [
                'id' => (int) $context['session']['id'], 'code' => $context['session']['code'], 'libelle' => $context['session']['libelle'],
                'formation' => $context['session']['formation_libelle'], 'date_debut' => $context['session']['date_debut'], 'date_fin' => $context['session']['date_fin'],
            ],
            'mode' => $context['mode'],
            'configuration' => $context['config'],
            'salles_utilisables' => array_map(static fn($r) => ['id' => (int) $r['id'], 'code' => $r['code'], 'libelle' => $r['libelle'], 'mode' => $r['mode'], 'formateur' => $r['formateur'], 'capacite' => $r['capacite']], $context['rooms']['salles']),
            'salles_exclues' => $context['rooms']['exclues'],
            'salles_configurees' => $context['rooms']['salles_configurees'],
            'reservations_externes' => count($context['occupations']),
            'groupes_existants' => count($context['groups']),
            'groupes_a_creer' => array_map(static fn($g) => $g['code'], $context['newGroups']),
        ];
    }

    /** Écrit le plan : groupes, passages et créneaux. Retourne [créés, retirés, groupes créés]. */
    private function write(array $context, array $plan, int $generationId, string $origin): array
    {
        $session = $context['session'];
        $groupsByKey = [];
        foreach ($context['groups'] as $group) {
            $groupsByKey['g' . $group['id']] = $group;
        }
        $newByKey = [];
        foreach ($context['newGroups'] as $group) {
            $newByKey[$group['cle']] = $group;
        }
        $trainers = [];
        foreach ($context['rooms']['salles'] as $room) {
            $trainers[(int) $room['id']] = (int) $room['id_formateur'];
        }

        $created = 0;
        $removed = 0;
        $groupsCreated = [];
        foreach ($plan as $assignment) {
            if ($assignment['fixe'] && $assignment['nouveaux_jours'] === []) {
                continue; // groupe figé et complet : rien à écrire
            }
            $start = PlanningSolver::time($assignment['creneau']['debut']);
            $end = PlanningSolver::time($assignment['creneau']['fin']);
            $days = $assignment['jours'];

            if (isset($newByKey[$assignment['cle']])) {
                $new = $newByKey[$assignment['cle']];
                $this->db->prepare(
                    'INSERT INTO groupes (id_session, code, libelle, jour_semaine, heure_debut, heure_fin, statut)
                     VALUES (:s, :code, :libelle, :jour, :debut, :fin, 1)'
                )->execute(['s' => (int) $session['id'], 'code' => $new['code'], 'libelle' => $new['libelle'], 'jour' => $days[0], 'debut' => $start, 'fin' => $end]);
                $groupId = (int) $this->db->lastInsertId();
                $groupsCreated[] = ['id' => $groupId, 'code' => $new['code']];
                $existingRows = [];
            } else {
                $group = $groupsByKey[$assignment['cle']];
                $groupId = (int) $group['id'];
                $existingRows = $group['creneaux'];
                $this->db->prepare('UPDATE groupes SET jour_semaine = :j, heure_debut = :d, heure_fin = :f WHERE id = :id')
                    ->execute(['j' => $days[0], 'd' => $start, 'f' => $end, 'id' => $groupId]);
            }

            $wanted = [];
            foreach ($days as $d) {
                foreach ($assignment['salles'] as $roomId) {
                    $wanted["{$d}:{$roomId}"] = [$d, (int) $roomId];
                }
            }
            foreach ($existingRows as $row) {
                $key = (int) $row['jour_semaine'] . ':' . (int) $row['id_salle'];
                if (!isset($wanted[$key]) && !$assignment['fixe']) {
                    $removed += $this->removeRow((int) $row['id']);
                } else {
                    unset($wanted[$key]);
                }
            }
            foreach ($wanted as [$d, $roomId]) {
                $trainerId = $trainers[$roomId] ?? (int) $this->fetchColumn('SELECT id FROM formateurs WHERE id_salle = :s', ['s' => $roomId]);
                $this->insertRow($session, [
                    'id_groupe' => $groupId, 'id_salle' => $roomId, 'id_formateur' => $trainerId,
                    'jour_semaine' => $d, 'heure_debut' => $start, 'heure_fin' => $end,
                ], $origin, $generationId);
                $created++;
            }

            $this->db->prepare('DELETE FROM groupe_passages WHERE id_groupe = :id')->execute(['id' => $groupId]);
            foreach ($days as $order => $d) {
                $this->db->prepare('INSERT INTO groupe_passages (id_groupe, jour_semaine, heure_debut, heure_fin, ordre) VALUES (:g, :j, :d, :f, :o)')
                    ->execute(['g' => $groupId, 'j' => $d, 'd' => $start, 'f' => $end, 'o' => $order + 1]);
            }
        }
        return [$created, $removed, $groupsCreated];
    }

    private function insertRow(array $session, array $slot, string $origin, ?int $generationId): void
    {
        // Un créneau désactivé du même groupe, dans la même salle et le même jour, est réactivé.
        $inactive = $this->fetchColumn(
            'SELECT id FROM emplois_du_temps WHERE id_groupe = :g AND id_salle = :s AND jour_semaine = :j AND statut = 0',
            ['g' => (int) $slot['id_groupe'], 's' => (int) $slot['id_salle'], 'j' => (int) $slot['jour_semaine']]
        );
        if ($inactive !== false) {
            $this->db->prepare(
                'UPDATE emplois_du_temps SET statut = 1, id_formateur = :f, heure_debut = :d, heure_fin = :e, origine = :o, id_generation = :gen WHERE id = :id'
            )->execute(['f' => (int) $slot['id_formateur'], 'd' => $slot['heure_debut'], 'e' => $slot['heure_fin'], 'o' => $origin, 'gen' => $generationId, 'id' => (int) $inactive]);
            return;
        }
        // Les créneaux désactivés sans historique qui occupent encore la place sont supprimés.
        $this->db->prepare(
            'DELETE e FROM emplois_du_temps e
             WHERE e.id_session = :session AND e.jour_semaine = :j AND e.heure_debut = :d AND e.statut = 0
               AND (e.id_salle = :s OR e.id_formateur = :f)
               AND NOT EXISTS (SELECT 1 FROM inscriptions i WHERE i.id_emploi_du_temps = e.id)
               AND NOT EXISTS (SELECT 1 FROM presences p WHERE p.id_emploi_du_temps = e.id)'
        )->execute(['session' => (int) $session['id'], 'j' => (int) $slot['jour_semaine'], 'd' => $slot['heure_debut'], 's' => (int) $slot['id_salle'], 'f' => (int) $slot['id_formateur']]);
        $this->db->prepare(
            'INSERT INTO emplois_du_temps (id_annee, id_session, id_groupe, id_salle, id_formateur, jour_semaine, heure_debut, heure_fin, statut, origine, id_generation)
             VALUES (:annee, :session, :groupe, :salle, :formateur, :jour, :debut, :fin, 1, :origine, :generation)'
        )->execute([
            'annee' => (int) $session['id_annee'], 'session' => (int) $session['id'], 'groupe' => (int) $slot['id_groupe'],
            'salle' => (int) $slot['id_salle'], 'formateur' => (int) $slot['id_formateur'], 'jour' => (int) $slot['jour_semaine'],
            'debut' => $slot['heure_debut'], 'fin' => $slot['heure_fin'], 'origine' => $origin, 'generation' => $generationId,
        ]);
    }

    /** Supprime un créneau sans historique ; le désactive s'il est référencé (inscriptions, présences). */
    private function removeRow(int $rowId): int
    {
        $used = (int) $this->fetchColumn(
            'SELECT (SELECT COUNT(*) FROM inscriptions WHERE id_emploi_du_temps = :a) + (SELECT COUNT(*) FROM presences WHERE id_emploi_du_temps = :b)',
            ['a' => $rowId, 'b' => $rowId]
        );
        if ($used > 0) {
            $this->db->prepare('UPDATE emplois_du_temps SET statut = 0 WHERE id = :id')->execute(['id' => $rowId]);
        } else {
            $this->db->prepare('DELETE FROM emplois_du_temps WHERE id = :id')->execute(['id' => $rowId]);
        }
        return 1;
    }

    private function throwConflicts(array $conflicts, int $groupId): void
    {
        if ($conflicts !== []) {
            throw new PlanningException(
                implode(' ', array_map(static fn($c) => $c['message'], $conflicts)),
                ['conflits' => $conflicts, 'alternatives' => $this->alternatives($groupId)]
            );
        }
    }

    /** Jours et salles de chaque groupe (par code) d'une session précédente. */
    private function preferencesFrom(int $sourceId): array
    {
        if ($sourceId <= 0) {
            return [];
        }
        $prefs = [];
        foreach ($this->fetchAll(
            'SELECT g.code, e.jour_semaine, e.id_salle FROM emplois_du_temps e JOIN groupes g ON g.id = e.id_groupe
             WHERE g.id_session = :id AND e.statut = 1',
            ['id' => $sourceId]
        ) as $row) {
            $prefs[$row['code']]['jours'][] = (int) $row['jour_semaine'];
            $prefs[$row['code']]['salles'][] = (int) $row['id_salle'];
        }
        foreach ($prefs as &$pref) {
            $pref['jours'] = array_values(array_unique($pref['jours']));
            $pref['salles'] = array_values(array_unique($pref['salles']));
        }
        unset($pref);
        return $prefs;
    }

    private function logGeneration(int $sessionId, string $mode, array $options, ?int $userId): int
    {
        $this->db->prepare(
            "INSERT INTO planification_generations (id_session, mode, statut, parametres, user_id) VALUES (:s, :m, 'echec', :p, :u)"
        )->execute(['s' => $sessionId, 'm' => $mode, 'p' => json_encode($options, JSON_UNESCAPED_UNICODE), 'u' => $userId]);
        return (int) $this->db->lastInsertId();
    }

    /** Verrou applicatif : deux planifications simultanées ne peuvent pas réserver la même salle. */
    private int $lockDepth = 0;

    public function acquireLock(): void
    {
        if ($this->lockDepth++ > 0) {
            return;
        }
        if ((int) $this->fetchColumn('SELECT GET_LOCK(:name, 10)', ['name' => self::LOCK]) !== 1) {
            $this->lockDepth = 0;
            throw new DomainException('Une autre planification est en cours : réessayez dans quelques secondes.', 409);
        }
    }

    public function releaseLock(): void
    {
        if ($this->lockDepth > 0 && --$this->lockDepth === 0) {
            $this->fetchColumn('SELECT RELEASE_LOCK(:name)', ['name' => self::LOCK]);
        }
    }

    /** Nombre de jours de la semaine $weekday (1 = lundi) entre deux dates incluses. */
    public static function countWeekday(string $from, string $to, int $weekday): int
    {
        if ($to < $from) {
            return 0;
        }
        $start = new DateTimeImmutable($from);
        $offset = ($weekday - (int) $start->format('N') + 7) % 7;
        $first = $start->modify("+{$offset} days");
        $end = new DateTimeImmutable($to);
        if ($first > $end) {
            return 0;
        }
        return intdiv((int) $first->diff($end)->days, 7) + 1;
    }
}

