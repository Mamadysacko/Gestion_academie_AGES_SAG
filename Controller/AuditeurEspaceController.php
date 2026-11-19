<?php
/**
 * Contrôleur pour l'espace personnel de l'auditeur connecté.
 *
 * Toutes les routes sont préfixées par /auditeur et ne retournent
 * que les données de l'auditeur authentifié (rôle auditeur).
 *
 * GET  /auditeur/profil              → infos personnelles
 * PUT  /auditeur/profil              → modifier infos + mot de passe
 * GET  /auditeur/notes               → notes par évaluation
 * GET  /auditeur/emploi-du-temps     → emploi du temps du groupe inscrit
 * GET  /auditeur/presences           → historique présences + taux
 * GET  /auditeur/notifications       → liste notifications
 * PUT  /auditeur/notifications/{id}/lu   → marquer lue
 * PUT  /auditeur/notifications/tout-lire → marquer toutes lues
 * GET  /auditeur/bulletin            → bulletin de notes complet
 * GET  /auditeur/dashboard           → résumé tableau de bord
 */

require_once __DIR__ . '/../Model/User.php';
require_once __DIR__ . '/../Model/Auditeur.php';
require_once __DIR__ . '/../Model/Notification.php';

class AuditeurEspaceController
{
    private User $userModel;
    private Auditeur $auditeurModel;
    private Notification $notifModel;

    public function __construct()
    {
        $this->userModel     = new User();
        $this->auditeurModel = new Auditeur();
        $this->notifModel    = new Notification();
    }

    /* ----------------------------------------------------------------
     | Helpers d'authentification
     |---------------------------------------------------------------- */

    /** Retourne l'auditeur lié au compte connecté, ou envoie 403/404. */
    private function resolveAuditeur(): ?array
    {
        $user = Authorization::currentUser();
        if ($user === null) {
            $this->sendError(401, 'Authentification requise.');
            return null;
        }
        if (($user['role_code'] ?? '') !== 'auditeur') {
            $this->sendError(403, 'Cet espace est réservé aux auditeurs.');
            return null;
        }

        $auditeur = $this->fetchOne(
            "SELECT a.* FROM auditeurs a WHERE a.user_id = :uid LIMIT 1",
            ['uid' => (int) $user['id']]
        );
        if ($auditeur === null) {
            $this->sendError(404, 'Aucun profil auditeur lié à ce compte.');
            return null;
        }

        $auditeur['_user'] = $user;
        return $auditeur;
    }

    private function userId(): int
    {
        return (int) Authorization::userId();
    }

    /* ================================================================
     | PROFIL
     |================================================================ */

    public function getProfil(): void
    {
        try {
            $auditeur = $this->resolveAuditeur();
            if ($auditeur === null) return;

            $user = $auditeur['_user'];
            unset($auditeur['_user']);

            // Inscriptions actives
            require_once __DIR__ . '/../Model/Inscription.php';
            $inscriptions = (new Inscription())->getByAuditeur((int) $auditeur['id']);

            $this->sendResponse(200, [
                'success' => true,
                'data'    => [
                    'auditeur'     => $auditeur,
                    'user'         => [
                        'id'          => $user['id'],
                        'nom'         => $user['nom'],
                        'prenom'      => $user['prenom'],
                        'email'       => $user['email'],
                        'telephone'   => $user['telephone'],
                        'first_login' => (int) ($user['first_login'] ?? 0),
                    ],
                    'inscriptions' => $inscriptions,
                ],
            ]);
        } catch (Exception $e) {
            $this->sendError(500, 'Erreur : ' . $e->getMessage());
        }
    }

