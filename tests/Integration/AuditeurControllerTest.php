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
            ['matricule' => 'AUD-T-050', 'nom' => 'Keita', 'prenom' => 'Sekou', 'sexe' => 'M', 'id_position' => 3, 'id_type_employe' => 2]
        );

        self::assertSame(201, $code);
        self::assertSame(3, (int) $this->scalar('SELECT id_position FROM auditeurs WHERE id = ?', [$json['data']['id']]));
    }

    /** @return array<string, array{array<string,mixed>, int}> */
    public static function creationsRefusees(): array
    {
        return [
            'matricule en double'   => [['matricule' => 'AUD-T-001', 'nom' => 'A', 'prenom' => 'B'], 409],
            'nom manquant'          => [['matricule' => 'AUD-T-051', 'prenom' => 'B'], 400],
            'sexe invalide'         => [['matricule' => 'AUD-T-051', 'nom' => 'A', 'prenom' => 'B', 'sexe' => 'X'], 400],
            'e-mail invalide'       => [['matricule' => 'AUD-T-051', 'nom' => 'A', 'prenom' => 'B', 'email' => 'pas-un-mail'], 400],
            'position inexistante'  => [['matricule' => 'AUD-T-051', 'nom' => 'A', 'prenom' => 'B', 'id_position' => 999], 400],
            'type inexistant'       => [['matricule' => 'AUD-T-051', 'nom' => 'A', 'prenom' => 'B', 'id_type_employe' => 999], 400],
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
