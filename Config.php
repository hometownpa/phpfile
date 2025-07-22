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

// Database credentials
define('DB_HOST', 'localhost'); // Your database host (e.g., 'localhost', '127.0.0.1', or a remote host)
define('DB_USER', 'root'); // Your database username
define('DB_PASS', ''); // Your database password
define('DB_NAME', 'heritagebank_db'); // <--- IMPORTANT: This matches your provided DB_NAME

// Optional: Set default timezone if needed (e.g., for logging timestamps)
date_default_timezone_set('Europe/London'); // Set to UK time (London)

// --- START: Required for Email and Admin Notifications ---
// Admin Email for notifications
// IMPORTANT: Change this to your actual admin email
define('ADMIN_EMAIL', 'hometownbankpa@gmail.com');

// Base URL of your project
// IMPORTANT: Ensure this matches your project's URL in your browser (e.g., http://localhost/heritagebank)
define('BASE_URL', 'http://localhost/heritagebank');

// SMTP Settings for Email Sending (using Gmail)
// IMPORTANT: Replace with your actual Gmail account and the generated 16-character App Password
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USERNAME', 'hometownbankpa@gmail.com'); // Your full Gmail address
define('SMTP_PASSWORD', 'svkh egmo bwqk hick'); // The App Password generated from Google Security
define('SMTP_PORT', 587); // Use 587 for TLS
define('SMTP_ENCRYPTION', 'tls'); // Use 'tls' for port 587 or 'ssl' for port 465
define('SMTP_FROM_EMAIL', 'hometownbankpa@gmail.com'); // Should match SMTP_USERNAME for Gmail
define('SMTP_FROM_NAME', 'HomeTown Bank PA');
// --- END: Required for Email and Admin Notifications ---


// Optional: Error Reporting (adjust for production)
// For development: display all errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// For production: disable display errors, log errors instead
// ini_set('display_errors', 0);
// ini_set('log_errors', 1);
// ini_set('error_log', '/path/to/your/php-error.log'); // Specify your error log file path

// --- START: Required for Currency Exchange and Transfer Rules ---

// Currency Exchange Rate API Configuration
// You MUST sign up for a free API key from a service like exchangerate-api.com
// or openexchangerates.org. Replace 'YOUR_ACTUAL_API_KEY_HERE' with your key.
// Note: Free tiers may have limitations (e.g., rate limits, fixed base currency).
define('EXCHANGE_RATE_API_BASE_URL', 'https://v6.exchangerate-api.com/v6/');
define('EXCHANGE_RATE_API_KEY', 'YOUR_ACTUAL_API_KEY_HERE'); // <-- GET YOUR FREE KEY FROM exchangerate-api.com

// --- IMPORTANT CHANGE: Define explicitly the allowed currencies for ALL transfers. ---
// This enforces that all transfers (internal and external) can ONLY be made in GBP or EUR.
define('ALLOWED_TRANSFER_CURRENCIES', ['GBP', 'EUR', 'USD']);

// The EXTERNAL_TRANSFER_ALLOWED_CURRENCIES constant is now redundant if ALLOWED_TRANSFER_CURRENCIES
// is the strict rule for all transfers, so it's removed to avoid confusion.
// If you ever need different rules for internal vs. external, you could re-introduce it.

// Optional: Define a list of all currencies your bank internally supports for accounts.
// This can be useful for dropdowns or validation across your application.
define('SUPPORTED_CURRENCIES', ['EUR', 'USD', 'GBP', 'CAD', 'AUD', 'JPY']);

// --- END: Required for Currency Exchange and Transfer Rules ---