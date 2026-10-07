<?php
/**
 * Contrôleur pour la gestion des présences
 */

require_once __DIR__ . '/../Model/Presence.php';

class PresenceController
{
    private Presence $model;

    public function __construct()
    {
        $this->model = new Presence();
    }

    public function getAll(): void
    {
        try {
            $presences = $this->model->getAllWithDetails();
            $this->sendResponse(200, ['success' => true, 'data' => $presences]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getById(int $id): void
    {
        try {
            $presence = $this->model->find($id);
            if ($presence === null) {
                $this->sendError(404, "Présence introuvable.");
                return;
            }
            $this->sendResponse(200, ['success' => true, 'data' => $presence]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getByInscription(int $id_inscription): void
    {
        try {
            $presences = $this->model->getByInscription($id_inscription);
            $this->sendResponse(200, ['success' => true, 'data' => $presences]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getByDate(string $date): void
    {
        try {
            $presences = $this->model->getByDate($date);
            $this->sendResponse(200, ['success' => true, 'data' => $presences]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getByFormateur(int $id_formateur): void
    {
        try {
            $presences = $this->model->getByFormateur($id_formateur);
            $this->sendResponse(200, ['success' => true, 'data' => $presences]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getByEmploiDuTemps(int $id_edt): void
    {
        try {
            $presences = $this->model->getByEmploiDuTemps($id_edt);
            $this->sendResponse(200, ['success' => true, 'data' => $presences]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getStatistiques(int $id_inscription): void
    {
        try {
            $stats = $this->model->getStatistiques($id_inscription);
            $this->sendResponse(200, ['success' => true, 'data' => $stats]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function create(): void
    {
        $this->markPresence();
    }

    public function update(int $id): void
    {
        try {
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Présence introuvable.");
                return;
            }

            $data = $this->getJsonInput();

            $allowedFields = ['statut', 'conge_date_debut', 'conge_date_fin', 'observation'];
            if (!$data || array_diff(array_keys($data), $allowedFields)) {
                $this->sendError(400, "Seuls le statut, les dates de congé et l’observation peuvent être modifiés.");
                return;
            }

            $success = $this->model->updateAttendance($id, $data);

            if ($success) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Présence mise à jour.']);
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
                $this->sendResponse(200, ['success' => true, 'message' => 'Présence supprimée.']);
            } else {
                $this->sendError(404, "Présence introuvable.");
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function markPresence(): void
    {
        try {
            $data = $this->getJsonInput();

            $required = ['id_inscription', 'date_presence', 'statut', 'id_formateur', 'id_emploi_du_temps'];
            foreach ($required as $field) {
                if (!isset($data[$field]) || $data[$field] === '') {
                    $this->sendError(400, "Le champ '{$field}' est requis.");
                    return;
                }
            }

            foreach (['id_inscription', 'id_formateur', 'id_emploi_du_temps'] as $field) {
                if (!filter_var($data[$field], FILTER_VALIDATE_INT) || (int) $data[$field] <= 0) {
                    $this->sendError(400, "Le champ '{$field}' doit être un entier positif.");
                    return;
                }
                $data[$field] = (int) $data[$field];
            }
            if (!in_array($data['statut'], Presence::STATUTS, true)) {
                $this->sendError(400, "Statut invalide. Valeurs : " . implode(', ', Presence::STATUTS));
                return;
            }

            $success = $this->model->markPresence(
                $data['id_inscription'],
                (string) $data['date_presence'],
                $data['statut'],
                $data['id_formateur'],
                $data['id_emploi_du_temps'],
                isset($data['observation']) && $data['observation'] !== '' ? (string) $data['observation'] : null,
                $data['conge_date_debut'] ?? null,
                $data['conge_date_fin'] ?? null
            );

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Présence marquée avec succès.'
                ]);
            } else {
                $this->sendError(400, "Impossible de marquer la présence.");
            }
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
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
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function sendError(int $code, string $message): void
    {
        $this->sendResponse($code, ['success' => false, 'error' => $message]);
    }
}
