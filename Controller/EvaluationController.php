<?php
/**
 * Contrôleur pour la gestion des évaluations
 */

require_once __DIR__ . '/../Model/Evaluation.php';
require_once __DIR__ . '/../Model/ReglesEvaluation.php';
require_once __DIR__ . '/../Model/Notification.php';

class EvaluationController
{
    private Evaluation $model;
    private Notification $notifications;

    public function __construct()
    {
        $this->model = new Evaluation();
        $this->notifications = new Notification();
    }

    public function getAll(): void
    {
        try {
            $evaluations = $this->model->getAllWithSession();
            $this->sendResponse(200, ['success' => true, 'data' => $evaluations]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getById(int $id): void
    {
        try {
            $evaluation = $this->model->find($id);
            if ($evaluation === null) {
                $this->sendError(404, "Évaluation introuvable.");
                return;
            }
            $this->sendResponse(200, ['success' => true, 'data' => $evaluation]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getBySession(int $id_session): void
    {
        try {
            $evaluations = $this->model->getBySession($id_session);
            $this->sendResponse(200, ['success' => true, 'data' => $evaluations]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function create(): void
    {
        try {
            $data = $this->getJsonInput();

            $required = ['id_session', 'libelle', 'date_evaluation'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $this->sendError(400, "Le champ '{$field}' est requis.");
                    return;
                }
            }

            $data['id_session'] = filter_var($data['id_session'], FILTER_VALIDATE_INT);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $data['date_evaluation']);
            if (
                !$data['id_session']
                || $date === false
                || $date->format('Y-m-d') !== (string) $data['date_evaluation']
            ) {
                $this->sendError(400, "La session et une date d’évaluation valide sont requises.");
                return;
            }
            $data['libelle'] = trim((string) $data['libelle']);
            if ($data['libelle'] === '' || mb_strlen($data['libelle']) > 100) {
                $this->sendError(400, "Le libellé doit contenir entre 1 et 100 caractères.");
                return;
            }

            $numberError = $this->resolveNumber($data, (int) $data['id_session']);
            if ($numberError !== null) {
                $this->sendError(400, $numberError);
                return;
            }
            $dateError = $this->dateOutsideSession((int) $data['id_session'], (string) $data['date_evaluation']);
            if ($dateError !== null) {
                $this->sendError(409, $dateError);
                return;
            }
            $id = $this->model->create($data);
            $this->notifySessionAuditeurs(
                (int) $data['id_session'],
                'Nouvelle évaluation planifiée',
                "L'évaluation « {$data['libelle']} » est planifiée le " . $date->format('d/m/Y') . '. Consultez votre espace auditeur pour les détails.'
            );

            $this->sendResponse(201, [
                'success' => true,
                'message' => 'Évaluation créée. Les paramètres existants ont été associés automatiquement.',
                'data' => ['id' => $id]
            ]);
        } catch (PDOException $e) {
            $this->sendError((string) $e->getCode() === '23000' ? 400 : 500, "Erreur : " . $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function update(int $id): void
    {
        try {
            $current = $this->model->find($id);
            if ($current === null) {
                $this->sendError(404, "Évaluation introuvable.");
                return;
            }

            $data = $this->getJsonInput();
            if (array_key_exists('libelle', $data)) {
                $data['libelle'] = trim((string) $data['libelle']);
                if ($data['libelle'] === '' || mb_strlen($data['libelle']) > 100) {
                    $this->sendError(400, "Le libellé doit contenir entre 1 et 100 caractères.");
                    return;
                }
            }
            if (array_key_exists('date_evaluation', $data)) {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $data['date_evaluation']);
                if ($date === false || $date->format('Y-m-d') !== (string) $data['date_evaluation']) {
                    $this->sendError(400, "La date d’évaluation doit respecter le format AAAA-MM-JJ.");
                    return;
                }
            }
            if (array_key_exists('numero', $data) || array_key_exists('id_session', $data)) {
                $data['numero'] = $data['numero'] ?? $current['numero'];
                $numberError = $this->resolveNumber($data, (int) ($data['id_session'] ?? $current['id_session']), $id);
                if ($numberError !== null) {
                    $this->sendError(400, $numberError);
                    return;
                }
            }
            $dateError = $this->dateOutsideSession(
                (int) ($data['id_session'] ?? $current['id_session']),
                (string) ($data['date_evaluation'] ?? $current['date_evaluation'])
            );
            if ($dateError !== null) {
                $this->sendError(409, $dateError);
                return;
            }
            $success = $this->model->update($id, $data);

            if ($success) {
                if (array_intersect(['libelle', 'date_evaluation', 'id_session'], array_keys($data))) {
                    $updated = array_replace($current, $data);
                    $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $updated['date_evaluation']);
                    $dateLabel = $date ? $date->format('d/m/Y') : (string) $updated['date_evaluation'];
                    $this->notifySessionAuditeurs(
                        (int) $updated['id_session'],
                        'Évaluation mise à jour',
                        "L'évaluation « {$updated['libelle']} » a été mise à jour. Date prévue : {$dateLabel}. Consultez votre espace auditeur."
                    );
                }
                $this->sendResponse(200, ['success' => true, 'message' => 'Évaluation mise à jour.']);
            } else {
                $this->sendError(400, "Aucune donnée valide.");
            }
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    /**
     * Crée automatiquement les évaluations prévues (1 à N) manquantes d'une session.
     * POST /evaluations/generer/{id_session}
     */
    public function generer(int $id_session): void
    {
        try {
            $count = (new ReglesEvaluation())->getRegles($this->model->niveauOfSession($id_session))['nb_evaluations'];
            $created = $this->model->generateForSession($id_session, $count);
            if ($created > 0) {
                $this->notifySessionAuditeurs(
                    $id_session,
                    'Évaluations planifiées',
                    "{$created} nouvelle(s) évaluation(s) ont été planifiée(s) pour votre session. Consultez votre espace auditeur pour les dates."
                );
            }
            $this->sendResponse(201, [
                'success' => true,
                'message' => $created
                    ? "{$created} évaluation(s) créée(s). Les paramètres existants sont associés automatiquement et restent modifiables dans la configuration des évaluations."
                    : "Les {$count} évaluations de cette session existent déjà.",
                'data' => ['created' => $created],
            ]);
        } catch (InvalidArgumentException $e) {
            $this->sendError(404, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    /**
     * Numéro d'évaluation : 1 à N (règles), unique dans la session ; attribué automatiquement si absent.
     * @return string|null Message d'erreur.
     */
    /** Une évaluation a lieu pendant sa session. @return string|null message de refus */
    private function dateOutsideSession(int $idSession, string $date): ?string
    {
        $stmt = Database::getInstance()->prepare('SELECT date_debut, date_fin FROM sessions WHERE id = :id');
        $stmt->execute(['id' => $idSession]);
        $session = $stmt->fetch();
        if ($session && ($date < $session['date_debut'] || $date > $session['date_fin'])) {
            return "La date de l’évaluation doit être comprise dans la période de la session (du {$session['date_debut']} au {$session['date_fin']}).";
        }
        return null;
    }

    private function resolveNumber(array &$data, int $idSession, ?int $excludeId = null): ?string
    {
        $max = (new ReglesEvaluation())->getRegles($this->model->niveauOfSession($idSession))['nb_evaluations'];
        $used = $this->model->usedNumbers($idSession, $excludeId);
        if (!isset($data['numero']) || $data['numero'] === '' || $data['numero'] === null) {
            $free = array_values(array_diff(range(1, $max), $used));
            if ($free === []) {
                return "Cette session a déjà ses {$max} évaluations prévues.";
            }
            $data['numero'] = $free[0];
            return null;
        }

        $numero = filter_var($data['numero'], FILTER_VALIDATE_INT);
        if ($numero === false || $numero < 1 || $numero > $max) {
            return "Le numéro d’évaluation doit être compris entre 1 et {$max}.";
        }
        if (in_array($numero, $used, true)) {
            return "L’évaluation n° {$numero} existe déjà pour cette session.";
        }
        $data['numero'] = $numero;
        return null;
    }

    private function notifySessionAuditeurs(int $sessionId, string $title, string $message): void
    {
        try {
            $this->notifications->createForActiveAuditeursInSession($sessionId, $title, $message);
        } catch (Throwable $error) {
            error_log('AGES : impossible de créer les notifications de planification d’évaluation : ' . $error->getMessage());
        }
    }

    public function delete(int $id): void
    {
        try {
            $success = $this->model->delete($id);
            if ($success) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Évaluation supprimée.']);
            } else {
                $this->sendError(404, "Évaluation introuvable.");
            }
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
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function sendError(int $code, string $message): void
    {
        $this->sendResponse($code, ['success' => false, 'error' => $message]);
    }
}
