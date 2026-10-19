<?php
/**
 * Contrôleur pour la gestion des paramètres d'évaluation
 */

require_once __DIR__ . '/../Model/ParametreEvaluation.php';

class ParametreEvaluationController
{
    private ParametreEvaluation $model;

    public function __construct()
    {
        $this->model = new ParametreEvaluation();
    }

    public function getAll(): void
    {
        try {
            $parametres = $this->model->getAllWithRelations();
            $this->sendResponse(200, ['success' => true, 'data' => $parametres]);
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getById(int $id): void
    {
        try {
            $parametre = $this->model->find($id);
            if ($parametre === null) {
                $this->sendError(404, "Paramètre introuvable.");
                return;
            }
            $this->sendResponse(200, ['success' => true, 'data' => $parametre]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getByEvaluation(int $id_evaluation): void
    {
        try {
            $parametres = $this->model->getByEvaluation($id_evaluation);
            $this->sendResponse(200, ['success' => true, 'data' => $parametres]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function create(): void
    {
        try {
            $data = $this->getJsonInput();
            $data['libelle'] = trim((string) ($data['libelle'] ?? ''));
            if ($data['libelle'] === '' || mb_strlen($data['libelle']) > 100) {
                $this->sendError(400, "Le champ 'libelle' est requis.");
                return;
            }

            if (!isset($data['bareme'])) {
                $data['bareme'] = 4.00;
            }

            if (!is_numeric($data['bareme']) || $data['bareme'] <= 0 || $data['bareme'] > 999.99) {
                $this->sendError(400, "Le barème doit être positif et inférieur ou égal à 999,99.");
                return;
            }
            $data['bareme'] = round((float) $data['bareme'], 2);

            $id_evaluation = isset($data['id_evaluation']) ? (int) $data['id_evaluation'] : null;
            $ordre = isset($data['ordre']) ? (int) $data['ordre'] : 1;
            $db = $this->model->getDatabase();
            $db->beginTransaction();
            $id = $this->model->create($data);
            if ($id_evaluation !== null) {
                $this->model->attachToEvaluation($id_evaluation, $id, $ordre);
            }
            $db->commit();

            $this->sendResponse(201, [
                'success' => true,
                'message' => 'Paramètre créé avec succès.',
                'data' => ['id' => $id]
            ]);
        } catch (InvalidArgumentException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            $this->sendError(400, $e->getMessage());
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            $this->sendError((string) $e->getCode() === '23000' ? 404 : 500, "Erreur : " . $e->getMessage());
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function attachToEvaluation(int $id_evaluation): void
    {
        try {
            $data = $this->getJsonInput();
            $id_parametre = filter_var($data['id_parametre'] ?? null, FILTER_VALIDATE_INT);
            $ordre = filter_var($data['ordre'] ?? 1, FILTER_VALIDATE_INT);
            if (!$id_parametre || !$ordre || !$this->model->exists((int) $id_parametre)) {
                $this->sendError(400, "Un paramètre existant et un ordre positif sont requis.");
                return;
            }

            $this->model->attachToEvaluation($id_evaluation, (int) $id_parametre, (int) $ordre);
            $this->sendResponse(200, ['success' => true, 'message' => 'Paramètre associé à l’évaluation.']);
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (PDOException $e) {
            $this->sendError((string) $e->getCode() === '23000' ? 404 : 500, "Erreur : " . $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function detachFromEvaluation(int $id_evaluation, int $id_parametre): void
    {
        try {
            if ($this->model->detachFromEvaluation($id_evaluation, $id_parametre)) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Association supprimée.']);
            } else {
                $this->sendError(404, "Association introuvable.");
            }
        } catch (DomainException $e) {
            $this->sendError(409, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function update(int $id): void
    {
        try {
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Paramètre introuvable.");
                return;
            }

            $data = $this->getJsonInput();

            if (isset($data['bareme']) && (
                !is_numeric($data['bareme']) || $data['bareme'] <= 0 || $data['bareme'] > 999.99
            )) {
                $this->sendError(400, "Le barème doit être positif et inférieur ou égal à 999,99.");
                return;
            }
            if (isset($data['bareme'])) $data['bareme'] = round((float) $data['bareme'], 2);

            $success = $this->model->update($id, $data);

            if ($success) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Paramètre mis à jour.']);
            } else {
                $this->sendError(400, "Aucune donnée valide.");
            }
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (DomainException $e) {
            $this->sendError(409, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function delete(int $id): void
    {
        try {
            $success = $this->model->delete($id);
            if ($success) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Paramètre supprimé.']);
            } else {
                $this->sendError(404, "Paramètre introuvable.");
            }
        } catch (DomainException $e) {
            $this->sendError(409, $e->getMessage());
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
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function sendError(int $code, string $message): void
    {
        $this->sendResponse($code, ['success' => false, 'error' => $message]);
    }
}
