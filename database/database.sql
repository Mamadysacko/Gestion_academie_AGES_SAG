-- ============================================================
-- BASE DE DONNÉES : ACADÉMIE DE LANGUES
-- VERSION FINALE
--
-- HIÉRARCHIE :
--
-- ANNÉE SCOLAIRE
--      ↓
-- TYPE DE FORMATION
--      ↓
-- SESSION
--      ↓
-- GROUPE
--      ↓
-- INSCRIPTIONS
--      ↓
-- AUDITEURS
--
-- PLANNING :
--
-- GROUPE + JOUR + SALLE + FORMATEUR
--
-- Un groupe peut avoir plusieurs salles le même jour.
-- Chaque salle possède son propre formateur.
-- ============================================================



CREATE DATABASE IF NOT EXISTS academie_langues2
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE academie_langues2;


-- ============================================================
-- 1. ANNÉES SCOLAIRES
-- ============================================================

CREATE TABLE annees_scolaires (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    libelle VARCHAR(20) NOT NULL,

    date_debut DATE NOT NULL,
    date_fin DATE NOT NULL,

    statut BOOLEAN NOT NULL DEFAULT TRUE,

    PRIMARY KEY (id),

    UNIQUE KEY uk_annees_libelle (libelle),

    CONSTRAINT chk_annees_dates
        CHECK (date_fin > date_debut)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 2. RÔLES
-- ============================================================

CREATE TABLE roles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    code VARCHAR(30) NOT NULL,
    nom VARCHAR(80) NOT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uk_roles_code (code),
    UNIQUE KEY uk_roles_nom (nom)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 3. UTILISATEURS
-- ============================================================

CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,

    email VARCHAR(180) DEFAULT NULL,
    telephone VARCHAR(30) DEFAULT NULL,

    password VARCHAR(255) NOT NULL,

    role_id INT UNSIGNED NOT NULL,

    actif BOOLEAN NOT NULL DEFAULT TRUE,

    last_login TIMESTAMP NULL DEFAULT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uk_users_email (email),

    KEY idx_users_role (role_id),
    KEY idx_users_actif (actif),

    CONSTRAINT fk_users_role
        FOREIGN KEY (role_id)
        REFERENCES roles(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 4. DÉPARTEMENTS
-- ============================================================

CREATE TABLE departements (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    code VARCHAR(30) NOT NULL,
    libelle VARCHAR(100) NOT NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uk_departements_code (code)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 5. NIVEAUX
-- ============================================================

CREATE TABLE niveaux (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    libelle VARCHAR(100) NOT NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uk_niveaux_libelle (libelle)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 6. TYPES DE FORMATIONS
-- ============================================================
--
-- Exemple :
--
-- Français
-- Anglais
--
-- Cette table ne représente PAS une session.
-- Elle représente le type de formation.
-- ============================================================

CREATE TABLE types_formations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    libelle VARCHAR(100) NOT NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uk_types_formations_libelle
        (libelle)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 7. FORMATEURS
-- ============================================================

CREATE TABLE formateurs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    user_id INT UNSIGNED DEFAULT NULL,

    matricule VARCHAR(50) NOT NULL,

    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,

    password VARCHAR(255) NOT NULL,

    telephone VARCHAR(30) DEFAULT NULL,
    email VARCHAR(180) DEFAULT NULL,

    actif BOOLEAN NOT NULL DEFAULT TRUE,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uk_formateurs_matricule
        (matricule),

    UNIQUE KEY uk_formateurs_email
        (email),

    KEY idx_formateurs_user
        (user_id),

    CONSTRAINT fk_formateurs_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 8. SALLES
-- ============================================================

CREATE TABLE salles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    code VARCHAR(30) NOT NULL,
    libelle VARCHAR(100) NOT NULL,

    capacite INT UNSIGNED DEFAULT NULL,

    statut ENUM(
        'disponible',
        'indisponible'
    ) NOT NULL DEFAULT 'disponible',

    PRIMARY KEY (id),

    UNIQUE KEY uk_salles_code
        (code),

    CONSTRAINT chk_salles_capacite
        CHECK (
            capacite IS NULL
            OR capacite > 0
        )

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 9. SESSIONS
-- ============================================================
--
-- Une session est une organisation concrète d'un type
-- de formation pendant une année scolaire.
--
-- Exemple :
--
-- 2026-2027
--    Français
--       Session 1
--       Session 2
--       Session 3
--
--    Anglais
--       Session 1
--       Session 2
--
-- ============================================================

CREATE TABLE sessions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    id_annee INT UNSIGNED NOT NULL,

    id_type_formation INT UNSIGNED NOT NULL,

    id_niveau INT UNSIGNED NOT NULL,

    code VARCHAR(50) NOT NULL,

    libelle VARCHAR(100) NOT NULL,

    date_debut DATE NOT NULL,
    date_fin DATE NOT NULL,

    statut ENUM(
        'planifiee',
        'ouverte',
        'en_cours',
        'terminee',
        'annulee'
    ) NOT NULL DEFAULT 'planifiee',

    PRIMARY KEY (id),

    UNIQUE KEY uk_sessions_code
        (code),

    UNIQUE KEY uk_sessions_annee_type_libelle
        (
            id_annee,
            id_type_formation,
            libelle
        ),

    KEY idx_sessions_annee
        (id_annee),

    KEY idx_sessions_type
        (id_type_formation),

    KEY idx_sessions_niveau
        (id_niveau),

    CONSTRAINT fk_sessions_annee
        FOREIGN KEY (id_annee)
        REFERENCES annees_scolaires(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_sessions_type
        FOREIGN KEY (id_type_formation)
        REFERENCES types_formations(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_sessions_niveau
        FOREIGN KEY (id_niveau)
        REFERENCES niveaux(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT chk_sessions_dates
        CHECK (date_fin >= date_debut)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 10. GROUPES
-- ============================================================
--
-- Une session possède plusieurs groupes.
--
-- Exemple :
--
-- Français - Session 1
--      ├── Groupe F01
--      ├── Groupe F02
--      └── Groupe F03
--
-- ============================================================

CREATE TABLE groupes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    id_session INT UNSIGNED NOT NULL,

    code VARCHAR(30) NOT NULL,
    libelle VARCHAR(100) NOT NULL,

    jour_semaine TINYINT UNSIGNED NOT NULL,

    capacite INT UNSIGNED DEFAULT NULL,

    statut BOOLEAN NOT NULL DEFAULT TRUE,

    PRIMARY KEY (id),

    UNIQUE KEY uk_groupes_session_code
        (
            id_session,
            code
        ),

    KEY idx_groupes_session
        (id_session),

    KEY idx_groupes_jour
        (jour_semaine),

    CONSTRAINT fk_groupes_session
        FOREIGN KEY (id_session)
        REFERENCES sessions(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT chk_groupes_jour
        CHECK (jour_semaine BETWEEN 1 AND 5),

    CONSTRAINT chk_groupes_capacite
        CHECK (
            capacite IS NULL
            OR capacite > 0
        )

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 11. AUDITEURS
-- ============================================================

CREATE TABLE auditeurs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    matricule VARCHAR(50) NOT NULL,

    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,

    sexe ENUM(
        'M',
        'F'
    ) DEFAULT NULL,

    telephone VARCHAR(30) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uk_auditeurs_matricule
        (matricule)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 12. INSCRIPTIONS
-- ============================================================
--
-- Un auditeur est inscrit dans un groupe.
--
-- Le groupe appartient à une session.
-- La session appartient à une année.
--
-- ============================================================

CREATE TABLE inscriptions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    id_auditeur INT UNSIGNED NOT NULL,

    id_groupe INT UNSIGNED NOT NULL,

    id_departement INT UNSIGNED NOT NULL,

    date_inscription DATE NOT NULL,

    statut ENUM(
        'active',
        'terminee',
        'abandonnee',
        'annulee'
    ) NOT NULL DEFAULT 'active',

    PRIMARY KEY (id),

    UNIQUE KEY uk_inscription_auditeur_groupe
        (
            id_auditeur,
            id_groupe
        ),

    KEY idx_inscriptions_auditeur
        (id_auditeur),

    KEY idx_inscriptions_groupe
        (id_groupe),

    KEY idx_inscriptions_departement
        (id_departement),

    CONSTRAINT fk_inscriptions_auditeur
        FOREIGN KEY (id_auditeur)
        REFERENCES auditeurs(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_inscriptions_groupe
        FOREIGN KEY (id_groupe)
        REFERENCES groupes(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_inscriptions_departement
        FOREIGN KEY (id_departement)
        REFERENCES departements(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 13. EMPLOIS DU TEMPS
-- ============================================================
--
-- C'EST LA TABLE CENTRALE POUR LES AFFECTATIONS.
--
-- Une ligne signifie :
--
--     GROUPE
--        +
--     JOUR
--        +
--     SALLE
--        +
--     FORMATEUR
--
--
-- Exemple :
--
-- LUNDI
--
-- Groupe 1
--    Salle A → Formateur 1
--    Salle B → Formateur 2
--    Salle C → Formateur 3
--
--
-- MARDI
--
-- Groupe 2
--    Salle A → Formateur 4
--    Salle B → Formateur 5
--
--
-- RÈGLES :
--
-- 1. Une salle ne peut être utilisée que par un groupe
--    pendant une journée.
--
-- 2. Un formateur ne peut être affecté qu'à une salle
--    pendant une journée.
--
-- 3. Un groupe PEUT avoir plusieurs salles le même jour.
--
-- 4. Chaque salle possède son propre formateur.
--
-- IMPORTANT :
--
-- Il n'y a volontairement PAS de :
--
-- UNIQUE (année, groupe, jour)
--
-- car cela empêcherait un groupe d'avoir plusieurs salles.
--
-- ============================================================

CREATE TABLE emplois_du_temps (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    id_annee INT UNSIGNED NOT NULL,

    id_groupe INT UNSIGNED NOT NULL,

    id_salle INT UNSIGNED NOT NULL,

    id_formateur INT UNSIGNED NOT NULL,

    jour_semaine TINYINT UNSIGNED NOT NULL,

    statut BOOLEAN NOT NULL DEFAULT TRUE,

    PRIMARY KEY (id),

    /*
     * Une salle ne peut pas être utilisée
     * par plusieurs groupes le même jour.
     */
    UNIQUE KEY uk_edt_salle_annee_jour
        (
            id_annee,
            id_salle,
            jour_semaine
        ),

    /*
     * Un formateur ne peut pas être affecté
     * à plusieurs salles le même jour.
     */
    UNIQUE KEY uk_edt_formateur_annee_jour
        (
            id_annee,
            id_formateur,
            jour_semaine
        ),

    /*
     * Empêche le même groupe d'être affecté
     * deux fois à la même salle le même jour.
     */
    UNIQUE KEY uk_edt_groupe_salle_jour
        (
            id_annee,
            id_groupe,
            id_salle,
            jour_semaine
        ),

    KEY idx_edt_annee
        (id_annee),

    KEY idx_edt_groupe
        (id_groupe),

    KEY idx_edt_salle
        (id_salle),

    KEY idx_edt_formateur
        (id_formateur),

    KEY idx_edt_jour
        (jour_semaine),

    CONSTRAINT fk_edt_annee
        FOREIGN KEY (id_annee)
        REFERENCES annees_scolaires(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_edt_groupe
        FOREIGN KEY (id_groupe)
        REFERENCES groupes(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_edt_salle
        FOREIGN KEY (id_salle)
        REFERENCES salles(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_edt_formateur
        FOREIGN KEY (id_formateur)
        REFERENCES formateurs(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT chk_edt_jour
        CHECK (jour_semaine BETWEEN 1 AND 5)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 14. ÉVALUATIONS
-- ============================================================
--
-- Une évaluation appartient à une session.
--
-- ============================================================

CREATE TABLE evaluations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    id_session INT UNSIGNED NOT NULL,

    libelle VARCHAR(100) NOT NULL,

    date_evaluation DATE NOT NULL,

    PRIMARY KEY (id),

    KEY idx_evaluations_session
        (id_session),

    CONSTRAINT fk_evaluations_session
        FOREIGN KEY (id_session)
        REFERENCES sessions(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 15. PARAMÈTRES DES ÉVALUATIONS
-- ============================================================

CREATE TABLE parametres_evaluations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    id_evaluation INT UNSIGNED NOT NULL,

    libelle VARCHAR(100) NOT NULL,

    bareme DECIMAL(5,2) NOT NULL DEFAULT 4.00,

    PRIMARY KEY (id),

    KEY idx_parametres_evaluation
        (id_evaluation),

    CONSTRAINT fk_parametres_evaluation
        FOREIGN KEY (id_evaluation)
        REFERENCES evaluations(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT chk_parametres_bareme
        CHECK (bareme > 0)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 16. NOTES
-- ============================================================

CREATE TABLE notes (
    id_note INT UNSIGNED NOT NULL AUTO_INCREMENT,

    id_parametre INT UNSIGNED NOT NULL,

    id_inscription INT UNSIGNED NOT NULL,

    note DECIMAL(5,2) DEFAULT NULL,

    observation VARCHAR(255) DEFAULT NULL,

    PRIMARY KEY (id_note),

    UNIQUE KEY uk_note_inscription_parametre
        (
            id_parametre,
            id_inscription
        ),

    KEY idx_notes_inscription
        (id_inscription),

    CONSTRAINT fk_notes_parametre
        FOREIGN KEY (id_parametre)
        REFERENCES parametres_evaluations(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_notes_inscription
        FOREIGN KEY (id_inscription)
        REFERENCES inscriptions(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 17. PASSAGES DE NIVEAUX
-- ============================================================

CREATE TABLE passages_niveaux (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    id_inscription INT UNSIGNED NOT NULL,

    niveau_depart INT UNSIGNED NOT NULL,

    niveau_arrivee INT UNSIGNED DEFAULT NULL,

    decision ENUM(
        'admis',
        'non_admis',
        'redouble',
        'abandonne'
    ) NOT NULL,

    moyenne DECIMAL(5,2) DEFAULT NULL,

    date_decision DATE NOT NULL,

    observation VARCHAR(255) DEFAULT NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uk_passage_inscription
        (id_inscription),

    KEY idx_passage_niveau_depart
        (niveau_depart),

    KEY idx_passage_niveau_arrivee
        (niveau_arrivee),

    CONSTRAINT fk_passage_inscription
        FOREIGN KEY (id_inscription)
        REFERENCES inscriptions(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_passage_niveau_depart
        FOREIGN KEY (niveau_depart)
        REFERENCES niveaux(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_passage_niveau_arrivee
        FOREIGN KEY (niveau_arrivee)
        REFERENCES niveaux(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 18. PRÉSENCES
-- ============================================================

CREATE TABLE presences (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    id_inscription INT UNSIGNED NOT NULL,

    id_emploi_du_temps INT UNSIGNED DEFAULT NULL,

    id_formateur INT UNSIGNED NOT NULL,

    date_presence DATE NOT NULL,

    jour_semaine_presence
        VARCHAR(10)
        GENERATED ALWAYS AS (
            CASE DAYOFWEEK(date_presence)
                WHEN 2 THEN 'lundi'
                WHEN 3 THEN 'mardi'
                WHEN 4 THEN 'mercredi'
                WHEN 5 THEN 'jeudi'
                WHEN 6 THEN 'vendredi'
                ELSE NULL
            END
        ) STORED,

    statut ENUM(
        'present',
        'absent',
        'permissionnaire',
        'malade',
        'conge'
    ) NOT NULL DEFAULT 'present',

    conge_date_debut DATE DEFAULT NULL,

    conge_date_fin DATE DEFAULT NULL,

    observation VARCHAR(255) DEFAULT NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uk_presence_inscription_date
        (
            id_inscription,
            date_presence
        ),

    KEY idx_presences_edt
        (id_emploi_du_temps),

    KEY idx_presences_formateur
        (id_formateur),

    KEY idx_presences_date
        (date_presence),

    CONSTRAINT fk_presences_inscription
        FOREIGN KEY (id_inscription)
        REFERENCES inscriptions(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_presences_edt
        FOREIGN KEY (id_emploi_du_temps)
        REFERENCES emplois_du_temps(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_presences_formateur
        FOREIGN KEY (id_formateur)
        REFERENCES formateurs(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT chk_presences_conge_dates
        CHECK (
            conge_date_fin IS NULL
            OR conge_date_debut IS NULL
            OR conge_date_fin >= conge_date_debut
        )

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
