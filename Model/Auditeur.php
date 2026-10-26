<?php
/**
 * Modèle Auditeur (table : auditeurs)
 */

require_once __DIR__ . '/Model.php';

class Auditeur extends Model
{
    protected string $table = 'auditeurs';

    public const SEXES = ['M', 'F'];

    protected array $fillable = [
        'matricule', 'nom', 'prenom', 'sexe', 'telephone', 'email',
        'id_position', 'id_type_employe', 'id_niveau',
    ];

    /** Liste des auditeurs avec le libellé de leur type d'employé. */
    public function getAllWithType(): array
    {
        return $this->fetchAll(
            "SELECT a.*, po.libelle AS position, t.libelle AS type_employe_libelle, nv.libelle AS niveau_libelle
             FROM {$this->table} a
             LEFT JOIN types_employes t ON t.id = a.id_type_employe
             LEFT JOIN positions po ON po.id = a.id_position
             LEFT JOIN niveaux nv ON nv.id = a.id_niveau
             ORDER BY a.nom, a.prenom"
        );
    }

    /** Auditeur avec le libellé de son type d'employé. */
    public function findWithType(int $id): ?array
    {
        return $this->fetchOne(
            "SELECT a.*, po.libelle AS position, t.libelle AS type_employe_libelle, nv.libelle AS niveau_libelle
             FROM {$this->table} a
             LEFT JOIN types_employes t ON t.id = a.id_type_employe
             LEFT JOIN positions po ON po.id = a.id_position
             LEFT JOIN niveaux nv ON nv.id = a.id_niveau
             WHERE a.id = :id
             LIMIT 1",
            ['id' => $id]
        );
    }

    /** Historique complet des présences d'un auditeur, avec synthèse globale. */
    public function getPresenceHistory(int $id): array
    {
        $rows = $this->fetchAll(
            "SELECT p.id, p.id_inscription, p.date_presence, p.statut, p.observation,
                    p.conge_date_debut, p.conge_date_fin,
                    s.code AS session_code, s.libelle AS session_libelle,
                    g.code AS groupe_code, sa.code AS salle_code,
                    f.prenom AS formateur_prenom, f.nom AS formateur_nom
             FROM presences p
             JOIN inscriptions i ON i.id = p.id_inscription
             JOIN sessions s ON s.id = i.id_session
             JOIN emplois_du_temps e ON e.id = COALESCE(p.id_emploi_du_temps, i.id_emploi_du_temps)
             JOIN groupes g ON g.id = e.id_groupe
             JOIN salles sa ON sa.id = e.id_salle
             JOIN v_formateurs f ON f.id = p.id_formateur
             WHERE i.id_auditeur = :id
             ORDER BY p.date_presence DESC",
            ['id' => $id]
        );
        $counts = ['present' => 0, 'absent' => 0, 'permissionnaire' => 0, 'malade' => 0, 'conge' => 0];
        foreach ($rows as $row) {
            $counts[$row['statut']] = ($counts[$row['statut']] ?? 0) + 1;
        }
        $total = count($rows);
        return [
            'historique' => $rows,
            'synthese' => $counts + [
                'total' => $total,
                'taux_presence' => $total ? round($counts['present'] / $total * 100, 1) : null,
                'taux_absence' => $total ? round($counts['absent'] / $total * 100, 1) : null,
            ],
        ];
    }

    /** Notes détaillées (par critère) de toutes les inscriptions de l'auditeur. */
    public function getNotes(int $id): array
    {
        return $this->fetchAll(
            "SELECT n.id_inscription, n.id_evaluation, n.id_parametre, n.note, n.observation
             FROM notes n JOIN inscriptions i ON i.id = n.id_inscription
             WHERE i.id_auditeur = :id",
            ['id' => $id]
        );
    }

    /** Vérifie qu'un type d'employé existe. */
    public function typeEmployeExists(int $id): bool
    {
        return $this->fetchOne('SELECT 1 AS ok FROM types_employes WHERE id = :id LIMIT 1', ['id' => $id]) !== null;
    }

    /** Vérifie qu'une position (poste) existe. */
    public function positionExists(int $id): bool
    {
        return $this->fetchOne('SELECT 1 AS ok FROM positions WHERE id = :id LIMIT 1', ['id' => $id]) !== null;
    }

    /** Trouve un auditeur par son matricule unique. */
    public function findByMatricule(string $matricule): ?array
    {
        return $this->fetchOne(
            "SELECT * FROM {$this->table} WHERE matricule = :matricule LIMIT 1",
            ['matricule' => $matricule]
        );
    }

    /**
     * Recherche des auditeurs par nom, prénom ou matricule (LIKE %term%).
     */
    public function search(string $term): array
    {
        return $this->fetchAll(
            "SELECT a.*, po.libelle AS position, t.libelle AS type_employe_libelle, nv.libelle AS niveau_libelle
             FROM {$this->table} a
             LEFT JOIN types_employes t ON t.id = a.id_type_employe
             LEFT JOIN positions po ON po.id = a.id_position
             LEFT JOIN niveaux nv ON nv.id = a.id_niveau
             WHERE a.nom LIKE :name_term
                OR a.prenom LIKE :first_name_term
                OR a.matricule LIKE :matricule_term
             ORDER BY a.nom, a.prenom",
            [
                'name_term' => '%' . $term . '%',
                'first_name_term' => '%' . $term . '%',
                'matricule_term' => '%' . $term . '%',
            ]
        );
    }
}
