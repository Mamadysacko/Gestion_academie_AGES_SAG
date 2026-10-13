-- =====================================================================
--  Migration 2026-10 : inscriptions par année / session, types d'employés
--  Base : academie_langues3 (MySQL 8)
--
--  À exécuter UNE SEULE FOIS :
--    mysql -uroot -p academie_langues3 < migration_2026_10_inscriptions_salles.sql
--
--  1. Table types_employes + rattachement de chaque auditeur (position, type)
--  2. Inscriptions organisées par année et par session (id_annee, id_session)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Types d'employés
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `types_employes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_types_employes_libelle` (`libelle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `types_employes` (`libelle`) VALUES
  ('Fonctionnaire'),
  ('Contractuel'),
  ('Stagiaire');

ALTER TABLE `auditeurs`
  ADD COLUMN `position` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `email`,
  ADD COLUMN `id_type_employe` int unsigned DEFAULT NULL AFTER `position`,
  ADD KEY `idx_auditeurs_type_employe` (`id_type_employe`),
  ADD CONSTRAINT `fk_auditeurs_type_employe` FOREIGN KEY (`id_type_employe`)
      REFERENCES `types_employes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- ---------------------------------------------------------------------
-- 2. Inscriptions rattachées explicitement à l'année et à la session
--    (renseignées automatiquement par l'API à partir du créneau choisi)
-- ---------------------------------------------------------------------
ALTER TABLE `inscriptions`
  ADD COLUMN `id_annee` int unsigned DEFAULT NULL AFTER `id_auditeur`,
  ADD COLUMN `id_session` int unsigned DEFAULT NULL AFTER `id_annee`;

UPDATE `inscriptions` i
JOIN `emplois_du_temps` e ON e.id = i.id_emploi_du_temps
JOIN `groupes` g ON g.id = e.id_groupe
SET i.id_annee = e.id_annee,
    i.id_session = g.id_session;

ALTER TABLE `inscriptions`
  MODIFY `id_annee` int unsigned NOT NULL,
  MODIFY `id_session` int unsigned NOT NULL,
  ADD KEY `idx_inscriptions_annee` (`id_annee`),
  ADD KEY `idx_inscriptions_session` (`id_session`),
  ADD CONSTRAINT `fk_inscriptions_annee` FOREIGN KEY (`id_annee`)
      REFERENCES `annees_scolaires` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_inscriptions_session` FOREIGN KEY (`id_session`)
      REFERENCES `sessions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
