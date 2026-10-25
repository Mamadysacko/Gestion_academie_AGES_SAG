-- =====================================================================
--  Données de la BASE DE TEST (academie_langues3_test)
--
--  Ce fichier ne contient QUE des données : la structure (tables, clés
--  étrangères) est copiée automatiquement depuis la vraie base par
--  tests/database/setup_test_db.php. Il décrit un petit établissement :
--
--    3 niveaux (Débutant < Intermédiaire < Avancé)
--    1 session « Débutant » avec 1 groupe, 1 salle, 1 formateur, 3 évaluations
--    4 auditeurs : 3 inscrits (Diallo, Bah, Sow), 1 sans inscription (Camara)
--    notes : Diallo 16/20 partout, Bah 8/20 partout, Sow seulement l'évaluation 1
--    3 rôles : admin, secrétaire (sans droit de passage), approbateur (avec)
--
--  Les identifiants sont fixes : les tests s'appuient dessus.
-- =====================================================================

SET NAMES utf8mb4;

-- Référentiels ---------------------------------------------------------
INSERT INTO niveaux (id, libelle, ordre) VALUES
  (1, 'Débutant', 1), (2, 'Intermédiaire', 2), (3, 'Avancé', 3);

INSERT INTO annees_scolaires (id, libelle, date_debut, date_fin) VALUES
  (1, '2026-2027', '2026-09-01', '2027-06-30');

INSERT INTO types_formations (id, libelle) VALUES (1, 'Français général');

INSERT INTO departements (id, code, libelle) VALUES
  (1, 'ECMB', 'Département ECMB'), (2, 'EGC', 'Département EGC');

INSERT INTO types_employes (id, libelle) VALUES (1, 'SAG'), (2, 'Contractor');

INSERT INTO positions (id, libelle) VALUES
  (1, 'Chef de service'), (2, 'Opérateur'), (3, 'Superviseur');

-- Organisation pédagogique ----------------------------------------------
INSERT INTO sessions (id, id_annee, id_type_formation, id_niveau, code, libelle, date_debut, date_fin) VALUES
  (1, 1, 1, 1, 'S1-DEB', 'Session Débutant', '2026-09-07', '2026-12-18'),
  (2, 1, 1, 2, 'S2-INT', 'Session Intermédiaire', '2027-01-11', '2027-04-02');

INSERT INTO groupes (id, id_session, code, libelle, jour_semaine) VALUES
  (1, 1, 'G-A', 'Groupe A', 1);

INSERT INTO groupe_passages (id_groupe, jour_semaine, heure_debut, heure_fin, ordre) VALUES
  (1, 1, '08:00:00', '12:00:00', 1);

INSERT INTO salles (id, code, libelle, capacite) VALUES
  (1, 'S01', 'Salle 1', 30);

INSERT INTO formateurs (id, matricule, nom, prenom, password, id_salle) VALUES
  (1, 'FOR-T-001', 'Camara', 'Fatou', 'mot-de-passe-factice', 1);

INSERT INTO emplois_du_temps (id, id_annee, id_session, id_formateur, id_groupe, id_salle, jour_semaine, heure_debut, heure_fin) VALUES
  (1, 1, 1, 1, 1, 1, 1, '08:00:00', '12:00:00');

-- Auditeurs et inscriptions ---------------------------------------------
INSERT INTO auditeurs (id, matricule, nom, prenom, sexe, email, id_position, id_type_employe, id_niveau) VALUES
  (1, 'AUD-T-001', 'Diallo', 'Mamadou', 'M', 'mamadou.diallo@test.local', 1, 1, 1),
  (2, 'AUD-T-002', 'Bah',    'Mariam',  'F', NULL,                        2, 2, 1),
  (3, 'AUD-T-003', 'Sow',    'Ibrahima','M', NULL,                        NULL, 1, 1),
  (4, 'AUD-T-004', 'Camara', 'Aïcha',   'F', NULL,                        3, 2, 1);

INSERT INTO inscriptions (id, id_auditeur, id_annee, id_session, id_emploi_du_temps, id_departement, date_inscription) VALUES
  (1, 1, 1, 1, 1, 1, '2026-09-07'),
  (2, 2, 1, 1, 1, 2, '2026-09-07'),
  (3, 3, 1, 1, 1, 1, '2026-09-08');

-- Évaluations, critères et notes ----------------------------------------
INSERT INTO evaluations (id, id_session, numero, libelle, date_evaluation) VALUES
  (1, 1, 1, 'Évaluation 1', '2026-10-02'),
  (2, 1, 2, 'Évaluation 2', '2026-11-06'),
  (3, 1, 3, 'Évaluation 3', '2026-12-11');

INSERT INTO parametres (id, libelle, bareme) VALUES
  (1, 'Expression orale', 20), (2, 'Compréhension écrite', 20);

INSERT INTO evaluation_parametres (id_evaluation, id_parametre, ordre) VALUES
  (1, 1, 1), (1, 2, 2), (2, 1, 1), (2, 2, 2), (3, 1, 1), (3, 2, 2);

-- Diallo (inscription 1) : 16/20 partout -> 3.20 sur 4
INSERT INTO notes (id_evaluation, id_parametre, id_inscription, note) VALUES
  (1, 1, 1, 16), (1, 2, 1, 16), (2, 1, 1, 16), (2, 2, 1, 16), (3, 1, 1, 16), (3, 2, 1, 16);
-- Bah (inscription 2) : 8/20 partout -> 1.60 sur 4
INSERT INTO notes (id_evaluation, id_parametre, id_inscription, note) VALUES
  (1, 1, 2, 8), (1, 2, 2, 8), (2, 1, 2, 8), (2, 2, 2, 8), (3, 1, 2, 8), (3, 2, 2, 8);
-- Sow (inscription 3) : seulement l'évaluation 1 -> moyenne générale incomplète
INSERT INTO notes (id_evaluation, id_parametre, id_inscription, note) VALUES
  (1, 1, 3, 12), (1, 2, 3, 12);

-- Règles et barème d'appréciation ----------------------------------------
INSERT INTO regles_evaluation (id, libelle, id_niveau, nb_evaluations, moyenne_admission, taux_presence_min, passage_automatique) VALUES
  (1, 'Règle générale', NULL, 3, 2.00, 0.00, 0);

INSERT INTO baremes_appreciation (note, libelle, seuil_min) VALUES
  (1, 'Insuffisant', 0.00), (2, 'Passable', 2.00), (3, 'Bien', 2.75), (4, 'Très bien', 3.50);

-- Rôles, comptes et droits -----------------------------------------------
INSERT INTO roles (id, code, nom) VALUES
  (1, 'admin', 'Administrateur'),
  (2, 'secretaire', 'Secrétaire'),
  (3, 'approbateur', 'Approbateur de passages');

INSERT INTO users (id, nom, prenom, email, password, role_id, actif) VALUES
  (1, 'Admin', 'Test', 'admin@test.local', 'mot-de-passe-factice', 1, 1),
  (2, 'Secretaire', 'Test', 'secretaire@test.local', 'mot-de-passe-factice', 2, 1),
  (3, 'Approbateur', 'Test', 'approbateur@test.local', 'mot-de-passe-factice', 3, 1);

INSERT INTO role_permissions (role_id, module_code, action_code) VALUES
  (2, 'evaluations', 'read'), (2, 'auditeurs', 'read'), (2, 'auditeurs', 'create'),
  (3, 'evaluations', 'read'), (3, 'passages-niveau', 'validate');
