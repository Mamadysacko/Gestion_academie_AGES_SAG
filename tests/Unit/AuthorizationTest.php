<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Quelles requêtes demandent le droit « Passages de niveau » ? (logique pure, sans base de données) */
final class AuthorizationTest extends TestCase
{
    /** @return array<string, array{string, string, ?string, bool}> */
    public static function requetes(): array
    {
        return [
            'approuver un passage'         => ['resultats', 'POST', 'valider', true],
            'annuler un passage'           => ['resultats', 'POST', 'annuler', true],
            'consulter les résultats'      => ['resultats', 'GET', 'session', false],
            'modifier un passage (ancien)' => ['passages-niveaux', 'PUT', null, true],
            'supprimer un passage (ancien)' => ['passages-niveaux', 'DELETE', null, true],
            'consulter les passages'       => ['passages-niveaux', 'GET', null, false],
            'saisir une note'              => ['notes', 'POST', 'save-or-update', false],
        ];
    }

    #[DataProvider('requetes')]
    public function testDetecteLesApprobationsDePassage(string $ressource, string $methode, ?string $param1, bool $attendu): void
    {
        self::assertSame($attendu, Authorization::isPassageApproval($ressource, $methode, $param1));
    }

    public function testLeModuleDeDroitEstCelDesPassages(): void
    {
        self::assertSame('passages-niveau', Authorization::PASSAGE_MODULE);
    }
}
