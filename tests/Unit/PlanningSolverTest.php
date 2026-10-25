<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Moteur d'affectation hebdomadaire (logique pure, sans base de données). */
final class PlanningSolverTest extends TestCase
{
    /** Salles S1…Sn, chacune avec son formateur titulaire (100 + n). */
    private static function rooms(int $count): array
    {
        return array_map(static fn($i) => ['id' => $i, 'code' => "S{$i}", 'formateur' => 100 + $i], range(1, $count));
    }

    private static function groupList(int $count): array
    {
        return array_map(static fn($i) => ['cle' => "G{$i}", 'code' => "G{$i}"], range(1, $count));
    }

    private static function solve(array $input): array
    {
        return (new PlanningSolver($input + ['creneaux' => [['debut' => '08:00', 'fin' => '15:00']]]))->solve();
    }

    /** Aucune salle n'est occupée deux fois au même jour et au même créneau. */
    private static function assertNoRoomConflict(array $affectations): void
    {
        $seen = [];
        foreach ($affectations as $a) {
            foreach ($a['jours'] as $day) {
                foreach ($a['salles'] as $room) {
                    $key = "{$room}|{$day}|{$a['creneau']['debut']}";
                    self::assertArrayNotHasKey($key, $seen, "Salle {$room} réservée deux fois le jour {$day}");
                    $seen[$key] = true;
                }
            }
        }
    }

    /** @return array<string, array{int, int, int, int}> groupes, passages, salles par groupe, salles disponibles */
    public static function configurationsRealisables(): array
    {
        return [
            '5 groupes × 1 passage × 10 salles' => [5, 1, 10, 10],
            '4 groupes × 2 passages × 5 salles' => [4, 2, 5, 10],
            '5 groupes × 1 passage × 5 salles'  => [5, 1, 5, 5],
            '2 groupes × 3 passages × 2 salles' => [2, 3, 2, 4],
        ];
    }

    #[DataProvider('configurationsRealisables')]
    public function testTrouveUneAffectationCompleteSansConflit(int $groups, int $passages, int $perGroup, int $rooms): void
    {
        $result = self::solve(['passages' => $passages, 'salles_par_groupe' => $perGroup, 'salles' => self::rooms($rooms), 'groupes' => self::groupList($groups)]);

        self::assertTrue($result['complete']);
        $affectations = $result['solutions'][0]['affectations'];
        self::assertCount($groups, $affectations);
        foreach ($affectations as $a) {
            self::assertCount($passages, $a['jours'], 'Chaque groupe a exactement le nombre de passages prévu');
            self::assertSame($a['jours'], array_values(array_unique($a['jours'])), 'Deux passages ne tombent jamais le même jour');
            self::assertCount($perGroup, $a['salles'], 'Chaque groupe occupe le nombre de salles prévu');
        }
        self::assertNoRoomConflict($affectations);
    }

    public function testPassagesGroupesEtSallesSontTroisParametresDistincts(): void
    {
        // 3 groupes, 2 passages chacun, 4 salles par groupe : 24 occupations salle-jour.
        $result = self::solve(['passages' => 2, 'salles_par_groupe' => 4, 'salles' => self::rooms(8), 'groupes' => self::groupList(3)]);

        self::assertTrue($result['complete']);
        self::assertSame(24, $result['capacite']['requis']);
        $occupations = array_sum(array_map(static fn($a) => count($a['jours']) * count($a['salles']), $result['solutions'][0]['affectations']));
        self::assertSame(24, $occupations);
    }

    public function testExpliqueUneCapaciteInsuffisante(): void
    {
        $result = self::solve(['passages' => 3, 'salles_par_groupe' => 3, 'salles' => self::rooms(10), 'groupes' => self::groupList(6)]);

        self::assertFalse($result['complete']);
        self::assertStringContainsString('Capacité insuffisante', $result['diagnostic']['bloquant'][0]);
        self::assertSame(54, $result['capacite']['requis']);
        self::assertSame(50, $result['capacite']['disponible']);
        self::assertNotEmpty($result['non_places']);
    }

