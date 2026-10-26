<?php
/**
 * Contrôleur pour la gestion des utilisateurs
 */

require_once __DIR__ . '/../Model/User.php';
require_once __DIR__ . '/../Model/Formateur.php';

class UserController
{
    private User $model;

    public function __construct()
    {
        $this->model = new User();
    }

    /**
     * Retourne tous les utilisateurs
     * GET /users
     */
    public function getAll(): void
    {
        try {
            $users = $this->model->getAllWithRole();
            
            // Suppression des mots de passe de la réponse
            foreach ($users as &$user) {
                unset($user['password']);
            }

            $this->sendResponse(200, [
                'success' => true,
                'data' => $users
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des utilisateurs : " . $e->getMessage());
        }
    }

    public function currentSession(): void
    {
        $user = Authorization::currentUser();
        if ($user === null) {
            $this->sendError(401, "Aucune session authentifiée.");
            return;
        }
        $this->sendResponse(200, ['success' => true, 'data' => $user]);
    }

    public function logout(): void
    {
        Authorization::logout();
        $this->sendResponse(200, ['success' => true, 'message' => 'Déconnexion réussie.']);
    }

    /**
     * Retourne un utilisateur par son ID
     * GET /users/{id}
     */
    public function getById(int $id): void
    {
        try {
            $user = $this->model->findWithRole($id);
            
            if ($user === null) {
                $this->sendError(404, "Utilisateur introuvable.");
                return;
            }

            // Suppression du mot de passe
            unset($user['password'], $user['token_version']);

            $this->sendResponse(200, [
                'success' => true,
                'data' => $user
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération de l'utilisateur : " . $e->getMessage());
        }
    }

    /**
     * Retourne un utilisateur par son email
     * GET /users/email/{email}
     */
    public function getByEmail(string $email): void
    {
        try {
            $user = $this->model->findByEmail($email);
            
            if ($user === null) {
                $this->sendError(404, "Utilisateur avec l'email '{$email}' introuvable.");
                return;
            }

            // Suppression du mot de passe
            unset($user['password']);

            $this->sendResponse(200, [
                'success' => true,
                'data' => $user
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération de l'utilisateur : " . $e->getMessage());
        }
    }

    /**
     * Retourne les utilisateurs d'un rôle spécifique
     * GET /users/role/{role_id}
     */
    public function getByRole(int $role_id): void
    {
        try {
            $users = $this->model->getByRole($role_id);
            
            // Suppression des mots de passe
            foreach ($users as &$user) {
                unset($user['password']);
            }

            $this->sendResponse(200, [
                'success' => true,
                'data' => $users
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des utilisateurs : " . $e->getMessage());
        }
    }

    /**
     * Crée un nouvel utilisateur
     * POST /users
     */
    public function create(): void
    {
        $db = null;
        try {
            $data = $this->getJsonInput();

            // Validation des champs requis
            $required = ['nom', 'prenom', 'password', 'role_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $this->sendError(400, "Le champ '{$field}' est requis.");
                    return;
                }
            }

            $data['email'] = !empty($data['email']) ? trim((string) $data['email']) : null;
            $data['telephone'] = !empty($data['telephone']) ? trim((string) $data['telephone']) : null;
            if ($data['email'] === null && $data['telephone'] === null) {
                $this->sendError(400, "Un email ou un numéro de téléphone est requis pour la connexion.");
                return;
            }
            if (!filter_var($data['role_id'], FILTER_VALIDATE_INT) ||
                (int) $data['role_id'] <= 0 ||
                !$this->model->roleExists((int) $data['role_id'])
            ) {
                $this->sendError(400, "Le rôle sélectionné n'existe pas.");
                return;
            }
            $roleCode = $this->model->getRoleCode((int) $data['role_id']);

            // Validation de l'email si fourni
            if ($data['email'] !== null) {
                if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                    $this->sendError(400, "L'email fourni n'est pas valide.");
                    return;
                }

                // Vérification de l'unicité de l'email
                if ($this->model->findByEmail($data['email']) !== null) {
                    $this->sendError(409, "Un utilisateur avec cet email existe déjà.");
                    return;
                }
            }
            if ($data['telephone'] !== null && $this->model->findByPhone($data['telephone']) !== null) {
                $this->sendError(409, "Ce numéro de téléphone est déjà utilisé par un autre compte.");
                return;
            }

            $formateurModel = new Formateur();
            $matricule = trim((string) ($data['matricule'] ?? ''));
            if ($roleCode === 'formateur') {
                if ($matricule === '') {
                    $this->sendError(400, "Le matricule est requis pour créer un compte formateur.");
                    return;
                }
                if (mb_strlen($matricule) > 50) {
                    $this->sendError(400, "Le matricule ne peut pas dépasser 50 caractères.");
                    return;
                }
                if ($formateurModel->findByMatricule($matricule) !== null) {
                    $this->sendError(409, "Un formateur avec ce matricule existe déjà.");
                    return;
                }
                if ($data['email'] !== null && $formateurModel->findByEmail($data['email']) !== null) {
                    $this->sendError(409, "Un formateur avec cet email existe déjà.");
                    return;
                }
            }

            // Valeur par défaut pour actif
            if (!isset($data['actif'])) {
                $data['actif'] = 1;
            }

            unset($data['matricule']);
            $db = $this->model->getDatabase();
            $ownTx = !$db->inTransaction();
            if ($ownTx) {
                $db->beginTransaction();
            }
            try {
                $id = $this->model->create($data);
                $formateurId = null;
                if ($roleCode === 'formateur') {
                    // L'identité et le mot de passe restent dans le compte ; la fiche ne porte que le matricule.
                    $formateurId = $formateurModel->create(['user_id' => $id, 'matricule' => $matricule]);
                }
                if ($ownTx) {
                    $db->commit();
                }
            } catch (Throwable $error) {
                if (($ownTx ?? false) && $db->inTransaction()) {
                    $db->rollBack();
                }
                throw $error;
            }

            $this->sendResponse(201, [
                'success' => true,
                'message' => 'Utilisateur créé avec succès.',
                'data' => ['id' => $id, 'formateur_id' => $formateurId]
            ]);
        } catch (Throwable $e) {
            if ($db instanceof PDO && ($ownTx ?? false) && $db->inTransaction()) {
                $db->rollBack();
            }
            $this->sendError(500, "Erreur lors de la création de l'utilisateur : " . $e->getMessage());
        }
    }

    /**
     * Met à jour un utilisateur
     * PUT /users/{id}
     */
    public function update(int $id): void
    {
        $db = null;
        try {
            // Vérification de l'existence
            $existingUser = $this->model->findWithRole($id);
            if ($existingUser === null) {
                $this->sendError(404, "Utilisateur introuvable.");
                return;
            }

            $data = $this->getJsonInput();
            if ((int) Authorization::userId() === $id &&
                array_key_exists('actif', $data) && !(bool) $data['actif']
            ) {
                $this->sendError(409, "Vous ne pouvez pas désactiver votre propre compte.");
                return;
            }
            if ($this->model->isLastActiveAdmin($id) &&
                ((array_key_exists('actif', $data) && !(bool) $data['actif']) ||
                 (isset($data['role_id']) && (int) $data['role_id'] !== (int) $existingUser['role_id']))
            ) {
                $this->sendError(409, "Le dernier compte administrateur actif ne peut pas être désactivé ou changer de rôle.");
                return;
            }

            if (isset($data['role_id']) &&
                (!filter_var($data['role_id'], FILTER_VALIDATE_INT) ||
                 (int) $data['role_id'] <= 0 ||
                 !$this->model->roleExists((int) $data['role_id']))
            ) {
                $this->sendError(400, "Le rôle sélectionné est invalide.");
                return;
            }
            $targetRoleId = isset($data['role_id']) ? (int) $data['role_id'] : (int) $existingUser['role_id'];
            $targetRoleCode = $this->model->getRoleCode($targetRoleId);

            // Validation de l'email si fourni
            if (array_key_exists('email', $data) && $data['email'] !== null && $data['email'] !== '') {
                if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                    $this->sendError(400, "L'email fourni n'est pas valide.");
                    return;
                }

                // Vérification de l'unicité de l'email (sauf pour l'utilisateur actuel)
                $existing = $this->model->findByEmail($data['email']);
                if ($existing !== null && (int)$existing['id'] !== $id) {
                    $this->sendError(409, "Un autre utilisateur avec cet email existe déjà.");
                    return;
                }
            }

            if (array_key_exists('email', $data) && ($data['email'] === '' || $data['email'] === null)) {
                $data['email'] = null;
            } elseif (isset($data['email'])) {
                $data['email'] = trim((string) $data['email']);
            }
            if (array_key_exists('telephone', $data) && ($data['telephone'] === '' || $data['telephone'] === null)) {
                $data['telephone'] = null;
            } elseif (isset($data['telephone'])) {
                $data['telephone'] = trim((string) $data['telephone']);
            }
            $effectiveEmail = array_key_exists('email', $data) ? $data['email'] : $existingUser['email'];
            $effectivePhone = array_key_exists('telephone', $data) ? $data['telephone'] : $existingUser['telephone'];
            if (empty($effectiveEmail) && empty($effectivePhone)) {
                $this->sendError(400, "Un email ou un numéro de téléphone doit rester associé au compte.");
                return;
            }
            if (!empty($effectivePhone)) {
                $phoneOwner = $this->model->findByPhone((string) $effectivePhone);
                if ($phoneOwner !== null && (int) $phoneOwner['id'] !== $id) {
                    $this->sendError(409, "Ce numéro de téléphone est déjà utilisé par un autre compte.");
                    return;
                }
            }
            $formateurModel = new Formateur();
            $linkedFormateur = $formateurModel->getByUser($id);
            $matricule = array_key_exists('matricule', $data)
                ? trim((string) $data['matricule'])
                : (string) ($linkedFormateur['matricule'] ?? '');
            if ($targetRoleCode === 'formateur' && $matricule === '') {
                $this->sendError(400, "Le matricule est requis pour créer le profil formateur.");
                return;
            }
            if (array_key_exists('matricule', $data) && $matricule === '') {
                $this->sendError(400, "Le matricule du formateur ne peut pas être vide.");
                return;
            }
            if ($matricule !== '') {
                $duplicateMatricule = $formateurModel->findByMatricule($matricule);
                if ($duplicateMatricule !== null &&
                    (int) $duplicateMatricule['id'] !== (int) ($linkedFormateur['id'] ?? 0)
                ) {
                    $this->sendError(409, "Un formateur avec ce matricule existe déjà.");
                    return;
                }
            }
            if ($effectiveEmail !== null && $effectiveEmail !== '') {
                $duplicateFormateurEmail = $formateurModel->findByEmail((string) $effectiveEmail);
                if ($duplicateFormateurEmail !== null &&
                    (int) $duplicateFormateurEmail['id'] !== (int) ($linkedFormateur['id'] ?? 0)
                ) {
                    $this->sendError(409, "Un formateur avec cet email existe déjà.");
                    return;
                }
            }
            if (array_key_exists('password', $data) && $data['password'] === '') {
                unset($data['password']);
            }

            unset($data['matricule']);
            $db = $this->model->getDatabase();
            $ownTx = !$db->inTransaction();
            if ($ownTx) {
                $db->beginTransaction();
            }
            try {
                $success = $this->model->update($id, $data);
                if (isset($data['password']) && $data['password'] !== '') {
                    // Nouveau mot de passe : les jetons déjà émis pour ce compte ne sont plus valables.
                    $this->model->revokeSessions($id);
                }
                // L'identité du formateur est celle du compte, déjà mise à jour ci-dessus :
                // seule la fiche (matricule) reste à enregistrer.
                if ($linkedFormateur !== null) {
                    if ($matricule !== '' && $matricule !== $linkedFormateur['matricule']) {
                        $success = $formateurModel->update((int) $linkedFormateur['id'], ['matricule' => $matricule]) || $success;
                    }
                } elseif ($targetRoleCode === 'formateur') {
                    $formateurModel->create(['user_id' => $id, 'matricule' => $matricule]);
                    $success = true;
                }

                if ($success) {
                    if ($ownTx) {
                        $db->commit();
                    }
                } else {
                    if ($ownTx ?? false) {
                        $db->rollBack();
                    }
                }
            } catch (Throwable $error) {
                if (($ownTx ?? false) && $db->inTransaction()) {
                    $db->rollBack();
                }
                throw $error;
            }

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Utilisateur mis à jour avec succès.'
                ]);
            } else {
                $this->sendError(400, "Aucune donnée valide à mettre à jour.");
            }
        } catch (Throwable $e) {
            if ($db instanceof PDO && ($ownTx ?? false) && $db->inTransaction()) {
                $db->rollBack();
            }
            $this->sendError(500, "Erreur lors de la mise à jour de l'utilisateur : " . $e->getMessage());
        }
    }

    /**
     * Supprime un utilisateur
     * DELETE /users/{id}
     */
    public function delete(int $id): void
    {
        try {
            if ((int) Authorization::userId() === $id) {
                $this->sendError(409, "Vous ne pouvez pas supprimer votre propre compte.");
                return;
            }
            if ($this->model->isLastActiveAdmin($id)) {
                $this->sendError(409, "Le dernier compte administrateur actif ne peut pas être supprimé.");
                return;
            }
            $success = $this->model->delete($id);

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Utilisateur supprimé avec succès.'
                ]);
            } else {
                $this->sendError(404, "Utilisateur introuvable.");
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la suppression de l'utilisateur : " . $e->getMessage());
        }
    }

