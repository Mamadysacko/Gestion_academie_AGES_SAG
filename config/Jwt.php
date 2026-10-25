<?php
/**
 * Jetons JWT signés HS256 (sans dépendance externe).
 *
 * Le secret est lu dans la variable d'environnement AGES_JWT_SECRET ; à défaut, il est généré
 * une seule fois dans config/jwt_secret.key (fichier exclu du dépôt Git).
 */

require_once __DIR__ . '/config.php';

final class Jwt
{
    private const ALGORITHM = 'HS256';
    /** Tolérance d'horloge entre serveurs, en secondes. */
    private const LEEWAY = 30;

    private static ?string $secret = null;

    /** Signe les revendications données ; iat / exp / iss sont ajoutés automatiquement. */
    public static function encode(array $claims, int $ttl): string
    {
        $now = time();
        $payload = array_merge([
            'iss' => JWT_ISSUER,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(12)),
        ], $claims);

        $segments = [
            self::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => self::ALGORITHM])),
            self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE)),
        ];
        $segments[] = self::base64UrlEncode(self::sign(implode('.', $segments)));
        return implode('.', $segments);
    }

    /**
     * Vérifie la signature, l'algorithme, l'émetteur et les dates du jeton.
     * @throws UnexpectedValueException si le jeton est invalide ou expiré (message en français).
     */
    public static function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new UnexpectedValueException('Jeton mal formé.');
        }
        [$head, $body, $signature] = $parts;

        $header = json_decode(self::base64UrlDecode($head), true);
        if (!is_array($header) || ($header['alg'] ?? null) !== self::ALGORITHM) {
            throw new UnexpectedValueException('Algorithme de jeton non accepté.');
        }
        if (!hash_equals(self::sign("{$head}.{$body}"), self::base64UrlDecode($signature))) {
            throw new UnexpectedValueException('Signature du jeton invalide.');
        }

        $claims = json_decode(self::base64UrlDecode($body), true);
        if (!is_array($claims)) {
            throw new UnexpectedValueException('Contenu du jeton illisible.');
        }
        $now = time();
        if (($claims['iss'] ?? null) !== JWT_ISSUER) {
            throw new UnexpectedValueException('Émetteur du jeton inconnu.');
        }
        if (isset($claims['nbf']) && $claims['nbf'] > $now + self::LEEWAY) {
            throw new UnexpectedValueException('Jeton pas encore valide.');
        }
        if (!isset($claims['exp']) || $claims['exp'] < $now - self::LEEWAY) {
            throw new UnexpectedValueException('Jeton expiré.');
        }
        return $claims;
    }

    private static function sign(string $data): string
    {
        return hash_hmac('sha256', $data, self::secret(), true);
    }

    private static function secret(): string
    {
        if (self::$secret !== null) {
            return self::$secret;
        }
        $fromEnv = getenv('AGES_JWT_SECRET');
        if (is_string($fromEnv) && strlen($fromEnv) >= 32) {
            return self::$secret = $fromEnv;
        }

        $file = __DIR__ . '/jwt_secret.key';
        if (!is_file($file)) {
            // Création exclusive : deux requêtes simultanées ne génèrent pas deux secrets différents.
            $handle = @fopen($file, 'x');
            if ($handle !== false) {
                fwrite($handle, bin2hex(random_bytes(48)));
                fclose($handle);
            }
        }
        $stored = trim((string) @file_get_contents($file));
        if (strlen($stored) < 32) {
            throw new RuntimeException('Secret JWT indisponible : définissez AGES_JWT_SECRET ou rendez config/ accessible en écriture.');
        }
        return self::$secret = $stored;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
    }
}
