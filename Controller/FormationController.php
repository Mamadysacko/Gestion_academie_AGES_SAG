<?php
/**
 * Configuration de référence des formations (passages, groupes, salles dédiées, calendrier).
 *
 * GET  /formations                     → formations et configuration courante
 * GET  /formations/{id}                → configuration + salles rattachées
 * PUT  /formations/{id}/configuration  → nouvelle version de configuration (motif conseillé)
 * GET  /formations/{id}/historique     → versions successives (qui, quand, pourquoi)
 * GET  /formations/{id}/salles         → salles dédiées / partagées
 * PUT  /formations/{id}/salles         → remplace les salles rattachées
 */

require_once __DIR__ . '/../Model/FormationConfig.php';

class FormationController
{
    private FormationConfig $model;

    public function __construct()
    {
        $this->model = new FormationConfig();
    }

    public function getAll(): void
    {
        $this->handle(fn() => $this->sendResponse(200, ['success' => true, 'data' => $this->model->getAllWithConfig()]));
    }

    public function getById(int $id): void
    {
        $this->handle(function () use ($id) {
            $formation = $this->model->getWithConfig($id);
            if ($formation === null) {
                $this->sendError(404, 'Formation introuvable.');
                return;
            }
            $this->sendResponse(200, ['success' => true, 'data' => $formation]);
        });
    }

    public function updateConfiguration(int $id): void
    {
        $this->handle(function () use ($id) {
            $data = $this->getJsonInput();
            $motif = isset($data['motif']) ? trim((string) $data['motif']) : null;
            $changes = array_intersect_key($data, array_flip(array_merge(FormationConfig::FIELDS, ['couleur'])));
            if ($changes === []) {
                $this->sendError(400, 'Aucun paramètre de configuration à modifier.');
                return;
            }
            $result = $this->model->updateConfig($id, $changes, $motif !== '' ? $motif : null, Authorization::userId());
            $this->sendResponse(200, [
                'success' => true,
                'message' => $result['version_creee']
                    ? "Configuration enregistrée (version {$result['config_version']}). Elle s’appliquera aux nouvelles sessions ; les sessions existantes conservent leur configuration."
                    : 'Aucun changement de configuration (présentation mise à jour).',
                'data' => $result,
            ]);
        });
    }

    public function getHistory(int $id): void
    {
        $this->handle(fn() => $this->sendResponse(200, ['success' => true, 'data' => $this->model->getHistory($id)]));
    }

    public function getRooms(int $id): void
    {
        $this->handle(fn() => $this->sendResponse(200, ['success' => true, 'data' => $this->model->getRooms($id)]));
    }

    public function setRooms(int $id): void
    {
        $this->handle(function () use ($id) {
            $data = $this->getJsonInput();
            if (!isset($data['salles']) || !is_array($data['salles'])) {
                $this->sendError(400, 'Le champ « salles » (liste) est requis.');
                return;
            }
            // Partager une salle dédiée à une autre formation : autorisation explicite (droit « Valider » des salles).
            $canShare = Authorization::can('salles', 'validate');
            $rooms = $this->model->setRooms($id, $data['salles'], $canShare, Authorization::userId());
            $this->sendResponse(200, ['success' => true, 'message' => 'Salles de la formation enregistrées.', 'data' => $rooms]);
        });
    }

    /* ----------------------------------------------------------------
     | Helpers
     |---------------------------------------------------------------- */

    private function handle(callable $action): void
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (DomainException $e) {
            $code = (int) $e->getCode();
            $this->sendError(in_array($code, [403, 404, 409], true) ? $code : 409, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'Erreur sur la configuration de la formation : ' . $e->getMessage());
        }
    }

    private function getJsonInput(): array
    {
        $data = json_decode((string) file_get_contents('php://input'), true);
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
