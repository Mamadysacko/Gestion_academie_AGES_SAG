<?php
/**
 * Résultats d'une session — source unique des moyennes, appréciations et décisions.
 *
 * Calculs (moyennes ramenées sur 4) :
 * - moyenne d'une évaluation  = Σ notes / Σ barèmes × 4, uniquement si tous ses critères sont notés ;
 * - moyenne générale          = moyenne des évaluations de la session, uniquement lorsque les
 *                               N évaluations prévues (regles_evaluation.nb_evaluations) sont complètes ;
 * - appréciation (1 à 4)      = note du barème dont le seuil est atteint (baremes_appreciation) ;
 * - résultat                  = admis / non_admis selon la moyenne d'admission et le taux de présence
 *                               minimal, « en_attente » tant que la moyenne générale n'est pas calculable.
 */

require_once __DIR__ . '/Model.php';
require_once __DIR__ . '/ReglesEvaluation.php';

class Resultat extends Model
{
    protected string $table = 'passages_niveaux';

    /**
     * Résultats d'une session.
     * @param array $filters id_groupe, id_salle, id_formateur, id_auditeur (facultatifs)
     */
    public function getSessionResults(int $sessionId, array $filters = []): array
    {
        $session = $this->fetchOne(
            "SELECT s.id, s.code, s.libelle, s.statut, s.date_debut, s.date_fin, s.id_annee, s.id_niveau,
                    an.libelle AS annee_libelle, n.libelle AS niveau_libelle, n.ordre AS niveau_ordre,
                    tf.libelle AS formation_libelle
             FROM sessions s
             JOIN annees_scolaires an ON an.id = s.id_annee
             JOIN niveaux n ON n.id = s.id_niveau
             LEFT JOIN types_formations tf ON tf.id = s.id_type_formation
             WHERE s.id = :id",
            ['id' => $sessionId]
        );
        if ($session === null) {
            throw new InvalidArgumentException('Session introuvable.');
        }

        $reglesModel = new ReglesEvaluation();
        $regles = $reglesModel->getRegles((int) $session['id_niveau']);
        $bareme = $reglesModel->getBareme();
        $nextLevel = $this->nextLevel((int) $session['niveau_ordre'], (int) $session['id_niveau']);

        $evaluations = $this->fetchAll(
            'SELECT id, numero, libelle, date_evaluation FROM evaluations WHERE id_session = :id ORDER BY numero',
            ['id' => $sessionId]
        );
        $criteriaByEvaluation = [];
        if ($evaluations) {
            $ids = array_map(static fn($e) => (int) $e['id'], $evaluations);
            foreach ($this->fetchAll(
                'SELECT ep.id_evaluation, p.id, p.libelle, p.bareme, ep.ordre
                 FROM evaluation_parametres ep JOIN parametres p ON p.id = ep.id_parametre
                 WHERE ep.id_evaluation IN (' . implode(',', $ids) . ')
                 ORDER BY ep.ordre, p.libelle'
            ) as $criterion) {
                $criteriaByEvaluation[(int) $criterion['id_evaluation']][] = $criterion;
            }
        }

        [$where, $params] = $this->scope($sessionId, $filters);
        $enrollments = $this->fetchAll(
            "SELECT i.id AS id_inscription, i.statut AS inscription_statut, i.date_inscription,
                    i.id_auditeur, a.matricule AS auditeur_matricule, a.nom AS auditeur_nom, a.prenom AS auditeur_prenom,
                    a.position AS auditeur_position, te.libelle AS type_employe_libelle, dp.libelle AS departement_libelle,
                    i.id_emploi_du_temps, e.id_groupe, g.code AS groupe_code, g.libelle AS groupe_libelle,
                    e.id_salle, sa.code AS salle_code, sa.libelle AS salle_libelle,
                    e.id_formateur, f.nom AS formateur_nom, f.prenom AS formateur_prenom
             FROM inscriptions i
             JOIN auditeurs a ON a.id = i.id_auditeur
             LEFT JOIN types_employes te ON te.id = a.id_type_employe
             JOIN departements dp ON dp.id = i.id_departement
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN groupes g ON g.id = e.id_groupe
             JOIN salles sa ON sa.id = e.id_salle
             JOIN formateurs f ON f.id = e.id_formateur
             WHERE {$where}
             ORDER BY g.code, sa.code, a.nom, a.prenom",
            $params
        );

        $notes = [];
        $attendance = [];
        $passages = [];
        if ($enrollments) {
            $inscriptionIds = implode(',', array_map(static fn($r) => (int) $r['id_inscription'], $enrollments));
            foreach ($this->fetchAll(
                "SELECT id_inscription, id_evaluation, id_parametre, note FROM notes
                 WHERE id_inscription IN ({$inscriptionIds}) AND note IS NOT NULL"
            ) as $note) {
                $notes[(int) $note['id_inscription']][(int) $note['id_evaluation']][(int) $note['id_parametre']] = (float) $note['note'];
            }
            foreach ($this->fetchAll(
                "SELECT id_inscription, COUNT(*) AS total, SUM(statut = 'present') AS present,
                        SUM(statut = 'absent') AS absent
                 FROM presences WHERE id_inscription IN ({$inscriptionIds}) GROUP BY id_inscription"
            ) as $row) {
                $attendance[(int) $row['id_inscription']] = $row;
            }
            foreach ($this->fetchAll(
                "SELECT p.*, na.libelle AS niveau_arrivee_libelle, nd.libelle AS niveau_depart_libelle
                 FROM passages_niveaux p
                 JOIN niveaux nd ON nd.id = p.niveau_depart
                 LEFT JOIN niveaux na ON na.id = p.niveau_arrivee
                 WHERE p.id_inscription IN ({$inscriptionIds})"
            ) as $row) {
                $passages[(int) $row['id_inscription']] = $row;
            }
        }

        $rows = [];
        foreach ($enrollments as $enrollment) {
            $id = (int) $enrollment['id_inscription'];
            $evaluationResults = [];
            $saisies = 0;
            $attendues = 0;
            $completeAverages = [];
            foreach ($evaluations as $evaluation) {
                $evaluationId = (int) $evaluation['id'];
                $criteria = $criteriaByEvaluation[$evaluationId] ?? [];
                $points = 0.0;
                $max = 0.0;
                $count = 0;
                foreach ($criteria as $criterion) {
                    $value = $notes[$id][$evaluationId][(int) $criterion['id']] ?? null;
                    if ($value !== null) {
                        $points += $value;
                        $max += (float) $criterion['bareme'];
                        $count++;
                    }
                }
                $complete = $criteria !== [] && $count === count($criteria);
                $average = $max > 0 ? round($points / $max * 4, 2) : null;
                $saisies += $count;
                $attendues += count($criteria);
                if ($complete) {
                    $completeAverages[] = $average;
                }
                $evaluationResults[] = [
                    'id_evaluation' => $evaluationId,
                    'numero' => (int) $evaluation['numero'],
                    'notes_saisies' => $count,
                    'notes_attendues' => count($criteria),
                    'complete' => $complete,
                    'moyenne' => $complete ? $average : null,
                    'moyenne_partielle' => $complete ? null : $average,
                    'appreciation' => ReglesEvaluation::appreciationFor($complete ? $average : null, $bareme),
                ];
            }

            $allPlanned = count($evaluations) >= $regles['nb_evaluations'];
            $generalAverage = ($allPlanned && count($completeAverages) === count($evaluations) && $completeAverages !== [])
                ? round(array_sum($completeAverages) / count($completeAverages), 2)
                : null;

            $presence = $attendance[$id] ?? null;
            $presenceTotal = (int) ($presence['total'] ?? 0);
            $presenceRate = $presenceTotal > 0 ? round(((int) $presence['present']) / $presenceTotal * 100, 1) : null;

            [$status, $reason] = $this->decide(
                $enrollment['inscription_statut'], $generalAverage, $presenceRate, $regles, $evaluations, $evaluationResults
            );
            $passage = $passages[$id] ?? null;

            $rows[] = $enrollment + [
                'evaluations' => $evaluationResults,
                'notes_saisies' => $saisies,
                'notes_attendues' => $attendues,
                'moyenne_generale' => $generalAverage,
                'appreciation_generale' => ReglesEvaluation::appreciationFor($generalAverage, $bareme),
                'presences' => [
                    'total' => $presenceTotal,
                    'present' => (int) ($presence['present'] ?? 0),
                    'absent' => (int) ($presence['absent'] ?? 0),
                    'taux' => $presenceRate,
                ],
                'resultat' => $status,
                'motif' => $reason,
                'niveau_propose' => $status === 'admis' ? $nextLevel : null,
                'passage' => $passage === null ? null : [
                    'decision' => $passage['decision'],
                    'niveau_depart_libelle' => $passage['niveau_depart_libelle'],
                    'niveau_arrivee' => $passage['niveau_arrivee'] === null ? null : (int) $passage['niveau_arrivee'],
                    'niveau_arrivee_libelle' => $passage['niveau_arrivee_libelle'],
                    'moyenne' => $passage['moyenne'] === null ? null : (float) $passage['moyenne'],
                    'appreciation' => $passage['appreciation'] === null ? null : (int) $passage['appreciation'],
                    'date_decision' => $passage['date_decision'],
                    'mode_validation' => $passage['mode_validation'],
                    'observation' => $passage['observation'],
                ],
            ];
        }

        return [
            'session' => $session + ['niveau_suivant' => $nextLevel],
            'regles' => $regles,
            'bareme' => $bareme,
            'evaluations' => array_map(static fn($e) => [
                'id' => (int) $e['id'],
                'numero' => (int) $e['numero'],
                'libelle' => $e['libelle'],
                'date_evaluation' => $e['date_evaluation'],
                'criteres' => array_map(static fn($c) => [
                    'id' => (int) $c['id'], 'libelle' => $c['libelle'], 'bareme' => (float) $c['bareme'],
                ], $criteriaByEvaluation[(int) $e['id']] ?? []),
            ], $evaluations),
            'evaluations_manquantes' => max(0, $regles['nb_evaluations'] - count($evaluations)),
            'auditeurs' => $rows,
            'synthese' => $this->summarize($rows, $evaluations),
        ];
    }

