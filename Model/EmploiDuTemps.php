<?php
/**
 * Modèle EmploiDuTemps (table centrale : emplois_du_temps)
 *
 * Un emploi du temps = un créneau hebdomadaire (année + groupe + salle +
 * formateur + jour). Les contraintes uniques garantissent qu'une salle ou
 * un formateur ne peut pas être doublement réservé le même jour.
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

    public function validateAssignment(array $assignment, ?int $excludeId = null): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT g.jour_semaine, g.statut AS groupe_actif,
                    s.statut AS session_statut,
                    f.actif AS formateur_actif, sa.statut AS salle_statut,
                    sa.capacite AS salle_capacite, g.capacite AS groupe_capacite,
                    (SELECT COUNT(DISTINCT i.id) FROM inscriptions i
                     JOIN emplois_du_temps enrollment_edt ON enrollment_edt.id = i.id_emploi_du_temps
                     WHERE enrollment_edt.id_groupe = g.id
                       AND enrollment_edt.id_annee = s.id_annee
                       AND i.statut = 'active') AS inscrits
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
        if ($isActive && $references['groupe_capacite'] !== null && (int) $references['inscrits'] > (int) $references['groupe_capacite']) {
            return 'Le nombre d’inscriptions actives dépasse la capacité maximale du groupe.';
        }
        if ($isActive && $references['salle_capacite'] !== null && (int) $references['inscrits'] > (int) $references['salle_capacite']) {
            return 'La capacité de la salle est inférieure au nombre d’inscriptions actives.';
        }

        if (!$isActive) {
            return null;
        }

        $sql = "SELECT 1 FROM {$this->table}
                WHERE id_annee = :id_annee AND id_groupe = :id_groupe
                  AND jour_semaine = :jour AND statut = 1";
        $params = [
            'id_annee' => (int) $assignment['id_annee'],
            'id_groupe' => (int) $assignment['id_groupe'],
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
            return 'Ce groupe possède déjà un créneau actif ce jour-là pour cette année.';
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
                       sa.code AS salle_code, sa.libelle AS salle_libelle,
                       sa.capacite AS salle_capacite, sa.statut AS salle_statut,
                       f.matricule AS formateur_matricule, f.nom AS formateur_nom, f.prenom AS formateur_prenom,
                       f.actif AS formateur_actif,
                       s.id AS id_session, s.code AS session_code, s.libelle AS session_libelle,
                       s.statut AS session_statut,
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
