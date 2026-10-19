-- Index composites pour les requêtes les plus fréquentes (effectifs actifs par créneau,
-- pointage d'un créneau pour une date). Sans effet sur les règles métier.
ALTER TABLE inscriptions ADD INDEX idx_inscriptions_edt_statut (id_emploi_du_temps, statut);
ALTER TABLE presences ADD INDEX idx_presences_edt_date (id_emploi_du_temps, date_presence);
ANALYZE TABLE inscriptions, presences, emplois_du_temps, notes, auditeurs;
