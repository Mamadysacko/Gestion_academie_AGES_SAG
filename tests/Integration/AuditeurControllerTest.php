<?php

use PHPUnit\Framework\Attributes\DataProvider;

/** AuditeurController + Auditeur sur la base de test. */
final class AuditeurControllerTest extends ControllerTestCase
{
    public function testLaListeRenvoieLesLibellesDePositionEtDeType(): void
    {
        [$code, $json] = $this->request(fn() => (new AuditeurController())->getAll());

        self::assertSame(200, $code);
        self::assertCount(4, $json['data']);
        $diallo = current(array_filter($json['data'], fn($a) => $a['matricule'] === 'AUD-T-001'));
        self::assertSame('Chef de service', $diallo['position']);
        self::assertSame('SAG', $diallo['type_employe_libelle']);
    }

    public function testRechercheParNom(): void
    {
        [, $json] = $this->request(fn() => (new AuditeurController())->search('bah'));

        self::assertCount(1, $json['data']);
        self::assertSame('Mariam', $json['data'][0]['prenom']);
    }

    public function testCreationLieLaPositionChoisie(): void
    {
        [$code, $json] = $this->request(
            fn() => (new AuditeurController())->create(),
            ['matricule' => 'AUD-T-050', 'nom' => 'Keita', 'prenom' => 'Sekou', 'sexe' => 'M', 'telephone' => '620000050', 'id_position' => 3, 'id_type_employe' => 2]
        );

        self::assertSame(201, $code);
        self::assertSame(3, (int) $this->scalar('SELECT id_position FROM auditeurs WHERE id = ?', [$json['data']['id']]));
        self::assertSame((int) $json['data']['user_id'], (int) $this->scalar('SELECT user_id FROM auditeurs WHERE id = ?', [$json['data']['id']]));
        self::assertSame('620000050', $json['data']['login']);
        self::assertNotEmpty($json['data']['password_temporaire']);
        self::assertSame(1, (int) $this->scalar('SELECT first_login FROM users WHERE id = ?', [$json['data']['user_id']]));
    }

    /** @return array<string, array{array<string,mixed>, int}> */
    public static function creationsRefusees(): array
    {
        return [
            'matricule en double'   => [['matricule' => 'AUD-T-001', 'nom' => 'A', 'prenom' => 'B', 'telephone' => '620000001'], 409],
            'téléphone obligatoire même si e-mail présent' => [['matricule' => 'AUD-T-052', 'nom' => 'A', 'prenom' => 'B', 'email' => 'a@example.test'], 400],
            'nom manquant'          => [['matricule' => 'AUD-T-051', 'prenom' => 'B', 'telephone' => '620000051'], 400],
            'sexe invalide'         => [['matricule' => 'AUD-T-051', 'nom' => 'A', 'prenom' => 'B', 'telephone' => '620000051', 'sexe' => 'X'], 400],
            'e-mail invalide'       => [['matricule' => 'AUD-T-051', 'nom' => 'A', 'prenom' => 'B', 'email' => 'pas-un-mail', 'telephone' => '620000051'], 400],
            'position inexistante'  => [['matricule' => 'AUD-T-051', 'nom' => 'A', 'prenom' => 'B', 'telephone' => '620000051', 'id_position' => 999], 400],
            'type inexistant'       => [['matricule' => 'AUD-T-051', 'nom' => 'A', 'prenom' => 'B', 'telephone' => '620000051', 'id_type_employe' => 999], 400],
        ];
    }

    /** @param array<string,mixed> $donnees */
    #[DataProvider('creationsRefusees')]
    public function testCreationRefusee(array $donnees, int $codeAttendu): void
    {
        [$code] = $this->request(fn() => (new AuditeurController())->create(), $donnees);

        self::assertSame($codeAttendu, $code);
        self::assertSame(4, (int) $this->scalar('SELECT COUNT(*) FROM auditeurs'), 'aucun auditeur ne doit être créé');
    }

    public function testModificationPeutRetirerLaPosition(): void
    {
        [$code] = $this->request(fn() => (new AuditeurController())->update(1), ['id_position' => '']);

        self::assertSame(200, $code);
        self::assertNull($this->scalar('SELECT id_position FROM auditeurs WHERE id = 1'));
    }

    public function testModificationProvisionneUnComptePourUnAuditeurHistorique(): void
    {
        [$code, $json] = $this->request(
            fn() => (new AuditeurController())->update(4),
            ['telephone' => '620000054', 'email' => null]
        );

        self::assertSame(200, $code);
        self::assertSame(4, (int) $json['data']['id']);
        self::assertNotEmpty($json['data']['password_temporaire']);
        self::assertSame('620000054', $json['data']['login']);
        self::assertSame((int) $json['data']['user_id'], (int) $this->scalar('SELECT user_id FROM auditeurs WHERE id = 4'));
        self::assertSame(1, (int) $this->scalar('SELECT first_login FROM users WHERE id = ?', [$json['data']['user_id']]));
        self::assertSame(
            1,
            (int) $this->scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = ?', [$json['data']['user_id'], 'info'])
        );
        self::assertStringNotContainsString(
            $json['data']['password_temporaire'],
            (string) $this->scalar('SELECT message FROM notifications WHERE user_id = ? AND type = ?', [$json['data']['user_id'], 'info'])
        );
    }

