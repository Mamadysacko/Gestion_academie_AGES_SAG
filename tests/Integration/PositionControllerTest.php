<?php

use PHPUnit\Framework\Attributes\DataProvider;

/** PositionController + Position sur la base de test (chaque test est annulé à la fin). */
final class PositionControllerTest extends ControllerTestCase
{
    public function testListeLesPositionsAvecLeNombreDAuditeurs(): void
    {
        [$code, $json] = $this->request(fn() => (new PositionController())->getAll());

        self::assertSame(200, $code);
        self::assertCount(3, $json['data']);
        $operateur = current(array_filter($json['data'], fn($p) => $p['libelle'] === 'Opérateur'));
        self::assertSame(1, (int) $operateur['nb_auditeurs']);
    }

    public function testPositionInconnueRenvoie404(): void
    {
        [$code] = $this->request(fn() => (new PositionController())->getById(999));
        self::assertSame(404, $code);
    }

    public function testCreationValide(): void
    {
        [$code, $json] = $this->request(fn() => (new PositionController())->create(), ['libelle' => 'Directeur']);

        self::assertSame(201, $code);
        self::assertSame('Directeur', $this->scalar('SELECT libelle FROM positions WHERE id = ?', [$json['data']['id']]));
    }

    public function testLibelleEnDoubleEstRefuseSansTenirCompteDeLaCasse(): void
    {
        [$code] = $this->request(fn() => (new PositionController())->create(), ['libelle' => 'chef DE service']);

        self::assertSame(409, $code);
        self::assertSame(3, (int) $this->scalar('SELECT COUNT(*) FROM positions'));
    }

    /** @return array<string, array{string}> */
    public static function libellesInvalides(): array
    {
        return ['vide' => [''], 'espaces' => ['   '], 'trop long' => [str_repeat('a', 101)]];
    }

    #[DataProvider('libellesInvalides')]
    public function testLibelleInvalideEstRefuse(string $libelle): void
    {
        [$code] = $this->request(fn() => (new PositionController())->create(), ['libelle' => $libelle]);
        self::assertSame(400, $code);
    }

    public function testModificationRefuseUnLibelleDejaPris(): void
    {
        [$code] = $this->request(fn() => (new PositionController())->update(1), ['libelle' => 'Opérateur']);
        self::assertSame(409, $code);
    }

    public function testSupprimerUnePositionUtiliseeConserveLAuditeur(): void
    {
        // Mariam Bah (auditeur 2) a la position « Opérateur » (2).
        [$code] = $this->request(fn() => (new PositionController())->delete(2));

        self::assertSame(200, $code);
        self::assertNull($this->scalar('SELECT id_position FROM auditeurs WHERE id = 2'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM auditeurs WHERE id = 2'));
    }
}
