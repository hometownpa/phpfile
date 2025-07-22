<?php
session_start();
require_once '../../Config.php'; // Adjust path

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ../index.php'); // Corrected redirect to admin login page
    exit;
}

$message = '';
$message_type = '';

// --- Database Connection ---
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn === false) {
    die("ERROR: Could not connect to database. " . mysqli_connect_error());
}

// --- Handle Delete Action ---
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $user_id_to_delete = intval($_GET['id']);

    // Start Transaction for Atomicity
    mysqli_autocommit($conn, FALSE); // Disable autocommit
    $transaction_success = true;

    try {
        // Optional: Get profile image path before deleting user to remove the file
        $profile_image_path_to_delete = null;
        $stmt_get_image = mysqli_prepare($conn, "SELECT profile_image FROM users WHERE id = ?");
        if ($stmt_get_image) {
            mysqli_stmt_bind_param($stmt_get_image, "i", $user_id_to_delete);
            mysqli_stmt_execute($stmt_get_image);
            mysqli_stmt_bind_result($stmt_get_image, $profile_image_path_to_delete);
            mysqli_stmt_fetch($stmt_get_image);
            mysqli_stmt_close($stmt_get_image);
        }

        // 1. Get all account IDs associated with this user
        $stmt_get_accounts = mysqli_prepare($conn, "SELECT id FROM accounts WHERE user_id = ?");
        if (!$stmt_get_accounts) {
            throw new Exception("Failed to prepare account fetch: " . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt_get_accounts, "i", $user_id_to_delete);
        mysqli_stmt_execute($stmt_get_accounts);
        $result_accounts = mysqli_stmt_get_result($stmt_get_accounts);
        $account_ids_to_delete = [];
        while ($row = mysqli_fetch_assoc($result_accounts)) {
            $account_ids_to_delete[] = $row['id'];
        }
        mysqli_stmt_close($stmt_get_accounts);

        // If there are accounts, delete their dependent data first
        if (!empty($account_ids_to_delete)) {
            $account_ids_placeholder = implode(',', array_fill(0, count($account_ids_to_delete), '?'));
            $types = str_repeat('i', count($account_ids_to_delete));

            // 2. Delete related records from 'transactions' table (Child of accounts)
            $stmt_del_transactions = mysqli_prepare($conn, "DELETE FROM transactions WHERE account_id IN ($account_ids_placeholder)");
            if (!$stmt_del_transactions) {
                throw new Exception("Failed to prepare transaction delete: " . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt_del_transactions, $types, ...$account_ids_to_delete);
            if (!mysqli_stmt_execute($stmt_del_transactions)) {
                throw new Exception("Failed to delete transactions: " . mysqli_error($conn));
            }
            mysqli_stmt_close($stmt_del_transactions);
            
            // 3. Delete related records from 'bank_cards' table (Child of accounts or users - assuming user_id for now)
            // If bank_cards are linked to accounts, update this query.
            // For now, assuming bank_cards are linked to users directly.
            $stmt_del_cards = mysqli_prepare($conn, "DELETE FROM bank_cards WHERE user_id = ?"); 
            if (!$stmt_del_cards) {
                throw new Exception("Failed to prepare bank cards delete: " . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt_del_cards, "i", $user_id_to_delete);
            if (!mysqli_stmt_execute($stmt_del_cards)) {
                throw new Exception("Failed to delete bank cards: " . mysqli_error($conn));
            }
            mysqli_stmt_close($stmt_del_cards);

            // 4. Delete associated records from 'account_status_history' (Child of users)
            $stmt_del_status_history = mysqli_prepare($conn, "DELETE FROM account_status_history WHERE user_id = ?");
            if (!$stmt_del_status_history) {
                throw new Exception("Failed to prepare account status history delete: " . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt_del_status_history, "i", $user_id_to_delete);
            if (!mysqli_stmt_execute($stmt_del_status_history)) {
                throw new Exception("Failed to delete account status history: " . mysqli_error($conn));
            }
            mysqli_stmt_close($stmt_del_status_history);

            // 5. Delete the accounts themselves (Parent of transactions, child of users)
            $stmt_delete_accounts = mysqli_prepare($conn, "DELETE FROM accounts WHERE user_id = ?");
            if (!$stmt_delete_accounts) {
                throw new Exception("Failed to prepare accounts delete: " . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt_delete_accounts, "i", $user_id_to_delete);
            if (!mysqli_stmt_execute($stmt_delete_accounts)) {
                throw new Exception("Failed to delete accounts: " . mysqli_error($conn));
            }
            mysqli_stmt_close($stmt_delete_accounts);
        }

        // 6. Finally, delete the user record (Parent of accounts, account_status_history, bank_cards)
        $stmt_delete_user = mysqli_prepare($conn, "DELETE FROM users WHERE id = ?");
        if ($stmt_delete_user) {
            mysqli_stmt_bind_param($stmt_delete_user, "i", $user_id_to_delete);
            if (!mysqli_stmt_execute($stmt_delete_user)) {
                throw new Exception("Failed to delete user: " . mysqli_error($conn));
            }
            mysqli_stmt_close($stmt_delete_user);
        } else {
            throw new Exception("Failed to prepare user delete statement: " . mysqli_error($conn));
        }

        // Commit if all successful
        mysqli_commit($conn);
        $message = "User and all associated data deleted successfully. 🎉";
        $message_type = 'success';

        // Delete profile image file from server if it exists
        // Adjust the path to '../../' if 'profile_images' is directly in the project root
        // or ensure the path stored in DB is relative to the web root.
        if ($profile_image_path_to_delete && file_exists('../../' . $profile_image_path_to_delete)) {
            unlink('../../' . $profile_image_path_to_delete);
        }

    } catch (Exception $e) {
        mysqli_rollback($conn); // Rollback on any error
        $message = "Error deleting user: " . $e->getMessage();
        $message_type = 'error';
        error_log("User deletion error: " . $e->getMessage()); // Log the error for debugging
    }
    
    mysqli_autocommit($conn, TRUE); // Re-enable autocommit

    // Redirect to prevent re-submission on refresh and display message
    header('Location: manage_users.php?message=' . urlencode($message) . '&type=' . urlencode($message_type));
    exit;
}

// Re-fetch message if it came from a redirect after an action (like delete)
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = htmlspecialchars($_GET['message']);
    $message_type = htmlspecialchars($_GET['type']);
}


// --- Fetch Users (for display) ---
$users = [];
$query = "SELECT u.id, u.first_name, u.last_name, u.email, u.phone_number, u.membership_number, u.account_status,
                    COALESCE(ac.balance, 0.00) AS checking_balance, ac.account_number AS checking_account_number,
                    COALESCE(sa.balance, 0.00) AS savings_balance, sa.account_number AS savings_account_number,
                    COALESCE(ac.currency, sa.currency) AS common_currency,
                    COALESCE(ac.sort_code, sa.sort_code) AS common_sort_code,
                    COALESCE(ac.iban, sa.iban) AS common_iban,
                    COALESCE(ac.swift_bic, sa.swift_bic) AS common_swift_bic
            FROM users u
            LEFT JOIN accounts ac ON u.id = ac.user_id AND ac.account_type = 'Checking'
            LEFT JOIN accounts sa ON u.id = sa.user_id AND sa.account_type = 'Savings'
            ORDER BY u.created_at DESC";

$result = mysqli_query($conn, $query);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $users[] = $row;
    }
    mysqli_free_result($result);
} else {
    $message = "Error fetching users: " . mysqli_error($conn);
    $message_type = 'error';
}

mysqli_close($conn);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heritage Bank - Manage Users</title>
    <link rel="stylesheet" href="../style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        /* General body and container styling */
        body {
            font-family: 'Roboto', sans-serif;
            background-color: #f4f7f6;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .dashboard-container {
            display: flex;
            flex-direction: column;
            width: 100%;
            align-items: center;
        }

        /* Dashboard Header */
        .dashboard-header {
            background-color: #004494; /* Darker blue for header */
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .dashboard-header .logo {
            height: 40px; /* Adjust logo size */
        }

        .dashboard-header h2 {
            margin: 0;
            color: white;
            font-size: 1.8em;
        }

        .dashboard-header .logout-button {
            background-color: #ffcc29; /* Heritage accent color */
            color: #004494;
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s ease;
        }

        .dashboard-header .logout-button:hover {
            background-color: #e0b821;
        }

        /* Main Content Area */
        .dashboard-content {
            padding: 30px;
            width: 100%;
            max-width: 1200px; /* Wider for table */
            margin: 20px auto; /* Center the content */
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
            box-sizing: border-box; /* Include padding in width */
        }

        /* Messages */
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-weight: bold;
            text-align: center;
            border: 1px solid transparent;
        }

        .message.success {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }

        /* Table Styling */
        .table-responsive {
            overflow-x: auto;
            margin-top: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            min-width: 800px; /* Ensure table doesn't get too small on narrow screens */
        }
        table th, table td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
            vertical-align: top;
        }
        table th {
            background-color: #f2f2f2;
            font-weight: bold;
            background-color: #004494;
            color: white;
            font-size: 0.9em;
            text-transform: uppercase;
        }
        table td {
            font-size: 0.95em;
        }
        table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        table tr:hover {
            background-color: #f1f1f1;
        }

        .button-small {
            padding: 5px 10px;
            border-radius: 4px;
            text-decoration: none;
            color: white;
            font-size: 0.85em;
            display: inline-block;
            margin-right: 5px;
            margin-bottom: 5px; /* For stacking on smaller screens */
        }
        .button-edit {
            background-color: #007bff; /* Blue */
        }
        .button-delete {
            background-color: #dc3545; /* Red */
        }
        .button-status { /* New style for status button */
            background-color: #17a2b8; /* Teal */
        }
        .button-edit:hover, .button-delete:hover, .button-status:hover {
            opacity: 0.9;
        }
        .add-user-button {
            display: inline-block;
            background-color: #28a745;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            margin-bottom: 20px;
            transition: background-color 0.3s ease;
        }

        .add-user-button:hover {
            background-color: #218838;
        }
        .account-details-cell span {
            display: block; /* Make each account detail a new line */
            margin-bottom: 5px;
        }
        .account-details-cell span:last-child {
            margin-bottom: 0;
        }
        /* Styles for account status display */
        .status-active { color: #28a745; font-weight: bold; }
        .status-suspended { color: #ffc107; font-weight: bold; }
        .status-blocked { color: #dc3545; font-weight: bold; }
        .status-restricted { color: #17a2b8; font-weight: bold; }
        .status-closed { color: #6c757d; font-weight: bold; }

    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <img src="../../images/logo.png" alt="Heritage Bank Logo" class="logo">
            <h2>Manage Users</h2>
            <a href="../logout.php" class="logout-button">Logout</a>
        </div>

        <div class="dashboard-content">
            <?php if (!empty($message)): ?>
                <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <h3>All Bank Users</h3>
            <p class="section-description">Here you can view, edit, or delete user accounts. You can also <a href="create_user.php" class="add-user-button">Create New User</a>.</p>

            <?php if (empty($users)): ?>
                <p>No users found. <a href="create_user.php">Create a new user</a>.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Membership No.</th>
                                <th>Account Status</th> 
                                <th>Common Bank Details</th>
                                <th>Accounts & Balances</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['id']); ?></td>
                                    <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo htmlspecialchars($user['phone_number']); ?></td>
                                    <td><?php echo htmlspecialchars($user['membership_number']); ?></td>
                                    <td><span class="status-<?php echo strtolower(htmlspecialchars($user['account_status'])); ?>"><?php echo htmlspecialchars(ucfirst($user['account_status'])); ?></span></td> <td>
                                        <strong>Currency:</strong> <?php echo htmlspecialchars($user['common_currency'] ?? 'N/A'); ?><br>
                                        <?php if ($user['common_sort_code']): ?>
                                            <strong>Sort Code:</strong> <?php echo htmlspecialchars($user['common_sort_code']); ?><br>
                                        <?php endif; ?>
                                        <strong>IBAN:</strong> <?php echo htmlspecialchars($user['common_iban'] ?? 'N/A'); ?><br>
                                        <strong>SWIFT/BIC:</strong> <?php echo htmlspecialchars($user['common_swift_bic'] ?? 'N/A'); ?>
                                    </td>
                                    <td class="account-details-cell">
                                        <?php if ($user['checking_account_number']): ?>
                                            <span><strong>Checking:</strong> <?php echo htmlspecialchars($user['checking_account_number']); ?> (<?php echo htmlspecialchars($user['common_currency']); ?> <?php echo number_format($user['checking_balance'], 2); ?>)</span>
                                        <?php else: ?>
                                            <span>No Checking Account</span>
                                        <?php endif; ?>
                                        <br>
                                        <?php if ($user['savings_account_number']): ?>
                                            <span><strong>Savings:</strong> <?php echo htmlspecialchars($user['savings_account_number']); ?> (<?php echo htmlspecialchars($user['common_currency']); ?> <?php echo number_format($user['savings_balance'], 2); ?>)</span>
                                        <?php else: ?>
                                            <span>No Savings Account</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="edit_user.php?id=<?php echo $user['id']; ?>" class="button-small button-edit">Edit</a>
                                        <a href="account_status_management.php?user_id=<?php echo $user['id']; ?>" class="button-small button-status">Manage Status</a> 
                                        <a href="manage_users.php?action=delete&id=<?php echo $user['id']; ?>" class="button-small button-delete" onclick="return confirm('Are you sure you want to delete this user AND ALL their associated data (accounts, transactions, cards, etc.)? This action cannot be undone.');">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <p><a href="../dashboard.php" class="back-link">&larr; Back to Admin Dashboard</a></p>
        </div>
    </div>
    <script src="../script.js"></script>
</body>
</html>