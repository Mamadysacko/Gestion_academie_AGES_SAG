<?php
/**
 * Contrôleur pour la gestion des passages de niveau
 */

require_once __DIR__ . '/../Model/PassageNiveau.php';

class PassageNiveauController
{
    private PassageNiveau $model;

    public function __construct()
    {
        $this->model = new PassageNiveau();
    }

    public function getAll(): void
    {
        try {
            $passages = $this->model->getAllWithDetails();
            $this->sendResponse(200, ['success' => true, 'data' => $passages]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getById(int $id): void
    {
        try {
            $passage = $this->model->find($id);
            if ($passage === null) {
                $this->sendError(404, "Passage de niveau introuvable.");
                return;
            }
            $this->sendResponse(200, ['success' => true, 'data' => $passage]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getByInscription(int $id_inscription): void
    {
        try {
            $passage = $this->model->getByInscription($id_inscription);
            
            if ($passage === null) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Aucune décision de passage pour cette inscription.',
                    'data' => null
                ]);
            } else {
                $this->sendResponse(200, ['success' => true, 'data' => $passage]);
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function create(): void
    {
        $this->sendError(405, "Utilisez l’écran « Passage de niveau » pour approuver un passage.");
    }

    public function update(int $id): void
    {
        $this->sendError(405, "Utilisez l’écran « Passage de niveau » pour approuver un passage.");
    }

    public function delete(int $id): void
    {
        $this->sendError(405, $this->manualMessage());
    }

    public function decide(): void
    {
        $this->sendError(405, $this->manualMessage());
    }

    private function manualMessage(): string
    {
        return "Les passages de niveau s'approuvent depuis « Évaluations & notes > Passage de niveau » (sélection d'auditeurs, puis « Passe au niveau supérieur » ou « Redouble la classe »).";
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
        $this->sendResponse($code, ['success' => false, 'error' => $message]);
    }
}
