<?php
/**
 * Contrôleur pour la gestion des sessions de formation
 */

require_once __DIR__ . '/../Model/Session.php';
require_once __DIR__ . '/../Model/Resultat.php';
require_once __DIR__ . '/../Model/Planification.php';

class SessionController
{
    private Session $model;

    public function __construct()
    {
        $this->model = new Session();
    }

    /**
     * Retourne toutes les sessions
     * GET /sessions
     */
    public function getAll(): void
    {
        try {
            $sessions = $this->model->getAllWithRelations();
            $this->sendResponse(200, [
                'success' => true,
                'data' => $sessions
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des sessions : " . $e->getMessage());
        }
    }

    /**
     * Retourne une session par son ID
     * GET /sessions/{id}
     */
    public function getById(int $id): void
    {
        try {
            $session = $this->model->find($id);
            
            if ($session === null) {
                $this->sendError(404, "Session introuvable.");
                return;
            }

            $this->sendResponse(200, [
                'success' => true,
                'data' => $session
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération de la session : " . $e->getMessage());
        }
    }

    /**
     * Retourne une session avec ses relations
     * GET /sessions/{id}/relations
     */
    public function getWithRelations(int $id): void
    {
        try {
            $session = $this->model->getWithRelations($id);
            
            if ($session === null) {
                $this->sendError(404, "Session introuvable.");
                return;
            }

            $this->sendResponse(200, [
                'success' => true,
                'data' => $session
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération de la session : " . $e->getMessage());
        }
    }

    /**
     * Retourne les sessions d'une année scolaire
     * GET /sessions/annee/{id_annee}
     */
    public function getByAnnee(int $id_annee): void
    {
        try {
            $sessions = $this->model->getByAnnee($id_annee);
            $this->sendResponse(200, [
                'success' => true,
                'data' => $sessions
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des sessions : " . $e->getMessage());
        }
    }

    /**
     * Retourne les sessions d'un type de formation
     * GET /sessions/type-formation/{id_type}
     */
    public function getByTypeFormation(int $id_type): void
    {
        try {
            $sessions = $this->model->getByTypeFormation($id_type);
            $this->sendResponse(200, [
                'success' => true,
                'data' => $sessions
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des sessions : " . $e->getMessage());
        }
    }

    /**
     * Retourne les sessions d'un niveau
     * GET /sessions/niveau/{id_niveau}
     */
    public function getByNiveau(int $id_niveau): void
    {
        try {
            $sessions = $this->model->getByNiveau($id_niveau);
            $this->sendResponse(200, [
                'success' => true,
                'data' => $sessions
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des sessions : " . $e->getMessage());
        }
    }

    /**
     * Crée une nouvelle session
     * POST /sessions
     */
    public function create(): void
    {
        try {
            $data = $this->getJsonInput();

            // Validation des champs requis
            $required = ['id_annee', 'id_type_formation', 'id_niveau', 'code', 'libelle', 'date_debut', 'date_fin'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $this->sendError(400, "Le champ '{$field}' est requis.");
                    return;
                }
            }

            // Validation des dates
            if (strtotime($data['date_fin']) < strtotime($data['date_debut'])) {
                $this->sendError(400, "La date de fin doit être égale ou postérieure à la date de début.");
                return;
            }

            // Valeur par défaut pour statut
            if (!isset($data['statut'])) {
                $data['statut'] = 'planifiee';
            }

            // Validation du statut
            if (!in_array($data['statut'], Session::STATUTS)) {
                $this->sendError(400, "Statut invalide. Valeurs acceptées : " . implode(', ', Session::STATUTS));
                return;
            }

            // Réutilisation de la configuration : activée par défaut, désactivable explicitement.
            $reuse = !array_key_exists('reprendre_configuration', $data) || filter_var($data['reprendre_configuration'], FILTER_VALIDATE_BOOLEAN);
            $generate = !array_key_exists('generer_planning', $data) || filter_var($data['generer_planning'], FILTER_VALIDATE_BOOLEAN);
            $sourceId = !empty($data['id_session_source']) ? (int) $data['id_session_source'] : null;
            unset($data['id_session_source'], $data['config_formation'], $data['config_version'], $data['passages_semaine']);

            $planning = new Planification();
            $db = Database::getInstance();
            $ownTx = !$db->inTransaction();
            if ($ownTx) {
                $db->beginTransaction();
            }
            try {
                $id = $this->model->create($data);
                // La session fige la configuration actuelle de sa formation : la modifier plus tard
                // ne changera pas cette session.
                $config = $planning->snapshotSession($id);
                $reprise = $reuse ? $planning->reprendre($id, $sourceId, $generate, Authorization::userId()) : null;
                if ($ownTx) {
                    $db->commit();
                }
            } catch (Throwable $error) {
                if ($ownTx && $db->inTransaction()) {
                    $db->rollBack();
                }
                throw $error;
            }

            $message = 'Session créée avec succès.';
            if ($reprise !== null) {
                $message .= ' ' . (new PlanificationController())->repriseMessage($reprise);
            }
            $this->sendResponse(201, [
                'success' => true,
                'message' => $message,
                'data' => ['id' => $id, 'configuration' => $config, 'reprise' => $reprise]
            ]);
        } catch (DomainException $e) {
            $this->sendError(409, $e->getMessage());
        } catch (Exception $e) {
            // Erreur de contrainte unique
            if (strpos($e->getMessage(), 'uk_sessions_code') !== false) {
                $this->sendError(409, "Une session avec ce code existe déjà.");
            } elseif (strpos($e->getMessage(), 'uk_sessions_annee_type_libelle') !== false) {
                $this->sendError(409, "Une session avec ce libellé existe déjà pour cette année et ce type de formation.");
            } else {
                $this->sendError(500, "Erreur lors de la création de la session : " . $e->getMessage());
            }
        }
    }

    /**
     * Met à jour une session
     * PUT /sessions/{id}
     */
    public function update(int $id): void
    {
        try {
            // Vérification de l'existence
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Session introuvable.");
                return;
            }

            $data = $this->getJsonInput();

            // Validation des dates si fournies
            if (isset($data['date_debut']) && isset($data['date_fin'])) {
                if (strtotime($data['date_fin']) < strtotime($data['date_debut'])) {
                    $this->sendError(400, "La date de fin doit être égale ou postérieure à la date de début.");
                    return;
                }
            }

            // Validation du statut si fourni
            if (isset($data['statut']) && !in_array($data['statut'], Session::STATUTS)) {
                $this->sendError(400, "Statut invalide. Valeurs acceptées : " . implode(', ', Session::STATUTS));
                return;
            }

            // La configuration figée de la session ne se modifie que par « adopter la configuration ».
            unset($data['config_formation'], $data['config_version'], $data['passages_semaine'], $data['id_session_source']);
            $success = $this->model->update($id, $data);

            if ($success) {
                // Nouvelles dates : signaler immédiatement les créneaux qui chevauchent désormais d'autres sessions.
                $conflicts = array_values(array_filter(
                    (new Planification())->conflicts(null, $id),
                    static fn($c) => $c['gravite'] === 'erreur'
                ));
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Session mise à jour avec succès.' . ($conflicts ? ' Attention : ' . count($conflicts) . ' conflit(s) de planning détecté(s) avec les nouvelles dates.' : ''),
                    'data' => ['conflits' => $conflicts]
                ]);
            } else {
                $this->sendError(400, "Aucune donnée valide à mettre à jour.");
            }
        } catch (Exception $e) {
            // Erreur de contrainte unique
            if (strpos($e->getMessage(), 'uk_sessions_code') !== false) {
                $this->sendError(409, "Une autre session avec ce code existe déjà.");
            } elseif (strpos($e->getMessage(), 'uk_sessions_annee_type_libelle') !== false) {
                $this->sendError(409, "Une autre session avec ce libellé existe déjà pour cette année et ce type de formation.");
            } else {
                $this->sendError(500, "Erreur lors de la mise à jour de la session : " . $e->getMessage());
            }
        }
    }

    /**
     * Supprime une session
     * DELETE /sessions/{id}
     */
    public function delete(int $id): void
    {
        try {
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Session introuvable.");
                return;
            }
            if ($this->model->getGroupCount($id) > 0) {
                $this->sendError(409, "Cette session contient des groupes. Supprimez ou réaffectez-les avant de supprimer la session.");
                return;
            }
            $success = $this->model->delete($id);

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Session supprimée avec succès.'
                ]);
            } else {
                $this->sendError(404, "Session introuvable.");
            }
        } catch (Exception $e) {
            // Erreur de contrainte de clé étrangère
            if (strpos($e->getMessage(), 'foreign key constraint') !== false) {
                $this->sendError(409, "Impossible de supprimer cette session car elle est utilisée par des groupes ou évaluations.");
            } else {
                $this->sendError(500, "Erreur lors de la suppression de la session : " . $e->getMessage());
            }
        }
    }

    /**
     * Change le statut d'une session
     * POST /sessions/{id}/statut
     */
    public function changeStatut(int $id): void
    {
        try {
            // Vérification de l'existence
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Session introuvable.");
                return;
            }

            $data = $this->getJsonInput();

            if (empty($data['statut'])) {
                $this->sendError(400, "Le champ 'statut' est requis.");
                return;
            }

            // Validation du statut
            if (!in_array($data['statut'], Session::STATUTS)) {
                $this->sendError(400, "Statut invalide. Valeurs acceptées : " . implode(', ', Session::STATUTS));
                return;
            }

            $success = $this->model->changeStatut($id, $data['statut']);

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Statut de la session modifié avec succès.',
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
