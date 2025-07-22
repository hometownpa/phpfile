<?php
session_start();
require_once '../../Config.php'; // Adjust path based on your actual file structure

// Check if the admin is NOT logged in, redirect to login page
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ../index.php'); // Corrected redirect to admin login page
    exit;
}

$message = '';
$message_type = ''; // Will be 'success', 'error', 'credit', or 'debit' for styling

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn === false) {
    error_log("Database connection error: " . mysqli_connect_error());
    die("ERROR: Could not connect to database. Please try again later.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $account_number_input = trim($_POST['account_number'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $operation_type = $_POST['operation_type'] ?? ''; // 'credit' or 'debit'
    $admin_description = trim($_POST['admin_description'] ?? '');

    // Input validation
    if (empty($account_number_input) || $amount <= 0 || !in_array($operation_type, ['credit', 'debit'])) {
        $message = 'Please provide a valid account number, a positive amount, and select an operation type.';
        $message_type = 'error';
    } else {
        $current_balance = 0;
        $account_id = null;
        $user_id = null; // <--- NEW: Initialize user_id
        $user_email = '';
        $user_name = '';

        // Start transaction to ensure atomicity
        mysqli_begin_transaction($conn);

        try {
            // Find the account by account number and get its current balance, associated user email/name, AND user_id
            $stmt = mysqli_prepare($conn, "SELECT a.id, a.balance, u.id AS user_id_from_db, u.email, u.first_name, u.last_name FROM accounts a JOIN users u ON a.user_id = u.id WHERE a.account_number = ? FOR UPDATE"); // Added FOR UPDATE to lock the row
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "s", $account_number_input);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                if ($row = mysqli_fetch_assoc($result)) {
                    $account_id = $row['id'];
                    $current_balance = $row['balance'];
                    $user_id = $row['user_id_from_db']; // <--- NEW: Store the fetched user_id
                    $user_email = $row['email'];
                    $user_name = trim($row['first_name'] . ' ' . $row['last_name']);
                }
                mysqli_stmt_close($stmt);
            }

            if (!$account_id || !$user_id) { // <--- MODIFIED: Also check if user_id was found
                $message = 'Account not found or associated user not found with the provided account number.';
                $message_type = 'error';
                mysqli_rollback($conn);
            } else {
                $new_balance = $current_balance;
                $transaction_type_label = '';
                $transaction_description = empty($admin_description) ? ($operation_type === 'credit' ? 'Admin credit' : 'Admin debit') : $admin_description;

                if ($operation_type === 'credit') {
                    $new_balance += $amount;
                    $transaction_type_label = 'credited';
                } else { // debit
                    if ($current_balance < $amount) {
                        $message = 'Insufficient funds in account ' . htmlspecialchars($account_number_input) . ' for debit operation.';
                        $message_type = 'error';
                        mysqli_rollback($conn);
                    } else {
                        $new_balance -= $amount;
                        $transaction_type_label = 'debited';
                    }
                }

                if ($message_type !== 'error') {
                    // Update the balance in the accounts table
                    $update_stmt = mysqli_prepare($conn, "UPDATE accounts SET balance = ? WHERE id = ?");
                    if ($update_stmt) {
                        mysqli_stmt_bind_param($update_stmt, "di", $new_balance, $account_id);
                        if (mysqli_stmt_execute($update_stmt)) {
                            // Insert a transaction record
                            // <--- MODIFIED: Added user_id to the columns and parameters
                            $insert_transaction_stmt = mysqli_prepare($conn, "INSERT INTO transactions (account_id, user_id, type, amount, description, current_balance, transaction_date) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                            if ($insert_transaction_stmt) {
                                // <--- MODIFIED: Added 'i' for user_id and $user_id parameter
                                mysqli_stmt_bind_param($insert_transaction_stmt, "iisdsd", $account_id, $user_id, $operation_type, $amount, $transaction_description, $new_balance);
                                if (mysqli_stmt_execute($insert_transaction_stmt)) {
                                    mysqli_commit($conn);
                                    $message = "Account " . htmlspecialchars($account_number_input) . " (" . htmlspecialchars($user_name) . " - {$user_email}) successfully {$transaction_type_label} with " . number_format($amount, 2) . ". New balance: " . number_format($new_balance, 2);
                                    $message_type = $operation_type;
                                    $_POST = array();
                                } else {
                                    $message = "Error recording transaction for account " . htmlspecialchars($account_number_input) . ": " . mysqli_error($conn);
                                    $message_type = 'error';
                                    mysqli_rollback($conn);
                                }
                                mysqli_stmt_close($insert_transaction_stmt);
                            } else {
                                $message = "Transaction record query preparation failed: " . mysqli_error($conn);
                                $message_type = 'error';
                                mysqli_rollback($conn);
                            }
                        } else {
                            $message = "Error updating balance for account " . htmlspecialchars($account_number_input) . ": " . mysqli_error($conn);
                            $message_type = 'error';
                            mysqli_rollback($conn);
                        }
                        mysqli_stmt_close($update_stmt);
                    } else {
                        $message = "Database update query preparation failed: " . mysqli_error($conn);
                        $message_type = 'error';
                        mysqli_rollback($conn);
                    }
                }
            }
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $message = "An unexpected error occurred: " . $e->getMessage();
            $message_type = 'error';
            error_log("Manage User Funds Error: " . $e->getMessage());
        }
    }
}

