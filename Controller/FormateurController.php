<?php
/**
 * Contrôleur pour la gestion des formateurs
 */

require_once __DIR__ . '/../Model/Formateur.php';
require_once __DIR__ . '/../Model/User.php';

class FormateurController
{
    private Formateur $model;

    public function __construct()
    {
        $this->model = new Formateur();
    }

    /**
     * Retourne tous les formateurs
     * GET /formateurs
     */
    public function getAll(): void
    {
        try {
            $formateurs = $this->model->getAllWithoutPasswords();
            $this->sendResponse(200, [
                'success' => true,
                'data' => $formateurs
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des formateurs : " . $e->getMessage());
        }
    }

    /**
     * Retourne un formateur par son ID
     * GET /formateurs/{id}
     */
    public function getById(int $id): void
    {
        try {
            $formateur = $this->model->findById($id);
            
            if ($formateur === null) {
                $this->sendError(404, "Formateur introuvable.");
                return;
            }

            // Suppression du mot de passe
            $this->sendResponse(200, [
                'success' => true,
                'data' => $formateur
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération du formateur : " . $e->getMessage());
        }
    }

    /**
     * Retourne un formateur par son matricule
     * GET /formateurs/matricule/{matricule}
     */
    public function getByMatricule(string $matricule): void
    {
        try {
            $formateur = $this->model->findByMatricule($matricule);
            
            if ($formateur === null) {
                $this->sendError(404, "Formateur avec le matricule '{$matricule}' introuvable.");
                return;
            }

            // Suppression du mot de passe
            unset($formateur['password']);

            $this->sendResponse(200, [
                'success' => true,
                'data' => $formateur
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération du formateur : " . $e->getMessage());
        }
    }

    /**
     * Retourne un formateur par son email
     * GET /formateurs/email/{email}
     */
    public function getByEmail(string $email): void
    {
        try {
            $formateur = $this->model->findByEmail($email);
            
            if ($formateur === null) {
                $this->sendError(404, "Formateur avec l'email '{$email}' introuvable.");
                return;
            }

            // Suppression du mot de passe
            unset($formateur['password']);

            $this->sendResponse(200, [
                'success' => true,
                'data' => $formateur
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération du formateur : " . $e->getMessage());
        }
    }

    /**
     * Retourne les formateurs actifs
     * GET /formateurs/actifs
     */
    public function getActifs(): void
    {
        try {
            $formateurs = $this->model->getActifs();
            
            // Suppression des mots de passe
            foreach ($formateurs as &$formateur) {
                unset($formateur['password']);
            }

            $this->sendResponse(200, [
                'success' => true,
                'data' => $formateurs
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des formateurs actifs : " . $e->getMessage());
        }
    }

    /**
     * Retourne le formateur lié à un utilisateur
     * GET /formateurs/user/{user_id}
     */
    public function getByUser(int $user_id): void
    {
        try {
            $formateur = $this->model->getByUser($user_id);
            
            if ($formateur === null) {
                $this->sendError(404, "Aucun formateur lié à cet utilisateur.");
                return;
            }

            // Suppression du mot de passe
            unset($formateur['password']);

            $this->sendResponse(200, [
                'success' => true,
                'data' => $formateur
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération du formateur : " . $e->getMessage());
        }
    }

    /**
     * Crée un nouveau formateur
     * POST /formateurs
     */
    public function create(): void
    {
        $db = null;
        try {
            $data = $this->getJsonInput();

            // Validation des champs requis
            $required = ['matricule', 'nom', 'prenom', 'password'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $this->sendError(400, "Le champ '{$field}' est requis.");
                    return;
                }
            }
            foreach (['matricule' => 50, 'nom' => 100, 'prenom' => 100] as $field => $maxLength) {
                $data[$field] = trim((string) $data[$field]);
                if ($data[$field] === '' || mb_strlen($data[$field]) > $maxLength) {
                    $this->sendError(400, "Le champ '{$field}' est obligatoire et ne peut pas dépasser {$maxLength} caractères.");
                    return;
                }
            }
            $data['telephone'] = !empty($data['telephone']) ? trim((string) $data['telephone']) : null;
            $data['email'] = !empty($data['email']) ? mb_strtolower(trim((string) $data['email'])) : null;
            if ($data['telephone'] !== null && mb_strlen($data['telephone']) > 30) {
                $this->sendError(400, "Le téléphone ne peut pas dépasser 30 caractères.");
                return;
            }
            if ($data['email'] === null && $data['telephone'] === null) {
                $this->sendError(400, "Un e-mail ou un téléphone est requis pour créer les identifiants de connexion.");
                return;
            }

            // Vérification de l'unicité du matricule
            if ($this->model->findByMatricule($data['matricule']) !== null) {
                $this->sendError(409, "Un formateur avec ce matricule existe déjà.");
                return;
            }

            // Validation de l'email si fourni
            if ($data['email'] !== null) {
                if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($data['email']) > 180) {
                    $this->sendError(400, "L'email fourni n'est pas valide.");
                    return;
                }

                // Vérification de l'unicité de l'email
                if ($this->model->findByEmail($data['email']) !== null) {
                    $this->sendError(409, "Un formateur avec cet email existe déjà.");
                    return;
                }
                if ((new User())->findByEmail($data['email']) !== null) {
                    $this->sendError(409, "Un utilisateur avec cet email existe déjà.");
                    return;
                }
            }
            if ($data['telephone'] !== null && (new User())->findByPhone($data['telephone']) !== null) {
                $this->sendError(409, "Ce numéro de téléphone est déjà utilisé par un autre compte.");
                return;
            }

            // Valeur par défaut pour actif
            if (array_key_exists('id_salle', $data)) {
                if ($data['id_salle'] === '' || $data['id_salle'] === null) {
                    $data['id_salle'] = null;
                } elseif (!filter_var($data['id_salle'], FILTER_VALIDATE_INT) || (int) $data['id_salle'] <= 0) {
                    $this->sendError(400, "La salle rattachée doit être un identifiant positif.");
                    return;
                } else {
                    $data['id_salle'] = (int) $data['id_salle'];
                    if ($this->model->findBySalle($data['id_salle']) !== null) {
                        $this->sendError(409, "Cette salle a déjà un formateur attitré.");
                        return;
                    }
                }
            }

            if (!isset($data['actif'])) {
                $data['actif'] = 1;
            }
            if (!in_array((string) $data['actif'], ['0', '1'], true)) {
                $this->sendError(400, "Le statut actif doit être égal à 0 ou 1.");
                return;
            }
            if (!is_string($data['password']) || mb_strlen($data['password']) > 255) {
                $this->sendError(400, "Le mot de passe doit contenir au maximum 255 caractères.");
                return;
            }

            $db = $this->model->getDatabase();
            $roleStmt = $db->prepare("SELECT id FROM roles WHERE code = 'formateur' LIMIT 1");
            $roleStmt->execute();
            $roleId = $roleStmt->fetchColumn();
            if ($roleId === false) {
                $this->sendError(500, "Le rôle formateur doit être créé avant de créer un profil.");
                return;
            }

            $ownTx = !$db->inTransaction();

            if ($ownTx) {

                $db->beginTransaction();

            }
            try {
                $userModel = new User();
                $userId = $userModel->create([
                    'nom' => $data['nom'],
                    'prenom' => $data['prenom'],
                    'email' => $data['email'],
                    'telephone' => $data['telephone'],
                    'password' => $data['password'],
                    'role_id' => (int) $roleId,
                    'actif' => $data['actif'],
                ]);
                // La fiche formateur ne garde que le compte, le matricule et la salle.
                $id = $this->model->create(['user_id' => $userId, 'matricule' => $data['matricule'], 'id_salle' => $data['id_salle'] ?? null]);
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
                'message' => 'Formateur créé avec succès.',
                'data' => ['id' => $id, 'user_id' => $userId]
            ]);
        } catch (PDOException $e) {
            if ($db instanceof PDO && ($ownTx ?? false) && $db->inTransaction()) {
                $db->rollBack();
            }
            $this->sendError((string) $e->getCode() === '23000' ? 409 : 500,
                (string) $e->getCode() === '23000'
                    ? "Le matricule ou l'adresse e-mail est déjà utilisé."
                    : "Erreur lors de la création du formateur : " . $e->getMessage());
        } catch (Exception $e) {
            if ($db instanceof PDO && ($ownTx ?? false) && $db->inTransaction()) {
                $db->rollBack();
            }
            $this->sendError(500, "Erreur lors de la création du formateur : " . $e->getMessage());
        } catch (Throwable $e) {
            if ($db instanceof PDO && ($ownTx ?? false) && $db->inTransaction()) {
                $db->rollBack();
            }
            $this->sendError(500, "Erreur lors de la création du compte formateur : " . $e->getMessage());
        }
    }

    /**
     * Met à jour un formateur
     * PUT /formateurs/{id}
     */
    public function update(int $id): void
    {
        try {
            // Vérification de l'existence
            $current = $this->model->find($id);
            if ($current === null) {
                $this->sendError(404, "Formateur introuvable.");
                return;
            }

            $data = $this->getJsonInput();
            foreach (['matricule' => 50, 'nom' => 100, 'prenom' => 100] as $field => $maxLength) {
                if (array_key_exists($field, $data)) {
                    $data[$field] = trim((string) $data[$field]);
                    if ($data[$field] === '' || mb_strlen($data[$field]) > $maxLength) {
                        $this->sendError(400, "Le champ '{$field}' est obligatoire et ne peut pas dépasser {$maxLength} caractères.");
                        return;
                    }
                }
            }
            if (array_key_exists('telephone', $data)) {
                $data['telephone'] = trim((string) $data['telephone']) ?: null;
                if ($data['telephone'] !== null && mb_strlen($data['telephone']) > 30) {
                    $this->sendError(400, "Le téléphone ne peut pas dépasser 30 caractères.");
                    return;
                }
            }
            if (array_key_exists('email', $data)) {
                $data['email'] = trim((string) $data['email']);
                $data['email'] = $data['email'] === '' ? null : mb_strtolower($data['email']);
                if ($data['email'] !== null &&
                    (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($data['email']) > 180)
                ) {
                    $this->sendError(400, "L'adresse e-mail fournie n'est pas valide ou dépasse 180 caractères.");
                    return;
                }
            }
            if (array_key_exists('password', $data) && $data['password'] !== '' &&
                (!is_string($data['password']) || mb_strlen($data['password']) > 255)
            ) {
                $this->sendError(400, "Le mot de passe doit contenir au maximum 255 caractères.");
                return;
            }
            if (array_key_exists('actif', $data) && !in_array((string) $data['actif'], ['0', '1'], true)) {
                $this->sendError(400, "Le statut actif doit être égal à 0 ou 1.");
                return;
            }
            if (array_key_exists('id_salle', $data)) {
                if ($data['id_salle'] === '' || $data['id_salle'] === null) {
                    $data['id_salle'] = null;
                } elseif (!filter_var($data['id_salle'], FILTER_VALIDATE_INT) || (int) $data['id_salle'] <= 0) {
                    $this->sendError(400, "La salle rattachée doit être un identifiant positif.");
                    return;
                } else {
                    $data['id_salle'] = (int) $data['id_salle'];
                    $salleError = $this->model->validateSalleAssignment($id, $data['id_salle'], false);
                    if ($salleError !== null) {
                        $this->sendError(409, $salleError);
                        return;
                    }
                }
            }

            // Vérification de l'unicité du matricule (si modifié)
            if (isset($data['matricule'])) {
                $existing = $this->model->findByMatricule($data['matricule']);
                if ($existing !== null && (int)$existing['id'] !== $id) {
                    $this->sendError(409, "Un autre formateur avec ce matricule existe déjà.");
                    return;
                }
            }

            // Validation de l'email si fourni
            if (isset($data['email']) && !empty($data['email'])) {
                if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                    $this->sendError(400, "L'email fourni n'est pas valide.");
                    return;
                }

                // Vérification de l'unicité de l'email (porté par le compte utilisateur)
                $existing = (new User())->findByEmail($data['email']);
                if ($existing !== null && (int) $existing['id'] !== (int) $current['user_id']) {
                    $this->sendError(409, "Un autre compte utilise déjà cet email.");
                    return;
                }
            }
            if (!empty($data['telephone'])) {
                $phoneOwner = (new User())->findByPhone($data['telephone']);
                if ($phoneOwner !== null && (int) $phoneOwner['id'] !== (int) $current['user_id']) {
                    $this->sendError(409, "Ce numéro de téléphone est déjà utilisé par un autre compte.");
                    return;
                }
            }

            $db = $this->model->getDatabase();
            $ownTx = !$db->inTransaction();
            if ($ownTx) {
                $db->beginTransaction();
            }
            try {
                // Fiche (matricule, salle) et identité du compte (nom, contacts, actif, mot de passe haché).
                $success = $this->model->update($id, $data);
                if (!empty($data['password'])) {
                    (new User())->revokeSessions((int) $current['user_id']);
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
                    'message' => 'Formateur mis à jour avec succès.'
                ]);
            } else {
                $this->sendError(400, "Aucune donnée valide à mettre à jour.");
            }
        } catch (PDOException $e) {
            if (isset($db) && ($ownTx ?? false) && $db->inTransaction()) {
                $db->rollBack();
            }
            $this->sendError((string) $e->getCode() === '23000' ? 409 : 500,
                (string) $e->getCode() === '23000'
                    ? "Le matricule ou l'adresse e-mail est déjà utilisé."
                    : "Erreur lors de la mise à jour du formateur : " . $e->getMessage());
        } catch (Exception $e) {
            if (isset($db) && ($ownTx ?? false) && $db->inTransaction()) {
                $db->rollBack();
            }
            $this->sendError(500, "Erreur lors de la mise à jour du formateur : " . $e->getMessage());
        }
    }

    /**
     * Supprime un formateur
     * DELETE /formateurs/{id}
     */
    public function delete(int $id): void
    {
        try {
            if ($this->model->getUserId($id) !== null) {
                $this->sendError(409, "Ce formateur est lié à un compte utilisateur. Gérez ou supprimez d'abord le compte depuis l'onglet Utilisateurs.");
                return;
            }
            if ($this->model->isUsedInPlanning($id)) {
                $this->sendError(409, "Impossible de supprimer ce formateur car il est utilisé dans un emploi du temps. Désactivez-le plutôt.");
                return;
            }
            $success = $this->model->delete($id);

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Formateur supprimé avec succès.'
                ]);
            } else {
                $this->sendError(404, "Formateur introuvable.");
            }
        } catch (Exception $e) {
            // Erreur de contrainte de clé étrangère
            if (strpos($e->getMessage(), 'foreign key constraint') !== false) {
                $this->sendError(409, "Impossible de supprimer ce formateur car il est utilisé dans des emplois du temps ou présences.");
            } else {
                $this->sendError(500, "Erreur lors de la suppression du formateur : " . $e->getMessage());
            }
        }
    }

    /**
     * Active ou désactive un formateur
     * POST /formateurs/{id}/toggle-actif
     */
    public function toggleActif(int $id): void
    {
        $db = $this->model->getDatabase();
        try {
            $ownTx = !$db->inTransaction();
            if ($ownTx) {
                $db->beginTransaction();
            }
            $newStatus = $this->model->toggleActif($id);

            if ($newStatus === null) {
                if ($ownTx ?? false) {
                    $db->rollBack();
                }
                $this->sendError(404, "Formateur introuvable.");
                return;
            }
            if ($ownTx) {
                $db->commit();
            }

            $message = $newStatus === 1 ? 'Formateur activé avec succès.' : 'Formateur désactivé avec succès.';

            $this->sendResponse(200, [
                'success' => true,
                'message' => $message,
                'data' => ['actif' => $newStatus]
            ]);
        } catch (Throwable $e) {
            if (($ownTx ?? false) && $db->inTransaction()) {
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
