<?php
/**
 * Singleton PDO Database Connection Manager
 */

require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $instance = null;

    private function __construct() {}
    private function __clone() {}

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                die("Erreur de connexion à la base de données (academie_langues2) : " . $e->getMessage());
            }
        }
        return self::$instance;
    }

    /**
     * Exécute $work dans une transaction et retourne son résultat.
     * Si une transaction est déjà ouverte (appel imbriqué, tests), elle est réutilisée :
     * c'est alors l'appelant extérieur qui valide ou annule l'ensemble.
     */
    public static function transaction(callable $work): mixed
    {
        $db = self::getInstance();
        if ($db->inTransaction()) {
            return $work($db);
        }
        $db->beginTransaction();
        try {
            $result = $work($db);
            $db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
