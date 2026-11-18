<?php
/**
 * Contrôleur pour la fiche de notes imprimable (destinée au professeur).
 *
 * GET /evaluations/{id}/fiche-notes → retourne JSON avec la liste des auditeurs
 *     et les paramètres d'évaluation pour générer une fiche de saisie.
 */

class FicheNotesController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Retourne les données nécessaires pour imprimer la fiche de notes :
     * - Infos de l'évaluation + session
     * - Paramètres d'évaluation (colonnes de la fiche)
     * - Liste des auditeurs inscrits (lignes de la fiche)
     */
    public function getFicheNotes(int $evaluationId): void
    {
        try {
            // 1. Évaluation
            $evaluation = $this->fetchOne(
                "SELECT e.id, e.libelle, e.date_evaluation, e.numero,
                        s.id AS id_session, s.libelle AS session_libelle, s.code AS session_code,
                        tf.libelle AS type_formation,
                        n.libelle AS niveau_libelle,
                        a.libelle AS annee_libelle
                 FROM evaluations e
                 JOIN sessions s ON s.id = e.id_session
                 JOIN types_formations tf ON tf.id = s.id_type_formation
                 JOIN niveaux n ON n.id = s.id_niveau
                 JOIN annees_scolaires a ON a.id = s.id_annee
                 WHERE e.id = :id LIMIT 1",
                ['id' => $evaluationId]
            );

            if ($evaluation === null) {
                $this->sendError(404, "Évaluation introuvable.");
                return;
            }

            // 2. Paramètres d'évaluation (colonnes de la fiche)
            $parametres = $this->fetchAll(
                "SELECT p.id, p.libelle, p.bareme, ep.ordre
                 FROM evaluation_parametres ep
                 JOIN parametres p ON p.id = ep.id_parametre
                 WHERE ep.id_evaluation = :id_evaluation
                 ORDER BY ep.ordre, p.id",
                ['id_evaluation' => $evaluationId]
            );

            // 3. Auditeurs inscrits dans la session de l'évaluation (via emplois du temps)
            $auditeurs = $this->fetchAll(
                "SELECT DISTINCT a.id, a.nom, a.prenom, a.matricule,
                        i.id AS id_inscription,
                        g.libelle AS groupe, g.code AS groupe_code
                 FROM emplois_du_temps edt
                 JOIN inscriptions i ON i.id_emploi_du_temps = edt.id AND i.statut = 'active'
                 JOIN auditeurs a ON a.id = i.id_auditeur
                 JOIN groupes g ON g.id = edt.id_groupe
                 WHERE edt.id_session = :id_session
                 ORDER BY g.code, a.nom, a.prenom",
                ['id_session' => (int) $evaluation['id_session']]
            );

            // Regrouper par groupe
            $groupes = [];
            foreach ($auditeurs as $aud) {
                $gc = $aud['groupe_code'];
                if (!isset($groupes[$gc])) {
                    $groupes[$gc] = [
                        'code'    => $gc,
                        'libelle' => $aud['groupe'],
                        'auditeurs' => [],
                    ];
                }
                $groupes[$gc]['auditeurs'][] = [
                    'id'             => (int) $aud['id'],
                    'nom'            => $aud['nom'],
                    'prenom'         => $aud['prenom'],
                    'matricule'      => $aud['matricule'],
                    'id_inscription' => (int) $aud['id_inscription'],
                ];
            }

            $this->sendResponse(200, [
                'success' => true,
                'data'    => [
                    'evaluation' => $evaluation,
                    'parametres' => $parametres,
                    'groupes'    => array_values($groupes),
                    'effectif_total' => count($auditeurs),
                ],
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors du chargement de la fiche : " . $e->getMessage());
        }
    }

    /**
     * Génère une page HTML imprimable (fiche vierge pour le professeur).
     * GET /evaluations/{id}/fiche-notes/imprimer
     */
    public function printFicheNotes(int $evaluationId): void
    {
        // 1. Évaluation
        $evaluation = $this->fetchOne(
            "SELECT e.id, e.libelle, e.date_evaluation, e.numero,
                    s.id AS id_session, s.libelle AS session_libelle, s.code AS session_code,
                    tf.libelle AS type_formation,
                    n.libelle AS niveau_libelle,
                    a.libelle AS annee_libelle
             FROM evaluations e
             JOIN sessions s ON s.id = e.id_session
             JOIN types_formations tf ON tf.id = s.id_type_formation
             JOIN niveaux n ON n.id = s.id_niveau
             JOIN annees_scolaires a ON a.id = s.id_annee
             WHERE e.id = :id LIMIT 1",
            ['id' => $evaluationId]
        );

        if ($evaluation === null) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Évaluation introuvable.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 2. Paramètres d'évaluation
        $parametres = $this->fetchAll(
            "SELECT p.id, p.libelle, p.bareme, ep.ordre
             FROM evaluation_parametres ep
             JOIN parametres p ON p.id = ep.id_parametre
             WHERE ep.id_evaluation = :id_evaluation
             ORDER BY ep.ordre, p.id",
            ['id_evaluation' => $evaluationId]
        );

        // 3. Auditeurs inscrits
        $auditeurs = $this->fetchAll(
            "SELECT DISTINCT a.id, a.nom, a.prenom, a.matricule,
                    i.id AS id_inscription,
                    g.libelle AS groupe, g.code AS groupe_code
             FROM emplois_du_temps edt
             JOIN inscriptions i ON i.id_emploi_du_temps = edt.id AND i.statut = 'active'
             JOIN auditeurs a ON a.id = i.id_auditeur
             JOIN groupes g ON g.id = edt.id_groupe
             WHERE edt.id_session = :id_session
             ORDER BY g.code, a.nom, a.prenom",
            ['id_session' => (int) $evaluation['id_session']]
        );

        // Regrouper par groupe
        $groupes = [];
        foreach ($auditeurs as $aud) {
            $gc = $aud['groupe_code'];
            if (!isset($groupes[$gc])) {
                $groupes[$gc] = ['code' => $gc, 'libelle' => $aud['groupe'], 'auditeurs' => []];
            }
            $groupes[$gc]['auditeurs'][] = $aud;
        }

        $effectifTotal = count($auditeurs);
        $dateEval = $evaluation['date_evaluation']
            ? date('d/m/Y', strtotime($evaluation['date_evaluation']))
            : '—';

        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8', true);

        echo '<!DOCTYPE html>';
        echo '<html lang="fr">';
        echo '<head>';
        echo '<meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>Fiche de notes — ' . $e($evaluation['libelle']) . '</title>';
        echo '<style>';
        echo '@page { size: A4 landscape; margin: 1cm; }';
        echo '@media print { body { margin: 0; } .no-print { display: none; } }';
        echo 'body { font-family: Arial, sans-serif; font-size: 11px; color: #000; padding: 8mm; }';
        echo 'h1 { font-size: 14px; text-align: center; margin: 0 0 2px 0; }';
        echo 'h2 { font-size: 12px; text-align: center; margin: 0 0 8px 0; font-weight: normal; }';
        echo '.meta { text-align: center; margin-bottom: 8px; font-size: 10px; }';
        echo 'table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }';
        echo 'th, td { border: 1px solid #000; padding: 3px 5px; text-align: left; }';
        echo 'th { background: #f0f0f0; text-align: center; font-size: 10px; }';
        echo '.sub { font-size: 9px; font-weight: normal; color: #444; }';
        echo '.footer { margin-top: 16px; font-size: 10px; }';
        echo '.print-btn { margin: 8px; padding: 6px 14px; cursor: pointer; }';
        echo '</style>';
        echo '</head><body>';

        echo '<button class="no-print print-btn" onclick="window.print()">Imprimer</button>';
        echo '<h1>AGES — Académie de Langues</h1>';
        echo '<h2>Fiche de notes — ' . $e($evaluation['libelle']) . '</h2>';
        echo '<div class="meta">';
        echo 'Session : ' . $e($evaluation['session_libelle']);
        echo ' | Formation : ' . $e($evaluation['type_formation']);
        echo ' | Niveau : ' . $e($evaluation['niveau_libelle']);
        echo ' | Année : ' . $e($evaluation['annee_libelle']);
        echo ' | Date : ' . $e($dateEval);
        echo ' | Effectif total : ' . $effectifTotal;
        echo '</div>';

        foreach ($groupes as $groupe) {
            echo '<h3>Groupe : ' . $e($groupe['libelle']) . ' (' . $e($groupe['code']) . ')</h3>';
            echo '<table><thead><tr>';
            echo '<th>Matricule</th><th>Nom</th><th>Prénom</th>';
            foreach ($parametres as $p) {
                echo '<th>' . $e($p['libelle']) . '<br><span class="sub">/' . $e((string)$p['bareme']) . '</span></th>';
            }
            echo '<th>Total</th><th>Signature</th>';
            echo '</tr></thead><tbody>';
            foreach ($groupe['auditeurs'] as $aud) {
                echo '<tr>';
                echo '<td>' . $e($aud['matricule']) . '</td>';
                echo '<td>' . $e($aud['nom']) . '</td>';
                echo '<td>' . $e($aud['prenom']) . '</td>';
                foreach ($parametres as $p) {
                    echo '<td></td>';
                }
                echo '<td></td><td></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '<div class="footer">';
        echo 'Signature du formateur : _______________________________ &nbsp;&nbsp; Date : _______________';
        echo '</div>';
        echo '</body></html>';
    }

    /* ---- Helpers ---- */

    private function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function sendResponse(int $code, array $data): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function sendError(int $code, string $message): void
    {
        $this->sendResponse($code, ['success' => false, 'error' => $message]);
    }
}
