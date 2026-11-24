<?php

require_once __DIR__ . '/Model.php';
require_once __DIR__ . '/Planification.php';

final class AffectationPonctuelle extends Model
{
    protected string $table = 'affectations_ponctuelles';

    protected array $fillable = [
        'id_emploi_du_temps', 'id_formateur', 'id_salle', 'date_affectation', 'motif', 'created_by',
    ];

    public function getAssignments(?int $trainerId = null): array
    {
        $sql = $this->detailsQuery();
        $params = [];
        if ($trainerId !== null) {
            $sql .= ' WHERE ap.id_formateur = :trainer_id';
            $params['trainer_id'] = $trainerId;
        }
        $sql .= ' ORDER BY ap.date_affectation DESC, ap.id DESC';
        return $this->fetchAll($sql, $params);
    }

    public function saveValidated(array $data, ?int $id = null): int
    {
        return $this->withPlanningLock(function () use ($data, $id): int {
            $error = $this->validateAssignment($data, $id);
            if ($error !== null) {
                throw new DomainException($error, 409);
            }
            if ($id === null) {
                return $this->create($data);
            }
            $this->update($id, $data);
            return $id;
        });
    }

    public function deleteLocked(int $id): bool
    {
        return $this->withPlanningLock(fn(): bool => $this->delete($id));
    }

