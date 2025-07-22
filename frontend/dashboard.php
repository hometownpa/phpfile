<?php
// Path: C:\xampp\htdocs\hometownbank\frontend\dashboard.php
// Assuming dashboard.php is in the 'frontend' folder, so paths to Config and functions need to go up one level.

session_start();

// TEMPORARY DEBUG: Display logged-in user ID to confirm session is set
// echo "User ID from session: " . ($_SESSION['user_id'] ?? 'Not Set') . "\n";

// Assuming Config.php and functions.php are in the parent directory of frontend/
require_once '../Config.php';
require_once '../functions.php'; // For sanitize_input, and potentially other utilities

// Check if the user is logged in. If not, redirect to login page.
// Note: If your main login page is 'indx.php' as per your verify_code.php,
// then the redirect should be to '../indx.php'.
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !isset($_SESSION['user_id'])) {
    header('Location: ../indx.php'); // Redirect to the main login page (e.g., indx.php)
    exit;
}

$user_id = $_SESSION['user_id'];
// Fetch username, first_name, last_name from session (set during login)
$username = $_SESSION['username'] ?? 'User'; // Fallback if username not set in session
$first_name = $_SESSION['first_name'] ?? '';
$last_name = $_SESSION['last_name'] ?? '';
$user_email = $_SESSION['temp_user_email'] ?? ''; // Assuming email is stored in session from 2FA flow


// Generate full name for display
$full_name = trim($first_name . ' ' . $last_name);
if (empty($full_name)) {
    $full_name = $username;
}

// Connect to the database
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn === false) {
    // TEMPORARY DEBUG: Die with a clear message if DB connection fails
    die("ERROR: Could not connect to database. " . mysqli_connect_error() . ". Please check Config.php and MySQL service.");
} else {
    // TEMPORARY DEBUG: Confirm successful DB connection
    // echo "Database connected successfully.\n";
}


$user_accounts = []; // Array to store all accounts for the logged-in user
$recent_transactions = []; // Array to store recent transactions

// 1. Fetch user's accounts
$stmt_accounts = mysqli_prepare($conn, "SELECT id, account_number, account_type, balance, currency FROM accounts WHERE user_id = ?");
if ($stmt_accounts) {
    mysqli_stmt_bind_param($stmt_accounts, "i", $user_id);
    if (mysqli_stmt_execute($stmt_accounts)) { // Execute the statement
        // echo "Accounts query executed.\n"; // TEMPORARY DEBUG
        $result_accounts = mysqli_stmt_get_result($stmt_accounts);
        while ($account_data = mysqli_fetch_assoc($result_accounts)) {
            $user_accounts[] = $account_data;
        }
        mysqli_stmt_close($stmt_accounts);
    } else {
        // TEMPORARY DEBUG: Log and echo execution error
        $error_message = "Error executing account fetch statement: " . mysqli_stmt_error($stmt_accounts);
        error_log($error_message);
        // echo "Error: " . $error_message . "\n";
    }
} else {
    // TEMPORARY DEBUG: Log and echo preparation error
    $error_message = "Error preparing account fetch statement: " . mysqli_error($conn);
    error_log($error_message);
    // echo "Error: " . $error_message . "\n";
}

