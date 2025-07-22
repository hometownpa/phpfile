<?php
session_start();
require_once '../../Config.php'; // Adjust path for Config.php

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ../index.php'); // Correct path from users folder to HERITAGEBANK_ADMIN/index.php
    exit;
}

$message = '';
$message_type = ''; // 'success' or 'error'

// Define the upload directory using an absolute path relative to the Railway /app root
// This is already correct: /app/uploads/profile_images/
define('UPLOAD_DIR', '/app/uploads/profile_images/');

// Attempt to create the upload directory if it doesn't exist
// and set permissions more aggressively if initial creation fails.
if (!is_dir(UPLOAD_DIR)) {
    // Attempt creation with full permissions (0777) and recursively (true)
    if (!mkdir(UPLOAD_DIR, 0777, true)) {
        // If mkdir failed, log an error or handle it. For debugging on Railway:
        error_log('PHP Error: Failed to create upload directory: ' . UPLOAD_DIR . '. Please check underlying permissions or path.');
        // Consider redirecting or showing a user-friendly error instead of dying directly
        // header('Location: error_page.php?msg=upload_dir_creation_failed');
        // exit();
    }
}

// *** CRUCIAL ADDITION/MODIFICATION HERE ***
// After creation (or if it already existed), ensure permissions are explicitly set to 0777.
// This is the most likely missing piece for "Permission denied" on Railway.
// This `chmod` should happen even if the directory already existed, to re-assert permissions.
if (!chmod(UPLOAD_DIR, 0777)) {
    // If chmod failed, log an error or handle it. For debugging on Railway:
    error_log('PHP Error: Failed to set permissions on upload directory: ' . UPLOAD_DIR . '.');
    // Consider redirecting or showing a user-friendly error instead of dying directly
    // header('Location: error_page.php?msg=upload_dir_permissions_failed');
    // exit();
}

// Now proceed with your file upload logic:
// (Your move_uploaded_file call will be here)
// Example:
// if (move_uploaded_file($file_tmp_path, $target_file_path)) {
//     // ... success ...
// } else {
//     // ... failure ...
// }

// --- Define HomeTown Bank's Fixed Identifiers (Fictional) ---
// These would be real bank details in a production system.
// For simulation, these are consistent for "HomeTown Bank" for a given currency/region.

// For UK (GBP) accounts - BIC must be 11 chars
define('HOMETOWN_BANK_UK_BIC', 'HOMTGB2LXXX'); // Fictional BIC for HomeTown Bank UK
// UK Sort Code is 6 digits. We will generate the last 4.
define('HOMETOWN_BANK_UK_SORT_CODE_PREFIX', '90'); // Example: '90xxxx'

// For EURO (EUR) accounts (e.g., simulating a German branch for Euro operations) - BIC must be 11 chars
define('HOMETOWN_BANK_EUR_BIC', 'HOMTDEFFXXX'); // Fictional BIC for HomeTown Bank Europe (Germany)
// Fictional German Bankleitzahl (BLZ) part of BBAN for EUR IBANs.
// German BLZ is 8 digits.
define('HOMETOWN_BANK_EUR_BANK_CODE_BBAN', '50070010');

// For US (USD) accounts - BIC must be 11 chars
define('HOMETOWN_BANK_USD_BIC', 'HOMTUS33XXX'); // Fictional BIC for HomeTown Bank USA
// ABA Routing Transit Number (RTN) is 9 digits for US banks
define('HOMETOWN_BANK_USD_ROUTING_NUMBER_PREFIX', '021000000'); // Fictional: Example prefix, usually a real RTN

/**
 * Helper function to generate a unique numeric ID of a specific length.
 * Ensures the generated ID does not already exist in the specified table and column.
 *
 * @param mysqli $conn The database connection object.
 * @param string $table The table name to check for uniqueness.
 * @param string $column The column name to check for uniqueness.
 * @param int $length The desired length of the numeric ID.
 * @return string|false The unique numeric ID as a string, or false on error.
 */
function generateUniqueNumericId($conn, $table, $column, $length) {
    $max_attempts = 100; // Increased attempts for higher uniqueness chance
    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        $id_candidate = '';
        for ($i = 0; $i < $length; $i++) {
            if ($i === 0 && $length > 1) { // Ensure first digit is not zero for multi-digit numbers
                $id_candidate .= mt_rand(1, 9);
            } else {
                $id_candidate .= mt_rand(0, 9);
            }
        }

        $stmt = mysqli_prepare($conn, "SELECT 1 FROM $table WHERE $column = ?");
        if (!$stmt) {
            error_log("Failed to prepare statement for unique ID check ($table.$column): " . mysqli_error($conn));
            return false; // Indicate an error
        }
        mysqli_stmt_bind_param($stmt, "s", $id_candidate); // Bind as string
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $exists = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);

        if (!$exists) {
            return $id_candidate; // Found a unique ID
        }
    }

    error_log("Failed to generate a unique Numeric ID for $table.$column after $max_attempts attempts.");
    return false; // Could not generate a unique ID
}

