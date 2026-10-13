<?php
/**
 * Rappels : points importants à traiter, calculés à partir des données réelles.
 *
 * - Administrateur / rôles avec droits : vue complète.
 * - Formateur : uniquement ses salles, groupes et auditeurs.
 * Chaque rappel : { id, type, severite, titre, message, total, cible } où `cible` est
 * l'onglet de l'application à ouvrir pour traiter le point.
 */

require_once __DIR__ . '/Model.php';
require_once __DIR__ . '/Presence.php';

class Rappel extends Model
{
    public function getRappels(array $user): array
    {
        $isAdmin = $user['role_code'] === 'admin';
        $trainerId = $user['role_code'] === 'formateur' ? (int) ($user['formateur_id'] ?? 0) : null;
        $can = static fn(string $module): bool => $isAdmin || $trainerId !== null
            || in_array('read', $user['permissions'][$module] ?? [], true);
        $today = date('Y-m-d');
        $reminders = [];
        $push = static function (string $id, string $type, string $severity, string $title, string $message, int $total, string $target) use (&$reminders): void {
            if ($total > 0) {
                $reminders[] = compact('id', 'type', 'title', 'message', 'total', 'target') + ['severite' => $severity, 'cible' => $target];
            }
        };

        // Scope formateur sur les créneaux (alias e)
        $edtScope = $trainerId !== null ? ' AND e.id_formateur = :trainer' : '';
        $scopeParams = $trainerId !== null ? ['trainer' => $trainerId] : [];

        // 1. Pointage du jour
        if ($can('presences')) {
            $sheet = (new Presence())->getFeuille($today, $trainerId !== null ? ['id_formateur' => $trainerId] : []);
            $pending = count(array_filter($sheet['auditeurs'], static fn($row) => $row['statut'] === null));
            $push('pointage-du-jour', 'presences', 'attention', 'Pointage du jour à compléter',
                "{$pending} auditeur(s) attendu(s) aujourd’hui n’ont pas encore été pointés.", $pending, 'presences');
        }

        // 2. Notes manquantes pour des évaluations déjà passées
        if ($can('evaluations')) {
            $missing = $this->fetchAll(
                "SELECT ev.id, ev.numero, s.code AS session_code,
                        COUNT(DISTINCT i.id) * (SELECT COUNT(*) FROM evaluation_parametres ep WHERE ep.id_evaluation = ev.id) AS attendues,
                        (SELECT COUNT(*) FROM notes n JOIN inscriptions i2 ON i2.id = n.id_inscription
                           JOIN emplois_du_temps e2 ON e2.id = i2.id_emploi_du_temps
                          WHERE n.id_evaluation = ev.id AND n.note IS NOT NULL
                            AND i2.statut IN ('active','terminee')"
                . ($trainerId !== null ? ' AND e2.id_formateur = :trainer2' : '') . ") AS saisies
                 FROM evaluations ev
                 JOIN sessions s ON s.id = ev.id_session
                 JOIN inscriptions i ON i.id_session = s.id AND i.statut IN ('active','terminee')
                 JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
                 WHERE ev.date_evaluation <= :today {$edtScope}
                 GROUP BY ev.id, ev.numero, s.code",
                ['today' => $today] + $scopeParams + ($trainerId !== null ? ['trainer2' => $trainerId] : [])
            );
            $total = 0;
            foreach ($missing as $row) {
                $total += max(0, (int) $row['attendues'] - (int) $row['saisies']);
            }
            $push('notes-manquantes', 'notes', 'attention', 'Notes à saisir',
                "{$total} note(s) manquent pour des évaluations déjà passées.", $total, 'evaluations');
        }

        // 3. Évaluations dans les 7 prochains jours
        if ($can('evaluations')) {
            $upcoming = (int) $this->fetchColumn(
                "SELECT COUNT(DISTINCT ev.id)
                 FROM evaluations ev
                 JOIN sessions s ON s.id = ev.id_session
                 JOIN groupes g ON g.id_session = s.id
                 JOIN emplois_du_temps e ON e.id_groupe = g.id AND e.statut = 1
                 WHERE ev.date_evaluation BETWEEN :from_d AND DATE_ADD(:from_d2, INTERVAL 7 DAY) {$edtScope}",
                ['from_d' => $today, 'from_d2' => $today] + $scopeParams
            );
            $push('evaluations-proches', 'evaluations', 'information', 'Évaluations cette semaine',
                "{$upcoming} évaluation(s) sont prévues dans les 7 prochains jours.", $upcoming, 'evaluations');
        }

        // 4. Sessions qui se terminent bientôt avec des passages non validés
        if ($can('evaluations')) {
            $ending = $this->fetchAll(
                "SELECT s.id, s.code,
                        COUNT(DISTINCT i.id) AS inscrits,
                        COUNT(DISTINCT pn.id) AS decisions
                 FROM sessions s
                 JOIN inscriptions i ON i.id_session = s.id AND i.statut = 'active'
                 JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
                 LEFT JOIN passages_niveaux pn ON pn.id_inscription = i.id
                 WHERE s.statut IN ('ouverte','en_cours')
                   AND s.date_fin BETWEEN :today AND DATE_ADD(:today2, INTERVAL 7 DAY) {$edtScope}
                 GROUP BY s.id, s.code
                 HAVING decisions < inscrits",
                ['today' => $today, 'today2' => $today] + $scopeParams
            );
            $push('sessions-fin-proche', 'passages', 'attention', 'Sessions bientôt terminées',
                count($ending) . ' session(s) se terminent dans 7 jours ou moins : pensez à valider les passages de niveau (' . implode(', ', array_column($ending, 'code')) . ').',
                count($ending), 'evaluations');
        }

        // 5. Points d'organisation (administration uniquement)
        if ($isAdmin || in_array('read', $user['permissions']['organisation-pedagogique'] ?? [], true)) {
            if ($trainerId === null) {
                $noRoom = (int) $this->fetchColumn(
                    "SELECT COUNT(*) FROM groupes g JOIN sessions s ON s.id = g.id_session
                     WHERE g.statut = 1 AND s.statut IN ('planifiee','ouverte','en_cours')
                       AND NOT EXISTS (SELECT 1 FROM emplois_du_temps e WHERE e.id_groupe = g.id AND e.statut = 1)"
                );
                $push('groupes-sans-salle', 'organisation', 'attention', 'Groupes sans salle',
                    "{$noRoom} groupe(s) actif(s) n’ont aucune salle affectée : aucun auditeur ne peut y être inscrit.", $noRoom, 'emplois-temps');

                $incomplete = (int) $this->fetchColumn(
                    "SELECT COUNT(*) FROM sessions s
                     WHERE s.statut IN ('planifiee','ouverte','en_cours')
                       AND (SELECT COUNT(*) FROM evaluations ev WHERE ev.id_session = s.id)
                           < COALESCE((SELECT r.nb_evaluations FROM regles_evaluation r WHERE r.id_niveau = s.id_niveau),
                                      (SELECT r.nb_evaluations FROM regles_evaluation r WHERE r.id = 1), 3)"
                );
                $push('evaluations-incompletes', 'organisation', 'information', 'Évaluations à planifier',
                    "{$incomplete} session(s) n’ont pas toutes leurs évaluations planifiées.", $incomplete, 'evaluations');

                $overCapacity = (int) $this->fetchColumn(
                    "SELECT COUNT(*) FROM (
                        SELECT e.id FROM emplois_du_temps e
                        JOIN salles sa ON sa.id = e.id_salle
                        JOIN inscriptions i ON i.id_emploi_du_temps = e.id AND i.statut = 'active'
                        WHERE e.statut = 1 AND sa.capacite IS NOT NULL
                        GROUP BY e.id, sa.capacite HAVING COUNT(i.id) > sa.capacite) x"
                );
                $push('salles-surchargees', 'organisation', 'attention', 'Salles au-delà de leur capacité',
                    "{$overCapacity} salle(s) accueillent plus d’auditeurs que leur capacité.", $overCapacity, 'salles');
            }
        }

        $order = ['attention' => 0, 'information' => 1];
        usort($reminders, static fn($a, $b) => ($order[$a['severite']] ?? 2) <=> ($order[$b['severite']] ?? 2));
        return ['date' => $today, 'rappels' => $reminders, 'total' => array_sum(array_column($reminders, 'total'))];
    }
}
