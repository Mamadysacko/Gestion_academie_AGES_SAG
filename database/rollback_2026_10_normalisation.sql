-- =====================================================================
--  Retour arrière de migration_2026_10_normalisation.sql
--    php database/apply_migration.php rollback_2026_10_normalisation.sql
--
--  Restaure la structure précédente (colonnes, index, absence de déclencheurs) et recopie
--  l'identité des formateurs depuis `users`. Les corrections de données (dates de l'année,
--  libellés, espaces) sont conservées : ce sont des corrections, pas des changements de structure.
--  Le mot de passe en clair des formateurs ne peut pas revenir (il est remplacé par celui, haché,
--  du compte) : pour retrouver l'état exact, restaurez database/backups/backup_avant_normalisation_*.sql.
--  À exécuter avec la version du code antérieure à la normalisation.
-- =====================================================================

SET NAMES utf8mb4;

DROP TRIGGER IF EXISTS trg_edt_coherence_ins;
DROP TRIGGER IF EXISTS trg_edt_coherence_upd;
DROP TRIGGER IF EXISTS trg_inscription_coherence_ins;
DROP TRIGGER IF EXISTS trg_inscription_coherence_upd;
DROP TRIGGER IF EXISTS trg_session_dates_ins;
DROP TRIGGER IF EXISTS trg_session_dates_upd;
DROP TRIGGER IF EXISTS trg_annee_dates_upd;
DROP TRIGGER IF EXISTS trg_evaluation_date_ins;
DROP TRIGGER IF EXISTS trg_evaluation_date_upd;

ALTER TABLE emplois_du_temps ADD KEY idx_edt_groupe (id_groupe), ADD KEY idx_edt_salle (id_salle), ADD KEY idx_edt_session (id_session);
ALTER TABLE evaluations ADD KEY idx_evaluations_session (id_session);
ALTER TABLE groupes ADD KEY idx_groupes_session (id_session);
ALTER TABLE inscriptions ADD KEY idx_inscriptions_auditeur (id_auditeur), ADD KEY idx_inscriptions_edt (id_emploi_du_temps);
ALTER TABLE presences ADD KEY idx_presences_edt (id_emploi_du_temps);
ALTER TABLE sessions ADD KEY idx_sessions_annee (id_annee);

ALTER TABLE emplois_du_temps DROP FOREIGN KEY fk_edt_generation, DROP INDEX idx_edt_generation;
ALTER TABLE refresh_tokens DROP FOREIGN KEY fk_refresh_remplace, DROP INDEX idx_refresh_remplace;

ALTER TABLE types_formations MODIFY jours_autorises varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1,2,3,4,5';

ALTER TABLE sessions DROP COLUMN passages_semaine;
ALTER TABLE sessions ADD COLUMN passages_semaine tinyint unsigned DEFAULT NULL AFTER id_session_source;
UPDATE sessions SET passages_semaine = CAST(JSON_UNQUOTE(JSON_EXTRACT(config_formation, '$.passages_semaine')) AS UNSIGNED) WHERE config_formation IS NOT NULL;

ALTER TABLE refresh_tokens ADD COLUMN last_used_at datetime DEFAULT NULL AFTER created_at;
ALTER TABLE types_formations ADD COLUMN config_updated_at timestamp NULL DEFAULT NULL;
ALTER TABLE regles_evaluation ADD COLUMN passage_automatique tinyint(1) NOT NULL DEFAULT '1';
ALTER TABLE presences ADD COLUMN jour_semaine_presence varchar(10) COLLATE utf8mb4_unicode_ci
  GENERATED ALWAYS AS ((case dayofweek(`date_presence`) when 2 then _utf8mb4'lundi' when 3 then _utf8mb4'mardi' when 4 then _utf8mb4'mercredi' when 5 then _utf8mb4'jeudi' when 6 then _utf8mb4'vendredi' else NULL end)) STORED AFTER date_presence;

ALTER TABLE users DROP INDEX uk_users_telephone, DROP COLUMN telephone_normalise;

DROP VIEW IF EXISTS v_formateurs;
ALTER TABLE formateurs DROP FOREIGN KEY fk_formateurs_user;
ALTER TABLE formateurs
  DROP INDEX uk_formateurs_user,
  MODIFY user_id int unsigned DEFAULT NULL,
  ADD COLUMN nom varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' AFTER matricule,
  ADD COLUMN prenom varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' AFTER nom,
  ADD COLUMN password varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' AFTER prenom,
  ADD COLUMN telephone varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER password,
  ADD COLUMN email varchar(180) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER telephone,
  ADD COLUMN actif tinyint(1) NOT NULL DEFAULT '1' AFTER id_salle;
UPDATE formateurs f JOIN users u ON u.id = f.user_id
SET f.nom = u.nom, f.prenom = u.prenom, f.password = u.password, f.telephone = u.telephone, f.email = u.email, f.actif = u.actif;
ALTER TABLE formateurs
  ALTER COLUMN nom DROP DEFAULT, ALTER COLUMN prenom DROP DEFAULT, ALTER COLUMN password DROP DEFAULT,
  ADD UNIQUE KEY uk_formateurs_email (email),
  ADD KEY idx_formateurs_user (user_id),
  ADD CONSTRAINT fk_formateurs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE;
