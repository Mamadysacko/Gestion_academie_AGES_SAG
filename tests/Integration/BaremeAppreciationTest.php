<?php

/**
 * Barème d'appréciation : ajout, modification et suppression, avec la règle de cohérence du serveur
 * (la plus petite note commence à 0, les seuils croissent strictement avec la note).
 * fixtures.sql : 1 Insuffisant (0) · 2 Passable (2) · 3 Bien (2,75) · 4 Très bien (3,5).
 */
final class BaremeAppreciationTest extends ControllerTestCase
{
    private function bareme(string $action, ?array $body = null, ?int $note = null): array
    {
        return $this->request(fn() => (new ResultatController())->crud('bareme', $action, $note), $body);
    }

    public function testListeDuBareme(): void
    {
        [$code, $json] = $this->bareme('list');

        self::assertSame(200, $code);
        self::assertSame(['Insuffisant', 'Passable', 'Bien', 'Très bien'], array_column($json['data'], 'libelle'));
    }

    public function testAjouterUneAppreciationApresUneSuppression(): void
    {
        self::assertSame(200, $this->bareme('delete', null, 3)[0]);
        self::assertSame(3, (int) $this->scalar('SELECT COUNT(*) FROM baremes_appreciation'));

        [$code] = $this->bareme('create', ['note' => 3, 'libelle' => 'Assez bien', 'seuil_min' => 2.9]);

        self::assertSame(201, $code);
        self::assertSame('Assez bien', $this->scalar('SELECT libelle FROM baremes_appreciation WHERE note = 3'));
    }

    public function testUneNoteDejaPresenteNePeutPasEtreAjoutee(): void
    {
        [$code, $json] = $this->bareme('create', ['note' => 2, 'libelle' => 'Doublon', 'seuil_min' => 2.2]);

        self::assertSame(400, $code);
        self::assertStringContainsString('existe déjà', $json['error']);
    }

    public function testModifierLeLibelleEtLeSeuil(): void
    {
        [$code] = $this->bareme('update', ['libelle' => 'Moyen', 'seuil_min' => 2.25], 2);

        self::assertSame(200, $code);
        self::assertSame('Moyen', $this->scalar('SELECT libelle FROM baremes_appreciation WHERE note = 2'));
        self::assertSame(2.25, (float) $this->scalar('SELECT seuil_min FROM baremes_appreciation WHERE note = 2'));
    }

    public function testUnSeuilNonCroissantEstRefuse(): void
    {
        // « Passable » ne peut pas dépasser « Bien » (2,75).
        [$code] = $this->bareme('update', ['seuil_min' => 3.0], 2);

        self::assertSame(400, $code);
        self::assertSame(2.0, (float) $this->scalar('SELECT seuil_min FROM baremes_appreciation WHERE note = 2'), 'le barème reste inchangé');
    }

    public function testSupprimerLaPlusBasseNoteEstRefuse(): void
    {
        // Sans la note 1 (seuil 0), certaines moyennes n'auraient aucune appréciation.
        [$code] = $this->bareme('delete', null, 1);

        self::assertSame(400, $code);
        self::assertSame(4, (int) $this->scalar('SELECT COUNT(*) FROM baremes_appreciation'));
    }

    public function testLaPlusHauteNotePeutEtreSupprimee(): void
    {
        [$code] = $this->bareme('delete', null, 4);

        self::assertSame(200, $code);
        self::assertSame(3, (int) $this->scalar('SELECT COUNT(*) FROM baremes_appreciation'));
    }
}
