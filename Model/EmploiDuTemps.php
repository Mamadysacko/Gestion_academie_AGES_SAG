<?php
/**
 * Modèle EmploiDuTemps (table centrale : emplois_du_temps)
 *
 * Un créneau = un groupe dans une salle un jour donné.
 * Un formateur appartient à une seule salle et y vient tous les jours ;
 * les auditeurs du groupe peuvent être répartis dans plusieurs salles.
 */

require_once __DIR__ . '/Model.php';

class EmploiDuTemps extends Model
{
    protected string $table = 'emplois_du_temps';

    /** Jours autorisés : 1 = lundi ... 5 = vendredi (chk_edt_jour). */
    public const JOURS = [1, 2, 3, 4, 5];

    protected array $fillable = [
        'id_annee', 'id_groupe', 'id_salle', 'id_formateur', 'jour_semaine', 'statut',
    ];

    /* ------------------------------------------------------------------
     | Recherches filtrées
     |------------------------------------------------------------------ */

    public function getByGroupe(int $id_groupe): array
    {
        return $this->getPlanning(['e.id_groupe' => $id_groupe]);
    }

    public function getBySalle(int $id_salle): array
    {
        return $this->getPlanning(['e.id_salle' => $id_salle]);
    }

    public function getByFormateur(int $id_formateur): array
    {
        return $this->getPlanning(['e.id_formateur' => $id_formateur]);
    }

    public function getByJour(int $jour_semaine): array
    {
        return $this->getPlanning(['e.jour_semaine' => $jour_semaine]);
    }

    public function getByAnnee(int $id_annee): array
    {
        return $this->getPlanning(['e.id_annee' => $id_annee]);
    }

    /* ------------------------------------------------------------------
     | Disponibilités
     |------------------------------------------------------------------ */

    /**
     * Vérifie qu'une salle est libre un jour donné pour une année.
     * @param int|null $excludeId ID d'un EDT à exclure (utile en mise à jour).
     */
    public function isSalleLibre(int $id_annee, int $id_salle, int $jour, ?int $excludeId = null): bool
    {
        return !$this->creneauExiste('id_salle', $id_salle, $id_annee, $jour, $excludeId);
    }

    /**
     * Vérifie qu'un formateur est libre un jour donné pour une année.
     * @param int|null $excludeId ID d'un EDT à exclure (utile en mise à jour).
     */
    public function isFormateurLibre(int $id_annee, int $id_formateur, int $jour, ?int $excludeId = null): bool
    {
        return !$this->creneauExiste('id_formateur', $id_formateur, $id_annee, $jour, $excludeId);
    }

    /**
     * Aligne formateur et salle : un formateur = une salle, tous les jours.
     * Si le formateur n'a pas encore de salle, le rattachement est enregistré.
     * @return array{0:int,1:int}|string Paire [id_salle, id_formateur] ou message d'erreur.
     */
    public function resolveTrainerRoom(array $assignment): array|string
    {
        require_once __DIR__ . '/Formateur.php';
        $formateurModel = new Formateur();

        $idSalle = (int) $assignment['id_salle'];
        $idFormateur = (int) ($assignment['id_formateur'] ?? 0);

        $roomTrainer = $formateurModel->findBySalle($idSalle);
        if ($roomTrainer !== null) {
            if ($idFormateur > 0 && $idFormateur !== (int) $roomTrainer['id']) {
                return 'Cette salle a déjà un formateur attitré. Il y enseigne tous les jours de la semaine.';
            }
            $idFormateur = (int) $roomTrainer['id'];
        } elseif ($idFormateur <= 0) {
            return 'Attribuez d’abord un formateur à cette salle. Un formateur reste dans une seule salle.';
        }

        $error = $formateurModel->validateSalleAssignment($idFormateur, $idSalle, false);
        if ($error !== null) {
            return $error;
        }

        return [$idSalle, $idFormateur];
    }

