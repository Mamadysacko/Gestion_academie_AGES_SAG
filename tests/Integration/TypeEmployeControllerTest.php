<?php

/**
 * Exemple pas à pas du guide (tests/GUIDE_PRATIQUE.md) : tester un contrôleur qu'on n'a pas encore couvert.
 */
final class TypeEmployeControllerTest extends ControllerTestCase
{
    public function testListeLesTypesAvecLeNombreDAuditeurs(): void
    {
        // 1. Agir : appeler le contrôleur comme le ferait l'API.
        [$code, $json] = $this->request(fn() => (new TypeEmployeController())->getAll());

        // 2. Vérifier : code HTTP, puis contenu (les 2 types viennent de fixtures.sql).
        self::assertSame(200, $code);
        self::assertCount(2, $json['data']);
        $sag = current(array_filter($json['data'], fn($t) => $t['libelle'] === 'SAG'));
        self::assertSame(2, (int) $sag['nb_auditeurs']); // Diallo et Sow
    }

    public function testCreationEnregistreLeTypeEnBase(): void
    {
        [$code, $json] = $this->request(fn() => (new TypeEmployeController())->create(), ['libelle' => 'Stagiaire']);

        self::assertSame(201, $code);
        // Toujours contrôler la base : la réponse JSON ne prouve pas que la ligne existe.
        self::assertSame('Stagiaire', $this->scalar('SELECT libelle FROM types_employes WHERE id = ?', [$json['data']['id']]));
    }

    public function testUnLibelleEnDoubleEstRefuse(): void
    {
        [$code] = $this->request(fn() => (new TypeEmployeController())->create(), ['libelle' => 'SAG']);

        self::assertSame(409, $code);
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM types_employes'));
    }

    public function testTypeInconnuRenvoie404(): void
    {
        [$code] = $this->request(fn() => (new TypeEmployeController())->getById(999));
        self::assertSame(404, $code);
    }
}
