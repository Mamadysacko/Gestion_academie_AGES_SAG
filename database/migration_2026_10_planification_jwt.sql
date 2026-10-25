-- =====================================================================
--  Migration 2026-10 : authentification JWT + planification automatique
--  Base : academie_langues3 (MySQL 8)
--
--  À exécuter UNE SEULE FOIS (sauvegarde préalable recommandée) :
--    mysql -uroot -p academie_langues3 < migration_2026_10_planification_jwt.sql
--  ou : php database/apply_migration.php migration_2026_10_planification_jwt.sql
--
--  Aucune donnée existante n'est supprimée :
--    - les colonnes ajoutées ont des valeurs par défaut et sont remplies
--      à partir des données déjà saisies (groupes, créneaux, sessions) ;
--    - les anciennes contraintes d'unicité des emplois du temps (une salle
--      par jour et par ANNÉE) sont remplacées par des contraintes par
--      SESSION et par HORAIRE : deux sessions successives de la même année
--      peuvent ainsi réutiliser les mêmes salles. Les chevauchements entre
--      sessions simultanées sont contrôlés par l'API (dates + horaires).
-- =====================================================================

SET NAMES utf8mb4;

-- ── 1. Sécurité : jetons de renouvellement (refresh tokens) ──────────────
ALTER TABLE `users`
  ADD COLUMN `token_version` int unsigned NOT NULL DEFAULT 0
    COMMENT 'Incrémenté pour invalider immédiatement tous les jetons d''accès du compte';

CREATE TABLE `refresh_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `famille` char(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Chaîne de rotation : un jeton réutilisé révoque toute la famille',
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SHA-256 du jeton (le jeton brut n''est jamais stocké)',
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `motif_revocation` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remplace_par` bigint unsigned DEFAULT NULL,
  `adresse_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_refresh_hash` (`token_hash`),
  KEY `idx_refresh_user` (`user_id`),
  KEY `idx_refresh_famille` (`famille`),
  KEY `idx_refresh_expiration` (`expires_at`),
  CONSTRAINT `fk_refresh_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Configuration de référence de chaque formation ───────────────────
--  passages_semaine  : nombre de venues de CHAQUE groupe par semaine
--  nb_groupes        : nombre de groupes ouverts par session
--  salles_par_groupe : nombre de salles occupées simultanément par un groupe
--  Trois paramètres distincts : 5 groupes × 2 passages × 5 salles = 50 occupations salle-jour.
ALTER TABLE `types_formations`
  ADD COLUMN `passages_semaine` tinyint unsigned NOT NULL DEFAULT 1,
  ADD COLUMN `nb_groupes` tinyint unsigned NOT NULL DEFAULT 1,
  ADD COLUMN `salles_par_groupe` tinyint unsigned NOT NULL DEFAULT 1,
  ADD COLUMN `jours_autorises` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1,2,3,4,5',
  ADD COLUMN `heure_debut` time NOT NULL DEFAULT '08:00:00',
  ADD COLUMN `heure_fin` time NOT NULL DEFAULT '12:00:00',
  ADD COLUMN `creneaux` json DEFAULT NULL COMMENT 'Plusieurs créneaux par jour : [{"debut":"08:00","fin":"12:00"}, …] ; NULL = heure_debut/heure_fin',
  ADD COLUMN `espacer_passages` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Préférer des jours non consécutifs pour les passages d''un groupe',
  ADD COLUMN `couleur` varchar(7) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ADD COLUMN `config_version` int unsigned NOT NULL DEFAULT 1,
  ADD COLUMN `config_updated_at` timestamp NULL DEFAULT NULL,
  ADD CONSTRAINT `chk_tf_passages` CHECK (`passages_semaine` BETWEEN 1 AND 5),
  ADD CONSTRAINT `chk_tf_groupes` CHECK (`nb_groupes` BETWEEN 1 AND 50),
  ADD CONSTRAINT `chk_tf_salles` CHECK (`salles_par_groupe` BETWEEN 1 AND 50),
  ADD CONSTRAINT `chk_tf_horaires` CHECK (`heure_fin` > `heure_debut`);

-- Historique versionné : chaque modification de la configuration est tracée (qui, quand, pourquoi).
CREATE TABLE `formation_config_historique` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_type_formation` int unsigned NOT NULL,
  `version` int unsigned NOT NULL,
  `configuration` json NOT NULL,
  `motif` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fch_version` (`id_type_formation`, `version`),
  CONSTRAINT `fk_fch_formation` FOREIGN KEY (`id_type_formation`) REFERENCES `types_formations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_fch_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Salles dédiées : une salle n'a qu'UNE formation propriétaire (mode « dediee »).