    public function validateAssignment(array $assignment, ?int $excludeId = null): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT g.jour_semaine, g.statut AS groupe_actif,
                    s.statut AS session_statut,
                    f.actif AS formateur_actif, f.id_salle AS formateur_salle,
                    sa.statut AS salle_statut, sa.capacite AS salle_capacite
             FROM groupes g
             JOIN sessions s ON s.id = g.id_session
             JOIN formateurs f ON f.id = :formateur_id
             JOIN salles sa ON sa.id = :salle_id
             WHERE g.id = :groupe_id AND s.id_annee = :annee_id
             LIMIT 1"
        );
        $stmt->execute([
            'formateur_id' => (int) $assignment['id_formateur'],
            'salle_id' => (int) $assignment['id_salle'],
            'groupe_id' => (int) $assignment['id_groupe'],
            'annee_id' => (int) $assignment['id_annee'],
        ]);
        $references = $stmt->fetch();
        if (!$references) {
            return 'Le groupe, le formateur ou la salle sélectionné(e) n’existe pas.';
        }
        $isActive = !isset($assignment['statut']) || (int) $assignment['statut'] === 1;
        if ($isActive && !(bool) $references['groupe_actif']) {
            return 'Un groupe inactif ne peut pas recevoir de nouveau créneau.';
        }
        if ($isActive && !in_array($references['session_statut'], ['planifiee', 'ouverte', 'en_cours'], true)) {
            return 'Un créneau actif ne peut être associé qu’à une session planifiée, ouverte ou en cours.';
        }
        if ($isActive && !(bool) $references['formateur_actif']) {
            return 'Un formateur inactif ne peut pas recevoir de nouveau créneau.';
        }
        if ($isActive && $references['salle_statut'] !== 'disponible') {
            return 'Une salle indisponible ne peut pas recevoir de nouveau créneau.';
        }
        if ((int) $references['jour_semaine'] !== (int) $assignment['jour_semaine']) {
            return 'Le jour du créneau doit correspondre au jour hebdomadaire du groupe.';
        }
        if ($references['formateur_salle'] !== null && (int) $references['formateur_salle'] !== (int) $assignment['id_salle']) {
            return 'Ce formateur appartient déjà à une autre salle et y reste toute la semaine.';
        }

        if ($excludeId !== null) {
            $slotCount = $this->fetchColumn(
                "SELECT COUNT(*) FROM inscriptions
                 WHERE id_emploi_du_temps = :id AND statut = 'active'",
                ['id' => $excludeId]
            );
            if ($references['salle_capacite'] !== null && (int) $slotCount > (int) $references['salle_capacite']) {
                return 'La capacité de la salle est inférieure au nombre d’auditeurs déjà inscrits dans cette classe.';
            }
        }

        if (!$isActive) {
            return null;
        }

        $sql = "SELECT 1 FROM {$this->table}
                WHERE id_annee = :id_annee AND id_groupe = :id_groupe
                  AND id_salle = :id_salle AND jour_semaine = :jour AND statut = 1";
        $params = [
            'id_annee' => (int) $assignment['id_annee'],
            'id_groupe' => (int) $assignment['id_groupe'],
            'id_salle' => (int) $assignment['id_salle'],
            'jour' => (int) $assignment['jour_semaine'],
        ];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetchColumn()) {
            return 'Ce groupe est déjà affecté à cette salle ce jour-là.';
        }

        return null;
    }

    /**
     * Planning complet (jointures groupe, session, salle, formateur),
     * optionnellement filtré par année scolaire.
     */
    public function getPlanningComplet(?int $id_annee = null): array
    {
        $filters = [];
        if ($id_annee !== null) {
            $filters['e.id_annee'] = $id_annee;
        }
        return $this->getPlanning($filters);
    }

    public function getPlanningById(int $id): ?array
    {
        $rows = $this->getPlanning(['e.id' => $id]);
        return $rows[0] ?? null;
    }

    /* ------------------------------------------------------------------
     | Interne
     |------------------------------------------------------------------ */

    /** Planning enrichi avec filtres dynamiques sur les colonnes préfixées "e.". */
    private function getPlanning(array $filters = []): array
    {
        $sql = "SELECT e.*,
                       a.libelle AS annee_libelle,
                       g.code AS groupe_code, g.libelle AS groupe_libelle,
                       g.capacite AS groupe_capacite, g.statut AS groupe_actif,
                       g.jour_semaine AS groupe_jour_semaine, g.heure_debut, g.heure_fin,
                       sa.code AS salle_code, sa.libelle AS salle_libelle,
                       sa.capacite AS salle_capacite, sa.statut AS salle_statut,
                       f.matricule AS formateur_matricule, f.nom AS formateur_nom, f.prenom AS formateur_prenom,
                       f.actif AS formateur_actif,
                       s.id AS id_session, s.code AS session_code, s.libelle AS session_libelle,
                       s.statut AS session_statut, s.date_debut AS session_date_debut, s.date_fin AS session_date_fin,
                       a.date_debut AS annee_date_debut, a.date_fin AS annee_date_fin,
                       (SELECT COUNT(DISTINCT i.id) FROM inscriptions i
                        WHERE i.id_emploi_du_temps = e.id AND i.statut = 'active') AS inscrits_actifs
                FROM {$this->table} e
                JOIN groupes g    ON g.id  = e.id_groupe
                JOIN salles sa    ON sa.id = e.id_salle
                JOIN formateurs f ON f.id  = e.id_formateur
                JOIN sessions s   ON s.id  = g.id_session AND s.id_annee = e.id_annee
                JOIN annees_scolaires a ON a.id = e.id_annee";

        $params = [];
        if (!empty($filters)) {
            $where = [];
            foreach ($filters as $column => $value) {
                $placeholder = str_replace('.', '_', $column);
                $where[] = "{$column} = :{$placeholder}";
                $params[$placeholder] = $value;
            }
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= " ORDER BY e.jour_semaine, g.code";

        return $this->fetchAll($sql, $params);
    }

    /** Vérifie l'existence d'un créneau actif sur une colonne donnée (salle/formateur). */
    private function creneauExiste(string $column, int $value, int $id_annee, int $jour, ?int $excludeId): bool
    {
        $sql = "SELECT 1 AS ok FROM {$this->table}
                WHERE id_annee = :id_annee AND {$column} = :valeur AND jour_semaine = :jour";
        $params = ['id_annee' => $id_annee, 'valeur' => $value, 'jour' => $jour];

        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params['exclude_id'] = $excludeId;
        }
        $sql .= " LIMIT 1";

        return $this->fetchOne($sql, $params) !== null;
    }
}
