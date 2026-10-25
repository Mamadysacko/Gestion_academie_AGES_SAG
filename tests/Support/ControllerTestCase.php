<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../database/setup_test_db.php';

/**
 * Socle des tests d'INTÉGRATION : un contrôleur, ses modèles et une vraie base MySQL (jetable).
 *
 *  - la base de test est reconstruite UNE fois par lancement (structure copiée de la vraie base
 *    + tests/database/fixtures.sql) ;
 *  - chaque test s'exécute dans une transaction annulée à la fin : les tests ne se gênent jamais
 *    et chacun retrouve exactement les données de fixtures.sql ;
 *  - request() appelle un contrôleur comme Route/api.php et renvoie [code HTTP, JSON décodé].
 */
abstract class ControllerTestCase extends TestCase
{
    private static bool $databaseReady = false;

    public static function setUpBeforeClass(): void
    {
        if (!self::$databaseReady) {
            buildTestDatabase();
            self::$databaseReady = true;
        }
    }

    protected function setUp(): void
    {
        Database::getInstance()->beginTransaction();
        $this->loginAs(1); // administrateur de fixtures.sql
    }

    protected function tearDown(): void
    {
        $db = Database::getInstance();
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION = [];
        $_GET = [];
        $this->useBearer(null);
    }

    /** Simule l'utilisateur connecté (session PHP). null = visiteur non connecté. */
    protected function loginAs(?int $userId): void
    {
        $_SESSION = $userId === null ? [] : ['user_id' => $userId];
    }

    /** Simule l'en-tête « Authorization: Bearer <jeton> » (null = aucun en-tête). */
    protected function useBearer(?string $token): void
    {
        if ($token === null) {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        } else {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        Authorization::resetRequestState();
    }

    /**
     * Appelle un contrôleur.
     * Exemple : $this->request(fn() => (new PositionController())->create(), ['libelle' => 'Chef']);
     *
     * @return array{0:int, 1:array<string,mixed>} [code HTTP, réponse JSON]
     */
    protected function request(callable $call, ?array $body = null, array $query = []): array
    {
        $_GET = $query;
        http_response_code(200);
        // Les contrôleurs appellent header() alors que PHPUnit a déjà écrit : avertissement sans importance ici.
        set_error_handler(static fn(int $no, string $message): bool => str_contains($message, 'headers already sent') || str_contains($message, 'Cannot modify header'));
        ob_start();
        try {
            PhpInput::with($body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE), $call);
        } finally {
            $output = (string) ob_get_clean();
            restore_error_handler();
        }
        $json = json_decode($output, true);
        return [http_response_code() ?: 200, is_array($json) ? $json : ['raw' => $output]];
    }

    /** Valeur d'une requête SQL de contrôle (première colonne de la première ligne). */
    protected function scalar(string $sql, array $params = []): mixed
    {
        $statement = Database::getInstance()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }
}
