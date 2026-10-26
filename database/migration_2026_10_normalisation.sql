-- =====================================================================
--  Migration 2026-10 : normalisation et intégrité (audit du 25/10/2026)
--  Base : academie_langues3 (MySQL 8) — voir docs/AUDIT_BASE_DE_DONNEES.md
--
--    php database/apply_migration.php migration_2026_10_normalisation.sql
--    retour arrière : php database/apply_migration.php rollback_2026_10_normalisation.sql
--
--  1. Données incohérentes corrigées (année trop courte pour sa session, libellés, espaces).
--  2. Formateurs : l'identité (nom, prénom, contacts, actif) n'existe plus qu'une fois, dans `users` ;
--     `formateurs` ne garde que ce qui lui est propre (compte, matricule, salle). La vue `v_formateurs`
--     présente les deux réunis, avec les mêmes colonnes qu'avant. Le mot de passe copié est supprimé.
--  3. Colonnes jamais utilisées supprimées ; colonnes dérivées calculées par MySQL.
--  4. Déclencheurs : colonnes recopiées toujours cohérentes, dates des sessions et évaluations contrôlées.
--  5. Clés étrangères manquantes ajoutées, index en double supprimés.
-- =====================================================================

SET NAMES utf8mb4;

-- ── 1. Données ───────────────────────────────────────────────────────────
-- L'année « 2026-2027 » se terminait le 31/12/2026 alors que sa session SN2 et ses évaluations
-- vont jusqu'au 31/03/2027 : sans correction, plus aucune séance ni présence possible en 2027.
UPDATE annees_scolaires a
SET a.date_fin = GREATEST(a.date_fin,
        COALESCE((SELECT MAX(s.date_fin) FROM sessions s WHERE s.id_annee = a.id), a.date_fin),
        COALESCE((SELECT MAX(e.date_evaluation) FROM evaluations e JOIN sessions s ON s.id = e.id_session WHERE s.id_annee = a.id), a.date_fin)),
    a.date_debut = LEAST(a.date_debut,
        COALESCE((SELECT MIN(s.date_debut) FROM sessions s WHERE s.id_annee = a.id), a.date_debut));

-- Une seule année : elle devient l'année active (aucune ne l'était).
SET @annees_actives = (SELECT COUNT(*) FROM annees_scolaires WHERE statut = 1);
SET @annee_courante = (SELECT id FROM annees_scolaires WHERE CURDATE() BETWEEN date_debut AND date_fin ORDER BY date_debut DESC LIMIT 1);
UPDATE annees_scolaires SET statut = 1 WHERE @annees_actives = 0 AND id = @annee_courante;

UPDATE groupes SET libelle = 'Groupe 4' WHERE code = 'G4' AND libelle = 'Groupe 3';
UPDATE roles SET nom = REPLACE(nom, 'Administrative er Comptable', 'Administrative et Comptable');
UPDATE users SET nom = TRIM(nom), prenom = TRIM(prenom), email = NULLIF(TRIM(email), ''), telephone = NULLIF(TRIM(telephone), '');
UPDATE formateurs SET nom = TRIM(nom), prenom = TRIM(prenom), matricule = TRIM(matricule);

-- ── 2. Formateurs : une seule identité ──────────────────────────────────
-- Contacts éventuellement absents du compte : repris de la fiche formateur avant suppression.
UPDATE users u JOIN formateurs f ON f.user_id = u.id
SET u.email = COALESCE(u.email, f.email), u.telephone = COALESCE(u.telephone, f.telephone);

ALTER TABLE formateurs DROP FOREIGN KEY fk_formateurs_user;
ALTER TABLE formateurs
  MODIFY user_id int unsigned NOT NULL,
  DROP INDEX idx_formateurs_user,
  DROP INDEX uk_formateurs_email,
  ADD UNIQUE KEY uk_formateurs_user (user_id),
  DROP COLUMN nom,
  DROP COLUMN prenom,
  DROP COLUMN password,
  DROP COLUMN telephone,
  DROP COLUMN email,
  DROP COLUMN actif,
  ADD CONSTRAINT fk_formateurs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW v_formateurs AS
