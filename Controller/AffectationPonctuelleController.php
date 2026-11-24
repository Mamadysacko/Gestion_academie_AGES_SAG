<?php

require_once __DIR__ . '/../Model/AffectationPonctuelle.php';
require_once __DIR__ . '/Authorization.php';

final class AffectationPonctuelleController
{
    private AffectationPonctuelle $model;

    public function __construct()
    {
        $this->model = new AffectationPonctuelle();
    }

    public function getAll(): void
    {
        try {
            $user = Authorization::currentUser();
            $trainerId = ($user['role_code'] ?? '') === 'formateur'
                ? (int) ($user['formateur_id'] ?? 0)
                : null;
            $this->sendResponse(200, ['success' => true, 'data' => $this->model->getAssignments($trainerId)]);
        } catch (Throwable $error) {
            $this->sendError(500, 'Impossible de charger les affectations ponctuelles : ' . $error->getMessage());
        }
    }

    public function create(): void
    {
        $data = $this->readInput();
        $error = $this->validateInput($data);
        if ($error !== null) {
            $this->sendError(400, $error);
            return;
        }
        try {
            $data['created_by'] = Authorization::userId();
            $id = $this->model->saveValidated($data);
            $this->sendResponse(201, [
                'success' => true,
                'message' => 'Affectation ponctuelle enregistrée.',
                'data' => ['id' => $id],
            ]);
        } catch (DomainException $error) {
            $this->sendError((int) $error->getCode() ?: 409, $error->getMessage());
        } catch (Throwable $error) {
            $this->sendError(500, 'Impossible d’enregistrer l’affectation ponctuelle : ' . $error->getMessage());
        }
    }

    public function update(int $id): void
    {
        $current = $this->model->find($id);
        if ($current === null) {
            $this->sendError(404, 'Affectation ponctuelle introuvable.');
            return;
        }
        $data = array_replace($current, $this->readInput());
        $error = $this->validateInput($data);
        if ($error !== null) {
            $this->sendError(400, $error);
            return;
        }
        try {
            $this->model->saveValidated([
                'id_emploi_du_temps' => (int) $data['id_emploi_du_temps'],
                'id_formateur' => (int) $data['id_formateur'],
                'id_salle' => (int) $data['id_salle'],
                'date_affectation' => $data['date_affectation'],
                'motif' => trim($data['motif']),
            ], $id);
            $this->sendResponse(200, ['success' => true, 'message' => 'Affectation ponctuelle mise à jour.']);
        } catch (DomainException $error) {
            $this->sendError((int) $error->getCode() ?: 409, $error->getMessage());
        } catch (Throwable $error) {
            $this->sendError(500, 'Impossible de modifier l’affectation ponctuelle : ' . $error->getMessage());
        }
    }

    public function delete(int $id): void
    {
        try {
            if (!$this->model->deleteLocked($id)) {
                $this->sendError(404, 'Affectation ponctuelle introuvable.');
                return;
            }
            $this->sendResponse(200, ['success' => true, 'message' => 'Affectation ponctuelle supprimée.']);
        } catch (Throwable $error) {
            $this->sendError(500, 'Impossible de supprimer l’affectation ponctuelle : ' . $error->getMessage());
        }
    }

    private function validateInput(array &$data): ?string
    {
        foreach (['id_emploi_du_temps', 'id_formateur', 'id_salle'] as $field) {
            $value = filter_var($data[$field] ?? null, FILTER_VALIDATE_INT);
            if ($value === false || $value <= 0) {
                return "Le champ '{$field}' doit être un entier positif.";
            }
            $data[$field] = $value;
        }
        $date = (string) ($data['date_affectation'] ?? '');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))
            || $parsed->format('Y-m-d') !== $date
        ) {
            return 'La date d’affectation doit respecter le format AAAA-MM-JJ.';
        }
        $data['date_affectation'] = $date;
        $data['motif'] = trim((string) ($data['motif'] ?? ''));
        if ($data['motif'] === '' || mb_strlen($data['motif']) > 255) {
            return 'Le motif est obligatoire et ne peut pas dépasser 255 caractères.';
        }
        return null;
    }

    private function readInput(): array
    {
        $input = json_decode((string) file_get_contents('php://input'), true);
        return is_array($input) ? $input : [];
    }

    private function sendResponse(int $status, array $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function sendError(int $status, string $message): void
    {
        $this->sendResponse($status, ['success' => false, 'error' => $message]);
    }
}
