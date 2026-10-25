<?php

use PHPUnit\Framework\TestCase;

/** Jetons JWT HS256 : signature, expiration, algorithme imposé (logique pure). */
final class JwtTest extends TestCase
{
    public function testUnJetonValideEstRelu(): void
    {
        $token = Jwt::encode(['sub' => 42, 'typ' => 'access'], 60);
        $claims = Jwt::decode($token);

        self::assertSame(42, $claims['sub']);
        self::assertSame(JWT_ISSUER, $claims['iss']);
        self::assertGreaterThan(time(), $claims['exp']);
    }

    public function testUneSignatureFalsifieeEstRefusee(): void
    {
        [$head, $body] = explode('.', Jwt::encode(['sub' => 1], 60));
        $forged = rtrim(strtr(base64_encode(json_encode(['sub' => 999, 'iss' => JWT_ISSUER, 'exp' => time() + 60])), '+/', '-_'), '=');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Signature');
        Jwt::decode("{$head}.{$forged}." . explode('.', Jwt::encode(['sub' => 1], 60))[2]);
    }

    public function testUnJetonExpireEstRefuse(): void
    {
        $token = Jwt::encode(['sub' => 1, 'exp' => time() - 3600], 60);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('expiré');
        Jwt::decode($token);
    }

    public function testLAlgorithmeNoneEstRefuse(): void
    {
        $header = rtrim(strtr(base64_encode('{"typ":"JWT","alg":"none"}'), '+/', '-_'), '=');
        $body = rtrim(strtr(base64_encode(json_encode(['sub' => 1, 'iss' => JWT_ISSUER, 'exp' => time() + 60])), '+/', '-_'), '=');

        $this->expectException(UnexpectedValueException::class);
        Jwt::decode("{$header}.{$body}.");
    }

    public function testUnJetonMalFormeEstRefuse(): void
    {
        $this->expectException(UnexpectedValueException::class);
        Jwt::decode('pas-un-jeton');
    }
}