    /**
     * Enregistre les décisions de passage calculables d'une session et met à jour
     * le niveau actuel des auditeurs. Les résultats « en attente » sont ignorés.
     * En mode automatique, une décision saisie manuellement n'est jamais écrasée.
     * @return array{validees:int, admis:int, non_admis:int, abandonne:int, en_attente:int}
     */
    public function validerPassages(int $sessionId, string $mode = 'manuelle'): array
    {
        $results = $this->getSessionResults($sessionId);
        $session = $results['session'];
        $counts = ['validees' => 0, 'admis' => 0, 'non_admis' => 0, 'abandonne' => 0, 'en_attente' => 0];

        $upsert = $this->db->prepare(
            "INSERT INTO passages_niveaux
                (id_inscription, niveau_depart, niveau_arrivee, decision, moyenne, appreciation,
                 taux_presence, date_decision, observation, mode_validation)
             VALUES (:id_inscription, :depart, :arrivee, :decision, :moyenne, :appreciation,
                 :taux, CURDATE(), :observation, :mode)
             ON DUPLICATE KEY UPDATE
                niveau_depart = VALUES(niveau_depart), niveau_arrivee = VALUES(niveau_arrivee),
                decision = VALUES(decision), moyenne = VALUES(moyenne), appreciation = VALUES(appreciation),
                taux_presence = VALUES(taux_presence), date_decision = VALUES(date_decision),
                observation = VALUES(observation), mode_validation = VALUES(mode_validation)"
        );
        $updateLevel = $this->db->prepare('UPDATE auditeurs SET id_niveau = :niveau WHERE id = :id');

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            foreach ($results['auditeurs'] as $row) {
                $decision = ['admis' => 'admis', 'non_admis' => 'non_admis', 'abandonne' => 'abandonne'][$row['resultat']] ?? null;
                if ($decision === null) {
                    $counts['en_attente']++;
                    continue;
                }
                if ($mode === 'automatique' && $row['passage'] !== null && $row['passage']['mode_validation'] === 'manuelle') {
                    continue;
                }

                $arrivee = $decision === 'admis' ? ($session['niveau_suivant']['id'] ?? null) : null;
                $upsert->execute([
                    'id_inscription' => $row['id_inscription'],
                    'depart' => (int) $session['id_niveau'],
                    'arrivee' => $arrivee,
                    'decision' => $decision,
                    'moyenne' => $row['moyenne_generale'],
                    'appreciation' => $row['appreciation_generale']['note'] ?? null,
                    'taux' => $row['presences']['taux'],
                    'observation' => $decision === 'admis' && $arrivee === null ? 'Niveau final atteint.' : $row['motif'],
                    'mode' => $mode,
                ]);
                $updateLevel->execute([
                    'niveau' => $arrivee ?? (int) $session['id_niveau'],
                    'id' => (int) $row['id_auditeur'],
                ]);
                $counts['validees']++;
                $counts[$decision]++;
            }
            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $counts;
    }

