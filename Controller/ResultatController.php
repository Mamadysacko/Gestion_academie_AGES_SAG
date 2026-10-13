<?php
/**
 * Résultats, règles d'évaluation et passages automatiques
 *
 * GET  /resultats/session/{id}?id_groupe=&id_salle=  → moyennes, appréciations, statuts, synthèse
 * GET  /resultats/auditeur/{id}                       → parcours (niveaux, sessions, résultats)
 * POST /resultats/valider/{id_session}                → enregistre les passages calculables
 * GET  /regles-evaluation                             → règles + barème d'appréciation
 * PUT  /regles-evaluation                             → { regles?: {...}, bareme?: [...] }
 */

require_once __DIR__ . '/../Model/Resultat.php';
require_once __DIR__ . '/../Model/ReglesEvaluation.php';

class ResultatController
{
    private Resultat $model;

    public function __construct()
    {
        $this->model = new Resultat();
    }

    /** @param int|null $trainerId restreint aux classes d'un formateur (espace formateur). */
    public function getSession(int $sessionId, ?int $trainerId = null): void
    {
        try {
            $filters = [
                'id_groupe' => filter_var($_GET['id_groupe'] ?? null, FILTER_VALIDATE_INT) ?: null,
                'id_salle' => filter_var($_GET['id_salle'] ?? null, FILTER_VALIDATE_INT) ?: null,
                'id_formateur' => $trainerId,
            ];
            $this->sendResponse(200, ['success' => true, 'data' => $this->model->getSessionResults($sessionId, $filters)]);
        } catch (InvalidArgumentException $e) {
            $this->sendError(404, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'Erreur lors du calcul des résultats : ' . $e->getMessage());
        }
    }

    public function getAuditeur(int $auditeurId): void
    {
        try {
            $this->sendResponse(200, ['success' => true, 'data' => $this->model->getAuditeurParcours($auditeurId)]);
        } catch (InvalidArgumentException $e) {
            $this->sendError(404, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'Erreur : ' . $e->getMessage());
        }
    }

    public function valider(int $sessionId): void
    {
        try {
            $counts = $this->model->validerPassages($sessionId, 'manuelle');
            $this->sendResponse(200, [
                'success' => true,
                'message' => sprintf(
                    '%d décision(s) enregistrée(s) : %d admis, %d non admis, %d abandon(s). %d résultat(s) restent en attente.',
                    $counts['validees'], $counts['admis'], $counts['non_admis'], $counts['abandonne'], $counts['en_attente']
                ),
                'data' => $counts,
            ]);
        } catch (InvalidArgumentException $e) {
            $this->sendError(404, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'Erreur lors de la validation des passages : ' . $e->getMessage());
        }
    }

    public function getRegles(): void
    {
        $model = new ReglesEvaluation();
        $this->sendResponse(200, ['success' => true, 'data' => [
            'regles' => $model->getRegles(),
            'bareme' => $model->getBareme(),
        ]]);
    }

    public function updateRegles(): void
    {
        try {
            $data = $this->getJsonInput();
            $model = new ReglesEvaluation();
            if (isset($data['regles']) && is_array($data['regles'])) {
                $model->updateRegles($data['regles']);
            }
            if (isset($data['bareme']) && is_array($data['bareme'])) {
                $model->updateBareme($data['bareme']);
            }
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Règles d’évaluation enregistrées.',
                'data' => ['regles' => $model->getRegles(), 'bareme' => $model->getBareme()],
            ]);
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'Erreur : ' . $e->getMessage());
        }
    }

    /**
     * CRUD des règles par niveau et du barème d'appréciation.
     * $table : 'regles' | 'bareme' ; $action : 'list' | 'create' | 'update' | 'delete'.
     */
    public function crud(string $table, string $action, ?int $id = null): void
    {
        $model = new ReglesEvaluation();
        try {
            $data = $this->getJsonInput();
            if ($action === 'list') {
                $this->sendResponse(200, ['success' => true, 'data' => $table === 'regles' ? $model->listRules() : $model->getBareme()]);
                return;
            }
            if ($table === 'regles') {
                match ($action) {
                    'create' => $model->createRule($data),
                    'update' => $model->updateRule((int) $id, $data),
                    'delete' => $model->deleteRule((int) $id),
                };
            } else {
                match ($action) {
                    'create' => $model->createScaleRow($data),
                    'update' => $model->updateScaleRow((int) $id, $data),
                    'delete' => $model->deleteScaleRow((int) $id),
                };
            }
            $labels = ['create' => 'ajoutée', 'update' => 'modifiée', 'delete' => 'supprimée'];
            $this->sendResponse($action === 'create' ? 201 : 200, [
                'success' => true,
                'message' => ($table === 'regles' ? 'Règle d’évaluation ' : 'Appréciation ') . $labels[$action] . '. Les résultats sont recalculés automatiquement.',
            ]);
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'Erreur : ' . $e->getMessage());
        }
    }

    private function getJsonInput(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        return is_array($data) ? $data : [];
    }

    private function sendResponse(int $code, array $data): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function sendError(int $code, string $message): void
    {
        $this->sendResponse($code, ['success' => false, 'error' => $message]);
    }
}
