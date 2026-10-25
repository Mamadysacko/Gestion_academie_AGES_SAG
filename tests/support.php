<?php
/**
 * Outils des tests de contrôleurs (sans PHPUnit, sans serveur web).
 *
 *  - viser la base de test et refuser de tourner sur une autre ;
 *  - fournir un corps JSON aux contrôleurs (ils lisent php://input, vide en ligne de commande) ;
 *  - appeler un contrôleur comme le ferait Route/api.php et récupérer (code HTTP, JSON) ;
 *  - afficher chaque requête et le résultat de chaque vérification.
 */

putenv('AGES_DB_NAME=academie_langues3_test');
require_once __DIR__ . '/database/setup_test_db.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';

if (DB_NAME !== TEST_DB_NAME) {
    fwrite(STDERR, "ARRÊT : les tests doivent tourner sur « " . TEST_DB_NAME . " » (base actuelle : " . DB_NAME . ").\n");
    exit(2);
}

// Même chargement que Route/routes/bootstrap.php : contrôleurs et modèles à la demande.
require_once __DIR__ . '/../Controller/Authorization.php';
spl_autoload_register(static function (string $class): void {
    foreach ([__DIR__ . '/../Controller/', __DIR__ . '/../Model/'] as $dir) {
        if (preg_match('/^[A-Za-z0-9_]+$/', $class) && is_file($dir . $class . '.php')) {
            require_once $dir . $class . '.php';
            return;
        }
    }
});

// En ligne de commande, les contrôleurs appellent header() alors que le script a déjà affiché du texte :
// cet avertissement sans importance ne doit pas polluer la réponse JSON capturée.
set_error_handler(static fn(int $no, string $message): bool => str_contains($message, 'headers already sent') || str_contains($message, 'Cannot modify header'));

/** Remplace le flux php://input par un corps choisi par le test (les autres flux php:// restent natifs). */
final class InputStub
{
    public static string $body = '';
    /** @var resource|null */
    public $context;
    private int $position = 0;

    public function stream_open(string $path): bool
    {
        if ($path === 'php://input') {
            $this->position = 0;
            return true;
        }
        return false;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$body, $this->position, $count);
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool { return $this->position >= strlen(self::$body); }
    public function stream_stat(): array { return []; }
}
stream_wrapper_unregister('php');
stream_wrapper_register('php', InputStub::class);

final class Report
{
    public static int $passed = 0;
    public static int $failed = 0;
    /** @var string[] */
    public static array $failures = [];
}

function title(string $text): void
{
    echo "\n", str_repeat('═', 78), "\n  ", $text, "\n", str_repeat('═', 78), "\n";
}

function section(string $text): void
{
    echo "\n  ── ", $text, "\n";
}

/** Résumé court d'un JSON de réponse. */
function summary(array $json): string
{
    if (isset($json['error'])) {
        return 'erreur : ' . $json['error'];
    }
    $parts = [];
    if (isset($json['message'])) {
        $parts[] = '« ' . $json['message'] . ' »';
    }
    if (isset($json['data']) && is_array($json['data'])) {
        $parts[] = array_is_list($json['data']) ? count($json['data']) . ' élément(s)' : 'données : ' . implode(', ', array_slice(array_keys($json['data']), 0, 6));
    }
    return implode(' ', $parts) ?: 'succès';
}

/**
 * Appelle un contrôleur. `$call` reçoit rien et exécute la méthode (ex. fn() => (new PositionController())->create()).
 * @return array{0:int,1:array} [code HTTP, réponse JSON décodée]
 */
function call(string $label, ?array $body, callable $call, array $query = []): array
{
    InputStub::$body = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE);
    $_GET = $query;
    http_response_code(200);
    ob_start();
    $call();
    $output = (string) ob_get_clean();
    $code = http_response_code() ?: 200;
    $json = json_decode($output, true);
    $json = is_array($json) ? $json : ['raw' => $output];
    printf("    %-52s → %d  %s\n", $label, $code, summary($json));
    return [$code, $json];
}

function check(string $name, bool $ok, string $detail = ''): void
{
    if ($ok) {
        Report::$passed++;
        echo "      ✔ {$name}\n";
        return;
    }
    Report::$failed++;
    Report::$failures[] = $name . ($detail !== '' ? " ({$detail})" : '');
    echo "      ✘ {$name}" . ($detail !== '' ? "  [{$detail}]" : '') . "\n";
}

function expectStatus(array $response, int $expected, string $name): void
{
    check($name, $response[0] === $expected, "attendu {$expected}, reçu {$response[0]}");
}

function dbValue(string $sql, array $params = []): mixed
{
    $statement = Database::getInstance()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}

/** Simule l'utilisateur connecté (la session PHP) pour les contrôles de droits. */
function loginAs(?int $userId): void
{
    $_SESSION = $userId === null ? [] : ['user_id' => $userId];
}
