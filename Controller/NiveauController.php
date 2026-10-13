<?php
/**
 * Contrôleur pour la gestion des niveaux
 */

require_once __DIR__ . '/../Model/Niveau.php';

class NiveauController
{
    private Niveau $model;

    public function __construct()
    {
        $this->model = new Niveau();
    }

    /**
     * Retourne tous les niveaux
     * GET /niveaux
     */
    public function getAll(): void
    {
        try {
            $niveaux = $this->model->getAll('ordre ASC, libelle ASC');
            $this->sendResponse(200, [
                'success' => true,
                'data' => $niveaux
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des niveaux : " . $e->getMessage());
        }
    }

    /**
     * Retourne un niveau par son ID
     * GET /niveaux/{id}
     */
    public function getById(int $id): void
    {
        try {
            $niveau = $this->model->find($id);
            
            if ($niveau === null) {
                $this->sendError(404, "Niveau introuvable.");
                return;
            }

            $this->sendResponse(200, [
                'success' => true,
                'data' => $niveau
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération du niveau : " . $e->getMessage());
        }
    }

    /**
     * Retourne un niveau par son libellé
     * GET /niveaux/libelle/{libelle}
     */
    public function getByLibelle(string $libelle): void
    {
        try {
            $niveau = $this->model->findByLibelle($libelle);
            
            if ($niveau === null) {
                $this->sendError(404, "Niveau avec le libellé '{$libelle}' introuvable.");
                return;
            }

            $this->sendResponse(200, [
                'success' => true,
                'data' => $niveau
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération du niveau : " . $e->getMessage());
        }
    }

    /**
     * Crée un nouveau niveau
     * POST /niveaux
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
                $this->sendError(409, "Un niveau avec ce libellé existe déjà.");
                return;
            }

            $id = $this->model->create($data);

            $this->sendResponse(201, [
                'success' => true,
                'message' => 'Niveau créé avec succès.',
                'data' => ['id' => $id]
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la création du niveau : " . $e->getMessage());
        }
    }

    /**
     * Met à jour un niveau
     * PUT /niveaux/{id}
     */
    public function update(int $id): void
    {
        try {
            // Vérification de l'existence
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Niveau introuvable.");
                return;
            }

            $data = $this->getJsonInput();

            // Vérification de l'unicité du libellé (si modifié)
            if (isset($data['libelle'])) {
                $existing = $this->model->findByLibelle($data['libelle']);
                if ($existing !== null && (int)$existing['id'] !== $id) {
                    $this->sendError(409, "Un autre niveau avec ce libellé existe déjà.");
                    return;
                }
            }

            $success = $this->model->update($id, $data);

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Niveau mis à jour avec succès.'
                ]);
            } else {
                $this->sendError(400, "Aucune donnée valide à mettre à jour.");
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la mise à jour du niveau : " . $e->getMessage());
        }
    }

    /**
     * Supprime un niveau
     * DELETE /niveaux/{id}
     */
    public function delete(int $id): void
    {
        try {
            $success = $this->model->delete($id);

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Niveau supprimé avec succès.'
                ]);
            } else {
                $this->sendError(404, "Niveau introuvable.");
            }
        } catch (Exception $e) {
            // Erreur de contrainte de clé étrangère (des sessions ou passages utilisent ce niveau)
            if (strpos($e->getMessage(), 'foreign key constraint') !== false) {
                $this->sendError(409, "Impossible de supprimer ce niveau car il est utilisé par des sessions ou passages de niveaux.");
            } else {
                $this->sendError(500, "Erreur lors de la suppression du niveau : " . $e->getMessage());
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
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function sendError(int $code, string $message): void
    {
        $this->sendResponse($code, [
            'success' => false,
            'error' => $message
        ]);
    }
}
