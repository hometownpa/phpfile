<?php
// Ensure Composer autoload is at the very top for PHPMailer
require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// IMPORTANT: Config.php with database credentials and SMTP constants
// MUST be included/required BEFORE this functions.php file in any script that uses these functions.
// Example:
// require_once __DIR__ . '/../Config.php'; // Adjust path as per your project structure
// require_once __DIR__ . '/functions.php';

// --- Helper Functions for Email (PHPMailer - NO DATABASE DEPENDENCY) ---

/**
 * Sends an email using PHPMailer.
 * Requires SMTP constants to be defined in Config.php (SMTP_HOST, SMTP_USERNAME, etc.).
 *
 * @param string $to The recipient's email address.
 * @param string $subject The email subject.
 * @param string $body The email body (HTML allowed).
 * @param string|null $altBody Optional plain text body.
 * @return bool True on success, false on failure.
 */
function sendEmail(string $to, string $subject, string $body, ?string $altBody = null): bool {
    $mail = new PHPMailer(true); // Enable exceptions

    try {
        // Server settings - NOW USING CONSTANTS FROM Config.php
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;           // Use constant from Config.php
        $mail->SMTPAuth   = true;                // Enable SMTP authentication
        $mail->Username   = SMTP_USERNAME;       // Use constant from Config.php
        $mail->Password   = SMTP_PASSWORD;       // Use constant from Config.php

        // Correctly set SMTPSecure based on constant from Config.php
        // PHPMailer::ENCRYPTION_SMTPS for SSL, PHPMailer::ENCRYPTION_STARTTLS for TLS
        if (defined('SMTP_ENCRYPTION') && strtolower(SMTP_ENCRYPTION) === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (defined('SMTP_ENCRYPTION') && strtolower(SMTP_ENCRYPTION) === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            // Default or no encryption if not specified, often not recommended for production
            $mail->SMTPSecure = ''; // No encryption
        }
        $mail->Port       = SMTP_PORT;           // Use constant from Config.php

        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME); // Use constants from Config.php
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = $altBody ?: strip_tags($body); // Fallback to stripped HTML if altBody not provided

        $mail->send();
        error_log("Email sent successfully to $to. Subject: $subject");
        return true;
    } catch (Exception $e) {
        // Log the error for debugging. These errors are critical for email delivery.
        error_log("Email could not be sent to $to. Mailer Error: {$mail->ErrorInfo}. Exception: {$e->getMessage()}");
        return false;
    }
}

// --- Helper Functions for Currency and Financial Math (bcmath - NO DATABASE DEPENDENCY) ---

/**
 * Returns the currency symbol for a given currency code.
 *
 * @param string $currencyCode The 3-letter currency code (e.g., USD, EUR).
 * @return string The currency symbol or the code itself if not found.
 */
function get_currency_symbol(string $currencyCode): string {
    switch (strtoupper($currencyCode)) {
        case 'USD': return '$';
        case 'EUR': return '€';
        case 'GBP': return '£';
        case 'JPY': return '¥';
        case 'NGN': return '₦';
        case 'CAD': return 'C$';
        case 'AUD': return 'A$';
        case 'CHF': return 'CHF'; // Swiss Franc
        case 'CNY': return '¥';   // Chinese Yuan (same as JPY, context matters)
        case 'INR': return '₹';   // Indian Rupee
        case 'ZAR': return 'R';   // South African Rand
        // Add more as needed
        default: return $currencyCode; // Fallback to code if symbol not known
    }
}

/**
 * Multiplies two numbers with a specified precision using bcmath.
 *
 * @param string $num1 The first number as a string.
 * @param string $num2 The second number as a string.
 * @param int $precision The number of decimal places to round the result to.
 * @return string The result of the multiplication as a string.
 */
function bcmul_precision(string $num1, string $num2, int $precision = 2): string {
    // Add a buffer for internal calculations, then round to desired precision
    return bcadd('0', bcmul($num1, $num2, $precision + 4), $precision);
}

/**
 * Subtracts two numbers with a specified precision using bcmath.
 *
 * @param string $num1 The number to subtract from.
 * @param string $num2 The number to subtract.
 * @param int $precision The number of decimal places to round the result to.
 * @return string The result of the subtraction as a string.
 */