// 2. Fetch recent transactions for the user
// IMPORTANT: This query assumes your 'transactions' table has an 'account_id' column
// which links to the 'accounts' table's 'id'. If it only has 'user_id',
// the JOIN will fail. It also assumes 'user_id' exists in the transactions table.
$stmt_transactions = mysqli_prepare($conn, "
    SELECT
        t.initiated_at AS transaction_date,      -- Your date column
        t.description,
        t.transaction_type AS type,              -- Your transaction type column
        t.amount,
        t.currency,                              -- Your currency column for transactions
        a.account_number,
        a.account_type
    FROM
        transactions t
    JOIN
        accounts a ON t.account_id = a.id   -- This JOIN requires 'account_id' in your transactions table!
    WHERE
        t.user_id = ?                             -- Filtering by user_id directly
    ORDER BY
        t.initiated_at DESC                 -- Order by your date column
    LIMIT 10"); // Limit to last 10 transactions

if ($stmt_transactions) {
    mysqli_stmt_bind_param($stmt_transactions, "i", $user_id);
    if (mysqli_stmt_execute($stmt_transactions)) { // Execute the statement
        // echo "Transactions query executed.\n"; // TEMPORARY DEBUG
        $result_transactions = mysqli_stmt_get_result($stmt_transactions);
        while ($transaction_data = mysqli_fetch_assoc($result_transactions)) {
            $recent_transactions[] = $transaction_data;
        }
        mysqli_stmt_close($stmt_transactions);
    } else {
        // TEMPORARY DEBUG: Log and echo execution error
        $error_message = "Error executing transactions fetch statement: " . mysqli_stmt_error($stmt_transactions);
        error_log($error_message);
        // echo "Error: " . $error_message . "\n";
    }
} else {
    // TEMPORARY DEBUG: Log and echo preparation error
    $error_message = "Error preparing transactions fetch statement: " . mysqli_error($conn);
    error_log($error_message);
    // echo "Error: " . $error_message . "\n";
}


// Always close the database connection when done
mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WestStar Credit Union - Dashboard</title>
    <link rel="stylesheet" href="dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="container">
        <header class="header">
            <div class="menu-icon" id="menuIcon">
                <i class="fas fa-bars"></i>
            </div>
            <div class="greeting">
                <h1 data-user-first-name="<?php echo htmlspecialchars($first_name); ?>">Hi, </h1>
            </div>
            <div class="profile-pic">
                <img src="/heritagebank/images/default-profile.png" alt="Profile Picture" id="headerProfilePic">
            </div>
        </header>

        <section class="accounts-section">
            <div class="accounts-header-row">
                <h2>Accounts</h2>
                <div class="view-all-link">
                    <a href="accounts.php">View all</a>
                </div>
            </div>
            <div class="account-cards-container">
                <?php if (empty($user_accounts)): ?>
                    <p class="loading-message" id="accountsLoadingMessage">No accounts found. Please contact support.</p>
                <?php else: ?>
                    <?php foreach ($user_accounts as $account): ?>
                        <div class="account-card">
                            <div class="account-details">
                                <p class="account-type"><?php echo htmlspecialchars(strtoupper($account['account_type'])); ?></p>
                                <p class="account-number">**** **** **** <?php echo htmlspecialchars(substr($account['account_number'], -4)); ?></p>
                            </div>
                            <div class="account-balance">
                                <p class="balance-amount">
                                    <?php echo htmlspecialchars($account['currency']); ?> <?php echo number_format($account['balance'], 2); ?>
                                </p>
                                <p class="balance-status">Available</p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="account-pagination">
            </div>
        </section>

      <section class="actions-section">
    <div class="action-button" id="transferButton">
        <i class="fas fa-exchange-alt"></i>
        <p>Transfer</p>
    </div>
    <div class="action-button" id="depositButton">
        <i class="fas fa-download"></i>
        <p>Deposit</p>
    </div>
    <div class="action-button">
        <i class="fas fa-dollar-sign"></i>
        <p>Pay</p>
    </div>
    <div class="action-button" id="messageButton" onclick="window.location.href='customer-service.php'">
        <i class="fas fa-headset"></i> <p>Customer Service</p>
    </div>
</section>

       <section class="bank-cards-section">
    <h2>My Cards</h2>
    <a class="view-cards-button" id="viewMyCardsButton" href="bank_cards.php">
        <i class="fas fa-credit-card"></i> View My Cards
    </a>
    <div class="card-list-container" id="userCardList" style="display: none;">
        <p class="loading-message" id="cardsLoadingMessage">No cards found. Go to "Manage All Cards" to add one.</p>
    </div>

</section>

        <section class="activity-section">
            <div class="transactions-header">
                <h2>Transactions</h2> <span class="more-options" onclick="window.location.href='statements.php'">...</span>
            </div>
            <div class="transaction-list">
                <?php if (empty($recent_transactions)): ?>
                    <p class="loading-message" id="transactionsLoadingMessage">No recent transactions to display.</p>
                <?php else: ?>
                    <?php foreach ($recent_transactions as $transaction): ?>
                        <div class="transaction-item">
                            <div class="transaction-details">
                                <span class="transaction-description"><?php echo htmlspecialchars($transaction['description']); ?></span>
                                <span class="transaction-account">
                                    <?php echo htmlspecialchars($transaction['account_type']); ?> x<?php echo htmlspecialchars(substr($transaction['account_number'], -4)); ?>
                                </span>
                            </div>
                            <div class="transaction-amount-date">
                                <span class="transaction-amount <?php echo ($transaction['type'] == 'Credit') ? 'credit' : 'debit'; ?>">
                                    <?php echo ($transaction['type'] == 'Credit' ? '+' : '-'); ?>
                                    <?php echo htmlspecialchars($transaction['currency']); ?> <?php echo number_format($transaction['amount'], 2); ?>
                                </span>
                                <span class="transaction-date"><?php echo htmlspecialchars(date('M d', strtotime($transaction['transaction_date']))); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <button class="see-more-button" onclick="window.location.href='statements.php'">See more</button>
        </section>

    </div>

    <div class="transfer-modal-overlay" id="transferModalOverlay">
        <div class="transfer-modal-content">
            <h3>Choose Transfer Type</h3>
            <div class="transfer-options-list">
                <button class="transfer-option" data-transfer-type="Own Account" onclick="window.location.href='transfer.php?type=own_account'">
                    <i class="fas fa-wallet"></i> <p>Transfer to My Other Account</p>
                </button>

                <button class="transfer-option" data-transfer-type="Bank to Bank" onclick="window.location.href='transfer.php?type=bank_to_bank'">
                    <i class="fas fa-university"></i>
                    <p>Bank to Bank Transfer</p>
                </button>
                <button class="transfer-option" data-transfer-type="ACH" onclick="window.location.href='transfer.php?type=ach'">
                    <i class="fas fa-exchange-alt"></i>
                    <p>ACH Transfer</p>
                </button>
                <button class="transfer-option" data-transfer-type="Wire" onclick="window.location.href='transfer.php?type=wire'">
                    <i class="fas fa-ethernet"></i>
                    <p>Wire Transfer</p>
                </button>
                <button class="transfer-option" data-transfer-type="International Bank" onclick="window.location.href='transfer.php?type=international_bank'">
                    <i class="fas fa-globe"></i>
                    <p>International Bank Transfer</p>
                </button>
                <button class="transfer-option" data-transfer-type="Domestic Wire" onclick="window.location.href='transfer.php?type=domestic_wire'">
                    <i class="fas fa-home"></i>
                    <p>Domestic Wire Transfer</p>
                </button>
            </div>
            <button class="close-modal-button" id="closeTransferModal">Close</button>
        </div>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <button class="close-sidebar-button" id="closeSidebarBtn">
                <i class="fas fa-times"></i>
            </button>
            <div class="sidebar-profile">
                <img src="/heritagebank/images/default-profile.png" alt="Profile Picture" class="sidebar-profile-pic">
                <h3><span id="sidebarUserName"><?php echo htmlspecialchars($full_name); ?></span></h3>
                <p><span id="sidebarUserEmail"><?php echo htmlspecialchars($user_email); ?></span></p>
            </div>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php" class="active"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="accounts.php"><i class="fas fa-wallet"></i> Accounts</a></li>
                <li><a href="transfer.php"><i class="fas fa-exchange-alt"></i> Transfers</a></li>
                <li><a href="statements.php"><i class="fas fa-file-invoice"></i> Statements</a></li>
                <li><a href="profile.php"><i class="fas fa-user"></i> Profile</a></li>
                <li><a href="#"><i class="fas fa-cog"></i> Settings</a></li>
                <li><a href="bank_cards.php"><i class="fas fa-credit-card"></i> Bank Cards</a></li>
            </ul>
        </nav>
        <button class="logout-button" id="logoutButton" onclick="window.location.href='../logout.php'">
            <i class="fas fa-sign-out-alt"></i> Logout
        </button>
    </div>

    <script src="user.dashboard.js"></script>
</body>
</html>