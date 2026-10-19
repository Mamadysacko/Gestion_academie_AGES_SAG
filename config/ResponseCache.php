<?php
/**
 * Petit cache de réponses GET sur disque, pour les agrégats coûteux (tableau de bord, rappels).
 *
 * - La clé combine l'utilisateur et l'URL complète : chacun ne voit que ses propres données.
 * - Toute écriture réussie passant par l'API change le « numéro de version » : toutes les entrées
 *   existantes deviennent alors invalides (la cohérence prime sur la vitesse).
 * - Une entrée expire de toute façon après TTL secondes, au cas où la base serait modifiée en dehors de l'API.
 */
final class ResponseCache
{
    private const TTL = 30;

    private static function dir(): string
    {
        $dir = __DIR__ . '/../cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private static function version(): string
    {
        $file = self::dir() . '/version';
        return is_file($file) ? (string) @file_get_contents($file) : '0';
    }

    public static function key(int $userId, string $uri): string
    {
        return md5($userId . '|' . $uri);
    }

    public static function read(string $key): ?string
    {
        $file = self::dir() . '/' . $key . '.json';
        if (!is_file($file) || (time() - (int) @filemtime($file)) > self::TTL) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        [$version, $body] = array_pad(explode("\n", $raw, 2), 2, '');
        return $version === self::version() && $body !== '' ? $body : null;
    }

    public static function write(string $key, string $body): void
    {
        if ($body === '') {
            return;
        }
        @file_put_contents(self::dir() . '/' . $key . '.json', self::version() . "\n" . $body, LOCK_EX);
        if (random_int(1, 50) === 1) {
            foreach (glob(self::dir() . '/*.json') ?: [] as $old) {
                if (time() - (int) @filemtime($old) > 3600) {
                    @unlink($old);
                }
            }
        }
    }

    public static function invalidate(): void
    {
        @file_put_contents(self::dir() . '/version', (string) hrtime(true), LOCK_EX);
    }
}
