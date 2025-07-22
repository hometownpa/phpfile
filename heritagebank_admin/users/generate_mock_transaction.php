<?php
session_start();
require_once '../../Config.php';

// Check if the admin is NOT logged in, redirect to login page
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ../../index.php');
    exit;
}

$message = '';
$message_type = '';
$user_currency_symbol = '€'; // Default to Euro symbol for display in form if no user selected initially

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn === false) {
    die("ERROR: Could not connect to database. " . mysqli_connect_error());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_identifier = trim($_POST['user_identifier'] ?? '');
    $start_date_str = trim($_POST['start_date'] ?? '');
    $end_date_str = trim($_POST['end_date'] ?? '');
    $max_amount_per_transaction = floatval($_POST['max_amount_per_transaction'] ?? 0);
    $min_transactions_per_day = intval($_POST['min_transactions_per_day'] ?? 0);
    $max_transactions_per_day = intval($_POST['max_transactions_per_day'] ?? 0);

    // Basic validation
    if (empty($user_identifier) || empty($start_date_str) || empty($end_date_str) ||
        $max_amount_per_transaction <= 0 || $min_transactions_per_day <= 0 ||
        $max_transactions_per_day < $min_transactions_per_day) {
        $message = 'All fields are required. Dates must be valid, amounts positive, and transaction counts correctly set.';
        $message_type = 'error';
    } else {
        try {
            $start_date = new DateTime($start_date_str);
            $end_date = new DateTime($end_date_str);

            if ($start_date > $end_date) {
                throw new Exception("Start date cannot be after end date.");
            }

            // Find the user and their first name, last name, and currency from 'users' table
            $user_id = null;
            $user_name = '';
            $user_currency_code = ''; // To store the fetched currency code

            // MODIFIED: Removed 'balance' from SELECT, as we will use account balance instead
            $stmt = mysqli_prepare($conn, "SELECT id, first_name, last_name, currency FROM users WHERE email = ? OR membership_number = ?");
            if (!$stmt) {
                throw new Exception("User lookup statement prep failed: " . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt, "ss", $user_identifier, $user_identifier);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($result)) {
                $user_id = $row['id'];
                $user_name = $row['first_name'] . ' ' . $row['last_name'];
                $user_currency_code = strtoupper($row['currency'] ?? 'EUR'); // Default to EUR if not set in DB
            }
            mysqli_stmt_close($stmt);

            if (!$user_id) {
                throw new Exception('User not found with the provided identifier.');
            }

            // --- START CODE TO FETCH ACCOUNT_ID AND ITS BALANCE ---
            $user_account_id = null;
            $current_account_balance = 0; // This will hold the running balance of the selected account

            // MODIFIED: Select 'balance' from the 'accounts' table for the selected account
            $stmt_account = mysqli_prepare($conn, "SELECT id, balance FROM accounts WHERE user_id = ? LIMIT 1");
            if (!$stmt_account) {
                throw new Exception("Account lookup statement prep failed: " . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt_account, "i", $user_id);
            mysqli_stmt_execute($stmt_account);
            $result_account = mysqli_stmt_get_result($stmt_account);
            if ($account_row = mysqli_fetch_assoc($result_account)) {
                $user_account_id = $account_row['id'];
                $current_account_balance = $account_row['balance']; // Initialize with the account's current balance
            }
            mysqli_stmt_close($stmt_account);

            if (!$user_account_id) {
                throw new Exception('No account found for the provided user. Please ensure the user has at least one account in the "accounts" table.');
            }
            // --- END CODE TO FETCH ACCOUNT_ID AND ITS BALANCE ---

            // Set the currency symbol for display based on fetched code (now only handles EUR/GBP)
            switch ($user_currency_code) {
                case 'GBP': $user_currency_symbol = '£'; break;
                case 'EUR':
                default: $user_currency_symbol = '€'; break; // Default to Euro if it's not GBP or unrecognized
            }

            mysqli_begin_transaction($conn); // Start transaction for all operations in this batch

            $successful_transactions = 0;
            $failed_transactions = 0;

            $credit_descriptions = [
                'Online Deposit', 'Transfer In', 'Salary', 'Investment Gain', 'Cash Deposit', 'Refund', 'Loan Disbursement'
            ];
            $debit_descriptions = [
                'Online Purchase', 'Groceries', 'Utility Bill', 'ATM Withdrawal', 'Transfer Out', 'Subscription Fee', 'Restaurant Bill', 'POS Payment'
            ];

            $interval = new DateInterval('P1D'); // 1 Day interval
            $period = new DatePeriod($start_date, $interval, $end_date->modify('+1 day')); // Include end date

            foreach ($period as $date) {
                $num_transactions_today = mt_rand($min_transactions_per_day, $max_transactions_per_day);

                for ($i = 0; $i < $num_transactions_today; $i++) {
                    $amount = round(mt_rand(100, $max_amount_per_transaction * 100) / 100, 2); // Random amount between 1 and max
                    $type = (mt_rand(0, 1) === 0) ? 'credit' : 'debit'; // Randomly choose credit or debit

                    $description_list = ($type === 'credit') ? $credit_descriptions : $debit_descriptions;
                    $description = $description_list[array_rand($description_list)];

                    $transaction_status = 'Completed';

                    // Generate a random time for the transaction within the day
                    $random_hour = str_pad(mt_rand(0, 23), 2, '0', STR_PAD_LEFT);
                    $random_minute = str_pad(mt_rand(0, 59), 2, '0', STR_PAD_LEFT);
                    $random_second = str_pad(mt_rand(0, 59), 2, '0', STR_PAD_LEFT);
                    $transaction_datetime = $date->format('Y-m-d') . " {$random_hour}:{$random_minute}:{$random_second}";

                    // --- NEW: Generate a unique transaction reference ---
                    $transaction_reference = 'TRX-' . $user_id . '-' . $date->format('Ymd') . $random_hour . $random_minute . $random_second . '-' . substr(uniqid(), -5);

                    if ($type === 'debit') {
                        // MODIFIED: Check against account's current balance
                        if ($current_account_balance < $amount) {
                            $transaction_status = 'Failed - Insufficient Funds';
                            // Balance does not change for failed debits
                        } else {
                            // MODIFIED: Deduct from the running account balance
                            $current_account_balance -= $amount;
                        }
                    } else { // credit
                        // MODIFIED: Add to the running account balance
                        $current_account_balance += $amount;
                    }

                    // --- MODIFIED INSERT STATEMENT: ADDED 'transaction_reference' ---
                    $stmt_trans = mysqli_prepare($conn, "INSERT INTO transactions (user_id, account_id, amount, transaction_type, description, status, transaction_date, currency, transaction_reference) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if (!$stmt_trans) {
                        error_log("Transaction insertion statement prep failed: " . mysqli_error($conn)); // Log error
                        throw new Exception("Transaction insertion statement prep failed: " . mysqli_error($conn));
                    }
                    // --- MODIFIED BIND PARAMETERS: ADDED '$transaction_reference' (type 's') ---
                    mysqli_stmt_bind_param($stmt_trans, "iiissssss", $user_id, $user_account_id, $amount, $type, $description, $transaction_status, $transaction_datetime, $user_currency_code, $transaction_reference);
                    if (!mysqli_stmt_execute($stmt_trans)) {
                        error_log("Failed transaction insertion for user " . $user_id . ": " . mysqli_error($conn)); // Log error
                        throw new Exception("Transaction insertion failed for user " . $user_id . ": " . mysqli_error($conn));
                    }
                    mysqli_stmt_close($stmt_trans);

                    // --- NEW: Update the balance in the 'accounts' table ---
                    if ($transaction_status === 'Completed') {
                        $stmt_update_account = mysqli_prepare($conn, "UPDATE accounts SET balance = ? WHERE id = ?");
                        if (!$stmt_update_account) {
                            error_log("Account balance update statement prep failed: " . mysqli_error($conn));
                            throw new Exception("Account balance update statement prep failed: " . mysqli_error($conn));
                        }
                        mysqli_stmt_bind_param($stmt_update_account, "di", $current_account_balance, $user_account_id);
                        if (!mysqli_stmt_execute($stmt_update_account)) {
                            error_log("Failed to update account balance for account " . $user_account_id . ": " . mysqli_error($conn));
                            throw new Exception("Account balance update failed: " . mysqli_error($conn));
                        }
                        mysqli_stmt_close($stmt_update_account);
                        $successful_transactions++;
                    } else {
                        $failed_transactions++;
                    }
                }
            }

            // After all transactions for the period, update the user's final balance in the 'users' table
            // This balance in 'users' table will now reflect the final balance of the single primary account selected.
            $stmt_balance = mysqli_prepare($conn, "UPDATE users SET balance = ? WHERE id = ?");
            if (!$stmt_balance) {
                throw new Exception("Balance update statement prep failed: " . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt_balance, "di", $current_account_balance, $user_id); // Using $current_account_balance
            if (!mysqli_stmt_execute($stmt_balance)) {
                throw new Exception("Final balance update failed for user " . $user_id . ": " . mysqli_error($conn));
            }
            mysqli_stmt_close($stmt_balance);

            mysqli_commit($conn); // Commit all operations

            // Determine balance display color and sign for the success message
            $balance_display = $user_currency_symbol . number_format($current_account_balance, 2);
            $balance_color_style = '';
            if ($current_account_balance > 0) {
                $balance_display = '+' . $balance_display; // Add plus sign for positive balance
                $balance_color_style = 'color: green;';
            } elseif ($current_account_balance < 0) {
                $balance_display = '-' . $balance_display; // Add minus sign for negative balance
                $balance_color_style = 'color: red;';
            }
            // If balance is 0, no sign, default color

            $message = "Generated " . $successful_transactions . " successful transactions and " . $failed_transactions . " failed transactions for " . htmlspecialchars($user_name) . ". New balance: <span style='" . $balance_color_style . " font-weight: bold;'>" . $balance_display . "</span>";
            $message_type = 'success';

            // Clear form fields after successful generation
            $_POST = array(); // Clear post data to reset form values

        } catch (Exception $e) {
            mysqli_rollback($conn); // Rollback if any error occurred
            $message = "Transaction generation failed: " . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// If form was not submitted or on error, try to pre-fetch user currency if identifier is present
// This block ensures the currency symbol is correctly displayed even on initial load or validation errors.
$display_user_currency_symbol = '€'; // Default symbol for form display
if (isset($_POST['user_identifier']) && !empty($_POST['user_identifier'])) {
    $temp_identifier = trim($_POST['user_identifier']);
    // Check if $conn is still open, if not, try to reconnect for this specific lookup
    if (!$conn || !$conn->ping()) {
        $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn === false) {
             error_log("ERROR: Could not reconnect to database for currency lookup.");
        }
    }

    if ($conn) { // Ensure connection is established before preparing statement
        $stmt_currency_lookup = mysqli_prepare($conn, "SELECT currency FROM users WHERE email = ? OR membership_number = ?");
        if ($stmt_currency_lookup) {
            mysqli_stmt_bind_param($stmt_currency_lookup, "ss", $temp_identifier, $temp_identifier);
            mysqli_stmt_execute($stmt_currency_lookup);
            $result_currency = mysqli_stmt_get_result($stmt_currency_lookup);
            if ($row_currency = mysqli_fetch_assoc($result_currency)) {
                $fetched_currency_code = strtoupper($row_currency['currency'] ?? 'EUR');
                switch ($fetched_currency_code) {
                    case 'GBP': $display_user_currency_symbol = '£'; break;
                    case 'EUR':
                    default: $display_user_currency_symbol = '€'; break;
                }
            }
            mysqli_stmt_close($stmt_currency_lookup);
        }
    }
} else {
    // If no identifier is submitted, default to EUR for the form placeholder
    $display_user_currency_symbol = '€';
}

// Ensure connection is closed even if not explicitly in POST block
// Only close if the connection is valid and not already closed
if ($conn && $conn->ping()) {
    mysqli_close($conn);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heritage Bank - Generate Mock Transaction</title>
    <link rel="stylesheet" href="../style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        /* Additional styling for messages */
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
        /* Style for the "Back to User Management" link */
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #007bff;
            text-decoration: none;
            font-weight: bold;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        /* Basic form styling for better appearance */
        .form-standard {
            background-color: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }

        .form-group input[type="text"],
        .form-group input[type="date"],
        .form-group input[type="number"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box; /* Include padding in width */
        }

        .form-group small {
            color: #666;
            font-size: 0.85em;
        }

        .button-primary {
            background-color: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            width: auto; /* Adjust width to content */
            display: inline-block; /* Allows text-align center in parent if needed */
        }

        .button-primary:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <img src="../../images/logo.png" alt="Heritage Bank Logo" class="logo">
            <h2>Generate Mock Transactions</h2>
            <a href="../logout.php" class="logout-button">Logout</a>
        </div>

        <div class="dashboard-content">
            <?php if (!empty($message)): ?>
                <p class="message <?php echo $message_type; ?>"><?php echo $message; /* $message already contains HTML */ ?></p>
            <?php endif; ?>

            <form action="generate_mock_transaction.php" method="POST" class="form-standard">
                <div class="form-group">
                    <label for="user_identifier">User Email or Membership Number</label>
                    <input type="text" id="user_identifier" name="user_identifier" value="<?php echo htmlspecialchars($_POST['user_identifier'] ?? ''); ?>" placeholder="e.g., user@example.com or MEM12345678" required>
                    <small>Enter the user's email or membership number.</small>
                </div>

                <div class="form-group">
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($_POST['start_date'] ?? date('Y-m-01')); ?>" required>
                </div>

                <div class="form-group">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($_POST['end_date'] ?? date('Y-m-t')); ?>" required>
                </div>

                <div class="form-group">
                    <label for="max_amount_per_transaction">Max Amount Per Transaction (<?php echo $display_user_currency_symbol; ?>)</label>
                    <input type="number" id="max_amount_per_transaction" name="max_amount_per_transaction" step="0.01" min="1.00" value="<?php echo htmlspecialchars($_POST['max_amount_per_transaction'] ?? '1000.00'); ?>" required>
                    <small>The maximum amount for any single generated transaction in the user's currency.</small>
                </div>

                <div class="form-group">
                    <label for="min_transactions_per_day">Min Transactions Per Day</label>
                    <input type="number" id="min_transactions_per_day" name="min_transactions_per_day" min="1" value="<?php echo htmlspecialchars($_POST['min_transactions_per_day'] ?? '1'); ?>" required>
                </div>

                <div class="form-group">
                    <label for="max_transactions_per_day">Max Transactions Per Day</label>
                    <input type="number" id="max_transactions_per_day" name="max_transactions_per_day" min="1" value="<?php echo htmlspecialchars($_POST['max_transactions_per_day'] ?? '5'); ?>" required>
                    <small>Sets the range for how many transactions are generated each day.</small>
                </div>

                <button type="submit" class="button-primary">Generate Transactions</button>
            </form>

            <p><a href="users_management.php" class="back-link">&larr; Back to User Management</a></p>
        </div>
    </div>
    <script src="../script.js"></script>
</body>
</html>