<?php
/**
 * Contrôleur pour la gestion des rôles
 */

require_once __DIR__ . '/../Model/Role.php';

class RoleController
{
    private Role $model;

    public function __construct()
    {
        $this->model = new Role();
    }

    /**
     * Retourne tous les rôles
     * GET /roles
     */
    public function getAll(): void
    {
        try {
            $roles = $this->model->getAllWithPermissions();
            $this->sendResponse(200, [
                'success' => true,
                'data' => $roles
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération des rôles : " . $e->getMessage());
        }
    }

    /**
     * Retourne un rôle par son ID
     * GET /roles/{id}
     */
    public function getById(int $id): void
    {
        try {
            $role = $this->model->find($id);
            
            if ($role === null) {
                $this->sendError(404, "Rôle introuvable.");
                return;
            }

            $role['permissions'] = $this->model->getPermissionsMap($id);
            $this->sendResponse(200, [
                'success' => true,
                'data' => $role
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération du rôle : " . $e->getMessage());
        }
    }

    /**
     * Retourne un rôle par son code
     * GET /roles/code/{code}
     */
    public function getByCode(string $code): void
    {
        try {
            $role = $this->model->findByCode($code);
            
            if ($role === null) {
                $this->sendError(404, "Rôle avec le code '{$code}' introuvable.");
                return;
            }

            $this->sendResponse(200, [
                'success' => true,
                'data' => $role
            ]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la récupération du rôle : " . $e->getMessage());
        }
    }

    /**
     * Crée un nouveau rôle
     * POST /roles
     */
    public function create(): void
    {
        try {
            $data = $this->getJsonInput();

            $code = trim((string) ($data['code'] ?? ''));
            $nom = trim((string) ($data['nom'] ?? ''));
            if ($code === '' || $nom === '') {
                $this->sendError(400, "Les champs 'code' et 'nom' sont requis.");
                return;
            }
            if (!preg_match('/^[a-z][a-z0-9_]{1,29}$/', $code)) {
                $this->sendError(400, "Le code doit contenir 2 à 30 caractères : lettres minuscules, chiffres et tirets bas.");
                return;
            }
            if ($code === 'admin') {
                $this->sendError(409, "Le rôle administrateur système est réservé à l'initialisation sécurisée.");
                return;
            }

            // Vérification de l'unicité du code
            if ($this->model->findByCode($code) !== null) {
                $this->sendError(409, "Un rôle avec ce code existe déjà.");
                return;
            }
            if ($this->model->findByNom($nom) !== null) {
                $this->sendError(409, "Un rôle avec ce nom existe déjà.");
                return;
            }

            $permissions = $data['permissions'] ?? [];
            if (!is_array($permissions)) {
                $this->sendError(400, "Les permissions doivent être fournies sous forme de matrice.");
                return;
            }
            $db = $this->model->getDatabase();
            $db->beginTransaction();
            $id = $this->model->create(['code' => $code, 'nom' => $nom]);
            $this->model->savePermissions($id, $permissions);
            $db->commit();

            $this->sendResponse(201, [
                'success' => true,
                'message' => 'Rôle créé avec succès.',
                'data' => ['id' => $id]
            ]);
        } catch (InvalidArgumentException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            $this->sendError(400, $e->getMessage());
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            $this->sendError(500, "Erreur lors de la création du rôle : " . $e->getMessage());
        }
    }

    /**
     * Met à jour un rôle
     * PUT /roles/{id}
     */
    public function update(int $id): void
    {
        try {
            // Vérification de l'existence
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Rôle introuvable.");
                return;
            }

            $data = $this->getJsonInput();
            if ($this->model->isReserved($id) && isset($data['code']) && $data['code'] !== 'admin') {
                $this->sendError(400, "Le code du rôle administrateur système ne peut pas être modifié.");
                return;
            }
            if ($this->model->isReserved($id) && isset($data['permissions'])) {
                $this->sendError(400, "Les permissions du rôle administrateur système sont toujours complètes.");
                return;
            }

            // Vérification de l'unicité du code (si modifié)
            if (isset($data['code'])) {
                if (!preg_match('/^[a-z][a-z0-9_]{1,29}$/', (string) $data['code'])) {
                    $this->sendError(400, "Le code doit contenir 2 à 30 caractères : lettres minuscules, chiffres et tirets bas.");
                    return;
                }
                $existing = $this->model->findByCode($data['code']);
                if ($existing !== null && (int)$existing['id'] !== $id) {
                    $this->sendError(409, "Un autre rôle avec ce code existe déjà.");
                    return;
                }
            }

            if (isset($data['nom'])) {
                $duplicateName = $this->model->findByNom(trim((string) $data['nom']));
                if ($duplicateName !== null && (int) $duplicateName['id'] !== $id) {
                    $this->sendError(409, "Un autre rôle avec ce nom existe déjà.");
                    return;
                }
            }
            $permissions = $data['permissions'] ?? null;
            if ($permissions !== null && !is_array($permissions)) {
                $this->sendError(400, "Les permissions doivent être fournies sous forme de matrice.");
                return;
            }
            unset($data['permissions']);
            $db = $this->model->getDatabase();
            $db->beginTransaction();
            $success = $this->model->update($id, $data);
            if ($permissions !== null) {
                $this->model->savePermissions($id, $permissions);
                $success = true;
            }
            if ($success) {
                $db->commit();
            } else {
                $db->rollBack();
            }

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Rôle mis à jour avec succès.'
                ]);
            } else {
                $this->sendError(400, "Aucune donnée valide à mettre à jour.");
            }
        } catch (InvalidArgumentException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            $this->sendError(400, $e->getMessage());
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            $this->sendError(500, "Erreur lors de la mise à jour du rôle : " . $e->getMessage());
        }
    }

    /**
     * Supprime un rôle
     * DELETE /roles/{id}
     */
    public function delete(int $id): void
    {
        try {
            if ($this->model->isReserved($id)) {
                $this->sendError(409, "Le rôle administrateur système ne peut pas être supprimé.");
                return;
            }
            if ($this->model->hasUsers($id)) {
                $this->sendError(409, "Impossible de supprimer ce rôle car il est attribué à des utilisateurs.");
                return;
            }
            $success = $this->model->delete($id);

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Rôle supprimé avec succès.'
                ]);
            } else {
                $this->sendError(404, "Rôle introuvable.");
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de la suppression du rôle : " . $e->getMessage());
        }
    }

    public function updatePermissions(int $id): void
    {
        try {
            if (!$this->model->exists($id)) {
                $this->sendError(404, "Rôle introuvable.");
                return;
            }
            if ($this->model->isReserved($id)) {
                $this->sendError(409, "Les permissions du rôle administrateur système ne peuvent pas être modifiées.");
                return;
            }
            $data = $this->getJsonInput();
            if (!isset($data['permissions']) || !is_array($data['permissions'])) {
                $this->sendError(400, "Le champ 'permissions' est requis.");
                return;
            }
            $this->model->savePermissions($id, $data['permissions']);
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Permissions enregistrées.',
                'data' => ['permissions' => $this->model->getPermissionsMap($id)],
            ]);
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, "Erreur lors de l'enregistrement des permissions : " . $e->getMessage());
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
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function sendError(int $code, string $message): void
    {
        $this->sendResponse($code, [
            'success' => false,
            'error' => $message
        ]);
    }
}
