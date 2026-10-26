<?php
/**
 * Modèle Inscription (table : inscriptions)
 *
 * Une inscription lie un auditeur à une session, un groupe et une salle :
 * id_emploi_du_temps désigne le créneau « groupe × salle × jour » choisi.
 * id_annee et id_session sont renseignés automatiquement à partir de ce créneau.
 */

require_once __DIR__ . '/Model.php';

class Inscription extends Model
{
    protected string $table = 'inscriptions';

    public const STATUTS = ['active', 'terminee', 'abandonnee', 'annulee'];

    /** Statuts de session acceptant des inscriptions. */
    private const SESSIONS_OUVERTES = ['ouverte', 'en_cours'];

    protected array $fillable = [
        'id_auditeur', 'id_annee', 'id_session', 'id_emploi_du_temps',
        'id_departement', 'date_inscription', 'statut',
    ];

    /** Colonnes communes aux listes détaillées. */
    private const DETAIL_COLUMNS = "i.*,
                    a.matricule AS auditeur_matricule, a.nom AS auditeur_nom, a.prenom AS auditeur_prenom,
                    a.sexe AS auditeur_sexe, a.telephone AS auditeur_telephone, a.email AS auditeur_email,
                    po.libelle AS auditeur_position, te.libelle AS type_employe_libelle,
                    d.code AS departement_code, d.libelle AS departement_libelle,
                    e.id_groupe, e.id_salle, e.id_formateur, e.jour_semaine,
                    g.code AS groupe_code, g.libelle AS groupe_libelle, g.capacite AS groupe_capacite,
                    s.code AS session_code, s.libelle AS session_libelle, s.statut AS session_statut,
                    an.libelle AS annee_libelle,
                    sa.code AS salle_code, sa.libelle AS salle_libelle,
                    f.matricule AS formateur_matricule, f.nom AS formateur_nom, f.prenom AS formateur_prenom";

    private const DETAIL_JOINS = "FROM inscriptions i
             JOIN auditeurs a ON a.id = i.id_auditeur
             LEFT JOIN types_employes te ON te.id = a.id_type_employe LEFT JOIN positions po ON po.id = a.id_position
             JOIN departements d ON d.id = i.id_departement
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = i.id_session
             JOIN annees_scolaires an ON an.id = i.id_annee
             JOIN salles sa ON sa.id = e.id_salle
             JOIN v_formateurs f ON f.id = e.id_formateur";

    /** Liste enrichie destinée au registre d'inscriptions (filtrable par année / session). */
    public function getAllWithDetails(?int $idAnnee = null, ?int $idSession = null): array
    {
        $where = [];
        $params = [];
        if ($idAnnee !== null) {
            $where[] = 'i.id_annee = :id_annee';
            $params['id_annee'] = $idAnnee;
        }
        if ($idSession !== null) {
            $where[] = 'i.id_session = :id_session';
            $params['id_session'] = $idSession;
        }

        return $this->fetchAll(
            'SELECT ' . self::DETAIL_COLUMNS . ' ' . self::DETAIL_JOINS
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY i.date_inscription DESC, a.nom, a.prenom',
            $params
        );
    }

    /* ------------------------------------------------------------------
     | Recherches filtrées
     |------------------------------------------------------------------ */

    /** Inscriptions d'un auditeur (avec groupe et session). */
    public function getByAuditeur(int $id_auditeur): array
    {
        return $this->fetchAll(
            'SELECT ' . self::DETAIL_COLUMNS . ' ' . self::DETAIL_JOINS
            . ' WHERE i.id_auditeur = :id_auditeur ORDER BY i.date_inscription DESC',
            ['id_auditeur' => $id_auditeur]
        );
    }

    /** Inscriptions d'un groupe (via l'emploi du temps). */
    public function getByGroupe(int $id_groupe): array
    {
        return $this->fetchAll(
            'SELECT ' . self::DETAIL_COLUMNS . ' ' . self::DETAIL_JOINS
            . ' WHERE e.id_groupe = :id_groupe ORDER BY sa.code, a.nom, a.prenom',
            ['id_groupe' => $id_groupe]
        );
    }

    /** Inscriptions d'un département. */
    public function getByDepartement(int $id_departement): array
    {
        return $this->fetchAll(
            'SELECT ' . self::DETAIL_COLUMNS . ' ' . self::DETAIL_JOINS
            . ' WHERE i.id_departement = :id_departement ORDER BY a.nom, a.prenom',
            ['id_departement' => $id_departement]
        );
    }

