<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Texte de la colonne « Observation » : période de congé / permission puis observation saisie. */
final class PresenceObservationTest extends TestCase
{
    /** @return array<string, array{?string, ?string, ?string, ?string, ?string}> */
    public static function cas(): array
    {
        return [
            'congé sur plusieurs jours'      => ['conge', '2026-10-01', '2026-10-05', null, 'Congé du 01/10/2026 au 05/10/2026'],
            'congé avec observation'         => ['conge', '2026-10-01', '2026-10-05', 'Voyage', 'Congé du 01/10/2026 au 05/10/2026 — Voyage'],
            'permission d’un seul jour'      => ['permissionnaire', '2026-10-02', '2026-10-02', null, 'Permission le 02/10/2026'],
            'observation seule (absent)'     => ['absent', null, null, '  Malade  ', 'Malade'],
            'rien à afficher'                => ['present', null, null, null, null],
            'observation vide ignorée'       => ['present', null, null, '   ', null],
            'période sans dates ignorée'     => ['conge', null, null, null, null],
        ];
    }

    #[DataProvider('cas')]
    public function testLibelleDObservation(?string $statut, ?string $debut, ?string $fin, ?string $observation, ?string $attendu): void
    {
        self::assertSame($attendu, Presence::observationLabel($statut, $debut, $fin, $observation));
    }
}
