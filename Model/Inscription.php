<?php
/**
 * Modèle Inscription (table : inscriptions)
 *
 * Une inscription lie un auditeur à un emploi du temps (donc à un groupe,
 * via emplois_du_temps.id_groupe) et à un département.
 */

require_once __DIR__ . '/Model.php';

class Inscription extends Model
{
    protected string $table = 'inscriptions';

    public const STATUTS = ['active', 'terminee', 'abandonnee', 'annulee'];

    protected array $fillable = [
        'id_auditeur', 'id_emploi_du_temps', 'id_departement', 'date_inscription', 'statut',
    ];

    /** Liste enrichie destinée au registre d'inscriptions. */
    public function getAllWithDetails(): array
    {
        return $this->fetchAll(
            "SELECT i.*,
                    a.matricule AS auditeur_matricule, a.nom AS auditeur_nom, a.prenom AS auditeur_prenom,
                    a.sexe AS auditeur_sexe, a.telephone AS auditeur_telephone, a.email AS auditeur_email,
                    d.code AS departement_code, d.libelle AS departement_libelle,
                    e.id_annee, e.id_groupe, e.id_salle, e.id_formateur, e.jour_semaine,
                    g.code AS groupe_code, g.libelle AS groupe_libelle, g.capacite AS groupe_capacite,
                    s.id AS id_session, s.code AS session_code, s.libelle AS session_libelle,
                    an.libelle AS annee_libelle,
                    sa.code AS salle_code, sa.libelle AS salle_libelle,
                    f.matricule AS formateur_matricule, f.nom AS formateur_nom, f.prenom AS formateur_prenom
             FROM {$this->table} i
             JOIN auditeurs a ON a.id = i.id_auditeur
             JOIN departements d ON d.id = i.id_departement
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             JOIN annees_scolaires an ON an.id = e.id_annee
             JOIN salles sa ON sa.id = e.id_salle
             JOIN formateurs f ON f.id = e.id_formateur
             ORDER BY i.date_inscription DESC, a.nom, a.prenom"
        );
    }

    /* ------------------------------------------------------------------
     | Recherches filtrées
     |------------------------------------------------------------------ */

    /** Inscriptions d'un auditeur (avec groupe et session). */
    public function getByAuditeur(int $id_auditeur): array
    {
        return $this->fetchAll(
            "SELECT i.*,
                    g.code AS groupe_code, g.libelle AS groupe_libelle,
                    s.code AS session_code, s.libelle AS session_libelle
             FROM {$this->table} i
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN groupes g          ON g.id = e.id_groupe
             JOIN sessions s         ON s.id = g.id_session
             WHERE i.id_auditeur = :id_auditeur
             ORDER BY i.date_inscription DESC",
            ['id_auditeur' => $id_auditeur]
        );
    }

    /** Inscriptions d'un groupe (via l'emploi du temps). */
    public function getByGroupe(int $id_groupe): array
    {
        return $this->fetchAll(
            "SELECT i.*,
                    a.matricule AS auditeur_matricule, a.nom AS auditeur_nom, a.prenom AS auditeur_prenom
             FROM {$this->table} i
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN auditeurs a        ON a.id = i.id_auditeur
             WHERE e.id_groupe = :id_groupe
             ORDER BY a.nom, a.prenom",
            ['id_groupe' => $id_groupe]
        );
    }

    /** Inscriptions d'un département. */
    public function getByDepartement(int $id_departement): array
    {
        return $this->fetchAll(
            "SELECT i.*,
                    a.matricule AS auditeur_matricule, a.nom AS auditeur_nom, a.prenom AS auditeur_prenom
             FROM {$this->table} i
             JOIN auditeurs a ON a.id = i.id_auditeur
             WHERE i.id_departement = :id_departement
             ORDER BY a.nom, a.prenom",
            ['id_departement' => $id_departement]
        );
    }

