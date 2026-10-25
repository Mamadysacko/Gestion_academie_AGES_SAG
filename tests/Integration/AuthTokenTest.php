<?php

/** Authentification JWT : connexion, renouvellement avec rotation, révocation, contrôle des rôles. */
final class AuthTokenTest extends ControllerTestCase
{
    private function login(string $email = 'admin@test.local'): array
    {
        $this->loginAs(null);
        [$code, $json] = $this->request(fn() => (new UserController())->authenticate(), ['email' => $email, 'password' => 'mot-de-passe-factice']);
        self::assertSame(200, $code, json_encode($json));
        return $json;
    }

    private function refresh(string $refreshToken): array
    {
        return $this->request(fn() => (new AuthController())->refresh(), ['refresh_token' => $refreshToken]);
    }

    public function testLaConnexionRenvoieUnJetonDAccesEtUnJetonDeRenouvellement(): void
    {
        $json = $this->login();

        self::assertSame('Bearer', $json['token_type']);
        self::assertSame(JWT_ACCESS_TTL, $json['expires_in']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $json['refresh_token']);
        $claims = Jwt::decode($json['access_token']);
        self::assertSame(1, $claims['sub']);
        self::assertSame('access', $claims['typ']);
        self::assertArrayNotHasKey('token_version', $json['data']);
        // Le jeton brut n'est jamais stocké : seule son empreinte l'est.
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM refresh_tokens WHERE token_hash = ?', [hash('sha256', $json['refresh_token'])]));
    }

    public function testUnAncienMotDePasseEnClairEstHacheALaConnexion(): void
    {
        $this->login();
        self::assertTrue(password_verify('mot-de-passe-factice', (string) $this->scalar('SELECT password FROM users WHERE id = 1')));
    }

    public function testLeJetonDAccesAuthentifieSansSession(): void
    {
        $json = $this->login();
        $this->loginAs(null);
        $this->useBearer($json['access_token']);

        self::assertSame(1, Authorization::userId());
        Authorization::authorizeRequest('sessions', 'GET', null, null); // aucune exception
        self::assertSame('admin', Authorization::currentUser()['role_code']);
    }

    public function testUnJetonInvalideEstRefuseAvecUnMessageExplicite(): void
    {
        $this->loginAs(1); // même avec une session valide, un jeton présenté et invalide l'emporte
        $this->useBearer('abc.def.ghi');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(401);
        $this->expectExceptionMessage('Jeton d’accès refusé');
        Authorization::authorizeRequest('sessions', 'GET', null, null);
    }

    public function testLesDroitsDuRoleSAppliquentAuxJetons(): void
    {
        $json = $this->login('secretaire@test.local');
        $this->loginAs(null);
        $this->useBearer($json['access_token']);

        Authorization::authorizeRequest('auditeurs', 'GET', null, null); // droit de lecture accordé
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(403);
        Authorization::authorizeRequest('planification', 'POST', 'appliquer', '1');
    }

    public function testLaSimulationNeDemandeQueLeDroitDeLecture(): void
    {
        $this->scalar("INSERT INTO role_permissions (role_id, module_code, action_code) VALUES (2, 'emplois-temps', 'read')");
        $this->loginAs(2);
        Authorization::authorizeRequest('planification', 'POST', 'simuler', '1'); // autorisé
        $this->expectExceptionCode(403);
        Authorization::authorizeRequest('planification', 'POST', 'appliquer', '1');
    }

    public function testLeRenouvellementFaitTournerLeJeton(): void
    {
        $first = $this->login()['refresh_token'];
        [$code, $json] = $this->refresh($first);

        self::assertSame(200, $code);
        self::assertNotSame($first, $json['refresh_token']);
        self::assertSame(1, Jwt::decode($json['access_token'])['sub']);
        self::assertSame('rotation', $this->scalar('SELECT motif_revocation FROM refresh_tokens WHERE token_hash = ?', [hash('sha256', $first)]));
    }

    public function testReutiliserUnJetonEchangeRevoqueToutLaFamille(): void
    {
        $first = $this->login()['refresh_token'];
        [, $rotated] = $this->refresh($first);

        [$code, $json] = $this->refresh($first); // vol présumé : l'ancien jeton revient
        self::assertSame(401, $code);
        self::assertStringContainsString('déjà utilisé', $json['error']);

        [$codeNew] = $this->refresh($rotated['refresh_token']); // le jeton légitime est révoqué aussi
        self::assertSame(401, $codeNew);
    }

    public function testLaDeconnexionRevoqueLeJetonDeRenouvellement(): void
    {
        $token = $this->login()['refresh_token'];
        [$code] = $this->request(fn() => (new AuthController())->logout(), ['refresh_token' => $token]);
        self::assertSame(200, $code);

        [$codeRefresh] = $this->refresh($token);
        self::assertSame(401, $codeRefresh);
    }

    public function testLaDeconnexionGlobaleInvalideAussiLesJetonsDAcces(): void
    {
        $json = $this->login();
        $this->useBearer($json['access_token']);
        [$code, $result] = $this->request(fn() => (new AuthController())->logoutAll());
        self::assertSame(200, $code);
        self::assertSame(1, $result['data']['jetons_revoques']);

        $this->useBearer($json['access_token']);
        self::assertNull(Authorization::currentUser(), 'Le jeton émis avant la déconnexion globale est refusé');
        self::assertStringContainsString('révoqué', (string) Authorization::bearerError());
    }

    public function testUnCompteDesactiveNePeutPlusRenouveler(): void
    {
        $token = $this->login('secretaire@test.local')['refresh_token'];
        $this->scalar('UPDATE users SET actif = 0 WHERE id = 2');

        [$code] = $this->refresh($token);
        self::assertSame(401, $code);
    }

    public function testJetonDeRenouvellementAbsentOuMalForme(): void
    {
        [$code] = $this->request(fn() => (new AuthController())->refresh(), ['refresh_token' => 'xyz']);
        self::assertSame(401, $code);
    }
}
