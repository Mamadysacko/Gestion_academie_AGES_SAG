<?php
/**
 * Contrôleur pour la gestion des notes
 */

require_once __DIR__ . '/../Model/Note.php';

class NoteController
{
    private Note $model;

    public function __construct()
    {
        $this->model = new Note();
    }

    public function getAll(): void
    {
        try {
            $notes = $this->model->getAll();
            $this->sendResponse(200, ['success' => true, 'data' => $notes]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getById(int $id): void
    {
        try {
            $note = $this->model->find($id);
            if ($note === null) {
                $this->sendError(404, "Note introuvable.");
                return;
            }
            $this->sendResponse(200, ['success' => true, 'data' => $note]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getByInscription(int $id_inscription): void
    {
        try {
            $notes = $this->model->getByInscription($id_inscription);
            $this->sendResponse(200, ['success' => true, 'data' => $notes]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getByParametre(int $id_parametre, ?int $id_evaluation = null): void
    {
        try {
            $evaluationId = $id_evaluation ?? (int) ($_GET['id_evaluation'] ?? 0);
            if ($evaluationId <= 0) {
                $this->sendError(400, "L'identifiant de l'évaluation est requis.");
                return;
            }
            $notes = $this->model->getByParametre($evaluationId, $id_parametre);
            $this->sendResponse(200, ['success' => true, 'data' => $notes]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getByEvaluation(int $id_evaluation): void
    {
        try {
            $notes = $this->model->getByEvaluation($id_evaluation);
            $this->sendResponse(200, ['success' => true, 'data' => $notes]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getMoyenne(int $id_inscription, ?int $id_evaluation = null): void
    {
        try {
            $moyennes = $this->model->getMoyenneInscription($id_inscription, $id_evaluation);
            
            if (!$moyennes) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Aucune note enregistrée pour cette inscription.',
                    'data' => []
                ]);
            } else {
                $this->sendResponse(200, ['success' => true, 'data' => $moyennes]);
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function create(): void
    {
        try {
            $data = $this->getJsonInput();

            $required = ['id_parametre', 'id_inscription'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $this->sendError(400, "Le champ '{$field}' est requis.");
                    return;
                }
            }

            $id = $this->model->create($data);

            $this->sendResponse(201, [
                'success' => true,
                'message' => 'Note créée avec succès.',
                'data' => ['id_note' => $id]
            ]);
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function update(int $id): void
    {
        try {
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Note introuvable.");
                return;
            }

            $data = $this->getJsonInput();
            $success = $this->model->update($id, $data);

            if ($success) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Note mise à jour.']);
            } else {
                $this->sendError(400, "Aucune donnée valide.");
            }
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function delete(int $id): void
    {
        try {
            $success = $this->model->delete($id);
            if ($success) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Note supprimée.']);
            } else {
                $this->sendError(404, "Note introuvable.");
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function saveOrUpdate(): void
    {
        try {
            $data = $this->getJsonInput();

            $required = ['id_evaluation', 'id_parametre', 'id_inscription'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $this->sendError(400, "Le champ '{$field}' est requis.");
                    return;
                }
            }

            $note = isset($data['note']) && $data['note'] !== '' ? (float)$data['note'] : null;
            $observation = $data['observation'] ?? null;

            $success = $this->model->saveOrUpdate(
                (int)$data['id_evaluation'],
                (int)$data['id_parametre'],
                (int)$data['id_inscription'],
                $note,
                $observation
            );

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Note enregistrée avec succès.'
                ]);
            } else {
                $this->sendError(400, "Impossible d'enregistrer la note.");
            }
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (RuntimeException $e) {
            $this->sendError(400, $e->getMessage());
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
