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
$user_accounts = []; // Array to store user's accounts

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

// Fetch user's accounts - UPDATED to include sort_code, iban, swift_bic
$stmt_accounts = $conn->prepare("SELECT id, account_number, account_type, balance, currency, sort_code, iban, swift_bic FROM accounts WHERE user_id = ? ORDER BY account_type, account_number");
if (!$stmt_accounts) {
    die("Error preparing accounts statement: " . $conn->error);
}
$stmt_accounts->bind_param("i", $user_id);
$stmt_accounts->execute();
$result_accounts = $stmt_accounts->get_result();
while ($row = $result_accounts->fetch_assoc()) {
    $user_accounts[] = $row;
}
$stmt_accounts->close();

$conn->close();

// Helper to format currency
if (!function_exists('formatCurrency')) {
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
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Accounts - Heritage Bank</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Specific styles for the accounts page */
        .accounts-content {
            padding: 20px;
        }

        .account-card-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .account-card {
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            padding: 25px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 220px; /* Increased height to accommodate new fields */
            transition: transform 0.2s ease-in-out;
        }

        .account-card:hover {
            transform: translateY(-5px);
        }

        .account-card h3 {
            color: #0056b3;
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 1.3em;
            display: flex;
            align-items: center;
        }

        .account-card h3 i {
            margin-right: 10px;
            color: #007bff;
        }

        .account-card p {
            margin: 8px 0;
            font-size: 1.1em;
            color: #333;
        }

        .account-card .detail-label {
            font-weight: bold;
            color: #555;
            margin-right: 5px;
        }

        .account-card .detail-value {
            font-family: 'Courier New', Courier, monospace;
            background-color: #f0f0f0;
            padding: 3px 8px;
            border-radius: 4px;
            display: inline-block;
            letter-spacing: 0.5px;
        }

        .account-card .balance {
            font-size: 1.8em;
            font-weight: bold;
            color: #28a745; /* Green for positive balance */
            margin-top: 15px;
        }

        .no-accounts {
            text-align: center;
            padding: 40px;
            font-size: 1.1em;
            color: #777;
            background-color: #f9f9f9;
            border-radius: 8px;
            margin-top: 20px;
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
                <li class="active"><a href="accounts.php"><i class="fas fa-wallet"></i> <span>My Accounts</span></a></li>
                <li><a href="profile.php"><i class="fas fa-user-circle"></i> <span>Profile</span></a></li>
                <li><a href="statements.php"><i class="fas fa-file-alt"></i> <span>Statements</span></a></li>
                <li><a href="transfer.php"><i class="fas fa-exchange-alt"></i> <span>Transfers</span></a></li>
                <li><a href="transactions.php"><i class="fas fa-history"></i> <span>Transaction History</span></a></li>
                <li><a href="#"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </aside>

        <main class="accounts-content">
            <div class="card">
                <h2>Your Bank Accounts</h2>

                <?php if (!empty($user_accounts)): ?>
                    <div class="account-card-container">
                        <?php foreach ($user_accounts as $account): ?>
                            <div class="account-card">
                                <h3>
                                    <?php
                                        // Display icon based on account type
                                        switch (strtolower($account['account_type'])) {
                                            case 'checking':
                                                echo '<i class="fas fa-money-check-alt"></i>';
                                                break;
                                            case 'savings':
                                                echo '<i class="fas fa-piggy-bank"></i>';
                                                break;
                                            case 'current':
                                                echo '<i class="fas fa-hand-holding-usd"></i>';
                                                break;
                                            default:
                                                echo '<i class="fas fa-wallet"></i>';
                                                break;
                                        }
                                    ?>
                                    <?php echo htmlspecialchars(ucwords($account['account_type'])); ?> Account
                                </h3>
                                <p><span class="detail-label">Account Number:</span> <span class="detail-value"><?php echo htmlspecialchars($account['account_number']); ?></span></p>
                                <p><span class="detail-label">Currency:</span> <span class="detail-value"><?php echo htmlspecialchars(strtoupper($account['currency'])); ?></span></p>

                                <?php if (!empty($account['sort_code'])): ?>
                                    <p><span class="detail-label">Sort Code:</span> <span class="detail-value"><?php echo htmlspecialchars($account['sort_code']); ?></span></p>
                                <?php endif; ?>

                                <?php if (!empty($account['iban'])): ?>
                                    <p><span class="detail-label">IBAN:</span> <span class="detail-value"><?php echo htmlspecialchars($account['iban']); ?></span></p>
                                <?php endif; ?>

                                <?php if (!empty($account['swift_bic'])): ?>
                                    <p><span class="detail-label">SWIFT/BIC:</span> <span class="detail-value"><?php echo htmlspecialchars($account['swift_bic']); ?></span></p>
                                <?php endif; ?>

                                <p class="balance">Balance: <?php echo formatCurrency($account['balance'], $account['currency']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="no-accounts">You currently have no bank accounts linked to your profile.</p>
                <?php endif; ?>

            </div>
        </main>
    </div>
    <script src="script.js"></script>
</body>
</html>