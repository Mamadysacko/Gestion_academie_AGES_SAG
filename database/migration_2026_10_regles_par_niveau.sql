-- =====================================================================
--  Migration 2026-10 (4) : règles de passage par niveau
--  Base : academie_langues3 (MySQL 8) — à exécuter UNE SEULE FOIS.
--
--  regles_evaluation devient une table de règles : la ligne id = 1 est la
--  « règle générale » (id_niveau NULL, non supprimable) ; une ligne par niveau
--  peut la remplacer pour les sessions de ce niveau.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `regles_evaluation`
  DROP CHECK `chk_regles_id`;

ALTER TABLE `regles_evaluation`
  MODIFY `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  ADD COLUMN `libelle` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Règle générale' AFTER `id`,
  ADD COLUMN `id_niveau` int unsigned DEFAULT NULL AFTER `libelle`,
  ADD UNIQUE KEY `uk_regles_niveau` (`id_niveau`),
  ADD CONSTRAINT `fk_regles_niveau` FOREIGN KEY (`id_niveau`)
      REFERENCES `niveaux` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
