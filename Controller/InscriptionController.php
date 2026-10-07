<?php
/**
 * Contrôleur pour la gestion des inscriptions
 */

require_once __DIR__ . '/../Model/Inscription.php';

class InscriptionController
{
    private Inscription $model;

    public function __construct()
    {
        $this->model = new Inscription();
    }

    /**
     * GET /inscriptions
     */
    public function getAll(): void
    {
        try {
            $inscriptions = $this->model->getAllWithDetails();
            $this->sendResponse(200, [
                'success' => true,
                'data' => $inscriptions
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des inscriptions : " . $e->getMessage());
        }
    }

    /**
     * GET /inscriptions/{id}
     */
    public function getById(int $id): void
    {
        try {
            $inscription = $this->model->find($id);
            
            if ($inscription === null) {
                $this->sendError(404, "Inscription introuvable.");
                return;
            }

            $this->sendResponse(200, ['success' => true, 'data' => $inscription]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    /**
     * GET /inscriptions/{id}/details
     */
    public function getWithDetails(int $id): void
    {
        try {
            $inscription = $this->model->getWithDetails($id);
            
            if ($inscription === null) {
                $this->sendError(404, "Inscription introuvable.");
                return;
            }

            $this->sendResponse(200, ['success' => true, 'data' => $inscription]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    /**
     * GET /inscriptions/auditeur/{id_auditeur}
     */
    public function getByAuditeur(int $id_auditeur): void
    {
        try {
            $inscriptions = $this->model->getByAuditeur($id_auditeur);
            $this->sendResponse(200, ['success' => true, 'data' => $inscriptions]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    /**
     * GET /inscriptions/groupe/{id_groupe}
     */
    public function getByGroupe(int $id_groupe): void
    {
        try {
            $inscriptions = $this->model->getByGroupe($id_groupe);
            $this->sendResponse(200, ['success' => true, 'data' => $inscriptions]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    /**
     * GET /inscriptions/departement/{id_departement}
     */
    public function getByDepartement(int $id_departement): void
    {
        try {
            $inscriptions = $this->model->getByDepartement($id_departement);
            $this->sendResponse(200, ['success' => true, 'data' => $inscriptions]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    /**
     * POST /inscriptions
     */
    public function create(): void
    {
        try {
            $data = $this->getJsonInput();

            $required = ['id_auditeur', 'id_emploi_du_temps', 'id_departement', 'date_inscription'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $this->sendError(400, "Le champ '{$field}' est requis.");
                    return;
                }
            }

            foreach (['id_auditeur', 'id_emploi_du_temps', 'id_departement'] as $field) {
                if (!filter_var($data[$field], FILTER_VALIDATE_INT) || (int) $data[$field] <= 0) {
                    $this->sendError(400, "Le champ '{$field}' doit être un entier positif.");
                    return;
                }
                $data[$field] = (int) $data[$field];
            }

            $date = DateTime::createFromFormat('!Y-m-d', (string) $data['date_inscription']);
            if (!$date || $date->format('Y-m-d') !== $data['date_inscription']) {
                $this->sendError(400, "La date d’inscription doit respecter le format AAAA-MM-JJ.");
                return;
            }

            $data['statut'] = $data['statut'] ?? 'active';
            if (!in_array($data['statut'], Inscription::STATUTS, true)) {
                $this->sendError(400, "Statut invalide.");
                return;
            }

            $id = $this->model->createValidated($data);

            $this->sendResponse(201, [
                'success' => true,
                'message' => 'Inscription créée avec succès.',
                'data' => ['id' => $id]
            ]);
        } catch (DomainException $e) {
            $this->sendError(409, $e->getMessage());
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                $this->sendError(409, "Cette inscription existe déjà ou une référence sélectionnée est invalide.");
            } else {
                $this->sendError(500, "Erreur lors de la création de l’inscription : " . $e->getMessage());
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la création de l’inscription : " . $e->getMessage());
        }
    }

    /**
     * PUT /inscriptions/{id}
     */
    public function update(int $id): void
    {
        try {
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Inscription introuvable.");
                return;
            }

            $data = $this->getJsonInput();

            if (!$data || array_diff(array_keys($data), ['statut']) || !isset($data['statut'])) {
                $this->sendError(400, "Seul le statut de l’inscription peut être modifié.");
                return;
            }

            $success = $this->model->changeStatut($id, (string) $data['statut']);

            if ($success) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Inscription mise à jour avec succès.']);
            } else {
                $this->sendError(400, "Aucune donnée valide à mettre à jour.");
            }
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    /**
     * DELETE /inscriptions/{id}
     */
    public function delete(int $id): void
    {
        try {
            $success = $this->model->delete($id);

            if ($success) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Inscription supprimée avec succès.']);
            } else {
                $this->sendError(404, "Inscription introuvable.");
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    /**
     * POST /inscriptions/{id}/statut
     */
    public function changeStatut(int $id): void
    {
        try {
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Inscription introuvable.");
                return;
            }

            $data = $this->getJsonInput();

            if (empty($data['statut'])) {
                $this->sendError(400, "Le champ 'statut' est requis.");
                return;
            }

            $success = $this->model->changeStatut($id, $data['statut']);

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Statut modifié avec succès.',
                    'data' => ['statut' => $data['statut']]
                ]);
            } else {
                $this->sendError(400, "Impossible de modifier le statut.");
            }
        } catch (InvalidArgumentException $e) {
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
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function sendError(int $code, string $message): void
    {
        $this->sendResponse($code, ['success' => false, 'error' => $message]);
    }
}
