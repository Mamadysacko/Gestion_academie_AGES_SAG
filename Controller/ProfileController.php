<?php

require_once __DIR__ . '/../Model/User.php';
require_once __DIR__ . '/../Model/Formateur.php';
require_once __DIR__ . '/../Model/TrainerPortal.php';
require_once __DIR__ . '/../Model/Note.php';
require_once __DIR__ . '/Authorization.php';

final class ProfileController
{
    public function getProfile(): void
    {
        $user = Authorization::currentUser();
        if ($user === null) {
            $this->sendError(401, 'Authentification requise.');
            return;
        }

        $this->sendResponse(200, ['success' => true, 'data' => $user]);
    }

    public function updateProfile(): void
    {
        $user = Authorization::currentUser();
        if ($user === null) {
            $this->sendError(401, 'Authentification requise.');
            return;
        }

        $data = $this->getJsonInput();
        $allowedFields = ['nom', 'prenom', 'email', 'telephone', 'current_password', 'new_password'];
        if (!$data || array_diff(array_keys($data), $allowedFields)) {
            $this->sendError(400, 'Seules les coordonnées personnelles et le mot de passe peuvent être modifiés.');
            return;
        }

        $nom = trim((string) ($data['nom'] ?? $user['nom']));
        $prenom = trim((string) ($data['prenom'] ?? $user['prenom']));
        $email = trim((string) ($data['email'] ?? $user['email'] ?? ''));
        $telephone = trim((string) ($data['telephone'] ?? $user['telephone'] ?? ''));

        if ($nom === '' || mb_strlen($nom) > 100 || $prenom === '' || mb_strlen($prenom) > 100) {
            $this->sendError(400, 'Le nom et le prénom sont obligatoires et ne doivent pas dépasser 100 caractères.');
            return;
        }
        if ($email !== '' && (mb_strlen($email) > 180 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $this->sendError(400, 'L’adresse e-mail est invalide.');
            return;
        }
        if ($telephone !== '' && mb_strlen($telephone) > 30) {
            $this->sendError(400, 'Le numéro de téléphone ne doit pas dépasser 30 caractères.');
            return;
        }
        if ($email === '' && $telephone === '') {
            $this->sendError(400, 'Un e-mail ou un numéro de téléphone doit rester associé au compte.');
            return;
        }

        $userModel = new User();
        $userId = (int) $user['id'];
        if ($email !== '') {
            $existing = $userModel->findByEmail($email);
            if ($existing !== null && (int) $existing['id'] !== $userId) {
                $this->sendError(409, 'Cette adresse e-mail est déjà utilisée.');
                return;
            }
        }

        $passwordUpdate = isset($data['new_password']) && (string) $data['new_password'] !== '';
        $passwordHash = null;
        if ($passwordUpdate) {
            $newPassword = (string) $data['new_password'];
            $currentPassword = (string) ($data['current_password'] ?? '');
            $storedPassword = $userModel->getPasswordForSync($userId);
            $currentPasswordValid = $storedPassword !== null && (
                password_verify($currentPassword, $storedPassword)
                || hash_equals($storedPassword, $currentPassword)
            );
            if (!$currentPasswordValid) {
                $this->sendError(400, 'Le mot de passe actuel est incorrect.');
                return;
            }
            if (mb_strlen($newPassword) < 8 || mb_strlen($newPassword) > 255) {
                $this->sendError(400, 'Le nouveau mot de passe doit contenir entre 8 et 255 caractères.');
                return;
            }
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        $formateurModel = new Formateur();
        $formateur = $formateurModel->getByUser($userId);
        if ($formateur !== null && $email !== '') {
            $duplicateTrainerEmail = $formateurModel->findByEmail($email);
            if ($duplicateTrainerEmail !== null && (int) $duplicateTrainerEmail['id'] !== (int) $formateur['id']) {
                $this->sendError(409, 'Cette adresse e-mail est déjà utilisée par un formateur.');
                return;
            }
        }

        $db = $userModel->getDatabase();
        try {
            $db->beginTransaction();
            $changes = [
                'nom' => $nom,
                'prenom' => $prenom,
                'email' => $email !== '' ? $email : null,
                'telephone' => $telephone !== '' ? $telephone : null,
            ];
            if ($passwordHash !== null) {
                $changes['password'] = $passwordHash;
            }
            $updated = $userModel->update($userId, $changes);

            if ($formateur !== null) {
                $trainerChanges = [
                    'nom' => $nom,
                    'prenom' => $prenom,
                    'email' => $changes['email'],
                    'telephone' => $changes['telephone'],
                ];
                if ($passwordHash !== null) {
                    $trainerChanges['password'] = $passwordHash;
                }
                $updated = $formateurModel->update((int) $formateur['id'], $trainerChanges) || $updated;
            }

            if (!$updated) {
                throw new RuntimeException('Le profil n’a pas été modifié.');
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->sendError(500, 'Impossible d’enregistrer le profil : ' . $error->getMessage());
            return;
        }

        $this->sendResponse(200, [
            'success' => true,
            'message' => 'Profil mis à jour.',
            'data' => Authorization::currentUser(),
        ]);
    }

    public function getTrainerPortal(): void
    {
        $user = Authorization::currentUser();
        if ($user === null) {
            $this->sendError(401, 'Authentification requise.');
            return;
        }
        if ($user['role_code'] !== 'formateur' || empty($user['formateur_id'])) {
            $this->sendError(403, 'Cet espace est réservé aux comptes formateurs liés à un profil.');
            return;
        }

        $attendanceDate = null;
        if (isset($_GET['date'])) {
            $candidateDate = (string) $_GET['date'];
            $parsedDate = DateTime::createFromFormat('!Y-m-d', $candidateDate);
            if (!$parsedDate || $parsedDate->format('Y-m-d') !== $candidateDate) {
                $this->sendError(400, 'La date doit respecter le format AAAA-MM-JJ.');
                return;
            }
            $attendanceDate = $candidateDate;
        }

        try {
            $model = new TrainerPortal();
            $data = $model->getDashboard(
                (int) $user['formateur_id'],
                $user['permissions'] ?? [],
                $attendanceDate
            );
            $data['trainer'] = [
                'id' => (int) $user['formateur_id'],
                'nom' => $user['nom'],
                'prenom' => $user['prenom'],
                'email' => $user['email'],
                'matricule' => $user['formateur_matricule'],
            ];
            $this->sendResponse(200, ['success' => true, 'data' => $data]);
        } catch (Throwable $error) {
            $this->sendError(500, 'Impossible de charger votre espace formateur : ' . $error->getMessage());
        }
    }

    public function markTrainerPresence(): void
    {
        $user = Authorization::currentUser();
        if ($user === null) {
            $this->sendError(401, 'Authentification requise.');
            return;
        }
        if ($user['role_code'] !== 'formateur' || empty($user['formateur_id'])) {
            $this->sendError(403, 'Cette action est réservée aux comptes formateurs liés à un profil.');
            return;
        }

        $permissions = $user['permissions']['presences'] ?? [];
        if (!in_array('create', $permissions, true) && !in_array('update', $permissions, true)) {
            $this->sendError(403, 'Votre rôle ne permet pas de modifier les présences.');
            return;
        }

        (new PresenceController())->markPresence((int) $user['formateur_id']);
    }

    /** Formateur connecté (id) ou null après envoi de l'erreur. */
    private function currentTrainerId(): ?int
    {
        $user = Authorization::currentUser();
        if ($user === null) {
            $this->sendError(401, 'Authentification requise.');
            return null;
        }
        if ($user['role_code'] !== 'formateur' || empty($user['formateur_id'])) {
            $this->sendError(403, 'Cette action est réservée aux comptes formateurs liés à un profil.');
            return null;
        }
        return (int) $user['formateur_id'];
    }

    /** GET /auth/trainer-portal/feuille?date= — auditeurs attendus dans les classes du formateur. */
    public function trainerFeuille(): void
    {
        $trainerId = $this->currentTrainerId();
        if ($trainerId !== null) {
            (new PresenceController())->feuille($trainerId);
        }
    }

    /** POST /auth/trainer-portal/presences-bulk — pointage groupé, date du jour uniquement. */
    public function trainerMarkBulk(): void
    {
        $trainerId = $this->currentTrainerId();
        if ($trainerId === null) {
            return;
        }
        $permissions = Authorization::currentUser()['permissions']['presences'] ?? [];
        if (!in_array('create', $permissions, true) && !in_array('update', $permissions, true)) {
            $this->sendError(403, 'Votre rôle ne permet pas de modifier les présences.');
            return;
        }
        (new PresenceController())->markBulk($trainerId);
    }

    /** GET /auth/trainer-portal/resultats/{id_session} — résultats limités aux classes du formateur. */
    public function trainerResultats(int $sessionId): void
    {
        $trainerId = $this->currentTrainerId();
        if ($trainerId !== null) {
            (new ResultatController())->getSession($sessionId, $trainerId);
        }
    }

    public function saveTrainerGrade(): void
    {
        $user = Authorization::currentUser();
        if ($user === null) {
            $this->sendError(401, 'Authentification requise.');
            return;
        }
        if ($user['role_code'] !== 'formateur' || empty($user['formateur_id'])) {
            $this->sendError(403, 'Cette action est réservée aux comptes formateurs liés à un profil.');
            return;
        }

        $data = $this->getJsonInput();
        foreach (['id_evaluation', 'id_parametre', 'id_inscription'] as $field) {
            $value = filter_var($data[$field] ?? null, FILTER_VALIDATE_INT);
            if ($value === false || $value <= 0) {
                $this->sendError(400, "Le champ '{$field}' doit être un entier positif.");
                return;
            }
            $data[$field] = $value;
        }

        $grade = null;
        if (array_key_exists('note', $data) && $data['note'] !== '' && $data['note'] !== null) {
            if (!is_numeric($data['note'])) {
                $this->sendError(400, 'La note doit être un nombre valide.');
                return;
            }
            $grade = (float) $data['note'];
        }
        $observation = isset($data['observation']) ? trim((string) $data['observation']) : null;

        try {
            (new Note())->saveOrUpdateForTrainer(
                (int) $user['formateur_id'],
                $data['id_evaluation'],
                $data['id_parametre'],
                $data['id_inscription'],
                $grade,
                $observation !== '' ? $observation : null
            );
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Note enregistrée avec succès.',
            ]);
        } catch (InvalidArgumentException $error) {
            $this->sendError(400, $error->getMessage());
        } catch (DomainException $error) {
            $this->sendError(403, $error->getMessage());
        } catch (Throwable $error) {
            $this->sendError(500, 'Impossible d’enregistrer la note : ' . $error->getMessage());
        }
    }

    private function getJsonInput(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        return is_array($data) ? $data : [];
    }

    private function sendResponse(int $status, array $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function sendError(int $status, string $message): void
    {
        $this->sendResponse($status, ['success' => false, 'error' => $message]);
    }
}
