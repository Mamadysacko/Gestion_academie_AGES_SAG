<?php
/**
 * Contrôleur pour la gestion des emplois du temps
 */

require_once __DIR__ . '/../Model/EmploiDuTemps.php';
require_once __DIR__ . '/../Model/Formateur.php';
require_once __DIR__ . '/../Model/Planification.php';

class EmploiDuTempsController
{
    private EmploiDuTemps $model;

    public function __construct()
    {
        $this->model = new EmploiDuTemps();
    }

    public function getAll(): void
    {
        try {
            $edts = $this->scopeForTrainer($this->model->getPlanningComplet());
            $this->sendResponse(200, ['success' => true, 'data' => $edts]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getById(int $id): void
    {
        try {
            $edt = $this->model->getPlanningById($id);
            if ($edt === null) {
                $this->sendError(404, "Emploi du temps introuvable.");
                return;
            }
            $this->sendResponse(200, ['success' => true, 'data' => $edt]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getByGroupe(int $id_groupe): void
    {
        try {
            $edts = $this->model->getByGroupe($id_groupe);
            $this->sendResponse(200, ['success' => true, 'data' => $edts]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getBySalle(int $id_salle): void
    {
        try {
            $edts = $this->model->getBySalle($id_salle);
            $this->sendResponse(200, ['success' => true, 'data' => $edts]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getByFormateur(int $id_formateur): void
    {
        try {
            $edts = $this->model->getByFormateur($id_formateur);
            $this->sendResponse(200, ['success' => true, 'data' => $edts]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getByJour(int $jour_semaine): void
    {
        try {
            if (!in_array($jour_semaine, EmploiDuTemps::JOURS)) {
                $this->sendError(400, "Jour invalide. Valeurs : 1 à 5.");
                return;
            }
            $edts = $this->model->getByJour($jour_semaine);
            $this->sendResponse(200, ['success' => true, 'data' => $edts]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getByAnnee(int $id_annee): void
    {
        try {
            $edts = $this->model->getByAnnee($id_annee);
            $this->sendResponse(200, ['success' => true, 'data' => $edts]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function getPlanningComplet(): void
    {
        try {
            $data = $this->getJsonInput();
            $id_annee = isset($data['id_annee']) ? (int)$data['id_annee'] : null;
            
            $edts = $this->scopeForTrainer($this->model->getPlanningComplet($id_annee));
            $this->sendResponse(200, ['success' => true, 'data' => $edts]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function create(): void
    {
        try {
            $data = $this->getJsonInput();

            $required = ['id_annee', 'id_groupe', 'id_salle', 'jour_semaine'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $this->sendError(400, "Le champ '{$field}' est requis.");
                    return;
                }
            }

            foreach (['id_annee', 'id_groupe', 'id_salle', 'id_formateur', 'jour_semaine'] as $field) {
                if ($field === 'id_formateur' && (empty($data[$field]) || $data[$field] === '')) {
                    $data[$field] = 0;
                    continue;
                }
                if (!filter_var($data[$field], FILTER_VALIDATE_INT) || (int) $data[$field] <= 0) {
                    $this->sendError(400, "Le champ '{$field}' doit être un entier positif.");
                    return;
                }
                $data[$field] = (int) $data[$field];
            }
            if (!isset($data['statut'])) {
                $data['statut'] = 1;
            }
            if (!in_array((string) $data['statut'], ['0', '1'], true)) {
                $this->sendError(400, "Le statut doit être égal à 0 ou 1.");
                return;
            }

            if (!in_array((int)$data['jour_semaine'], EmploiDuTemps::JOURS)) {
                $this->sendError(400, "Jour invalide. Valeurs : 1 à 5.");
                return;
            }

            $resolved = $this->model->resolveTrainerRoom($data);
            if (is_string($resolved)) {
                $this->sendError(400, $resolved);
                return;
            }
            $data['id_salle'] = $resolved[0];
            $data['id_formateur'] = $resolved[1];

            $validation = $this->model->validateAssignment($data);
            if ($validation !== null) {
                $this->sendError(400, $validation);
                return;
            }

            $planning = new Planification();
            $db = Database::getInstance();
            $planning->acquireLock();
            $ownTx = !$db->inTransaction();
            if ($ownTx) {
                $db->beginTransaction();
            }
            try {
                $newPassage = $this->placeSlot($planning, $data, null);
                $data['origine'] = 'manuelle';
                $id = $this->model->create($data);
                if ($newPassage) {
                    $planning->ensurePassage($data['id_groupe'], $data['jour_semaine'], $data['heure_debut'], $data['heure_fin']);
                }
                (new Formateur())->validateSalleAssignment($data['id_formateur'], $data['id_salle'], true);
                if ($ownTx) {
                    $db->commit();
                }
            } catch (Throwable $error) {
                if ($ownTx && $db->inTransaction()) {
                    $db->rollBack();
                }
                throw $error;
            } finally {
                $planning->releaseLock();
            }

            $this->sendResponse(201, [
                'success' => true,
                'message' => 'Emploi du temps créé avec succès.' . ($newPassage ? ' Ce jour a été ajouté aux passages hebdomadaires du groupe.' : ''),
                'data' => ['id' => $id, 'heure_debut' => substr($data['heure_debut'], 0, 5), 'heure_fin' => substr($data['heure_fin'], 0, 5), 'nouveau_passage' => $newPassage]
            ]);
        } catch (PlanningException $e) {
            $this->sendResponse(409, ['success' => false, 'error' => $e->getMessage(), 'details' => $e->details]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                $this->sendError(409, "Ce créneau entre en conflit avec une autre réservation de salle, de formateur ou de groupe.");
                return;
            }
            $this->sendError(500, "Erreur : " . $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function update(int $id): void
    {
        try {
            $current = $this->model->find($id);
            if ($current === null) {
                $this->sendError(404, "Emploi du temps introuvable.");
                return;
            }

            $data = $this->getJsonInput();

            if (isset($data['jour_semaine']) && !in_array((int)$data['jour_semaine'], EmploiDuTemps::JOURS)) {
                $this->sendError(400, "Jour invalide. Valeurs : 1 à 5.");
                return;
            }

            $assignment = array_merge($current, $data);
            if (!in_array((string) $assignment['statut'], ['0', '1'], true)) {
                $this->sendError(400, "Le statut doit être égal à 0 ou 1.");
                return;
            }
            foreach (['id_annee', 'id_groupe', 'id_salle', 'id_formateur', 'jour_semaine'] as $field) {
                if (!isset($assignment[$field]) || !filter_var($assignment[$field], FILTER_VALIDATE_INT) || (int) $assignment[$field] <= 0) {
                    $this->sendError(400, "Le champ '{$field}' doit être un entier positif.");
                    return;
                }
                $assignment[$field] = (int) $assignment[$field];
            }

            $resolved = $this->model->resolveTrainerRoom($assignment);
            if (is_string($resolved)) {
                $this->sendError(400, $resolved);
                return;
            }
            $assignment['id_salle'] = $resolved[0];
            $assignment['id_formateur'] = $resolved[1];

            $validation = $this->model->validateAssignment($assignment, $id);
            if ($validation !== null) {
                $this->sendError(400, $validation);
                return;
            }
            $planning = new Planification();
            $db = Database::getInstance();
            $planning->acquireLock();
            $ownTx = !$db->inTransaction();
            if ($ownTx) {
                $db->beginTransaction();
            }
            try {
                $newPassage = $this->placeSlot($planning, $assignment, $id);
                $success = $this->model->update($id, $assignment);
                if ($newPassage) {
                    $planning->ensurePassage($assignment['id_groupe'], $assignment['jour_semaine'], $assignment['heure_debut'], $assignment['heure_fin']);
                }
                // Jour quitté : s'il n'y reste aucun créneau actif du groupe, ce passage disparaît.
                if ((int) $current['jour_semaine'] !== (int) $assignment['jour_semaine'] || (int) $current['id_groupe'] !== (int) $assignment['id_groupe']) {
                    $db->prepare(
                        'DELETE gp FROM groupe_passages gp
                         WHERE gp.id_groupe = :g AND gp.jour_semaine = :j
                           AND NOT EXISTS (SELECT 1 FROM emplois_du_temps e WHERE e.id_groupe = gp.id_groupe AND e.jour_semaine = gp.jour_semaine AND e.statut = 1)
                           AND EXISTS (SELECT 1 FROM emplois_du_temps e2 WHERE e2.id_groupe = gp.id_groupe AND e2.statut = 1)'
                    )->execute(['g' => (int) $current['id_groupe'], 'j' => (int) $current['jour_semaine']]);
                }
                if ($success) {
                    (new Formateur())->validateSalleAssignment($assignment['id_formateur'], $assignment['id_salle'], true);
                }
                if ($ownTx) {
                    $db->commit();
                }
            } catch (Throwable $error) {
                if ($ownTx && $db->inTransaction()) {
                    $db->rollBack();
                }
                throw $error;
            } finally {
                $planning->releaseLock();
            }

            if ($success) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Emploi du temps mis à jour.' . ($newPassage ? ' Ce jour a été ajouté aux passages hebdomadaires du groupe.' : '')]);
            } else {
                $this->sendError(400, "Aucune donnée valide.");
            }
        } catch (PlanningException $e) {
            $this->sendResponse(409, ['success' => false, 'error' => $e->getMessage(), 'details' => $e->details]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                $this->sendError(409, "Ce créneau entre en conflit avec une autre réservation de salle, de formateur ou de groupe.");
                return;
            }
            $this->sendError(500, "Erreur : " . $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function delete(int $id): void
    {
        try {
            $success = $this->model->delete($id);
            if ($success) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Emploi du temps supprimé.']);
            } else {
                $this->sendError(404, "Emploi du temps introuvable.");
            }
        } catch (Exception $e) {
            if ((string) $e->getCode() === '23000') {
                $this->sendError(409, "Cet emploi du temps est utilisé par des inscriptions ou des présences. Désactivez-le plutôt que de le supprimer.");
                return;
            }
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    public function checkDisponibilite(): void
    {
        try {
            $data = $this->getJsonInput();

            if (empty($data['id_annee']) || empty($data['jour_semaine'])) {
                $this->sendError(400, "Les champs 'id_annee' et 'jour_semaine' sont requis.");
                return;
            }

            $result = [
                'salle_libre' => null,
                'formateur_libre' => null
            ];

            if (!empty($data['id_salle'])) {
                $result['salle_libre'] = $this->model->isSalleLibre(
                    (int)$data['id_annee'],
                    (int)$data['id_salle'],
                    (int)$data['jour_semaine'],
                    isset($data['exclude_id']) ? (int)$data['exclude_id'] : null
                );
            }

            if (!empty($data['id_formateur'])) {
                $result['formateur_libre'] = $this->model->isFormateurLibre(
                    (int)$data['id_annee'],
                    (int)$data['id_formateur'],
                    (int)$data['jour_semaine'],
                    isset($data['exclude_id']) ? (int)$data['exclude_id'] : null
                );
            }

            $this->sendResponse(200, ['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            $this->sendError(500, "Erreur : " . $e->getMessage());
        }
    }

    /**
     * Complète un créneau manuel (session, horaire du passage) et vérifie disponibilités et règles :
     * jour de passage du groupe, salle dédiée à une autre formation, salle / formateur / groupe déjà pris
     * sur un horaire qui chevauche, pendant des dates de session qui se recoupent.
     * @return bool true si ce jour devient un nouveau passage du groupe
     * @throws PlanningException avec conflits et alternatives proposées
     */
    private function placeSlot(Planification $planning, array &$slot, ?int $excludeId): bool
    {
        $stmt = Database::getInstance()->prepare('SELECT id_session FROM groupes WHERE id = :id');
        $stmt->execute(['id' => (int) $slot['id_groupe']]);
        $slot['id_session'] = (int) $stmt->fetchColumn();

        $passage = $planning->passageFor((int) $slot['id_groupe'], (int) $slot['jour_semaine']);
        if (is_string($passage)) {
            throw new PlanningException($passage, ['alternatives' => $planning->alternatives((int) $slot['id_groupe'], $excludeId)]);
        }
        [$slot['heure_debut'], $slot['heure_fin'], $isNew] = $passage;

        if ((int) ($slot['statut'] ?? 1) === 1) {
            $conflicts = $planning->slotConflicts($slot, $excludeId);
            if ($conflicts !== []) {
                throw new PlanningException(
                    implode(' ', array_map(static fn($c) => $c['message'], $conflicts)),
                    ['conflits' => $conflicts, 'alternatives' => $planning->alternatives((int) $slot['id_groupe'], $excludeId, (int) $slot['jour_semaine'], (int) $slot['id_salle'])]
                );
            }
        }
        return $isNew;
    }

    /** Un formateur ne voit que ses propres créneaux (salles, groupes, auditeurs de ses affectations). */
    private function scopeForTrainer(array $rows): array
    {
        $user = Authorization::currentUser();
        if ($user === null || $user['role_code'] !== 'formateur') {
            return $rows;
        }
        $trainerId = (int) ($user['formateur_id'] ?? 0);
        return array_values(array_filter($rows, static fn($row) => (int) $row['id_formateur'] === $trainerId));
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
