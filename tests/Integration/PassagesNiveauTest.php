<?php

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ResultatController : les résultats sont calculés, mais le passage de niveau est APPROUVÉ à la main
 * (administrateur ou rôle disposant du droit « Passages de niveau »).
 */
final class PassagesNiveauTest extends ControllerTestCase
{
    private function approuver(array $inscriptions, string $decision): array
    {
        return $this->request(fn() => (new ResultatController())->valider(1), ['inscriptions' => $inscriptions, 'decision' => $decision]);
    }

    public function testLesMoyennesSontCalculeesMaisAucunePassageNEstDecide(): void
    {
        [$code, $json] = $this->request(fn() => (new ResultatController())->getSession(1));

        self::assertSame(200, $code);
        $lignes = array_column($json['data']['auditeurs'], null, 'id_inscription');
        self::assertSame(3.2, $lignes[1]['moyenne_generale']);
        self::assertSame(1.6, $lignes[2]['moyenne_generale']);
        self::assertNull($lignes[3]['moyenne_generale'], 'notes incomplètes : pas de moyenne générale');
        self::assertSame([null, null, null], array_column($lignes, 'passage'), 'rien n’est décidé automatiquement');
    }

    public function testApprouverFaitPasserAuNiveauSuperieur(): void
    {
        [$code, $json] = $this->approuver([1], 'admis');

        self::assertSame(200, $code);
        self::assertStringContainsString('Intermédiaire', $json['message']);
        self::assertSame(2, (int) $this->scalar('SELECT id_niveau FROM auditeurs WHERE id = 1'));
        self::assertSame('manuelle', $this->scalar('SELECT mode_validation FROM passages_niveaux WHERE id_inscription = 1'));
    }

    public function testRedoublerGardeLeNiveauMemeAvecUneBonneMoyenne(): void
    {
        // Diallo a 3,20 / 4 : la moyenne ne décide de rien, l'approbateur peut le faire redoubler.
        [$code] = $this->approuver([1], 'redouble');

        self::assertSame(200, $code);
        self::assertSame(1, (int) $this->scalar('SELECT id_niveau FROM auditeurs WHERE id = 1'));
        self::assertSame('redouble', $this->scalar('SELECT decision FROM passages_niveaux WHERE id_inscription = 1'));
    }

    public function testTerminerLaFormationGardeLeNiveauSansNiveauDArrivee(): void
    {
        [$code, $json] = $this->approuver([1], 'termine');

        self::assertSame(200, $code);
        self::assertStringContainsString('terminent la formation', $json['message']);
        self::assertSame(1, (int) $this->scalar('SELECT id_niveau FROM auditeurs WHERE id = 1'));
        self::assertSame('termine', $this->scalar('SELECT decision FROM passages_niveaux WHERE id_inscription = 1'));
        self::assertNull($this->scalar('SELECT niveau_arrivee FROM passages_niveaux WHERE id_inscription = 1'));
    }

    public function testPasserEnClasseSuperieureEstRefuseSansNiveauSuperieur(): void
    {
        // La session 1 devient celle du dernier niveau (annulé en fin de test comme tout le reste).
        Database::getInstance()->exec('UPDATE sessions SET id_niveau = 3 WHERE id = 1');

        [$code, $json] = $this->approuver([1], 'admis');

        self::assertSame(400, $code);
        self::assertStringContainsString('termine la formation', $json['error']);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM passages_niveaux'));
        self::assertSame(200, $this->approuver([1], 'termine')[0], 'au dernier niveau, la bonne décision est « termine »');
    }

    public function testUneDecisionPeutEtreRemplaceeSansDoublon(): void
    {
        $this->approuver([3], 'redouble');
        $this->approuver([3], 'admis');

        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM passages_niveaux WHERE id_inscription = 3'));
        self::assertSame(2, (int) $this->scalar('SELECT id_niveau FROM auditeurs WHERE id = 3'));
    }

    public function testAnnulerRendLeNiveauDeDepart(): void
    {
        $this->approuver([1, 2, 3], 'admis');
        [$code] = $this->request(fn() => (new ResultatController())->annuler(1), ['inscriptions' => [1, 2, 3]]);

        self::assertSame(200, $code);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM passages_niveaux'));
        self::assertSame(3, (int) $this->scalar('SELECT COUNT(*) FROM auditeurs WHERE id IN (1, 2, 3) AND id_niveau = 1'));
    }

    /** @return array<string, array{array<int,int>, string}> */
    public static function approbationsRefusees(): array
    {
        return [
            'sélection vide'              => [[], 'admis'],
            'décision non prévue'         => [[1], 'non_admis'],
            'inscription d’une autre session' => [[999], 'admis'],
        ];
    }

    /** @param array<int,int> $inscriptions */
    #[DataProvider('approbationsRefusees')]
    public function testApprobationInvalideEstRefusee(array $inscriptions, string $decision): void
    {
        [$code] = $this->approuver($inscriptions, $decision);

        self::assertSame(400, $code);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM passages_niveaux'));
    }

    /** @return array<string, array{?int, ?int}> utilisateur → code d'erreur attendu (null = autorisé) */
    public static function droits(): array
    {
        return [
            'visiteur non connecté' => [null, 401],
            'secrétaire sans le droit' => [2, 403],
            'approbateur' => [3, null],
            'administrateur' => [1, null],
        ];
    }

    #[DataProvider('droits')]
    public function testSeulsLesApprobateursPeuventValider(?int $utilisateur, ?int $codeAttendu): void
    {
        $this->loginAs($utilisateur);
        $code = null;
        try {
            Authorization::authorizeRequest('resultats', 'POST', 'valider', '1');
        } catch (RuntimeException $e) {
            $code = $e->getCode();
        }

        self::assertSame($codeAttendu, $code);
    }

    public function testLaSecretairePeutConsulterLesResultats(): void
    {
        $this->loginAs(2);
        Authorization::authorizeRequest('resultats', 'GET', 'session', '1');
        $this->addToAssertionCount(1); // aucune exception levée
    }
}