-- Une autre formation ne peut l'utiliser qu'avec une autorisation explicite (mode « partagee »).
CREATE TABLE `formation_salles` (
  `id_type_formation` int unsigned NOT NULL,
  `id_salle` int unsigned NOT NULL,
  `mode` enum('dediee','partagee') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dediee',
  `priorite` smallint unsigned NOT NULL DEFAULT 0,
  `motif` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `autorise_par` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `salle_dediee` int unsigned GENERATED ALWAYS AS ((case when (`mode` = _utf8mb4'dediee') then `id_salle` else NULL end)) STORED,
  PRIMARY KEY (`id_type_formation`, `id_salle`),
  UNIQUE KEY `uk_fs_proprietaire_unique` (`salle_dediee`),
  KEY `idx_fs_salle` (`id_salle`),
  CONSTRAINT `fk_fs_formation` FOREIGN KEY (`id_type_formation`) REFERENCES `types_formations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  -- RESTRICT imposé par MySQL (colonne de base d'une colonne générée) : l'API retire
  -- d'abord le rattachement lorsqu'une salle est supprimée.
  CONSTRAINT `fk_fs_salle` FOREIGN KEY (`id_salle`) REFERENCES `salles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_fs_user` FOREIGN KEY (`autorise_par`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Sessions : instantané de la configuration utilisée ───────────────
-- Modifier la formation ne réécrit jamais les sessions déjà créées.
ALTER TABLE `sessions`
  ADD COLUMN `id_session_source` int unsigned DEFAULT NULL COMMENT 'Session dont la configuration a été reprise',
  ADD COLUMN `passages_semaine` tinyint unsigned DEFAULT NULL,
  ADD COLUMN `config_version` int unsigned DEFAULT NULL,
  ADD COLUMN `config_formation` json DEFAULT NULL,
  ADD KEY `idx_sessions_source` (`id_session_source`),
  ADD CONSTRAINT `fk_sessions_source` FOREIGN KEY (`id_session_source`) REFERENCES `sessions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- ── 4. Passages hebdomadaires des groupes ───────────────────────────────
-- Un groupe vient N jours par semaine (un passage par jour au plus).
CREATE TABLE `groupe_passages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_groupe` int unsigned NOT NULL,
  `jour_semaine` tinyint unsigned NOT NULL,
  `heure_debut` time NOT NULL,
  `heure_fin` time NOT NULL,
  `ordre` tinyint unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_gp_groupe_jour` (`id_groupe`, `jour_semaine`),
  KEY `idx_gp_jour` (`jour_semaine`),
  CONSTRAINT `fk_gp_groupe` FOREIGN KEY (`id_groupe`) REFERENCES `groupes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_gp_jour` CHECK (`jour_semaine` BETWEEN 1 AND 5),
  CONSTRAINT `chk_gp_horaires` CHECK (`heure_fin` > `heure_debut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. Emplois du temps : session, horaires et origine du créneau ───────
ALTER TABLE `emplois_du_temps`
  ADD COLUMN `id_session` int unsigned DEFAULT NULL AFTER `id_annee`,
  ADD COLUMN `heure_debut` time DEFAULT NULL AFTER `jour_semaine`,
  ADD COLUMN `heure_fin` time DEFAULT NULL AFTER `heure_debut`,
  ADD COLUMN `origine` enum('manuelle','automatique','reprise') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manuelle',
  ADD COLUMN `id_generation` int unsigned DEFAULT NULL,
  ADD COLUMN `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

CREATE TABLE `planification_generations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_session` int unsigned NOT NULL,
  `mode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `statut` enum('appliquee','echec') COLLATE utf8mb4_unicode_ci NOT NULL,
  `parametres` json DEFAULT NULL,
  `resultat` json DEFAULT NULL,
  `nb_crees` int unsigned NOT NULL DEFAULT 0,
  `nb_supprimes` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pg_session` (`id_session`),
  CONSTRAINT `fk_pg_session` FOREIGN KEY (`id_session`) REFERENCES `sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pg_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. Reprise des données existantes ───────────────────────────────────
-- 6a. Créneaux : session et horaires du groupe.
UPDATE `emplois_du_temps` e
JOIN `groupes` g ON g.id = e.id_groupe
SET e.id_session = g.id_session, e.heure_debut = g.heure_debut, e.heure_fin = g.heure_fin;

ALTER TABLE `emplois_du_temps`
  MODIFY `id_session` int unsigned NOT NULL,
  MODIFY `heure_debut` time NOT NULL,
  MODIFY `heure_fin` time NOT NULL,
  DROP INDEX `uk_edt_salle_annee_jour`,
  DROP INDEX `uk_edt_formateur_annee_jour`,
  DROP INDEX `uk_edt_groupe_salle_jour`,
  ADD UNIQUE KEY `uk_edt_groupe_salle_jour` (`id_groupe`, `id_salle`, `jour_semaine`),
  ADD UNIQUE KEY `uk_edt_session_salle_creneau` (`id_session`, `id_salle`, `jour_semaine`, `heure_debut`),
  ADD UNIQUE KEY `uk_edt_session_formateur_creneau` (`id_session`, `id_formateur`, `jour_semaine`, `heure_debut`),
  ADD KEY `idx_edt_session` (`id_session`),
  ADD KEY `idx_edt_salle_jour` (`id_salle`, `jour_semaine`),
  ADD CONSTRAINT `fk_edt_session` FOREIGN KEY (`id_session`) REFERENCES `sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `chk_edt_horaires` CHECK (`heure_fin` > `heure_debut`);

-- 6b. Passages : le jour actuel de chaque groupe devient son premier passage.
INSERT INTO `groupe_passages` (`id_groupe`, `jour_semaine`, `heure_debut`, `heure_fin`, `ordre`)
SELECT g.id, g.jour_semaine, g.heure_debut, g.heure_fin, 1 FROM `groupes` g;

-- 6c. Configuration des formations déduite de l'existant :
--     nombre de groupes de la dernière session, salles par groupe observées, horaires usuels.
UPDATE `types_formations` tf
SET tf.nb_groupes = GREATEST(1, LEAST(50, COALESCE((
        SELECT COUNT(*) FROM groupes g JOIN sessions s ON s.id = g.id_session
        WHERE s.id_type_formation = tf.id
          AND s.id = (SELECT s2.id FROM sessions s2 WHERE s2.id_type_formation = tf.id ORDER BY s2.date_debut DESC, s2.id DESC LIMIT 1)
    ), 1))),
    tf.salles_par_groupe = GREATEST(1, LEAST(50, COALESCE((
        SELECT MAX(x.nb) FROM (
            SELECT s.id_type_formation AS id_tf, e.id_groupe, e.jour_semaine, COUNT(*) AS nb
            FROM emplois_du_temps e JOIN sessions s ON s.id = e.id_session
            GROUP BY s.id_type_formation, e.id_groupe, e.jour_semaine
        ) x WHERE x.id_tf = tf.id
    ), 1))),
    tf.heure_debut = COALESCE((
        SELECT g.heure_debut FROM groupes g JOIN sessions s ON s.id = g.id_session
        WHERE s.id_type_formation = tf.id GROUP BY g.heure_debut ORDER BY COUNT(*) DESC LIMIT 1
    ), tf.heure_debut),
    tf.heure_fin = COALESCE((
        SELECT g.heure_fin FROM groupes g JOIN sessions s ON s.id = g.id_session
        WHERE s.id_type_formation = tf.id GROUP BY g.heure_fin ORDER BY COUNT(*) DESC LIMIT 1
    ), tf.heure_fin),
    tf.config_updated_at = CURRENT_TIMESTAMP;

-- 6d. Salles déjà utilisées par une seule formation : elles lui deviennent dédiées.
INSERT INTO `formation_salles` (`id_type_formation`, `id_salle`, `mode`, `priorite`, `motif`)
SELECT u.id_tf, u.id_salle, 'dediee', 0, 'Reprise automatique des affectations existantes'
FROM (
    SELECT MIN(s.id_type_formation) AS id_tf, e.id_salle, COUNT(DISTINCT s.id_type_formation) AS nb_formations
    FROM emplois_du_temps e JOIN sessions s ON s.id = e.id_session
    GROUP BY e.id_salle
) u
WHERE u.nb_formations = 1;

-- 6e. Version 1 de chaque configuration + instantané dans les sessions existantes.
INSERT INTO `formation_config_historique` (`id_type_formation`, `version`, `configuration`, `motif`)
SELECT tf.id, 1, JSON_OBJECT(
    'passages_semaine', tf.passages_semaine, 'nb_groupes', tf.nb_groupes,
    'salles_par_groupe', tf.salles_par_groupe, 'jours_autorises', tf.jours_autorises,
    'heure_debut', TIME_FORMAT(tf.heure_debut, '%H:%i'), 'heure_fin', TIME_FORMAT(tf.heure_fin, '%H:%i'),
    'creneaux', tf.creneaux, 'espacer_passages', tf.espacer_passages
), 'Configuration initiale reprise de l''existant'
FROM `types_formations` tf;

UPDATE `sessions` s
JOIN `formation_config_historique` h ON h.id_type_formation = s.id_type_formation AND h.version = 1
JOIN `types_formations` tf ON tf.id = s.id_type_formation
SET s.config_version = 1, s.config_formation = h.configuration, s.passages_semaine = tf.passages_semaine;
