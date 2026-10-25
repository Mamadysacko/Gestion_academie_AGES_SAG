<?php
/**
 * Contrôleur pour la gestion des positions
 */

require_once __DIR__ . '/../Model/Position.php';

class PositionController
{
    private Position $model;

    public function __construct()
    {
        $this->model = new Position();
    }

    /**
     * GET /positions
     */
    public function getAll(): void
    {
        try {
            $this->sendResponse(200, ['success' => true, 'data' => $this->model->getAllWithCount()]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des positions : " . $e->getMessage());
        }
    }

    /**
     * GET /positions/{id}
     */
    public function getById(int $id): void
    {
        try {
            $position = $this->model->find($id);
            if ($position === null) {
                $this->sendError(404, "Position introuvable.");
                return;
            }
            $this->sendResponse(200, ['success' => true, 'data' => $position]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    /**
     * POST /positions
     */
    public function create(): void
    {
        try {
            $data = $this->getJsonInput();
            $libelle = trim((string) ($data['libelle'] ?? ''));
            if ($libelle === '' || mb_strlen($libelle) > 100) {
                $this->sendError(400, "Le libellé est obligatoire (100 caractères max.).");
                return;
            }
            if ($this->model->findByLibelle($libelle) !== null) {
                $this->sendError(409, "Une position avec ce libellé existe déjà.");
                return;
            }

            $id = $this->model->create(['libelle' => $libelle]);
            $this->sendResponse(201, [
                'success' => true,
                'message' => "Position créée avec succès.",
                'data' => ['id' => $id]
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la création de la position : " . $e->getMessage());
        }
    }

    /**
     * PUT /positions/{id}
     */
    public function update(int $id): void
    {
        try {
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Position introuvable.");
                return;
            }
            $data = $this->getJsonInput();
            $libelle = trim((string) ($data['libelle'] ?? ''));
            if ($libelle === '' || mb_strlen($libelle) > 100) {
                $this->sendError(400, "Le libellé est obligatoire (100 caractères max.).");
                return;
            }
            $existing = $this->model->findByLibelle($libelle);
            if ($existing !== null && (int) $existing['id'] !== $id) {
                $this->sendError(409, "Une autre position avec ce libellé existe déjà.");
                return;
            }

            $this->model->update($id, ['libelle' => $libelle]);
            $this->sendResponse(200, ['success' => true, 'message' => "Position mise à jour avec succès."]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la mise à jour de la position : " . $e->getMessage());
        }
    }

    /**
     * DELETE /positions/{id}
     * Les auditeurs rattachés perdent leur position (ON DELETE SET NULL).
     */
    public function delete(int $id): void
    {
        try {
            if ($this->model->delete($id)) {
                $this->sendResponse(200, ['success' => true, 'message' => "Position supprimée avec succès."]);
            } else {
                $this->sendError(404, "Position introuvable.");
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la suppression de la position : " . $e->getMessage());
        }
    }

    /* ----------------------------------------------------------------
     | Helpers
     |---------------------------------------------------------------- */

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
