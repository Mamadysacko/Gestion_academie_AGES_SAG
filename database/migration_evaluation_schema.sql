-- Migration vers le nouveau schéma d'évaluation partagé
-- Ce script remplace l'ancien schéma `parametres_evaluations` et la note non évaluée par évaluation.

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `notes`;
DROP TABLE IF EXISTS `evaluation_parametres`;
DROP TABLE IF EXISTS `parametres`;
DROP TABLE IF EXISTS `parametres_evaluations`;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `parametres` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `libelle` VARCHAR(100) NOT NULL,
    `bareme` DECIMAL(5,2) NOT NULL DEFAULT 4.00,
    PRIMARY KEY (`id`),
    CONSTRAINT `chk_parametres_bareme` CHECK (`bareme` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `evaluation_parametres` (
    `id_evaluation` INT UNSIGNED NOT NULL,
    `id_parametre` INT UNSIGNED NOT NULL,
    `ordre` INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id_evaluation`, `id_parametre`),
    CONSTRAINT `fk_evaluation_parametres_evaluation`
        FOREIGN KEY (`id_evaluation`)
        REFERENCES `evaluations` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_evaluation_parametres_parametre`
        FOREIGN KEY (`id_parametre`)
        REFERENCES `parametres` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notes` (
    `id_note` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_evaluation` INT UNSIGNED NOT NULL,
    `id_parametre` INT UNSIGNED NOT NULL,
    `id_inscription` INT UNSIGNED NOT NULL,
    `note` DECIMAL(5,2) DEFAULT NULL,
    `observation` VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id_note`),
    UNIQUE KEY `uk_note` (`id_evaluation`, `id_parametre`, `id_inscription`),
    CONSTRAINT `fk_notes_evaluation`
        FOREIGN KEY (`id_evaluation`)
        REFERENCES `evaluations` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_notes_parametre`
        FOREIGN KEY (`id_parametre`)
        REFERENCES `parametres` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_notes_inscription`
        FOREIGN KEY (`id_inscription`)
        REFERENCES `inscriptions` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