function bcsub_precision(string $num1, string $num2, int $precision = 2): string {
    // Add a buffer for internal calculations, then round to desired precision
    return bcadd('0', bcsub($num1, $num2, $precision + 4), $precision);
}

/**
 * Adds two numbers with a specified precision using bcmath.
 *
 * @param string $num1 The first number as a string.
 * @param string $num2 The second number as a string.
 * @param int $precision The number of decimal places to round the result to.
 * @return string The result of the addition as a string.
 */
function bcadd_precision(string $num1, string $num2, int $precision = 2): string {
    // Add a buffer for internal calculations, then round to desired precision
    return bcadd('0', bcadd($num1, $num2, $precision + 4), $precision);
}

/**
 * Sanitizes input string to prevent XSS and SQL Injection (basic).
 *
 * @param string|null $data The input string to sanitize.
 * @return string The sanitized string.
 */
function sanitize_input(?string $data): string {
    if ($data === null) {
        return '';
    }
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}


// --- DATABASE-RELATED HELPERS (EXCLUSIVELY USING MYSQLI) ---

/**
 * Fetches user details (specifically email and full_name) from the database.
 *
 * @param mysqli $conn The mysqli database connection object.
 * @param int $user_id The ID of the user.
 * @return array|null An associative array with user details, or null if not found.
 */