    public function testDetecteUneImpossibiliteMemeQuandLaCapaciteSuffit(): void
    {
        // 50 occupations pour 50 places, mais une salle ne peut accueillir que 2 groupes de 2 passages (5 jours).
        $result = self::solve(['passages' => 2, 'salles_par_groupe' => 5, 'salles' => self::rooms(10), 'groupes' => self::groupList(5)]);

        self::assertFalse($result['complete']);
        self::assertSame(50, $result['capacite']['requis']);
        self::assertStringContainsString('au mieux 4 groupe(s) sur 5', implode(' ', $result['diagnostic']['bloquant']));
        self::assertCount(4, $result['partielle']);
        self::assertNoRoomConflict($result['partielle']);
    }

    public function testRefuseDesParametresIncompatibles(): void
    {
        $tropDePassages = self::solve(['jours' => [1, 2], 'passages' => 3, 'salles' => self::rooms(3), 'groupes' => self::groupList(1)]);
        self::assertFalse($tropDePassages['complete']);
        self::assertStringContainsString('seuls 2 jour(s)', $tropDePassages['diagnostic']['bloquant'][0]);

        $tropDeSalles = self::solve(['passages' => 1, 'salles_par_groupe' => 6, 'salles' => self::rooms(5), 'groupes' => self::groupList(1)]);
        self::assertStringContainsString('6 salle(s) simultanément', $tropDeSalles['diagnostic']['bloquant'][0]);

        $aucuneSalle = self::solve(['passages' => 1, 'salles' => [], 'groupes' => self::groupList(1)]);
        self::assertStringContainsString('Aucune salle utilisable', $aucuneSalle['diagnostic']['bloquant'][0]);
    }

    public function testRespecteLesReservationsDesAutresSessions(): void
    {
        // Lundi à jeudi, S1 et S2 sont prises par une autre formation : seul le vendredi reste libre.
        $busy = [];
        foreach ([1, 2, 3, 4] as $day) {
            foreach ([1, 2] as $room) {
                $busy[] = ['id_salle' => $room, 'jour' => $day, 'debut' => '08:00', 'fin' => '12:00', 'id_formateur' => 100 + $room];
            }
        }
        $result = self::solve(['passages' => 1, 'salles_par_groupe' => 2, 'salles' => self::rooms(2), 'groupes' => self::groupList(1), 'occupations' => $busy]);

        self::assertTrue($result['complete']);
        self::assertSame([5], $result['solutions'][0]['affectations'][0]['jours']);
    }

    public function testUnHoraireQuiNeChevauchePasNeBloquePas(): void
    {
        $busy = [['id_salle' => 1, 'jour' => 1, 'debut' => '15:00', 'fin' => '18:00']];
        $result = self::solve(['jours' => [1], 'passages' => 1, 'salles' => self::rooms(1), 'groupes' => self::groupList(1), 'occupations' => $busy]);
        self::assertTrue($result['complete']);
    }

    public function testUnFormateurOccupeAilleursRendSaSalleIndisponible(): void
    {
        $busy = [['id_salle' => 99, 'id_formateur' => 101, 'jour' => 1, 'debut' => '08:00', 'fin' => '12:00']];
        $result = self::solve(['jours' => [1], 'passages' => 1, 'salles' => self::rooms(1), 'groupes' => self::groupList(1), 'occupations' => $busy]);
        self::assertFalse($result['complete']);
    }

