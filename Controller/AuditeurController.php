<?php
/**
 * Contrôleur pour la gestion des auditeurs
 */

require_once __DIR__ . '/../Model/Auditeur.php';

class AuditeurController
{
    private Auditeur $model;

    public function __construct()
    {
        $this->model = new Auditeur();
    }

    /**
     * Retourne tous les auditeurs
     * GET /auditeurs
     */
    public function getAll(): void
    {
        try {
            $auditeurs = $this->model->getAllWithType();
            $this->sendResponse(200, [
                'success' => true,
                'data' => $auditeurs
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des auditeurs : " . $e->getMessage());
        }
    }

    /**
     * Retourne un auditeur par son ID
     * GET /auditeurs/{id}
     */
    public function getById(int $id): void
    {
        try {
            $auditeur = $this->model->findWithType($id);

            if ($auditeur === null) {
                $this->sendError(404, "Auditeur introuvable.");
                return;
            }

            $this->sendResponse(200, [
                'success' => true,
                'data' => $auditeur
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération de l'auditeur : " . $e->getMessage());
        }
    }

    /**
     * Dossier complet : identité, inscriptions et affectations, présences, notes, résultats et passages.
     * Les présences et les résultats ne sont joints que si le rôle peut les consulter.
     * GET /auditeurs/{id}/dossier
     */
    public function dossier(int $id): void
    {
        try {
            $auditeur = $this->model->findWithType($id);
            if ($auditeur === null) {
                $this->sendError(404, "Auditeur introuvable.");
                return;
            }
            $user = Authorization::currentUser();
            $isAdmin = ($user['role_code'] ?? '') === 'admin';
            $canRead = static fn(string $module): bool => $isAdmin || in_array('read', $user['permissions'][$module] ?? [], true);

            require_once __DIR__ . '/../Model/Inscription.php';
            $dossier = [
                'auditeur' => $auditeur,
                'inscriptions' => (new Inscription())->getByAuditeur($id),
                'presences' => null,
                'resultats' => null,
                'notes' => null,
            ];
            if ($canRead('presences')) {
                $dossier['presences'] = $this->model->getPresenceHistory($id);
            }
            if ($canRead('evaluations')) {
                require_once __DIR__ . '/../Model/Resultat.php';
                $dossier['resultats'] = (new Resultat())->getAuditeurParcours($id)['parcours'];
                $dossier['notes'] = $this->model->getNotes($id);
            }
            $this->sendResponse(200, ['success' => true, 'data' => $dossier]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors du chargement du dossier : " . $e->getMessage());
        }
    }

    /**
     * Retourne un auditeur par son matricule
     * GET /auditeurs/matricule/{matricule}
     */
    public function getByMatricule(string $matricule): void
    {
        try {
            $auditeur = $this->model->findByMatricule($matricule);
            
            if ($auditeur === null) {
                $this->sendError(404, "Auditeur avec le matricule '{$matricule}' introuvable.");
                return;
            }

            $this->sendResponse(200, [
                'success' => true,
                'data' => $auditeur
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération de l'auditeur : " . $e->getMessage());
        }
    }

    /**
     * Recherche des auditeurs
     * GET /auditeurs/search/{term}
     */
    public function search(string $term): void
    {
        try {
            $auditeurs = $this->model->search($term);
            $this->sendResponse(200, [
                'success' => true,
                'data' => $auditeurs,
                'count' => count($auditeurs)
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la recherche : " . $e->getMessage());
        }
    }

    /**
     * Crée un nouvel auditeur
     * POST /auditeurs
     */
    public function create(): void
    {
        try {
            $data = $this->getJsonInput();

            // Validation des champs requis
            $required = ['matricule', 'nom', 'prenom'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $this->sendError(400, "Le champ '{$field}' est requis.");
                    return;
                }
            }

            // Vérification de l'unicité du matricule
            if ($this->model->findByMatricule($data['matricule']) !== null) {
                $this->sendError(409, "Un auditeur avec ce matricule existe déjà.");
                return;
            }

            // Validation du sexe si fourni
            if (isset($data['sexe']) && !in_array($data['sexe'], Auditeur::SEXES)) {
                $this->sendError(400, "Sexe invalide. Valeurs acceptées : M, F");
                return;
            }

            // Validation de l'email si fourni
            if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $this->sendError(400, "L'email fourni n'est pas valide.");
                return;
            }

            $error = $this->normalizeEmployment($data);
            if ($error !== null) {
                $this->sendError(400, $error);
                return;
            }

            $id = $this->model->create($data);

            $this->sendResponse(201, [
                'success' => true,
                'message' => 'Auditeur créé avec succès.',
                'data' => ['id' => $id]
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la création de l'auditeur : " . $e->getMessage());
        }
    }

    /**
     * Met à jour un auditeur
     * PUT /auditeurs/{id}
     */
    public function update(int $id): void
    {
        try {
            // Vérification de l'existence
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Auditeur introuvable.");
                return;
            }

            $data = $this->getJsonInput();

            // Vérification de l'unicité du matricule (si modifié)
            if (isset($data['matricule'])) {
                $existing = $this->model->findByMatricule($data['matricule']);
                if ($existing !== null && (int)$existing['id'] !== $id) {
                    $this->sendError(409, "Un autre auditeur avec ce matricule existe déjà.");
                    return;
                }
            }

            // Validation du sexe si fourni
            if (isset($data['sexe']) && !in_array($data['sexe'], Auditeur::SEXES)) {
                $this->sendError(400, "Sexe invalide. Valeurs acceptées : M, F");
                return;
            }

            // Validation de l'email si fourni
            if (isset($data['email']) && !empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $this->sendError(400, "L'email fourni n'est pas valide.");
                return;
            }

            $error = $this->normalizeEmployment($data);
            if ($error !== null) {
                $this->sendError(400, $error);
                return;
            }

            $success = $this->model->update($id, $data);

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Auditeur mis à jour avec succès.'
                ]);
            } else {
                $this->sendError(400, "Aucune donnée valide à mettre à jour.");
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la mise à jour de l'auditeur : " . $e->getMessage());
        }
    }

    /**
     * Supprime un auditeur
     * DELETE /auditeurs/{id}
     */
    public function delete(int $id): void
    {
        try {
            $success = $this->model->delete($id);

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Auditeur supprimé avec succès.'
                ]);
            } else {
                $this->sendError(404, "Auditeur introuvable.");
            }
        } catch (Exception $e) {
            // Erreur de contrainte de clé étrangère
            if (strpos($e->getMessage(), 'foreign key constraint') !== false) {
                $this->sendError(409, "Impossible de supprimer cet auditeur car il possède des inscriptions.");
            } else {
                $this->sendError(500, "Erreur lors de la suppression de l'auditeur : " . $e->getMessage());
            }
        }
    }

    /* ----------------------------------------------------------------
     | Helpers
     |---------------------------------------------------------------- */

    /**
     * Normalise la position et le type d'employé (champs facultatifs).
     * @return string|null Message d'erreur ou null si valide.
     */
    private function normalizeEmployment(array &$data): ?string
    {
        if (array_key_exists('position', $data)) {
            $data['position'] = trim((string) $data['position']);
            if ($data['position'] === '') {
                $data['position'] = null;
            } elseif (mb_strlen($data['position']) > 100) {
                return 'La position ne peut pas dépasser 100 caractères.';
            }
        }

        if (array_key_exists('id_type_employe', $data)) {
            if ($data['id_type_employe'] === '' || $data['id_type_employe'] === null) {
                $data['id_type_employe'] = null;
            } elseif (!filter_var($data['id_type_employe'], FILTER_VALIDATE_INT)
                || !$this->model->typeEmployeExists((int) $data['id_type_employe'])
            ) {
                return "Le type d'employé sélectionné n'existe pas.";
            } else {
                $data['id_type_employe'] = (int) $data['id_type_employe'];
            }
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