    public function testCompteHistoriqueSansTelephoneNEstPasProvisionne(): void
    {
        [$code] = $this->request(
            fn() => (new AuditeurController())->update(4),
            ['email' => 'aicha@example.test']
        );

        self::assertSame(200, $code);
        self::assertNull($this->scalar('SELECT user_id FROM auditeurs WHERE id = 4'));
    }

    public function testAlertesSessionEtAbsenceVontAuxComptesAuditeurLies(): void
    {
        $userModel = new User();
        $userId = $userModel->create([
            'nom' => 'Diallo',
            'prenom' => 'Mamadou',
            'email' => null,
            'telephone' => '620000101',
            'password' => 'mot-de-passe-test',
            'role_id' => 5,
            'actif' => 1,
        ]);
        Database::getInstance()->prepare('UPDATE auditeurs SET user_id = :user_id WHERE id = 1')
            ->execute(['user_id' => $userId]);

        $notifications = new Notification();
        self::assertSame(
            1,
            $notifications->createForActiveAuditeursInSession(1, 'Évaluation planifiée', 'Une évaluation est prévue.')
        );
        self::assertTrue($notifications->createAbsenceAlertForInscription(1, '2026-10-19'));
        self::assertSame(
            1,
            (int) $this->scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = ?', [$userId, 'alerte_presence'])
        );
        self::assertSame(
            1,
            (int) $this->scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = ?', [$userId, 'alerte_evaluation'])
        );
    }

    public function testPremiereConnexionExigeUnNouveauMotDePasse(): void
    {
        [, $created] = $this->request(
            fn() => (new AuditeurController())->create(),
            ['matricule' => 'AUD-T-055', 'nom' => 'Keita', 'prenom' => 'Sali', 'telephone' => '620000055']
        );
        $userId = (int) $created['data']['user_id'];
        $this->loginAs($userId);
        Authorization::resetRequestState();

        [$code] = $this->request(
            fn() => (new AuditeurEspaceController())->updateProfil(),
            ['first_login_done' => true]
        );

        self::assertSame(400, $code);
        self::assertSame(1, (int) $this->scalar('SELECT first_login FROM users WHERE id = ?', [$userId]));
    }

    public function testLesEcransDeLEspaceAuditeurChargentLesDonneesDuSchemaReel(): void
    {
        $userId = (new User())->create([
            'nom' => 'Diallo',
            'prenom' => 'Mamadou',
            'email' => null,
            'telephone' => '620000102',
            'password' => 'mot-de-passe-test',
            'role_id' => 5,
            'actif' => 1,
        ]);
        Database::getInstance()->prepare('UPDATE auditeurs SET user_id = :user_id WHERE id = 1')
            ->execute(['user_id' => $userId]);
        $this->loginAs($userId);
        Authorization::resetRequestState();
        $controller = new AuditeurEspaceController();

        [$profileCode, $profile] = $this->request(fn() => $controller->getProfil());
        self::assertSame(200, $profileCode);
        self::assertSame('AUD-T-001', $profile['data']['auditeur']['matricule']);
        self::assertSame('Chef de service', $profile['data']['auditeur']['position']);
        self::assertSame('SAG', $profile['data']['auditeur']['type_employe_libelle']);
        self::assertCount(1, $profile['data']['inscriptions']);

        [$scheduleCode, $schedule] = $this->request(fn() => $controller->getEmploiDuTemps());
        self::assertSame(200, $scheduleCode);
        self::assertSame('Salle 1', $schedule['data'][0]['salle_nom']);
        self::assertSame('Fatou Camara', $schedule['data'][0]['formateur_nom']);

        [$dashboardCode] = $this->request(fn() => $controller->getDashboard());
        self::assertSame(200, $dashboardCode);
        [$presenceCode] = $this->request(fn() => $controller->getPresences());
        self::assertSame(200, $presenceCode);
        [$notesCode] = $this->request(fn() => $controller->getNotes());
        self::assertSame(200, $notesCode);
        [$bulletinCode] = $this->request(fn() => $controller->getBulletin());
        self::assertSame(200, $bulletinCode);
        [$notificationsCode] = $this->request(fn() => $controller->getNotifications());
        self::assertSame(200, $notificationsCode);
        [$alertsCode] = $this->request(fn() => $controller->getAlertes());
        self::assertSame(200, $alertsCode);
    }

    public function testUnAuditeurInscritNePeutPasEtreSupprime(): void
    {
        [$code] = $this->request(fn() => (new AuditeurController())->delete(1));
        self::assertSame(409, $code);
    }

    public function testUnAuditeurSansInscriptionPeutEtreSupprime(): void
    {
        [$code] = $this->request(fn() => (new AuditeurController())->delete(4));

        self::assertSame(200, $code);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM auditeurs WHERE id = 4'));
    }
}