/**
 * Generates a 6-digit UK Sort Code.
 * Each account should have a unique sort code if it implies a unique "branch" or routing.
 * If all accounts under HomeTown Bank UK use the same sort code, this can be simplified.
 * Assuming for now each *account* gets a unique sort code suffix based on its currency region.
 * @param mysqli $conn The database connection.
 * @return string The unique 6-digit UK Sort Code.
 */
function generateUniqueUkSortCode($conn): string|false {
    $max_attempts = 100;
    for ($i = 0; $i < $max_attempts; $i++) {
        $last_four = str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
        $sort_code_candidate = HOMETOWN_BANK_UK_SORT_CODE_PREFIX . $last_four;

        $stmt = mysqli_prepare($conn, "SELECT 1 FROM accounts WHERE sort_code = ? LIMIT 1");
        if (!$stmt) {
            error_log("Failed to prepare statement for sort code uniqueness check: " . mysqli_error($conn));
            return false;
        }
        mysqli_stmt_bind_param($stmt, "s", $sort_code_candidate);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $exists = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);

        if (!$exists) {
            return $sort_code_candidate;
        }
    }
    error_log("Failed to generate a unique UK Sort Code after $max_attempts attempts.");
    return false;
}


/**
 * Calculates a simplified IBAN check digits (NOT the real MOD97-10).
 * For production, use a robust IBAN generation library.
 * This function is a placeholder and should NOT be used for real banking systems.
 */
function calculateIbanCheckDigits(string $countryCode, string $bban): string {
    // This is a simplified placeholder checksum calculation.
    // Real IBAN calculation uses MOD 97-10 on a string representation
    // where letters are mapped to numbers (A=10, B=11, etc.).
    // For demonstration, this aims to return 2 digits.

    $iban_string_for_calc = $bban . strtoupper($countryCode) . '00'; // Append country code + '00' for calculation
    $numeric_string = '';
    for ($i = 0; $i < strlen($iban_string_for_calc); $i++) {
        $char = $iban_string_for_calc[$i];
        if (ctype_alpha($char)) {
            $numeric_string .= (string)(ord($char) - 55); // A=10, B=11, ..., Z=35
        } else {
            $numeric_string .= $char;
        }
    }

    // Use bcmod for large numbers
    $checksum_val = bcmod($numeric_string, '97');
    $check_digits = 98 - (int)$checksum_val;

    return str_pad($check_digits, 2, '0', STR_PAD_LEFT);
}

/**
 * Generates a unique UK IBAN.
 * Format: GBkk BBBB SSSSSS AAAAAAAA
 * GB (Country Code), kk (Check Digits), BBBB (Bank Code from BIC), SSSSSS (Sort Code), AAAAAAAA (Account Number, typically 8 digits)
 * The total length will be 22 characters (GB + 2 + 4 + 6 + 8).
 *
 * @param mysqli $conn The database connection.
 * @param string $sortCode The 6-digit sort code for this account.
 * @param string $internalAccountNumber8Digits The 8-digit internal account number part for IBAN.
 * @return string|false The unique UK IBAN, or false on error.
*/
function generateUniqueUkIban($conn, string $sortCode, string $internalAccountNumber8Digits): string|false {
    $countryCode = 'GB';
    $bankCode = substr(HOMETOWN_BANK_UK_BIC, 0, 4); // First 4 chars of BIC as Bank Code

    $max_attempts = 100;
    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        // BBAN for checksum calculation: Bank Code (4) + Sort Code (6) + Account Number (8)
        $bban_for_checksum = $bankCode . $sortCode . $internalAccountNumber8Digits;

        $checkDigits = calculateIbanCheckDigits($countryCode, $bban_for_checksum);

        $iban_candidate = $countryCode . $checkDigits . $bankCode . $sortCode . $internalAccountNumber8Digits;

        // Check for uniqueness in the database
        $stmt = mysqli_prepare($conn, "SELECT 1 FROM accounts WHERE iban = ? LIMIT 1");
        if (!$stmt) {
            error_log("Failed to prepare statement for IBAN uniqueness check: " . mysqli_error($conn));
            return false;
        }
        mysqli_stmt_bind_param($stmt, "s", $iban_candidate);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $exists = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);

        if (!$exists) {
            return $iban_candidate; // Found a unique IBAN
        }
        // If IBAN exists, regenerate the 8-digit account number part and try again
        $internalAccountNumber8Digits = str_pad(mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
    }
    error_log("Failed to generate a unique UK IBAN after $max_attempts attempts.");
    return false;
}

