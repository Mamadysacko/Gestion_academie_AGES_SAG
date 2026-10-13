<?php

require_once __DIR__ . '/../Model/Dashboard.php';

class DashboardController
{
    private Dashboard $model;

    public function __construct()
    {
        $this->model = new Dashboard();
    }

    public function overview(): void
    {
        try {
            $yearId = $this->optionalPositiveInt($_GET['id_annee'] ?? null, 'id_annee');
            $sessionId = $this->optionalPositiveInt($_GET['id_session'] ?? null, 'id_session');
            $date = isset($_GET['date']) ? (string) $_GET['date'] : date('Y-m-d');
            $this->respond(200, [
                'success' => true,
                'data' => $this->model->getDashboard($yearId, $sessionId, $date),
            ]);
        } catch (InvalidArgumentException $e) {
            $this->respond(400, ['success' => false, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('Dashboard overview failed: ' . $e->getMessage());
            $this->respond(500, ['success' => false, 'error' => 'Impossible de charger les indicateurs du tableau de bord.']);
        }
    }

    public function report(): void
    {
        try {
            $type = (string) ($_GET['type'] ?? '');
            $date = isset($_GET['date']) ? (string) $_GET['date'] : null;
            $month = isset($_GET['mois']) ? (string) $_GET['mois'] : null;
            $yearId = $this->optionalPositiveInt($_GET['id_annee'] ?? null, 'id_annee');
            $sessionId = $this->optionalPositiveInt($_GET['id_session'] ?? null, 'id_session');
            $this->respond(200, [
                'success' => true,
                'data' => $this->model->getReport(
                    $type, $date, $month, $yearId, $sessionId,
                    [
                        'id_groupe' => $this->optionalPositiveInt($_GET['id_groupe'] ?? null, 'id_groupe'),
                        'id_salle' => $this->optionalPositiveInt($_GET['id_salle'] ?? null, 'id_salle'),
                        'id_formateur' => $this->optionalPositiveInt($_GET['id_formateur'] ?? null, 'id_formateur'),
                    ],
                    isset($_GET['date_debut']) ? (string) $_GET['date_debut'] : null,
                    isset($_GET['date_fin']) ? (string) $_GET['date_fin'] : null
                ),
            ]);
        } catch (InvalidArgumentException $e) {
            $this->respond(400, ['success' => false, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('Dashboard report failed: ' . $e->getMessage());
            $this->respond(500, ['success' => false, 'error' => 'Impossible de générer le rapport demandé.']);
        }
    }

    private function optionalPositiveInt($value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            throw new InvalidArgumentException("Le paramètre '{$field}' doit être un entier positif.");
        }
        return $id;
    }

    private function respond(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