mysqli_close($conn);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heritage Bank - Manage User Funds</title>
    <link rel="stylesheet" href="../style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        /* Specific styles for fund management messages */
        .message {
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-weight: bold;
        }
        .message.credit {
            color: #1a7d30; /* Darker Green */
            background-color: #d4edda; /* Lighter Green */
            border-color: #a3daab;
        }
        .message.debit {
            color: #8c0000; /* Darker Red */
            background-color: #f8d7da; /* Lighter Red */
            border-color: #f5c6cb;
        }
        .message.error {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }
        .message.success { /* For other non-credit/debit successes, though 'credit' and 'debit' are more specific here */
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <img src="../../images/logo.png" alt="Heritage Bank Logo" class="logo">
            <h2>Manage User Funds</h2>
            <a href="../logout.php" class="logout-button">Logout</a>
        </div>

        <div class="dashboard-content">
            <?php if (!empty($message)): ?>
                <p class="message <?php echo $message_type; ?>">
                    <?php
                        if ($message_type === 'credit') {
                            echo '<span style="font-size: 1.2em;">&#x2713; + </span> ' . htmlspecialchars($message);
                        } elseif ($message_type === 'debit') {
                            echo '<span style="font-size: 1.2em;">&#x2717; - </span> ' . htmlspecialchars($message);
                        } else {
                            echo htmlspecialchars($message);
                        }
                    ?>
                </p>
            <?php endif; ?>

            <form action="manage_user_funds.php" method="POST" class="form-standard">
                <div class="form-group">
                    <label for="account_number">User Account Number</label>
                    <input type="text" id="account_number" name="account_number" value="<?php echo htmlspecialchars($_POST['account_number'] ?? ''); ?>" placeholder="e.g., CHK00123456 or SAV00123456" required>
                    <small>Enter the exact account number to credit or debit.</small>
                </div>
                <div class="form-group">
                    <label for="amount">Amount</label>
                    <input type="number" id="amount" name="amount" step="0.01" min="0.01" value="<?php echo htmlspecialchars($_POST['amount'] ?? '0.00'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="operation_type">Operation Type</label>
                    <select id="operation_type" name="operation_type" required>
                        <option value="">-- Select --</option>
                        <option value="credit" <?php echo (($_POST['operation_type'] ?? '') == 'credit') ? 'selected' : ''; ?>>Credit (Add Funds)</option>
                        <option value="debit" <?php echo (($_POST['operation_type'] ?? '') == 'debit') ? 'selected' : ''; ?>>Debit (Remove Funds)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="admin_description">Transaction Description (Optional)</label>
                    <input type="text" id="admin_description" name="admin_description" value="<?php echo htmlspecialchars($_POST['admin_description'] ?? ''); ?>" placeholder="e.g., Salary deposit, Withdrawal at ATM">
                    <small>This description will appear in the user's transaction history.</small>
                </div>
                <button type="submit" class="button-primary">Process Funds</button>
            </form>

            <p><a href="manage_users.php" class="back-link">&larr; Back to Manage Users</a></p>
        </div>
    </div>
    <script src="../script.js"></script>
</body>
</html>