    public function updateProfil(): void
    {
        try {
            $auditeur = $this->resolveAuditeur();
            if ($auditeur === null) return;

            $data = $this->getJsonInput();
            $userId = $this->userId();
            $auditeurId = (int) $auditeur['id'];

            // Champs modifiables
            $userUpdate = [];
            foreach (['nom', 'prenom', 'telephone', 'email'] as $field) {
                if (isset($data[$field])) {
                    $userUpdate[$field] = trim((string) $data[$field]);
                }
            }

            // Validation email
            if (isset($userUpdate['email']) && $userUpdate['email'] !== '') {
                if (!filter_var($userUpdate['email'], FILTER_VALIDATE_EMAIL)) {
                    $this->sendError(400, "L'email fourni n'est pas valide.");
                    return;
                }
                $existing = $this->userModel->findByEmail($userUpdate['email']);
                if ($existing !== null && (int) $existing['id'] !== $userId) {
                    $this->sendError(409, "Cet email est déjà utilisé.");
                    return;
                }
            }

            // Changement de mot de passe
            $passwordChanged = false;
            if (!empty($data['nouveau_mot_de_passe'])) {
                if (mb_strlen($data['nouveau_mot_de_passe']) < 6) {
                    $this->sendError(400, 'Le mot de passe doit contenir au moins 6 caractères.');
                    return;
                }
                // Vérifier l'ancien mot de passe (sauf si first_login)
                if (empty($auditeur['_user']['first_login'])) {
                    if (empty($data['ancien_mot_de_passe'])) {
                        $this->sendError(400, "L'ancien mot de passe est requis.");
                        return;
                    }
                    $currentHash = $this->userModel->getPasswordForSync($userId);
                    if (!password_verify($data['ancien_mot_de_passe'], $currentHash)) {
                        $this->sendError(400, "L'ancien mot de passe est incorrect.");
                        return;
                    }
                }
                $userUpdate['password'] = $data['nouveau_mot_de_passe'];
                $passwordChanged = true;
            }

            // Mettre à jour users
            if (!empty($userUpdate)) {
                $this->userModel->update($userId, $userUpdate);
            }

            // Synchroniser les champs dans auditeurs
            $auditeurUpdate = [];
            foreach (['nom', 'prenom', 'telephone', 'email'] as $field) {
                if (isset($userUpdate[$field])) {
                    $auditeurUpdate[$field] = $userUpdate[$field];
                }
            }
            if (!empty($auditeurUpdate)) {
                $this->auditeurModel->update($auditeurId, $auditeurUpdate);
            }

            // Marquer first_login = 0
            if (!empty($data['first_login_done']) || $passwordChanged) {
                $db = $this->userModel->getDatabase();
                $db->prepare("UPDATE users SET first_login = 0 WHERE id = :id")->execute(['id' => $userId]);
            }

            if ($passwordChanged) {
                $this->userModel->revokeSessions($userId);
            }

            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Profil mis à jour avec succès.',
            ]);
        } catch (Exception $e) {
            $this->sendError(500, 'Erreur : ' . $e->getMessage());
        }
    }

    /* ================================================================
     | NOTES
     |================================================================ */

    public function getNotes(): void
    {
        try {
            $auditeur = $this->resolveAuditeur();
            if ($auditeur === null) return;

            $rows = $this->fetchAll(
                "SELECT e.id AS id_evaluation, e.libelle AS evaluation, e.date_evaluation,
                        e.numero, p.id AS id_parametre, p.libelle AS parametre, p.bareme,
                        n.note, n.observation,
                        s.libelle AS session_libelle, s.id AS id_session
                 FROM notes n
                 JOIN evaluations e ON e.id = n.id_evaluation
                 JOIN parametres p ON p.id = n.id_parametre
                 JOIN inscriptions i ON i.id = n.id_inscription
                 JOIN sessions s ON s.id = i.id_session
                 WHERE i.id_auditeur = :id_auditeur
                 ORDER BY e.date_evaluation DESC, e.numero, p.id",
                ['id_auditeur' => (int) $auditeur['id']]
            );

            // Regrouper par évaluation
            $evaluations = [];
            foreach ($rows as $row) {
                $key = (int) $row['id_evaluation'];
                if (!isset($evaluations[$key])) {
                    $evaluations[$key] = [
                        'id'              => $key,
                        'libelle'         => $row['evaluation'],
                        'date_evaluation' => $row['date_evaluation'],
                        'numero'          => (int) $row['numero'],
                        'session'         => $row['session_libelle'],
                        'notes'           => [],
                    ];
                }
                $evaluations[$key]['notes'][] = [
                    'parametre'   => $row['parametre'],
                    'bareme'      => (float) $row['bareme'],
                    'note'        => $row['note'] !== null ? (float) $row['note'] : null,
                    'observation' => $row['observation'],
                ];
            }

            // Calculer les moyennes par évaluation
            foreach ($evaluations as &$eval) {
                $total = 0;
                $count = 0;
                foreach ($eval['notes'] as $n) {
                    if ($n['note'] !== null) {
                        $total += $n['note'];
                        $count++;
                    }
                }
                $eval['moyenne'] = $count > 0 ? round($total / $count, 2) : null;
            }

            $this->sendResponse(200, [
                'success' => true,
                'data'    => array_values($evaluations),
            ]);
        } catch (Exception $e) {
            $this->sendError(500, 'Erreur : ' . $e->getMessage());
        }
    }

    /* ================================================================
     | EMPLOI DU TEMPS
     |================================================================ */

    public function getEmploiDuTemps(): void
    {
        try {
            $auditeur = $this->resolveAuditeur();
            if ($auditeur === null) return;

            $rows = $this->fetchAll(
                "SELECT edt.id, edt.jour_semaine, edt.heure_debut, edt.heure_fin,
                        g.code AS groupe_code, g.libelle AS groupe_libelle,
                        s.libelle AS session_libelle,
                        sa.nom AS salle_nom, sa.code AS salle_code,
                        CONCAT(u.prenom, ' ', u.nom) AS formateur_nom
                 FROM inscriptions i
                 JOIN emplois_du_temps edt ON edt.id = i.id_emploi_du_temps
                 JOIN groupes g ON g.id = edt.id_groupe
                 JOIN sessions s ON s.id = edt.id_session
                 JOIN salles sa ON sa.id = edt.id_salle
                 JOIN formateurs f ON f.id = edt.id_formateur
                 JOIN users u ON u.id = f.user_id
                 WHERE i.id_auditeur = :id_auditeur AND i.statut = 'active'
                 ORDER BY edt.jour_semaine, edt.heure_debut",
                ['id_auditeur' => (int) $auditeur['id']]
            );

            $jours = ['', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi'];
            foreach ($rows as &$row) {
                $row['jour_libelle'] = $jours[(int) $row['jour_semaine']] ?? 'Jour ' . $row['jour_semaine'];
            }

            $this->sendResponse(200, [
                'success' => true,
                'data'    => $rows,
            ]);
        } catch (Exception $e) {
            $this->sendError(500, 'Erreur : ' . $e->getMessage());
        }
    }

    /* ================================================================
     | PRESENCES
     |================================================================ */

    public function getPresences(): void
    {
        try {
            $auditeur = $this->resolveAuditeur();
            if ($auditeur === null) return;

            $rows = $this->fetchAll(
                "SELECT pr.id, pr.date_presence, pr.statut, pr.observation,
                        g.libelle AS groupe, s.libelle AS session
                 FROM presences pr
                 JOIN inscriptions i ON i.id = pr.id_inscription
                 JOIN emplois_du_temps edt ON edt.id = i.id_emploi_du_temps
                 JOIN groupes g ON g.id = edt.id_groupe
                 JOIN sessions s ON s.id = edt.id_session
                 WHERE i.id_auditeur = :id_auditeur
                 ORDER BY pr.date_presence DESC",
                ['id_auditeur' => (int) $auditeur['id']]
            );

            // Calculer les statistiques
            $total    = count($rows);
            $presents = 0;
            $absents  = 0;
            foreach ($rows as $row) {
                if ($row['statut'] === 'present') $presents++;
                elseif ($row['statut'] === 'absent') $absents++;
            }
            $taux = $total > 0 ? round($presents / $total * 100, 1) : 0;

            $this->sendResponse(200, [
                'success' => true,
                'data'    => [
                    'presences'    => $rows,
                    'statistiques' => [
                        'total'    => $total,
                        'presents' => $presents,
                        'absents'  => $absents,
                        'taux'     => $taux,
                    ],
                ],
            ]);
        } catch (Exception $e) {
            $this->sendError(500, 'Erreur : ' . $e->getMessage());
        }
    }

    /* ================================================================
     | NOTIFICATIONS
     |================================================================ */

    public function getNotifications(): void
    {
        try {
            $user = Authorization::currentUser();
            if ($user === null) {
                $this->sendError(401, 'Authentification requise.');
                return;
            }
            $userId  = (int) $user['id'];
            $unread  = isset($_GET['unread']) && $_GET['unread'] === '1';
            $notifs  = $this->notifModel->getForUser($userId, $unread);
            $countUr = $this->notifModel->countUnread($userId);

            $this->sendResponse(200, [
                'success' => true,
                'data'    => [
                    'notifications' => $notifs,
                    'non_lues'      => $countUr,
                ],
            ]);
        } catch (Exception $e) {
            $this->sendError(500, 'Erreur : ' . $e->getMessage());
        }
    }

    public function markNotificationRead(int $id): void
    {
        try {
            $user = Authorization::currentUser();
            if ($user === null) {
                $this->sendError(401, 'Authentification requise.');
                return;
            }
            $this->notifModel->markRead($id, (int) $user['id']);
            $this->sendResponse(200, ['success' => true, 'message' => 'Notification lue.']);
        } catch (Exception $e) {
            $this->sendError(500, 'Erreur : ' . $e->getMessage());
        }
    }

    public function markAllNotificationsRead(): void
    {
        try {
            $user = Authorization::currentUser();
            if ($user === null) {
                $this->sendError(401, 'Authentification requise.');
                return;
            }
            $count = $this->notifModel->markAllRead((int) $user['id']);
            $this->sendResponse(200, [
                'success' => true,
                'message' => "{$count} notification(s) marquée(s) comme lue(s).",
            ]);
        } catch (Exception $e) {
            $this->sendError(500, 'Erreur : ' . $e->getMessage());
        }
    }

    /* ================================================================
     | BULLETIN
     |================================================================ */

    public function getBulletin(): void
    {
        try {
            $auditeur = $this->resolveAuditeur();
            if ($auditeur === null) return;
            unset($auditeur['_user']);

            $auditeurId = (int) $auditeur['id'];

            // Inscriptions actives
            require_once __DIR__ . '/../Model/Inscription.php';
            $inscriptions = (new Inscription())->getByAuditeur($auditeurId);

            // Notes groupées
            $notes = $this->fetchAll(
                "SELECT e.id AS id_evaluation, e.libelle AS evaluation, e.date_evaluation,
                        e.numero, p.libelle AS parametre, p.bareme,
                        n.note, n.observation,
                        s.libelle AS session_libelle
                 FROM notes n
                 JOIN evaluations e ON e.id = n.id_evaluation
                 JOIN parametres p ON p.id = n.id_parametre
                 JOIN inscriptions i ON i.id = n.id_inscription
                 JOIN sessions s ON s.id = i.id_session
                 WHERE i.id_auditeur = :id_auditeur
                 ORDER BY e.date_evaluation, e.numero, p.id",
                ['id_auditeur' => $auditeurId]
            );

            // Résultats / Passages
            require_once __DIR__ . '/../Model/Resultat.php';
            $resultats = [];
            try {
                $resultats = (new Resultat())->getAuditeurParcours($auditeurId)['parcours'] ?? [];
            } catch (Exception $e) { /* pas de résultats */ }

            // Taux présence
            $presRow = $this->fetchOne(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN pr.statut = 'present' THEN 1 ELSE 0 END) AS presents
                 FROM presences pr
                 JOIN inscriptions i ON i.id = pr.id_inscription
                 WHERE i.id_auditeur = :id_auditeur",
                ['id_auditeur' => $auditeurId]
            );
            $total    = (int) ($presRow['total'] ?? 0);
            $presents = (int) ($presRow['presents'] ?? 0);
            $taux     = $total > 0 ? round($presents / $total * 100, 1) : null;

            $this->sendResponse(200, [
                'success' => true,
                'data'    => [
                    'auditeur'     => $auditeur,
                    'inscriptions' => $inscriptions,
                    'notes'        => $notes,
                    'resultats'    => $resultats,
                    'taux_presence' => $taux,
                ],
            ]);
        } catch (Exception $e) {
            $this->sendError(500, 'Erreur : ' . $e->getMessage());
        }
    }

    /* ================================================================
     | DASHBOARD RÉSUMÉ
     |================================================================ */

    public function getDashboard(): void
    {
        try {
            $auditeur = $this->resolveAuditeur();
            if ($auditeur === null) return;

            $userId = $this->userId();
            $auditeurId = (int) $auditeur['id'];

            // Dernières notes (5)
            $lastNotes = $this->fetchAll(
                "SELECT e.libelle AS evaluation, p.libelle AS parametre,
                        n.note, p.bareme, e.date_evaluation
                 FROM notes n
                 JOIN evaluations e ON e.id = n.id_evaluation
                 JOIN parametres p ON p.id = n.id_parametre
                 JOIN inscriptions i ON i.id = n.id_inscription
                 WHERE i.id_auditeur = :id AND n.note IS NOT NULL
                 ORDER BY e.date_evaluation DESC, n.id_note DESC
                 LIMIT 5",
                ['id' => $auditeurId]
            );

            // Prochain cours (aujourd'hui ou après)
            $dayOfWeek = (int) date('N'); // 1=lundi
            $nextCourse = $this->fetchOne(
                "SELECT edt.jour_semaine, edt.heure_debut, edt.heure_fin,
                        g.libelle AS groupe, sa.nom AS salle,
                        CONCAT(u.prenom, ' ', u.nom) AS formateur
                 FROM inscriptions i
                 JOIN emplois_du_temps edt ON edt.id = i.id_emploi_du_temps
                 JOIN groupes g ON g.id = edt.id_groupe
                 JOIN salles sa ON sa.id = edt.id_salle
                 JOIN formateurs f ON f.id = edt.id_formateur
                 JOIN users u ON u.id = f.user_id
                 WHERE i.id_auditeur = :id AND i.statut = 'active'
                   AND edt.jour_semaine >= :dow
                 ORDER BY edt.jour_semaine, edt.heure_debut
                 LIMIT 1",
                ['id' => $auditeurId, 'dow' => $dayOfWeek]
            );

            // Taux assiduité
            $presRow = $this->fetchOne(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN pr.statut='present' THEN 1 ELSE 0 END) AS presents
                 FROM presences pr JOIN inscriptions i ON i.id = pr.id_inscription
                 WHERE i.id_auditeur = :id",
                ['id' => $auditeurId]
            );
            $total = (int) ($presRow['total'] ?? 0);
            $presents = (int) ($presRow['presents'] ?? 0);

            // Notifications non lues
            $notifsCount = $this->notifModel->countUnread($userId);

            $this->sendResponse(200, [
                'success' => true,
                'data'    => [
                    'dernieres_notes'   => $lastNotes,
                    'prochain_cours'    => $nextCourse,
                    'taux_assiduite'    => $total > 0 ? round($presents / $total * 100, 1) : null,
                    'total_presences'   => $total,
                    'notifications_non_lues' => $notifsCount,
                ],
            ]);
        } catch (Exception $e) {
            $this->sendError(500, 'Erreur : ' . $e->getMessage());
        }
    }

    /* ================================================================
     | ALERTES DE PRÉVENTION
     |================================================================ */

    public function getAlertes(): void
    {
        try {
            $auditeur = $this->resolveAuditeur();
            if ($auditeur === null) return;

            $auditeurId = (int) $auditeur['id'];
            $alertes    = [];

            // ── 1. Évaluations à venir dans les 7 prochains jours ────────────
            $evals = $this->fetchAll(
                "SELECT e.id, e.libelle, e.date_evaluation,
                        s.libelle AS session_libelle
                 FROM evaluations e
                 JOIN sessions s ON s.id = e.id_session
                 JOIN emplois_du_temps edt ON edt.id_session = s.id
                 JOIN inscriptions i ON i.id_emploi_du_temps = edt.id AND i.id_auditeur = :id_auditeur AND i.statut = 'active'
                 WHERE e.date_evaluation BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                 ORDER BY e.date_evaluation",
                ['id_auditeur' => $auditeurId]
            );
            foreach ($evals as $ev) {
                $jours = (int) (new DateTime($ev['date_evaluation']))->diff(new DateTime('today'))->days;
                $alertes[] = [
                    'type'            => 'evaluation',
                    'niveau'          => 'info',
                    'titre'           => "Évaluation dans {$jours} jour(s) : {$ev['libelle']}",
                    'message'         => "L'évaluation « {$ev['libelle']} » de la session « {$ev['session_libelle']} » a lieu le {$ev['date_evaluation']}.",
                    'date_evaluation' => $ev['date_evaluation'],
                ];
            }

            // ── 2. Alerte de présence ─────────────────────────────────────────
            $presRow = $this->fetchOne(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN pr.statut = 'absent' THEN 1 ELSE 0 END) AS absents
                 FROM presences pr
                 JOIN inscriptions i ON i.id = pr.id_inscription
                 WHERE i.id_auditeur = :id_auditeur",
                ['id_auditeur' => $auditeurId]
            );
            $total   = (int) ($presRow['total']  ?? 0);
            $absents = (int) ($presRow['absents'] ?? 0);
            if ($total > 0) {
                $tauxAbsence = $absents / $total * 100;
                if ($tauxAbsence > 30) {
                    $alertes[] = [
                        'type'    => 'presence',
                        'niveau'  => 'danger',
                        'titre'   => "Taux d'absence critique",
                        'message' => sprintf(
                            "Votre taux d'absence est de %.1f %% (seuil critique : 30 %%).",
                            $tauxAbsence
                        ),
                    ];
                } elseif ($tauxAbsence > 20) {
                    $alertes[] = [
                        'type'    => 'presence',
                        'niveau'  => 'warning',
                        'titre'   => "Taux d'absence élevé",
                        'message' => sprintf(
                            "Votre taux d'absence est de %.1f %% (seuil : 20 %%).",
                            $tauxAbsence
                        ),
                    ];
                }
            }

            $this->sendResponse(200, [
                'success' => true,
                'data'    => ['alertes' => $alertes],
            ]);
        } catch (Exception $e) {
            $this->sendError(500, 'Erreur : ' . $e->getMessage());
        }
    }

    /* ----------------------------------------------------------------
     | Helpers
     |---------------------------------------------------------------- */

    private function fetchOne(string $sql, array $params = []): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function fetchAll(string $sql, array $params = []): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
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
