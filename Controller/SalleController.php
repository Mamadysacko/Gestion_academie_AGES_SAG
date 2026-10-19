<?php
/**
 * Contrôleur pour la gestion des salles
 */

require_once __DIR__ . '/../Model/Salle.php';

class SalleController
{
    private Salle $model;

    public function __construct()
    {
        $this->model = new Salle();
    }

    /**
     * Retourne toutes les salles
     * GET /salles
     */
    public function getAll(): void
    {
        try {
            $salles = $this->model->getAll('code ASC');
            $this->sendResponse(200, [
                'success' => true,
                'data' => $salles
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des salles : " . $e->getMessage());
        }
    }

    /**
     * Retourne une salle par son ID
     * GET /salles/{id}
     */
    public function getById(int $id): void
    {
        try {
            $salle = $this->model->find($id);
            
            if ($salle === null) {
                $this->sendError(404, "Salle introuvable.");
                return;
            }

            $this->sendResponse(200, [
                'success' => true,
                'data' => $salle
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération de la salle : " . $e->getMessage());
        }
    }

    /**
     * Retourne une salle par son code
     * GET /salles/code/{code}
     */
    public function getByCode(string $code): void
    {
        try {
            $salle = $this->model->findByCode($code);
            
            if ($salle === null) {
                $this->sendError(404, "Salle avec le code '{$code}' introuvable.");
                return;
            }

            $this->sendResponse(200, [
                'success' => true,
                'data' => $salle
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération de la salle : " . $e->getMessage());
        }
    }

    /**
     * Retourne les salles disponibles
     * GET /salles/disponibles
     */
    public function getDisponibles(): void
    {
        try {
            $salles = $this->model->getDisponibles();
            $this->sendResponse(200, [
                'success' => true,
                'data' => $salles
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des salles disponibles : " . $e->getMessage());
        }
    }

    /**
     * Crée une nouvelle salle
     * POST /salles
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

            $data['code'] = mb_strtoupper(trim((string) $data['code']), 'UTF-8');
            $data['libelle'] = trim((string) $data['libelle']);
            if ($data['code'] === '' || mb_strlen($data['code']) > 30 ||
                $data['libelle'] === '' || mb_strlen($data['libelle']) > 100
            ) {
                $this->sendError(400, "Le code (30 caractères max.) et le libellé (100 caractères max.) sont obligatoires.");
                return;
            }

            // Vérification de l'unicité du code
            if ($this->model->findByCode($data['code']) !== null) {
                $this->sendError(409, "Une salle avec ce code existe déjà.");
                return;
            }

            // Validation de la capacité si fournie
            if (array_key_exists('capacite', $data) && $data['capacite'] !== null && $data['capacite'] !== '') {
                if (!filter_var($data['capacite'], FILTER_VALIDATE_INT) ||
                    (int) $data['capacite'] <= 0 ||
                    (float) $data['capacite'] > 4294967295
                ) {
                    $this->sendError(400, "La capacité doit être un entier positif compatible avec la base.");
                    return;
                }
                $data['capacite'] = (int) $data['capacite'];
            } else {
                $data['capacite'] = null;
            }

            // Valeur par défaut pour statut
            if (!isset($data['statut'])) {
                $data['statut'] = 'disponible';
            }

            // Validation du statut
            if (!in_array($data['statut'], Salle::STATUTS, true)) {
                $this->sendError(400, "Statut invalide. Valeurs acceptées : " . implode(', ', Salle::STATUTS));
                return;
            }

            $id = $this->model->create($data);

            $this->sendResponse(201, [
                'success' => true,
                'message' => 'Salle créée avec succès.',
                'data' => ['id' => $id]
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                $this->sendError(409, "Une salle avec ce code existe déjà.");
                return;
            }
            $this->sendError(500, "Erreur lors de la création de la salle : " . $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la création de la salle : " . $e->getMessage());
        }
    }

    /**
     * Met à jour une salle
     * PUT /salles/{id}
     */
    public function update(int $id): void
    {
        try {
            // Vérification de l'existence
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Salle introuvable.");
                return;
            }

            $data = $this->getJsonInput();
            if (array_key_exists('code', $data)) {
                $data['code'] = mb_strtoupper(trim((string) $data['code']), 'UTF-8');
                if ($data['code'] === '' || mb_strlen($data['code']) > 30) {
                    $this->sendError(400, "Le code est obligatoire et limité à 30 caractères.");
                    return;
                }
            }
            if (array_key_exists('libelle', $data)) {
                $data['libelle'] = trim((string) $data['libelle']);
                if ($data['libelle'] === '' || mb_strlen($data['libelle']) > 100) {
                    $this->sendError(400, "Le libellé est obligatoire et limité à 100 caractères.");
                    return;
                }
            }

            // Vérification de l'unicité du code (si modifié)
            if (isset($data['code'])) {
                $existing = $this->model->findByCode($data['code']);
                if ($existing !== null && (int)$existing['id'] !== $id) {
                    $this->sendError(409, "Une autre salle avec ce code existe déjà.");
                    return;
                }
            }

            // Validation de la capacité si fournie
            if (array_key_exists('capacite', $data) && $data['capacite'] !== null && $data['capacite'] !== '') {
                if (!filter_var($data['capacite'], FILTER_VALIDATE_INT) ||
                    (int) $data['capacite'] <= 0 ||
                    (float) $data['capacite'] > 4294967295
                ) {
                    $this->sendError(400, "La capacité doit être un entier positif compatible avec la base.");
                    return;
                }
                $data['capacite'] = (int) $data['capacite'];
            } elseif (array_key_exists('capacite', $data)) {
                $data['capacite'] = null;
            }

            // Validation du statut si fourni
            if (isset($data['statut']) && !in_array($data['statut'], Salle::STATUTS, true)) {
                $this->sendError(400, "Statut invalide. Valeurs acceptées : " . implode(', ', Salle::STATUTS));
                return;
            }

            $capacity = array_key_exists('capacite', $data) ? $data['capacite'] : $this->model->find($id)['capacite'];
            if ($capacity !== null && (int) $capacity < $this->model->getPeakActiveEnrollment($id)) {
                $this->sendError(409, "La capacité ne peut pas être inférieure au nombre d'auditeurs actifs déjà planifiés dans cette salle.");
                return;
            }
            if (($data['statut'] ?? null) === 'indisponible' && $this->model->hasActiveSchedules($id)) {
                $this->sendError(409, "Réaffectez ou désactivez d'abord les créneaux actifs avant de rendre cette salle indisponible.");
                return;
            }

            $success = $this->model->update($id, $data);

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Salle mise à jour avec succès.'
                ]);
            } else {
                $this->sendError(400, "Aucune donnée valide à mettre à jour.");
            }
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                $this->sendError(409, "Une autre salle utilise déjà ce code.");
                return;
            }
            $this->sendError(500, "Erreur lors de la mise à jour de la salle : " . $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la mise à jour de la salle : " . $e->getMessage());
        }
    }

    /**
     * Supprime une salle
     * DELETE /salles/{id}
     */
    public function delete(int $id): void
    {
        try {
            $success = $this->model->delete($id);

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Salle supprimée avec succès.'
                ]);
            } else {
                $this->sendError(404, "Salle introuvable.");
            }
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                $this->sendError(409, "Impossible de supprimer cette salle car elle est utilisée dans des emplois du temps.");
            } else {
                $this->sendError(500, "Erreur lors de la suppression de la salle : " . $e->getMessage());
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la suppression de la salle : " . $e->getMessage());
        }
    }

    /**
     * Change le statut d'une salle
     * POST /salles/{id}/statut
     */
    public function changeStatut(int $id): void
    {
        try {
            // Vérification de l'existence
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Salle introuvable.");
                return;
            }

            $data = $this->getJsonInput();

            if (empty($data['statut'])) {
                $this->sendError(400, "Le champ 'statut' est requis.");
                return;
            }

            // Validation du statut
            if (!in_array($data['statut'], Salle::STATUTS, true)) {
                $this->sendError(400, "Statut invalide. Valeurs acceptées : " . implode(', ', Salle::STATUTS));
                return;
            }
            if ($data['statut'] === 'indisponible' && $this->model->hasActiveSchedules($id)) {
                $this->sendError(409, "Réaffectez ou désactivez d'abord les créneaux actifs avant de rendre cette salle indisponible.");
                return;
            }

            $success = $this->model->changeStatut($id, $data['statut']);

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Statut de la salle modifié avec succès.',
                    'data' => ['statut' => $data['statut']]
                ]);
            } else {
                $this->sendError(400, "Impossible de modifier le statut.");
            }
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la modification du statut : " . $e->getMessage());
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
