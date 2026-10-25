<?php
/**
 * Test de 3 contrôleurs sur une base de test jetable :
 *
 *   1. AuditeurController   — liste, détail, recherche, création, validations, modification, suppression, dossier
 *   2. PositionController   — CRUD complet de la table « positions » + effet sur les auditeurs rattachés
 *   3. ResultatController   — résultats d'une session, passages de niveau approuvés à la main, annulation,
 *                             et droit d'approbation (Authorization)
 *
 * Lancer (depuis le dossier Backend_AGES) :
 *     C:\xampp\php\php.exe tests\run_controller_tests.php
 *
 * La base « academie_langues3_test » est reconstruite à chaque lancement puis laissée en l'état :
 * ouvrez-la dans phpMyAdmin pour voir ce que les tests ont écrit. La vraie base n'est jamais modifiée.
 */

require __DIR__ . '/support.php';

echo "Reconstruction de la base de test…\n";
$built = buildTestDatabase();
echo "Base « " . TEST_DB_NAME . " » : {$built['tables']} tables, données chargées depuis tests/database/fixtures.sql\n";
loginAs(1); // administrateur de test

// ═════════════════════════════════════════════════════════════════════════════
title('1. AuditeurController');
// ═════════════════════════════════════════════════════════════════════════════
section('Lecture');
$r = call('GET /auditeurs', null, fn() => (new AuditeurController())->getAll());
expectStatus($r, 200, 'la liste répond 200');
check('4 auditeurs dans la base de test', count($r[1]['data']) === 4);
$diallo = array_values(array_filter($r[1]['data'], fn($a) => $a['matricule'] === 'AUD-T-001'))[0] ?? [];
check('la position est renvoyée avec son libellé (jointure positions)', ($diallo['position'] ?? null) === 'Chef de service');
check('le type d’employé est renvoyé avec son libellé', ($diallo['type_employe_libelle'] ?? null) === 'SAG');

$r = call('GET /auditeurs/1', null, fn() => (new AuditeurController())->getById(1));
check('détail : Diallo Mamadou', ($r[1]['data']['nom'] ?? '') === 'Diallo');
expectStatus(call('GET /auditeurs/999', null, fn() => (new AuditeurController())->getById(999)), 404, 'auditeur inconnu → 404');
$r = call('GET /auditeurs/search/bah', null, fn() => (new AuditeurController())->search('bah'));
check('la recherche « bah » trouve Mariam Bah', count($r[1]['data']) === 1 && $r[1]['data'][0]['prenom'] === 'Mariam');
$r = call('GET /auditeurs/matricule/AUD-T-003', null, fn() => (new AuditeurController())->getByMatricule('AUD-T-003'));
check('lecture par matricule', ($r[1]['data']['nom'] ?? '') === 'Sow');

section('Création et validations');
$r = call('POST /auditeurs (valide)', ['matricule' => 'AUD-T-005', 'nom' => 'Keita', 'prenom' => 'Sekou', 'sexe' => 'M', 'id_position' => 3, 'id_type_employe' => 2], fn() => (new AuditeurController())->create());
expectStatus($r, 201, 'création → 201');
$newId = (int) ($r[1]['data']['id'] ?? 0);
check('la position choisie est liée en base', (int) dbValue('SELECT id_position FROM auditeurs WHERE id = ?', [$newId]) === 3);
expectStatus(call('POST /auditeurs (matricule en double)', ['matricule' => 'AUD-T-005', 'nom' => 'X', 'prenom' => 'Y'], fn() => (new AuditeurController())->create()), 409, 'matricule en double → 409');
expectStatus(call('POST /auditeurs (sans nom)', ['matricule' => 'AUD-T-006', 'prenom' => 'Y'], fn() => (new AuditeurController())->create()), 400, 'nom manquant → 400');
expectStatus(call('POST /auditeurs (sexe « X »)', ['matricule' => 'AUD-T-006', 'nom' => 'A', 'prenom' => 'B', 'sexe' => 'X'], fn() => (new AuditeurController())->create()), 400, 'sexe invalide → 400');
expectStatus(call('POST /auditeurs (e-mail invalide)', ['matricule' => 'AUD-T-006', 'nom' => 'A', 'prenom' => 'B', 'email' => 'pas-un-mail'], fn() => (new AuditeurController())->create()), 400, 'e-mail invalide → 400');
$r = call('POST /auditeurs (position 999)', ['matricule' => 'AUD-T-006', 'nom' => 'A', 'prenom' => 'B', 'id_position' => 999], fn() => (new AuditeurController())->create());
check('position inexistante → 400', $r[0] === 400 && str_contains($r[1]['error'] ?? '', 'position'));
expectStatus(call('POST /auditeurs (type d’employé 999)', ['matricule' => 'AUD-T-006', 'nom' => 'A', 'prenom' => 'B', 'id_type_employe' => 999], fn() => (new AuditeurController())->create()), 400, 'type d’employé inexistant → 400');
check('aucun auditeur n’a été créé par les requêtes refusées', (int) dbValue('SELECT COUNT(*) FROM auditeurs') === 5);

