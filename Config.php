<?php

/**
 * Database Configuration File for HeritageBanking Admin Panel
 *
 * This file contains the database connection parameters.
 *
 * IMPORTANT:
 * 1. Replace the placeholder values with your actual database credentials.
 * 2. In a production environment, ensure this file is not directly accessible via the web.
 * 3. Never hardcode sensitive information like this directly into version control.
 * Consider using environment variables or a more secure configuration management approach
 * for production deployments.
 */

// --- Database Configuration ---
// Attempt to get DATABASE_URL from environment variables (set by Railway/Pxxl.app)
$databaseUrl = getenv('DATABASE_URL');

if ($databaseUrl) {
    // If DATABASE_URL is set (on deployed environment)
    $urlParts = parse_url($databaseUrl);

    define('DB_HOST', $urlParts['host'] ?? 'localhost');
    define('DB_USER', $urlParts['user'] ?? 'root');
    define('DB_PASS', $urlParts['pass'] ?? '');
    // The path usually includes a leading slash, remove it for DB_NAME
    define('DB_NAME', ltrim($urlParts['path'] ?? '', '/'));
    // Default MySQL port is 3306, parse_url might not always include 'port'
    define('DB_PORT', $urlParts['port'] ?? 3306);
} else {
    // Fallback for local development (XAMPP) if DATABASE_URL is not set
    // IMPORTANT: These are your local XAMPP credentials
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'heritagebank_db');
    define('DB_PORT', 3306); // Default MySQL port
}


// Optional: Set default timezone if needed (e.g., for logging timestamps)
// Get from environment or default to 'Europe/London'
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Europe/London');

// --- START: Required for Email and Admin Notifications ---
// Admin Email for notifications
// IMPORTANT: Get from environment for production
define('ADMIN_EMAIL', getenv('ADMIN_EMAIL') ?: 'hometownbankpa@gmail.com');

// Base URL of your project
// IMPORTANT: Ensure this matches your project's URL in your browser (e.g., http://localhost/heritagebank)
// Get from environment for production
define('BASE_URL', getenv('BASE_URL') ?: 'http://localhost/heritagebank');

// SMTP Settings for Email Sending (using Gmail)
// IMPORTANT: Get these from environment variables for production!
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_USERNAME', getenv('SMTP_USERNAME') ?: 'hometownbankpa@gmail.com'); // Your full Gmail address
define('SMTP_PASSWORD', getenv('SMTP_PASSWORD') ?: 'svkh egmo bwqk hick'); // The App Password generated from Google Security
define('SMTP_PORT', getenv('SMTP_PORT') ?: 587); // Use 587 for TLS
define('SMTP_ENCRYPTION', getenv('SMTP_ENCRYPTION') ?: 'tls'); // Use 'tls' for port 587 or 'ssl' for port 465
define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: 'hometownbankpa@gmail.com'); // Should match SMTP_USERNAME for Gmail
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'HomeTown Bank PA');
// --- END: Required for Email and Admin Notifications ---


// Optional: Error Reporting (adjust for production)
// Control via APP_DEBUG environment variable
ini_set('display_errors', getenv('APP_DEBUG') ? 1 : 0);
ini_set('display_startup_errors', getenv('APP_DEBUG') ? 1 : 0);
error_reporting(getenv('APP_DEBUG') ? E_ALL : 0);

// For production: disable display errors, log errors instead
// ini_set('display_errors', 0);
// ini_set('log_errors', 1);
// ini_set('error_log', '/path/to/your/php-error.log'); // Specify your error log file path

// --- START: Required for Currency Exchange and Transfer Rules ---

// Currency Exchange Rate API Configuration
// IMPORTANT: Get API key from environment for production!
define('EXCHANGE_RATE_API_BASE_URL', getenv('EXCHANGE_RATE_API_BASE_URL') ?: 'https://v6.exchangerate-api.com/v6/');
define('EXCHANGE_RATE_API_KEY', getenv('EXCHANGE_RATE_API_KEY') ?: 'YOUR_ACTUAL_API_KEY_HERE'); // <-- GET YOUR FREE KEY FROM exchangerate-api.com

// --- IMPORTANT CHANGE: Define explicitly the allowed currencies for ALL transfers. ---
// This enforces that all transfers (internal and external) can ONLY be made in GBP or EUR.
define('ALLOWED_TRANSFER_CURRENCIES', ['GBP', 'EUR', 'USD']);

// Optional: Define a list of all currencies your bank internally supports for accounts.
// This can be useful for dropdowns or validation across your application.
define('SUPPORTED_CURRENCIES', ['EUR', 'USD', 'GBP', 'CAD', 'AUD', 'JPY']);

// --- END: Required for Currency Exchange and Transfer Rules ---