<?php
/**
 * Modèle Groupe (table : groupes)
 */

require_once __DIR__ . '/Model.php';

class Groupe extends Model
{
    protected string $table = 'groupes';

    /** Jours autorisés : 1 = lundi ... 5 = vendredi (chk_groupes_jour). */
    public const JOURS = [1, 2, 3, 4, 5];

    protected array $fillable = [
        'id_session', 'code', 'libelle', 'jour_semaine', 'heure_debut', 'heure_fin', 'capacite', 'statut',
    ];

    /** Colonnes communes : jours de passage (« 1,3 ») et nombre de salles affectées. */
    private const LIST_COLUMNS = "g.*,
                (SELECT GROUP_CONCAT(gp.jour_semaine ORDER BY gp.jour_semaine) FROM groupe_passages gp WHERE gp.id_groupe = g.id) AS passages,
                (SELECT COUNT(DISTINCT e.id_salle) FROM emplois_du_temps e WHERE e.id_groupe = g.id AND e.statut = 1) AS nb_salles";

    /** Tous les groupes, avec leurs jours de passage. */
    public function getAllWithPassages(): array
    {
        return $this->fetchAll("SELECT " . self::LIST_COLUMNS . " FROM {$this->table} g ORDER BY g.code");
    }

    /** Groupes d'une session. */
    public function getBySession(int $id_session): array
    {
        return $this->fetchAll(
            "SELECT " . self::LIST_COLUMNS . " FROM {$this->table} g WHERE g.id_session = :id_session ORDER BY g.code",
            ['id_session' => $id_session]
        );
    }

