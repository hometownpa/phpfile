<?php
session_start();
require_once '../Config.php'; // Your database configuration
require_once '../functions.php'; // If you have a sanitize_input function here

// Check if the user is logged in. If not, redirect to login page.
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$full_name = ''; // Will be fetched from DB

// Establish database connection
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn === false) {
    die("ERROR: Could not connect to database. " . mysqli_connect_error());
}

// Fetch user's name for display in header
$stmt_user = $conn->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
if (!$stmt_user) {
    die("Error preparing user data statement: " . $conn->error);
}
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$result_user = $stmt_user->get_result();
if ($user_row = $result_user->fetch_assoc()) {
    $full_name = trim($user_row['first_name'] . ' ' . $user_row['last_name']);
} else {
    // Fallback if user data not found, though session check should prevent this
    $full_name = $_SESSION['username'] ?? 'User';
}
$stmt_user->close();

// --- Transaction Filtering and Pagination (Optional but Recommended) ---
$records_per_page = 10;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_type = isset($_GET['type']) ? trim($_GET['type']) : '';
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

$where_clauses = ["(t.user_id = ? OR t.recipient_user_id = ?)"]; // User is either sender or recipient
$params = [$user_id, $user_id];
$param_types = "ii"; // For the two user_id parameters

if (!empty($search_query)) {
    $where_clauses[] = "(t.description LIKE ? OR t.transaction_reference LIKE ? OR t.recipient_name LIKE ? OR t.sender_name LIKE ?)";
    $search_param = '%' . $search_query . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= "ssss";
}

if (!empty($filter_type)) {
    $where_clauses[] = "t.transaction_type = ?";
    $params[] = $filter_type;
    $param_types .= "s";
}

if (!empty($filter_status)) {
    $where_clauses[] = "t.status = ?";
    $params[] = $filter_status;
    $param_types .= "s";
}

if (!empty($start_date)) {
    $where_clauses[] = "t.initiated_at >= ?";
    $params[] = $start_date . ' 00:00:00'; // Start of the day
    $param_types .= "s";
}

if (!empty($end_date)) {
    $where_clauses[] = "t.initiated_at <= ?";
    $params[] = $end_date . ' 23:59:59'; // End of the day
    $param_types .= "s";
}

$where_sql = count($where_clauses) > 0 ? " WHERE " . implode(" AND ", $where_clauses) : "";


// Count total transactions for pagination
$count_sql = "SELECT COUNT(*) FROM transactions t" . $where_sql;
$stmt_count = $conn->prepare($count_sql);
if (!$stmt_count) {
    die("Error preparing count statement: " . $conn->error);
}
// Dynamically bind parameters for count query
if (count($params) > 0) {
    $stmt_count->bind_param($param_types, ...$params);
}
$stmt_count->execute();
$stmt_count->bind_result($total_transactions);
$stmt_count->fetch();
$stmt_count->close();

$total_pages = ceil($total_transactions / $records_per_page);


// Fetch transactions
$transactions = [];
$sql = "SELECT t.*,
                ua.account_number AS user_account_num, ua.currency AS user_account_currency,
                ra.account_number AS recipient_account_num_linked, ra.currency AS recipient_account_currency_linked
        FROM transactions t
        LEFT JOIN accounts ua ON t.account_id = ua.id AND t.user_id = ?
        LEFT JOIN accounts ra ON t.recipient_account_number = ra.account_number AND t.recipient_user_id = ?
        " . $where_sql . "
        ORDER BY t.initiated_at DESC
        LIMIT ? OFFSET ?";

// For the main query, we need to add the user_id params for the LEFT JOINs
$main_params = array_merge([$user_id, $user_id], $params, [$records_per_page, $offset]);
$main_param_types = "ii" . $param_types . "ii"; // Adjust for the two additional user_id params and limit/offset

$stmt_transactions = $conn->prepare($sql);
if (!$stmt_transactions) {
    die("Error preparing transactions statement: " . $conn->error);
}

// Dynamically bind parameters
$stmt_transactions->bind_param($main_param_types, ...$main_params);

