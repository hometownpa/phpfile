<?php
session_start();
require_once '../Config.php'; // Essential for DB_HOST, DB_USER, etc.
require_once '../functions.php'; // Include if you have general utility functions here (e.g., sanitize_input)

// Check if the user is logged in. If not, redirect to login page.
// We check for both 'user_logged_in' (as seen in your dashboard.php) and 'user_id'.
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch username, first_name, last_name from session (set during login)
$username = $_SESSION['username'] ?? 'User'; // Fallback if username not set in session
$first_name = $_SESSION['first_name'] ?? '';
$last_name = $_SESSION['last_name'] ?? '';

// Generate full name for display
$full_name = trim($first_name . ' ' . $last_name);
if (empty($full_name)) {
    $full_name = $username;
}

// --- DATABASE CONNECTION ---
// This connects to the database using the credentials from your Config.php.
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn === false) {
    die("ERROR: Could not connect to database. " . mysqli_connect_error());
}
// --- END DATABASE CONNECTION ---


$user_transactions = []; // Initialize array to store fetched transactions
$user_currency_symbol = '€'; // Default for display, will be updated based on user's account currency

// Fetch user's primary account currency (assuming one main currency per user/account for display)
// It's good practice to fetch the actual account's currency if available for display.
$stmt_currency = $conn->prepare("SELECT currency FROM accounts WHERE user_id = ? LIMIT 1");
if ($stmt_currency) {
    $stmt_currency->bind_param("i", $user_id);
    $stmt_currency->execute();
    $result_currency = $stmt_currency->get_result();
    if ($currency_row = $result_currency->fetch_assoc()) {
        $user_currency_code = strtoupper($currency_row['currency'] ?? 'EUR');
        switch ($user_currency_code) {
            case 'GBP': $user_currency_symbol = '£'; break;
            case 'USD': $user_currency_symbol = '$'; break; // Added USD
            case 'EUR':
            default: $user_currency_symbol = '€'; break;
        }
    }
    $stmt_currency->close();
}


// Fetch user's transactions from the database
// We fetch more details now for a richer display
$sql = "SELECT initiated_at, description, amount, transaction_type, status, transaction_reference
        FROM transactions
        WHERE user_id = ? OR recipient_user_id = ? -- Include transactions where user is recipient
        ORDER BY initiated_at DESC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    // Bind user_id twice for the OR condition
    $stmt->bind_param("ii", $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $user_transactions[] = $row;
        }
    }
    $stmt->close();
} else {
    error_log("Error preparing transaction fetch statement in statements.php: " . $conn->error);
}

$conn->close(); // Close the database connection when done

// Organize transactions into 'statements' by month/year for display
// Now, instead of just periods, we'll group the actual transactions.
$grouped_transactions = [];
foreach ($user_transactions as $transaction) {
    $date = new DateTime($transaction['initiated_at']); // Use initiated_at for grouping
    $period = $date->format('F Y'); // e.g., "July 2025"

    if (!isset($grouped_transactions[$period])) {
        $grouped_transactions[$period] = [];
    }
    $grouped_transactions[$period][] = $transaction;
}

// Ensure the periods are sorted from most recent to oldest
uksort($grouped_transactions, function($a, $b) {
    return strtotime($b) - strtotime($a);
});

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statements - Heritage Bank</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* General styling for the main content area */
        .statements-content {
            padding: 20px;
            background-color: #f4f7f6;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-left: 260px; /* Adjust based on sidebar width */
            padding-top: 80px; /* Space for header */
            min-height: calc(100vh - 60px); /* Adjust based on header height */
        }

        .statements-card {
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }

        .statements-card h2 {
            color: #333;
            margin-bottom: 25px;
            font-size: 1.8em;
            border-bottom: 2px solid #0056b3;
            padding-bottom: 10px;
        }

        /* Styling for monthly grouping */
        .month-group {
            margin-bottom: 30px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
            background-color: #fbfbfb;
        }

        .month-group h3 {
            background-color: #007bff; /* Primary blue */
            color: white;
            padding: 15px 20px;
            margin: 0;
            font-size: 1.4em;
            border-bottom: 1px solid #0056b3;
            cursor: pointer; /* Indicate it's clickable for toggle */
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .month-group h3 .toggle-icon {
            transition: transform 0.3s ease;
        }

        .month-group h3.collapsed .toggle-icon {
            transform: rotate(-90deg); /* Icon points down when collapsed */
        }

        .transactions-list {
            list-style: none;
            padding: 0;
            margin: 0;
            max-height: 0; /* Hidden by default */
            overflow: hidden;
            transition: max-height 0.5s ease-out; /* Smooth collapse/expand */
        }

        .transactions-list.expanded {
            max-height: 1000px; /* Large enough to show all items, adjust if needed */
        }

        .transaction-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
        }

        .transaction-item:last-child {
            border-bottom: none;
        }

        .transaction-item-details {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }

        .transaction-item-details .description {
            font-weight: bold;
            color: #333;
            font-size: 1.1em;
        }

        .transaction-item-details .date-ref {
            font-size: 0.9em;
            color: #777;
            margin-top: 3px;
        }

        .transaction-item-amount {
            font-size: 1.2em;
            font-weight: bold;
            text-align: right;
            min-width: 120px; /* Ensure space for amounts */
        }

        /* Applying specific colors based on transaction type for amounts */
        .transaction-item-amount.credit,
        .transaction-item-amount.deposit { /* Added deposit for clarity */
            color: #28a745; /* Green for credit/deposit */
        }

        .transaction-item-amount.debit,
        .transaction-item-amount.withdrawal, /* Added withdrawal for clarity */
        .transaction-item-amount.transfer,
        .transaction-item-amount.internal_self_transfer,
        .transaction-item-amount.internal_heritage,
        .transaction-item-amount.external_iban,
        .transaction-item-amount.external_sort_code {
            color: #dc3545; /* Red for debit and all transfer types */
        }

        .transaction-item-status {
            font-size: 0.85em;
            margin-left: 15px;
            padding: 5px 8px;
            border-radius: 4px;
            color: white;
        }

        .transaction-item-status.Completed {
            background-color: #28a745;
        }
        .transaction-item-status.Pending { /* Added Pending status style */
            background-color: #ffc107; /* Yellow/Orange */
            color: #343a40; /* Darker text for contrast */
        }
        .transaction-item-status.Failed,
        .transaction-item-status.Cancelled { /* Added Failed/Cancelled status style */
            background-color: #dc3545;
        }


        .no-transactions-message {
            text-align: center;
            color: #666;
            padding: 40px 20px;
            background-color: #f9f9f9;
            border-radius: 5px;
            border: 1px dashed #ddd;
            margin-top: 20px;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .statements-content {
                margin-left: 0; /* Sidebar collapses */
                padding: 15px;
            }

            .statements-card {
                padding: 15px;
            }

            .month-group h3 {
                font-size: 1.2em;
            }

            .transaction-item {
                flex-direction: column;
                align-items: flex-start;
            }

            .transaction-item-amount {
                margin-top: 10px;
                text-align: left;
                width: 100%;
            }

            .transaction-item-status {
                margin-left: 0;
                margin-top: 10px;
            }
        }
    </style>