    /** Crée un groupe et son premier passage hebdomadaire (son jour de cours). */
    public function createWithPassage(array $data): int
    {
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            $id = $this->create($data);
            $this->db->prepare(
                'INSERT INTO groupe_passages (id_groupe, jour_semaine, heure_debut, heure_fin, ordre)
                 SELECT id, jour_semaine, heure_debut, heure_fin, 1 FROM groupes WHERE id = :id'
            )->execute(['id' => $id]);
            if ($ownTx) {
                $this->db->commit();
            }
            return $id;
        } catch (Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Groupes planifiés un jour donné (1 = lundi ... 5 = vendredi). */
    public function getByJour(int $jour_semaine): array
    {
        return $this->fetchAll(
            "SELECT * FROM {$this->table} WHERE jour_semaine = :jour AND statut = 1 ORDER BY code",
            ['jour' => $jour_semaine]
        );
    }

    /** Groupe avec le détail de sa session. */
    public function getWithSession(int $id): ?array
    {
        return $this->fetchOne(
            "SELECT g.*,
                    s.code AS session_code, s.libelle AS session_libelle,
                    s.date_debut AS session_date_debut, s.date_fin AS session_date_fin,
                    s.statut AS session_statut
             FROM {$this->table} g
             JOIN sessions s ON s.id = g.id_session
             WHERE g.id = :id
             LIMIT 1",
            ['id' => $id]
        );
    }

    /**
     * Salles utilisées par le groupe (un créneau par salle, le jour du groupe),
     * avec le formateur attitré, l'effectif actif et la liste des auditeurs.
     */
    public function getSalles(int $id): array
    {
        // Un créneau par salle : celui du premier jour de passage (les inscriptions y sont rattachées).
        $rooms = $this->fetchAll(
            "SELECT e.id AS id_emploi_du_temps, e.id_annee, e.jour_semaine, e.statut,
                    (SELECT GROUP_CONCAT(e3.jour_semaine ORDER BY e3.jour_semaine) FROM emplois_du_temps e3
                      WHERE e3.id_groupe = e.id_groupe AND e3.id_salle = e.id_salle AND e3.statut = 1) AS jours,
                    sa.id AS id_salle, sa.code AS salle_code, sa.libelle AS salle_libelle,
                    sa.capacite AS salle_capacite, sa.statut AS salle_statut,
                    f.id AS id_formateur, f.matricule AS formateur_matricule,
                    f.nom AS formateur_nom, f.prenom AS formateur_prenom,
                    (SELECT COUNT(*) FROM inscriptions i JOIN emplois_du_temps e2 ON e2.id = i.id_emploi_du_temps
                      WHERE e2.id_groupe = e.id_groupe AND e2.id_salle = e.id_salle AND i.statut = 'active') AS inscrits
             FROM emplois_du_temps e
             JOIN salles sa ON sa.id = e.id_salle
             JOIN v_formateurs f ON f.id = e.id_formateur
             WHERE e.id_groupe = :id
               AND e.id = (SELECT e4.id FROM emplois_du_temps e4 WHERE e4.id_groupe = e.id_groupe AND e4.id_salle = e.id_salle
                           ORDER BY e4.statut DESC, e4.jour_semaine, e4.id LIMIT 1)
             ORDER BY sa.code",
            ['id' => $id]
        );

        foreach ($rooms as &$room) {
            $room['inscrits'] = (int) $room['inscrits'];
            $room['places_restantes'] = $room['salle_capacite'] === null
                ? null
                : max(0, (int) $room['salle_capacite'] - $room['inscrits']);
            $room['auditeurs'] = $this->fetchAll(
                "SELECT i.id AS id_inscription, a.id AS id_auditeur, a.matricule, a.nom, a.prenom,
                        po.libelle AS position, t.libelle AS type_employe_libelle
                 FROM inscriptions i
                 JOIN auditeurs a ON a.id = i.id_auditeur
                 LEFT JOIN types_employes t ON t.id = a.id_type_employe
                 LEFT JOIN positions po ON po.id = a.id_position
                 JOIN emplois_du_temps ei ON ei.id = i.id_emploi_du_temps
                 WHERE ei.id_groupe = :groupe AND ei.id_salle = :salle AND i.statut = 'active'
                 ORDER BY a.nom, a.prenom",
                ['groupe' => $id, 'salle' => (int) $room['id_salle']]
            );
        }
        unset($room);

        return $rooms;
    }

    /**
     * Met à jour un groupe. Si son jour de cours (premier passage) ou ses horaires changent, ses créneaux
     * (salles) suivent automatiquement, après vérification des salles et formateurs sur le nouveau créneau.
     * @throws DomainException si une salle ou un formateur est déjà occupé, ou si le jour est déjà un passage.
     */
    public function updateWithSchedules(int $id, array $data): bool
    {
        require_once __DIR__ . '/Planification.php';
        $current = $this->find($id);
        if ($current === null) {
            return false;
        }
        $oldDay = (int) $current['jour_semaine'];
        $newDay = isset($data['jour_semaine']) ? (int) $data['jour_semaine'] : $oldDay;
        $newStart = $data['heure_debut'] ?? $current['heure_debut'];
        $newEnd = $data['heure_fin'] ?? $current['heure_fin'];
        $timeChanged = $newStart !== $current['heure_debut'] || $newEnd !== $current['heure_fin'];
        $planning = new Planification();

        $planning->acquireLock();
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            $rows = $this->fetchAll('SELECT * FROM emplois_du_temps WHERE id_groupe = :id AND statut = 1', ['id' => $id]);
            $ownIds = array_map(static fn($r) => (int) $r['id'], $rows);
            if ($newDay !== $oldDay && in_array($newDay, array_map(static fn($r) => (int) $r['jour_semaine'], $rows), true)) {
                throw new DomainException('Ce groupe a déjà un passage ce jour-là : choisissez un autre jour ou modifiez ses passages.');
            }

            foreach ($rows as $row) {
                $day = (int) $row['jour_semaine'] === $oldDay ? $newDay : (int) $row['jour_semaine'];
                if ($day === (int) $row['jour_semaine'] && !$timeChanged) {
                    continue;
                }
                $slot = [
                    'id_session' => (int) $row['id_session'], 'id_groupe' => $id, 'id_salle' => (int) $row['id_salle'],
                    'id_formateur' => (int) $row['id_formateur'], 'jour_semaine' => $day,
                    'heure_debut' => $timeChanged ? $newStart : $row['heure_debut'], 'heure_fin' => $timeChanged ? $newEnd : $row['heure_fin'],
                ];
                $conflicts = array_filter($planning->slotConflicts($slot, (int) $row['id']), static fn($c) => !in_array($c['id'], $ownIds, true) && $c['type'] !== 'salle_dediee');
                if ($conflicts !== []) {
                    throw new DomainException('Impossible de déplacer le groupe : ' . implode(' ', array_map(static fn($c) => $c['message'], $conflicts)));
                }
                $this->db->prepare('UPDATE emplois_du_temps SET jour_semaine = :j, heure_debut = :d, heure_fin = :f WHERE id = :id')
                    ->execute(['j' => $day, 'd' => $slot['heure_debut'], 'f' => $slot['heure_fin'], 'id' => (int) $row['id']]);
            }

            $success = $this->update($id, $data);
            $this->db->prepare('UPDATE groupe_passages SET jour_semaine = :new WHERE id_groupe = :id AND jour_semaine = :old')
                ->execute(['new' => $newDay, 'id' => $id, 'old' => $oldDay]);
            if ($timeChanged) {
                $this->db->prepare('UPDATE groupe_passages SET heure_debut = :d, heure_fin = :f WHERE id_groupe = :id')
                    ->execute(['d' => $newStart, 'f' => $newEnd, 'id' => $id]);
            }
            $this->db->prepare(
                'INSERT IGNORE INTO groupe_passages (id_groupe, jour_semaine, heure_debut, heure_fin, ordre) VALUES (:id, :j, :d, :f, 1)'
            )->execute(['id' => $id, 'j' => $newDay, 'd' => $newStart, 'f' => $newEnd]);

            if ($ownTx) {

                $this->db->commit();

            }
            return $success;
        } catch (PDOException $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ((string) $e->getCode() === '23000' && strpos($e->getMessage(), 'uk_edt_') !== false) {
                throw new DomainException(
                    'Impossible de changer le jour : une salle ou un formateur de ce groupe est déjà occupé ce jour-là.'
                );
            }
            throw $e;
        } catch (Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        } finally {
            $planning->releaseLock();
        }
    }

