<?php
/**
 * Contrôleur pour la gestion des types d'employés
 */

require_once __DIR__ . '/../Model/TypeEmploye.php';

class TypeEmployeController
{
    private TypeEmploye $model;

    public function __construct()
    {
        $this->model = new TypeEmploye();
    }

    /**
     * GET /types-employes
     */
    public function getAll(): void
    {
        try {
            $this->sendResponse(200, ['success' => true, 'data' => $this->model->getAllWithCount()]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des types d'employés : " . $e->getMessage());
        }
    }

    /**
     * GET /types-employes/{id}
     */
    public function getById(int $id): void
    {
        try {
            $type = $this->model->find($id);
            if ($type === null) {
                $this->sendError(404, "Type d'employé introuvable.");
                return;
            }
            $this->sendResponse(200, ['success' => true, 'data' => $type]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    /**
     * POST /types-employes
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
                $this->sendError(409, "Un type d'employé avec ce libellé existe déjà.");
                return;
            }

            $id = $this->model->create(['libelle' => $libelle]);
            $this->sendResponse(201, [
                'success' => true,
                'message' => "Type d'employé créé avec succès.",
                'data' => ['id' => $id]
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la création du type d'employé : " . $e->getMessage());
        }
    }

    /**
     * PUT /types-employes/{id}
     */
    public function update(int $id): void
    {
        try {
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Type d'employé introuvable.");
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
                $this->sendError(409, "Un autre type d'employé avec ce libellé existe déjà.");
                return;
            }

            $this->model->update($id, ['libelle' => $libelle]);
            $this->sendResponse(200, ['success' => true, 'message' => "Type d'employé mis à jour avec succès."]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la mise à jour du type d'employé : " . $e->getMessage());
        }
    }

    /**
     * DELETE /types-employes/{id}
     * Les auditeurs rattachés passent à « non renseigné » (ON DELETE SET NULL).
     */
    public function delete(int $id): void
    {
        try {
            if ($this->model->delete($id)) {
                $this->sendResponse(200, ['success' => true, 'message' => "Type d'employé supprimé avec succès."]);
            } else {
                $this->sendError(404, "Type d'employé introuvable.");
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la suppression du type d'employé : " . $e->getMessage());
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
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function sendError(int $code, string $message): void
    {
        $this->sendResponse($code, ['success' => false, 'error' => $message]);
    }
}