SELECT f.id, f.user_id, f.matricule, u.nom, u.prenom, u.telephone, u.email,
       f.id_salle, u.actif, f.created_at, f.updated_at
FROM formateurs f
JOIN users u ON u.id = f.user_id;

-- ── 3. Utilisateurs : connexion par téléphone sans ambiguïté ────────────
-- « 627 15 55 97 » et « 627155597 » désignent le même numéro : il ne peut appartenir qu'à un compte.
ALTER TABLE users
  ADD COLUMN telephone_normalise varchar(30) COLLATE utf8mb4_unicode_ci
    GENERATED ALWAYS AS (NULLIF(REPLACE(REPLACE(REPLACE(REPLACE(telephone, ' ', ''), '-', ''), '.', ''), '+', ''), '')) STORED
    AFTER telephone,
  ADD UNIQUE KEY uk_users_telephone (telephone_normalise);

-- ── 4. Colonnes inutiles ou dérivées ────────────────────────────────────
-- Jamais lue (le jour se déduit de date_presence).
ALTER TABLE presences DROP COLUMN jour_semaine_presence;
-- Toujours forcée à 0 par l'API : les passages de niveau sont validés manuellement.
ALTER TABLE regles_evaluation DROP COLUMN passage_automatique;
-- Doublon de la date de la dernière version (formation_config_historique.created_at).
ALTER TABLE types_formations DROP COLUMN config_updated_at;
-- Doublon de revoked_at (un jeton n'est utilisé qu'une fois, au moment où il est révoqué).
ALTER TABLE refresh_tokens DROP COLUMN last_used_at;

-- Nombre de passages : calculé par MySQL depuis l'instantané de configuration (plus de divergence possible).
ALTER TABLE sessions DROP COLUMN passages_semaine;
ALTER TABLE sessions
  ADD COLUMN passages_semaine tinyint unsigned
    GENERATED ALWAYS AS (CAST(JSON_UNQUOTE(JSON_EXTRACT(config_formation, '$.passages_semaine')) AS UNSIGNED)) STORED
    AFTER id_session_source;

-- Jours autorisés : liste fermée contrôlée par la base (valeurs 1 à 5 uniquement).
ALTER TABLE types_formations
  MODIFY jours_autorises SET('1','2','3','4','5') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1,2,3,4,5';

-- ── 5. Clés étrangères manquantes ───────────────────────────────────────
ALTER TABLE refresh_tokens
  ADD KEY idx_refresh_remplace (remplace_par),
  ADD CONSTRAINT fk_refresh_remplace FOREIGN KEY (remplace_par) REFERENCES refresh_tokens (id) ON DELETE SET NULL;
ALTER TABLE emplois_du_temps
  ADD KEY idx_edt_generation (id_generation),
  ADD CONSTRAINT fk_edt_generation FOREIGN KEY (id_generation) REFERENCES planification_generations (id) ON DELETE SET NULL ON UPDATE CASCADE;

-- ── 6. Index en double (entièrement couverts par un index composite) ────
ALTER TABLE emplois_du_temps DROP INDEX idx_edt_groupe, DROP INDEX idx_edt_salle, DROP INDEX idx_edt_session;
ALTER TABLE evaluations DROP INDEX idx_evaluations_session;
ALTER TABLE groupes DROP INDEX idx_groupes_session;
ALTER TABLE inscriptions DROP INDEX idx_inscriptions_auditeur, DROP INDEX idx_inscriptions_edt;
ALTER TABLE presences DROP INDEX idx_presences_edt;
ALTER TABLE sessions DROP INDEX idx_sessions_annee;

-- ── 7. Déclencheurs de cohérence ────────────────────────────────────────
DELIMITER $$

-- Créneau : session et année toujours celles du groupe.
CREATE TRIGGER trg_edt_coherence_ins BEFORE INSERT ON emplois_du_temps FOR EACH ROW
BEGIN
  SET NEW.id_session = (SELECT g.id_session FROM groupes g WHERE g.id = NEW.id_groupe);
  SET NEW.id_annee = (SELECT s.id_annee FROM sessions s WHERE s.id = NEW.id_session);
