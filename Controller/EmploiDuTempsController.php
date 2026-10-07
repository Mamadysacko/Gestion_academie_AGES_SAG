<?php
/**
 * Contrôleur pour la gestion des emplois du temps
 */

require_once __DIR__ . '/../Model/EmploiDuTemps.php';

class EmploiDuTempsController
{
    private EmploiDuTemps $model;

    public function __construct()
    {
        $this->model = new EmploiDuTemps();
    }

    public function getAll(): void
    {
        try {
            $edts = $this->model->getPlanningComplet();
            $this->sendResponse(200, ['success' => true, 'data' => $edts]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getById(int $id): void
    {
        try {
            $edt = $this->model->getPlanningById($id);
            if ($edt === null) {
                $this->sendError(404, "Emploi du temps introuvable.");
                return;
            }
            $this->sendResponse(200, ['success' => true, 'data' => $edt]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getByGroupe(int $id_groupe): void
    {
        try {
            $edts = $this->model->getByGroupe($id_groupe);
            $this->sendResponse(200, ['success' => true, 'data' => $edts]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getBySalle(int $id_salle): void
    {
        try {
            $edts = $this->model->getBySalle($id_salle);
            $this->sendResponse(200, ['success' => true, 'data' => $edts]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getByFormateur(int $id_formateur): void
    {
        try {
            $edts = $this->model->getByFormateur($id_formateur);
            $this->sendResponse(200, ['success' => true, 'data' => $edts]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getByJour(int $jour_semaine): void
    {
        try {
            if (!in_array($jour_semaine, EmploiDuTemps::JOURS)) {
                $this->sendError(400, "Jour invalide. Valeurs : 1 à 5.");
                return;
            }
            $edts = $this->model->getByJour($jour_semaine);
            $this->sendResponse(200, ['success' => true, 'data' => $edts]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getByAnnee(int $id_annee): void
    {
        try {
            $edts = $this->model->getByAnnee($id_annee);
            $this->sendResponse(200, ['success' => true, 'data' => $edts]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getPlanningComplet(): void
    {
        try {
            $data = $this->getJsonInput();
            $id_annee = isset($data['id_annee']) ? (int)$data['id_annee'] : null;
            
            $edts = $this->model->getPlanningComplet($id_annee);
            $this->sendResponse(200, ['success' => true, 'data' => $edts]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function create(): void
    {
        try {
            $data = $this->getJsonInput();

            $required = ['id_annee', 'id_groupe', 'id_salle', 'id_formateur', 'jour_semaine'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $this->sendError(400, "Le champ '{$field}' est requis.");
                    return;
                }
            }

            foreach (['id_annee', 'id_groupe', 'id_salle', 'id_formateur', 'jour_semaine'] as $field) {
                if (!filter_var($data[$field], FILTER_VALIDATE_INT) || (int) $data[$field] <= 0) {
                    $this->sendError(400, "Le champ '{$field}' doit être un entier positif.");
                    return;
                }
                $data[$field] = (int) $data[$field];
            }
            if (!isset($data['statut'])) {
                $data['statut'] = 1;
            }
            if (!in_array((string) $data['statut'], ['0', '1'], true)) {
                $this->sendError(400, "Le statut doit être égal à 0 ou 1.");
                return;
            }

            if (!in_array((int)$data['jour_semaine'], EmploiDuTemps::JOURS)) {
                $this->sendError(400, "Jour invalide. Valeurs : 1 à 5.");
                return;
            }

            $validation = $this->model->validateAssignment($data);
            if ($validation !== null) {
                $this->sendError(400, $validation);
                return;
            }

            // Vérifier disponibilité salle
            if (!$this->model->isSalleLibre($data['id_annee'], $data['id_salle'], $data['jour_semaine'])) {
                $this->sendError(409, "Cette salle est déjà occupée ce jour-là.");
                return;
            }

            // Vérifier disponibilité formateur
            if (!$this->model->isFormateurLibre($data['id_annee'], $data['id_formateur'], $data['jour_semaine'])) {
                $this->sendError(409, "Ce formateur est déjà occupé ce jour-là.");
                return;
            }

            $id = $this->model->create($data);

            $this->sendResponse(201, [
                'success' => true,
                'message' => 'Emploi du temps créé avec succès.',
                'data' => ['id' => $id]
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                $this->sendError(409, "Ce créneau entre en conflit avec une autre réservation de salle, de formateur ou de groupe.");
                return;
            }
            $this->sendError(500, "Erreur : " . $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function update(int $id): void
    {
        try {
            $current = $this->model->find($id);
            if ($current === null) {
                $this->sendError(404, "Emploi du temps introuvable.");
                return;
            }

            $data = $this->getJsonInput();

            if (isset($data['jour_semaine']) && !in_array((int)$data['jour_semaine'], EmploiDuTemps::JOURS)) {
                $this->sendError(400, "Jour invalide. Valeurs : 1 à 5.");
                return;
            }

            $assignment = array_merge($current, $data);
            if (!in_array((string) $assignment['statut'], ['0', '1'], true)) {
                $this->sendError(400, "Le statut doit être égal à 0 ou 1.");
                return;
            }
            foreach (['id_annee', 'id_groupe', 'id_salle', 'id_formateur', 'jour_semaine'] as $field) {
                if (!isset($assignment[$field]) || !filter_var($assignment[$field], FILTER_VALIDATE_INT) || (int) $assignment[$field] <= 0) {
                    $this->sendError(400, "Le champ '{$field}' doit être un entier positif.");
                    return;
                }
                $assignment[$field] = (int) $assignment[$field];
            }

            $validation = $this->model->validateAssignment($assignment, $id);
            if ($validation !== null) {
                $this->sendError(400, $validation);
                return;
            }
            if ((int) $assignment['statut'] === 1) {
                if (!$this->model->isSalleLibre($assignment['id_annee'], $assignment['id_salle'], $assignment['jour_semaine'], $id)) {
                    $this->sendError(409, "Cette salle est déjà occupée ce jour-là.");
                    return;
                }
                if (!$this->model->isFormateurLibre($assignment['id_annee'], $assignment['id_formateur'], $assignment['jour_semaine'], $id)) {
                    $this->sendError(409, "Ce formateur est déjà occupé ce jour-là.");
                    return;
                }
            }

            $success = $this->model->update($id, $assignment);

            if ($success) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Emploi du temps mis à jour.']);
            } else {
                $this->sendError(400, "Aucune donnée valide.");
            }
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                $this->sendError(409, "Ce créneau entre en conflit avec une autre réservation de salle, de formateur ou de groupe.");
                return;
            }
            $this->sendError(500, "Erreur : " . $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function delete(int $id): void
    {
        try {
            $success = $this->model->delete($id);
            if ($success) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Emploi du temps supprimé.']);
            } else {
                $this->sendError(404, "Emploi du temps introuvable.");
            }
        } catch (Exception $e) {
            if ((string) $e->getCode() === '23000') {
                $this->sendError(409, "Cet emploi du temps est utilisé par des inscriptions ou des présences. Désactivez-le plutôt que de le supprimer.");
                return;
            }
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function checkDisponibilite(): void
    {
        try {
            $data = $this->getJsonInput();

            if (empty($data['id_annee']) || empty($data['jour_semaine'])) {
                $this->sendError(400, "Les champs 'id_annee' et 'jour_semaine' sont requis.");
                return;
            }

            $result = [
                'salle_libre' => null,
                'formateur_libre' => null
            ];

            if (!empty($data['id_salle'])) {
                $result['salle_libre'] = $this->model->isSalleLibre(
                    (int)$data['id_annee'],
                    (int)$data['id_salle'],
                    (int)$data['jour_semaine'],
                    isset($data['exclude_id']) ? (int)$data['exclude_id'] : null
                );
            }

            if (!empty($data['id_formateur'])) {
                $result['formateur_libre'] = $this->model->isFormateurLibre(
                    (int)$data['id_annee'],
                    (int)$data['id_formateur'],
                    (int)$data['jour_semaine'],
                    isset($data['exclude_id']) ? (int)$data['exclude_id'] : null
                );
            }

            $this->sendResponse(200, ['success' => true, 'data' => $result]);
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
