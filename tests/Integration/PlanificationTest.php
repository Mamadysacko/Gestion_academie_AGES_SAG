<?php

/**
 * Planification automatique sur la base de test : configuration versionnée des formations,
 * salles dédiées, simulation / application, sessions successives, reprise de configuration,
 * créneaux manuels contrôlés, passages multiples et présences.
 *
 * Fixtures : formation 1, session 1 (07/09 → 18/12/2026, groupe G-A le lundi en salle S01 avec 3 inscrits),
 * session 2 (11/01 → 02/04/2027) sans groupe.
 */
final class PlanificationTest extends ControllerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Trois salles de plus, chacune avec son formateur titulaire (un compte + sa fiche).
        foreach ([2, 3, 4] as $n) {
            $this->scalar("INSERT INTO salles (id, code, libelle, capacite) VALUES ({$n}, 'S0{$n}', 'Salle {$n}', 20)");
            $this->scalar("INSERT INTO users (id, nom, prenom, email, password, role_id) VALUES (1{$n}, 'Formateur', 'N{$n}', 'formateur{$n}@test.local', 'x', 4)");
            $this->scalar("INSERT INTO formateurs (id, user_id, matricule, id_salle) VALUES ({$n}, 1{$n}, 'FOR-T-00{$n}', {$n})");
        }
    }

    private function configure(int $formation, array $config): array
    {
        [$code, $json] = $this->request(fn() => (new FormationController())->updateConfiguration($formation), $config);
        self::assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        return $json['data'];
    }

    private function setRooms(int $formation, array $rooms): array
    {
        return $this->request(fn() => (new FormationController())->setRooms($formation), ['salles' => $rooms]);
    }

    private function createSession(array $data): array
    {
        [$code, $json] = $this->request(fn() => (new SessionController())->create(), $data + [
            'id_annee' => 1, 'id_type_formation' => 1, 'id_niveau' => 2, 'statut' => 'planifiee',
        ]);
        self::assertSame(201, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        return $json['data'];
    }

    private function activeSlots(int $session): array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM emplois_du_temps WHERE id_session = ? AND statut = 1 ORDER BY id_groupe, jour_semaine, id_salle');
        $stmt->execute([$session]);
        return $stmt->fetchAll();
    }

    /* ── Configuration de référence ─────────────────────────────────────── */

    public function testModifierLaConfigurationCreeUneVersionTraceeSansToucherAuxSessionsExistantes(): void
    {
        $session = $this->createSession(['code' => 'S3', 'libelle' => 'Session 3', 'date_debut' => '2027-04-12', 'date_fin' => '2027-06-25', 'reprendre_configuration' => false]);
        self::assertSame(1, $session['configuration']['passages_semaine']);

        $config = $this->configure(1, ['passages_semaine' => 2, 'nb_groupes' => 3, 'motif' => 'Rythme renforcé']);
        self::assertTrue($config['version_creee']);
        self::assertSame(2, (int) $config['config_version']);

        [$code, $history] = $this->request(fn() => (new FormationController())->getHistory(1));
        self::assertSame(200, $code);
        self::assertSame('Rythme renforcé', $history['data'][0]['motif']);
        self::assertSame(2, $history['data'][0]['configuration']['passages_semaine']);

        // La session créée avant garde son instantané (1 passage).
        self::assertSame(1, (int) $this->scalar('SELECT passages_semaine FROM sessions WHERE id = ?', [$session['id']]));
        $snapshot = json_decode((string) $this->scalar('SELECT config_formation FROM sessions WHERE id = ?', [$session['id']]), true);
        self::assertSame(1, $snapshot['passages_semaine']);

        // Adoption explicite et tracée de la nouvelle version.
        [$codeAdopt, $adopt] = $this->request(fn() => (new PlanificationController())->adopterConfiguration($session['id']));
        self::assertSame(200, $codeAdopt);
        self::assertSame(2, $adopt['data']['version']);
        self::assertSame(2, (int) $this->scalar('SELECT passages_semaine FROM sessions WHERE id = ?', [$session['id']]));
    }

    public function testUneConfigurationIncoherenteEstRefusee(): void
    {
        [$code, $json] = $this->request(fn() => (new FormationController())->updateConfiguration(1), ['passages_semaine' => 3, 'jours_autorises' => [1, 2]]);
        self::assertSame(400, $code);
        self::assertStringContainsString('3 passage(s)', $json['error']);

        [$code2] = $this->request(fn() => (new FormationController())->updateConfiguration(1), ['heure_debut' => '14:00', 'heure_fin' => '10:00']);
        self::assertSame(400, $code2);

        [$code3, $json3] = $this->request(fn() => (new FormationController())->updateConfiguration(1), ['creneaux' => [['debut' => '08:00', 'fin' => '12:00'], ['debut' => '11:00', 'fin' => '15:00']]]);
        self::assertSame(400, $code3);
        self::assertStringContainsString('se chevauchent', $json3['error']);
    }

    /* ── Salles dédiées ──────────────────────────────────────────────────── */

    public function testUneSalleDedieeNePeutPasEtreDonneeAUneAutreFormationSansAutorisation(): void
    {
        [$code] = $this->setRooms(1, [['id_salle' => 1, 'mode' => 'dediee'], ['id_salle' => 2, 'mode' => 'dediee']]);
        self::assertSame(200, $code);

        $this->scalar("INSERT INTO types_formations (id, libelle) VALUES (2, 'Numératie')");
        [$codeDedie, $json] = $this->setRooms(2, [['id_salle' => 1, 'mode' => 'dediee']]);
        self::assertSame(409, $codeDedie);
        self::assertStringContainsString('déjà dédiée', $json['error']);

        [$codeSansMotif] = $this->setRooms(2, [['id_salle' => 1, 'mode' => 'partagee']]);
        self::assertSame(400, $codeSansMotif, 'Le partage exige un motif');

        $this->scalar("INSERT INTO role_permissions (role_id, module_code, action_code) VALUES (2, 'organisation-pedagogique', 'update')");
        $this->loginAs(2); // secrétaire : pas le droit « Valider » des salles
        [$codeRefuse] = $this->setRooms(2, [['id_salle' => 1, 'mode' => 'partagee', 'motif' => 'Examen']]);
        self::assertSame(403, $codeRefuse);

        $this->loginAs(1);
        [$codeOk, $rooms] = $this->setRooms(2, [['id_salle' => 1, 'mode' => 'partagee', 'motif' => 'Examen commun']]);
        self::assertSame(200, $codeOk);
        self::assertSame('partagee', $rooms['data'][0]['mode']);
        self::assertSame('Test Admin', $rooms['data'][0]['autorise_par']);
    }

    public function testUnCreneauManuelDansUneSalleDedieeAUneAutreFormationEstRefuse(): void
    {
        $this->scalar("INSERT INTO types_formations (id, libelle) VALUES (2, 'Numératie')");
        $this->setRooms(2, [['id_salle' => 3, 'mode' => 'dediee']]);
        // La session 2 (formation 1) tente d'utiliser S03, dédiée à « Numératie ».
        $this->scalar("INSERT INTO groupes (id, id_session, code, libelle, jour_semaine) VALUES (10, 2, 'G1', 'Groupe 1', 2)");

        [$code, $json] = $this->request(fn() => (new EmploiDuTempsController())->create(), ['id_annee' => 1, 'id_groupe' => 10, 'id_salle' => 3, 'jour_semaine' => 2]);
        self::assertSame(409, $code);
        self::assertStringContainsString('dédiée à la formation « Numératie »', $json['error']);
    }

    /* ── Simulation et application ───────────────────────────────────────── */

    public function testSimulerNEcritRienEtAppliquerCreeGroupesPassagesEtCreneaux(): void
    {
        $this->configure(1, ['passages_semaine' => 2, 'nb_groupes' => 2, 'salles_par_groupe' => 2]);
        $this->scalar('UPDATE sessions SET config_formation = NULL WHERE id = 2'); // suit la configuration actuelle

        [$code, $sim] = $this->request(fn() => (new PlanificationController())->simuler(2), ['mode' => 'completer']);
        self::assertSame(200, $code, json_encode($sim, JSON_UNESCAPED_UNICODE));
        self::assertTrue($sim['data']['complete']);
        self::assertSame(['G1', 'G2'], $sim['data']['groupes_a_creer']);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM groupes WHERE id_session = 2'), 'La simulation n’écrit rien');

        [$codeApply, $applied] = $this->request(fn() => (new PlanificationController())->appliquer(2), ['mode' => 'completer']);
        self::assertSame(200, $codeApply, json_encode($applied, JSON_UNESCAPED_UNICODE));
        self::assertSame(8, $applied['data']['creneaux_crees'], '2 groupes × 2 passages × 2 salles');
        self::assertCount(2, $applied['data']['groupes_crees']);

        $slots = $this->activeSlots(2);
        self::assertCount(8, $slots);
        foreach ($slots as $slot) {
            self::assertSame('automatique', $slot['origine']);
            self::assertSame((int) $this->scalar('SELECT id FROM formateurs WHERE id_salle = ?', [$slot['id_salle']]), (int) $slot['id_formateur'], 'Le formateur est le titulaire de la salle');
        }
        self::assertSame(4, (int) $this->scalar('SELECT COUNT(*) FROM groupe_passages gp JOIN groupes g ON g.id = gp.id_groupe WHERE g.id_session = 2'));
        self::assertSame('appliquee', $this->scalar('SELECT statut FROM planification_generations WHERE id = ?', [$applied['data']['id_generation']]));
        self::assertSame([], array_filter((new Planification())->conflicts(null, 2), static fn($c) => $c['gravite'] === 'erreur'));
    }

    public function testUneAffectationImpossibleEstExpliqueeEtRienNEstEcrit(): void
    {
        $this->configure(1, ['passages_semaine' => 1, 'nb_groupes' => 6, 'salles_par_groupe' => 4]);
        $this->scalar('UPDATE sessions SET config_formation = NULL WHERE id = 2');

        [$code, $json] = $this->request(fn() => (new PlanificationController())->appliquer(2), []);
        self::assertSame(409, $code);
        self::assertNotEmpty($json['details']['diagnostic']['bloquant']);
        self::assertStringContainsString('Capacité insuffisante', $json['details']['diagnostic']['bloquant'][0]);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM groupes WHERE id_session = 2'));

        [$codePartial, $partial] = $this->request(fn() => (new PlanificationController())->appliquer(2), ['accepter_partiel' => true]);
        self::assertSame(200, $codePartial);
        self::assertFalse($partial['data']['complete']);
        self::assertSame(5, (int) $this->scalar('SELECT COUNT(*) FROM groupes WHERE id_session = 2'), '5 jours × 1 groupe de 4 salles');
    }

    public function testLesCreneauxAvecHistoriqueNeSontJamaisDeplaces(): void
    {
        $this->configure(1, ['passages_semaine' => 2, 'salles_par_groupe' => 1]);
        $this->scalar('UPDATE sessions SET config_formation = NULL WHERE id = 1');

        [$code, $json] = $this->request(fn() => (new PlanificationController())->appliquer(1), ['mode' => 'regenerer']);
        self::assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));

        // G-A (3 inscrits) garde son lundi en S01 et reçoit seulement un second passage dans la même salle.
        $slots = $this->activeSlots(1);
        self::assertSame(1, (int) $slots[0]['id'], 'Le créneau d’origine est conservé');
        self::assertCount(2, $slots);
        self::assertSame([1, 1], array_map(static fn($s) => (int) $s['id_salle'], $slots));
        self::assertSame(1, (int) $slots[0]['jour_semaine']);
    }

    /* ── Sessions successives et reprise de configuration ────────────────── */

    public function testDeuxSessionsSuccessivesPeuventUtiliserLaMemeSalleLeMemeJour(): void
    {
        $this->scalar("INSERT INTO groupes (id, id_session, code, libelle, jour_semaine) VALUES (10, 2, 'G1', 'Groupe 1', 1)");
        [$code, $json] = $this->request(fn() => (new EmploiDuTempsController())->create(), ['id_annee' => 1, 'id_groupe' => 10, 'id_salle' => 1, 'jour_semaine' => 1]);

        self::assertSame(201, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        $doubleBookings = array_filter((new Planification())->conflicts(1, null), static fn($c) => in_array($c['type'], ['salle', 'formateur'], true));
        self::assertSame([], array_values($doubleBookings), 'Les dates ne se recoupent pas : aucun conflit');
    }

    public function testDeuxSessionsSimultaneesNePeuventPasPartagerUneSalle(): void
    {
        $this->scalar("INSERT INTO sessions (id, id_annee, id_type_formation, id_niveau, code, libelle, date_debut, date_fin) VALUES (5, 1, 1, 2, 'S-PAR', 'Session parallèle', '2026-11-02', '2027-01-29')");
        $this->scalar("INSERT INTO groupes (id, id_session, code, libelle, jour_semaine) VALUES (10, 5, 'G1', 'Groupe 1', 1)");

        [$code, $json] = $this->request(fn() => (new EmploiDuTempsController())->create(), ['id_annee' => 1, 'id_groupe' => 10, 'id_salle' => 1, 'jour_semaine' => 1]);
        self::assertSame(409, $code);
        self::assertStringContainsString('La salle S01 est déjà occupée', $json['error']);
        self::assertNotEmpty($json['details']['alternatives']['autres_salles'], 'Des salles libres sont proposées');
        self::assertNotEmpty($json['details']['alternatives']['autres_jours']);
    }

    public function testUneNouvelleSessionRepritAutomatiquementLaConfigurationPrecedente(): void
    {
        // Nouvelle session juste après la session 1 (qui se termine le 18/12/2026) : les périodes ne se recoupent pas.
        $created = $this->createSession(['code' => 'S1-BIS', 'libelle' => 'Débutant 2', 'date_debut' => '2026-12-21', 'date_fin' => '2027-01-08', 'id_niveau' => 1]);

        $reprise = $created['reprise'];
        self::assertSame(1, $reprise['session_source']['id'], 'Dernière session de la même formation commencée avant');
        self::assertSame('G-A', $reprise['groupes_repris'][0]['code']);
        self::assertTrue($reprise['planification']['complete']);

        // Même jour et même salle qu'à la session précédente : la salle est libre sur la nouvelle période.
        $slots = $this->activeSlots($created['id']);
        self::assertCount(1, $slots);
        self::assertSame(1, (int) $slots[0]['jour_semaine']);
        self::assertSame(1, (int) $slots[0]['id_salle']);
        self::assertSame('reprise', $slots[0]['origine']);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM inscriptions WHERE id_session = ?', [$created['id']]), 'Les inscriptions ne sont pas copiées');
    }

    public function testLaRepriseSAdapteAuxDisponibilitesDeLaNouvellePeriode(): void
    {
        // La nouvelle session chevauche la session 1 : S01 est prise le lundi, un autre créneau est trouvé.
        $created = $this->createSession(['code' => 'S1-TER', 'libelle' => 'Débutant 3', 'date_debut' => '2026-12-01', 'date_fin' => '2027-02-26', 'id_niveau' => 1, 'id_session_source' => 1]);

        self::assertTrue($created['reprise']['planification']['complete']);
        $slot = $this->activeSlots($created['id'])[0];
        self::assertFalse((int) $slot['jour_semaine'] === 1 && (int) $slot['id_salle'] === 1, 'Pas de double réservation de S01 le lundi');
        self::assertSame([], array_filter((new Planification())->conflicts(null, $created['id']), static fn($c) => $c['gravite'] === 'erreur'));
    }

    /* ── Créneaux manuels et passages ────────────────────────────────────── */

    public function testUnJourHorsPassagesEstRefuseQuandLesPassagesSontComplets(): void
    {
        [$code, $json] = $this->request(fn() => (new EmploiDuTempsController())->create(), ['id_annee' => 1, 'id_groupe' => 1, 'id_salle' => 2, 'jour_semaine' => 3]);
        self::assertSame(409, $code);
        self::assertStringContainsString('a déjà ses 1 passage(s)', $json['error']);
    }

    public function testUnNouveauPassageEstAjouteSiLaConfigurationLePermet(): void
    {
        $this->configure(1, ['passages_semaine' => 2]);
        $this->scalar('UPDATE sessions SET config_formation = NULL WHERE id = 1');

        [$code, $json] = $this->request(fn() => (new EmploiDuTempsController())->create(), ['id_annee' => 1, 'id_groupe' => 1, 'id_salle' => 1, 'jour_semaine' => 3]);
        self::assertSame(201, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        self::assertTrue($json['data']['nouveau_passage']);
        self::assertSame('1,3', $this->scalar('SELECT GROUP_CONCAT(jour_semaine ORDER BY jour_semaine) FROM groupe_passages WHERE id_groupe = 1'));
    }

    public function testRedefinirLesPassagesReproduitLesSallesDuGroupe(): void
    {
        $this->configure(1, ['passages_semaine' => 2]);
        $this->scalar('UPDATE sessions SET config_formation = NULL WHERE id = 1');

        [$code, $json] = $this->request(fn() => (new PlanificationController())->passages(1), ['jours' => [1, 4]]);
        self::assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        self::assertSame(1, $json['data']['creneaux_crees']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM emplois_du_temps WHERE id_groupe = 1 AND jour_semaine = 4 AND id_salle = 1'));

        [$codeTooMany] = $this->request(fn() => (new PlanificationController())->passages(1), ['jours' => [1, 2, 4]]);
        self::assertSame(409, $codeTooMany);
    }

    public function testLesAuditeursSontAttendusAChaqueJourDePassage(): void
    {
        $this->configure(1, ['passages_semaine' => 2]);
        $this->scalar('UPDATE sessions SET config_formation = NULL WHERE id = 1');
        $this->request(fn() => (new PlanificationController())->passages(1), ['jours' => [1, 3]]);

        $presence = new Presence();
        $wednesday = $presence->getFeuille('2026-10-07'); // mercredi
        self::assertCount(3, $wednesday['auditeurs']);
        $dayRow = (int) $this->scalar('SELECT id FROM emplois_du_temps WHERE id_groupe = 1 AND jour_semaine = 3');
        self::assertSame($dayRow, (int) $wednesday['auditeurs'][0]['id_emploi_du_temps']);

        self::assertTrue($presence->markPresence(1, '2026-10-07', 'present', 1));
        self::assertSame($dayRow, (int) $this->scalar("SELECT id_emploi_du_temps FROM presences WHERE id_inscription = 1 AND date_presence = '2026-10-07'"));

        $this->expectException(DomainException::class);
        $presence->markPresence(1, '2026-10-06', 'present', 1); // mardi : pas un jour de passage
    }

    public function testVerifierSignaleLesConflitsEtProposeDesAlternatives(): void
    {
        $this->scalar("INSERT INTO sessions (id, id_annee, id_type_formation, id_niveau, code, libelle, date_debut, date_fin) VALUES (5, 1, 1, 2, 'S-PAR', 'Session parallèle', '2026-11-02', '2027-01-29')");
        $this->scalar("INSERT INTO groupes (id, id_session, code, libelle, jour_semaine) VALUES (10, 5, 'G1', 'Groupe 1', 1)");
        $this->scalar("INSERT INTO groupe_passages (id_groupe, jour_semaine, heure_debut, heure_fin) VALUES (10, 1, '08:00:00', '12:00:00')");

        [$code, $json] = $this->request(fn() => (new PlanificationController())->verifier(), ['id_groupe' => 10, 'id_salle' => 1, 'jour_semaine' => 1]);
        self::assertSame(200, $code);
        self::assertFalse($json['data']['disponible']);
        self::assertSame('salle', $json['data']['conflits'][0]['type']);

        [, $free] = $this->request(fn() => (new PlanificationController())->verifier(), ['id_groupe' => 10, 'id_salle' => 2, 'jour_semaine' => 1]);
        self::assertTrue($free['data']['disponible']);
    }

    /* ── Synthèse ────────────────────────────────────────────────────────── */

    public function testLaSyntheseCompteLesSeancesEtLesPassages(): void
    {
        [$code, $json] = $this->request(fn() => (new PlanificationController())->synthese(), null, ['id_session' => '1', 'date' => '2026-10-01']);
        self::assertSame(200, $code);
        $session = $json['data']['sessions'][0];

        // Lundis du 07/09 au 18/12/2026 : 15 séances, dont 4 avant le 01/10.
        self::assertSame(15, $session['seances_programmees']);
        self::assertSame(4, $session['seances_realisees']);
        self::assertSame(11, $session['seances_restantes']);
        self::assertSame(1, $session['passages_requis']);
        self::assertSame(1, $session['passages_planifies']);
        self::assertNotEmpty($json['data']['occupation']['salles']);
    }

    public function testCompteDesJoursDeLaSemaine(): void
    {
        self::assertSame(15, Planification::countWeekday('2026-09-07', '2026-12-18', 1));
        self::assertSame(0, Planification::countWeekday('2026-09-08', '2026-09-13', 1));
        self::assertSame(1, Planification::countWeekday('2026-09-07', '2026-09-07', 1));
    }
}