/**
 * Generates a unique German-style EURO IBAN.
 * Format: DEkk BBBBBBBB AAAAAAAAAA
 * DE (Country Code), kk (Check Digits), BBBBBBBB (German Bankleitzahl - 8 digits), AAAAAAAAAA (German Account Number - 10 digits)
 * The total length will be 22 characters (DE + 2 + 8 + 10).
 *
 * @param mysqli $conn The database connection.
 * @param string $internalAccountNumber10Digits The 10-digit internal account number part for IBAN.
 * @return string|false The unique EURO IBAN, or false on error.
*/
function generateUniqueEurIban($conn, string $internalAccountNumber10Digits): string|false {
    $countryCode = 'DE'; // Example: German IBAN structure
    $bankleitzahl = HOMETOWN_BANK_EUR_BANK_CODE_BBAN; // Fictional German Bankleitzahl (8 digits)

    $max_attempts = 100;
    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        // BBAN for checksum calculation: BLZ (8) + Account Number (10)
        $bban_for_checksum = $bankleitzahl . $internalAccountNumber10Digits;

        $checkDigits = calculateIbanCheckDigits($countryCode, $bban_for_checksum);

        $iban_candidate = $countryCode . $checkDigits . $bankleitzahl . $internalAccountNumber10Digits;

        // Check for uniqueness in the database
        $stmt = mysqli_prepare($conn, "SELECT 1 FROM accounts WHERE iban = ? LIMIT 1");
        if (!$stmt) {
            error_log("Failed to prepare statement for IBAN uniqueness check: " . mysqli_error($conn));
            return false;
        }
        mysqli_stmt_bind_param($stmt, "s", $iban_candidate);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $exists = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);

        if (!$exists) {
            return $iban_candidate; // Found a unique IBAN
        }
        // If IBAN exists, regenerate the 10-digit account number part and try again
        $internalAccountNumber10Digits = str_pad(mt_rand(0, 9999999999), 10, '0', STR_PAD_LEFT);
    }
    error_log("Failed to generate a unique EUR IBAN after $max_attempts attempts.");
    return false;
}

/**
 * Generates a unique US-style "IBAN" (simulating a BBAN with routing number).
 * For US domestic transfers, Routing Number (9 digits) + Account Number (variable length, often 10-12 digits) are used.
 * For international transfers, SWIFT/BIC + Account Number are used.
 * This function will create a string in the 'iban' column that reflects this.
 * Format: USkk RRRRRRRRR AAAAAAAAAAAA (US + Check Digits + Routing Number + Account Number)
 * Total length will be 2 + 2 + 9 + (typically 10-12) = 23-25 characters.
 *
 * @param mysqli $conn The database connection.
 * @param string $internalAccountNumberForUSD The internal account number, will be padded/truncated as needed.
 * @return array|false The unique US "IBAN" (BBAN) and its routing number, or false on error.
 */
