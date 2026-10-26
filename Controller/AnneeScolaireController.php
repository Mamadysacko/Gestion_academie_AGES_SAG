<?php
/**
 * Contrôleur pour la gestion des années scolaires
 */

require_once __DIR__ . '/../Model/AnneeScolaire.php';

class AnneeScolaireController
{
    private AnneeScolaire $model;

    public function __construct()
    {
        $this->model = new AnneeScolaire();
    }

    /**
     * Retourne toutes les années scolaires
     * GET /annees-scolaires
     */
    public function getAll(): void
    {
        try {
            $annees = $this->model->getAll('date_debut DESC');
            $this->sendResponse(200, [
                'success' => true,
                'data' => $annees
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des années scolaires : " . $e->getMessage());
        }
    }

    /**
     * Retourne une année scolaire par son ID
     * GET /annees-scolaires/{id}
     */
    public function getById(int $id): void
    {
        try {
            $annee = $this->model->find($id);
            
            if ($annee === null) {
                $this->sendError(404, "Année scolaire introuvable.");
                return;
            }

            $this->sendResponse(200, [
                'success' => true,
                'data' => $annee
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération de l'année scolaire : " . $e->getMessage());
        }
    }

    /**
     * Retourne l'année scolaire active
     * GET /annees-scolaires/active
     */
    public function getActive(): void
    {
        try {
            $annee = $this->model->getActive();
            
            if ($annee === null) {
                $this->sendError(404, "Aucune année scolaire active trouvée.");
                return;
            }

            $this->sendResponse(200, [
                'success' => true,
                'data' => $annee
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération de l'année active : " . $e->getMessage());
        }
    }

    /**
     * Crée une nouvelle année scolaire
     * POST /annees-scolaires
     */
    public function create(): void
    {
        try {
            $data = $this->getJsonInput();

            // Validation des champs requis
            $required = ['libelle', 'date_debut', 'date_fin'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $this->sendError(400, "Le champ '{$field}' est requis.");
                    return;
                }
            }

            // Vérification de l'unicité du libellé
            if ($this->model->existsByLibelle($data['libelle'])) {
                $this->sendError(409, "Une année scolaire avec ce libellé existe déjà.");
                return;
            }

            // Validation des dates
            if (strtotime($data['date_fin']) <= strtotime($data['date_debut'])) {
                $this->sendError(400, "La date de fin doit être postérieure à la date de début.");
                return;
            }

            // Valeur par défaut pour statut
            if (!isset($data['statut'])) {
                $data['statut'] = 1;
            }

            $id = $this->model->create($data);

            $this->sendResponse(201, [
                'success' => true,
                'message' => 'Année scolaire créée avec succès.',
                'data' => ['id' => $id]
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la création de l'année scolaire : " . $e->getMessage());
        }
    }

    /**
     * Met à jour une année scolaire
     * PUT /annees-scolaires/{id}
     */
    public function update(int $id): void
    {
        try {
            // Vérification de l'existence
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Année scolaire introuvable.");
                return;
            }

            $data = $this->getJsonInput();

            // Vérification de l'unicité du libellé (sauf pour l'enregistrement actuel)
            if (isset($data['libelle']) && $this->model->existsByLibelle($data['libelle'], $id)) {
                $this->sendError(409, "Une autre année scolaire avec ce libellé existe déjà.");
                return;
            }

            // Validation des dates si fournies
            if (isset($data['date_debut']) && isset($data['date_fin'])) {
                if (strtotime($data['date_fin']) <= strtotime($data['date_debut'])) {
                    $this->sendError(400, "La date de fin doit être postérieure à la date de début.");
                    return;
                }
            }
            // Une année ne peut pas être raccourcie en laissant une de ses sessions dehors.
            $currentYear = $this->model->find($id);
            if ($currentYear !== null && (isset($data['date_debut']) || isset($data['date_fin']))) {
                $stmt = Database::getInstance()->prepare(
                    'SELECT code FROM sessions WHERE id_annee = :id AND (date_debut < :debut OR date_fin > :fin) LIMIT 1'
                );
                $stmt->execute([
                    'id' => $id,
                    'debut' => $data['date_debut'] ?? $currentYear['date_debut'],
                    'fin' => $data['date_fin'] ?? $currentYear['date_fin'],
                ]);
                $outside = $stmt->fetchColumn();
                if ($outside !== false) {
                    $this->sendError(409, "La session {$outside} sortirait de ces nouvelles dates : modifiez d’abord la session.");
                    return;
                }
            }

            $success = $this->model->update($id, $data);

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Année scolaire mise à jour avec succès.'
                ]);
            } else {
                $this->sendError(400, "Aucune donnée valide à mettre à jour.");
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la mise à jour de l'année scolaire : " . $e->getMessage());
        }
    }

    /**
     * Supprime une année scolaire
     * DELETE /annees-scolaires/{id}
     */
    public function delete(int $id): void
    {
        try {
            $year = $this->model->find($id);
            if ($year === null) {
                $this->sendError(404, "Année scolaire introuvable.");
                return;
            }
            if ((bool) $year['statut']) {
                $this->sendError(409, "L'année scolaire active ne peut pas être supprimée. Activez d'abord une autre année.");
                return;
            }
            if ($this->model->hasSessions($id)) {
                $this->sendError(409, "Cette année contient des sessions. Supprimez ou réaffectez d'abord ces sessions.");
                return;
            }
            $success = $this->model->delete($id);

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Année scolaire supprimée avec succès.'
                ]);
            } else {
                $this->sendError(404, "Année scolaire introuvable.");
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la suppression de l'année scolaire : " . $e->getMessage());
        }
    }

    /**
     * Active une année scolaire (désactive toutes les autres)
     * POST /annees-scolaires/{id}/activate
     */
    public function activate(int $id): void
    {
        try {
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Année scolaire introuvable.");
                return;
            }

            $success = $this->model->setActive($id);

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Année scolaire activée avec succès.'
                ]);
            } else {
                $this->sendError(400, "Impossible d'activer l'année scolaire.");
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de l'activation de l'année scolaire : " . $e->getMessage());
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