    /** Inscriptions rattachées à un emploi du temps précis. */
    public function getByEmploiDuTemps(int $id_edt): array
    {
        return $this->fetchAll(
            'SELECT ' . self::DETAIL_COLUMNS . ' ' . self::DETAIL_JOINS
            . ' WHERE i.id_emploi_du_temps = :id_edt ORDER BY a.nom, a.prenom',
            ['id_edt' => $id_edt]
        );
    }

    /** Inscription complète : auditeur + groupe + session + salle + formateur. */
    public function getWithDetails(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT ' . self::DETAIL_COLUMNS . ', g.jour_semaine AS groupe_jour '
            . self::DETAIL_JOINS . ' WHERE i.id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * Change le statut d'une inscription.
     * Réactiver une inscription vérifie qu'aucune autre n'est active dans la même session.
     * @throws InvalidArgumentException si le statut est invalide.
     * @throws DomainException si l'auditeur est déjà actif dans la session.
     */
    public function changeStatut(int $id, string $statut): bool
    {
        $this->assertIn($statut, self::STATUTS, 'statut');

        if ($statut === 'active') {
            $current = $this->find($id);
            if ($current !== null) {
                $this->assertSingleActiveInSession((int) $current['id_auditeur'], (int) $current['id_session'], $id);
            }
        }

        $stmt = $this->db->prepare(
            "UPDATE {$this->table} SET statut = :statut WHERE id = :id"
        );
        return $stmt->execute(['statut' => $statut, 'id' => $id]);
    }

    /**
     * Vérifie si un auditeur possède déjà une inscription ACTIVE dans un groupe.
     */
    public function isAlreadyInscrit(int $id_auditeur, int $id_groupe): bool
    {
        return $this->fetchOne(
            "SELECT 1 AS ok
             FROM {$this->table} i
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             WHERE i.id_auditeur = :id_auditeur
               AND e.id_groupe = :id_groupe
               AND i.statut = 'active'
             LIMIT 1",
            ['id_auditeur' => $id_auditeur, 'id_groupe' => $id_groupe]
        ) !== null;
    }

    /**
     * Inscription validée et atomique.
     * - id_emploi_du_temps fourni : la salle choisie est imposée (refus si elle est pleine) ;
     * - seul id_groupe fourni : la première salle du groupe ayant de la place est attribuée.
     * L'année et la session sont déduites automatiquement du créneau.
     *
     * @return array{id:int,id_emploi_du_temps:int,id_salle:int,salle_code:?string,salle_libelle:?string,auto_repartition:bool}
     * @throws DomainException lorsque la règle métier empêche l'inscription.
     */
    public function createValidated(array $data): array
    {
        $idAuditeur = (int) ($data['id_auditeur'] ?? 0);
        $preferredEdt = (int) ($data['id_emploi_du_temps'] ?? 0);
        $idGroupe = (int) ($data['id_groupe'] ?? 0);
        $idSessionDemandee = (int) ($data['id_session'] ?? 0);
        unset($data['id_groupe']);

        $this->db->beginTransaction();
        try {
            if ($preferredEdt > 0) {
                $idGroupe = (int) $this->fetchColumn(
                    'SELECT id_groupe FROM emplois_du_temps WHERE id = :id',
                    ['id' => $preferredEdt]
                );
                if ($idGroupe <= 0) {
                    throw new DomainException('La salle sélectionnée n’est pas affectée à ce groupe.');
                }
            } elseif ($idGroupe <= 0) {
                throw new DomainException('Indiquez un groupe et une salle pour inscrire l’auditeur.');
            }

            $group = $this->lockGroup($idGroupe);
            if ($idSessionDemandee > 0 && $idSessionDemandee !== (int) $group['id_session']) {
                throw new DomainException('Le groupe choisi n’appartient pas à la session sélectionnée.');
            }

            $this->assertSingleActiveInSession($idAuditeur, (int) $group['id_session']);
            $this->assertGroupCapacity($group);

            $slots = $this->lockGroupRooms($idGroupe);
            if ($preferredEdt > 0) {
                $chosen = $this->findSlot($slots, $preferredEdt);
                if ($chosen === null) {
                    throw new DomainException('La salle sélectionnée n’est pas active pour ce groupe.');
                }
                if (!$this->hasSpace($chosen)) {
                    throw new DomainException(sprintf(
                        'La salle %s est pleine (%d/%d). Choisissez une autre salle du groupe.',
                        $chosen['salle_code'], $chosen['inscrits'], $chosen['salle_capacite']
                    ));
                }
            } else {
                $chosen = null;
                foreach ($slots as $slot) {
                    if ($this->hasSpace($slot)) {
                        $chosen = $slot;
                        break;
                    }
                }
                if ($chosen === null) {
                    throw new DomainException('Toutes les salles de ce groupe sont pleines. Ajoutez une autre salle au groupe.');
                }
            }

            $data['id_emploi_du_temps'] = (int) $chosen['id'];
            $data['id_annee'] = (int) $chosen['id_annee'];
            $data['id_session'] = (int) $group['id_session'];
            $id = $this->create($data);
            $this->db->commit();

            return [
                'id' => $id,
                'id_emploi_du_temps' => (int) $chosen['id'],
                'id_salle' => (int) $chosen['id_salle'],
                'salle_code' => $chosen['salle_code'],
                'salle_libelle' => $chosen['salle_libelle'],
                'auto_repartition' => $preferredEdt <= 0,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Modification validée d'une inscription : salle / groupe (créneau), département,
     * date et statut. L'année et la session suivent automatiquement le créneau.
     * @throws DomainException lorsque la règle métier empêche la modification.
     */
    public function updateValidated(int $id, array $data): bool
    {
        $this->db->beginTransaction();
        try {
            $current = $this->fetchOne(
                "SELECT * FROM {$this->table} WHERE id = :id FOR UPDATE",
                ['id' => $id]
            );
            if ($current === null) {
                throw new DomainException('Inscription introuvable.');
            }

            $update = array_intersect_key($data, array_flip(['id_departement', 'date_inscription', 'statut']));
            $statut = $update['statut'] ?? $current['statut'];
            $idSession = (int) $current['id_session'];
            $newEdt = isset($data['id_emploi_du_temps']) ? (int) $data['id_emploi_du_temps'] : (int) $current['id_emploi_du_temps'];

            if ($newEdt !== (int) $current['id_emploi_du_temps']) {
                $idGroupe = (int) $this->fetchColumn(
                    'SELECT id_groupe FROM emplois_du_temps WHERE id = :id',
                    ['id' => $newEdt]
                );
                if ($idGroupe <= 0) {
                    throw new DomainException('La salle sélectionnée est introuvable.');
                }
                $group = $this->lockGroup($idGroupe);
                $idSession = (int) $group['id_session'];

                $currentGroup = (int) $this->fetchColumn(
                    'SELECT id_groupe FROM emplois_du_temps WHERE id = :id',
                    ['id' => (int) $current['id_emploi_du_temps']]
                );
                if ($currentGroup !== $idGroupe && $statut === 'active') {
                    $this->assertGroupCapacity($group, $id);
                }

                $chosen = $this->findSlot($this->lockGroupRooms($idGroupe, $id), $newEdt);
                if ($chosen === null) {
                    throw new DomainException('La salle sélectionnée n’est pas active pour ce groupe.');
                }
                if ($statut === 'active' && !$this->hasSpace($chosen)) {
                    throw new DomainException(sprintf(
                        'La salle %s est pleine (%d/%d).',
                        $chosen['salle_code'], $chosen['inscrits'], $chosen['salle_capacite']
                    ));
                }
                $update['id_emploi_du_temps'] = (int) $chosen['id'];
                $update['id_annee'] = (int) $chosen['id_annee'];
                $update['id_session'] = $idSession;
            }

            if ($statut === 'active') {
                $this->assertSingleActiveInSession((int) $current['id_auditeur'], $idSession, $id);
            }

            $success = $update === [] ? true : $this->update($id, $update);
            $this->db->commit();
            return $success;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /* ------------------------------------------------------------------
     | Règles métier internes
     |------------------------------------------------------------------ */

    /** Verrouille un groupe et vérifie qu'il peut recevoir des inscriptions. */
    private function lockGroup(int $idGroupe): array
    {
        $group = $this->fetchOne(
            "SELECT g.id, g.id_session, g.capacite, g.statut AS groupe_actif,
                    s.statut AS session_statut
             FROM groupes g
             JOIN sessions s ON s.id = g.id_session
             WHERE g.id = :id_groupe
             FOR UPDATE",
            ['id_groupe' => $idGroupe]
        );
        if ($group === null) {
            throw new DomainException('Le groupe sélectionné est introuvable.');
        }
        if (!(bool) $group['groupe_actif']) {
            throw new DomainException('Un groupe inactif ne peut pas recevoir d’inscription.');
        }
        if (!in_array($group['session_statut'], self::SESSIONS_OUVERTES, true)) {
            throw new DomainException('La session de ce groupe n’est pas ouverte aux inscriptions.');
        }
        return $group;
    }

    /**
     * Salles actives du groupe (une « classe » par salle), avec leur effectif actif (hors inscription exclue).
     * Un groupe qui vient plusieurs jours a un créneau par jour et par salle : l'inscription est rattachée
     * au créneau du premier jour de passage, l'auditeur suit sa classe tous les jours de passage.
     */
    private function lockGroupRooms(int $idGroupe, ?int $excludeInscription = null): array
    {
        $rows = $this->fetchAll(
            "SELECT e.id, e.id_annee, e.jour_semaine, sa.id AS id_salle, sa.code AS salle_code, sa.libelle AS salle_libelle,
                    sa.capacite AS salle_capacite
             FROM emplois_du_temps e
             JOIN salles sa ON sa.id = e.id_salle
             WHERE e.id_groupe = :id_groupe AND e.statut = 1
             ORDER BY sa.code, e.jour_semaine, e.id
             FOR UPDATE",
            ['id_groupe' => $idGroupe]
        );
        if ($rows === []) {
            throw new DomainException('Ce groupe n’a aucune salle affectée. Affectez d’abord des salles au groupe.');
        }
        $slots = [];
        foreach ($rows as $row) {
            $roomId = (int) $row['id_salle'];
            if (!isset($slots[$roomId])) {
                $slots[$roomId] = $row + ['ids' => []];
            }
            $slots[$roomId]['ids'][] = (int) $row['id'];
        }
        foreach ($slots as &$slot) {
            $slot['inscrits'] = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM {$this->table} i
                 JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
                 WHERE e.id_groupe = :groupe AND e.id_salle = :salle AND i.statut = 'active' AND i.id <> :exclude",
                ['groupe' => $idGroupe, 'salle' => (int) $slot['id_salle'], 'exclude' => (int) $excludeInscription]
            );
        }
        unset($slot);
        return array_values($slots);
    }

    /** Classe (salle) du groupe contenant ce créneau, quel que soit le jour de passage choisi. */
    private function findSlot(array $slots, int $idEdt): ?array
    {
        foreach ($slots as $slot) {
            if (in_array($idEdt, $slot['ids'], true)) {
                return $slot;
            }
        }
        return null;
    }

    private function hasSpace(array $slot): bool
    {
        return $slot['salle_capacite'] === null || (int) $slot['inscrits'] < (int) $slot['salle_capacite'];
    }

    private function assertGroupCapacity(array $group, ?int $excludeInscription = null): void
    {
        if ($group['capacite'] === null) {
            return;
        }
        $count = (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM {$this->table} i
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             WHERE e.id_groupe = :id_groupe AND i.statut = 'active' AND i.id <> :exclude",
            ['id_groupe' => (int) $group['id'], 'exclude' => (int) $excludeInscription]
        );
        if ($count >= (int) $group['capacite']) {
            throw new DomainException('La capacité maximale de ce groupe est atteinte.');
        }
    }

    /** Un auditeur ne peut avoir qu'une inscription active par session. */
    private function assertSingleActiveInSession(int $idAuditeur, int $idSession, ?int $excludeInscription = null): void
    {
        $existing = $this->fetchOne(
            "SELECT g.code AS groupe_code, g.libelle AS groupe_libelle
             FROM {$this->table} i
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN groupes g ON g.id = e.id_groupe
             WHERE i.id_auditeur = :id_auditeur AND i.id_session = :id_session
               AND i.statut = 'active' AND i.id <> :exclude
             LIMIT 1",
            ['id_auditeur' => $idAuditeur, 'id_session' => $idSession, 'exclude' => (int) $excludeInscription]
        );
        if ($existing !== null) {
            throw new DomainException(sprintf(
                'Cet auditeur est déjà inscrit dans cette session (groupe %s · %s).',
                $existing['groupe_code'], $existing['groupe_libelle']
            ));
        }
    }
}
