<?php
/**
 * Contrôleur pour la gestion des départements
 */

require_once __DIR__ . '/../Model/Departement.php';

class DepartementController
{
    private Departement $model;

    public function __construct()
    {
        $this->model = new Departement();
    }

    /**
     * Retourne tous les départements
     * GET /departements
     */
    public function getAll(): void
    {
        try {
            $departements = $this->model->getAll('libelle ASC');
            $this->sendResponse(200, [
                'success' => true,
                'data' => $departements
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des départements : " . $e->getMessage());
        }
    }

    /**
     * Retourne un département par son ID
     * GET /departements/{id}
     */
    public function getById(int $id): void
    {
        try {
            $departement = $this->model->find($id);
            
            if ($departement === null) {
                $this->sendError(404, "Département introuvable.");
                return;
            }

            $this->sendResponse(200, [
                'success' => true,
                'data' => $departement
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération du département : " . $e->getMessage());
        }
    }

    /**
     * Retourne un département par son code
     * GET /departements/code/{code}
     */
    public function getByCode(string $code): void
    {
        try {
            $departement = $this->model->findByCode($code);
            
            if ($departement === null) {
                $this->sendError(404, "Département avec le code '{$code}' introuvable.");
                return;
            }

            $this->sendResponse(200, [
                'success' => true,
                'data' => $departement
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération du département : " . $e->getMessage());
        }
    }

    /**
     * Crée un nouveau département
     * POST /departements
     */
    public function create(): void
    {
        try {
            $data = $this->getJsonInput();

            // Validation des champs requis
            $required = ['code', 'libelle'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $this->sendError(400, "Le champ '{$field}' est requis.");
                    return;
                }
            }

            // Vérification de l'unicité du code
            if ($this->model->findByCode($data['code']) !== null) {
                $this->sendError(409, "Un département avec ce code existe déjà.");
                return;
            }

            $id = $this->model->create($data);

            $this->sendResponse(201, [
                'success' => true,
                'message' => 'Département créé avec succès.',
                'data' => ['id' => $id]
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la création du département : " . $e->getMessage());
        }
    }

    /**
     * Met à jour un département
     * PUT /departements/{id}
     */
    public function update(int $id): void
    {
        try {
            // Vérification de l'existence
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Département introuvable.");
                return;
            }

            $data = $this->getJsonInput();

            // Vérification de l'unicité du code (si modifié)
            if (isset($data['code'])) {
                $existing = $this->model->findByCode($data['code']);
                if ($existing !== null && (int)$existing['id'] !== $id) {
                    $this->sendError(409, "Un autre département avec ce code existe déjà.");
                    return;
                }
            }

            $success = $this->model->update($id, $data);

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Département mis à jour avec succès.'
                ]);
            } else {
                $this->sendError(400, "Aucune donnée valide à mettre à jour.");
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la mise à jour du département : " . $e->getMessage());
        }
    }

    /**
     * Supprime un département
     * DELETE /departements/{id}
     */
    public function delete(int $id): void
    {
        try {
            $success = $this->model->delete($id);

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Département supprimé avec succès.'
                ]);
            } else {
                $this->sendError(404, "Département introuvable.");
            }
        } catch (Exception $e) {
            // Erreur de contrainte de clé étrangère (des inscriptions utilisent ce département)
            if (strpos($e->getMessage(), 'foreign key constraint') !== false) {
                $this->sendError(409, "Impossible de supprimer ce département car il est utilisé par des inscriptions.");
            } else {
                $this->sendError(500, "Erreur lors de la suppression du département : " . $e->getMessage());
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
