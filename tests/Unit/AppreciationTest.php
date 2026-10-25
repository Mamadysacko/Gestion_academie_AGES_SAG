<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Appréciation (1 à 4) attribuée selon le barème : la plus haute dont le seuil est atteint. */
final class AppreciationTest extends TestCase
{
    private const BAREME = [
        ['note' => 1, 'libelle' => 'Insuffisant', 'seuil_min' => 0.0],
        ['note' => 2, 'libelle' => 'Passable', 'seuil_min' => 2.0],
        ['note' => 3, 'libelle' => 'Bien', 'seuil_min' => 2.75],
        ['note' => 4, 'libelle' => 'Très bien', 'seuil_min' => 3.5],
    ];

    /** @return array<string, array{float, int}> */
    public static function moyennes(): array
    {
        return [
            'zéro'                 => [0.0, 1],
            'juste sous le seuil'  => [1.99, 1],
            'seuil exact'          => [2.0, 2],
            'bien'                 => [3.2, 3],
            'seuil « très bien »'  => [3.5, 4],
            'maximum'              => [4.0, 4],
        ];
    }

    #[DataProvider('moyennes')]
    public function testNoteSelonLaMoyenne(float $moyenne, int $noteAttendue): void
    {
        self::assertSame($noteAttendue, ReglesEvaluation::appreciationFor($moyenne, self::BAREME)['note']);
    }

    public function testSansMoyenneAucuneAppreciation(): void
    {
        self::assertNull(ReglesEvaluation::appreciationFor(null, self::BAREME));
    }

    public function testSansBaremeAucuneAppreciation(): void
    {
        self::assertNull(ReglesEvaluation::appreciationFor(3.0, []));
    }
}