    public function testLesGroupesFigesNeBougentPasEtReserventLeursSalles(): void
    {
        $groups = [
            ['cle' => 'G1', 'code' => 'G1', 'fixe' => true, 'jours' => [3], 'salles' => [1, 2], 'creneau' => ['debut' => '08:00', 'fin' => '15:00']],
            ['cle' => 'G2', 'code' => 'G2'],
        ];
        $result = self::solve(['jours' => [3, 4], 'passages' => 1, 'salles_par_groupe' => 2, 'salles' => self::rooms(2), 'groupes' => $groups]);

        self::assertTrue($result['complete']);
        $byKey = array_column($result['solutions'][0]['affectations'], null, 'cle');
        self::assertSame([3], $byKey['G1']['jours']);
        self::assertTrue($byKey['G1']['fixe']);
        self::assertSame([4], $byKey['G2']['jours'], 'G2 évite le mercredi déjà pris par G1');
    }

    public function testUnGroupeFigeIncompletRecoitSeulementSesPassagesManquants(): void
    {
        $groups = [['cle' => 'G1', 'code' => 'G1', 'fixe' => true, 'jours' => [1], 'salles' => [2], 'creneau' => ['debut' => '08:00', 'fin' => '15:00']]];
        $result = self::solve(['passages' => 2, 'salles_par_groupe' => 1, 'salles' => self::rooms(3), 'groupes' => $groups]);

        $a = $result['solutions'][0]['affectations'][0];
        self::assertContains(1, $a['jours']);
        self::assertCount(2, $a['jours']);
        self::assertSame([2], $a['salles'], 'Le groupe garde la même salle à tous ses passages');
        self::assertCount(1, $a['nouveaux_jours']);
    }

    public function testPrivilegieLesJoursEtSallesDeLaSessionPrecedente(): void
    {
        $groups = [['cle' => 'G1', 'code' => 'G1', 'preferences' => ['jours' => [2, 4], 'salles' => [3]]]];
        $result = self::solve(['passages' => 2, 'salles_par_groupe' => 1, 'salles' => self::rooms(3), 'groupes' => $groups]);

        $a = $result['solutions'][0]['affectations'][0];
        self::assertSame([2, 4], $a['jours']);
        self::assertSame([3], $a['salles']);
    }

    public function testEspaceLesPassagesQuandCestPossible(): void
    {
        $result = self::solve(['passages' => 2, 'salles_par_groupe' => 1, 'espacer' => true, 'salles' => self::rooms(1), 'groupes' => self::groupList(1)]);
        $days = $result['solutions'][0]['affectations'][0]['jours'];
        self::assertGreaterThan(1, $days[1] - $days[0]);
    }

    public function testPlusieursCreneauxParJourMultiplientLaCapacite(): void
    {
        $input = ['jours' => [1], 'passages' => 1, 'salles_par_groupe' => 1, 'salles' => self::rooms(1), 'groupes' => self::groupList(2)];
        self::assertFalse((new PlanningSolver($input + ['creneaux' => [['debut' => '08:00', 'fin' => '12:00']]]))->solve()['complete']);

        $twoSlots = (new PlanningSolver($input + ['creneaux' => [['debut' => '08:00', 'fin' => '12:00'], ['debut' => '13:00', 'fin' => '17:00']]]))->solve();
        self::assertTrue($twoSlots['complete']);
        $starts = array_map(static fn($a) => $a['creneau']['debut'], $twoSlots['solutions'][0]['affectations']);
        sort($starts);
        self::assertSame(['08:00', '13:00'], $starts);
    }

    public function testRechercheBorneeEtSolutionsDistinctes(): void
    {
        $result = self::solve(['passages' => 1, 'salles_par_groupe' => 1, 'salles' => self::rooms(5), 'groupes' => self::groupList(3), 'max_solutions' => 7]);
        self::assertLessThanOrEqual(7, $result['nb_solutions']);
        self::assertLessThanOrEqual(5, count($result['solutions']));
        self::assertGreaterThan(1, $result['nb_solutions']);
    }

    public function testCombinaisons(): void
    {
        self::assertCount(10, PlanningSolver::combinations([1, 2, 3, 4, 5], 2));
        self::assertSame([[]], PlanningSolver::combinations([1, 2], 0));
        self::assertSame([], PlanningSolver::combinations([1], 2));
    }
}
