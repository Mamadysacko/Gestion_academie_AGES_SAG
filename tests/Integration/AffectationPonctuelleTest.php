<?php

final class AffectationPonctuelleTest extends ControllerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->scalar("INSERT INTO salles (id, code, libelle, capacite) VALUES (2, 'S02', 'Salle 2', 20)");
        $this->scalar("INSERT INTO salles (id, code, libelle, capacite) VALUES (3, 'S03', 'Salle 3', 20)");
        $this->scalar("INSERT INTO users (id, nom, prenom, email, password, role_id, actif) VALUES (20, 'Diallo', 'Aminata', 'aminata.formateur@test.local', 'x', 4, 1)");
        $this->scalar("INSERT INTO formateurs (id, user_id, matricule, id_salle) VALUES (2, 20, 'FOR-T-002', 2)");
    }

    public function testUneAffectationPonctuelleConserveLaSallePrincipaleEtEstVisibleDansLeProfil(): void
    {
        [$status, $response] = $this->request(
            fn() => (new AffectationPonctuelleController())->create(),
            [
                'id_emploi_du_temps' => 1,
                'id_formateur' => 2,
                'id_salle' => 3,
                'date_affectation' => '2026-10-05',
                'motif' => 'Remplacement exceptionnel',
            ]
        );

        self::assertSame(201, $status, json_encode($response, JSON_UNESCAPED_UNICODE));
        self::assertSame(3, (int) $this->scalar('SELECT id_salle FROM affectations_ponctuelles WHERE id = ?', [$response['data']['id']]));
        self::assertSame(2, (int) $this->scalar('SELECT id_salle FROM formateurs WHERE id = 2'));
        self::assertSame(1, (int) $this->scalar('SELECT id_salle FROM emplois_du_temps WHERE id = 1'));

        $this->loginAs(20);
        [$portalStatus, $portal] = $this->request(fn() => (new ProfileController())->getTrainerPortal());
        self::assertSame(200, $portalStatus);
        self::assertSame(1, count($portal['data']['substitutions']));
        self::assertSame('S03', $portal['data']['substitutions'][0]['salle_code']);
        self::assertSame('S02', $portal['data']['trainer']['salle_principale_code']);
        self::assertSame(3, count($portal['data']['substitution_learners']));
    }

    public function testUneAffectationPonctuelleEstRefuseeSiLeFormateurEstDejaPris(): void
    {
        $this->scalar("INSERT INTO groupes (id, id_session, code, libelle, jour_semaine) VALUES (10, 1, 'G-B', 'Groupe B', 1)");
        $this->scalar("INSERT INTO emplois_du_temps (id, id_annee, id_session, id_formateur, id_groupe, id_salle, jour_semaine, heure_debut, heure_fin) VALUES (10, 1, 1, 2, 10, 2, 1, '09:00:00', '11:00:00')");

        [$status, $response] = $this->request(
            fn() => (new AffectationPonctuelleController())->create(),
            [
                'id_emploi_du_temps' => 1,
                'id_formateur' => 2,
                'id_salle' => 3,
                'date_affectation' => '2026-10-05',
                'motif' => 'Remplacement exceptionnel',
            ]
        );

        self::assertSame(409, $status);
        self::assertStringContainsString('déjà un cours', $response['error']);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM affectations_ponctuelles'));
    }

    public function testLaDateDoitCorrespondreAuJourHabituelDuGroupe(): void
    {
        [$status, $response] = $this->request(
            fn() => (new AffectationPonctuelleController())->create(),
            [
                'id_emploi_du_temps' => 1,
                'id_formateur' => 2,
                'id_salle' => 3,
                'date_affectation' => '2026-10-06',
                'motif' => 'Remplacement exceptionnel',
            ]
        );

        self::assertSame(409, $status);
        self::assertStringContainsString('jour habituel', $response['error']);
    }
}
