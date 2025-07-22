<?php
session_start(); // Only one session_start() needed
require_once '../Config.php'; // Contains DB constants, SMTP settings etc.
require_once '../vendor/autoload.php'; // For PHPMailer
require_once '../functions.php'; // Contains sendEmail, get_currency_symbol, bcmul_precision, bcsub_precision, bcadd_precision, complete_pending_transfer, reject_pending_transfer

// Check if the user is logged in. If not, redirect to login page.
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Initialize variables for redirection with messages
$message = '';
$message_type = '';
$post_data_for_redirect = []; // To preserve form data in case of error
$_SESSION['show_modal_on_load'] = false; // Flag to show the confirmation modal

// Only process if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['initiate_transfer'])) {

    // Store POST data to pass back if there's an error
    $post_data_for_redirect = $_POST;

    // Establish database connection using mysqli
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        $message = "ERROR: Could not connect to database. Please try again later.";
        $message_type = 'error';
        goto end_processing; // Jump to the end for redirection
    }

    // Fetch user's email and full name
    $user_email = '';
    $full_name = 'User';
    $user_preferred_currency = ''; // New: To store user's preferred currency from DB
    $stmt_user_details = $conn->prepare("SELECT first_name, last_name, email, preferred_currency FROM users WHERE id = ?");
    if ($stmt_user_details) {
        $stmt_user_details->bind_param("i", $user_id);
        $stmt_user_details->execute();
        $result_user_details = $stmt_user_details->get_result();
        if ($user_details_row = $result_user_details->fetch_assoc()) {
            $full_name = trim($user_details_row['first_name'] . ' ' . $user_details_row['last_name']);
            $user_email = $user_details_row['email'];
            $user_preferred_currency = strtoupper($user_details_row['preferred_currency']); // Fetch preferred currency
        }
        $stmt_user_details->close();
    } else {
        error_log("Failed to prepare user details statement: " . $conn->error);
        $message = "A system error occurred. Please try again later.";
        $message_type = 'error';
        goto end_processing;
    }

    // Fetch all accounts belonging to the logged-in user
    $user_accounts = [];
    $stmt_accounts = $conn->prepare("SELECT id, account_number, account_type, balance, currency FROM accounts WHERE user_id = ? AND status = 'active'");
    if ($stmt_accounts) {
        $stmt_accounts->bind_param("i", $user_id);
        $stmt_accounts->execute();
        $result_accounts = $stmt_accounts->get_result();
        while ($account_row = $result_accounts->fetch_assoc()) {
            $user_accounts[] = $account_row;
        }
        $stmt_accounts->close();
    } else {
        error_log("Failed to prepare user accounts statement: " . $conn->error);
        $message = "A system error occurred. Please try again later.";
        $message_type = 'error';
        goto end_processing;
    }

    if (empty($user_accounts)) {
        $message = "You don't have any active accounts linked to your profile. Please contact support.";
        $message_type = 'error';
        goto end_processing;
    }

    // Input Sanitization and Validation
    $source_account_id = filter_var($_POST['source_account_id'] ?? '', FILTER_VALIDATE_INT);
    $transfer_method = trim($_POST['transfer_method'] ?? '');
    $amount_str = trim($_POST['amount'] ?? ''); // Keep as string for bcmath
    $description = trim($_POST['description'] ?? '');
    $recipient_name = trim($_POST['recipient_name'] ?? '');

    // Get source account details from the fetched list (DO NOT TRUST CLIENT-SIDE DATA)
    $source_account = null;
    foreach ($user_accounts as $acc) {
        if ($acc['id'] == $source_account_id) {
            $source_account = $acc;
            break;
        }
    }

    if (!$source_account) {
        $message = "Invalid source account selected or account not found/active for your profile.";
        $message_type = 'error';
        goto end_processing;
    }

    $sender_currency = strtoupper($source_account['currency']); // Get sender's currency from the fetched account details

    // --- NEW GLOBAL CURRENCY RESTRICTION CHECK ---
    // Make sure ALLOWED_TRANSFER_CURRENCIES is defined in Config.php, e.g.:
    // define('ALLOWED_TRANSFER_CURRENCIES', ['GBP', 'EUR', 'USD']);
    // Add USD to the allowed currencies.
    // Also, allow transfer if the sender's currency matches their preferred currency set in the admin dashboard.
    $allowed_currencies_config = defined('ALLOWED_TRANSFER_CURRENCIES') ? ALLOWED_TRANSFER_CURRENCIES : [];
    
    // Convert all allowed currencies to uppercase for consistent comparison
    $allowed_currencies_upper = array_map('strtoupper', $allowed_currencies_config);

    $is_allowed_by_config = in_array($sender_currency, $allowed_currencies_upper);
    $is_allowed_by_preferred_currency = ($user_preferred_currency !== '' && $sender_currency === $user_preferred_currency);

    if (!$is_allowed_by_config && !$is_allowed_by_preferred_currency) {
        $allowed_list_display = implode(', ', $allowed_currencies_upper);
        if ($user_preferred_currency) {
             $allowed_list_display .= " or your preferred currency (" . $user_preferred_currency . ")";
        }
        $message = "Transfers are currently only allowed in " . $allowed_list_display . ". Your selected account is in " . htmlspecialchars($sender_currency) . ".";
        $message_type = 'error';
        goto end_processing;
    }
    // --- END NEW GLOBAL CURRENCY RESTRICTION CHECK ---

    // Common validations
    // Use bcmath for financial comparisons
    if (!is_numeric($amount_str) || bccomp($amount_str, '0', 2) <= 0) {
        $message = 'Please enter a positive amount.';
        $message_type = 'error';
        goto end_processing;
    }
    if (bccomp($amount_str, $source_account['balance'], 2) > 0) {
        $message = 'Insufficient funds in the selected source account for this transfer.';
        $message_type = 'error';
        goto end_processing;
    }
    if (empty($transfer_method)) {
        $message = 'Please select a transfer method.';
        $message_type = 'error';
        goto end_processing;
    }

    $sender_current_balance = $source_account['balance'];
    // $sender_currency is already validated and set above
    $sender_account_number = $source_account['account_number'];

    // Generate a unique transaction reference early
    $transaction_reference = 'HTB-' . date('YmdHis') . '-' . substr(uniqid(), -6); // Changed to HTB

    // Initialize transaction data array
    $transaction_data = [
        'user_id' => $user_id,
        'account_id' => $source_account_id,
        'amount' => $amount_str, // Stored as string for precision
        'currency' => $sender_currency,
        'transaction_type' => 'DEBIT', // Default for sender's transaction
        'description' => $description,
        'status' => 'PENDING', // All user-initiated transfers are PENDING for admin approval
        'initiated_at' => date('Y-m-d H:i:s'),
        'transaction_reference' => $transaction_reference,
        'recipient_name' => htmlspecialchars($recipient_name), // Sanitize for storage
        'recipient_account_number' => null,
        'recipient_iban' => null,
        'recipient_swift_bic' => null,
        'recipient_sort_code' => null,
        'recipient_external_account_number' => null,
        'recipient_user_id' => null, // For internal transfers
        'recipient_bank_name' => null,
        'sender_name' => htmlspecialchars($full_name), // Sanitize sender's name
        'sender_account_number' => $sender_account_number,
        'sender_user_id' => $user_id,
        'converted_amount' => null, // For currency exchange, set later if needed
        'converted_currency' => null, // For currency exchange, set later if needed
        'exchange_rate' => null, // For currency exchange, set later if needed
        'external_bank_details' => null // JSON encoded string for external
    ];

    // Begin transaction for database integrity
    $conn->begin_transaction();
    try {
        // Deduct amount immediately from source account (optimistic locking with balance check)
        // Use bcsub_precision for accurate deduction
        $new_sender_balance = bcsub_precision($sender_current_balance, $amount_str, 2);
        $stmt_deduct_source_account = $conn->prepare("UPDATE accounts SET balance = ? WHERE id = ? AND user_id = ? AND balance >= ?");
        if (!$stmt_deduct_source_account) {
            throw new Exception("Failed to prepare source account deduction statement: " . $conn->error);
        }
        $stmt_deduct_source_account->bind_param("dsid", $new_sender_balance, $source_account_id, $user_id, $amount_str);
        if (!$stmt_deduct_source_account->execute()) {
            throw new Exception("Failed to deduct from source account: " . $stmt_deduct_source_account->error);
        }
        // Check if any rows were actually affected, indicating the update worked and funds were sufficient
        if ($conn->affected_rows === 0) {
            throw new Exception("Source account balance update failed or insufficient funds (concurrency issue detected).");
        }
        $stmt_deduct_source_account->close();

        // Method-specific logic for recipient details and transaction type
        $is_internal_transfer = false;
        // $recipient_account_currency is implicitly the same as sender_currency for now due to the global check

        switch ($transfer_method) {
            case 'internal_self':
                $is_internal_transfer = true;
                $destination_account_id_self = filter_var($_POST['destination_account_id_self'] ?? '', FILTER_VALIDATE_INT);
                if (empty($destination_account_id_self) || $destination_account_id_self == $source_account_id) {
                    throw new Exception("Please select a valid different account for self-transfer.");
                }
                $destination_account = null;
                foreach ($user_accounts as $acc) {
                    if ($acc['id'] == $destination_account_id_self) {
                        $destination_account = $acc;
                        break;
                    }
                }
                if (!$destination_account) {
                    throw new Exception("Invalid destination account for self-transfer.");
                }
                // Currency match for self-transfer: ensure source and destination accounts are in the same currency.
                // This is crucial for internal self-transfers to prevent accidental cross-currency transfers within a user's accounts.
                if (strtoupper($destination_account['currency']) !== $sender_currency) { // Compare with already uppercased sender_currency
                    throw new Exception("Currency mismatch for self-transfer. Accounts must be in the same currency.");
                }

                $transaction_data['transaction_type'] = 'INTERNAL_SELF_TRANSFER_OUT'; // Specific type for sender's debit
                $transaction_data['recipient_user_id'] = $user_id;
                $transaction_data['recipient_account_number'] = $destination_account['account_number'];
                // For internal_self, the credit record will be created by admin_process_transfer when approved.
                // The current user's debit record is marked pending.
                break;

            case 'internal_heritage': // Renamed 'internal_hometown_bank' for consistency
                $is_internal_transfer = true;
                $recipient_account_number_internal = trim($_POST['recipient_account_number_internal'] ?? '');
                if (empty($recipient_account_number_internal) || empty($recipient_name)) {
                    throw new Exception("Recipient account number and name are required for HomeTown Bank Pa transfer.");
                }

                $stmt_internal_rec = $conn->prepare("SELECT id, user_id, currency, account_number FROM accounts WHERE account_number = ? AND status = 'active'");
                if (!$stmt_internal_rec) {
                    throw new Exception("Failed to prepare internal recipient lookup: " . $conn->error);
                }
                $stmt_internal_rec->bind_param("s", $recipient_account_number_internal);
                $stmt_internal_rec->execute();
                $result_internal_rec = $stmt_internal_rec->get_result();
                $internal_recipient_row = $result_internal_rec->fetch_assoc();
                $stmt_internal_rec->close();

                if (!$internal_recipient_row) {
                    throw new Exception("Recipient HomeTown Bank Pa account not found or is inactive.");
                }
                if ($internal_recipient_row['user_id'] == $user_id) {
                    throw new Exception("You cannot transfer to your own account using this method. Use 'Between My Accounts'.");
                }
                // Currency match for HomeTown Bank Pa transfer: ensure sender and recipient accounts are in the same currency.
                if (strtoupper($internal_recipient_row['currency']) !== $sender_currency) { // Compare with already uppercased sender_currency
                    throw new Exception("Currency mismatch for HomeTown Bank Pa transfer. Accounts must be in the same currency.");
                }

                $transaction_data['transaction_type'] = 'INTERNAL_TRANSFER_OUT'; // Specific type for sender's debit
                $transaction_data['recipient_user_id'] = $internal_recipient_row['user_id'];
                $transaction_data['recipient_account_number'] = $internal_recipient_row['account_number'];
                // Similar to self-transfer, the recipient's credit record is created upon admin approval.
                break;

            case 'external_iban':
                $recipient_iban = trim($_POST['recipient_iban'] ?? '');
                $recipient_swift_bic = trim($_POST['recipient_swift_bic'] ?? '');
                $recipient_bank_name_iban = trim($_POST['recipient_bank_name_iban'] ?? '');
                $recipient_country = trim($_POST['recipient_country'] ?? ''); // New: Country for IBAN transfers

                if (empty($recipient_iban) || empty($recipient_swift_bic) || empty($recipient_bank_name_iban) || empty($recipient_name) || empty($recipient_country)) {
                    throw new Exception("All IBAN transfer details (IBAN, SWIFT/BIC, Bank Name, Recipient Name, and Country) are required.");
                }
                // Basic format validation (more robust regex for IBANs can be found online)
                if (strlen($recipient_iban) < 15 || strlen($recipient_iban) > 34 || !preg_match('/^[A-Z0-9]+$/', strtoupper($recipient_iban))) {
                    throw new Exception("Invalid IBAN format. Must be alphanumeric, 15-34 characters.");
                }
                if (strlen($recipient_swift_bic) < 8 || strlen($recipient_swift_bic) > 11 || !preg_match('/^[A-Z0-9]+$/', strtoupper($recipient_swift_bic))) {
                    throw new Exception("Invalid SWIFT/BIC format. Must be alphanumeric, 8 or 11 characters.");
                }

                $external_bank_details_array = [
                    'iban' => strtoupper($recipient_iban),
                    'swift_bic' => strtoupper($recipient_swift_bic),
                    'bank_name' => $recipient_bank_name_iban,
                    'country' => $recipient_country, // Store country
                ];
                $transaction_data['external_bank_details'] = json_encode($external_bank_details_array);
                $transaction_data['recipient_iban'] = strtoupper($recipient_iban); // Store in specific column for easier querying
                $transaction_data['recipient_swift_bic'] = strtoupper($recipient_swift_bic); // Store in specific column
                $transaction_data['recipient_bank_name'] = $recipient_bank_name_iban; // Store in specific column
                $transaction_data['transaction_type'] = 'EXTERNAL_IBAN_TRANSFER_OUT';
                // The check for ALLOWED_TRANSFER_CURRENCIES (GBP/EUR/USD) or user's preferred currency is already done globally.
                break;

            case 'external_sort_code':
                $recipient_sort_code = trim($_POST['recipient_sort_code'] ?? '');
                $recipient_external_account_number = trim($_POST['recipient_external_account_number'] ?? '');
                $recipient_bank_name_sort = trim($_POST['recipient_bank_name_sort'] ?? '');

                if (empty($recipient_sort_code) || empty($recipient_external_account_number) || empty($recipient_bank_name_sort) || empty($recipient_name)) {
                    throw new Exception("All Sort Code transfer details (Sort Code, Account Number, Bank Name, Recipient Name) are required.");
                }
                if (!preg_match('/^\d{6}$/', $recipient_sort_code)) {
                    throw new Exception("Invalid UK Sort Code format (6 digits required).");
                }
                if (!preg_match('/^\d{8}$/', $recipient_external_account_number)) {
                    throw new Exception("Invalid UK Account Number format (8 digits required).");
                }

                $external_bank_details_array = [
                    'sort_code' => $recipient_sort_code,
                    'account_number' => $recipient_external_account_number,
                    'bank_name' => $recipient_bank_name_sort,
                ];
                $transaction_data['external_bank_details'] = json_encode($external_bank_details_array);
                $transaction_data['recipient_sort_code'] = $recipient_sort_code; // Store in specific column
                $transaction_data['recipient_external_account_number'] = $recipient_external_account_number; // Store in specific column
                $transaction_data['recipient_bank_name'] = $recipient_bank_name_sort; // Store in specific column
                $transaction_data['transaction_type'] = 'EXTERNAL_SORT_CODE_TRANSFER_OUT';
                // The check for ALLOWED_TRANSFER_CURRENCIES (GBP/EUR/USD) or user's preferred currency is already done globally.
                break;
            
            case 'external_usa_account': // New case for USA accounts (ACH/Wire details)
                $recipient_usa_account_number = trim($_POST['recipient_usa_account_number'] ?? '');
                $recipient_usa_routing_number = trim($_POST['recipient_usa_routing_number'] ?? '');
                $recipient_bank_name_usa = trim($_POST['recipient_bank_name_usa'] ?? '');
                $recipient_address_usa = trim($_POST['recipient_address_usa'] ?? '');
                $recipient_city_usa = trim($_POST['recipient_city_usa'] ?? '');
                $recipient_state_usa = trim($_POST['recipient_state_usa'] ?? '');
                $recipient_zip_usa = trim($_POST['recipient_zip_usa'] ?? '');
                $recipient_account_type_usa = trim($_POST['recipient_account_type_usa'] ?? '');

                if (empty($recipient_usa_account_number) || empty($recipient_usa_routing_number) || empty($recipient_bank_name_usa) || empty($recipient_name) || empty($recipient_address_usa) || empty($recipient_city_usa) || empty($recipient_state_usa) || empty($recipient_zip_usa) || empty($recipient_account_type_usa)) {
                    throw new Exception("All USA account transfer details (Account Number, Routing Number, Bank Name, Recipient Name, Address, City, State, Zip, and Account Type) are required.");
                }
                // Basic format validation for USA details (can be enhanced with more specific regex)
                if (!preg_match('/^\d{9}$/', $recipient_usa_routing_number)) {
                    throw new Exception("Invalid USA Routing Number format (9 digits required).");
                }
                if (!in_array($recipient_account_type_usa, ['Checking', 'Savings'])) {
                    throw new Exception("Invalid USA Account Type. Must be 'Checking' or 'Savings'.");
                }

                $external_bank_details_array = [
                    'account_number' => $recipient_usa_account_number,
                    'routing_number' => $recipient_usa_routing_number,
                    'bank_name' => $recipient_bank_name_usa,
                    'address' => $recipient_address_usa,
                    'city' => $recipient_city_usa,
                    'state' => $recipient_state_usa,
                    'zip' => $recipient_zip_usa,
                    'account_type' => $recipient_account_type_usa,
                ];
                $transaction_data['external_bank_details'] = json_encode($external_bank_details_array);
                $transaction_data['recipient_external_account_number'] = $recipient_usa_account_number; // Store in generic external account field
                $transaction_data['recipient_bank_name'] = $recipient_bank_name_usa; // Store in specific column
                $transaction_data['transaction_type'] = 'EXTERNAL_USA_TRANSFER_OUT';
                // Crucially, enforce that this transfer method requires the sender's account to be in USD
                if ($sender_currency !== 'USD') {
                    throw new Exception("USA account transfers are only available for USD accounts. Your selected account is in " . htmlspecialchars($sender_currency) . ".");
                }
                break;

            default:
                throw new Exception("Invalid transfer method selected.");
        }

        // Handle currency conversion if needed (e.g., if source GBP to target EUR, but based on current rule,
        // both are already GBP or EUR, so conversion might be implied if the recipient currency differs,
        // but for now, we assume same-currency as per the global restriction)
        $converted_amount = $amount_str;
        $converted_currency = $sender_currency;
        $exchange_rate = 1.0;

        // Populate the transaction_data array for insertion
        $p_user_id_tx = $transaction_data['user_id'];
        $p_account_id_tx = $transaction_data['account_id'];
        $p_amount_tx = $transaction_data['amount'];
        $p_currency_tx = $transaction_data['currency'];
        $p_transaction_type_tx = $transaction_data['transaction_type'];
        $p_description_tx = $transaction_data['description'];
        $p_status_tx = $transaction_data['status'];
        $p_initiated_at_tx = $transaction_data['initiated_at'];
        $p_transaction_reference_tx = $transaction_data['transaction_reference'];
        $p_recipient_name_tx = $transaction_data['recipient_name'];
        $p_recipient_account_number_tx = $transaction_data['recipient_account_number'];
        $p_recipient_iban_tx = $transaction_data['recipient_iban'];
        $p_recipient_swift_bic_tx = $transaction_data['recipient_swift_bic'];
        $p_recipient_sort_code_tx = $transaction_data['recipient_sort_code'];
        $p_recipient_external_account_number_tx = $transaction_data['recipient_external_account_number'];
        $p_recipient_user_id_tx = $transaction_data['recipient_user_id'];
        $p_recipient_bank_name_tx = $transaction_data['recipient_bank_name'];
        $p_sender_name_tx = $transaction_data['sender_name'];
        $p_sender_account_number_tx = $transaction_data['sender_account_number'];
        $p_sender_user_id_tx = $transaction_data['sender_user_id'];
        $p_converted_amount_tx = $converted_amount; // Set here
        $p_converted_currency_tx = $converted_currency; // Set here
        $p_exchange_rate_tx = $exchange_rate; // Set here
        $p_external_bank_details_tx = $transaction_data['external_bank_details'];


        // Insert transaction into the 'transactions' table (sender's PENDING record)
        $stmt_insert_tx = $conn->prepare(
            "INSERT INTO transactions (
                user_id, account_id, amount, currency, transaction_type, description, status, initiated_at,
                transaction_reference, recipient_name, recipient_account_number, recipient_iban,
                recipient_swift_bic, recipient_sort_code, recipient_external_account_number,
                recipient_user_id, recipient_bank_name, sender_name, sender_account_number,
                sender_user_id, converted_amount, converted_currency, exchange_rate,
                external_bank_details, transaction_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())"
        );

        if (!$stmt_insert_tx) {
            throw new Exception("Failed to prepare transaction insert statement: " . $conn->error);
        }

        // The binding for external_bank_details_tx should be 's' for string (JSON)
        $stmt_insert_tx->bind_param("iidssssssssssssisssisssd",
            $p_user_id_tx, $p_account_id_tx, $p_amount_tx, $p_currency_tx, $p_transaction_type_tx,
            $p_description_tx, $p_status_tx, $p_initiated_at_tx, $p_transaction_reference_tx,
            $p_recipient_name_tx, $p_recipient_account_number_tx, $p_recipient_iban_tx,
            $p_recipient_swift_bic_tx, $p_recipient_sort_code_tx, $p_recipient_external_account_number_tx,
            $p_recipient_user_id_tx, $p_recipient_bank_name_tx, $p_sender_name_tx,
            $p_sender_account_number_tx, $p_sender_user_id_tx, $p_converted_amount_tx,
            $p_converted_currency_tx, $p_exchange_rate_tx, $p_external_bank_details_tx
        );

        if (!$stmt_insert_tx->execute()) {
            throw new Exception("Failed to insert transaction record: " . $stmt_insert_tx->error);
        }
        $transaction_id = $conn->insert_id; // Get the ID of the newly inserted transaction
        $stmt_insert_tx->close();

        // Commit the transaction if all operations were successful
        $conn->commit();
        $message = 'Transfer of ' . htmlspecialchars(get_currency_symbol($sender_currency) . number_format((float)$amount_str, 2)) . ' initiated successfully! It is currently **Pending** admin approval.';
        $message_type = 'success';
        $_SESSION['show_modal_on_load'] = true; // Set flag to display modal

        // Store transfer details for the modal
        $_SESSION['transfer_success_details'] = [
            'amount' => number_format((float)$amount_str, 2),
            'currency' => $sender_currency,
            'recipient_name' => htmlspecialchars($recipient_name),
            'status' => 'Pending',
            'reference' => $p_transaction_reference_tx,
            'method' => str_replace('_', ' ', $transfer_method)
        ];

        // Send Email Confirmation
        $email_subject = "HomeTown Bank Pa: Transfer Initiated - Ref: " . htmlspecialchars($p_transaction_reference_tx); // Updated Bank Name

        $email_body = '
        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.6; color: #333333; background-color: #f4f4f4;">
            <tr>
                <td align="center" style="padding: 20px 0;">
                    <table border="0" cellpadding="0" cellspacing="0" width="600" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
                        <tr>
                            <td align="center" style="padding: 20px 0; background-color: #004A7F;">
                                <img src="https://i.imgur.com/YmC3kg3.png" alt="HomeTown Bank Pa Logo" style="display: block; max-width: 100px; height: auto; margin: 0 auto; padding: 10px;"> </td>
                        </tr>
                        <tr>
                            <td style="padding: 30px 40px;">
                                <p style="font-size: 16px; font-weight: bold; color: #004A7F; margin-bottom: 20px;">Dear ' . htmlspecialchars($full_name) . ',</p>
                                <p style="margin-bottom: 20px;">Your transfer request has been successfully initiated.</p>

                                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom: 25px; border: 1px solid #dddddd; border-collapse: collapse;">
                                    <tr>
                                        <td style="padding: 12px 15px; background-color: #f9f9f9; width: 40%; font-weight: bold; border-bottom: 1px solid #eeeeee;">Amount:</td>
                                        <td style="padding: 12px 15px; background-color: #f9f9f9; border-bottom: 1px solid #eeeeee;">' . htmlspecialchars(get_currency_symbol($sender_currency) . number_format((float)$amount_str, 2)) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 12px 15px; width: 40%; font-weight: bold; border-bottom: 1px solid #eeeeee;">Recipient:</td>
                                        <td style="padding: 12px 15px; border-bottom: 1px solid #eeeeee;">' . htmlspecialchars($recipient_name) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 12px 15px; background-color: #f9f9f9; width: 40%; font-weight: bold; border-bottom: 1px solid #eeeeee;">Transfer Method:</td>
                                        <td style="padding: 12px 15px; background-color: #f9f9f9; border-bottom: 1px solid #eeeeee;">' . htmlspecialchars(str_replace('_', ' ', $transfer_method)) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 12px 15px; width: 40%; font-weight: bold; border-bottom: 1px solid #eeeeee;">Status:</td>
                                        <td style="padding: 12px 15px; border-bottom: 1px solid #eeeeee;"><strong style="color: #FFA500;">Pending</strong></td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 12px 15px; background-color: #f9f9f9; width: 40%; font-weight: bold;">Reference:</td>
                                        <td style="padding: 12px 15px; background-color: #f9f9f9;">' . htmlspecialchars($p_transaction_reference_tx) . '</td>
                                    </tr>
                                </table>

                                <p style="margin-top: 20px;">We will notify you once your transfer has been processed by our team. You can monitor its status in your transaction history.</p>
                                <p style="margin-top: 20px; font-weight: bold; color: #004A7F;">Thank you for banking with HomeTown Bank Pa.</p>
                                <p style="font-size: 15px; color: #555555; margin-top: 5px;">The HomeTown Bank Pa Team</p>

                                <p style="font-size: 11px; color: #888888; text-align: center; margin-top: 40px; border-top: 1px solid #eeeeee; padding-top: 20px;">
                                    This is an automated email, please do not reply.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <td align="center" style="padding: 20px; background-color: #004A7F; color: #ffffff; font-size: 12px;">
                                &copy; ' . date("Y") . ' HomeTown Bank Pa. All rights reserved.
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>';

        sendEmail($user_email, $email_subject, $email_body);

    } catch (Exception $e) {
        $conn->rollback(); // Rollback transaction on error
        $message = 'Transfer failed: ' . $e->getMessage();
        $message_type = 'error';
        error_log("User Transfer Error (make_transfer.php): " . $e->getMessage() . " (User ID: {$user_id}, Sender Account: {$source_account_id}, Ref: {$transaction_reference})");

        $_SESSION['form_data'] = $post_data_for_redirect; // Keep original POST data for error message
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $message_type;
        $_SESSION['show_modal_on_load'] = false; // Do not show modal on error
    } finally {
        // Ensure connection is closed whether successful or not
        if (isset($conn) && $conn) {
            $conn->close();
        }
    }

    end_processing:
    // Redirect back to transfer.php with messages and optionally, old form data
    header('Location: transfer.php');
    exit;

} else {
    // If not a POST request, just redirect to transfer.php to display the form
    header('Location: transfer.php');
    exit;
}