section('Modification et suppression');
expectStatus(call("PUT /auditeurs/{$newId} (retire la position)", ['id_position' => ''], fn() => (new AuditeurController())->update($newId)), 200, 'modification → 200');
check('la position est vide après modification', dbValue('SELECT id_position FROM auditeurs WHERE id = ?', [$newId]) === null);
expectStatus(call("PUT /auditeurs/{$newId} (matricule d’un autre)", ['matricule' => 'AUD-T-001'], fn() => (new AuditeurController())->update($newId)), 409, 'matricule déjà pris → 409');
expectStatus(call('PUT /auditeurs/999', ['nom' => 'Z'], fn() => (new AuditeurController())->update(999)), 404, 'modifier un inconnu → 404');
expectStatus(call("DELETE /auditeurs/{$newId}", null, fn() => (new AuditeurController())->delete($newId)), 200, 'suppression d’un auditeur sans inscription → 200');
expectStatus(call('DELETE /auditeurs/1 (a une inscription)', null, fn() => (new AuditeurController())->delete(1)), 409, 'auditeur inscrit → 409 (clé étrangère)');
expectStatus(call('DELETE /auditeurs/999', null, fn() => (new AuditeurController())->delete(999)), 404, 'supprimer un inconnu → 404');

section('Dossier complet (administrateur)');
$r = call('GET /auditeurs/1/dossier', null, fn() => (new AuditeurController())->dossier(1));
expectStatus($r, 200, 'le dossier répond 200');
check('1 inscription dans le dossier', count($r[1]['data']['inscriptions'] ?? []) === 1);
check('notes et résultats joints (droit de lecture)', is_array($r[1]['data']['notes'] ?? null) && is_array($r[1]['data']['resultats'] ?? null));

// ═════════════════════════════════════════════════════════════════════════════
title('2. PositionController');
// ═════════════════════════════════════════════════════════════════════════════
section('Lecture');
$r = call('GET /positions', null, fn() => (new PositionController())->getAll());
expectStatus($r, 200, 'la liste répond 200');
check('3 positions, triées par libellé', count($r[1]['data']) === 3 && $r[1]['data'][0]['libelle'] === 'Chef de service');
$operateur = array_values(array_filter($r[1]['data'], fn($p) => $p['libelle'] === 'Opérateur'))[0] ?? [];
check('chaque position indique son nombre d’auditeurs', (int) ($operateur['nb_auditeurs'] ?? -1) === 1);
expectStatus(call('GET /positions/1', null, fn() => (new PositionController())->getById(1)), 200, 'détail d’une position → 200');
expectStatus(call('GET /positions/999', null, fn() => (new PositionController())->getById(999)), 404, 'position inconnue → 404');

section('Création');
$r = call('POST /positions {Directeur}', ['libelle' => 'Directeur'], fn() => (new PositionController())->create());
expectStatus($r, 201, 'création → 201');
$positionId = (int) ($r[1]['data']['id'] ?? 0);
expectStatus(call('POST /positions {directeur} (même libellé)', ['libelle' => 'directeur'], fn() => (new PositionController())->create()), 409, 'libellé en double (sans tenir compte de la casse) → 409');
expectStatus(call('POST /positions {vide}', ['libelle' => '   '], fn() => (new PositionController())->create()), 400, 'libellé vide → 400');
expectStatus(call('POST /positions {101 caractères}', ['libelle' => str_repeat('a', 101)], fn() => (new PositionController())->create()), 400, 'libellé trop long → 400');

