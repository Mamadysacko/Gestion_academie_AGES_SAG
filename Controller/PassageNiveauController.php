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
        $this->sendError(405, "Utilisez la route /passages-niveaux/decide afin de recalculer et valider la moyenne côté serveur.");
    }

    public function update(int $id): void
    {
        $this->sendError(405, "Utilisez la route /passages-niveaux/decide afin de recalculer et valider la moyenne côté serveur.");
    }

    public function delete(int $id): void
    {
        try {
            $success = $this->model->delete($id);
            if ($success) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Passage supprimé.']);
            } else {
                $this->sendError(404, "Passage introuvable.");
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function decide(): void
    {
        try {
            $data = $this->getJsonInput();

            $required = ['id_inscription', 'id_evaluation', 'decision'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $this->sendError(400, "Le champ '{$field}' est requis.");
                    return;
                }
            }

            if (!in_array($data['decision'], PassageNiveau::DECISIONS)) {
                $this->sendError(400, "Décision invalide. Valeurs : " . implode(', ', PassageNiveau::DECISIONS));
                return;
            }

            $niveau_arrivee = isset($data['niveau_arrivee']) && $data['niveau_arrivee'] !== '' ? (int)$data['niveau_arrivee'] : null;
            $observation = $data['observation'] ?? null;

            $success = $this->model->decide(
                (int)$data['id_inscription'],
                (int)$data['id_evaluation'],
                $data['decision'],
                $niveau_arrivee,
                $observation
            );

            if ($success) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Décision de passage enregistrée avec succès.'
                ]);
            } else {
                $this->sendError(400, "Impossible d'enregistrer la décision.");
            }
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (RuntimeException $e) {
            $this->sendError(404, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
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
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function sendError(int $code, string $message): void
    {
        $this->sendResponse($code, ['success' => false, 'error' => $message]);
    }
}
