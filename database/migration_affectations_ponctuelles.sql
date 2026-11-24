-- Affectations exceptionnelles : une date précise, sans modifier la salle
-- principale du formateur ni le planning hebdomadaire de la classe.

CREATE TABLE IF NOT EXISTS affectations_ponctuelles (
  id int unsigned NOT NULL AUTO_INCREMENT,
  id_emploi_du_temps int unsigned NOT NULL,
  id_formateur int unsigned NOT NULL,
  id_salle int unsigned NOT NULL,
  date_affectation date NOT NULL,
  motif varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  created_by int unsigned DEFAULT NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_affectation_ponctuelle_seance_date (id_emploi_du_temps, date_affectation),
  KEY idx_affectation_ponctuelle_formateur_date (id_formateur, date_affectation),
  KEY idx_affectation_ponctuelle_salle_date (id_salle, date_affectation),
  CONSTRAINT fk_affectation_ponctuelle_edt FOREIGN KEY (id_emploi_du_temps)
    REFERENCES emplois_du_temps (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_affectation_ponctuelle_formateur FOREIGN KEY (id_formateur)
    REFERENCES formateurs (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_affectation_ponctuelle_salle FOREIGN KEY (id_salle)
    REFERENCES salles (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_affectation_ponctuelle_user FOREIGN KEY (created_by)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_affectation_ponctuelle_motif CHECK (CHAR_LENGTH(TRIM(motif)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
