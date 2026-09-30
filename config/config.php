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
define('DB_NAME', 'academie_langues2');
define('DB_USER', 'root');
define('DB_PASS', 'makossa');
define('DB_CHARSET', 'utf8mb4');

// Debug Mode
define('DEBUG_MODE', true); // Mettre à false en production

// Display Errors in Dev Mode
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