    /**
     * Places restantes = minimum entre la capacité du groupe et la somme
     * des places encore libres dans les salles qui lui sont affectées.
     * Retourne null si aucune capacité n'est définie.
     */
    public function getPlacesRestantes(int $id): ?int
    {
        $row = $this->fetchOne(
            "SELECT g.capacite,
                    (SELECT COUNT(*) FROM inscriptions i
                      JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
                      WHERE e.id_groupe = g.id AND i.statut = 'active') AS inscrits
             FROM {$this->table} g
             WHERE g.id = :id",
            ['id' => $id]
        );
        if ($row === null) {
            return null;
        }

        $roomSeats = $this->fetchOne(
            "SELECT
                SUM(CASE WHEN sa.capacite IS NULL THEN NULL ELSE sa.capacite END) AS capacite_salles,
                SUM(CASE WHEN sa.capacite IS NULL THEN NULL ELSE
                    GREATEST(sa.capacite - (
                        SELECT COUNT(*) FROM inscriptions i
                        WHERE i.id_emploi_du_temps = e.id AND i.statut = 'active'
                    ), 0)
                END) AS places_salles,
                COUNT(e.id) AS salles_affectees
             FROM emplois_du_temps e
             JOIN salles sa ON sa.id = e.id_salle
             WHERE e.id_groupe = :id AND e.statut = 1",
            ['id' => $id]
        );

        $groupRemaining = $row['capacite'] === null ? null : (int) $row['capacite'] - (int) $row['inscrits'];
        $roomRemaining = ($roomSeats && $roomSeats['places_salles'] !== null)
            ? (int) $roomSeats['places_salles']
            : null;

        if ($groupRemaining === null && $roomRemaining === null) {
            return null;
        }
        if ($groupRemaining === null) {
            return $roomRemaining;
        }
        if ($roomRemaining === null) {
            return $groupRemaining;
        }
        return min($groupRemaining, $roomRemaining);
    }
}
