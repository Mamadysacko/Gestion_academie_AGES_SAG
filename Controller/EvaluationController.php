<?php
/**
 * Contrôleur pour la gestion des évaluations
 */

require_once __DIR__ . '/../Model/Evaluation.php';

class EvaluationController
{
    private Evaluation $model;

    public function __construct()
    {
        $this->model = new Evaluation();
    }

    public function getAll(): void
    {
        try {
            $evaluations = $this->model->getAllWithSession();
            $this->sendResponse(200, ['success' => true, 'data' => $evaluations]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getById(int $id): void
    {
        try {
            $evaluation = $this->model->find($id);
            if ($evaluation === null) {
                $this->sendError(404, "Évaluation introuvable.");
                return;
            }
            $this->sendResponse(200, ['success' => true, 'data' => $evaluation]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getBySession(int $id_session): void
    {
        try {
            $evaluations = $this->model->getBySession($id_session);
            $this->sendResponse(200, ['success' => true, 'data' => $evaluations]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function create(): void
    {
        try {
            $data = $this->getJsonInput();

            $required = ['id_session', 'libelle', 'date_evaluation'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $this->sendError(400, "Le champ '{$field}' est requis.");
                    return;
                }
            }

            $data['id_session'] = filter_var($data['id_session'], FILTER_VALIDATE_INT);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $data['date_evaluation']);
            if (
                !$data['id_session']
                || $date === false
                || $date->format('Y-m-d') !== (string) $data['date_evaluation']
            ) {
                $this->sendError(400, "La session et une date d’évaluation valide sont requises.");
                return;
            }
            $data['libelle'] = trim((string) $data['libelle']);
            if ($data['libelle'] === '' || mb_strlen($data['libelle']) > 100) {
                $this->sendError(400, "Le libellé doit contenir entre 1 et 100 caractères.");
                return;
            }

            $id = $this->model->create($data);

            $this->sendResponse(201, [
                'success' => true,
                'message' => 'Évaluation créée avec succès.',
                'data' => ['id' => $id]
            ]);
        } catch (PDOException $e) {
            $this->sendError((string) $e->getCode() === '23000' ? 400 : 500, "Erreur : " . $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function update(int $id): void
    {
        try {
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Évaluation introuvable.");
                return;
            }

            $data = $this->getJsonInput();
            $success = $this->model->update($id, $data);

            if ($success) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Évaluation mise à jour.']);
            } else {
                $this->sendError(400, "Aucune donnée valide.");
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function delete(int $id): void
    {
        try {
            $success = $this->model->delete($id);
            if ($success) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Évaluation supprimée.']);
            } else {
                $this->sendError(404, "Évaluation introuvable.");
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    private function getJsonInput(): array
    {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
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
