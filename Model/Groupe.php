<?php
/**
 * Modèle Groupe (table : groupes)
 */

require_once __DIR__ . '/Model.php';

class Groupe extends Model
{
    protected string $table = 'groupes';

    /** Jours autorisés : 1 = lundi ... 5 = vendredi (chk_groupes_jour). */
    public const JOURS = [1, 2, 3, 4, 5];

    protected array $fillable = [
        'id_session', 'code', 'libelle', 'jour_semaine', 'heure_debut', 'heure_fin', 'capacite', 'statut',
    ];

    /** Groupes d'une session. */
    public function getBySession(int $id_session): array
    {
        return $this->fetchAll(
            "SELECT * FROM {$this->table} WHERE id_session = :id_session ORDER BY code",
            ['id_session' => $id_session]
        );
    }

    /** Groupes planifiés un jour donné (1 = lundi ... 5 = vendredi). */
    public function getByJour(int $jour_semaine): array
    {
        return $this->fetchAll(
            "SELECT * FROM {$this->table} WHERE jour_semaine = :jour AND statut = 1 ORDER BY code",
            ['jour' => $jour_semaine]
        );
    }

    /** Groupe avec le détail de sa session. */
    public function getWithSession(int $id): ?array
    {
        return $this->fetchOne(
            "SELECT g.*,
                    s.code AS session_code, s.libelle AS session_libelle,
                    s.date_debut AS session_date_debut, s.date_fin AS session_date_fin,
                    s.statut AS session_statut
             FROM {$this->table} g
             JOIN sessions s ON s.id = g.id_session
             WHERE g.id = :id
             LIMIT 1",
            ['id' => $id]
        );
    }

    /**
     * Salles utilisées par le groupe (un créneau par salle, le jour du groupe),
     * avec le formateur attitré, l'effectif actif et la liste des auditeurs.
     */
    public function getSalles(int $id): array
    {
        $rooms = $this->fetchAll(
            "SELECT e.id AS id_emploi_du_temps, e.id_annee, e.jour_semaine, e.statut,
                    sa.id AS id_salle, sa.code AS salle_code, sa.libelle AS salle_libelle,
                    sa.capacite AS salle_capacite, sa.statut AS salle_statut,
                    f.id AS id_formateur, f.matricule AS formateur_matricule,
                    f.nom AS formateur_nom, f.prenom AS formateur_prenom,
                    (SELECT COUNT(*) FROM inscriptions i
                      WHERE i.id_emploi_du_temps = e.id AND i.statut = 'active') AS inscrits
             FROM emplois_du_temps e
             JOIN salles sa ON sa.id = e.id_salle
             JOIN formateurs f ON f.id = e.id_formateur
             WHERE e.id_groupe = :id
             ORDER BY sa.code",
            ['id' => $id]
        );

        foreach ($rooms as &$room) {
            $room['inscrits'] = (int) $room['inscrits'];
            $room['places_restantes'] = $room['salle_capacite'] === null
                ? null
                : max(0, (int) $room['salle_capacite'] - $room['inscrits']);
            $room['auditeurs'] = $this->fetchAll(
                "SELECT i.id AS id_inscription, a.id AS id_auditeur, a.matricule, a.nom, a.prenom,
                        a.position, t.libelle AS type_employe_libelle
                 FROM inscriptions i
                 JOIN auditeurs a ON a.id = i.id_auditeur
                 LEFT JOIN types_employes t ON t.id = a.id_type_employe
                 WHERE i.id_emploi_du_temps = :edt AND i.statut = 'active'
                 ORDER BY a.nom, a.prenom",
                ['edt' => (int) $room['id_emploi_du_temps']]
            );
        }
        unset($room);

        return $rooms;
    }

    /**
     * Met à jour un groupe et, si son jour de cours change, déplace automatiquement
     * tous ses créneaux (salles) sur le nouveau jour.
     * @throws DomainException si une salle ou un formateur est déjà occupé le nouveau jour.
     */
    public function updateWithSchedules(int $id, array $data): bool
    {
        $current = $this->find($id);
        if ($current === null) {
            return false;
        }
        $newDay = isset($data['jour_semaine']) ? (int) $data['jour_semaine'] : (int) $current['jour_semaine'];

        $this->db->beginTransaction();
        try {
            $success = $this->update($id, $data);
            if ($newDay !== (int) $current['jour_semaine']) {
                $stmt = $this->db->prepare(
                    'UPDATE emplois_du_temps SET jour_semaine = :jour WHERE id_groupe = :id'
                );
                $stmt->execute(['jour' => $newDay, 'id' => $id]);
            }
            $this->db->commit();
            return $success;
        } catch (PDOException $e) {
            $this->db->rollBack();
            if ((string) $e->getCode() === '23000' && strpos($e->getMessage(), 'uk_edt_') !== false) {
                throw new DomainException(
                    'Impossible de changer le jour : une salle ou un formateur de ce groupe est déjà occupé ce jour-là.'
                );
            }
            throw $e;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Places restantes = minimum entre la capacité du groupe et la somme
     * des places encore libres dans les salles qui lui sont affectées.
     * Retourne null si aucune capacité n'est définie.
     */
    public function getPlacesRestantes(int $id): ?int
    {
        $row = $this->fetchOne(
            "SELECT g.capacite,
                    (SELECT COUNT(*) FROM inscriptions i
                      JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
                      WHERE e.id_groupe = g.id AND i.statut = 'active') AS inscrits
             FROM {$this->table} g
             WHERE g.id = :id",
            ['id' => $id]
        );
        if ($row === null) {
            return null;
        }

        $roomSeats = $this->fetchOne(
            "SELECT
                SUM(CASE WHEN sa.capacite IS NULL THEN NULL ELSE sa.capacite END) AS capacite_salles,
                SUM(CASE WHEN sa.capacite IS NULL THEN NULL ELSE
                    GREATEST(sa.capacite - (
                        SELECT COUNT(*) FROM inscriptions i
                        WHERE i.id_emploi_du_temps = e.id AND i.statut = 'active'
                    ), 0)
                END) AS places_salles,
                COUNT(e.id) AS salles_affectees
             FROM emplois_du_temps e
             JOIN salles sa ON sa.id = e.id_salle
             WHERE e.id_groupe = :id AND e.statut = 1",
            ['id' => $id]
        );

        $groupRemaining = $row['capacite'] === null ? null : (int) $row['capacite'] - (int) $row['inscrits'];
        $roomRemaining = ($roomSeats && $roomSeats['places_salles'] !== null)
            ? (int) $roomSeats['places_salles']
            : null;

        if ($groupRemaining === null && $roomRemaining === null) {
            return null;
        }
        if ($groupRemaining === null) {
            return $roomRemaining;
        }
        if ($roomRemaining === null) {
            return $groupRemaining;
        }
        return min($groupRemaining, $roomRemaining);
    }
}
