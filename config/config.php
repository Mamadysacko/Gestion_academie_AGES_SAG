<?php
/**
 * Configuration Générale de l'Application Serveur_AGES
 */

// Global Application Constants
define('APP_NAME', 'Académie de Langues - AGES');
define('APP_URL', 'http://localhost/Serveur_AGES');

// Database Configuration (XAMPP MySQL)
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
// AGES_DB_NAME permet aux tests (tests/) de viser une base jetable au lieu de la vraie.
define('DB_NAME', getenv('AGES_DB_NAME') ?: 'academie_langues3');
define('DB_USER', 'root');
define('DB_PASS', 'makossa');
define('DB_CHARSET', 'utf8mb4');

// Authentification JWT (durées en secondes, modifiables par variables d'environnement)
define('JWT_ISSUER', 'ages-api');
define('JWT_ACCESS_TTL', max(60, (int) (getenv('AGES_JWT_ACCESS_TTL') ?: 900)));        // 15 min
define('JWT_REFRESH_TTL', max(3600, (int) (getenv('AGES_JWT_REFRESH_TTL') ?: 1209600))); // 14 jours
define('JWT_REFRESH_COOKIE', 'ages_refresh');
// Les anciens clients authentifiés par cookie de session PHP restent acceptés tant que cette option vaut true.
define('AUTH_SESSION_FALLBACK', filter_var(getenv('AGES_AUTH_SESSION_FALLBACK') === false ? 'true' : getenv('AGES_AUTH_SESSION_FALLBACK'), FILTER_VALIDATE_BOOLEAN));

// En production, les cookies restent sécurisés même derrière un proxy TLS terminant HTTPS.
$cookieSecureSetting = getenv('AGES_COOKIE_SECURE');
$requestUsesHttps = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
define(
    'AGES_COOKIE_SECURE',
    $cookieSecureSetting === false ? $requestUsesHttps : filter_var($cookieSecureSetting, FILTER_VALIDATE_BOOLEAN)
);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => AGES_COOKIE_SECURE,
    ]);
    session_start();
}

// Fuseau horaire de l'académie : la « date du jour » des pointages doit être celle de Conakry
date_default_timezone_set('Africa/Conakry');

// Les détails techniques sont masqués par défaut ; activer uniquement en développement.
$debugSetting = getenv('AGES_DEBUG_MODE');
define('DEBUG_MODE', $debugSetting !== false && filter_var($debugSetting, FILTER_VALIDATE_BOOLEAN));

ini_set('display_errors', DEBUG_MODE ? '1' : '0');
ini_set('display_startup_errors', DEBUG_MODE ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