    /** Parcours complet d'un auditeur : niveau actuel, sessions suivies, résultats et décisions. */
    public function getAuditeurParcours(int $auditeurId): array
    {
        $auditeur = $this->fetchOne(
            "SELECT a.id, a.matricule, a.nom, a.prenom, a.id_niveau, n.libelle AS niveau_libelle
             FROM auditeurs a LEFT JOIN niveaux n ON n.id = a.id_niveau WHERE a.id = :id",
            ['id' => $auditeurId]
        );
        if ($auditeur === null) {
            throw new InvalidArgumentException('Auditeur introuvable.');
        }

        $sessionIds = $this->fetchAll(
            "SELECT i.id_session, MAX(s.date_debut) AS debut FROM inscriptions i
             JOIN sessions s ON s.id = i.id_session
             WHERE i.id_auditeur = :id
             GROUP BY i.id_session
             ORDER BY debut DESC",
            ['id' => $auditeurId]
        );
        $parcours = [];
        foreach ($sessionIds as $row) {
            $results = $this->getSessionResults((int) $row['id_session'], ['id_auditeur' => $auditeurId, 'tous_statuts' => true]);
            foreach ($results['auditeurs'] as $result) {
                $parcours[] = [
                    'session' => $results['session'],
                    'evaluations' => $results['evaluations'],
                    'resultat' => $result,
                ];
            }
        }

        return ['auditeur' => $auditeur, 'parcours' => $parcours];
    }

