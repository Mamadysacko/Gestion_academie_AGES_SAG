<?php
/**
 * Contrôleur pour la gestion des auditeurs
 */

require_once __DIR__ . '/../Model/Auditeur.php';
require_once __DIR__ . '/../Model/User.php';
require_once __DIR__ . '/../Model/Notification.php';

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
     * Crée un nouvel auditeur + compte utilisateur automatique
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

            // Vérification de l'unicité avant les autres validations métier.
            if ($this->model->findByMatricule($data['matricule']) !== null) {
                $this->sendError(409, "Un auditeur avec ce matricule existe déjà.");
                return;
            }

            // Chaque compte auditeur doit disposer d'un téléphone pour la connexion.
            $email     = !empty($data['email']) ? trim((string) $data['email']) : null;
            $telephone = !empty($data['telephone']) ? trim((string) $data['telephone']) : null;
            if ($telephone === null || $telephone === '') {
                $this->sendError(400, "Le numéro de téléphone est obligatoire pour créer un compte auditeur.");
                return;
            }
            $data['telephone'] = $telephone;
            $data['email'] = $email;

            // Validation du sexe si fourni
            if (isset($data['sexe']) && !in_array($data['sexe'], Auditeur::SEXES)) {
                $this->sendError(400, "Sexe invalide. Valeurs acceptées : M, F");
                return;
            }

            // Validation de l'email si fourni
            if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->sendError(400, "L'email fourni n'est pas valide.");
                return;
            }

            $error = $this->normalizeEmployment($data);
            if ($error !== null) {
                $this->sendError(400, $error);
                return;
            }

            $password = $this->generateTemporaryPassword();

            // --- Transaction : créer auditeur + compte ---
            $userModel  = new User();
            $notifModel = new Notification();

            // Vérifier unicité email/telephone dans users
            if ($email !== null && $userModel->findByEmail($email) !== null) {
                $this->sendError(409, "Un compte utilisateur avec cet email existe déjà.");
                return;
            }
            if ($userModel->findByPhone($telephone) !== null) {
                $this->sendError(409, "Ce numéro de téléphone est déjà utilisé par un autre compte.");
                return;
            }

            // Trouver le role_id auditeur
            $db = $userModel->getDatabase();
            $roleId = (int) $db->query("SELECT id FROM roles WHERE code = 'auditeur' LIMIT 1")->fetchColumn();
            if (!$roleId) {
                $this->sendError(500, "Le rôle 'auditeur' n'existe pas. Exécutez la migration.");
                return;
            }

            $ownTx = !$db->inTransaction();
            if ($ownTx) {
                $db->beginTransaction();
            }
            try {
                // 1. Créer l'auditeur
                $auditeurId = $this->model->create($data);

                // 2. Créer le compte utilisateur
                $userId = $userModel->create([
                    'nom'       => $data['nom'],
                    'prenom'    => $data['prenom'],
                    'email'     => $email,
                    'telephone' => $telephone,
                    'password'  => $password,
                    'role_id'   => $roleId,
                    'actif'     => 1,
                ]);

                // Marquer first_login
                $db->prepare("UPDATE users SET first_login = 1 WHERE id = :id")->execute(['id' => $userId]);

                // 3. Lier auditeur ↔ user
                $db->prepare("UPDATE auditeurs SET user_id = :uid WHERE id = :aid")
                   ->execute(['uid' => $userId, 'aid' => $auditeurId]);

                // 4. Notification pour retrouver le compte après connexion.
                $login = $email ?: $telephone;
                // Toujours créer une notification de bienvenue
                $notifModel->createWelcome($userId, $login);

                if ($ownTx) {
                    $db->commit();
                }
            } catch (Throwable $error) {
                if ($ownTx && $db->inTransaction()) {
                    $db->rollBack();
                }
                throw $error;
            }

            $response = [
                'success' => true,
                'message' => 'Auditeur créé avec succès. Un compte de connexion a été généré automatiquement.',
                'data'    => [
                    'id'                   => $auditeurId,
                    'user_id'              => $userId,
                    'nom'                  => $data['nom'],
                    'prenom'               => $data['prenom'],
                    'matricule'            => $data['matricule'],
                    'telephone'            => $telephone,
                    'email'                => $email,
                    'login'                => $login,
                    'password_temporaire'  => $password,
                ],
            ];

            $this->sendResponse(201, $response);
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

            if (array_key_exists('telephone', $data) && trim((string) $data['telephone']) === '') {
                $this->sendError(400, "Le numéro de téléphone est obligatoire.");
                return;
            }

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

            $auditeur = $this->model->findWithType($id);
            $effectiveTelephone = trim((string) ($data['telephone'] ?? $auditeur['telephone'] ?? ''));
            if (empty($auditeur['user_id']) && $effectiveTelephone !== '') {
                $this->createAccountForExistingAuditeur($id, $auditeur, $data);
                return;
            }
            if (!empty($auditeur['user_id'])) {
                $userModel = new User();
                if (isset($data['email']) && $data['email'] !== '') {
                    $existing = $userModel->findByEmail(trim((string) $data['email']));
                    if ($existing !== null && (int) $existing['id'] !== (int) $auditeur['user_id']) {
                        $this->sendError(409, "Cet email est déjà utilisé par un autre compte.");
                        return;
                    }
                }
                if (isset($data['telephone']) && $data['telephone'] !== '') {
                    $existing = $userModel->findByPhone(trim((string) $data['telephone']));
                    if ($existing !== null && (int) $existing['id'] !== (int) $auditeur['user_id']) {
                        $this->sendError(409, "Ce téléphone est déjà utilisé par un autre compte.");
                        return;
                    }
                }
            }

            $error = $this->normalizeEmployment($data);
            if ($error !== null) {
                $this->sendError(400, $error);
                return;
            }

            $success = $this->model->update($id, $data);

            if ($success) {
                // Garder le compte de connexion synchronisé avec le dossier auditeur.
                $auditeur = $this->model->findWithType($id);
                if (!empty($auditeur['user_id'])) {
                    $userUpdate = [];
                    foreach (['nom', 'prenom', 'telephone', 'email'] as $field) {
                        if (array_key_exists($field, $data)) {
                            $userUpdate[$field] = $data[$field] === '' ? null : $data[$field];
                        }
                    }
                    if (!empty($userUpdate)) {
                        $userModel = new User();
                        $userModel->update((int) $auditeur['user_id'], $userUpdate);
                    }
                }
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
     * Normalise la position (poste) et le type d'employé (champs facultatifs).
     * @return string|null Message d'erreur ou null si valide.
     */
    private function normalizeEmployment(array &$data): ?string
    {
        if (array_key_exists('id_position', $data)) {
            if ($data['id_position'] === '' || $data['id_position'] === null) {
                $data['id_position'] = null;
            } elseif (!filter_var($data['id_position'], FILTER_VALIDATE_INT)
                || !$this->model->positionExists((int) $data['id_position'])
            ) {
                return "La position sélectionnée n'existe pas.";
            } else {
                $data['id_position'] = (int) $data['id_position'];
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

    private function createAccountForExistingAuditeur(int $id, array $auditeur, array $data): void
    {
        $telephone = trim((string) ($data['telephone'] ?? $auditeur['telephone'] ?? ''));
        if ($telephone === '') {
            $this->sendError(400, 'Un numéro de téléphone est obligatoire pour créer le compte de cet auditeur.');
            return;
        }
        $email = isset($data['email']) && trim((string) $data['email']) !== ''
            ? trim((string) $data['email'])
            : null;
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->sendError(400, "L'email fourni n'est pas valide.");
            return;
        }

        $userModel = new User();
        if ($email !== null && $userModel->findByEmail($email) !== null) {
            $this->sendError(409, "Un compte utilisateur avec cet email existe déjà.");
            return;
        }
        if ($userModel->findByPhone($telephone) !== null) {
            $this->sendError(409, "Ce numéro de téléphone est déjà utilisé par un autre compte.");
            return;
        }

        $db = $userModel->getDatabase();
        $roleId = (int) $db->query("SELECT id FROM roles WHERE code = 'auditeur' LIMIT 1")->fetchColumn();
        if (!$roleId) {
            $this->sendError(500, "Le rôle 'auditeur' n'existe pas. Exécutez la migration.");
            return;
        }

        $profile = array_replace($auditeur, $data, ['telephone' => $telephone, 'email' => $email]);
        $password = $this->generateTemporaryPassword();
        $login = $email ?: $telephone;
        $notifications = new Notification();
        $ownTx = !$db->inTransaction();
        if ($ownTx) {
            $db->beginTransaction();
        }
        try {
            $this->model->update($id, [
                'matricule' => $profile['matricule'],
                'nom' => $profile['nom'],
                'prenom' => $profile['prenom'],
                'sexe' => $profile['sexe'] ?? null,
                'telephone' => $telephone,
                'email' => $email,
                'id_position' => $profile['id_position'] ?? null,
                'id_type_employe' => $profile['id_type_employe'] ?? null,
            ]);
            $userId = $userModel->create([
                'nom' => $profile['nom'],
                'prenom' => $profile['prenom'],
                'email' => $email,
                'telephone' => $telephone,
                'password' => $password,
                'role_id' => $roleId,
                'actif' => 1,
            ]);
            $db->prepare('UPDATE users SET first_login = 1 WHERE id = :id')->execute(['id' => $userId]);
            $db->prepare('UPDATE auditeurs SET user_id = :user_id WHERE id = :id')
                ->execute(['user_id' => $userId, 'id' => $id]);
            $notifications->createWelcome($userId, $login);
            if ($ownTx) {
                $db->commit();
            }
        } catch (Throwable $error) {
            if ($ownTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }

        $this->sendResponse(200, [
            'success' => true,
            'message' => 'Le dossier auditeur et son compte de connexion ont été mis à jour.',
            'data' => [
                'id' => $id,
                'user_id' => $userId,
                'nom' => $profile['nom'],
                'prenom' => $profile['prenom'],
                'matricule' => $profile['matricule'],
                'telephone' => $telephone,
                'email' => $email,
                'login' => $login,
                'password_temporaire' => $password,
            ],
        ]);
    }

    private function generateTemporaryPassword(): string
    {
        return random_int(100000, 999999) . 'Ages!';
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
