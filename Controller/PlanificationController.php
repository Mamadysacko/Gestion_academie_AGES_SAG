<?php
/**
 * Planification automatique et contrôle du calendrier.
 *
 * GET  /planification/synthese?id_annee=&id_session=&date=   → indicateurs, occupation, conflits
 * GET  /planification/conflits?id_annee=&id_session=         → conflits et incohérences
 * GET  /planification/occupation?id_annee=&id_session=&date= → salles × jours
 * GET  /planification/generations/{id_session}               → historique des générations
 * POST /planification/simuler/{id_session}                   → propositions sans rien écrire
 * POST /planification/appliquer/{id_session}                 → écrit la solution choisie (transaction)
 * POST /planification/reprendre/{id_session}                 → reprend la configuration d'une session précédente
 * POST /planification/adopter-configuration/{id_session}     → applique à la session la configuration actuelle de la formation
 * POST /planification/verifier                               → conflits d'un créneau envisagé
 * POST /planification/alternatives                           → créneaux libres de remplacement
 * PUT  /planification/passages/{id_groupe}                   → jours de passage d'un groupe
 */

require_once __DIR__ . '/../Model/Planification.php';

class PlanificationController
{
    private Planification $model;

    public function __construct()
    {
        $this->model = new Planification();
    }

    public function synthese(): void
    {
        $this->handle(fn() => $this->ok($this->model->synthese($this->queryInt('id_annee'), $this->queryInt('id_session'), $this->queryDate())));
    }

    public function conflits(): void
    {
        $this->handle(fn() => $this->ok($this->model->conflicts($this->queryInt('id_annee'), $this->queryInt('id_session'))));
    }

    public function occupation(): void
    {
        $this->handle(fn() => $this->ok($this->model->occupation($this->queryInt('id_annee'), $this->queryInt('id_session'), $this->queryDate())));
    }

    public function generations(int $sessionId): void
    {
        $this->handle(function () use ($sessionId) {
            $stmt = Database::getInstance()->prepare(
                "SELECT pg.id, pg.mode, pg.statut, pg.nb_crees, pg.nb_supprimes, pg.created_at,
                        CONCAT(u.prenom, ' ', u.nom) AS auteur
                 FROM planification_generations pg LEFT JOIN users u ON u.id = pg.user_id
                 WHERE pg.id_session = :id ORDER BY pg.id DESC LIMIT 50"
            );
            $stmt->execute(['id' => $sessionId]);
            $this->ok($stmt->fetchAll());
        });
    }

    public function simuler(int $sessionId): void
    {
        $this->handle(fn() => $this->ok($this->model->simulate($sessionId, $this->options())));
    }

    public function appliquer(int $sessionId): void
    {
        $this->handle(function () use ($sessionId) {
            $result = $this->model->apply($sessionId, $this->options(), Authorization::userId());
            $message = $result['complete']
                ? sprintf('Planning appliqué : %d créneau(x) créé(s), %d retiré(s).', $result['creneaux_crees'], $result['creneaux_supprimes'])
                : sprintf('Planning partiel appliqué : %d créneau(x) créé(s). Groupe(s) non placé(s) : %s.', $result['creneaux_crees'], implode(', ', array_column($result['non_places'], 'code')));
            $this->sendResponse(200, ['success' => true, 'message' => $message, 'data' => $result]);
        });
    }

    public function reprendre(int $sessionId): void
    {
        $this->handle(function () use ($sessionId) {
            $data = $this->getJsonInput();
            $source = isset($data['id_session_source']) && (int) $data['id_session_source'] > 0 ? (int) $data['id_session_source'] : null;
            $result = $this->model->reprendre($sessionId, $source, (bool) ($data['generer'] ?? true), Authorization::userId());
            $this->sendResponse(200, ['success' => true, 'message' => $this->repriseMessage($result), 'data' => $result]);
        });
    }

    public function adopterConfiguration(int $sessionId): void
    {
        $this->handle(function () use ($sessionId) {
            $session = $this->model->getSession($sessionId);
            if (!in_array($session['statut'], ['planifiee', 'ouverte'], true)) {
                $this->sendError(409, 'Seule une session planifiée ou ouverte peut adopter une nouvelle configuration : l’historique des sessions en cours ou terminées est préservé.');
                return;
            }
            $config = $this->model->snapshotSession($sessionId);
            $this->sendResponse(200, ['success' => true, 'message' => "La session applique désormais la configuration version {$config['version']}.", 'data' => $config]);
        });
    }

