<?php
// PHP error reporting - MUST be at the very top
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Adjust paths as necessary for your file structure
// These paths are relative to the current file (account_status_management.php)
require_once '../../Config.php';
require_once '../../functions.php'; // This file is expected to contain the sendEmail function

// Check if the admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ../index.php'); // Redirect to admin login page
    exit; // Stop script execution after redirect
}

$message = '';
$message_type = '';
$user_to_manage = null; // This variable will store the fetched user's details

// --- Database Connection ---
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn === false) {
    // Log the error for administrator review (check your XAMPP Apache error logs)
    error_log("Database connection failed in account_status_management.php: " . mysqli_connect_error());
    // Display a user-friendly error message and terminate
    die("ERROR: We are currently experiencing technical difficulties. Please try again later.");
}

// Define allowed statuses - Adjusted order as per your request!
$allowed_statuses = ['active', 'blocked', 'closed', 'restricted', 'suspended'];


// --- Fetch User Details (GET or POST request) ---
// Safely retrieve user_id from GET (URL) or POST (form submission)
$target_user_id = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
if (!$target_user_id) { // If not found in GET, check POST
    $target_user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
}

if ($target_user_id) {
    // Prepare a safe SQL query using prepared statements
    $stmt = mysqli_prepare($conn, "SELECT id, first_name, last_name, email, account_status FROM users WHERE id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $target_user_id); // 'i' for integer
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt); // Get the result set
        if ($result && mysqli_num_rows($result) > 0) {
            $user_to_manage = mysqli_fetch_assoc($result); // Fetch the user data
        } else {
            // User not found with the provided ID
            $message = 'User not found with the provided ID: ' . htmlspecialchars($target_user_id);
            $message_type = 'error';
        }
        mysqli_stmt_close($stmt); // Close the statement
    } else {
        // Error preparing the statement
        $message = "Error preparing user details query: " . mysqli_error($conn);
        $message_type = 'error';
        error_log("SQL Prepare Error (account_status_management.php - fetch user): " . mysqli_error($conn));
    }
}