section('Modification');
expectStatus(call("PUT /positions/{$positionId} {Directeur adjoint}", ['libelle' => 'Directeur adjoint'], fn() => (new PositionController())->update($positionId)), 200, 'modification → 200');
check('le libellé est changé en base', dbValue('SELECT libelle FROM positions WHERE id = ?', [$positionId]) === 'Directeur adjoint');
expectStatus(call("PUT /positions/{$positionId} {Opérateur}", ['libelle' => 'Opérateur'], fn() => (new PositionController())->update($positionId)), 409, 'libellé déjà pris → 409');
expectStatus(call('PUT /positions/999', ['libelle' => 'Z'], fn() => (new PositionController())->update(999)), 404, 'modifier une position inconnue → 404');

section('Suppression');
check('avant : Bah (auditeur 2) a la position « Opérateur »', (int) dbValue('SELECT id_position FROM auditeurs WHERE id = 2') === 2);
expectStatus(call('DELETE /positions/2 (utilisée par Bah)', null, fn() => (new PositionController())->delete(2)), 200, 'suppression d’une position utilisée → 200');
check('après : l’auditeur est conservé, sa position est vide (ON DELETE SET NULL)', dbValue('SELECT id_position FROM auditeurs WHERE id = 2') === null && (int) dbValue('SELECT COUNT(*) FROM auditeurs WHERE id = 2') === 1);
expectStatus(call("DELETE /positions/{$positionId}", null, fn() => (new PositionController())->delete($positionId)), 200, 'suppression d’une position libre → 200');
expectStatus(call('DELETE /positions/999', null, fn() => (new PositionController())->delete(999)), 404, 'supprimer une position inconnue → 404');

// ═════════════════════════════════════════════════════════════════════════════
title('3. ResultatController (résultats et passages de niveau)');
// ═════════════════════════════════════════════════════════════════════════════
$session = fn() => call('GET /resultats/session/1', null, fn() => (new ResultatController())->getSession(1))[1]['data'];
$byInscription = static function (array $data): array {
    $rows = [];
    foreach ($data['auditeurs'] as $row) { $rows[(int) $row['id_inscription']] = $row; }
    return $rows;
};

section('Résultats d’une session (calculés, jamais décisifs)');
$data = $session();
$rows = $byInscription($data);
check('3 auditeurs inscrits', count($rows) === 3);
check('Diallo : moyenne générale 3.20 / 4, appréciation « Bien »', ($rows[1]['moyenne_generale'] ?? null) === 3.2 && ($rows[1]['appreciation_generale']['libelle'] ?? '') === 'Bien');
check('Bah : moyenne générale 1.60 / 4', ($rows[2]['moyenne_generale'] ?? null) === 1.6);
check('Sow : moyenne incomplète → pas de moyenne générale', array_key_exists('moyenne_generale', $rows[3]) && $rows[3]['moyenne_generale'] === null);
check('aucun passage décidé au départ', count(array_filter($rows, fn($row) => $row['passage'] !== null)) === 0);
check('le niveau suivant proposé est « Intermédiaire »', ($data['session']['niveau_suivant']['libelle'] ?? '') === 'Intermédiaire');
expectStatus(call('GET /resultats/session/999', null, fn() => (new ResultatController())->getSession(999)), 404, 'session inconnue → 404');

section('Qui a le droit d’approuver ? (Authorization, avant le contrôleur)');
$attempt = static function (string $label, ?int $userId): ?int {
    loginAs($userId);
    try {
        Authorization::authorizeRequest('resultats', 'POST', 'valider', '1');
        $code = null;
    } catch (RuntimeException $e) {
        $code = $e->getCode();
    }
    printf("    %-52s → %s\n", $label, $code === null ? 'autorisé' : "refusé ({$code})");
    return $code;
};
check('non connecté → 401', $attempt('POST valider — personne de connecté', null) === 401);
check('secrétaire (sans le droit) → 403', $attempt('POST valider — secrétaire', 2) === 403);
check('approbateur (droit « Passages de niveau ») → autorisé', $attempt('POST valider — approbateur', 3) === null);
check('administrateur → autorisé', $attempt('POST valider — administrateur', 1) === null);
loginAs(2);
try { Authorization::authorizeRequest('resultats', 'GET', 'session', '1'); $read = true; } catch (RuntimeException) { $read = false; }
check('la secrétaire peut tout de même CONSULTER les résultats', $read);
loginAs(1);

