<?php
/**
 * Applique le rattachement formateur → salle unique (idempotent).
 */

require_once dirname(__DIR__) . '/config/Database.php';

$db = Database::getInstance();

$column = $db->query("SHOW COLUMNS FROM formateurs LIKE 'id_salle'")->fetch();
if ($column === false) {
    $db->exec(
        "ALTER TABLE formateurs
         ADD COLUMN id_salle int unsigned DEFAULT NULL AFTER email,
         ADD UNIQUE KEY uk_formateurs_salle (id_salle),
         ADD CONSTRAINT fk_formateurs_salle
           FOREIGN KEY (id_salle) REFERENCES salles (id)
           ON DELETE SET NULL ON UPDATE CASCADE"
    );
    echo "Colonne formateurs.id_salle créée.\n";
} else {
    echo "Colonne formateurs.id_salle déjà présente.\n";
}

$updated = $db->exec(
    "UPDATE formateurs f
     INNER JOIN (
       SELECT e.id_formateur, MIN(e.id_salle) AS id_salle
       FROM emplois_du_temps e
       GROUP BY e.id_formateur
       HAVING COUNT(DISTINCT e.id_salle) = 1
     ) src ON src.id_formateur = f.id
     SET f.id_salle = src.id_salle
     WHERE f.id_salle IS NULL
       AND src.id_salle NOT IN (
         SELECT id_salle FROM (
           SELECT MIN(e2.id_salle) AS id_salle
           FROM emplois_du_temps e2
           GROUP BY e2.id_formateur
           HAVING COUNT(DISTINCT e2.id_salle) = 1
         ) shared
         GROUP BY id_salle
         HAVING COUNT(*) > 1
       )"
);

echo "Formateurs rattachés automatiquement : " . (int) $updated . "\n";