function get_user_details(mysqli $conn, int $user_id): ?array {
    $stmt = $conn->prepare("SELECT email, full_name FROM users WHERE id = ?");
    if (!$stmt) {
        error_log("Failed to prepare user details fetch statement: " . $conn->error);
        return null;
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    return $user;
}

/**
 * Processes the completion of a pending transfer, crediting the recipient and updating transaction status.
 * This function is intended for admin use after a user initiates a PENDING transfer.
 *
 * @param mysqli $conn The mysqli database connection object.
 * @param int $transaction_id The ID of the pending transaction to complete.
 * @return array An associative array with 'success' (bool) and 'message' (string), and 'transaction_details' (array|null).
 */
function complete_pending_transfer(mysqli $conn, int $transaction_id): array {
    // Begin transaction for atomicity
    $conn->begin_transaction();
    $transaction_details = null; // To store details for email notification

    try {
        // 1. Fetch the pending transaction details
        $stmt = $conn->prepare("SELECT
            t.id, t.amount, t.currency, t.transaction_type, t.recipient_user_id,
            t.recipient_account_number, t.recipient_name, t.recipient_iban,
            t.recipient_swift_bic, t.recipient_sort_code, t.recipient_external_account_number,
            t.recipient_bank_name, t.converted_amount, t.converted_currency, t.exchange_rate,
            t.status, t.user_id AS sender_user_id, t.account_id AS sender_account_id,
            t.sender_account_number, t.sender_name, t.description, t.initiated_at,
            t.external_bank_details, t.transaction_reference, t.HometownBank_comment
        FROM transactions t
        WHERE t.id = ? AND t.status = 'PENDING' FOR UPDATE"); // FOR UPDATE locks the row
        if (!$stmt) {
            throw new Exception("Failed to prepare transaction fetch statement: " . $conn->error);
        }
        $stmt->bind_param("i", $transaction_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $transaction = $result->fetch_assoc();
        $stmt->close();

        if (!$transaction) {
            return ['success' => false, 'message' => "Pending transaction not found or already processed.", 'transaction_details' => null];
        }
        $transaction_details = $transaction; // Store for return

        // Determine the actual amount and currency to credit for the recipient (or for external reporting)
        // Ensure these are treated as strings for bcmath functions
        $credit_amount_str = (string)($transaction['converted_amount'] ?? $transaction['amount']);
        $credit_currency = (string)($transaction['converted_currency'] ?? $transaction['currency']);

        // 2. Process based on transaction type
        if (strpos($transaction['transaction_type'], 'INTERNAL') !== false) {
            // This is an Internal Transfer (either self-transfer or to another Heritage Bank user)

            // Fetch recipient account details for crediting
            $stmt_rec_acc = $conn->prepare("SELECT id, balance, currency FROM accounts WHERE user_id = ? AND account_number = ? AND status = 'active' FOR UPDATE");
            if (!$stmt_rec_acc) {
                throw new Exception("Failed to prepare recipient account fetch statement: " . $conn->error);
            }
            $stmt_rec_acc->bind_param("is", $transaction['recipient_user_id'], $transaction['recipient_account_number']);
            $stmt_rec_acc->execute();
            $result_rec_acc = $stmt_rec_acc->get_result();
            $recipient_account = $result_rec_acc->fetch_assoc();
            $stmt_rec_acc->close();

            if (!$recipient_account) {
                throw new Exception("Recipient internal account not found or is inactive for transaction {$transaction_id}.");
            }

            // CRITICAL CHECK: Ensure recipient's account currency matches the transaction's credit currency
            if (strtoupper($recipient_account['currency']) !== strtoupper($credit_currency)) {
                throw new Exception("Currency mismatch for internal transfer credit. Expected " . $credit_currency . ", got " . $recipient_account['currency'] . " for transaction {$transaction_id}.");
            }

            $recipient_account_id = $recipient_account['id'];
            $new_recipient_balance_str = bcadd_precision((string)$recipient_account['balance'], $credit_amount_str, 2);

            // Credit recipient's account
            $stmt_credit_rec = $conn->prepare("UPDATE accounts SET balance = ? WHERE id = ?");
            if (!$stmt_credit_rec) {
                throw new Exception("Failed to prepare recipient account credit statement: " . $conn->error);
            }
            // Use 's' for string if storing DECIMAL in DB, or 'd' if PHP float/double
            $stmt_credit_rec->bind_param("si", $new_recipient_balance_str, $recipient_account_id);
            if (!$stmt_credit_rec->execute()) {
                throw new Exception("Failed to credit recipient account for transaction {$transaction_id}: " . $stmt_credit_rec->error);
            }
            if ($conn->affected_rows === 0) {
                throw new Exception("Recipient account update failed for transaction {$transaction_id}. Possible concurrency issue or invalid account ID.");
            }
            $stmt_credit_rec->close();

            // Insert recipient's credit transaction record
            $recipient_tx_type = (strpos($transaction['transaction_type'], 'SELF') !== false) ? 'INTERNAL_SELF_TRANSFER_IN' : 'INTERNAL_TRANSFER_IN';
            $stmt_insert_rec_tx = $conn->prepare(
                "INSERT INTO transactions (
                    user_id, account_id, amount, currency, transaction_type, description, status, initiated_at,
                    transaction_reference, recipient_name, recipient_account_number, recipient_iban,
                    recipient_swift_bic, recipient_sort_code, recipient_external_account_number,
                    recipient_user_id, recipient_bank_name, sender_name, sender_account_number,
                    sender_user_id, converted_amount, converted_currency, exchange_rate,
                    external_bank_details, transaction_date, completed_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), NOW())"
            );
            if (!$stmt_insert_rec_tx) {
                throw new Exception("Failed to prepare recipient transaction insert statement: " . $conn->error);
            }

            // Bind parameters for the recipient's transaction record
            $bind_recipient_user_id = $transaction['recipient_user_id'];
            $bind_recipient_account_id = $recipient_account_id;
            $bind_credit_amount_str = $credit_amount_str;
            $bind_credit_currency = $credit_currency;
            $bind_recipient_tx_type = $recipient_tx_type;
            $bind_description = $transaction['description'];
            $bind_status = 'COMPLETED';
            $bind_initiated_at = $transaction['initiated_at'];

            // Generate a unique transaction reference for the recipient's side
            $bind_transaction_reference_in = $transaction['transaction_reference'] . '_IN';

            $bind_recipient_name = $transaction['recipient_name'];
            $bind_recipient_account_number = $transaction['recipient_account_number'];
            $bind_recipient_iban = $transaction['recipient_iban']; // Use transaction's stored value
            $bind_recipient_swift_bic = $transaction['recipient_swift_bic']; // Use transaction's stored value
            $bind_recipient_sort_code = $transaction['recipient_sort_code']; // Use transaction's stored value
            $bind_recipient_external_account_number = $transaction['recipient_external_account_number']; // Use transaction's stored value
            $bind_recipient_user_id_for_sender_record = $transaction['recipient_user_id'];
            $bind_recipient_bank_name = $transaction['recipient_bank_name']; // Use transaction's stored value
            $bind_sender_name = $transaction['sender_name'];
            $bind_sender_account_number = $transaction['sender_account_number'];
            $bind_sender_user_id = $transaction['sender_user_id'];
            $bind_converted_amount_str = $credit_amount_str; // For incoming, converted is the amount received
            $bind_converted_currency = $credit_currency;
            $bind_exchange_rate = (string)($transaction['exchange_rate'] ?? 1.0); // Use transaction's stored rate
            $bind_external_bank_details = $transaction['external_bank_details']; // Use transaction's stored value

            $stmt_insert_rec_tx->bind_param("isssssssssssssisssisssd", // Adjusted types for string amounts (s)
                $bind_recipient_user_id, $bind_recipient_account_id, $bind_credit_amount_str, $bind_credit_currency,
                $bind_recipient_tx_type, $bind_description, $bind_status, $bind_initiated_at,
                $bind_transaction_reference_in, $bind_recipient_name, $bind_recipient_account_number,
                $bind_recipient_iban, $bind_recipient_swift_bic, $bind_recipient_sort_code,
                $bind_recipient_external_account_number, $bind_recipient_user_id_for_sender_record,
                $bind_recipient_bank_name, $bind_sender_name, $bind_sender_account_number,
                $bind_sender_user_id, $bind_converted_amount_str, $bind_converted_currency,
                $bind_exchange_rate, $bind_external_bank_details
            );
            if (!$stmt_insert_rec_tx->execute()) {
                throw new Exception("Failed to insert recipient transaction record for transaction {$transaction_id}: " . $stmt_insert_rec_tx->error);
            }
            $stmt_insert_rec_tx->close();

        } else {
            // External Transfer (Bank to Bank, ACH, Wire, International Wire)
            // For external transfers, no internal recipient account is credited.
            // The funds are assumed to be remitted to the external bank by the admin.
            // All necessary details are already in the `transactions` table.
            // No further internal financial actions are needed on internal accounts for completion.
        }

        // 3. Update the status of the original pending transaction to 'COMPLETED'
        $stmt_update_tx_status = $conn->prepare("UPDATE transactions SET status = 'COMPLETED', completed_at = NOW() WHERE id = ? AND status = 'PENDING'");
        if (!$stmt_update_tx_status) {
            throw new Exception("Failed to prepare transaction status update statement: " . $conn->error);
        }
        $stmt_update_tx_status->bind_param("i", $transaction_id);
        if (!$stmt_update_tx_status->execute()) {
            throw new Exception("Failed to update transaction status for transaction {$transaction_id}: " . $stmt_update_tx_status->error);
        }
        if ($conn->affected_rows === 0) {
            throw new Exception("Transaction status update failed for transaction {$transaction_id}. Transaction might no longer be PENDING or ID is incorrect.");
        }
        $stmt_update_tx_status->close();

        // If all operations succeed, commit the transaction
        $conn->commit();

        // --- Email Notification for Sender ---
        $sender_user = get_user_details($conn, $transaction['sender_user_id']);
        if ($sender_user && $sender_user['email']) {
            $subject = "Transaction Completed - Reference: {$transaction['transaction_reference']}";
            $body = '
                <div style="font-family: Arial, sans-serif; font-size: 14px; color: #333; background-color: #f8f8f8; padding: 20px;">
                    <table style="width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;">
                        <tr>
                            <td style="padding: 20px; text-align: center; background-color: #004d40; color: #ffffff;">
                                <h2 style="margin: 0; color: #ffffff; font-weight: normal;">' . htmlspecialchars(SMTP_FROM_NAME) . ' Notification</h2>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 30px;">
                                <p style="margin-bottom: 15px;">Dear ' . htmlspecialchars($sender_user['full_name']) . ',</p>
                                <p style="margin-bottom: 15px;">Your transaction has been <strong style="color: #28a745;">successfully completed</strong>.</p>
                                <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                                    <tr>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Transaction Reference:</strong></td>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . htmlspecialchars($transaction['transaction_reference']) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Amount:</strong></td>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . get_currency_symbol($transaction['currency']) . number_format((float)$transaction['amount'], 2) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Recipient:</strong></td>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . htmlspecialchars($transaction['recipient_name']) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Date & Time:</strong></td>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . date('Y-m-d H:i:s') . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 8px 0; color: #555;"><strong>Status:</strong></td>
                                        <td style="padding: 8px 0; text-align: right;"><strong style="color: #28a745;">COMPLETED</strong></td>
                                    </tr>
                                </table>
                                <p style="margin-bottom: 15px;">If you have any questions, please contact us.</p>
                                <p style="margin-top: 20px; font-size: 12px; color: #777;">Thank you for banking with ' . htmlspecialchars(SMTP_FROM_NAME) . '.</p>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 15px; text-align: center; font-size: 12px; color: #999; background-color: #f1f1f1; border-top: 1px solid #eee;">
                                &copy; ' . date("Y") . ' ' . htmlspecialchars(SMTP_FROM_NAME) . '. All rights reserved.
                            </td>
                        </tr>
                    </table>
                </div>
            ';
            sendEmail($sender_user['email'], $subject, $body);
        } else {
            error_log("Could not send completion email for transaction ID {$transaction_id}: Sender user details (email) not found.");
        }

        // --- Email Notification for Recipient (if internal transfer) ---
        if (strpos($transaction['transaction_type'], 'INTERNAL') !== false && $transaction['recipient_user_id']) {
            $recipient_user = get_user_details($conn, $transaction['recipient_user_id']);
            if ($recipient_user && $recipient_user['email']) {
                $subject = "Funds Received - Reference: {$transaction['transaction_reference']}";
                $body = '
                    <div style="font-family: Arial, sans-serif; font-size: 14px; color: #333; background-color: #f8f8f8; padding: 20px;">
                        <table style="width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;">
                            <tr>
                                <td style="padding: 20px; text-align: center; background-color: #004d40; color: #ffffff;">
                                    <h2 style="margin: 0; color: #ffffff; font-weight: normal;">' . htmlspecialchars(SMTP_FROM_NAME) . ' Notification</h2>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 30px;">
                                    <p style="margin-bottom: 15px;">Dear ' . htmlspecialchars($recipient_user['full_name']) . ',</p>
                                    <p style="margin-bottom: 15px;">You have received funds!</p>
                                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                                        <tr>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Transaction Reference:</strong></td>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . htmlspecialchars($bind_transaction_reference_in) . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Amount Received:</strong></td>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;"><strong style="color: #28a745;">' . get_currency_symbol($credit_currency) . number_format((float)$credit_amount_str, 2) . '</strong></td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>From:</strong></td>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . htmlspecialchars($transaction['sender_name']) . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Date & Time:</strong></td>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . date('Y-m-d H:i:s') . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 8px 0; color: #555;"><strong>Status:</strong></td>
                                            <td style="padding: 8px 0; text-align: right;"><strong style="color: #28a745;">COMPLETED</strong></td>
                                        </tr>
                                    </table>
                                    <p style="margin-bottom: 15px;">Your account balance has been updated accordingly.</p>
                                    <p style="margin-top: 20px; font-size: 12px; color: #777;">Thank you for banking with ' . htmlspecialchars(SMTP_FROM_NAME) . '.</p>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 15px; text-align: center; font-size: 12px; color: #999; background-color: #f1f1f1; border-top: 1px solid #eee;">
                                    &copy; ' . date("Y") . ' ' . htmlspecialchars(SMTP_FROM_NAME) . '. All rights reserved.
                                </td>
                            </tr>
                        </table>
                    </div>
                ';
                sendEmail($recipient_user['email'], $subject, $body);
            } else {
                error_log("Could not send completion email for transaction ID {$transaction_id}: Recipient user details (email) not found.");
            }
        }

        return ['success' => true, 'message' => "Transaction {$transaction['transaction_reference']} (ID: {$transaction_id}) completed successfully.", 'transaction_details' => $transaction_details];

    } catch (Exception $e) {
        // Rollback the transaction on any error
        if ($conn->in_transaction) {
            $conn->rollback();
        }
        // Log the error for debugging
        error_log("Failed to complete transaction ID {$transaction_id}: " . $e->getMessage());
        return ['success' => false, 'message' => "Transaction completion failed: " . $e->getMessage(), 'transaction_details' => null];
    }
}

/**
 * Rejects a pending transfer, crediting the original amount back to the sender's account.
 * This function is intended for admin use.
 *
 * @param mysqli $conn The mysqli database connection object.
 * @param int $transaction_id The ID of the pending transaction to reject.
 * @param string $reason Optional reason for rejection.
 * @return array An associative array with 'success' (bool) and 'message' (string), and 'transaction_details' (array|null).
 */
function reject_pending_transfer(mysqli $conn, int $transaction_id, string $reason = 'Rejected by Admin'): array {
    $conn->begin_transaction();
    $transaction_details = null; // To store details for email notification

    try {
        // 1. Fetch the pending transaction details
        $stmt = $conn->prepare("SELECT
            t.id, t.amount, t.currency, t.transaction_type, t.user_id AS sender_user_id,
            t.account_id AS sender_account_id, t.sender_account_number, t.transaction_reference,
            t.status, t.recipient_name, t.HometownBank_comment, t.sender_name
        FROM transactions t
        WHERE t.id = ? AND t.status = 'PENDING' FOR UPDATE"); // FOR UPDATE locks the row
        if (!$stmt) {
            throw new Exception("Failed to prepare rejection fetch statement: " . $conn->error);
        }
        $stmt->bind_param("i", $transaction_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $transaction = $result->fetch_assoc();
        $stmt->close();

        if (!$transaction) {
            return ['success' => false, 'message' => "Pending transaction not found or already processed for rejection.", 'transaction_details' => null];
        }
        $transaction_details = $transaction; // Store for return

        // 2. Credit the amount back to the sender's account
        $stmt_sender_acc = $conn->prepare("SELECT id, balance, currency FROM accounts WHERE id = ? AND user_id = ? AND status = 'active' FOR UPDATE");
        if (!$stmt_sender_acc) {
            throw new Exception("Failed to prepare sender account fetch for rejection: " . $conn->error);
        }
        $stmt_sender_acc->bind_param("ii", $transaction['sender_account_id'], $transaction['sender_user_id']);
        $stmt_sender_acc->execute();
        $result_sender_acc = $stmt_sender_acc->get_result();
        $sender_account = $result_sender_acc->fetch_assoc();
        $stmt_sender_acc->close();

        if (!$sender_account) {
            throw new Exception("Sender account not found or is inactive for transaction {$transaction_id}. Funds cannot be returned.");
        }

        // CRITICAL CHECK: Ensure sender's account currency matches the original transaction currency for refund
        if (strtoupper($sender_account['currency']) !== strtoupper($transaction['currency'])) {
            throw new Exception("Currency mismatch for refund during rejection. Expected " . $transaction['currency'] . ", got " . ($sender_account['currency'] ?? 'NULL/EMPTY') . " for transaction {$transaction_id}.");
        }

        $new_sender_balance_str = bcadd_precision((string)$sender_account['balance'], (string)$transaction['amount'], 2);

        $stmt_credit_sender = $conn->prepare("UPDATE accounts SET balance = ? WHERE id = ?");
        if (!$stmt_credit_sender) {
            throw new Exception("Failed to prepare sender account credit statement for rejection: " . $conn->error);
        }
        // Use 's' for string if storing DECIMAL in DB, or 'd' if PHP float/double
        $stmt_credit_sender->bind_param("si", $new_sender_balance_str, $sender_account['id']);
        if (!$stmt_credit_sender->execute()) {
            throw new Exception("Failed to credit sender account for rejection of transaction {$transaction_id}: " . $stmt_credit_sender->error);
        }
        if ($conn->affected_rows === 0) {
            throw new Exception("Sender account update failed during rejection for transaction {$transaction_id}.");
        }
        $stmt_credit_sender->close();

        // 3. Update the status of the original pending transaction to 'DECLINED'
        $stmt_update_tx_status = $conn->prepare("UPDATE transactions SET status = 'DECLINED', completed_at = NOW(), HometownBank_comment = ? WHERE id = ? AND status = 'PENDING'");
        if (!$stmt_update_tx_status) {
            throw new Exception("Failed to prepare transaction rejection status update: " . $conn->error);
        }
        $stmt_update_tx_status->bind_param("si", $reason, $transaction_id);
        if (!$stmt_update_tx_status->execute()) {
            throw new Exception("Failed to update transaction status to DECLINED for transaction {$transaction_id}: " . $stmt_update_tx_status->error);
        }
        if ($conn->affected_rows === 0) {
            throw new Exception("Transaction status update to DECLINED failed for transaction {$transaction_id}. Transaction might no longer be PENDING or ID is incorrect.");
        }
        $stmt_update_tx_status->close();

        $conn->commit();
        // Set new_status to 'DECLINED' for email notification, as that's what's set in the DB
        $transaction_details['status'] = 'DECLINED';
        $transaction_details['HometownBank_comment'] = $reason; // Corrected column name

        // --- Email Notification for Sender (Transaction Rejected) ---
        $sender_user = get_user_details($conn, $transaction['sender_user_id']);
        if ($sender_user && $sender_user['email']) {
            $subject = "Transaction Rejected - Reference: {$transaction['transaction_reference']}";
            $body = '
                <div style="font-family: Arial, sans-serif; font-size: 14px; color: #333; background-color: #f8f8f8; padding: 20px;">
                    <table style="width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;">
                        <tr>
                            <td style="padding: 20px; text-align: center; background-color: #dc3545; color: #ffffff;">
                                <h2 style="margin: 0; color: #ffffff; font-weight: normal;">' . htmlspecialchars(SMTP_FROM_NAME) . ' Notification</h2>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 30px;">
                                <p style="margin-bottom: 15px;">Dear ' . htmlspecialchars($sender_user['full_name']) . ',</p>
                                <p style="margin-bottom: 15px;">Your transaction has been <strong style="color: #dc3545;">rejected</strong> and the funds have been returned to your account.</p>
                                <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                                    <tr>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Transaction Reference:</strong></td>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . htmlspecialchars($transaction['transaction_reference']) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Amount Refunded:</strong></td>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;"><strong style="color: #28a745;">' . get_currency_symbol($transaction['currency']) . number_format((float)$transaction['amount'], 2) . '</strong></td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Recipient:</strong></td>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . htmlspecialchars($transaction['recipient_name']) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Rejection Reason:</strong></td>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . htmlspecialchars($reason) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 8px 0; color: #555;"><strong>Date & Time:</strong></td>
                                        <td style="padding: 8px 0; text-align: right;">' . date('Y-m-d H:i:s') . '</td>
                                    </tr>
                                </table>
                                <p style="margin-bottom: 15px;">If you have any questions, please contact us.</p>
                                <p style="margin-top: 20px; font-size: 12px; color: #777;">Thank you for banking with ' . htmlspecialchars(SMTP_FROM_NAME) . '.</p>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 15px; text-align: center; font-size: 12px; color: #999; background-color: #f1f1f1; border-top: 1px solid #eee;">
                                &copy; ' . date("Y") . ' ' . htmlspecialchars(SMTP_FROM_NAME) . '. All rights reserved.
                            </td>
                        </tr>
                    </table>
                </div>
            ';
            sendEmail($sender_user['email'], $subject, $body);
        } else {
            error_log("Could not send rejection email for transaction ID {$transaction_id}: Sender user details (email) not found.");
        }

        return ['success' => true, 'message' => "Transaction {$transaction['transaction_reference']} (ID: {$transaction_id}) successfully declined. Funds returned to sender.", 'transaction_details' => $transaction_details];

    } catch (Exception $e) {
        if ($conn->in_transaction) {
            $conn->rollback();
        }
        error_log("Failed to reject transaction ID {$transaction_id}: " . $e->getMessage());
        return ['success' => false, 'message' => "Transaction rejection failed: " . $e->getMessage(), 'transaction_details' => null];
    }
}