    /**
     * Authentifie un utilisateur
     * POST /users/authenticate
     */
    public function authenticate(): void
    {
        try {
            $data = $this->getJsonInput();
            $identifier = trim((string) ($data['email'] ?? $data['usernameOrEmail'] ?? ''));
            $password = $data['password'] ?? null;

            if ($identifier === '' || !is_string($password) || $password === '') {
                $this->sendError(400, "Email ou téléphone et mot de passe requis.");
                return;
            }

            $user = $this->model->authenticate($identifier, $password);

            if ($user === null) {
                $this->sendError(401, "Email ou mot de passe incorrect, ou compte inactif.");
                return;
            }

            if (AUTH_SESSION_FALLBACK) {
                if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                    session_regenerate_id(true);
                }
                $_SESSION['user_id'] = (int) $user['id'];
            }
            require_once __DIR__ . '/AuthController.php';
            $tokens = AuthController::tokenPayload($user);
            unset($user['token_version']);

            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Authentification réussie.',
                'data' => $user
            ] + $tokens);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de l'authentification : " . $e->getMessage());
        }
    }

    /**
     * Active ou désactive un utilisateur
     * POST /users/{id}/toggle-actif
     */
    public function toggleActif(int $id): void
    {
        $db = null;
        try {
            if ((int) Authorization::userId() === $id) {
                $this->sendError(409, "Vous ne pouvez pas désactiver votre propre compte.");
                return;
            }
            if ($this->model->isLastActiveAdmin($id)) {
                $this->sendError(409, "Le dernier compte administrateur actif ne peut pas être désactivé.");
                return;
            }
            $db = $this->model->getDatabase();
            $ownTx = !$db->inTransaction();
            if ($ownTx) {
                $db->beginTransaction();
            }
            try {
                // Un formateur actif est un compte actif : rien d'autre à synchroniser.
                $newStatus = $this->model->toggleActif($id);
                if ($ownTx) {
                    $db->commit();
                }
            } catch (Throwable $error) {
                if (($ownTx ?? false) && $db->inTransaction()) {
                    $db->rollBack();
                }
                throw $error;
            }

            if ($newStatus === null) {
                $this->sendError(404, "Utilisateur introuvable.");
                return;
            }

            $message = $newStatus === 1 ? 'Utilisateur activé avec succès.' : 'Utilisateur désactivé avec succès.';

            $this->sendResponse(200, [
                'success' => true,
                'message' => $message,
                'data' => ['actif' => $newStatus]
            ]);
        } catch (Throwable $e) {
            if ($db instanceof PDO && ($ownTx ?? false) && $db->inTransaction()) {
                $db->rollBack();
            }
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
