-- =====================================================================
--  Migration 2026-10 (2) : évaluations, appréciations, passages, horaires
--  Base : academie_langues3 (MySQL 8) — à exécuter UNE SEULE FOIS
--  après migration_2026_10_inscriptions_salles.sql.
--
--  1. niveaux.ordre              : ordre de progression (N1 → N2 → …)
--  2. auditeurs.id_niveau        : niveau actuel de l'auditeur
--  3. evaluations.numero         : évaluation 1, 2 ou 3 de la session
--  4. regles_evaluation          : règles de passage configurables
--  5. baremes_appreciation       : échelle d'appréciation 1 à 4
--  6. passages_niveaux           : appréciation, taux de présence, mode de validation
--  7. groupes.heure_debut / fin  : horaires affichés dans le calendrier
-- =====================================================================

SET NAMES utf8mb4;

-- 1. Ordre des niveaux --------------------------------------------------
ALTER TABLE `niveaux`
  ADD COLUMN `ordre` int unsigned NOT NULL DEFAULT 0 AFTER `libelle`;
UPDATE `niveaux` SET `ordre` = `id`;

-- 2. Niveau actuel de chaque auditeur ----------------------------------
ALTER TABLE `auditeurs`
  ADD COLUMN `id_niveau` int unsigned DEFAULT NULL AFTER `id_type_employe`,
  ADD KEY `idx_auditeurs_niveau` (`id_niveau`),
  ADD CONSTRAINT `fk_auditeurs_niveau` FOREIGN KEY (`id_niveau`)
      REFERENCES `niveaux` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

UPDATE `auditeurs` a
SET a.id_niveau = (
  SELECT s.id_niveau
  FROM inscriptions i
  JOIN sessions s ON s.id = i.id_session
  WHERE i.id_auditeur = a.id
  ORDER BY i.date_inscription DESC, i.id DESC
  LIMIT 1
);

-- 3. Numéro d'évaluation dans la session -------------------------------
ALTER TABLE `evaluations`
  ADD COLUMN `numero` tinyint unsigned DEFAULT NULL AFTER `id_session`;

UPDATE `evaluations` e
JOIN (
  SELECT id, ROW_NUMBER() OVER (PARTITION BY id_session ORDER BY date_evaluation, id) AS rn
  FROM evaluations
) x ON x.id = e.id
SET e.numero = x.rn;

ALTER TABLE `evaluations`
  MODIFY `numero` tinyint unsigned NOT NULL,
  ADD UNIQUE KEY `uk_evaluations_session_numero` (`id_session`, `numero`),
  ADD CONSTRAINT `chk_evaluations_numero` CHECK (`numero` BETWEEN 1 AND 9);

-- 4. Règles de passage (une seule ligne, id = 1) -----------------------
CREATE TABLE IF NOT EXISTS `regles_evaluation` (
  `id` tinyint unsigned NOT NULL DEFAULT 1,
  `nb_evaluations` tinyint unsigned NOT NULL DEFAULT 3,
  `moyenne_admission` decimal(4,2) NOT NULL DEFAULT 2.00,
  `taux_presence_min` decimal(5,2) NOT NULL DEFAULT 0.00,
  `passage_automatique` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_regles_id` CHECK (`id` = 1),
  CONSTRAINT `chk_regles_nb` CHECK (`nb_evaluations` BETWEEN 1 AND 9),
  CONSTRAINT `chk_regles_moyenne` CHECK (`moyenne_admission` BETWEEN 0 AND 4),
  CONSTRAINT `chk_regles_presence` CHECK (`taux_presence_min` BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `regles_evaluation` (`id`) VALUES (1);

-- 5. Barème d'appréciation (note 1 à 4 selon la moyenne sur 4) ----------
CREATE TABLE IF NOT EXISTS `baremes_appreciation` (
  `note` tinyint unsigned NOT NULL,
  `libelle` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `seuil_min` decimal(4,2) NOT NULL,
  PRIMARY KEY (`note`),
  CONSTRAINT `chk_bareme_note` CHECK (`note` BETWEEN 1 AND 4),
  CONSTRAINT `chk_bareme_seuil` CHECK (`seuil_min` BETWEEN 0 AND 4)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `baremes_appreciation` (`note`, `libelle`, `seuil_min`) VALUES
  (1, 'Insuffisant', 0.00),
  (2, 'Passable', 2.00),
  (3, 'Bien', 2.75),
  (4, 'Très bien', 3.50);

-- 6. Historique des passages enrichi ------------------------------------
ALTER TABLE `passages_niveaux`
  ADD COLUMN `appreciation` tinyint unsigned DEFAULT NULL AFTER `moyenne`,
  ADD COLUMN `taux_presence` decimal(5,2) DEFAULT NULL AFTER `appreciation`,
  ADD COLUMN `mode_validation` enum('automatique','manuelle') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manuelle' AFTER `observation`;

-- 7. Horaires des groupes ------------------------------------------------
ALTER TABLE `groupes`
  ADD COLUMN `heure_debut` time NOT NULL DEFAULT '08:00:00' AFTER `jour_semaine`,
  ADD COLUMN `heure_fin` time NOT NULL DEFAULT '12:00:00' AFTER `heure_debut`,
  ADD CONSTRAINT `chk_groupes_horaires` CHECK (`heure_fin` > `heure_debut`);
