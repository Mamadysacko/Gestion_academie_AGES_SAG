-- MySQL dump 10.13  Distrib 8.0.32, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: academie_langues3
-- ------------------------------------------------------
-- Server version	8.0.32

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `annees_scolaires`
--

DROP TABLE IF EXISTS `annees_scolaires`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `annees_scolaires` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date NOT NULL,
  `statut` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_annees_libelle` (`libelle`),
  CONSTRAINT `chk_annees_dates` CHECK ((`date_fin` > `date_debut`))
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `annees_scolaires`
--

LOCK TABLES `annees_scolaires` WRITE;
/*!40000 ALTER TABLE `annees_scolaires` DISABLE KEYS */;
INSERT INTO `annees_scolaires` VALUES (1,'2026-2027','2026-10-13','2026-12-31',1);
/*!40000 ALTER TABLE `annees_scolaires` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `auditeurs`
--

DROP TABLE IF EXISTS `auditeurs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auditeurs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `matricule` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sexe` enum('M','F') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telephone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `position` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_type_employe` int unsigned DEFAULT NULL,
  `id_niveau` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_auditeurs_matricule` (`matricule`),
  KEY `idx_auditeurs_type_employe` (`id_type_employe`),
  KEY `idx_auditeurs_niveau` (`id_niveau`),
  CONSTRAINT `fk_auditeurs_niveau` FOREIGN KEY (`id_niveau`) REFERENCES `niveaux` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_auditeurs_type_employe` FOREIGN KEY (`id_type_employe`) REFERENCES `types_employes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auditeurs`
--