    /* ------------------------------------------------------------------
     | Interne
     |------------------------------------------------------------------ */

    private function scope(int $sessionId, array $filters): array
    {
        $where = ['i.id_session = :id_session'];
        $params = ['id_session' => $sessionId];
        if (empty($filters['tous_statuts'])) {
            $where[] = "i.statut <> 'annulee'";
        }
        foreach (['id_groupe' => 'e.id_groupe', 'id_salle' => 'e.id_salle', 'id_formateur' => 'e.id_formateur', 'id_auditeur' => 'i.id_auditeur'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[] = "{$column} = :{$key}";
                $params[$key] = (int) $filters[$key];
            }
        }
        return [implode(' AND ', $where), $params];
    }

    private function nextLevel(int $ordre, int $currentId): ?array
    {
        $row = $this->fetchOne(
            'SELECT id, libelle FROM niveaux WHERE (ordre > :ordre OR (ordre = :ordre2 AND id > :id))
             ORDER BY ordre, id LIMIT 1',
            ['ordre' => $ordre, 'ordre2' => $ordre, 'id' => $currentId]
        );
        return $row === null ? null : ['id' => (int) $row['id'], 'libelle' => $row['libelle']];
    }

    /** @return array{0:string,1:?string} [résultat, motif] */
    private function decide(string $inscriptionStatus, ?float $average, ?float $presenceRate, array $regles, array $evaluations, array $evaluationResults): array
    {
        if ($inscriptionStatus === 'abandonnee') {
            return ['abandonne', 'Inscription abandonnée.'];
        }
        if ($average === null) {
            if (count($evaluations) < $regles['nb_evaluations']) {
                return ['en_attente', sprintf('%d évaluation(s) planifiée(s) sur %d.', count($evaluations), $regles['nb_evaluations'])];
            }
            $incomplete = array_map(
                static fn($e) => 'évaluation ' . $e['numero'],
                array_filter($evaluationResults, static fn($e) => !$e['complete'])
            );
            return ['en_attente', 'Notes manquantes : ' . implode(', ', $incomplete) . '.'];
        }
        if ($average + 1e-9 < $regles['moyenne_admission']) {
            return ['non_admis', sprintf('Moyenne %.2f inférieure au seuil %.2f.', $average, $regles['moyenne_admission'])];
        }
        if ($regles['taux_presence_min'] > 0 && ($presenceRate === null || $presenceRate < $regles['taux_presence_min'])) {
            return ['non_admis', sprintf(
                'Taux de présence %s inférieur au minimum %.0f %%.',
                $presenceRate === null ? 'non renseigné' : $presenceRate . ' %',
                $regles['taux_presence_min']
            )];
        }
        return ['admis', null];
    }