    /** Inscriptions rattachées à un emploi du temps précis. */
    public function getByEmploiDuTemps(int $id_edt): array
    {
        return $this->fetchAll(
            "SELECT i.*,
                    a.matricule AS auditeur_matricule, a.nom AS auditeur_nom, a.prenom AS auditeur_prenom
             FROM {$this->table} i
             JOIN auditeurs a ON a.id = i.id_auditeur
             WHERE i.id_emploi_du_temps = :id_edt
             ORDER BY a.nom, a.prenom",
            ['id_edt' => $id_edt]
        );
    }

    /**
     * Change le statut d'une inscription.
     * @throws InvalidArgumentException si le statut est invalide.
     */
    public function changeStatut(int $id, string $statut): bool
    {
        $this->assertIn($statut, self::STATUTS, 'statut');

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
     * Inscription validée et atomique : contrôle du créneau, capacité et unicité.
     * @throws DomainException lorsque la règle métier empêche l'inscription.
     */
    public function createValidated(array $data): int
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT g.id, g.capacite, g.statut AS groupe_actif,
                        e.statut AS edt_actif, s.statut AS session_statut
                 FROM emplois_du_temps e
                 JOIN groupes g ON g.id = e.id_groupe
                 JOIN sessions s ON s.id = g.id_session
                 WHERE e.id = :id_edt
                 FOR UPDATE"
            );
            $stmt->execute(['id_edt' => (int) $data['id_emploi_du_temps']]);
            $schedule = $stmt->fetch();
            if (!$schedule) {
                throw new DomainException('L’emploi du temps sélectionné est introuvable.');
            }
            if (!(bool) $schedule['edt_actif'] || !(bool) $schedule['groupe_actif']) {
                throw new DomainException('Un emploi du temps ou un groupe inactif ne peut pas recevoir d’inscription.');
            }
            if (!in_array($schedule['session_statut'], ['ouverte', 'en_cours'], true)) {
                throw new DomainException('La session sélectionnée n’est pas ouverte aux inscriptions.');
            }

            $duplicate = $this->fetchOne(
                "SELECT 1 FROM {$this->table}
                 WHERE id_auditeur = :id_auditeur AND id_emploi_du_temps = :id_edt
                 LIMIT 1",
                ['id_auditeur' => (int) $data['id_auditeur'], 'id_edt' => (int) $data['id_emploi_du_temps']]
            );
            if ($duplicate !== null) {
                throw new DomainException('Cet auditeur possède déjà une inscription pour cet emploi du temps.');
            }

            if ($schedule['capacite'] !== null) {
                $count = $this->fetchColumn(
                    "SELECT COUNT(*) FROM {$this->table} i
                     JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
                     WHERE e.id_groupe = :id_groupe AND i.statut = 'active'",
                    ['id_groupe' => (int) $schedule['id']]
                );
                if ((int) $count >= (int) $schedule['capacite']) {
                    throw new DomainException('La capacité maximale de ce groupe est atteinte.');
                }
            }

            $id = $this->create($data);
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Inscription complète : auditeur + groupe + session + département + EDT.
     */
    public function getWithDetails(int $id): ?array
    {
        return $this->fetchOne(
            "SELECT i.*,
                    a.matricule AS auditeur_matricule, a.nom AS auditeur_nom, a.prenom AS auditeur_prenom,
                    g.code AS groupe_code, g.libelle AS groupe_libelle, g.jour_semaine AS groupe_jour,
                    s.code AS session_code, s.libelle AS session_libelle,
                    d.code AS departement_code, d.libelle AS departement_libelle,
                    e.id_annee, e.id_salle, e.id_formateur
             FROM {$this->table} i
             JOIN auditeurs a        ON a.id = i.id_auditeur
             JOIN departements d     ON d.id = i.id_departement
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN groupes g          ON g.id = e.id_groupe
             JOIN sessions s         ON s.id = g.id_session
             WHERE i.id = :id
             LIMIT 1",
            ['id' => $id]
        );
    }
}
