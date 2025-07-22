<?php
// admin/transfer_process.php
session_start();
require_once '../Config.php';
require_once '../functions.php'; // Assuming sendEmail is here
require_once 'PHPMailer/src/PHPMailer.php'; // Include PHPMailer if not in functions.php
require_once 'PHPMailer/src/SMTP.php';
require_once 'PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Ensure admin is logged in and has appropriate permissions
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php'); // Redirect to admin login
    exit;
}

$admin_id = $_SESSION['admin_id']; // Assuming admin ID is stored in session

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn === false) {
        // Log the error instead of dying in a production environment
        error_log("Database connection error: " . mysqli_connect_error());
        $_SESSION['admin_message'] = "ERROR: Could not connect to database.";
        $_SESSION['admin_message_type'] = "error";
        header('Location: transfer_approvals.php');
        exit;
    }

    $transaction_id = filter_var($_POST['transaction_id'] ?? '', FILTER_VALIDATE_INT);
    $action = trim($_POST['action']); // 'complete', 'restrict', 'deliver', 'fail'
    $reason = trim($_POST['reason'] ?? '');

    if (!$transaction_id) {
        $_SESSION['admin_message'] = "Invalid transaction ID.";
        $_SESSION['admin_message_type'] = "error";
        header('Location: transfer_approvals.php');
        exit;
    }

    mysqli_begin_transaction($conn);
    try {
        // Fetch transaction details
        $stmt_tx = $conn->prepare("SELECT * FROM transactions WHERE id = ? FOR UPDATE"); // Lock row
        if (!$stmt_tx) throw new Exception("Failed to prepare transaction fetch: " . $conn->error);
        $stmt_tx->bind_param("i", $transaction_id);
        $stmt_tx->execute();
        $result_tx = $stmt_tx->get_result();
        $transaction = $result_tx->fetch_assoc();
        $stmt_tx->close();

        if (!$transaction) {
            throw new Exception("Transaction not found.");
        }

        $new_status = 'pending'; // Default
        $user_email = '';
        $user_full_name = '';

        // Fetch user's email and name for notification
        $stmt_user = $conn->prepare("SELECT email, first_name, last_name FROM users WHERE id = ?");
        if (!$stmt_user) throw new Exception("Failed to prepare user fetch: " . $conn->error);
        $stmt_user->bind_param("i", $transaction['user_id']);
        $stmt_user->execute();
        $user_result = $stmt_user->get_result();
        if ($user_row = $user_result->fetch_assoc()) {
            $user_email = $user_row['email'];
            $user_full_name = trim($user_row['first_name'] . ' ' . $user_row['last_name']);
        }
        $stmt_user->close();


        switch ($action) {
            case 'complete':
                $new_status = 'completed';
                // For internal transfers, this is where the recipient account is credited
                if ($transaction['transaction_type'] === 'internal_self_transfer' || $transaction['transaction_type'] === 'internal_transfer') {
                    if ($transaction['recipient_user_id'] && $transaction['recipient_account_number']) {
                        // Get recipient account ID
                        $stmt_rec_acc = $conn->prepare("SELECT id FROM accounts WHERE user_id = ? AND account_number = ?");
                        if (!$stmt_rec_acc) throw new Exception("Failed to prepare recipient account fetch: " . $conn->error);
                        $stmt_rec_acc->bind_param("is", $transaction['recipient_user_id'], $transaction['recipient_account_number']);
                        $stmt_rec_acc->execute();
                        $rec_acc_result = $stmt_rec_acc->get_result();
                        $rec_acc_row = $rec_acc_result->fetch_assoc();
                        $stmt_rec_acc->close();

                        if ($rec_acc_row) {
                            $recipient_account_id = $rec_acc_row['id'];
                            $stmt_credit = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
                            if (!$stmt_credit) throw new Exception("Failed to prepare credit update: " . $conn->error);
                            $stmt_credit->bind_param("di", $transaction['amount'], $recipient_account_id);
                            if (!$stmt_credit->execute()) throw new Exception("Failed to credit recipient: " . $stmt_credit->error);
                            $stmt_credit->close();

                            // Insert a CREDIT transaction for the recipient
                            $recipient_credit_desc = "Transfer from " . htmlspecialchars($transaction['sender_name']) . " (" . htmlspecialchars($transaction['sender_account_number']) . "): " . htmlspecialchars($transaction['description']);
                            $recipient_tx_ref = 'CR-' . $transaction['transaction_reference']; // Derived from sender's ref

                            $stmt_rec_tx = $conn->prepare("INSERT INTO transactions (user_id, account_id, amount, transaction_type, description, status, initiated_at, currency, transaction_reference, sender_name, sender_account_number, sender_user_id, transaction_date, recipient_name, recipient_account_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)");
                            if (!$stmt_rec_tx) throw new Exception("Error preparing recipient transaction insert: " . $conn->error);
                            $rec_amount = $transaction['amount'];
                            $rec_user_id = $transaction['recipient_user_id'];
                            $rec_acc_num = $transaction['recipient_account_number'];
                            $stmt_rec_tx->bind_param("iidssssssssisss",
                                $rec_user_id, $recipient_account_id, $rec_amount, 'credit', $recipient_credit_desc,
                                'completed', date('Y-m-d H:i:s'), $transaction['currency'], $recipient_tx_ref,
                                $transaction['sender_name'], $transaction['sender_account_number'], $transaction['sender_user_id'],
                                $transaction['recipient_name'], $rec_acc_num
                            );
                            if (!$stmt_rec_tx->execute()) throw new Exception("Error inserting recipient transaction: " . $stmt_rec_tx->error);
                            $stmt_rec_tx->close();
                        } else {
                            throw new Exception("Recipient account not found for internal credit. Please ensure recipient's account number is correct.");
                        }
                    } else {
                        // This case implies an internal transfer without valid recipient_user_id or account_number
                        // which should ideally be prevented earlier, but good to catch.
                        throw new Exception("Recipient user ID or account number missing for internal transfer credit.");
                    }
                }
                // For external transfers, 'completed' means it's sent from our bank. 'delivered' comes later.
                break;
            case 'restricted':
            case 'failed':
                $new_status = $action;
                // For restricted/failed, consider refunding the money if it was deducted immediately.
                // This logic depends on your business rules. For now, assume it stays deducted and admin handles manually.
                break;
            case 'delivered':
                if ($transaction['transaction_type'] === 'internal_self_transfer' || $transaction['transaction_type'] === 'internal_transfer') {
                    throw new Exception("Status 'delivered' is only applicable to external transfers. Internal transfers are 'completed'.");
                }
                $new_status = 'delivered';
                break;
            default:
                throw new Exception("Invalid action.");
        }

        // Update transaction status
        $stmt_update_tx = $conn->prepare("UPDATE transactions SET status = ?, last_updated = CURRENT_TIMESTAMP WHERE id = ?");
        if (!$stmt_update_tx) throw new Exception("Failed to prepare transaction status update: " . $conn->error);
        $stmt_update_tx->bind_param("si", $new_status, $transaction_id);
        if (!$stmt_update_tx->execute()) throw new Exception("Failed to update transaction status: " . $stmt_update_tx->error);
        $stmt_update_tx->close();

        // Insert into transfer_approvals
        $stmt_approval = $conn->prepare("INSERT INTO transfer_approvals (transaction_id, admin_id, status, reason) VALUES (?, ?, ?, ?)");
        if (!$stmt_approval) throw new Exception("Failed to prepare approval record insert: " . $conn->error);
        $stmt_approval->bind_param("iiss", $transaction_id, $admin_id, $new_status, $reason);
        if (!$stmt_approval->execute()) throw new Exception("Failed to insert approval record: " . $stmt_approval->error);
        $stmt_approval->close();

        mysqli_commit($conn);

        // --- Start Email Notification Design ---
        $email_subject = "HomeTown Bank: Transfer Status Update - Ref: " . $transaction['transaction_reference'];

        // Prepare variables for email content, ensuring HTML special characters are escaped
        $transaction_ref_display = htmlspecialchars($transaction['transaction_reference']);
        $user_name_display = htmlspecialchars($user_full_name);
        $amount_display = htmlspecialchars($transaction['currency']) . " " . number_format($transaction['amount'], 2);
        $recipient_display = htmlspecialchars($transaction['recipient_name']);
        $new_status_display = htmlspecialchars(ucfirst($new_status)); // Capitalize first letter

        $reason_section = '';
        if (!empty($reason)) {
            $reason_section = '<p style="font-size: 14px; color: #555555; line-height: 1.6;"><strong>Reason/Comment:</strong> ' . htmlspecialchars($reason) . '</p>';
        }

        $status_color = '#004494'; // Default Heritage Blue
        if ($new_status == 'completed' || $new_status == 'delivered') {
            $status_color = '#28a745'; // Green for success
        } elseif ($new_status == 'restricted' || $new_status == 'failed') {
            $status_color = '#dc3545'; // Red for failure/restriction
        } elseif ($new_status == 'pending') {
            $status_color = '#ffc107'; // Yellow/Orange for pending (though admin processes shouldn't set to pending)
        }

        $email_body = '
        <div style="font-family: \'Roboto\', sans-serif; background-color: #f4f7f6; margin: 0; padding: 20px 0; color: #333333;">
            <table width="100%" border="0" cellspacing="0" cellpadding="0">
                <tr>
                    <td align="center" style="padding: 20px 0;">
                        <table width="600" border="0" cellspacing="0" cellpadding="0" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08); overflow: hidden;">
                            <tr>
                                <td align="center" style="background-color:rgb(224, 226, 230); padding: 25px 0 20px 0; border-top-left-radius: 8px; border-top-right-radius: 8px;">
                                    <img src="https://i.imgur.com/YmC3kg3.png" alt="Heritage Bank Logo" style="height: 50px; display: block; margin: 0 auto;">
                                    <h1 style="color: #ffffff; font-size: 28px; margin: 15px 0 0 0; font-weight: 700;">Transfer Status Update</h1>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 30px 40px; text-align: left;">
                                    <p style="font-size: 16px; color: #333333; margin-bottom: 20px;">Dear ' . $user_name_display . ',</p>
                                    <p style="font-size: 15px; color: #555555; line-height: 1.6; margin-bottom: 20px;">
                                        The status of your transfer request with reference <strong>' . $transaction_ref_display . '</strong> has been updated.
                                    </p>
                                    <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-bottom: 25px; border-collapse: collapse;">
                                        <tr>
                                            <td style="padding: 8px 0; font-size: 15px; color: #333333; width: 40%; font-weight: bold;">Amount:</td>
                                            <td style="padding: 8px 0; font-size: 15px; color: #004494; width: 60%; font-weight: bold;">' . $amount_display . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 8px 0; font-size: 15px; color: #333333; font-weight: bold;">Recipient:</td>
                                            <td style="padding: 8px 0; font-size: 15px; color: #555555;">' . $recipient_display . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 8px 0; font-size: 15px; color: #333333; font-weight: bold;">New Status:</td>
                                            <td style="padding: 8px 0; font-size: 15px; color: ' . $status_color . '; font-weight: bold;">' . $new_status_display . '</td>
                                        </tr>
                                    </table>'
                                    . $reason_section . '
                                    <p style="font-size: 15px; color: #555555; line-height: 1.6; margin-top: 30px;">
                                        If you have any questions, please do not hesitate to contact our customer support.
                                    </p>
                                    <p style="font-size: 15px; color: #333333; margin-top: 20px;">Thank you for banking with HomeTwon Bank.</p>
                                    <p style="font-size: 15px; color: #333333; font-weight: bold; margin-top: 5px;">The HomeTown Bank Team</p>
                                </td>
                            </tr>
                            <tr>
                                <td align="center" style="background-color: #f8f8f8; padding: 20px 40px; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px; font-size: 12px; color: #777777;">
                                    <p>&copy; ' . date('Y') . ' HomeTown  Bank Pa. All rights reserved.</p>
                                    <p>This is an automated email, please do not reply.</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </div>
        ';

        sendEmail($user_email, $email_subject, $email_body);
        // --- End Email Notification Design ---

        $_SESSION['admin_message'] = "Transfer (Ref: " . htmlspecialchars($transaction['transaction_reference']) . ") status updated to " . ucfirst($new_status) . ".";
        $_SESSION['admin_message_type'] = "success";

    } catch (Exception $e) {
        mysqli_rollback($conn);
        // Log the error for debugging
        error_log("Transfer processing error: " . $e->getMessage());
        $_SESSION['admin_message'] = "Error processing transfer: " . $e->getMessage();
        $_SESSION['admin_message_type'] = "error";
    } finally {
        // Ensure connection is closed even if an error occurs outside try-catch
        if ($conn) {
            $conn->close();
        }
    }

    header('Location: transfer_approvals.php'); // Redirect back to admin approval list
    exit;
}

// If accessed directly without POST, redirect
header('Location: transfer_approvals.php');
exit;