$stmt_transactions->execute();
$result_transactions = $stmt_transactions->get_result();
while ($row = $result_transactions->fetch_assoc()) {
    $transactions[] = $row;
}
$stmt_transactions->close();

$conn->close();

// Helper to format currency
function formatCurrency($amount, $currency_code) {
    $symbol = '';
    switch (strtoupper($currency_code)) {
        case 'GBP': $symbol = '£'; break;
        case 'USD': $symbol = '$'; break;
        case 'EUR':
        default: $symbol = '€'; break;
    }
    return $symbol . number_format($amount, 2);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction History - Heritage Bank</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Add specific styles for the transaction history page if needed */
        .transaction-history-content {
            padding: 20px;
        }
        .filter-form {
            background-color: #f0f2f5;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }
        .filter-form .form-group {
            margin-bottom: 0; /* Remove default form-group margin */
        }
        .filter-form label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }
        .filter-form input[type="text"],
        .filter-form input[type="date"],
        .filter-form select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box; /* Ensures padding doesn't increase width */
        }
        .filter-form button {
            padding: 10px 15px;
            background-color: #0056b3;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1em;
        }
        .filter-form button:hover {
            background-color: #004494;
        }

        /* --- Responsive Table Styles --- */
        .table-responsive {
            overflow-x: auto; /* Enables horizontal scrolling */
            -webkit-overflow-scrolling: touch; /* Improves scrolling on iOS */
            margin-top: 15px;
        }

        .transactions-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 400px; /* Ensures the table doesn't get too cramped on smaller screens, forcing scroll */
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .transactions-table th, .transactions-table td {
            border: 1px solid #e0e0e0;
            padding: 12px 15px;
            text-align: left;
            vertical-align: top; /* Align content to the top */
        }
        .transactions-table th {
            background-color: #0056b3;
            color: white;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 0.9em;
            white-space: nowrap; /* Prevent headers from wrapping */
        }
        .transactions-table tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .transactions-table tbody tr:hover {
            background-color: #f1f1f1;
        }
        .status-completed { color: green; font-weight: bold; }
        .status-pending { color: orange; font-weight: bold; }
        .status-failed, .status-cancelled { color: red; font-weight: bold; }

        /* --- Custom CSS for Amount Colors --- */
        .text-success {
            color: green;
        }

        .text-danger {
            color: red;
        }
        /* --- End Custom CSS for Amount Colors --- */

        /* Mobile specific adjustments for table cells */
        @media (max-width: 768px) {
            .transactions-table th,
            .transactions-table td {
                padding: 8px 10px; /* Reduce padding on smaller screens */
                font-size: 0.85em; /* Slightly smaller font size */
            }

            /* Hide less critical columns on small screens */
            .transactions-table th:nth-child(5), /* Reference */
            .transactions-table td:nth-child(5),
            .transactions-table th:nth-child(7), /* Details */
            .transactions-table td:nth-child(7) {
                display: none;
            }

            /* For 'Amount' column, ensure currency symbol and amount are on new lines for clarity */
            .transactions-table td:nth-child(4) small {
                display: block; /* Force currency symbol to new line */
                font-size: 0.8em;
                margin-top: 2px;
            }
        }

        @media (max-width: 480px) {
            /* Further hide columns for very small screens if necessary, or just rely on scroll */
            .transactions-table th:nth-child(2), /* Type */
            .transactions-table td:nth-child(2) {
                display: none;
            }
        }


        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            gap: 5px;
            flex-wrap: wrap; /* Allow pagination items to wrap on small screens */
        }
        .pagination a, .pagination span {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #0056b3;
            background-color: white;
        }
        .pagination a:hover {
            background-color: #e0e0e0;
        }
        .pagination .current-page {
            background-color: #0056b3;
            color: white;
            border-color: #0056b3;
        }
        .no-transactions {
            text-align: center;
            padding: 30px;
            color: #777;
            font-style: italic;
        }
    </style>
