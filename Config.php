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
// Get the variable provided by Railway (MYSQL_PUBLIC_URL)
$railwayProvidedDbUrl = getenv('MYSQL_PUBLIC_URL');

// For PXXL application's internal use, we'll assign it to a variable name it expects,
// or directly use it to define constants.
// We will treat $railwayProvidedDbUrl as our "MYSQL_CONNECTION_STRING" for parsing.
$mysqlConnectionString = $railwayProvidedDbUrl; // This is the crucial line!

// Attempt to get specific individual MySQL variables from environment (set by Railway as fallbacks)
$mysqlHost      = getenv('MYSQLHOST');
$mysqlPort      = getenv('MYSQLPORT');
$mysqlUser      = getenv('MYSQLUSER');
$mysqlPassword  = getenv('MYSQLPASSWORD');
$mysqlDatabase  = getenv('MYSQL_DATABASE'); // Or MYSQLDATABASE if that's the one you prefer

// Prioritize the full connection string (which is now from MYSQL_PUBLIC_URL),
// then individual host/port, then fallback to local development.
if ($mysqlConnectionString) { // This now checks if MYSQL_PUBLIC_URL was set
    // If MYSQL_PUBLIC_URL (assigned to $mysqlConnectionString) is set, parse it
    // Railway's MYSQL_PUBLIC_URL can be "host:port" or a full "mysql://user:pass@host:port/db" format.
    // We need to handle both possibilities or assume the more common one.
    // Assuming MYSQL_PUBLIC_URL is in the format host:port as previously implied,
    // or if it's a full URL, parse_url will handle it.
    
    // Check if it's a full URL first (e.g., starts with "mysql://")
    if (strpos($mysqlConnectionString, 'mysql://') === 0) {
        $urlParts = parse_url($mysqlConnectionString);

        if ($urlParts === false) {
            error_log("Error parsing MYSQL_CONNECTION_STRING (from MYSQL_PUBLIC_URL): " . $mysqlConnectionString);
            // Fallback to local or default values
            define('DB_HOST', 'localhost');
            define('DB_PORT', 3306);
            define('DB_USER', 'root');
            define('DB_PASS', '');
            define('DB_NAME', 'heritagebank_db');
        } else {
            define('DB_HOST', $urlParts['host'] ?? 'localhost');
            define('DB_PORT', $urlParts['port'] ?? 3306);
            define('DB_USER', urldecode($urlParts['user'] ?? 'root'));
            define('DB_PASS', urldecode($urlParts['pass'] ?? ''));
            define('DB_NAME', ltrim($urlParts['path'] ?? '', '/'));
        }
    } else {
        // Assume it's in "host:port" format if not a full URL
        $parts = explode(':', $mysqlConnectionString);
        define('DB_HOST', $parts[0]);
        define('DB_PORT', $parts[1] ?? 3306);

        // For this "host:port" format, we still need user/pass/db name from individual env vars
        define('DB_USER', $mysqlUser ?: 'root');
        define('DB_PASS', $mysqlPassword ?: '');
        define('DB_NAME', $mysqlDatabase ?: 'default_db');
    }

    define('MYSQL_PUBLIC_URL_CONSTANT', DB_HOST . ':' . DB_PORT);

} elseif ($mysqlHost && $mysqlUser && $mysqlDatabase) {
    // If MYSQL_PUBLIC_URL (nor a full URL) isn't set, but individual host, user, dbname are provided by Railway
    define('DB_HOST', $mysqlHost);
    define('DB_PORT', $mysqlPort ?: 3306);
    define('DB_USER', $mysqlUser);
    define('DB_PASS', $mysqlPassword ?: '');
    define('DB_NAME', $mysqlDatabase);

    define('MYSQL_PUBLIC_URL_CONSTANT', DB_HOST . ':' . DB_PORT);

} else {
    // Fallback for local development (XAMPP) if no Railway env variables are set
    // IMPORTANT: These are your local XAMPP credentials
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'heritagebank_db');
    define('DB_PORT', 3306); // Default MySQL port

    define('MYSQL_PUBLIC_URL_CONSTANT', DB_HOST . ':' . DB_PORT);
}

// Optional: Set default timezone if needed (e.g., for logging timestamps)
// Get from environment variable APP_TIMEZONE or default to 'Europe/London'
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Europe/London');

// --- START: Required for Email and Admin Notifications ---
// Admin Email for notifications
// Get from environment variable ADMIN_EMAIL or default
define('ADMIN_EMAIL', getenv('ADMIN_EMAIL') ?: 'hometownbankpa@gmail.com');

// Base URL of your project
// Get from environment variable BASE_URL or RAILWAY_PUBLIC_DOMAIN or default for local development
define('BASE_URL', getenv('RAILWAY_PUBLIC_DOMAIN') ? 'https://' . getenv('RAILWAY_PUBLIC_DOMAIN') : (getenv('BASE_URL') ?: 'http://localhost/heritagebank'));

// SMTP Settings for Email Sending (using Gmail)
// Get from environment variables for production deployments
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_USERNAME', getenv('SMTP_USERNAME') ?: 'hometownbankpa@gmail.com'); // Your full Gmail address
define('SMTP_PASSWORD', getenv('SMTP_PASSWORD') ?: 'svkh egmo bwqk hick'); // The App Password generated from Google Security
define('SMTP_PORT', getenv('SMTP_PORT') ?: 587); // Use 587 for TLS
define('SMTP_ENCRYPTION', getenv('SMTP_ENCRYPTION') ?: 'tls'); // Use 'tls' for port 587 or 'ssl' for port 465
define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: 'hometownbankpa@gmail.com'); // Should match SMTP_USERNAME for Gmail
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'HomeTown Bank PA');
// --- END: Required for Email and Admin Notifications ---


// Optional: Error Reporting (adjust for production)
// Control via APP_DEBUG environment variable: set to 'true' for full errors, or 'false'
ini_set('display_errors', getenv('APP_DEBUG') ? 1 : 0);
ini_set('display_startup_errors', getenv('APP_DEBUG') ? 1 : 0);
error_reporting(getenv('APP_DEBUG') ? E_ALL : 0);

// For production: disable display errors, log errors instead
// ini_set('display_errors', 0);
// ini_set('log_errors', 1);
// ini_set('error_log', '/path/to/your/php-error.log'); // Specify your error log file path on server

// --- START: Required for Currency Exchange and Transfer Rules ---

// Currency Exchange Rate API Configuration
// Get API key from environment variable for production!
define('EXCHANGE_RATE_API_BASE_URL', getenv('EXCHANGE_RATE_API_BASE_URL') ?: 'https://v6.exchangerate-api.com/v6/');
define('EXCHANGE_RATE_API_KEY', getenv('EXCHANGE_RATE_API_KEY') ?: 'YOUR_ACTUAL_API_KEY_HERE'); // <-- GET YOUR FREE KEY FROM exchangerate-api.com

// --- IMPORTANT CHANGE: Define explicitly the allowed currencies for ALL transfers. ---
// This enforces that all transfers (internal and external) can ONLY be made in GBP or EUR.
define('ALLOWED_TRANSFER_CURRENCIES', ['GBP', 'EUR', 'USD']);

// Optional: Define a list of all currencies your bank internally supports for accounts.
// This can be useful for dropdowns or validation across your application.
define('SUPPORTED_CURRENCIES', ['EUR', 'USD', 'GBP', 'CAD', 'AUD', 'JPY']);

// --- END: Required for Currency Exchange and Transfer Rules ---