function generateUniqueUsdIban($conn, string $internalAccountNumberForUSD): array|false { // <--- Changed return type here
    $countryCode = 'US';
    // The routing number itself needs to be generated and unique for each account.
    // Previously, HOMETOWN_BANK_USD_ROUTING_NUMBER_PREFIX was used, implying a static prefix.
    // To make it unique per account and align with typical US RTN, let's generate it fully here.
    // A real RTN should pass a checksum algorithm (Modulus 10, position weighting), but for simulation, we'll just ensure uniqueness.

    $generatedRoutingNumber = generateUniqueNumericId($conn, 'accounts', 'routing_number', 9);
    if ($generatedRoutingNumber === false) {
        error_log("Failed to generate a unique US Routing Number for IBAN.");
        return false;
    }

    // US account numbers can be variable length. Let's aim for a 10-digit number for the IBAN part
    // and ensure the internalAccountNumberForUSD is at least 10 digits for this
    $accountNumberPart = str_pad(substr($internalAccountNumberForUSD, -10), 10, '0', STR_PAD_LEFT);

    $max_attempts = 100;
    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        // BBAN for checksum calculation: Routing Number (9) + Account Number (10)
        $bban_for_checksum = $generatedRoutingNumber . $accountNumberPart;

        // Use the simplified IBAN check digits, although real US 'IBANs' are not standard
        $checkDigits = calculateIbanCheckDigits($countryCode, $bban_for_checksum);

        $iban_candidate = $countryCode . $checkDigits . $generatedRoutingNumber . $accountNumberPart;

        // Check for uniqueness in the database
        $stmt = mysqli_prepare($conn, "SELECT 1 FROM accounts WHERE iban = ? LIMIT 1");
        if (!$stmt) {
            error_log("Failed to prepare statement for USD 'IBAN' uniqueness check: " . mysqli_error($conn));
            return false;
        }
        mysqli_stmt_bind_param($stmt, "s", $iban_candidate);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $exists = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);

        if (!$exists) {
            // Return both the generated IBAN and the routing number so it can be stored separately
            return ['iban' => $iban_candidate, 'routing_number' => $generatedRoutingNumber];
        }
        // If "IBAN" exists, regenerate the 10-digit account number part and try again
        $accountNumberPart = str_pad(mt_rand(0, 9999999999), 10, '0', STR_PAD_LEFT);
    }
    error_log("Failed to generate a unique US 'IBAN' after $max_attempts attempts.");
    return false;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect and sanitize user data
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? ''; // Don't trim password
    $home_address = trim($_POST['home_address'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $nationality = trim($_POST['nationality'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $occupation = trim($_POST['occupation'] ?? '');

    // Make initial_balance optional, default to 0 if not provided or invalid
    $initial_balance = floatval($_POST['initial_balance'] ?? 0); // Already handles non-numeric to 0
    $currency = trim($_POST['currency'] ?? 'GBP');

    // fund_account_type is now optional. Only use if initial_balance > 0.
    // If not selected AND initial_balance is > 0, we can default it or return an error.
    // For now, if initial_balance > 0 and fund_account_type is empty, we'll default it to 'Checking'.
    $fund_account_type = trim($_POST['fund_account_type'] ?? '');

    // Admin determined creation timestamp
    $admin_created_at = trim($_POST['admin_created_at'] ?? '');

    $profile_image_path = null; // To store the path of the uploaded image

    // --- Basic Validation ---
    // Removed initial_balance and fund_account_type from required fields check
    if (empty($first_name) || empty($last_name) || empty($email) || empty($password) || empty($home_address) || empty($phone_number) || empty($nationality) || empty($date_of_birth) || empty($gender) || empty($occupation) || empty($admin_created_at)) {
        $message = 'All required fields (marked with *) must be filled.';
        $message_type = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Invalid email format.';
        $message_type = 'error';
    } elseif (strlen($password) < 6) {
        $message = 'Password must be at least 6 characters long.';
        $message_type = 'error';
    } elseif ($initial_balance < 0) {
        $message = 'Initial balance cannot be negative.';
        $message_type = 'error';
    } elseif (!in_array($currency, ['GBP', 'EUR', 'USD'])) {
        $message = 'Invalid currency selected. Only GBP, EURO, and USD are allowed.';
        $message_type = 'error';
    } elseif (!in_array($gender, ['Male', 'Female', 'Other'])) { // Validate gender against ENUM
        $message = 'Invalid gender selected.';
        $message_type = 'error';
    } elseif (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $date_of_birth) || !strtotime($date_of_birth)) { // Validate Date of Birth format
        $message = 'Invalid Date of Birth format. Please use YYYY-MM-DD.';
        $message_type = 'error';
    } elseif (!DateTime::createFromFormat('Y-m-d\TH:i', $admin_created_at) && !DateTime::createFromFormat('Y-m-d H:i:s', $admin_created_at)) {
        // Validate admin_created_at format for datetime-local or common datetime string
        $message = 'Invalid "Created At" date/time format. Please use YYYY-MM-DDTHH:MM (e.g., 2025-07-01T14:30).';
        $message_type = 'error';
    }
    // New validation for optional initial funding: if balance > 0, account type must be selected
    elseif ($initial_balance > 0 && empty($fund_account_type)) {
        $message = 'If an initial balance is provided, you must select an account type to fund.';
        $message_type = 'error';
    }
    else {
        // --- Handle Profile Image Upload ---
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $file_tmp_path = $_FILES['profile_image']['tmp_name'];
            $file_name = $_FILES['profile_image']['name'];
            $file_size = $_FILES['profile_image']['size'];
            $file_type = $_FILES['profile_image']['type'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
            if (!in_array($file_ext, $allowed_ext)) {
                $message = 'Invalid image file type. Only JPG, JPEG, PNG, GIF are allowed.';
                $message_type = 'error';
            } elseif ($file_size > 5 * 1024 * 1024) { // 5MB limit
                $message = 'Image file size exceeds 5MB limit.';
                $message_type = 'error';
            } else {
                // Generate a unique filename
                $new_file_name = uniqid('profile_', true) . '.' . $file_ext;
                $target_file_path = UPLOAD_DIR . $new_file_name;

                if (move_uploaded_file($file_tmp_path, $target_file_path)) {
                    // Store the relative path to be saved in the database
                    $profile_image_path = 'uploads/profile_images/' . $new_file_name;
                } else {
                    $message = 'Failed to upload profile image.';
                    $message_type = 'error';
                }
            }
        }
        // If there was an image upload error or a validation error, do not proceed with DB operations
        if ($message_type == 'error') {
            goto end_of_post_processing;
        }

        // --- Database Insertion ---
        $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn === false) {
            $message = "Database connection error: " . mysqli_connect_error();
            $message_type = 'error';
        } else {
            // Start Transaction for Atomicity
            mysqli_autocommit($conn, FALSE); // Disable autocommit
            $transaction_success = true;

            // Hash the password before storing (VERY IMPORTANT!)
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Generate unique Membership Number (12 digits, numeric only)
            $membership_number = generateUniqueNumericId($conn, 'users', 'membership_number', 12);
            if ($membership_number === false) {
                $message = "Failed to generate unique Membership Number due to a database error.";
                $message_type = 'error';
                $transaction_success = false;
                goto end_of_post_processing;
            }

            // Determine a username (e.g., first_name.last_name or email prefix)
            $username_for_db = strtolower($first_name . '.' . $last_name);
            // Fallback if username is too long or empty
            if (empty($username_for_db) || strlen($username_for_db) > 50) { // Assuming username max length is 50
                $username_for_db = strtolower(explode('@', $email)[0]);
            }
            // Ensure username is unique - this part is missing in the original, adding a basic check
            $original_username = $username_for_db;
            $counter = 1;
            while (true) {
                $stmt_check_username = mysqli_prepare($conn, "SELECT 1 FROM users WHERE username = ?");
                mysqli_stmt_bind_param($stmt_check_username, "s", $username_for_db);
                mysqli_stmt_execute($stmt_check_username);
                mysqli_stmt_store_result($stmt_check_username);
                $username_exists = mysqli_stmt_num_rows($stmt_check_username) > 0;
                mysqli_stmt_close($stmt_check_username);

                if (!$username_exists) {
                    break;
                }
                $username_for_db = $original_username . $counter++;
                if (strlen($username_for_db) > 50) { // Prevent excessively long usernames
                    $username_for_db = uniqid('user_'); // Final fallback
                }
            }


            // Prepare INSERT statement for 'users' table
            $user_stmt = mysqli_prepare($conn, "INSERT INTO users (username, first_name, last_name, email, password_hash, home_address, phone_number, nationality, date_of_birth, gender, occupation, membership_number, profile_image, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($user_stmt) {
                mysqli_stmt_bind_param($user_stmt, "ssssssssssssss",
                    $username_for_db,
                    $first_name,
                    $last_name,
                    $email,
                    $hashed_password,
                    $home_address,
                    $phone_number,
                    $nationality,
                    $date_of_birth,
                    $gender,
                    $occupation,
                    $membership_number,
                    $profile_image_path,
                    $admin_created_at
                );

                if (!mysqli_stmt_execute($user_stmt)) {
                    if (mysqli_errno($conn) == 1062) { // MySQL error code for duplicate entry
                        $message = "Error creating user: Email or Membership Number already exists. Please check and try again.";
                    } else {
                        $message = "Error creating user: " . mysqli_error($conn);
                    }
                    $message_type = 'error';
                    $transaction_success = false;
                } else {
                    $new_user_id = mysqli_insert_id($conn); // Get the ID of the newly inserted user

                    $accounts_created_messages = [];

                    // Determine common sort_code, swift_bic, and routing_number based on currency once
                    $common_sort_code = NULL;
                    $common_swift_bic = NULL;
                    $common_routing_number = NULL; // For USD

                    if ($currency === 'GBP') {
                        $common_sort_code = generateUniqueUkSortCode($conn);
                        if ($common_sort_code === false) {
                            $message = "Failed to generate a unique Sort Code for UK accounts.";
                            $message_type = 'error';
                            $transaction_success = false;
                            goto end_of_post_processing;
                        }
                        $common_swift_bic = HOMETOWN_BANK_UK_BIC;
                    } elseif ($currency === 'EUR') {
                        $common_sort_code = NULL; // Not applicable for EUR IBAN (uses BLZ which is part of IBAN BBAN)
                        $common_swift_bic = HOMETOWN_BANK_EUR_BIC;
                    } elseif ($currency === 'USD') {
                        $common_sort_code = NULL; // Not applicable for US sort code
                        $common_swift_bic = HOMETOWN_BANK_USD_BIC;
                        // For USD, the routing number is generated *inside* generateUniqueUsdIban and returned.
                        // We will store this generated routing number in the 'sort_code' column for simplicity,
                        // or you could add a dedicated 'routing_number' column to your 'accounts' table.
                        // For now, let's use common_routing_number to store the one generated for the first account.
                        // Note: A single user might have multiple accounts with different routing numbers in a real US bank.
                        // This simplifies it to one primary routing number for all their accounts of a given currency.
                    }

                    // Prepare INSERT statement for 'accounts' table (prepared once, executed multiple times)
                    // Assuming you have run the ALTER TABLE accounts ADD COLUMN routing_number VARCHAR(9) NULL;
                    $account_stmt = mysqli_prepare($conn, "INSERT INTO accounts (user_id, account_number, account_type, balance, currency, sort_code, routing_number, iban, swift_bic, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                    if ($account_stmt) {
                        // 1. Create Checking Account
                        $checking_account_number = generateUniqueNumericId($conn, 'accounts', 'account_number', 12); // Unique for Checking
                        if ($checking_account_number === false) {
                            $message = "Failed to generate unique Checking account number due to a database error.";
                            $message_type = 'error';
                            $transaction_success = false;
                            goto end_of_post_processing;
                        }

                        $checking_iban = NULL;
                        $checking_sort_code = NULL; // Explicitly set to NULL by default
                        $checking_routing_number = NULL; // Explicitly set to NULL by default


                        if ($currency === 'GBP') {
                            // Extract last 8 digits for UK IBAN part
                            $uk_iban_part_account_number = str_pad(substr($checking_account_number, -8), 8, '0', STR_PAD_LEFT);
                            $checking_iban = generateUniqueUkIban($conn, $common_sort_code, $uk_iban_part_account_number);
                            $checking_sort_code = $common_sort_code; // Assign common sort code for GBP
                        } elseif ($currency === 'EUR') {
                            // Extract last 10 digits for EUR IBAN part
                            $eur_iban_part_account_number = str_pad(substr($checking_account_number, -10), 10, '0', STR_PAD_LEFT);
                            $checking_iban = generateUniqueEurIban($conn, $eur_iban_part_account_number);
                        } elseif ($currency === 'USD') {
                            $usd_iban_details = generateUniqueUsdIban($conn, $checking_account_number);
                            if ($usd_iban_details !== false) {
                                $checking_iban = $usd_iban_details['iban'];
                                $checking_routing_number = $usd_iban_details['routing_number']; // Assign the generated routing number
                                $common_routing_number = $checking_routing_number; // Store this for display/reuse
                            } else {
                                $message = "Failed to generate unique USD 'IBAN' and Routing Number for Checking account.";
                                $message_type = 'error';
                                $transaction_success = false;
                                goto end_of_post_processing;
                            }
                        }

                        // Consolidated check for IBAN generation
                        if ($checking_iban === false) {
                            $message = "Failed to generate unique IBAN/BBAN for Checking account.";
                            $message_type = 'error';
                            $transaction_success = false;
                            goto end_of_post_processing;
                        }

                        $checking_account_type = 'Checking';
                        // Apply initial balance ONLY if it's positive and this is the chosen account type
                        $checking_initial_balance = ($initial_balance > 0 && $fund_account_type === 'Checking') ? $initial_balance : 0;


                        mysqli_stmt_bind_param($account_stmt, "issdssssss",
                            $new_user_id,
                            $checking_account_number,
                            $checking_account_type,
                            $checking_initial_balance,
                            $currency,
                            $checking_sort_code,      // Sort code (for GBP), NULL otherwise
                            $checking_routing_number, // Routing number (for USD), NULL otherwise
                            $checking_iban,           // IBAN/BBAN
                            $common_swift_bic,        // Common SWIFT/BIC
                            $admin_created_at
                        );
                        if (!mysqli_stmt_execute($account_stmt)) {
                            $message = "Error creating Checking account: " . mysqli_error($conn);
                            $message_type = 'error';
                            $transaction_success = false;
                        } else {
                            $accounts_created_messages[] = "Checking Account: **" . $checking_account_number . "** (Balance: " . number_format($checking_initial_balance, 2) . " " . $currency . ")<br>IBAN/BBAN: **" . $checking_iban . "**";
                        }

                        // 2. Create Savings Account (only if checking was successful)
                        if ($transaction_success) {
                            $savings_account_number = generateUniqueNumericId($conn, 'accounts', 'account_number', 12); // Unique for Savings
                            if ($savings_account_number === false) {
                                $message = "Failed to generate unique Savings account number due to a database error.";
                                $message_type = 'error';
                                $transaction_success = false;
                                goto end_of_post_processing;
                            }

                            $savings_iban = NULL;
                            $savings_sort_code = NULL; // Explicitly set to NULL by default
                            $savings_routing_number = NULL; // Explicitly set to NULL by default

                            if ($currency === 'GBP') {
                                // Extract last 8 digits for UK IBAN part
                                $uk_iban_part_account_number = str_pad(substr($savings_account_number, -8), 8, '0', STR_PAD_LEFT);
                                $savings_iban = generateUniqueUkIban($conn, $common_sort_code, $uk_iban_part_account_number);
                                $savings_sort_code = $common_sort_code; // Assign common sort code for GBP
                            } elseif ($currency === 'EUR') {
                                // Extract last 10 digits for EUR IBAN part
                                $eur_iban_part_account_number = str_pad(substr($savings_account_number, -10), 10, '0', STR_PAD_LEFT);
                                $savings_iban = generateUniqueEurIban($conn, $eur_iban_part_account_number);
                            } elseif ($currency === 'USD') {
                                // For Savings, we will reuse the common_routing_number generated for Checking if it exists.
                                // If you need *different* routing numbers per account, you'd call generateUniqueUsdIban here again,
                                // but that's less typical for a single user's accounts at the same bank.
                                // If we're reusing common_routing_number, we still need a unique IBAN (BBAN part).
                                $usd_iban_details_savings = generateUniqueUsdIban($conn, $savings_account_number);
                                if ($usd_iban_details_savings !== false) {
                                     $savings_iban = $usd_iban_details_savings['iban'];
                                     // Re-use the common routing number that was generated for the first USD account
                                     $savings_routing_number = $common_routing_number;
                                } else {
                                     $message = "Failed to generate unique USD 'IBAN' for Savings account.";
                                     $message_type = 'error';
                                     $transaction_success = false;
                                     goto end_of_post_processing;
                                }
                            }

                            // Consolidated check for IBAN generation
                            if ($savings_iban === false) {
                                $message = "Failed to generate unique IBAN/BBAN for Savings account.";
                                $message_type = 'error';
                                $transaction_success = false;
                                goto end_of_post_processing;
                            }

                            $savings_account_type = 'Savings';
                            // Apply initial balance ONLY if it's positive and this is the chosen account type
                            $savings_initial_balance = ($initial_balance > 0 && $fund_account_type === 'Savings') ? $initial_balance : 0;

                            mysqli_stmt_bind_param($account_stmt, "issdssssss",
                                $new_user_id,
                                $savings_account_number,
                                $savings_account_type,
                                $savings_initial_balance,
                                $currency,
                                $savings_sort_code,       // Sort code (for GBP), NULL otherwise
                                $savings_routing_number,  // Routing number (for USD), NULL otherwise
                                $savings_iban,            // IBAN/BBAN
                                $common_swift_bic,        // Common SWIFT/BIC
                                $admin_created_at
                            );
                            if (!mysqli_stmt_execute($account_stmt)) {
                                $message = "Error creating Savings account: " . mysqli_error($conn);
                                $message_type = 'error';
                                $transaction_success = false;
                            } else {
                                $accounts_created_messages[] = "Savings Account: **" . $savings_account_number . "** (Balance: " . number_format($savings_initial_balance, 2) . " " . $currency . ")<br>IBAN/BBAN: **" . $savings_iban . "**";
                            }
                        }
                        mysqli_stmt_close($account_stmt); // Close account statement

                        // Add common bank details to success message
                        if ($transaction_success) {
                            // These are common for the user's accounts based on chosen currency
                            if ($common_sort_code) $accounts_created_messages[] = "User's Common Sort Code: **" . $common_sort_code . "**";
                            if ($common_routing_number) $accounts_created_messages[] = "User's Common Routing Number: **" . $common_routing_number . "**"; // ADDED
                            if ($common_swift_bic) $accounts_created_messages[] = "User's Common SWIFT/BIC: **" . $common_swift_bic . "**";
                        }

                    } else {
                        $message = "Database query preparation failed for accounts: " . mysqli_error($conn);
                        $message_type = 'error';
                        $transaction_success = false;
                    }
                }
                mysqli_stmt_close($user_stmt); // Close user statement

            } else {
                $message = "Database query preparation failed for user: " . mysqli_error($conn);
                $message_type = 'error';
                $transaction_success = false;
            }

            // Commit or Rollback transaction
            if ($transaction_success) {
                mysqli_commit($conn);
                $message = "User '{$first_name} {$last_name}' created successfully! <br>Membership Number: **{$membership_number}**<br>Initial Account Details:<br>" . implode("<br>", $accounts_created_messages);
                $message_type = 'success';
                // Clear form fields on success
                $_POST = array();
            } else {
                mysqli_rollback($conn);
                // The error message is already set above
                // If profile image was uploaded, delete it on rollback
                if ($profile_image_path && file_exists('../../' . $profile_image_path)) {
                    unlink('../../' . $profile_image_path);
                }
            }
            mysqli_autocommit($conn, TRUE); // Re-enable autocommit
            mysqli_close($conn);
        }
    }
    end_of_post_processing:; // Label for goto statement
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HomeTown Bank - Create User</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="create_user.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="dashboard-container">
        <header class="dashboard-header">
            <img src="../../images/logo.png" alt="HomeTown Bank Pa Logo" class="logo">
            <h2>Create New User</h2>
            <a href="../logout.php" class="logout-button">Logout</a>
        </header>

        <main class="dashboard-content">
            <?php if (!empty($message)): ?>
                <p class="message <?php echo $message_type; ?>"><?php echo $message; ?></p>
            <?php endif; ?>

            <form action="create_user.php" method="POST" class="form-standard" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="first_name">First Name <span class="required-asterisk">*</span></label>
                    <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name <span class="required-asterisk">*</span></label>
                    <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="email">Email Address <span class="required-asterisk">*</span></label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="password">Password <span class="required-asterisk">*</span></label>
                    <input type="password" id="password" name="password" required>
                </div>
                <div class="form-group full-width">
                    <label for="home_address">Home Address <span class="required-asterisk">*</span></label>
                    <textarea id="home_address" name="home_address" rows="3" required><?php echo htmlspecialchars($_POST['home_address'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label for="phone_number">Phone Number <span class="required-asterisk">*</span></label>
                    <input type="tel" id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="nationality">Nationality <span class="required-asterisk">*</span></label>
                    <input type="text" id="nationality" name="nationality" value="<?php echo htmlspecialchars($_POST['nationality'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label for="date_of_birth">Date of Birth <span class="required-asterisk">*</span></label>
                    <input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars($_POST['date_of_birth'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="gender">Gender <span class="required-asterisk">*</span></label>
                    <select id="gender" name="gender" required>
                        <option value="">-- Select Gender --</option>
                        <option value="Male" <?php echo (($_POST['gender'] ?? '') == 'Male') ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo (($_POST['gender'] ?? '') == 'Female') ? 'selected' : ''; ?>>Female</option>
                        <option value="Other" <?php echo (($_POST['gender'] ?? '') == 'Other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="occupation">Occupation <span class="required-asterisk">*</span></label>
                    <input type="text" id="occupation" name="occupation" value="<?php echo htmlspecialchars($_POST['occupation'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label for="profile_image">Profile Image (Max 5MB, JPG, PNG, GIF)</label>
                    <input type="file" id="profile_image" name="profile_image" accept="image/*">
                </div>

                <div class="form-group">
                    <label for="routing_number">Routing Number</label>
                    <input type="text" id="routing_number" name="routing_number_display" value="Auto-generated upon creation" readonly class="readonly-field">
                    <small>This will be automatically generated and assigned to the user's account(s) if USD is selected.</small>
                </div>

                <div class="form-group">
                    <label for="initial_balance">Initial Balance Amount (Optional)</label>
                    <input type="number" id="initial_balance" name="initial_balance" step="0.01" value="<?php echo htmlspecialchars($_POST['initial_balance'] ?? '0.00'); ?>">
                    <small>Enter an amount to initially fund the user's chosen account type. Leave 0.00 if no initial funding.</small>
                </div>
                <div class="form-group">
                    <label for="currency">Account Currency <span class="required-asterisk">*</span></label>
                    <select id="currency" name="currency" required>
                        <option value="GBP" <?php echo (($_POST['currency'] ?? 'GBP') == 'GBP') ? 'selected' : ''; ?>>GBP</option>
                        <option value="EUR" <?php echo (($_POST['currency'] ?? '') == 'EUR') ? 'selected' : ''; ?>>EURO</option>
                        <option value="USD" <?php echo (($_POST['currency'] ?? '') == 'USD') ? 'selected' : ''; ?>>USD</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="fund_account_type">Fund Which Account? (Optional)</label>
                    <select id="fund_account_type" name="fund_account_type">
                        <option value="">-- Select Account Type (Optional) --</option>
                        <option value="Checking" <?php echo (($_POST['fund_account_type'] ?? '') == 'Checking') ? 'selected' : ''; ?>>Checking Account</option>
                        <option value="Savings" <?php echo (($_POST['fund_account_type'] ?? '') == 'Savings') ? 'selected' : ''; ?>>Savings Account</option>
                    </select>
                    <small>If an initial balance is provided, you must select an account type here.</small>
                </div>

                <div class="form-group">
                    <label for="admin_created_at">Account Creation Date & Time <span class="required-asterisk">*</span></label>
                    <input type="datetime-local" id="admin_created_at" name="admin_created_at" value="<?php echo htmlspecialchars($_POST['admin_created_at'] ?? date('Y-m-d\TH:i')); ?>" required>
                    <small>Set the exact date and time the account was (or should be) created.</small>
                </div>

                <button type="submit" class="button-primary">Create User</button>
            </form>

            <p><a href="users_management.php" class="back-link">&larr; Back to User Management</a></p>
        </main>
    </div>
    <style>
        .readonly-field {
            background-color: #e9e9e9;
            cursor: not-allowed;
        }
        .required-asterisk {
            color: red;
            font-weight: bold;
            margin-left: 5px;
        }
    </style>
    </body>
</html>