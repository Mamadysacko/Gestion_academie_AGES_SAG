<?php
/**
 * Configuration Générale de l'Application Serveur_AGES
 */

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

// Fuseau horaire de l'académie : la « date du jour » des pointages doit être celle de Conakry
date_default_timezone_set('Africa/Conakry');

// Debug Mode
define('DEBUG_MODE', true); // Mettre à false en production

// Display Errors in Dev Mode
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