    private function validateAssignment(array $data, ?int $excludeId): ?string
    {
        $scheduleId = (int) ($data['id_emploi_du_temps'] ?? 0);
        $trainerId = (int) ($data['id_formateur'] ?? 0);
        $roomId = (int) ($data['id_salle'] ?? 0);
        $date = (string) ($data['date_affectation'] ?? '');
        $dateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($dateValue === false || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))
            || $dateValue->format('Y-m-d') !== $date
        ) {
            return 'La date d’affectation doit respecter le format AAAA-MM-JJ.';
        }
        if ($scheduleId <= 0 || $trainerId <= 0 || $roomId <= 0) {
            return 'La classe, le formateur et la salle sont obligatoires.';
        }
        if (trim((string) ($data['motif'] ?? '')) === '') {
            return 'Le motif de l’affectation ponctuelle est obligatoire.';
        }

        $schedule = $this->fetchOne(
            "SELECT e.id, e.id_annee, e.id_session, e.id_groupe, e.id_salle, e.id_formateur,
                    e.jour_semaine, e.heure_debut, e.heure_fin, e.statut,
                    g.statut AS groupe_statut, s.statut AS session_statut,
                    s.date_debut AS session_debut, s.date_fin AS session_fin,
                    s.id_type_formation, an.date_debut AS annee_debut, an.date_fin AS annee_fin
             FROM emplois_du_temps e
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = e.id_session AND s.id = g.id_session
             JOIN annees_scolaires an ON an.id = e.id_annee
             WHERE e.id = :id LIMIT 1",
            ['id' => $scheduleId]
        );
        if ($schedule === null || (int) $schedule['statut'] !== 1 || (int) $schedule['groupe_statut'] !== 1) {
            return 'La classe sélectionnée ne possède pas de créneau hebdomadaire actif.';
        }
        if (!in_array($schedule['session_statut'], ['planifiee', 'ouverte', 'en_cours'], true)) {
            return 'La session de cette classe n’est pas ouverte à une affectation.';
        }
        if ($dateValue->format('N') !== (string) $schedule['jour_semaine']) {
            return 'La date choisie ne correspond pas au jour habituel de cette classe.';
        }
        if ($date < $schedule['session_debut'] || $date > $schedule['session_fin']
            || $date < $schedule['annee_debut'] || $date > $schedule['annee_fin']
        ) {
            return 'La date doit se trouver dans la période de la session et de l’année scolaire.';
        }
        if ($trainerId === (int) $schedule['id_formateur'] && $roomId === (int) $schedule['id_salle']) {
            return 'Choisissez un formateur ou une salle différente du créneau habituel.';
        }

        $trainer = $this->fetchOne(
            'SELECT id, actif FROM v_formateurs WHERE id = :id LIMIT 1',
            ['id' => $trainerId]
        );
        if ($trainer === null || !(bool) $trainer['actif']) {
            return 'Le formateur ponctuel est introuvable ou inactif.';
        }
        $room = $this->fetchOne(
            'SELECT id, statut, capacite FROM salles WHERE id = :id LIMIT 1',
            ['id' => $roomId]
        );
        if ($room === null || $room['statut'] !== 'disponible') {
            return 'La salle choisie est introuvable ou indisponible.';
        }

        $enrollmentCount = (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM inscriptions
             WHERE id_emploi_du_temps = :schedule_id AND statut = 'active'",
            ['schedule_id' => $scheduleId]
        );
        if ($room['capacite'] !== null && $enrollmentCount > (int) $room['capacite']) {
            return 'La capacité de la salle est inférieure à l’effectif inscrit dans cette classe.';
        }

        $dedicatedRoomsCount = (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM formation_salles WHERE id_type_formation = :formation_id',
            ['formation_id' => (int) $schedule['id_type_formation']]
        );
        $roomAllowed = $dedicatedRoomsCount > 0
            ? (int) $this->fetchColumn(
                'SELECT COUNT(*) FROM formation_salles WHERE id_type_formation = :formation_id AND id_salle = :room_id',
                ['formation_id' => (int) $schedule['id_type_formation'], 'room_id' => $roomId]
            ) > 0
            : (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM formation_salles WHERE id_salle = :room_id AND mode = 'dediee'",
                ['room_id' => $roomId]
            ) === 0;
        if (!$roomAllowed) {
            return 'Cette salle n’est pas autorisée pour le type de formation de la classe.';
        }

        foreach (['id_formateur' => $trainerId, 'id_salle' => $roomId] as $column => $resourceId) {
            $conflict = $this->fetchOne(
                "SELECT e.id
                 FROM emplois_du_temps e
                 JOIN sessions s ON s.id = e.id_session
                 WHERE e.{$column} = :resource_id AND e.statut = 1
                   AND e.jour_semaine = :weekday AND e.id <> :source_id
                   AND s.statut IN ('planifiee', 'ouverte', 'en_cours')
                   AND s.date_debut <= :date_start AND s.date_fin >= :date_end
                   AND e.heure_debut < :end_time AND e.heure_fin > :start_time
                 LIMIT 1",
                [
                    'resource_id' => $resourceId,
                    'weekday' => (int) $schedule['jour_semaine'],
                    'source_id' => $scheduleId,
                    'date_start' => $date,
                    'date_end' => $date,
                    'end_time' => $schedule['heure_fin'],
                    'start_time' => $schedule['heure_debut'],
                ]
            );
            if ($conflict !== null) {
                return $column === 'id_formateur'
                    ? 'Ce formateur a déjà un cours à cette date et sur cette plage horaire.'
                    : 'Cette salle est déjà réservée à cette date et sur cette plage horaire.';
            }
        }

        $punctualConflict = $this->fetchOne(
            "SELECT ap.id
             FROM affectations_ponctuelles ap
             JOIN emplois_du_temps e ON e.id = ap.id_emploi_du_temps
             WHERE ap.date_affectation = :date_value AND ap.id <> :exclude_id
               AND (ap.id_formateur = :trainer_id OR ap.id_salle = :room_id)
               AND e.heure_debut < :end_time AND e.heure_fin > :start_time
             LIMIT 1",
            [
                'date_value' => $date,
                'exclude_id' => $excludeId ?? 0,
                'trainer_id' => $trainerId,
                'room_id' => $roomId,
                'end_time' => $schedule['heure_fin'],
                'start_time' => $schedule['heure_debut'],
            ]
        );
        if ($punctualConflict !== null) {
            return 'Le formateur ou la salle est déjà réservé(e) par une autre affectation ponctuelle à cette heure.';
        }

        $duplicate = $this->fetchOne(
            'SELECT id FROM affectations_ponctuelles
             WHERE id_emploi_du_temps = :schedule_id AND date_affectation = :date_value AND id <> :exclude_id
             LIMIT 1',
            ['schedule_id' => $scheduleId, 'date_value' => $date, 'exclude_id' => $excludeId ?? 0]
        );
        return $duplicate === null ? null : 'Une affectation ponctuelle existe déjà pour cette classe à cette date.';
    }

    private function detailsQuery(): string
    {
        return "SELECT ap.*,
                       e.id_annee, e.id_session, e.id_groupe, e.jour_semaine,
                       e.heure_debut, e.heure_fin, e.id_formateur AS id_formateur_titulaire,
                       e.id_salle AS id_salle_habituelle,
                       g.code AS groupe_code, g.libelle AS groupe_libelle,
                       s.code AS session_code, s.libelle AS session_libelle,
                       s.date_debut AS session_debut, s.date_fin AS session_fin,
                       tf.libelle AS formation_libelle, n.libelle AS niveau_libelle,
                       an.libelle AS annee_libelle,
                       CONCAT(ft.prenom, ' ', ft.nom) AS formateur_nom,
                       ft.matricule AS formateur_matricule,
                       CONCAT(fh.prenom, ' ', fh.nom) AS formateur_habituel_nom,
                       sa.libelle AS salle_libelle,
                       sa.code AS salle_code, sa.capacite AS salle_capacite,
                       sh.code AS salle_habituelle_code, sh.libelle AS salle_habituelle_libelle,
                       sh.capacite AS salle_habituelle_capacite,
                       (SELECT COUNT(*) FROM inscriptions i
                        WHERE i.id_emploi_du_temps = e.id AND i.statut = 'active') AS effectif
                FROM affectations_ponctuelles ap
                JOIN emplois_du_temps e ON e.id = ap.id_emploi_du_temps
                JOIN groupes g ON g.id = e.id_groupe
                JOIN sessions s ON s.id = e.id_session
                JOIN annees_scolaires an ON an.id = e.id_annee
                JOIN types_formations tf ON tf.id = s.id_type_formation
                JOIN niveaux n ON n.id = s.id_niveau
                JOIN v_formateurs ft ON ft.id = ap.id_formateur
                JOIN v_formateurs fh ON fh.id = e.id_formateur
                JOIN salles sa ON sa.id = ap.id_salle
                JOIN salles sh ON sh.id = e.id_salle";
    }

    private function withPlanningLock(callable $operation): mixed
    {
        $lock = new Planification();
        $lock->acquireLock();
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $result = $operation();
            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        } finally {
            $lock->releaseLock();
        }
    }
}
