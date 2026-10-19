<?php
/**
 * Rapports : catalogue et génération
 *
 * GET /rapports                 → catalogue (catégories + rapports + filtres proposés)
 * GET /rapports/{code}?filtres  → rapport généré (sections de tableaux)
 */

require_once __DIR__ . '/../Model/Rapport.php';

class RapportController
{
    public function catalogue(): void
    {
        $this->send(200, ['success' => true, 'data' => (new Rapport())->catalogue()]);
    }

    public function generate(string $code): void
    {
        try {
            $this->send(200, ['success' => true, 'data' => (new Rapport())->generate($code, $_GET)]);
        } catch (InvalidArgumentException $e) {
            $this->send(400, ['success' => false, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('Rapport ' . $code . ' : ' . $e->getMessage());
            $this->send(500, ['success' => false, 'error' => 'Impossible de générer ce rapport : ' . $e->getMessage()]);
        }
    }

    private function send(int $code, array $data): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