END$$

CREATE TRIGGER trg_edt_coherence_upd BEFORE UPDATE ON emplois_du_temps FOR EACH ROW
BEGIN
  SET NEW.id_session = (SELECT g.id_session FROM groupes g WHERE g.id = NEW.id_groupe);
  SET NEW.id_annee = (SELECT s.id_annee FROM sessions s WHERE s.id = NEW.id_session);
END$$

-- Inscription : session et année toujours celles du créneau choisi.
CREATE TRIGGER trg_inscription_coherence_ins BEFORE INSERT ON inscriptions FOR EACH ROW
BEGIN
  SET NEW.id_session = (SELECT e.id_session FROM emplois_du_temps e WHERE e.id = NEW.id_emploi_du_temps);
  SET NEW.id_annee = (SELECT e.id_annee FROM emplois_du_temps e WHERE e.id = NEW.id_emploi_du_temps);
END$$

CREATE TRIGGER trg_inscription_coherence_upd BEFORE UPDATE ON inscriptions FOR EACH ROW
BEGIN
  SET NEW.id_session = (SELECT e.id_session FROM emplois_du_temps e WHERE e.id = NEW.id_emploi_du_temps);
  SET NEW.id_annee = (SELECT e.id_annee FROM emplois_du_temps e WHERE e.id = NEW.id_emploi_du_temps);
END$$

-- Session : ses dates restent dans son année scolaire.
CREATE TRIGGER trg_session_dates_ins BEFORE INSERT ON sessions FOR EACH ROW
BEGIN
  IF EXISTS (SELECT 1 FROM annees_scolaires a WHERE a.id = NEW.id_annee AND (NEW.date_debut < a.date_debut OR NEW.date_fin > a.date_fin)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Les dates de la session doivent être comprises dans celles de son année scolaire.';
  END IF;
END$$

CREATE TRIGGER trg_session_dates_upd BEFORE UPDATE ON sessions FOR EACH ROW
BEGIN
  IF EXISTS (SELECT 1 FROM annees_scolaires a WHERE a.id = NEW.id_annee AND (NEW.date_debut < a.date_debut OR NEW.date_fin > a.date_fin)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Les dates de la session doivent être comprises dans celles de son année scolaire.';
  END IF;
  IF EXISTS (SELECT 1 FROM evaluations e WHERE e.id_session = NEW.id AND e.date_evaluation NOT BETWEEN NEW.date_debut AND NEW.date_fin) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Des évaluations de cette session tomberaient hors de ses nouvelles dates : déplacez-les d’abord.';
  END IF;
END$$

-- Année scolaire : ne peut pas être raccourcie en laissant une session dehors.
CREATE TRIGGER trg_annee_dates_upd BEFORE UPDATE ON annees_scolaires FOR EACH ROW
BEGIN
  IF EXISTS (SELECT 1 FROM sessions s WHERE s.id_annee = NEW.id AND (s.date_debut < NEW.date_debut OR s.date_fin > NEW.date_fin)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Une session de cette année sortirait de ses nouvelles dates : modifiez d’abord la session.';
  END IF;
END$$

-- Évaluation : sa date reste dans la période de sa session.
CREATE TRIGGER trg_evaluation_date_ins BEFORE INSERT ON evaluations FOR EACH ROW
BEGIN
  IF EXISTS (SELECT 1 FROM sessions s WHERE s.id = NEW.id_session AND NEW.date_evaluation NOT BETWEEN s.date_debut AND s.date_fin) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La date de l’évaluation doit être comprise dans la période de sa session.';
  END IF;
END$$

CREATE TRIGGER trg_evaluation_date_upd BEFORE UPDATE ON evaluations FOR EACH ROW
BEGIN
  IF EXISTS (SELECT 1 FROM sessions s WHERE s.id = NEW.id_session AND NEW.date_evaluation NOT BETWEEN s.date_debut AND s.date_fin) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La date de l’évaluation doit être comprise dans la période de sa session.';
  END IF;
END$$

DELIMITER ;
