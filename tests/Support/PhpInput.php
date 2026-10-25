<?php
/**
 * Les contrôleurs lisent le corps JSON dans php://input, qui est vide en ligne de commande.
 * Ce flux de remplacement le fournit, UNIQUEMENT pendant l'appel du contrôleur : le reste du temps
 * le flux natif est restauré (PHPUnit écrit lui-même sur php://stdout).
 */
final class PhpInput
{
    private static string $body = '';
    private int $position = 0;
    /** @var resource|null */
    public $context;

    /** Exécute $call en faisant croire aux contrôleurs que la requête HTTP contient $body. */
    public static function with(string $body, callable $call): void
    {
        self::$body = $body;
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', self::class);
        try {
            $call();
        } finally {
            stream_wrapper_restore('php');
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->position = 0;
        return $path === 'php://input';
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$body, $this->position, $count);
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$body);
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}