section('Approbation manuelle');
$r = call('POST valider/1 {Diallo → admis}', ['inscriptions' => [1], 'decision' => 'admis', 'observation' => 'Très bon travail'], fn() => (new ResultatController())->valider(1));
expectStatus($r, 200, 'passage au niveau supérieur → 200');
check('le message nomme le niveau d’arrivée', str_contains($r[1]['message'] ?? '', 'Intermédiaire'));
check('le niveau de Diallo passe à 2 (Intermédiaire)', (int) dbValue('SELECT id_niveau FROM auditeurs WHERE id = 1') === 2);
$r = call('POST valider/1 {Bah, Sow → redouble}', ['inscriptions' => [2, 3], 'decision' => 'redouble'], fn() => (new ResultatController())->valider(1));
expectStatus($r, 200, 'redoublement → 200');
check('Bah et Sow restent au niveau 1', (int) dbValue('SELECT COUNT(*) FROM auditeurs WHERE id IN (2, 3) AND id_niveau = 1') === 2);
$rows = $byInscription($session());
check('les décisions sont visibles dans les résultats', ($rows[1]['passage']['decision'] ?? '') === 'admis' && ($rows[2]['passage']['decision'] ?? '') === 'redouble');
check('Diallo : observation et mode « manuelle » enregistrés', ($rows[1]['passage']['observation'] ?? '') === 'Très bon travail' && ($rows[1]['passage']['mode_validation'] ?? '') === 'manuelle');

section('Refus et changement d’avis');
expectStatus(call('POST valider/1 {sélection vide}', ['inscriptions' => [], 'decision' => 'admis'], fn() => (new ResultatController())->valider(1)), 400, 'aucun auditeur sélectionné → 400');
expectStatus(call('POST valider/1 {décision « non_admis »}', ['inscriptions' => [1], 'decision' => 'non_admis'], fn() => (new ResultatController())->valider(1)), 400, 'décision autre que admis / redouble → 400');
expectStatus(call('POST valider/1 {inscription 999}', ['inscriptions' => [999], 'decision' => 'admis'], fn() => (new ResultatController())->valider(1)), 400, 'inscription étrangère à la session → 400');
call('POST valider/1 {Sow → admis}', ['inscriptions' => [3], 'decision' => 'admis'], fn() => (new ResultatController())->valider(1));
check('un redoublement peut être remplacé : Sow passe au niveau 2', (int) dbValue('SELECT id_niveau FROM auditeurs WHERE id = 3') === 2);
check('une seule décision par inscription (pas de doublon)', (int) dbValue('SELECT COUNT(*) FROM passages_niveaux WHERE id_inscription = 3') === 1);

section('Annulation');
$r = call('POST annuler/1 {1, 2, 3}', ['inscriptions' => [1, 2, 3]], fn() => (new ResultatController())->annuler(1));
expectStatus($r, 200, 'annulation → 200');
check('3 décisions supprimées', (int) dbValue('SELECT COUNT(*) FROM passages_niveaux') === 0);
check('tous les auditeurs retrouvent leur niveau de départ', (int) dbValue('SELECT COUNT(*) FROM auditeurs WHERE id IN (1, 2, 3) AND id_niveau = 1') === 3);
expectStatus(call('POST annuler/1 (rien à annuler)', ['inscriptions' => [1]], fn() => (new ResultatController())->annuler(1)), 400, 'annuler sans décision → 400');

// ═════════════════════════════════════════════════════════════════════════════
$total = Report::$passed + Report::$failed;
echo "\n", str_repeat('═', 78), "\n";
echo "  RÉSULTAT : " . Report::$passed . " / {$total} vérifications réussies";
echo Report::$failed ? " — " . Report::$failed . " échec(s)\n" : " — tout est conforme\n";
foreach (Report::$failures as $failure) {
    echo "    ✘ {$failure}\n";
}
echo "  Base de test conservée : « " . TEST_DB_NAME . " » (phpMyAdmin pour voir les données)\n";
echo str_repeat('═', 78), "\n";
exit(Report::$failed ? 1 : 0);
