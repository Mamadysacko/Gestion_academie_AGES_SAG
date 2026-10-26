-- =====================================================================
--  Structure de la base academie_langues3 (MySQL 8) — générée le 2026-10-25 19:32
--  Schéma seul (sans données). Lu par tools/featuresPlugin.js (centre d'aide).
--  Pour une nouvelle installation :  mysql -uroot -p < "DB Gestion Academie.sql"
--  Régénération : php database/export_schema.php
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

--
-- Table structure for table `annees_scolaires`
--

DROP TABLE IF EXISTS `annees_scolaires`;
CREATE TABLE `annees_scolaires` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date NOT NULL,
  `statut` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_annees_libelle` (`libelle`),
  CONSTRAINT `chk_annees_dates` CHECK ((`date_fin` > `date_debut`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `auditeurs`
--

DROP TABLE IF EXISTS `auditeurs`;
CREATE TABLE `auditeurs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `matricule` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sexe` enum('M','F') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telephone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_position` int unsigned DEFAULT NULL,
  `id_type_employe` int unsigned DEFAULT NULL,
  `id_niveau` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_auditeurs_matricule` (`matricule`),
  KEY `idx_auditeurs_type_employe` (`id_type_employe`),
  KEY `idx_auditeurs_niveau` (`id_niveau`),
  KEY `idx_auditeurs_position` (`id_position`),
  CONSTRAINT `fk_auditeurs_niveau` FOREIGN KEY (`id_niveau`) REFERENCES `niveaux` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_auditeurs_position` FOREIGN KEY (`id_position`) REFERENCES `positions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_auditeurs_type_employe` FOREIGN KEY (`id_type_employe`) REFERENCES `types_employes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `baremes_appreciation`
--

DROP TABLE IF EXISTS `baremes_appreciation`;
CREATE TABLE `baremes_appreciation` (
  `note` tinyint unsigned NOT NULL,
  `libelle` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `seuil_min` decimal(4,2) NOT NULL,
  PRIMARY KEY (`note`),
  CONSTRAINT `chk_bareme_note` CHECK ((`note` between 1 and 4)),
  CONSTRAINT `chk_bareme_seuil` CHECK ((`seuil_min` between 0 and 4))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `departements`
--

DROP TABLE IF EXISTS `departements`;
CREATE TABLE `departements` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `libelle` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_departements_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `emplois_du_temps`
--

DROP TABLE IF EXISTS `emplois_du_temps`;
CREATE TABLE `emplois_du_temps` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_annee` int unsigned NOT NULL,
  `id_session` int unsigned NOT NULL,
  `id_groupe` int unsigned NOT NULL,
  `id_salle` int unsigned NOT NULL,
  `id_formateur` int unsigned NOT NULL,
  `jour_semaine` tinyint unsigned NOT NULL,
  `heure_debut` time NOT NULL,
  `heure_fin` time NOT NULL,
  `statut` tinyint(1) NOT NULL DEFAULT '1',
  `origine` enum('manuelle','automatique','reprise') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manuelle',
  `id_generation` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_edt_groupe_salle_jour` (`id_groupe`,`id_salle`,`jour_semaine`),
  UNIQUE KEY `uk_edt_session_salle_creneau` (`id_session`,`id_salle`,`jour_semaine`,`heure_debut`),
  UNIQUE KEY `uk_edt_session_formateur_creneau` (`id_session`,`id_formateur`,`jour_semaine`,`heure_debut`),
  KEY `idx_edt_annee` (`id_annee`),
  KEY `idx_edt_formateur` (`id_formateur`),
  KEY `idx_edt_jour` (`jour_semaine`),
  KEY `idx_edt_salle_jour` (`id_salle`,`jour_semaine`),
  KEY `idx_edt_generation` (`id_generation`),
  CONSTRAINT `fk_edt_annee` FOREIGN KEY (`id_annee`) REFERENCES `annees_scolaires` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_edt_formateur` FOREIGN KEY (`id_formateur`) REFERENCES `formateurs` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_edt_generation` FOREIGN KEY (`id_generation`) REFERENCES `planification_generations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_edt_groupe` FOREIGN KEY (`id_groupe`) REFERENCES `groupes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_edt_salle` FOREIGN KEY (`id_salle`) REFERENCES `salles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_edt_session` FOREIGN KEY (`id_session`) REFERENCES `sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_edt_horaires` CHECK ((`heure_fin` > `heure_debut`)),
  CONSTRAINT `chk_edt_jour` CHECK ((`jour_semaine` between 1 and 5))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `evaluation_parametres`
--

DROP TABLE IF EXISTS `evaluation_parametres`;
CREATE TABLE `evaluation_parametres` (
  `id_evaluation` int unsigned NOT NULL,
  `id_parametre` int unsigned NOT NULL,
  `ordre` int unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`id_evaluation`,`id_parametre`),
  KEY `fk_evaluation_parametres_parametre` (`id_parametre`),
  CONSTRAINT `fk_evaluation_parametres_evaluation` FOREIGN KEY (`id_evaluation`) REFERENCES `evaluations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_evaluation_parametres_parametre` FOREIGN KEY (`id_parametre`) REFERENCES `parametres` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `evaluations`
--

DROP TABLE IF EXISTS `evaluations`;
CREATE TABLE `evaluations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_session` int unsigned NOT NULL,
  `numero` tinyint unsigned NOT NULL,
  `libelle` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_evaluation` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_evaluations_session_numero` (`id_session`,`numero`),
  CONSTRAINT `fk_evaluations_session` FOREIGN KEY (`id_session`) REFERENCES `sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_evaluations_numero` CHECK ((`numero` between 1 and 9))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `formateurs`
--

DROP TABLE IF EXISTS `formateurs`;
CREATE TABLE `formateurs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `matricule` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_salle` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_formateurs_matricule` (`matricule`),
  UNIQUE KEY `uk_formateurs_user` (`user_id`),
  UNIQUE KEY `uk_formateurs_salle` (`id_salle`),
  CONSTRAINT `fk_formateurs_salle` FOREIGN KEY (`id_salle`) REFERENCES `salles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_formateurs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `formation_config_historique`
--

DROP TABLE IF EXISTS `formation_config_historique`;
CREATE TABLE `formation_config_historique` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_type_formation` int unsigned NOT NULL,
  `version` int unsigned NOT NULL,
  `configuration` json NOT NULL,
  `motif` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fch_version` (`id_type_formation`,`version`),
  KEY `fk_fch_user` (`user_id`),
  CONSTRAINT `fk_fch_formation` FOREIGN KEY (`id_type_formation`) REFERENCES `types_formations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_fch_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `formation_salles`
--

DROP TABLE IF EXISTS `formation_salles`;
CREATE TABLE `formation_salles` (
  `id_type_formation` int unsigned NOT NULL,
  `id_salle` int unsigned NOT NULL,
  `mode` enum('dediee','partagee') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dediee',
  `priorite` smallint unsigned NOT NULL DEFAULT '0',
  `motif` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `autorise_par` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `salle_dediee` int unsigned GENERATED ALWAYS AS ((case when (`mode` = _utf8mb4'dediee') then `id_salle` else NULL end)) STORED,
  PRIMARY KEY (`id_type_formation`,`id_salle`),
  UNIQUE KEY `uk_fs_proprietaire_unique` (`salle_dediee`),
  KEY `idx_fs_salle` (`id_salle`),
  KEY `fk_fs_user` (`autorise_par`),
  CONSTRAINT `fk_fs_formation` FOREIGN KEY (`id_type_formation`) REFERENCES `types_formations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_fs_salle` FOREIGN KEY (`id_salle`) REFERENCES `salles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_fs_user` FOREIGN KEY (`autorise_par`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `groupe_passages`
--

DROP TABLE IF EXISTS `groupe_passages`;
CREATE TABLE `groupe_passages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_groupe` int unsigned NOT NULL,
  `jour_semaine` tinyint unsigned NOT NULL,
  `heure_debut` time NOT NULL,
  `heure_fin` time NOT NULL,
  `ordre` tinyint unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_gp_groupe_jour` (`id_groupe`,`jour_semaine`),
  KEY `idx_gp_jour` (`jour_semaine`),
  CONSTRAINT `fk_gp_groupe` FOREIGN KEY (`id_groupe`) REFERENCES `groupes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_gp_horaires` CHECK ((`heure_fin` > `heure_debut`)),
  CONSTRAINT `chk_gp_jour` CHECK ((`jour_semaine` between 1 and 5))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `groupes`
--

DROP TABLE IF EXISTS `groupes`;
CREATE TABLE `groupes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_session` int unsigned NOT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `libelle` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `jour_semaine` tinyint unsigned NOT NULL,
  `heure_debut` time NOT NULL DEFAULT '08:00:00',
  `heure_fin` time NOT NULL DEFAULT '12:00:00',
  `capacite` int unsigned DEFAULT NULL,
  `statut` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_groupes_session_code` (`id_session`,`code`),
  KEY `idx_groupes_jour` (`jour_semaine`),
  CONSTRAINT `fk_groupes_session` FOREIGN KEY (`id_session`) REFERENCES `sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_groupes_capacite` CHECK (((`capacite` is null) or (`capacite` > 0))),
  CONSTRAINT `chk_groupes_horaires` CHECK ((`heure_fin` > `heure_debut`)),
  CONSTRAINT `chk_groupes_jour` CHECK ((`jour_semaine` between 1 and 5))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `inscriptions`
--

DROP TABLE IF EXISTS `inscriptions`;
CREATE TABLE `inscriptions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_auditeur` int unsigned NOT NULL,
  `id_annee` int unsigned NOT NULL,
  `id_session` int unsigned NOT NULL,
  `id_emploi_du_temps` int unsigned NOT NULL,
  `id_departement` int unsigned NOT NULL,
  `date_inscription` date NOT NULL,
  `statut` enum('active','terminee','abandonnee','annulee') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_inscription_auditeur_edt` (`id_auditeur`,`id_emploi_du_temps`),
  KEY `idx_inscriptions_departement` (`id_departement`),
  KEY `idx_inscriptions_annee` (`id_annee`),
  KEY `idx_inscriptions_session` (`id_session`),
  KEY `idx_inscriptions_edt_statut` (`id_emploi_du_temps`,`statut`),
  CONSTRAINT `fk_inscriptions_annee` FOREIGN KEY (`id_annee`) REFERENCES `annees_scolaires` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_inscriptions_auditeur` FOREIGN KEY (`id_auditeur`) REFERENCES `auditeurs` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_inscriptions_departement` FOREIGN KEY (`id_departement`) REFERENCES `departements` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_inscriptions_edt` FOREIGN KEY (`id_emploi_du_temps`) REFERENCES `emplois_du_temps` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_inscriptions_session` FOREIGN KEY (`id_session`) REFERENCES `sessions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `journal_operations`
--

DROP TABLE IF EXISTS `journal_operations`;
CREATE TABLE `journal_operations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `utilisateur` varchar(210) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role_code` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `methode` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ressource` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ressource_id` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chemin` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `code_http` smallint unsigned NOT NULL,
  `succes` tinyint(1) NOT NULL,
  `message` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `donnees` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `adresse_ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_journal_date` (`created_at`),
  KEY `idx_journal_user` (`user_id`),
  KEY `idx_journal_ressource` (`ressource`),
  CONSTRAINT `fk_journal_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `niveaux`
--

DROP TABLE IF EXISTS `niveaux`;
CREATE TABLE `niveaux` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ordre` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_niveaux_libelle` (`libelle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `notes`
--

DROP TABLE IF EXISTS `notes`;
CREATE TABLE `notes` (
  `id_note` int unsigned NOT NULL AUTO_INCREMENT,
  `id_evaluation` int unsigned NOT NULL,
  `id_parametre` int unsigned NOT NULL,
  `id_inscription` int unsigned NOT NULL,
  `note` decimal(5,2) DEFAULT NULL,
  `observation` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id_note`),
  UNIQUE KEY `uk_note` (`id_evaluation`,`id_parametre`,`id_inscription`),
  KEY `fk_notes_parametre` (`id_parametre`),
  KEY `fk_notes_inscription` (`id_inscription`),
  CONSTRAINT `fk_notes_evaluation` FOREIGN KEY (`id_evaluation`) REFERENCES `evaluations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_notes_inscription` FOREIGN KEY (`id_inscription`) REFERENCES `inscriptions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_notes_parametre` FOREIGN KEY (`id_parametre`) REFERENCES `parametres` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `parametres`
--

DROP TABLE IF EXISTS `parametres`;
CREATE TABLE `parametres` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bareme` decimal(5,2) NOT NULL DEFAULT '4.00',
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_parametre_bareme` CHECK ((`bareme` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `passages_niveaux`
--

DROP TABLE IF EXISTS `passages_niveaux`;
CREATE TABLE `passages_niveaux` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_inscription` int unsigned NOT NULL,
  `niveau_depart` int unsigned NOT NULL,
  `niveau_arrivee` int unsigned DEFAULT NULL,
  `decision` enum('admis','non_admis','redouble','abandonne','termine') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `moyenne` decimal(5,2) DEFAULT NULL,
  `appreciation` tinyint unsigned DEFAULT NULL,
  `taux_presence` decimal(5,2) DEFAULT NULL,
  `date_decision` date NOT NULL,
  `observation` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mode_validation` enum('automatique','manuelle') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manuelle',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_passage_inscription` (`id_inscription`),
  KEY `idx_passage_niveau_depart` (`niveau_depart`),
  KEY `idx_passage_niveau_arrivee` (`niveau_arrivee`),
  CONSTRAINT `fk_passage_inscription` FOREIGN KEY (`id_inscription`) REFERENCES `inscriptions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_passage_niveau_arrivee` FOREIGN KEY (`niveau_arrivee`) REFERENCES `niveaux` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_passage_niveau_depart` FOREIGN KEY (`niveau_depart`) REFERENCES `niveaux` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `planification_generations`
--

DROP TABLE IF EXISTS `planification_generations`;
CREATE TABLE `planification_generations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_session` int unsigned NOT NULL,
  `mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `statut` enum('appliquee','echec') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `parametres` json DEFAULT NULL,
  `resultat` json DEFAULT NULL,
  `nb_crees` int unsigned NOT NULL DEFAULT '0',
  `nb_supprimes` int unsigned NOT NULL DEFAULT '0',
  `user_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pg_session` (`id_session`),
  KEY `fk_pg_user` (`user_id`),
  CONSTRAINT `fk_pg_session` FOREIGN KEY (`id_session`) REFERENCES `sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pg_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `positions`
--

DROP TABLE IF EXISTS `positions`;
CREATE TABLE `positions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_positions_libelle` (`libelle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `presences`
--

DROP TABLE IF EXISTS `presences`;
CREATE TABLE `presences` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_inscription` int unsigned NOT NULL,
  `id_emploi_du_temps` int unsigned DEFAULT NULL,
  `id_formateur` int unsigned NOT NULL,
  `date_presence` date NOT NULL,
  `statut` enum('present','absent','permissionnaire','malade','conge') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'present',
  `conge_date_debut` date DEFAULT NULL,
  `conge_date_fin` date DEFAULT NULL,
  `observation` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_presence_inscription_date` (`id_inscription`,`date_presence`),
  KEY `idx_presences_formateur` (`id_formateur`),
  KEY `idx_presences_date` (`date_presence`),
  KEY `idx_presences_edt_date` (`id_emploi_du_temps`,`date_presence`),
  CONSTRAINT `fk_presences_edt` FOREIGN KEY (`id_emploi_du_temps`) REFERENCES `emplois_du_temps` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_presences_formateur` FOREIGN KEY (`id_formateur`) REFERENCES `formateurs` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_presences_inscription` FOREIGN KEY (`id_inscription`) REFERENCES `inscriptions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_presences_conge_dates` CHECK (((`conge_date_fin` is null) or (`conge_date_debut` is null) or (`conge_date_fin` >= `conge_date_debut`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `refresh_tokens`
--

DROP TABLE IF EXISTS `refresh_tokens`;
CREATE TABLE `refresh_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `famille` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Chaîne de rotation : un jeton réutilisé révoque toute la famille',
  `token_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SHA-256 du jeton (le jeton brut n''est jamais stocké)',
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at` datetime DEFAULT NULL,
  `motif_revocation` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remplace_par` bigint unsigned DEFAULT NULL,
  `adresse_ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_refresh_hash` (`token_hash`),
  KEY `idx_refresh_user` (`user_id`),
  KEY `idx_refresh_famille` (`famille`),
  KEY `idx_refresh_expiration` (`expires_at`),
  KEY `idx_refresh_remplace` (`remplace_par`),
  CONSTRAINT `fk_refresh_remplace` FOREIGN KEY (`remplace_par`) REFERENCES `refresh_tokens` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_refresh_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `regles_evaluation`
--

DROP TABLE IF EXISTS `regles_evaluation`;
CREATE TABLE `regles_evaluation` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Règle générale',
  `id_niveau` int unsigned DEFAULT NULL,
  `nb_evaluations` tinyint unsigned NOT NULL DEFAULT '3',
  `moyenne_admission` decimal(4,2) NOT NULL DEFAULT '2.00',
  `taux_presence_min` decimal(5,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_regles_niveau` (`id_niveau`),
  CONSTRAINT `fk_regles_niveau` FOREIGN KEY (`id_niveau`) REFERENCES `niveaux` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_regles_moyenne` CHECK ((`moyenne_admission` between 0 and 4)),
  CONSTRAINT `chk_regles_nb` CHECK ((`nb_evaluations` between 1 and 9)),
  CONSTRAINT `chk_regles_presence` CHECK ((`taux_presence_min` between 0 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
  `role_id` int unsigned NOT NULL,
  `module_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`role_id`,`module_code`,`action_code`),
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nom` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_roles_code` (`code`),
  UNIQUE KEY `uk_roles_nom` (`nom`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `salles`
--

DROP TABLE IF EXISTS `salles`;
CREATE TABLE `salles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `libelle` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `capacite` int unsigned DEFAULT NULL,
  `statut` enum('disponible','indisponible') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'disponible',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_salles_code` (`code`),
  CONSTRAINT `chk_salles_capacite` CHECK (((`capacite` is null) or (`capacite` > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `schema_migrations`
--

DROP TABLE IF EXISTS `schema_migrations`;
CREATE TABLE `schema_migrations` (
  `nom` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`nom`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
CREATE TABLE `sessions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_annee` int unsigned NOT NULL,
  `id_type_formation` int unsigned NOT NULL,
  `id_niveau` int unsigned NOT NULL,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `libelle` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date NOT NULL,
  `statut` enum('planifiee','ouverte','en_cours','terminee','annulee') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'planifiee',
  `id_session_source` int unsigned DEFAULT NULL COMMENT 'Session dont la configuration a été reprise',
  `passages_semaine` tinyint unsigned GENERATED ALWAYS AS (cast(json_unquote(json_extract(`config_formation`,_utf8mb4'$.passages_semaine')) as unsigned)) STORED,
  `config_version` int unsigned DEFAULT NULL,
  `config_formation` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sessions_code` (`code`),
  UNIQUE KEY `uk_sessions_annee_type_libelle` (`id_annee`,`id_type_formation`,`libelle`),
  KEY `idx_sessions_type` (`id_type_formation`),
  KEY `idx_sessions_niveau` (`id_niveau`),
  KEY `idx_sessions_source` (`id_session_source`),
  CONSTRAINT `fk_sessions_annee` FOREIGN KEY (`id_annee`) REFERENCES `annees_scolaires` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_sessions_niveau` FOREIGN KEY (`id_niveau`) REFERENCES `niveaux` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_sessions_source` FOREIGN KEY (`id_session_source`) REFERENCES `sessions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sessions_type` FOREIGN KEY (`id_type_formation`) REFERENCES `types_formations` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_sessions_dates` CHECK ((`date_fin` >= `date_debut`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `types_employes`
--

DROP TABLE IF EXISTS `types_employes`;
CREATE TABLE `types_employes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_types_employes_libelle` (`libelle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `types_formations`
--

DROP TABLE IF EXISTS `types_formations`;
CREATE TABLE `types_formations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `passages_semaine` tinyint unsigned NOT NULL DEFAULT '1',
  `nb_groupes` tinyint unsigned NOT NULL DEFAULT '1',
  `salles_par_groupe` tinyint unsigned NOT NULL DEFAULT '1',
  `jours_autorises` set('1','2','3','4','5') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1,2,3,4,5',
  `heure_debut` time NOT NULL DEFAULT '08:00:00',
  `heure_fin` time NOT NULL DEFAULT '12:00:00',
  `creneaux` json DEFAULT NULL COMMENT 'Plusieurs créneaux par jour : [{"debut":"08:00","fin":"12:00"}, …] ; NULL = heure_debut/heure_fin',
  `espacer_passages` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Préférer des jours non consécutifs pour les passages d''un groupe',
  `couleur` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `config_version` int unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_types_formations_libelle` (`libelle`),
  CONSTRAINT `chk_tf_groupes` CHECK ((`nb_groupes` between 1 and 50)),
  CONSTRAINT `chk_tf_horaires` CHECK ((`heure_fin` > `heure_debut`)),
  CONSTRAINT `chk_tf_passages` CHECK ((`passages_semaine` between 1 and 5)),
  CONSTRAINT `chk_tf_salles` CHECK ((`salles_par_groupe` between 1 and 50))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(180) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telephone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telephone_normalise` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (nullif(replace(replace(replace(replace(`telephone`,_utf8mb4' ',_utf8mb4''),_utf8mb4'-',_utf8mb4''),_utf8mb4'.',_utf8mb4''),_utf8mb4'+',_utf8mb4''),_utf8mb4'')) STORED,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role_id` int unsigned NOT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `token_version` int unsigned NOT NULL DEFAULT '0' COMMENT 'Incrémenté pour invalider immédiatement tous les jetons d''accès du compte',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_email` (`email`),
  UNIQUE KEY `uk_users_telephone` (`telephone_normalise`),
  KEY `idx_users_role` (`role_id`),
  KEY `idx_users_actif` (`actif`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- View structure for view `v_formateurs`
--

DROP VIEW IF EXISTS `v_formateurs`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_formateurs` AS select `f`.`id` AS `id`,`f`.`user_id` AS `user_id`,`f`.`matricule` AS `matricule`,`u`.`nom` AS `nom`,`u`.`prenom` AS `prenom`,`u`.`telephone` AS `telephone`,`u`.`email` AS `email`,`f`.`id_salle` AS `id_salle`,`u`.`actif` AS `actif`,`f`.`created_at` AS `created_at`,`f`.`updated_at` AS `updated_at` from (`formateurs` `f` join `users` `u` on((`u`.`id` = `f`.`user_id`)));

--
-- Déclencheurs de cohérence
--

DELIMITER $$

DROP TRIGGER IF EXISTS `trg_annee_dates_upd`$$
CREATE TRIGGER `trg_annee_dates_upd` BEFORE UPDATE ON `annees_scolaires` FOR EACH ROW BEGIN
  IF EXISTS (SELECT 1 FROM sessions s WHERE s.id_annee = NEW.id AND (s.date_debut < NEW.date_debut OR s.date_fin > NEW.date_fin)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Une session de cette année sortirait de ses nouvelles dates : modifiez d’abord la session.';
  END IF;
END$$

DROP TRIGGER IF EXISTS `trg_edt_coherence_ins`$$
CREATE TRIGGER `trg_edt_coherence_ins` BEFORE INSERT ON `emplois_du_temps` FOR EACH ROW BEGIN
  SET NEW.id_session = (SELECT g.id_session FROM groupes g WHERE g.id = NEW.id_groupe);
  SET NEW.id_annee = (SELECT s.id_annee FROM sessions s WHERE s.id = NEW.id_session);
END$$

DROP TRIGGER IF EXISTS `trg_edt_coherence_upd`$$
CREATE TRIGGER `trg_edt_coherence_upd` BEFORE UPDATE ON `emplois_du_temps` FOR EACH ROW BEGIN
  SET NEW.id_session = (SELECT g.id_session FROM groupes g WHERE g.id = NEW.id_groupe);
  SET NEW.id_annee = (SELECT s.id_annee FROM sessions s WHERE s.id = NEW.id_session);
END$$

DROP TRIGGER IF EXISTS `trg_evaluation_date_ins`$$
CREATE TRIGGER `trg_evaluation_date_ins` BEFORE INSERT ON `evaluations` FOR EACH ROW BEGIN
  IF EXISTS (SELECT 1 FROM sessions s WHERE s.id = NEW.id_session AND NEW.date_evaluation NOT BETWEEN s.date_debut AND s.date_fin) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La date de l’évaluation doit être comprise dans la période de sa session.';
  END IF;
END$$

DROP TRIGGER IF EXISTS `trg_evaluation_date_upd`$$
CREATE TRIGGER `trg_evaluation_date_upd` BEFORE UPDATE ON `evaluations` FOR EACH ROW BEGIN
  IF EXISTS (SELECT 1 FROM sessions s WHERE s.id = NEW.id_session AND NEW.date_evaluation NOT BETWEEN s.date_debut AND s.date_fin) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La date de l’évaluation doit être comprise dans la période de sa session.';
  END IF;
END$$

DROP TRIGGER IF EXISTS `trg_inscription_coherence_ins`$$
CREATE TRIGGER `trg_inscription_coherence_ins` BEFORE INSERT ON `inscriptions` FOR EACH ROW BEGIN
  SET NEW.id_session = (SELECT e.id_session FROM emplois_du_temps e WHERE e.id = NEW.id_emploi_du_temps);
  SET NEW.id_annee = (SELECT e.id_annee FROM emplois_du_temps e WHERE e.id = NEW.id_emploi_du_temps);
END$$

DROP TRIGGER IF EXISTS `trg_inscription_coherence_upd`$$
CREATE TRIGGER `trg_inscription_coherence_upd` BEFORE UPDATE ON `inscriptions` FOR EACH ROW BEGIN
  SET NEW.id_session = (SELECT e.id_session FROM emplois_du_temps e WHERE e.id = NEW.id_emploi_du_temps);
  SET NEW.id_annee = (SELECT e.id_annee FROM emplois_du_temps e WHERE e.id = NEW.id_emploi_du_temps);
END$$

DROP TRIGGER IF EXISTS `trg_session_dates_ins`$$
CREATE TRIGGER `trg_session_dates_ins` BEFORE INSERT ON `sessions` FOR EACH ROW BEGIN
  IF EXISTS (SELECT 1 FROM annees_scolaires a WHERE a.id = NEW.id_annee AND (NEW.date_debut < a.date_debut OR NEW.date_fin > a.date_fin)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Les dates de la session doivent être comprises dans celles de son année scolaire.';
  END IF;
END$$

DROP TRIGGER IF EXISTS `trg_session_dates_upd`$$
CREATE TRIGGER `trg_session_dates_upd` BEFORE UPDATE ON `sessions` FOR EACH ROW BEGIN
  IF EXISTS (SELECT 1 FROM annees_scolaires a WHERE a.id = NEW.id_annee AND (NEW.date_debut < a.date_debut OR NEW.date_fin > a.date_fin)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Les dates de la session doivent être comprises dans celles de son année scolaire.';
  END IF;
  IF EXISTS (SELECT 1 FROM evaluations e WHERE e.id_session = NEW.id AND e.date_evaluation NOT BETWEEN NEW.date_debut AND NEW.date_fin) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Des évaluations de cette session tomberaient hors de ses nouvelles dates : déplacez-les d’abord.';
  END IF;
END$$

DELIMITER ;

SET FOREIGN_KEY_CHECKS = 1;