</head>
<body class="dashboard-page">
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
                <li><a href="statements.php"><i class="fas fa-file-alt"></i> <span>Statements</span></a></li>
                <li><a href="transfer.php"><i class="fas fa-exchange-alt"></i> <span>Transfers</span></a></li>
                <li class="active"><a href="transactions.php"><i class="fas fa-history"></i> <span>Transaction History</span></a></li>
                <li><a href="#"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </aside>

        <main class="transaction-history-content">
            <div class="card">
                <h2>Your Transaction History</h2>

                <form method="GET" action="transactions.php" class="filter-form">
                    <div class="form-group">
                        <label for="search">Search Description/Reference:</label>
                        <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="e.g., electricity bill">
                    </div>
                    <div class="form-group">
                        <label for="type">Transaction Type:</label>
                        <select id="type" name="type">
                            <option value="">All Types</option>
                            <option value="debit" <?php echo ($filter_type == 'debit') ? 'selected' : ''; ?>>Debit</option>
                            <option value="credit" <?php echo ($filter_type == 'credit') ? 'selected' : ''; ?>>Credit</option>
                            <option value="transfer" <?php echo ($filter_type == 'transfer') ? 'selected' : ''; ?>>General Transfer</option>
                            <option value="internal_self_transfer" <?php echo ($filter_type == 'internal_self_transfer') ? 'selected' : ''; ?>>Internal Self Transfer</option>
                            <option value="internal_heritage" <?php echo ($filter_type == 'internal_heritage') ? 'selected' : ''; ?>>Heritage Internal Transfer</option>
                            <option value="external_iban" <?php echo ($filter_type == 'external_iban') ? 'selected' : ''; ?>>International (IBAN)</option>
                            <option value="external_sort_code" <?php echo ($filter_type == 'external_sort_code') ? 'selected' : ''; ?>>UK Sort Code</option>
                            <option value="deposit" <?php echo ($filter_type == 'deposit') ? 'selected' : ''; ?>>Deposit</option>
                            <option value="withdrawal" <?php echo ($filter_type == 'withdrawal') ? 'selected' : ''; ?>>Withdrawal</option>
                            </select>
                    </div>
                    <div class="form-group">
                        <label for="status">Status:</label>
                        <select id="status" name="status">
                            <option value="">All Statuses</option>
                            <option value="completed" <?php echo ($filter_status == 'completed') ? 'selected' : ''; ?>>Completed</option>
                            <option value="pending" <?php echo ($filter_status == 'pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="failed" <?php echo ($filter_status == 'failed') ? 'selected' : ''; ?>>Failed</option>
                            <option value="cancelled" <?php echo ($filter_status == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="start_date">From Date:</label>
                        <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div class="form-group">
                        <label for="end_date">To Date:</label>
                        <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                    <div class="form-group">
                        <button type="submit">Apply Filters</button>
                    </div>
                </form>

                <?php if (!empty($transactions)): ?>
                    <div class="table-responsive">
                        <table class="transactions-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Description</th>
                                    <th>Amount</th>
                                    <th>Reference</th>
                                    <th>Status</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $transaction):
                                    // Determine if it's an incoming or outgoing transaction for display
                                    $is_incoming = ($transaction['recipient_user_id'] == $user_id && $transaction['user_id'] != $user_id);
                                    $display_amount = ($is_incoming || $transaction['transaction_type'] == 'credit' || strpos($transaction['transaction_type'], 'deposit') !== false) ? '+' : '-';

                                    // Determine the currency to display based on the user's perspective
                                    $display_currency = $transaction['currency']; // Default to transaction currency

                                    // If the transaction is from the user, use the sender's account currency
                                    if ($transaction['user_id'] == $user_id) {
                                        $display_currency = $transaction['user_account_currency'] ?? $transaction['currency'];
                                    }
                                    // If the transaction is to the user, use the recipient's account currency
                                    elseif ($transaction['recipient_user_id'] == $user_id) {
                                        $display_currency = $transaction['recipient_account_currency_linked'] ?? $transaction['currency'];
                                    }

                                    $display_amount .= formatCurrency($transaction['amount'], $display_currency);
                                    // This is the key line: assigning 'text-success' or 'text-danger' class
                                    $amount_class = ($is_incoming || $transaction['transaction_type'] == 'credit' || strpos($transaction['transaction_type'], 'deposit') !== false) ? 'text-success' : 'text-danger';

                                    // Construct description based on transaction type and sender/recipient
                                    $display_description = htmlspecialchars($transaction['description']);
                                    $transaction_details = [];

                                    if ($transaction['transaction_type'] === 'internal_self_transfer') {
                                            // For self transfers, show "From Account X to Account Y"
                                            $display_description = "Transfer from " . htmlspecialchars($transaction['sender_account_number'] ?? 'N/A') . " to " . htmlspecialchars($transaction['recipient_account_number'] ?? 'N/A') . ". " . $display_description;
                                    } elseif ($transaction['user_id'] == $user_id) { // This user is the sender
                                        $transaction_details[] = "From Acc: " . htmlspecialchars($transaction['user_account_num'] ?? 'N/A');
                                        if (!empty($transaction['recipient_name'])) {
                                            $transaction_details[] = "To: " . htmlspecialchars($transaction['recipient_name']);
                                        }
                                        if (!empty($transaction['recipient_account_number'])) {
                                            $transaction_details[] = "Acc No: " . htmlspecialchars($transaction['recipient_account_number']);
                                        } elseif (!empty($transaction['recipient_iban'])) {
                                            $transaction_details[] = "IBAN: " . htmlspecialchars($transaction['recipient_iban'] ?? '');
                                        } elseif (!empty($transaction['recipient_sort_code'])) {
                                            $transaction_details[] = "Sort Code: " . htmlspecialchars($transaction['recipient_sort_code'] ?? '');
                                            $transaction_details[] = "Ext Acc No: " . htmlspecialchars($transaction['recipient_external_account_number'] ?? '');
                                        }
                                        if (!empty($transaction['recipient_bank_name'])) {
                                            $transaction_details[] = "Bank: " . htmlspecialchars($transaction['recipient_bank_name'] ?? '');
                                        }
                                    } elseif ($transaction['recipient_user_id'] == $user_id) { // This user is the recipient
                                        $transaction_details[] = "To Acc: " . htmlspecialchars($transaction['recipient_account_num_linked'] ?? 'N/A');
                                        if (!empty($transaction['sender_name'])) {
                                            $transaction_details[] = "From: " . htmlspecialchars($transaction['sender_name']);
                                        }
                                        if (!empty($transaction['sender_account_number'])) {
                                            $transaction_details[] = "Sender Acc: " . htmlspecialchars($transaction['sender_account_number']);
                                        }
                                    }
                                ?>
                                    <tr>
                                        <td><?php echo date('Y-m-d H:i', strtotime($transaction['initiated_at'])); ?></td>
                                        <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $transaction['transaction_type']))); ?></td>
                                        <td><?php echo $display_description; ?></td>
                                        <td class="<?php echo $amount_class; ?>">
                                            <?php echo $display_amount; ?>
                                            <br><small>(<?php echo htmlspecialchars($display_currency); ?>)</small>
                                        </td>
                                        <td><?php echo htmlspecialchars($transaction['transaction_reference']); ?></td>
                                        <td><span class="status-<?php echo htmlspecialchars($transaction['status']); ?>"><?php echo htmlspecialchars(ucfirst($transaction['status'])); ?></span></td>
                                        <td><?php echo implode('<br>', $transaction_details); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="pagination">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php
                                $query_params = $_GET; // Get current filter params
                                $query_params['page'] = $i; // Set current page
                                $pagination_link = '?' . http_build_query($query_params);
                            ?>
                            <a href="<?php echo $pagination_link; ?>" class="<?php echo ($i == $current_page) ? 'current-page' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                <?php else: ?>
                    <p class="no-transactions">No transactions found for the selected criteria.</p>
                <?php endif; ?>

            </div>
        </main>
    </div>
    <script src="script.js"></script>
</body>
</html>