<?php
/**
 * Contrôleur pour la gestion des types de formation
 */

require_once __DIR__ . '/../Model/TypeFormation.php';

class TypeFormationController
{
    private TypeFormation $model;

    public function __construct()
    {
        $this->model = new TypeFormation();
    }

    /**
     * Retourne tous les types de formation
     * GET /types-formations
     */
    public function getAll(): void
    {
        try {
            $types = $this->model->getAll('libelle ASC');
            $this->sendResponse(200, [
                'success' => true,
                'data' => $types
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des types de formation : " . $e->getMessage());
        }
    }

    /**
     * Retourne un type de formation par son ID
     * GET /types-formations/{id}
     */
    public function getById(int $id): void
    {
        try {
            $type = $this->model->find($id);
            
            if ($type === null) {
                $this->sendError(404, "Type de formation introuvable.");
                return;
            }

            $this->sendResponse(200, [
                'success' => true,
                'data' => $type
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération du type de formation : " . $e->getMessage());
        }
    }

    /**
     * Retourne un type de formation par son libellé
     * GET /types-formations/libelle/{libelle}
     */
    public function getByLibelle(string $libelle): void
    {
        try {
            $type = $this->model->findByLibelle($libelle);
            
            if ($type === null) {
                $this->sendError(404, "Type de formation avec le libellé '{$libelle}' introuvable.");
                return;
            }

            $this->sendResponse(200, [
                'success' => true,
                'data' => $type
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération du type de formation : " . $e->getMessage());
        }
    }

    /**
     * Crée un nouveau type de formation
     * POST /types-formations
     */
    public function create(): void
    {
        try {
            $data = $this->getJsonInput();

            // Validation du champ requis
            if (empty($data['libelle'])) {
                $this->sendError(400, "Le champ 'libelle' est requis.");
                return;
            }

            // Vérification de l'unicité du libellé
            if ($this->model->findByLibelle($data['libelle']) !== null) {
                $this->sendError(409, "Un type de formation avec ce libellé existe déjà.");
                return;
            }

            $id = $this->model->create($data);

            $this->sendResponse(201, [
                'success' => true,
                'message' => 'Type de formation créé avec succès.',
                'data' => ['id' => $id]
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la création du type de formation : " . $e->getMessage());
        }
    }

    /**
     * Met à jour un type de formation
     * PUT /types-formations/{id}
     */
    public function update(int $id): void
    {
        try {
            // Vérification de l'existence
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Type de formation introuvable.");
                return;
            }

            $data = $this->getJsonInput();

            // Vérification de l'unicité du libellé (si modifié)
            if (isset($data['libelle'])) {
                $existing = $this->model->findByLibelle($data['libelle']);
                if ($existing !== null && (int)$existing['id'] !== $id) {
                    $this->sendError(409, "Un autre type de formation avec ce libellé existe déjà.");
                    return;
                }
            }

            $success = $this->model->update($id, $data);

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Type de formation mis à jour avec succès.'
                ]);
            } else {
                $this->sendError(400, "Aucune donnée valide à mettre à jour.");
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la mise à jour du type de formation : " . $e->getMessage());
        }
    }

    /**
     * Supprime un type de formation
     * DELETE /types-formations/{id}
     */
    public function delete(int $id): void
    {
        try {
            $success = $this->model->delete($id);

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Type de formation supprimé avec succès.'
                ]);
            } else {
                $this->sendError(404, "Type de formation introuvable.");
            }
        } catch (Exception $e) {
            // Erreur de contrainte de clé étrangère (des sessions utilisent ce type)
            if (strpos($e->getMessage(), 'foreign key constraint') !== false) {
                $this->sendError(409, "Impossible de supprimer ce type de formation car il est utilisé par des sessions.");
            } else {
                $this->sendError(500, "Erreur lors de la suppression du type de formation : " . $e->getMessage());
            }
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
        $this->sendResponse($code, [
            'success' => false,
            'error' => $message
        ]);
    }
}