</head>
<body class="statements-page">
    <header class="dashboard-header">
        <div class="logo">
            <img src="../images/logo.png" alt="Heritage Bank Logo" class="logo-barclays">
        </div>
        <div class="user-info">
            <i class="fa-solid fa-user profile-icon"></i>
            <span><?php echo htmlspecialchars($full_name); ?></span>
            <a href="logout.php">Logout</a>
        </div>
    </header>

    <div class="dashboard-container">
        <aside class="sidebar">
            <ul>
                <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                <li><a href="profile.php"><i class="fas fa-user-circle"></i> <span>Profile</span></a></li>
                <li class="active"><a href="statements.php"><i class="fas fa-file-alt"></i> <span>Statements</span></a></li>
                <li><a href="transfer.php"><i class="fas fa-exchange-alt"></i> <span>Transfers</span></a></li>
                <li><a href="transactions.php"><i class="fas fa-history"></i> <span>Transaction History</span></a></li>
                <li><a href="#"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </aside>

        <main class="statements-content">
            <div class="statements-card">
                <h2>Your Transaction History & Statements</h2>

                <?php if (empty($grouped_transactions)): ?>
                    <p class="no-transactions-message">No transactions available at this time. Please check back later.</p>
                <?php else: ?>
                    <?php foreach ($grouped_transactions as $period => $transactions): ?>
                        <div class="month-group">
                            <h3 class="collapsed" onclick="toggleTransactions(this)">
                                <?php echo htmlspecialchars($period); ?> Transactions
                                <i class="fas fa-chevron-down toggle-icon"></i>
                            </h3>
                            <ul class="transactions-list">
                                <?php foreach ($transactions as $transaction):
                                    // Determine the amount class and sign based on transaction type
                                    $amount_class = '';
                                    $amount_sign = '';
                                    if (in_array($transaction['transaction_type'], ['credit', 'deposit'])) {
                                        $amount_class = 'credit';
                                        $amount_sign = '+';
                                    } else {
                                        // All other types (debit, transfer, withdrawal, etc.) are considered outgoing/debit for display purposes here
                                        $amount_class = 'debit';
                                        $amount_sign = '-';
                                    }
                                ?>
                                    <li class="transaction-item">
                                        <div class="transaction-item-details">
                                            <span class="description"><?php echo htmlspecialchars($transaction['description']); ?></span>
                                            <span class="date-ref">
                                                <?php echo (new DateTime($transaction['initiated_at']))->format('M d, Y H:i'); ?>
                                                (Ref: <?php echo htmlspecialchars($transaction['transaction_reference']); ?>)
                                            </span>
                                        </div>
                                        <span class="transaction-item-amount <?php echo $amount_class; ?>">
                                            <?php echo $amount_sign . $user_currency_symbol . number_format($transaction['amount'], 2); ?>
                                        </span>
                                        <span class="transaction-item-status <?php echo htmlspecialchars(ucfirst($transaction['status'])); ?>">
                                            <?php echo htmlspecialchars(ucfirst($transaction['status'])); ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>


            </div>
        </main>
    </div>

    <script>
        // JavaScript for toggling monthly transaction lists
        function toggleTransactions(header) {
            const list = header.nextElementSibling; // The <ul> element
            const icon = header.querySelector('.toggle-icon');

            header.classList.toggle('collapsed');
            list.classList.toggle('expanded');
            icon.classList.toggle('fa-chevron-down');
            icon.classList.toggle('fa-chevron-up'); // Change icon direction
        }

        // Expand the most recent month by default on page load
        document.addEventListener('DOMContentLoaded', (event) => {
            const firstMonthGroupHeader = document.querySelector('.month-group h3');
            if (firstMonthGroupHeader) {
                // Ensure it's not already expanded if it was a cached page state
                if (firstMonthGroupHeader.classList.contains('collapsed')) {
                    toggleTransactions(firstMonthGroupHeader);
                }
            }
        });
    </script>
</body>
</html>