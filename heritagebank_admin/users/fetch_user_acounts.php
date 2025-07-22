<?php
session_start();
require_once '../Config.php'; // Path to Config.php from heritagebank/users/

header('Content-Type: application/json');

// Check if the user is NOT logged in or if it's not an AJAX request
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true ||
    !isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access or invalid request.']);
    exit;
}

$user_id = $_SESSION['user_id'];

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn === false) {
    error_log("ERROR: Could not connect to database for accounts. " . mysqli_connect_error());
    echo json_encode(['success' => false, 'message' => 'Database connection error.']);
    exit;
}

$user_accounts = [];
try {
    // Fetch accounts for the logged-in user
    $stmt = mysqli_prepare($conn, "SELECT id, account_name, account_number, account_type, balance FROM accounts WHERE user_id = ?");
    if (!$stmt) {
        throw new Exception("Account lookup statement prep failed: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        // You might want to format account_number for display, e.g., mask some digits
        $row['display_account_number'] = '...' . substr($row['account_number'], -4);
        $user_accounts[] = $row;
    }
    mysqli_stmt_close($stmt);
    echo json_encode(['success' => true, 'accounts' => $user_accounts]);

} catch (Exception $e) {
    error_log("Error fetching user accounts (AJAX): " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => "Error fetching accounts: " . $e->getMessage()]);
} finally {
    if ($conn) {
        mysqli_close($conn);
    }
}
exit;
?>