    public function verifier(): void
    {
        $this->handle(function () {
            $data = $this->getJsonInput();
            foreach (['id_groupe', 'id_salle', 'jour_semaine'] as $field) {
                if (empty($data[$field]) || (int) $data[$field] <= 0) {
                    $this->sendError(400, "Le champ « {$field} » est requis.");
                    return;
                }
            }
            $groupId = (int) $data['id_groupe'];
            $day = (int) $data['jour_semaine'];
            $passage = $this->model->passageFor($groupId, $day);
            $issues = [];
            if (is_string($passage)) {
                $issues[] = ['type' => 'passage', 'id' => null, 'message' => $passage];
                $passage = [$data['heure_debut'] ?? '08:00:00', $data['heure_fin'] ?? '12:00:00', false];
            }
            $stmt = Database::getInstance()->prepare('SELECT g.id_session, f.id AS id_formateur FROM groupes g LEFT JOIN v_formateurs f ON f.id_salle = :salle WHERE g.id = :id');
            $stmt->execute(['id' => $groupId, 'salle' => (int) $data['id_salle']]);
            $ref = $stmt->fetch();
            if (!$ref) {
                $this->sendError(404, 'Groupe introuvable.');
                return;
            }
            if ($ref['id_formateur'] === null) {
                $issues[] = ['type' => 'formateur', 'id' => null, 'message' => 'Cette salle n’a pas de formateur titulaire.'];
            }
            $issues = array_merge($issues, $this->model->slotConflicts([
                'id_session' => (int) $ref['id_session'], 'id_groupe' => $groupId, 'id_salle' => (int) $data['id_salle'],
                'id_formateur' => (int) ($data['id_formateur'] ?? $ref['id_formateur'] ?? 0), 'jour_semaine' => $day,
                'heure_debut' => PlanningSolver::time((string) ($data['heure_debut'] ?? $passage[0])),
                'heure_fin' => PlanningSolver::time((string) ($data['heure_fin'] ?? $passage[1])),
            ], isset($data['exclude_id']) ? (int) $data['exclude_id'] : null));

            $this->ok([
                'disponible' => $issues === [],
                'conflits' => $issues,
                'heure_debut' => substr((string) $passage[0], 0, 5),
                'heure_fin' => substr((string) $passage[1], 0, 5),
                'nouveau_passage' => (bool) $passage[2],
                'alternatives' => $issues === [] ? null : $this->model->alternatives($groupId, isset($data['exclude_id']) ? (int) $data['exclude_id'] : null, $day, (int) $data['id_salle']),
            ]);
        });
    }

    public function alternatives(): void
    {
        $this->handle(function () {
            $data = $this->getJsonInput();
            $groupId = (int) ($data['id_groupe'] ?? 0);
            $rowId = isset($data['id_emploi_du_temps']) ? (int) $data['id_emploi_du_temps'] : null;
            if ($groupId <= 0 && $rowId !== null) {
                $stmt = Database::getInstance()->prepare('SELECT id_groupe FROM emplois_du_temps WHERE id = :id');
                $stmt->execute(['id' => $rowId]);
                $groupId = (int) $stmt->fetchColumn();
            }
            if ($groupId <= 0) {
                $this->sendError(400, 'Indiquez le groupe ou le créneau concerné.');
                return;
            }
            $this->ok($this->model->alternatives($groupId, $rowId, isset($data['jour_semaine']) ? (int) $data['jour_semaine'] : null));
        });
    }

    public function passages(int $groupId): void
    {
        $this->handle(function () use ($groupId) {
            $data = $this->getJsonInput();
            if (!isset($data['jours']) || !is_array($data['jours'])) {
                $this->sendError(400, 'Le champ « jours » (liste de 1 à 5) est requis.');
                return;
            }
            $result = $this->model->setPassages($groupId, $data['jours'], $data['heure_debut'] ?? null, $data['heure_fin'] ?? null, Authorization::userId());
            $this->sendResponse(200, [
                'success' => true,
                'message' => sprintf('Passages enregistrés : %d créneau(x) ajouté(s), %d retiré(s).', $result['creneaux_crees'], $result['creneaux_retires']),
                'data' => $result,
            ]);
        });
    }

    /* ----------------------------------------------------------------
     | Helpers
     |---------------------------------------------------------------- */

    public function repriseMessage(array $result): string
    {
        if ($result['session_source'] === null) {
            return 'Aucune session précédente pour cette formation : configuration de référence appliquée.';
        }
        $text = sprintf('Configuration reprise de « %s » : %d groupe(s).', $result['session_source']['libelle'], count($result['groupes_repris']));
        $planning = $result['planification'];
        if ($planning !== null) {
            $text .= $planning['complete']
                ? sprintf(' Planning généré sans conflit (%d créneau(x)).', $planning['creneaux_crees'] ?? 0)
                : sprintf(' Planning partiel : %s à placer manuellement.', implode(', ', array_column($planning['non_places'] ?? [], 'code')) ?: 'des groupes');
        }
        return $text;
    }

    private function options(): array
    {
        $data = $this->getJsonInput();
        return [
            'mode' => in_array($data['mode'] ?? 'completer', Planification::MODES, true) ? ($data['mode'] ?? 'completer') : 'completer',
            'solution' => max(0, (int) ($data['solution'] ?? 0)),
            'accepter_partiel' => (bool) ($data['accepter_partiel'] ?? false),
            'creer_groupes' => (bool) ($data['creer_groupes'] ?? true),
        ];
    }

    private function queryInt(string $key): ?int
    {
        $value = $_GET[$key] ?? null;
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function queryDate(): string
    {
        $date = (string) ($_GET['date'] ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
    }

    private function handle(callable $action): void
    {
        try {
            $action();
        } catch (PlanningException $e) {
            $this->sendResponse(409, ['success' => false, 'error' => $e->getMessage(), 'details' => $e->details]);
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (DomainException $e) {
            $code = (int) $e->getCode();
            $this->sendError(in_array($code, [403, 404, 409], true) ? $code : 409, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'Erreur de planification : ' . $e->getMessage());
        }
    }

    private function ok($data): void
    {
        $this->sendResponse(200, ['success' => true, 'data' => $data]);
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
