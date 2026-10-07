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
        'id_session', 'code', 'libelle', 'jour_semaine', 'capacite', 'statut',
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
     * Nombre de places restantes dans un groupe (capacité - inscriptions actives).
     * Retourne null si le groupe n'a pas de capacité définie ou n'existe pas.
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

        if ($row === null || $row['capacite'] === null) {
            return null;
        }
        return (int) $row['capacite'] - (int) $row['inscrits'];
    }
}
