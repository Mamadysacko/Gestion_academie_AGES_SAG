-- =====================================================================
--  Migration 2026-10 : décision de passage « termine » (fin de formation)
--  Base : academie_langues3 (MySQL 8)
--
--  À exécuter UNE SEULE FOIS :
--    mysql -uroot -p academie_langues3 < migration_2026_10_passages_termine.sql
--
--  Le passage de niveau est approuvé à la main avec trois choix :
--    admis    = passe en classe supérieure
--    redouble = redouble la classe
--    termine  = termine la formation (aucun niveau d'arrivée)
-- =====================================================================

ALTER TABLE `passages_niveaux`
  MODIFY `decision` enum('admis','non_admis','redouble','abandonne','termine')
  COLLATE utf8mb4_unicode_ci NOT NULL;