    private function summarize(array $rows, array $evaluations): array
    {
        $count = static fn(array $items, string $status): int => count(array_filter($items, static fn($r) => $r['resultat'] === $status));
        $average = static function (array $values): ?float {
            $values = array_values(array_filter($values, static fn($v) => $v !== null));
            return $values ? round(array_sum($values) / count($values), 2) : null;
        };
        $bucket = static function (array $items) use ($count, $average): array {
            return [
                'effectif' => count($items),
                'moyenne' => $average(array_column($items, 'moyenne_generale')),
                'admis' => $count($items, 'admis'),
                'non_admis' => $count($items, 'non_admis'),
                'en_attente' => $count($items, 'en_attente'),
                'notes_saisies' => array_sum(array_column($items, 'notes_saisies')),
                'notes_attendues' => array_sum(array_column($items, 'notes_attendues')),
            ];
        };
        $group = static function (array $items, string $key, string $code, string $label) use ($bucket): array {
            $groups = [];
            foreach ($items as $item) {
                $groups[$item[$key]]['items'][] = $item;
                $groups[$item[$key]]['meta'] = ['id' => (int) $item[$key], 'code' => $item[$code], 'libelle' => $item[$label]];
            }
            return array_values(array_map(static fn($g) => $g['meta'] + $bucket($g['items']), $groups));
        };

        $byEvaluation = [];
        foreach ($evaluations as $index => $evaluation) {
            $results = array_map(static fn($r) => $r['evaluations'][$index], $rows);
            $byEvaluation[] = [
                'id_evaluation' => (int) $evaluation['id'],
                'numero' => (int) $evaluation['numero'],
                'libelle' => $evaluation['libelle'],
                'moyenne' => $average(array_column($results, 'moyenne')),
                'complets' => count(array_filter($results, static fn($r) => $r['complete'])),
                'notes_saisies' => array_sum(array_column($results, 'notes_saisies')),
                'notes_attendues' => array_sum(array_column($results, 'notes_attendues')),
            ];
        }

        $appreciations = [];
        foreach ($rows as $row) {
            $note = $row['appreciation_generale']['note'] ?? null;
            if ($note !== null) {
                $appreciations[$note] = ($appreciations[$note] ?? 0) + 1;
            }
        }

        $total = $bucket($rows);
        return $total + [
            'notes_manquantes' => $total['notes_attendues'] - $total['notes_saisies'],
            'abandonne' => $count($rows, 'abandonne'),
            'decisions_validees' => count(array_filter($rows, static fn($r) => $r['passage'] !== null)),
            'par_evaluation' => $byEvaluation,
            'par_groupe' => $group($rows, 'id_groupe', 'groupe_code', 'groupe_libelle'),
            'par_salle' => $group($rows, 'id_salle', 'salle_code', 'salle_libelle'),
            'appreciations' => $appreciations,
        ];
    }
}
