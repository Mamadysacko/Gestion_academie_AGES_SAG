<?php
/**
 * Contrôleur pour la gestion des groupes
 */

require_once __DIR__ . '/../Model/Groupe.php';

class GroupeController
{
    private Groupe $model;

    public function __construct()
    {
        $this->model = new Groupe();
    }

    /**
     * Retourne tous les groupes
     * GET /groupes
     */
    public function getAll(): void
    {
        try {
            $groupes = $this->model->getAll('code ASC');
            $this->sendResponse(200, [
                'success' => true,
                'data' => $groupes
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des groupes : " . $e->getMessage());
        }
    }

    /**
     * Retourne un groupe par son ID
     * GET /groupes/{id}
     */
    public function getById(int $id): void
    {
        try {
            $groupe = $this->model->find($id);
            
            if ($groupe === null) {
                $this->sendError(404, "Groupe introuvable.");
                return;
            }

            $this->sendResponse(200, [
                'success' => true,
                'data' => $groupe
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération du groupe : " . $e->getMessage());
        }
    }

    /**
     * Retourne un groupe avec sa session
     * GET /groupes/{id}/session
     */
    public function getWithSession(int $id): void
    {
        try {
            $groupe = $this->model->getWithSession($id);
            
            if ($groupe === null) {
                $this->sendError(404, "Groupe introuvable.");
                return;
            }

            $this->sendResponse(200, [
                'success' => true,
                'data' => $groupe
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération du groupe : " . $e->getMessage());
        }
    }

    /**
     * Retourne les groupes d'une session
     * GET /groupes/session/{id_session}
     */
    public function getBySession(int $id_session): void
    {
        try {
            $groupes = $this->model->getBySession($id_session);
            $this->sendResponse(200, [
                'success' => true,
                'data' => $groupes
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des groupes : " . $e->getMessage());
        }
    }

    /**
     * Retourne les groupes d'un jour de la semaine
     * GET /groupes/jour/{jour_semaine}
     */
    public function getByJour(int $jour_semaine): void
    {
        try {
            // Validation du jour
            if (!in_array($jour_semaine, Groupe::JOURS)) {
                $this->sendError(400, "Jour invalide. Valeurs acceptées : 1 (lundi) à 5 (vendredi).");
                return;
            }

            $groupes = $this->model->getByJour($jour_semaine);
            $this->sendResponse(200, [
                'success' => true,
                'data' => $groupes
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des groupes : " . $e->getMessage());
        }
    }

    /**
     * Crée un nouveau groupe
     * POST /groupes
     */
    public function create(): void
    {
        try {
            $data = $this->getJsonInput();

            // Validation des champs requis
            $required = ['id_session', 'code', 'libelle', 'jour_semaine'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $this->sendError(400, "Le champ '{$field}' est requis.");
                    return;
                }
            }

            foreach (['id_session', 'jour_semaine'] as $field) {
                if (!filter_var($data[$field], FILTER_VALIDATE_INT) || (int) $data[$field] <= 0) {
                    $this->sendError(400, "Le champ '{$field}' doit être un entier positif.");
                    return;
                }
                $data[$field] = (int) $data[$field];
            }
            $data['code'] = trim((string) $data['code']);
            $data['libelle'] = trim((string) $data['libelle']);
            if ($data['code'] === '' || mb_strlen($data['code']) > 30 ||
                $data['libelle'] === '' || mb_strlen($data['libelle']) > 100
            ) {
                $this->sendError(400, "Le code (30 caractères max.) et le libellé (100 caractères max.) sont obligatoires.");
                return;
            }

            // Validation du jour de la semaine
            if (!in_array((int)$data['jour_semaine'], Groupe::JOURS)) {
                $this->sendError(400, "Jour de semaine invalide. Valeurs acceptées : 1 (lundi) à 5 (vendredi).");
                return;
            }
            $hoursError = $this->normalizeHours($data);
            if ($hoursError !== null) {
                $this->sendError(400, $hoursError);
                return;
            }

            // Validation de la capacité si fournie
            if (isset($data['capacite']) && $data['capacite'] !== null && $data['capacite'] !== '') {
                if (!filter_var($data['capacite'], FILTER_VALIDATE_INT) ||
                    (int) $data['capacite'] <= 0 || (float) $data['capacite'] > 4294967295
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
                $data['statut'] = 1;
            }
            if (!in_array((string) $data['statut'], ['0', '1'], true)) {
                $this->sendError(400, "Le statut doit être égal à 0 ou 1.");
                return;
            }
            $data['statut'] = (int) $data['statut'];

            $id = $this->model->create($data);

            $this->sendResponse(201, [
                'success' => true,
                'message' => 'Groupe créé avec succès.',
                'data' => ['id' => $id]
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                $this->sendError(409, "Le groupe est déjà présent dans cette session ou la session sélectionnée n'existe pas.");
            } else {
                $this->sendError(500, "Erreur lors de la création du groupe : " . $e->getMessage());
            }
        } catch (Exception $e) {
            // Erreur de contrainte unique
            if (strpos($e->getMessage(), 'uk_groupes_session_code') !== false) {
                $this->sendError(409, "Un groupe avec ce code existe déjà pour cette session.");
            } else {
                $this->sendError(500, "Erreur lors de la création du groupe : " . $e->getMessage());
            }
        }
    }

    /**
     * Met à jour un groupe
     * PUT /groupes/{id}
     */
    public function update(int $id): void
    {
        try {
            // Vérification de l'existence
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Groupe introuvable.");
                return;
            }

            $data = $this->getJsonInput();

            foreach (['id_session', 'jour_semaine'] as $field) {
                if (array_key_exists($field, $data)) {
                    if (!filter_var($data[$field], FILTER_VALIDATE_INT) || (int) $data[$field] <= 0) {
                        $this->sendError(400, "Le champ '{$field}' doit être un entier positif.");
                        return;
                    }
                    $data[$field] = (int) $data[$field];
                }
            }
            foreach (['code' => 30, 'libelle' => 100] as $field => $maxLength) {
                if (array_key_exists($field, $data)) {
                    $data[$field] = trim((string) $data[$field]);
                    if ($data[$field] === '' || mb_strlen($data[$field]) > $maxLength) {
                        $this->sendError(400, "Le champ '{$field}' est obligatoire et limité à {$maxLength} caractères.");
                        return;
                    }
                }
            }

            // Validation du jour de la semaine si fourni
            if (isset($data['jour_semaine']) && !in_array((int)$data['jour_semaine'], Groupe::JOURS)) {
                $this->sendError(400, "Jour de semaine invalide. Valeurs acceptées : 1 (lundi) à 5 (vendredi).");
                return;
            }
            $hoursError = $this->normalizeHours($data, $this->model->find($id));
            if ($hoursError !== null) {
                $this->sendError(400, $hoursError);
                return;
            }

            // Validation de la capacité si fournie
            if (array_key_exists('capacite', $data)) {
                if ($data['capacite'] === '' || $data['capacite'] === null) {
                    $data['capacite'] = null;
                } elseif (!filter_var($data['capacite'], FILTER_VALIDATE_INT) ||
                    (int) $data['capacite'] <= 0 || (float) $data['capacite'] > 4294967295
                ) {
                    $this->sendError(400, "La capacité doit être un entier positif ou nulle.");
                    return;
                } else {
                    $data['capacite'] = (int) $data['capacite'];
                }
            }
            if (array_key_exists('statut', $data)) {
                if (!in_array((string) $data['statut'], ['0', '1'], true)) {
                    $this->sendError(400, "Le statut doit être égal à 0 ou 1.");
                    return;
                }
                $data['statut'] = (int) $data['statut'];
            }

            $success = $this->model->updateWithSchedules($id, $data);

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Groupe mis à jour avec succès. Ses salles suivent automatiquement son jour de cours.'
                ]);
            } else {
                $this->sendError(400, "Aucune donnée valide à mettre à jour.");
            }
        } catch (DomainException $e) {
            $this->sendError(409, $e->getMessage());
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                $this->sendError(409, "Le code du groupe est déjà utilisé dans cette session ou une référence est invalide.");
            } else {
                $this->sendError(500, "Erreur lors de la mise à jour du groupe : " . $e->getMessage());
            }
        } catch (Exception $e) {
            // Erreur de contrainte unique
            if (strpos($e->getMessage(), 'uk_groupes_session_code') !== false) {
                $this->sendError(409, "Un autre groupe avec ce code existe déjà pour cette session.");
            } else {
                $this->sendError(500, "Erreur lors de la mise à jour du groupe : " . $e->getMessage());
            }
        }
    }

    /**
     * Supprime un groupe
     * DELETE /groupes/{id}
     */
    public function delete(int $id): void
    {
        try {
            $success = $this->model->delete($id);

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Groupe supprimé avec succès.'
                ]);
            } else {
                $this->sendError(404, "Groupe introuvable.");
            }
        } catch (Exception $e) {
            // Erreur de contrainte de clé étrangère
            if (strpos($e->getMessage(), 'foreign key constraint') !== false) {
                $this->sendError(409, "Impossible de supprimer ce groupe car il est utilisé dans des emplois du temps.");
            } else {
                $this->sendError(500, "Erreur lors de la suppression du groupe : " . $e->getMessage());
            }
        }
    }

    /**
     * Salles du groupe (formateur, effectif, places, auditeurs)
     * GET /groupes/{id}/salles
     */
    public function getSalles(int $id): void
    {
        try {
            $groupe = $this->model->getWithSession($id);
            if ($groupe === null) {
                $this->sendError(404, "Groupe introuvable.");
                return;
            }

            $this->sendResponse(200, [
                'success' => true,
                'data' => [
                    'groupe' => $groupe,
                    'salles' => $this->model->getSalles($id),
                ]
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des salles du groupe : " . $e->getMessage());
        }
    }

    /**
     * Retourne les places restantes dans un groupe
     * GET /groupes/{id}/places-restantes
     */
    public function getPlacesRestantes(int $id): void
    {
        try {
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Groupe introuvable.");
                return;
            }

            $places = $this->model->getPlacesRestantes($id);

            if ($places === null) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Aucune capacité définie pour ce groupe.',
                    'data' => ['places_restantes' => null]
                ]);
            } else {
                $this->sendResponse(200, [
                    'success' => true,
                    'data' => ['places_restantes' => $places]
                ]);
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors du calcul des places restantes : " . $e->getMessage());
        }
    }

    /* ----------------------------------------------------------------
     | Helpers
     |---------------------------------------------------------------- */

    /**
     * Horaires du groupe (HH:MM) : facultatifs, l'heure de fin doit suivre l'heure de début.
     * @return string|null Message d'erreur.
     */
    private function normalizeHours(array &$data, ?array $current = null): ?string
    {
        foreach (['heure_debut', 'heure_fin'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            if ($data[$field] === '' || $data[$field] === null) {
                unset($data[$field]);
                continue;
            }
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $data[$field])) {
                return 'Les horaires doivent respecter le format HH:MM.';
            }
            $data[$field] = substr((string) $data[$field], 0, 5) . ':00';
        }
        $start = $data['heure_debut'] ?? $current['heure_debut'] ?? '08:00:00';
        $end = $data['heure_fin'] ?? $current['heure_fin'] ?? '12:00:00';
        if ($end <= $start) {
            return 'L’heure de fin doit être postérieure à l’heure de début.';
        }
        return null;
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