// --- Handle POST Request for Status Change ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_status'])) {
    // Re-validate and sanitize inputs from POST
    $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $new_status = trim(filter_input(INPUT_POST, 'new_status', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
    $reason = trim(filter_input(INPUT_POST, 'reason', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
    $admin_user_id = $_SESSION['admin_id'] ?? null; // Get admin ID from session
    // $admin_email = $_SESSION['admin_email'] ?? 'Unknown Admin'; // For logging if needed, not directly used in DB operation

    $custom_change_date_str = trim(filter_input(INPUT_POST, 'change_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS));

    // Input validation
    if (!$user_id || !$user_to_manage || $user_id !== $user_to_manage['id']) {
        $message = 'User not specified or not found for status update. Please ensure the user ID is valid.';
        $message_type = 'error';
    } elseif (!in_array(strtolower($new_status), $allowed_statuses)) {
        $message = 'Invalid account status selected.';
        $message_type = 'error';
    } elseif (empty($reason)) {
        $message = 'Reason for status change is required.';
        $message_type = 'error';
    } elseif (empty($custom_change_date_str)) {
        $message = 'Change Date/Time is required.';
        $message_type = 'error';
    } elseif (empty($admin_user_id)) {
        $message = 'Admin ID not found in session. Please ensure you are properly logged in.';
        $message_type = 'error';
        error_log("Security Alert: Admin ID missing from session during status change for user ID " . ($user_id ?? 'N/A'));
    } else {
        // Validate and format the custom_change_date for MySQL
        try {
            $change_datetime = new DateTime($custom_change_date_str);
            $mysql_formatted_date = $change_datetime->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            $message = 'Invalid Change Date/Time format provided. Please use a valid date and time.';
            $message_type = 'error';
        }

        // Only proceed with database operations if no validation errors so far
        if (empty($message)) {
            mysqli_begin_transaction($conn); // Start transaction for atomicity
            try {
                // IMPORTANT: Re-fetch user's *current* status just before updating
                // This prevents race conditions if another admin changed the status concurrently
                $stmt_fetch_current_status = mysqli_prepare($conn, "SELECT account_status FROM users WHERE id = ? FOR UPDATE"); // FOR UPDATE locks the row
                if (!$stmt_fetch_current_status) {
                    throw new Exception("Failed to prepare current status fetch: " . mysqli_error($conn));
                }
                mysqli_stmt_bind_param($stmt_fetch_current_status, "i", $user_id);
                mysqli_stmt_execute($stmt_fetch_current_status);
                $result_current_status = mysqli_stmt_get_result($stmt_fetch_current_status);
                if (!$result_current_status || mysqli_num_rows($result_current_status) === 0) {
                    throw new Exception("User not found or no current status available for update (ID: $user_id).");
                }
                $current_user_data = mysqli_fetch_assoc($result_current_status);
                $old_status = $current_user_data['account_status'];
                mysqli_stmt_close($stmt_fetch_current_status);

                // Check if the new status is different from the old status
                if (strtolower($old_status) === strtolower($new_status)) {
                    $message = "Account status is already '" . htmlspecialchars(ucfirst($new_status)) . "'. No change was made.";
                    $message_type = 'info';
                    mysqli_rollback($conn); // No actual change, so rollback the transaction
                } else {
                    // 1. Update user's account_status in the 'users' table
                    $stmt_update = mysqli_prepare($conn, "UPDATE users SET account_status = ? WHERE id = ?");
                    if (!$stmt_update) {
                        throw new Exception("Update statement preparation failed: " . mysqli_error($conn));
                    }
                    mysqli_stmt_bind_param($stmt_update, "si", $new_status, $user_id); // 's' for string, 'i' for integer
                    if (!mysqli_stmt_execute($stmt_update)) {
                        throw new Exception("Failed to update user status in 'users' table: " . mysqli_error($conn));
                    }
                    mysqli_stmt_close($stmt_update);

                    // 2. Insert into 'account_status_history' table for logging
                    $stmt_history = mysqli_prepare($conn, "INSERT INTO account_status_history (user_id, old_status, new_status, reason, changed_by_user_id, changed_at) VALUES (?, ?, ?, ?, ?, ?)");
                    if (!$stmt_history) {
                        error_log("History table INSERT prepare failed: " . mysqli_error($conn));
                        throw new Exception("Failed to prepare status history log. Database schema issue? " . mysqli_error($conn));
                    }
                    mysqli_stmt_bind_param($stmt_history, "isssis", $user_id, $old_status, $new_status, $reason, $admin_user_id, $mysql_formatted_date);
                    if (!mysqli_stmt_execute($stmt_history)) {
                        error_log("History table INSERT execute failed: " . mysqli_error($conn));
                        throw new Exception("Failed to log status change in 'account_status_history' table: " . mysqli_error($conn));
                    }
                    mysqli_stmt_close($stmt_history);

                    mysqli_commit($conn); // Commit the transaction if all operations were successful
                    $message = "Account status for " . htmlspecialchars($user_to_manage['first_name'] . ' ' . $user_to_manage['last_name']) . " successfully changed from '" . htmlspecialchars(ucfirst($old_status)) . "' to '" . htmlspecialchars(ucfirst($new_status)) . "'.";
                    $message_type = 'success';

                    // Update $user_to_manage with new status for immediate display on the page
                    $user_to_manage['account_status'] = $new_status;

                    // --- Email Notification to User ---
                    $status_color = '';
                    switch (strtolower($new_status)) {
                        case 'active': $status_color = '#28a745'; break; // Green
                        case 'suspended': $status_color = '#ffc107'; break; // Yellow/Orange
                        case 'blocked': $status_color = '#dc3545'; break; // Red
                        case 'restricted': $status_color = '#17a2b8'; break; // Teal/Cyan
                        case 'closed': $status_color = '#6c757d'; break; // Grey
                        default: $status_color = '#333333'; break; // Default dark grey
                    }

                    $user_subject = "Important: Your Heritage Bank Account Status Has Been Updated";
                    // IMPORTANT: Replace 'YOUR_BANK_LOGO_URL_HERE' with an actual, publicly accessible URL to your bank logo
                    $user_email_body = '
                    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.6; color: #333333; background-color: #f4f4f4;">
                        <tr>
                            <td align="center" style="padding: 20px 0;">
                                <table border="0" cellpadding="0" cellspacing="0" width="600" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
                                    <tr>
                                        <td align="center" style="padding: 20px 0; background-color:#E0E6EB;">
                                            <img src="https://i.imgur.com/YEFKZlG.png" alt="Heritage Bank Logo" style="display: block; max-width: 120px; height: auto; margin: 0 auto; padding: 5px;">
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 30px 40px;">
                                            <p style="font-size: 16px; font-weight: bold; color: #004A7F; margin-bottom: 20px;">Dear ' . htmlspecialchars($user_to_manage['first_name']) . ',</p>
                                            <p style="margin-bottom: 20px;">We wish to inform you that the status of your Heritage Bank account has been updated.</p>

                                            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom: 25px; border: 1px solid #dddddd; border-collapse: collapse;">
                                                <tr>
                                                    <td style="padding: 12px 15px; background-color: #f9f9f9; width: 40%; font-weight: bold; border-bottom: 1px solid #eeeeee;">Old Status:</td>
                                                    <td style="padding: 12px 15px; background-color: #f9f9f9; border-bottom: 1px solid #eeeeee;"><strong>' . ucfirst(htmlspecialchars($old_status)) . '</strong></td>
                                                </tr>
                                                <tr>
                                                    <td style="padding: 12px 15px; width: 40%; font-weight: bold; border-bottom: 1px solid #eeeeee;">New Status:</td>
                                                    <td style="padding: 12px 15px; border-bottom: 1px solid #eeeeee;"><strong style="color: ' . $status_color . ';">' . ucfirst(htmlspecialchars($new_status)) . '</strong></td>
                                                </tr>
                                                <tr>
                                                    <td style="padding: 12px 15px; background-color: #f9f9f9; width: 40%; font-weight: bold; border-bottom: 1px solid #eeeeee;">Reason for Change:</td>
                                                    <td style="padding: 12px 15px; background-color: #f9f9f9; border-bottom: 1px solid #eeeeee;"><strong>' . nl2br(htmlspecialchars($reason)) . '</strong></td>
                                                </tr>
                                                <tr>
                                                    <td style="padding: 12px 15px; width: 40%; font-weight: bold;">Date of Change:</td>
                                                    <td style="padding: 12px 15px;"><strong>' . $change_datetime->format('M d, Y H:i:s') . '</strong></td>
                                                </tr>
                                            </table>

                                            <p style="margin-top: 20px;">If you have any questions or require further clarification, please do not hesitate to contact our customer support team.</p>
                                            <p style="margin-top: 20px; font-weight: bold;">Sincerely,<br>Heritage Bank Administration</p>

                                            <p style="font-size: 11px; color: #888888; text-align: center; margin-top: 40px; border-top: 1px solid #eeeeee; padding-top: 20px;">
                                                This is an automated email, please do not reply.
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td align="center" style="padding: 20px; background-color: #004A7F; color: #ffffff; font-size: 12px; border-radius: 0 0 8px 8px;">
                                            &copy; ' . date("Y") . ' Heritage Bank. All rights reserved.
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                    ';

                    // Since sendEmail function should now be available, we always attempt to use it
                    $email_sent = sendEmail($user_to_manage['email'], $user_subject, $user_email_body);
                    if ($email_sent) {
                        $message .= " Email notification sent to user.";
                    } else {
                        $message .= " Failed to send email notification. Check error logs for details (likely an SMTP issue in functions.php).";
                        $message_type = 'warning'; // Set to warning if DB update was successful but email failed
                        error_log("Email sending failed for user " . $user_to_manage['email'] . " (Account Status Update)");
                    }
                }
            } catch (Exception $e) {
                mysqli_rollback($conn); // Rollback the transaction on any error
                $message = "Failed to change account status: " . $e->getMessage();
                $message_type = 'error';
                error_log("Transaction Error (account_status_management.php): " . $e->getMessage());
            }
        }
    }
}

// Close database connection
mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heritage Bank - Manage User Account Status</title>
    <link rel="stylesheet" href="../style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        /* Your existing CSS styles for status display */
        .status-active { color: #28a745; font-weight: bold; }
        .status-suspended { color: #ffc107; font-weight: bold; }
        .status-blocked { color: #dc3545; font-weight: bold; }
        .status-restricted { color: #17a2b8; font-weight: bold; }
        .status-closed { color: #6c757d; font-weight: bold; }

        /* Message box styles */
        .message {
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-weight: bold;
        }
        .message.success {
            color: #155724;
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            color: #721c24;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
        }
        .message.warning {
            color: #856404;
            background-color: #fff3cd;
            border: 1px solid #ffeeba;
        }
        .message.info { /* Added for informational messages */
            color: #0c5460;
            background-color: #d1ecf1;
            border: 1px solid #bee5eb;
        }

        .user-details-summary {
            background-color: #f8f9fa;
            border: 1px solid #e2e6ea;
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 25px;
        }
        .user-details-summary h3 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #343a40;
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 5px;
        }
        .user-details-summary p {
            margin-bottom: 5px;
            color: #495057;
        }

        /* Form specific styles */
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #343a40;
        }
        .form-group select,
        .form-group textarea,
        .form-group input[type="datetime-local"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            box-sizing: border-box; /* Ensures padding/border don't increase total width */
        }
        .form-group textarea {
            resize: vertical; /* Allows vertical resizing */
            min-height: 80px;
        }
        .btn-primary {
            background-color: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }
        .btn-primary:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>
    <?php // include '../admin_header.php'; // Assuming your admin header file is here ?>

    <div class="container">
        <h2>Manage User Account Status</h2>

        <?php if (!empty($message)): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($user_to_manage): // If a user was successfully fetched ?>
            <div class="user-details-summary">
                <h3>User Details</h3>
                <p><strong>Name:</strong> <?php echo htmlspecialchars($user_to_manage['first_name'] . ' ' . $user_to_manage['last_name']); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($user_to_manage['email']); ?></p>
                <p><strong>Current Status:</strong> <span class="status-<?php echo strtolower(htmlspecialchars($user_to_manage['account_status'])); ?>"><?php echo htmlspecialchars(ucfirst($user_to_manage['account_status'])); ?></span></p>
            </div>

            <form action="account_status_management.php" method="POST">
                <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user_to_manage['id']); ?>">

                <div class="form-group">
                    <label for="new_status">Change Status To:</label>
                    <select id="new_status" name="new_status" required>
                        <option value="">-- Select New Status --</option>
                        <?php
                        // Populate options dynamically from the allowed_statuses array
                        foreach ($allowed_statuses as $status) {
                            // Select the current status by default
                            $selected = (strtolower($user_to_manage['account_status']) === $status) ? 'selected' : '';
                            echo '<option value="' . htmlspecialchars($status) . '" ' . $selected . '>' . htmlspecialchars(ucfirst($status)) . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="reason">Reason for Change:</label>
                    <textarea id="reason" name="reason" rows="4" placeholder="e.g., 'Account flagged for suspicious activity', 'User requested account closure'" required><?php echo isset($_POST['reason']) ? htmlspecialchars($_POST['reason']) : ''; ?></textarea>
                </div>

                <div class="form-group">
                    <label for="change_date">Date and Time of Change:</label>
                    <input type="datetime-local" id="change_date" name="change_date" required value="<?php echo date('Y-m-d\TH:i'); ?>">
                    <small>Set this to the actual date and time the status change is being logged.</small>
                </div>

                <div class="form-group">
                    <button type="submit" name="change_status" class="btn-primary">Update Account Status</button>
                </div>
            </form>
            <p><a href="manage_users.php" class="back-link">&larr; Back to Manage Users</a></p>

        <?php else: // If no user ID was provided or user not found ?>
            <p class="message info">Please select a user to manage their account status from the <a href="manage_users.php">User Management</a> page.</p>
        <?php endif; ?>
    </div>

    <?php // include '../admin_footer.php'; // Assuming your admin footer file is here ?>
</body>
</html>