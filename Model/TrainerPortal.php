<?php

require_once __DIR__ . '/Model.php';

final class TrainerPortal extends Model
{
    public function getDashboard(int $trainerId, array $permissions, ?string $attendanceDate = null): array
    {
        // When called for a trainer (trainer portal), allow viewing that trainer's own
        // schedules, learners, attendance and grades even if fine-grained permissions
        // are not present in the user payload. This ensures the trainer portal works
        // for linked trainer accounts without requiring every permission flag.
        $forceTrainerScope = $trainerId > 0;

        $canReadSchedules = $forceTrainerScope || in_array('read', $permissions['emplois-temps'] ?? [], true);
        $canReadLearners = $forceTrainerScope || in_array('read', $permissions['auditeurs'] ?? [], true);
        $canReadAttendance = $forceTrainerScope || in_array('read', $permissions['presences'] ?? [], true);
        $canWriteAttendance = $forceTrainerScope || (
            array_intersect(
                ['create', 'update'],
                $permissions['presences'] ?? []
            ) !== []
        );
        $canReadAttendanceRoster = $canReadLearners || $canWriteAttendance;
        $canReadGrades = $forceTrainerScope || in_array('read', $permissions['evaluations'] ?? [], true);

        $schedules = ($canReadSchedules || $canReadAttendanceRoster) ? $this->fetchAll(
            "SELECT e.id, e.id_annee, e.id_groupe, e.id_salle, e.jour_semaine, e.statut,
                    an.libelle AS annee_libelle, an.statut AS annee_active,
                    an.date_debut AS annee_date_debut, an.date_fin AS annee_date_fin,
                    g.code AS groupe_code, g.libelle AS groupe_libelle,
                    g.jour_semaine AS groupe_jour_semaine,
                    g.statut AS groupe_actif,
                    s.id AS id_session, s.code AS session_code, s.libelle AS session_libelle,
                    s.statut AS session_statut,
                    tf.libelle AS formation_libelle, n.libelle AS niveau_libelle,
                    sa.code AS salle_code, sa.libelle AS salle_libelle, sa.capacite AS salle_capacite
             FROM emplois_du_temps e
             JOIN annees_scolaires an ON an.id = e.id_annee
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             LEFT JOIN types_formations tf ON tf.id = s.id_type_formation
             LEFT JOIN niveaux n ON n.id = s.id_niveau
             JOIN salles sa ON sa.id = e.id_salle
             WHERE e.id_formateur = :trainer_id
             ORDER BY an.id DESC, e.jour_semaine, g.libelle",
            ['trainer_id' => $trainerId]
        ) : [];

        $learners = $canReadAttendanceRoster ? $this->fetchAll(
            "SELECT i.id AS id_inscription, i.id_emploi_du_temps, i.statut AS inscription_statut,
                    i.date_inscription, a.id AS id_auditeur, a.matricule AS auditeur_matricule,
                    a.nom AS auditeur_nom, a.prenom AS auditeur_prenom,
                    e.id_annee, e.id_groupe, e.id_salle, e.jour_semaine, e.id_formateur,
                    g.code AS groupe_code, g.libelle AS groupe_libelle,
                    g.jour_semaine AS groupe_jour_semaine,
                    sa.code AS salle_code, sa.libelle AS salle_libelle, sa.capacite AS salle_capacite
             FROM inscriptions i
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN groupes g ON g.id = e.id_groupe
             JOIN salles sa ON sa.id = e.id_salle
             JOIN auditeurs a ON a.id = i.id_auditeur
             WHERE e.id_formateur = ?
             ORDER BY a.nom, a.prenom",
            [$trainerId]
        ) : [];

        $attendance = [];
        if ($canReadAttendance) {
            $attendance = $this->fetchAll(
                "SELECT p.id, p.id_inscription, p.id_emploi_du_temps, e.id_annee, p.date_presence,
                        p.statut, p.observation,
                        a.nom AS auditeur_nom, a.prenom AS auditeur_prenom,
                        g.libelle AS groupe_libelle
                 FROM presences p
                 JOIN inscriptions i ON i.id = p.id_inscription
                 JOIN emplois_du_temps e ON e.id = p.id_emploi_du_temps
                 JOIN auditeurs a ON a.id = i.id_auditeur
                 JOIN groupes g ON g.id = e.id_groupe
                 WHERE e.id_formateur = ?
                 ORDER BY p.date_presence DESC, a.nom, a.prenom",
                [$trainerId]
            );
        } elseif ($canWriteAttendance && $attendanceDate !== null) {
            $attendance = $this->fetchAll(
                "SELECT p.id, p.id_inscription, p.id_emploi_du_temps, e.id_annee, p.date_presence,
                        p.statut, p.observation
                 FROM presences p
                 JOIN inscriptions i ON i.id = p.id_inscription
                 JOIN emplois_du_temps e ON e.id = p.id_emploi_du_temps
                 WHERE e.id_formateur = ? AND p.date_presence = ?
                 ORDER BY p.date_presence DESC",
                [$trainerId, $attendanceDate]
            );
        }

        $grades = $canReadGrades ? $this->fetchAll(
            "SELECT n.id_note, n.id_inscription, n.id_parametre, n.note, n.observation,
                    e.id AS id_emploi_du_temps, e.id_annee, ev.id AS id_evaluation,
                    ev.libelle AS evaluation_libelle, ev.date_evaluation,
                    p.libelle AS critere_libelle, p.bareme,
                    a.nom AS auditeur_nom, a.prenom AS auditeur_prenom,
                    g.libelle AS groupe_libelle
             FROM notes n
             JOIN parametres p ON p.id = n.id_parametre
             JOIN evaluations ev ON ev.id = n.id_evaluation
             JOIN inscriptions i ON i.id = n.id_inscription
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN auditeurs a ON a.id = i.id_auditeur
             JOIN groupes g ON g.id = e.id_groupe
             WHERE e.id_formateur = ?
             ORDER BY ev.date_evaluation DESC, a.nom, a.prenom, p.libelle",
            [$trainerId]
        ) : [];

        $evaluations = $this->fetchAll(
            "SELECT ev.id, ev.id_session, ev.numero, ev.libelle, ev.date_evaluation
             FROM evaluations ev
             WHERE ev.id_session IN (
                 SELECT DISTINCT g.id_session
                 FROM emplois_du_temps assigned
                 JOIN groupes g ON g.id = assigned.id_groupe
                 WHERE assigned.id_formateur = ?
             )
             ORDER BY ev.id_session, ev.numero",
            [$trainerId]
        );

        $criteria = $this->fetchAll(
            "SELECT ep.id_evaluation, p.id, p.libelle, p.bareme, ep.ordre
             FROM evaluation_parametres ep
             JOIN parametres p ON p.id = ep.id_parametre
             JOIN evaluations ev ON ev.id = ep.id_evaluation
             WHERE ev.id_session IN (
                 SELECT DISTINCT g.id_session
                 FROM emplois_du_temps assigned
                 JOIN groupes g ON g.id = assigned.id_groupe
                 WHERE assigned.id_formateur = ?
             )
             ORDER BY ep.ordre, p.libelle",
            [$trainerId]
        );

        return [
            'schedules' => $schedules,
            'learners' => $learners,
            'attendance' => $attendance,
            'grades' => $grades,
            'evaluations' => $evaluations,
            'criteria' => $criteria,
        ];
    }
}
