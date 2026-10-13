-- =====================================================================
--  Migration 2026-10 (3) : journal des opérations (traçabilité)
--  Base : academie_langues3 (MySQL 8) — à exécuter UNE SEULE FOIS.
--
--  Chaque création, modification, suppression, validation et connexion
--  envoyée à l'API est enregistrée (utilisateur, action, ressource, résultat).
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `journal_operations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `utilisateur` varchar(210) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role_code` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `methode` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ressource` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ressource_id` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chemin` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code_http` smallint unsigned NOT NULL,
  `succes` tinyint(1) NOT NULL,
  `message` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `donnees` text COLLATE utf8mb4_unicode_ci,
  `adresse_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_journal_date` (`created_at`),
  KEY `idx_journal_user` (`user_id`),
  KEY `idx_journal_ressource` (`ressource`),
  CONSTRAINT `fk_journal_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