LOCK TABLES `auditeurs` WRITE;
/*!40000 ALTER TABLE `auditeurs` DISABLE KEYS */;
INSERT INTO `auditeurs` VALUES (1,'AUD-2026-001','MAMADY','SACKO','M',NULL,NULL,'MANGERE',2,1),(2,'AUD-2026-002','makossa','mako','M',NULL,NULL,NULL,3,1),(3,'AUD-2026-003','qmks','lskj','M',NULL,NULL,NULL,2,1),(4,'AUD-2026-004','lkfdjl','sklj','M',NULL,NULL,NULL,3,1),(5,'AUD-2026-005','qlskjdf','lkjsl','M',NULL,NULL,'sflk',4,1),(6,'AUD-2026-006','sqjf','jlsjl','M',NULL,NULL,'fdw',2,1);
/*!40000 ALTER TABLE `auditeurs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `baremes_appreciation`
--

DROP TABLE IF EXISTS `baremes_appreciation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `baremes_appreciation` (
  `note` tinyint unsigned NOT NULL,
  `libelle` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `seuil_min` decimal(4,2) NOT NULL,
  PRIMARY KEY (`note`),
  CONSTRAINT `chk_bareme_note` CHECK ((`note` between 1 and 4)),
  CONSTRAINT `chk_bareme_seuil` CHECK ((`seuil_min` between 0 and 4))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `baremes_appreciation`
--

LOCK TABLES `baremes_appreciation` WRITE;
/*!40000 ALTER TABLE `baremes_appreciation` DISABLE KEYS */;
INSERT INTO `baremes_appreciation` VALUES (1,'Insuffisant',0.00),(2,'Passable',2.00),(3,'Bien',2.75),(4,'Très bien',3.50);
/*!40000 ALTER TABLE `baremes_appreciation` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departements`
--

DROP TABLE IF EXISTS `departements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `departements` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `libelle` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_departements_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departements`
--

LOCK TABLES `departements` WRITE;
/*!40000 ALTER TABLE `departements` DISABLE KEYS */;
INSERT INTO `departements` VALUES (1,'DEP1','IT'),(2,'DEPT2','SEFTY');
/*!40000 ALTER TABLE `departements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `emplois_du_temps`
--

DROP TABLE IF EXISTS `emplois_du_temps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `emplois_du_temps` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_annee` int unsigned NOT NULL,
  `id_groupe` int unsigned NOT NULL,
  `id_salle` int unsigned NOT NULL,
  `id_formateur` int unsigned NOT NULL,
  `jour_semaine` tinyint unsigned NOT NULL,
  `statut` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_edt_salle_annee_jour` (`id_annee`,`id_salle`,`jour_semaine`),
  UNIQUE KEY `uk_edt_formateur_annee_jour` (`id_annee`,`id_formateur`,`jour_semaine`),
  UNIQUE KEY `uk_edt_groupe_salle_jour` (`id_annee`,`id_groupe`,`id_salle`,`jour_semaine`),
  KEY `idx_edt_annee` (`id_annee`),
  KEY `idx_edt_groupe` (`id_groupe`),
  KEY `idx_edt_salle` (`id_salle`),
  KEY `idx_edt_formateur` (`id_formateur`),
  KEY `idx_edt_jour` (`jour_semaine`),
  CONSTRAINT `fk_edt_annee` FOREIGN KEY (`id_annee`) REFERENCES `annees_scolaires` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_edt_formateur` FOREIGN KEY (`id_formateur`) REFERENCES `formateurs` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_edt_groupe` FOREIGN KEY (`id_groupe`) REFERENCES `groupes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_edt_salle` FOREIGN KEY (`id_salle`) REFERENCES `salles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_edt_jour` CHECK ((`jour_semaine` between 1 and 5))
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `emplois_du_temps`
--

LOCK TABLES `emplois_du_temps` WRITE;
/*!40000 ALTER TABLE `emplois_du_temps` DISABLE KEYS */;
INSERT INTO `emplois_du_temps` VALUES (1,1,1,1,2,1,1),(2,1,1,2,1,1,1),(3,1,2,1,2,2,1),(4,1,2,2,1,2,1),(5,1,3,1,2,3,1),(6,1,3,2,1,3,1),(7,1,4,3,3,1,1);
/*!40000 ALTER TABLE `emplois_du_temps` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `evaluation_parametres`
--

DROP TABLE IF EXISTS `evaluation_parametres`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `evaluation_parametres` (
  `id_evaluation` int unsigned NOT NULL,
  `id_parametre` int unsigned NOT NULL,
  `ordre` int unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`id_evaluation`,`id_parametre`),
  KEY `fk_evaluation_parametres_parametre` (`id_parametre`),
  CONSTRAINT `fk_evaluation_parametres_evaluation` FOREIGN KEY (`id_evaluation`) REFERENCES `evaluations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_evaluation_parametres_parametre` FOREIGN KEY (`id_parametre`) REFERENCES `parametres` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `evaluation_parametres`
--

LOCK TABLES `evaluation_parametres` WRITE;
/*!40000 ALTER TABLE `evaluation_parametres` DISABLE KEYS */;
INSERT INTO `evaluation_parametres` VALUES (1,1,1);
/*!40000 ALTER TABLE `evaluation_parametres` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `evaluations`
--

DROP TABLE IF EXISTS `evaluations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `evaluations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_session` int unsigned NOT NULL,
  `numero` tinyint unsigned NOT NULL,
  `libelle` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_evaluation` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_evaluations_session_numero` (`id_session`,`numero`),
  KEY `idx_evaluations_session` (`id_session`),
  CONSTRAINT `fk_evaluations_session` FOREIGN KEY (`id_session`) REFERENCES `sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_evaluations_numero` CHECK ((`numero` between 1 and 9))
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `evaluations`
--

LOCK TABLES `evaluations` WRITE;
/*!40000 ALTER TABLE `evaluations` DISABLE KEYS */;
INSERT INTO `evaluations` VALUES (1,1,1,'test','2026-10-12'),(2,1,2,'test','2026-10-12');
/*!40000 ALTER TABLE `evaluations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `formateurs`
--

DROP TABLE IF EXISTS `formateurs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `formateurs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `matricule` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telephone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(180) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_salle` int unsigned DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_formateurs_matricule` (`matricule`),
  UNIQUE KEY `uk_formateurs_email` (`email`),
  UNIQUE KEY `uk_formateurs_salle` (`id_salle`),
  KEY `idx_formateurs_user` (`user_id`),
  CONSTRAINT `fk_formateurs_salle` FOREIGN KEY (`id_salle`) REFERENCES `salles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_formateurs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `formateurs`
--

LOCK TABLES `formateurs` WRITE;
/*!40000 ALTER TABLE `formateurs` DISABLE KEYS */;
INSERT INTO `formateurs` VALUES (1,2,'f1222','MLQKJ','LSKJFL','L86VHGPkuJ',NULL,'form@gmail.com',2,1,'2026-10-12 10:30:11','2026-10-12 10:32:54'),(2,4,'f12222','KJSF','LKJFL','9M8*3@uh*c',NULL,'form2@gmail.com',1,1,'2026-10-12 10:31:32','2026-10-12 10:32:26'),(3,5,'122','f','sdff','BnpZFpUDFC',NULL,'for@gmail.com',3,1,'2026-10-12 11:47:12','2026-10-12 13:50:21');
/*!40000 ALTER TABLE `formateurs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `groupes`
--

DROP TABLE IF EXISTS `groupes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
  KEY `idx_groupes_session` (`id_session`),
  KEY `idx_groupes_jour` (`jour_semaine`),
  CONSTRAINT `fk_groupes_session` FOREIGN KEY (`id_session`) REFERENCES `sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_groupes_capacite` CHECK (((`capacite` is null) or (`capacite` > 0))),
  CONSTRAINT `chk_groupes_horaires` CHECK ((`heure_fin` > `heure_debut`)),
  CONSTRAINT `chk_groupes_jour` CHECK ((`jour_semaine` between 1 and 5))
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `groupes`
--

LOCK TABLES `groupes` WRITE;
/*!40000 ALTER TABLE `groupes` DISABLE KEYS */;
INSERT INTO `groupes` VALUES (1,1,'G1','GROUPE1',1,'08:00:00','12:00:00',NULL,1),(2,1,'G2','GROUPE2',2,'08:00:00','12:00:00',NULL,1),(3,1,'G3','GROUPE 3',3,'08:00:00','12:00:00',NULL,1),(4,2,'UIIU','LI',1,'08:00:00','12:00:00',NULL,1);
/*!40000 ALTER TABLE `groupes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inscriptions`
--

DROP TABLE IF EXISTS `inscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
  KEY `idx_inscriptions_auditeur` (`id_auditeur`),
  KEY `idx_inscriptions_departement` (`id_departement`),
  KEY `idx_inscriptions_edt` (`id_emploi_du_temps`),
  KEY `idx_inscriptions_annee` (`id_annee`),
  KEY `idx_inscriptions_session` (`id_session`),
  CONSTRAINT `fk_inscriptions_annee` FOREIGN KEY (`id_annee`) REFERENCES `annees_scolaires` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_inscriptions_auditeur` FOREIGN KEY (`id_auditeur`) REFERENCES `auditeurs` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_inscriptions_departement` FOREIGN KEY (`id_departement`) REFERENCES `departements` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_inscriptions_edt` FOREIGN KEY (`id_emploi_du_temps`) REFERENCES `emplois_du_temps` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_inscriptions_session` FOREIGN KEY (`id_session`) REFERENCES `sessions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inscriptions`
--

LOCK TABLES `inscriptions` WRITE;
/*!40000 ALTER TABLE `inscriptions` DISABLE KEYS */;
INSERT INTO `inscriptions` VALUES (1,1,1,1,1,1,'2026-10-12','active'),(2,2,1,1,2,1,'2026-10-12','active'),(3,3,1,1,3,1,'2026-10-12','active'),(4,4,1,1,5,1,'2026-10-12','active'),(5,5,1,1,4,2,'2026-10-12','active'),(6,6,1,1,2,2,'2026-10-12','active');
/*!40000 ALTER TABLE `inscriptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `journal_operations`
--

DROP TABLE IF EXISTS `journal_operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `journal_operations`
--

LOCK TABLES `journal_operations` WRITE;
/*!40000 ALTER TABLE `journal_operations` DISABLE KEYS */;
INSERT INTO `journal_operations` VALUES (23,1,'Système Admin','admin','POST','connexion','auth',NULL,'auth/login',200,1,'Authentification réussie.','{\"email\":\"admin@gmail.com\",\"password\":\"***\"}','::1','2026-10-12 15:39:15');
/*!40000 ALTER TABLE `journal_operations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `niveaux`
--

DROP TABLE IF EXISTS `niveaux`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `niveaux` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ordre` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_niveaux_libelle` (`libelle`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `niveaux`
--

LOCK TABLES `niveaux` WRITE;
/*!40000 ALTER TABLE `niveaux` DISABLE KEYS */;
INSERT INTO `niveaux` VALUES (1,'NIVEAU 1',1),(2,'NIVEAU 2',2);
/*!40000 ALTER TABLE `niveaux` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notes`
--

DROP TABLE IF EXISTS `notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notes`
--

LOCK TABLES `notes` WRITE;
/*!40000 ALTER TABLE `notes` DISABLE KEYS */;
/*!40000 ALTER TABLE `notes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `parametres`
--

DROP TABLE IF EXISTS `parametres`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `parametres` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bareme` decimal(5,2) NOT NULL DEFAULT '4.00',
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_parametre_bareme` CHECK ((`bareme` > 0))
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `parametres`
--

LOCK TABLES `parametres` WRITE;
/*!40000 ALTER TABLE `parametres` DISABLE KEYS */;
INSERT INTO `parametres` VALUES (1,'calcul',4.00);
/*!40000 ALTER TABLE `parametres` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `passages_niveaux`
--

DROP TABLE IF EXISTS `passages_niveaux`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `passages_niveaux` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_inscription` int unsigned NOT NULL,
  `niveau_depart` int unsigned NOT NULL,
  `niveau_arrivee` int unsigned DEFAULT NULL,
  `decision` enum('admis','non_admis','redouble','abandonne') COLLATE utf8mb4_unicode_ci NOT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `passages_niveaux`
--

LOCK TABLES `passages_niveaux` WRITE;
/*!40000 ALTER TABLE `passages_niveaux` DISABLE KEYS */;
/*!40000 ALTER TABLE `passages_niveaux` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `presences`
--

DROP TABLE IF EXISTS `presences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presences` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_inscription` int unsigned NOT NULL,
  `id_emploi_du_temps` int unsigned DEFAULT NULL,
  `id_formateur` int unsigned NOT NULL,
  `date_presence` date NOT NULL,
  `jour_semaine_presence` varchar(10) COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS ((case dayofweek(`date_presence`) when 2 then _utf8mb4'lundi' when 3 then _utf8mb4'mardi' when 4 then _utf8mb4'mercredi' when 5 then _utf8mb4'jeudi' when 6 then _utf8mb4'vendredi' else NULL end)) STORED,
  `statut` enum('present','absent','permissionnaire','malade','conge') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'present',
  `conge_date_debut` date DEFAULT NULL,
  `conge_date_fin` date DEFAULT NULL,
  `observation` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_presence_inscription_date` (`id_inscription`,`date_presence`),
  KEY `idx_presences_edt` (`id_emploi_du_temps`),
  KEY `idx_presences_formateur` (`id_formateur`),
  KEY `idx_presences_date` (`date_presence`),
  CONSTRAINT `fk_presences_edt` FOREIGN KEY (`id_emploi_du_temps`) REFERENCES `emplois_du_temps` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_presences_formateur` FOREIGN KEY (`id_formateur`) REFERENCES `formateurs` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_presences_inscription` FOREIGN KEY (`id_inscription`) REFERENCES `inscriptions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_presences_conge_dates` CHECK (((`conge_date_fin` is null) or (`conge_date_debut` is null) or (`conge_date_fin` >= `conge_date_debut`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `presences`
--

LOCK TABLES `presences` WRITE;
/*!40000 ALTER TABLE `presences` DISABLE KEYS */;
/*!40000 ALTER TABLE `presences` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `regles_evaluation`
--

DROP TABLE IF EXISTS `regles_evaluation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `regles_evaluation` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Règle générale',
  `id_niveau` int unsigned DEFAULT NULL,
  `nb_evaluations` tinyint unsigned NOT NULL DEFAULT '3',
  `moyenne_admission` decimal(4,2) NOT NULL DEFAULT '2.00',
  `taux_presence_min` decimal(5,2) NOT NULL DEFAULT '0.00',
  `passage_automatique` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_regles_niveau` (`id_niveau`),
  CONSTRAINT `fk_regles_niveau` FOREIGN KEY (`id_niveau`) REFERENCES `niveaux` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_regles_moyenne` CHECK ((`moyenne_admission` between 0 and 4)),
  CONSTRAINT `chk_regles_nb` CHECK ((`nb_evaluations` between 1 and 9)),
  CONSTRAINT `chk_regles_presence` CHECK ((`taux_presence_min` between 0 and 100))
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `regles_evaluation`
--

LOCK TABLES `regles_evaluation` WRITE;
/*!40000 ALTER TABLE `regles_evaluation` DISABLE KEYS */;
INSERT INTO `regles_evaluation` VALUES (1,'Règle générale',NULL,3,2.00,0.00,1);
/*!40000 ALTER TABLE `regles_evaluation` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_permissions` (
  `role_id` int unsigned NOT NULL,
  `module_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`role_id`,`module_code`,`action_code`),
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_permissions`
--

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nom` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_roles_code` (`code`),
  UNIQUE KEY `uk_roles_nom` (`nom`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'admin','Administrateur','2026-10-12 10:21:35'),(2,'scolarite','Service Scolarité','2026-10-12 10:21:35'),(3,'formateur','Formateur','2026-10-12 10:21:35'),(4,'secretaire','Secrétaire','2026-10-12 10:21:35');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `salles`
--

DROP TABLE IF EXISTS `salles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `salles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `libelle` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `capacite` int unsigned DEFAULT NULL,
  `statut` enum('disponible','indisponible') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'disponible',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_salles_code` (`code`),
  CONSTRAINT `chk_salles_capacite` CHECK (((`capacite` is null) or (`capacite` > 0)))
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `salles`
--

LOCK TABLES `salles` WRITE;
/*!40000 ALTER TABLE `salles` DISABLE KEYS */;
INSERT INTO `salles` VALUES (1,'S1','SALLE1',16,'disponible'),(2,'S2','salle 2',16,'disponible'),(3,'KJK','JK',16,'disponible');
/*!40000 ALTER TABLE `salles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sessions_code` (`code`),
  UNIQUE KEY `uk_sessions_annee_type_libelle` (`id_annee`,`id_type_formation`,`libelle`),
  KEY `idx_sessions_annee` (`id_annee`),
  KEY `idx_sessions_type` (`id_type_formation`),
  KEY `idx_sessions_niveau` (`id_niveau`),
  CONSTRAINT `fk_sessions_annee` FOREIGN KEY (`id_annee`) REFERENCES `annees_scolaires` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_sessions_niveau` FOREIGN KEY (`id_niveau`) REFERENCES `niveaux` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_sessions_type` FOREIGN KEY (`id_type_formation`) REFERENCES `types_formations` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_sessions_dates` CHECK ((`date_fin` >= `date_debut`))
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
INSERT INTO `sessions` VALUES (1,1,1,1,'FR01','SESSION 1','2026-10-12','2026-10-19','ouverte'),(2,1,1,2,'e','zre','2026-10-13','2026-10-25','ouverte');
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `types_employes`
--

DROP TABLE IF EXISTS `types_employes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `types_employes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_types_employes_libelle` (`libelle`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `types_employes`
--

LOCK TABLES `types_employes` WRITE;
/*!40000 ALTER TABLE `types_employes` DISABLE KEYS */;
INSERT INTO `types_employes` VALUES (3,'COMMUNAUTE'),(2,'CONTRACTOR'),(4,'PSF'),(1,'SAG');
/*!40000 ALTER TABLE `types_employes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `types_formations`
--

DROP TABLE IF EXISTS `types_formations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `types_formations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_types_formations_libelle` (`libelle`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `types_formations`
--

LOCK TABLES `types_formations` WRITE;
/*!40000 ALTER TABLE `types_formations` DISABLE KEYS */;
INSERT INTO `types_formations` VALUES (1,'ALPHABETISATION');
/*!40000 ALTER TABLE `types_formations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(180) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telephone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role_id` int unsigned NOT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_email` (`email`),
  KEY `idx_users_role` (`role_id`),
  KEY `idx_users_actif` (`actif`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Admin','Système','admin@gmail.com','771234567','111',1,1,'2026-10-12 15:39:15','2026-10-12 10:22:53','2026-10-12 15:39:15'),(2,'MLQKJ','LSKJFL','form@gmail.com',NULL,'L86VHGPkuJ',3,1,'2026-10-12 13:09:51','2026-10-12 10:30:11','2026-10-12 13:09:51'),(3,'jLKDJ','kj','form1@gmail.com',NULL,'e2U6TtRdgD',1,1,'2026-10-12 13:50:36','2026-10-12 10:30:43','2026-10-12 13:50:36'),(4,'KJSF','LKJFL','form2@gmail.com',NULL,'9M8*3@uh*c',3,1,NULL,'2026-10-12 10:31:32','2026-10-12 10:31:32'),(5,'f','sdff','for@gmail.com',NULL,'BnpZFpUDFC',3,1,'2026-10-12 13:50:53','2026-10-12 11:47:12','2026-10-12 13:50:53');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-12 15:43:59
