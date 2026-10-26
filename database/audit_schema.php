<?php
/**
 * Contrôle de la base (lecture seule) : index redondants, colonnes de référence sans clé étrangère,
 * cohérence des colonnes dérivées, qualité des données. À relancer avant et après chaque migration.
 *
 *   php database/audit_schema.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Script réservé à la ligne de commande.');
}

require_once __DIR__ . '/../config/Database.php';
$db = Database::getInstance();
$q = fn($sql) => $db->query($sql)->fetchAll();

echo "== Index redondants (préfixe d'un autre index de la même table)\n";
$idx = [];
foreach ($q("SELECT TABLE_NAME t, INDEX_NAME i, NON_UNIQUE nu, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE") as $r) $idx[$r['t']][] = $r;
$fkCols = [];
foreach ($q("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL") as $r) $fkCols[$r['t']][$r['c']] = true;
foreach ($idx as $t => $list) foreach ($list as $a) {
  if ($a['nu'] == 0) continue;
  foreach ($list as $b) {
    if ($a['i'] === $b['i']) continue;
    if (str_starts_with($b['cols'] . ',', $a['cols'] . ',')) { echo "  $t.{$a['i']} ({$a['cols']}) couvert par {$b['i']} ({$b['cols']})\n"; break; }
  }
}

echo "\n== Colonnes id_* sans clé étrangère\n";
foreach ($q("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE') AND (COLUMN_NAME LIKE 'id\\_%' OR COLUMN_NAME LIKE '%\\_id' OR COLUMN_NAME IN ('remplace_par','niveau_depart','niveau_arrivee','autorise_par'))") as $r)
  if (empty($fkCols[$r['t']][$r['c']])) echo "  {$r['t']}.{$r['c']}\n";

echo "\n== Tables sans contrainte unique métier (hors PK)\n";
foreach ($idx as $t => $list) if (!array_filter($list, fn($x) => $x['nu'] == 0 && $x['i'] !== 'PRIMARY')) echo "  $t\n";

echo "\n== Anomalies de données\n";
$checks = [
 'Mots de passe en clair (users)' => "SELECT COUNT(*) FROM users WHERE password NOT LIKE '\$2y\$%'",
 'Comptes rôle formateur sans fiche formateur' => "SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='formateur' AND NOT EXISTS (SELECT 1 FROM formateurs f WHERE f.user_id=u.id)",
 'Sessions hors de leur année scolaire' => "SELECT COUNT(*) FROM sessions s JOIN annees_scolaires a ON a.id=s.id_annee WHERE s.date_debut<a.date_debut OR s.date_fin>a.date_fin",
 'Évaluations hors période de session' => "SELECT COUNT(*) FROM evaluations e JOIN sessions s ON s.id=e.id_session WHERE e.date_evaluation NOT BETWEEN s.date_debut AND s.date_fin",
 'Évaluations hors année scolaire' => "SELECT COUNT(*) FROM evaluations e JOIN sessions s ON s.id=e.id_session JOIN annees_scolaires a ON a.id=s.id_annee WHERE e.date_evaluation NOT BETWEEN a.date_debut AND a.date_fin",
 'Créneaux: id_annee ≠ année de la session' => "SELECT COUNT(*) FROM emplois_du_temps e JOIN sessions s ON s.id=e.id_session WHERE e.id_annee<>s.id_annee",
 'Créneaux: session ≠ session du groupe' => "SELECT COUNT(*) FROM emplois_du_temps e JOIN groupes g ON g.id=e.id_groupe WHERE e.id_session<>g.id_session",
 'Créneaux: formateur ≠ titulaire de la salle' => "SELECT COUNT(*) FROM emplois_du_temps e JOIN formateurs f ON f.id_salle=e.id_salle WHERE f.id<>e.id_formateur",
 'Inscriptions: session/année ≠ créneau' => "SELECT COUNT(*) FROM inscriptions i JOIN emplois_du_temps e ON e.id=i.id_emploi_du_temps WHERE i.id_session<>e.id_session OR i.id_annee<>e.id_annee",
 'Présences: formateur ≠ formateur du créneau' => "SELECT COUNT(*) FROM presences p JOIN emplois_du_temps e ON e.id=p.id_emploi_du_temps WHERE p.id_formateur<>e.id_formateur",
 'Présences sans créneau' => "SELECT COUNT(*) FROM presences WHERE id_emploi_du_temps IS NULL",
 'Groupes: libellé en double dans une session' => "SELECT COUNT(*) FROM (SELECT id_session, libelle FROM groupes GROUP BY id_session, libelle HAVING COUNT(*)>1) x",
 'Groupes: jour ≠ premier passage' => "SELECT COUNT(*) FROM groupes g WHERE g.jour_semaine <> (SELECT MIN(jour_semaine) FROM groupe_passages gp WHERE gp.id_groupe=g.id)",
 'Créneaux: horaire ≠ passage du groupe' => "SELECT COUNT(*) FROM emplois_du_temps e JOIN groupe_passages gp ON gp.id_groupe=e.id_groupe AND gp.jour_semaine=e.jour_semaine WHERE gp.heure_debut<>e.heure_debut OR gp.heure_fin<>e.heure_fin",
 'Créneaux sur un jour sans passage' => "SELECT COUNT(*) FROM emplois_du_temps e WHERE NOT EXISTS (SELECT 1 FROM groupe_passages gp WHERE gp.id_groupe=e.id_groupe AND gp.jour_semaine=e.jour_semaine)",
 'Sessions: passages_semaine ≠ instantané' => "SELECT COUNT(*) FROM sessions WHERE config_formation IS NOT NULL AND passages_semaine <> JSON_EXTRACT(config_formation,'$.passages_semaine')",
 'Téléphones en double (users, espaces ignorés)' => "SELECT COUNT(*) FROM (SELECT REPLACE(telephone,' ','') t FROM users WHERE telephone IS NOT NULL GROUP BY t HAVING COUNT(*)>1) x",
 'Noms/prénoms avec espaces en trop' => "SELECT COUNT(*) FROM users WHERE nom<>TRIM(nom) OR prenom<>TRIM(prenom)",
 'Déclencheurs de cohérence en place (attendu : 9)' => "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()",
 'Vue v_formateurs présente (attendu : 1)' => "SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v_formateurs'",
 'Années scolaires actives' => "SELECT COUNT(*) FROM annees_scolaires WHERE statut=1",
 'Permissions vers un module inconnu' => "SELECT COUNT(*) FROM role_permissions WHERE module_code NOT IN ('organisation-pedagogique','emplois-temps','auditeurs','formateurs','salles','evaluations','presences','utilisateurs','parametres','passages-niveau')",
];
foreach ($checks as $label => $sql) printf("  %-55s %s\n", $label, $db->query($sql)->fetchColumn());

echo "\n== Moteur / jeu de caractères des colonnes texte hors utf8mb4_unicode_ci\n";
foreach ($q("SELECT TABLE_NAME t, COLUMN_NAME c, COLLATION_NAME k FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME<>'utf8mb4_unicode_ci'") as $r) echo "  {$r['t']}.{$r['c']} {$r['k']}\n";
echo "\n== Volumétrie\n";
foreach ($q("SELECT TABLE_NAME t, TABLE_ROWS r FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME") as $r) echo "  {$r['t']}={$r['r']}";
echo "\n";
