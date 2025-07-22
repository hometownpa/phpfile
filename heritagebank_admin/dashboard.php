<?php
session_start(); // Start the session

// Check if the admin is NOT logged in, redirect to login page
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

$admin_username = $_SESSION['admin_username'] ?? 'Admin User'; // Get logged-in username
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heritage Bank Admin Dashboard</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <img src="https://i.imgur.com/YmC3kg3.png" alt="HomeTown Bank Logo" class="logo">
            <h2>Welcome, <?php echo htmlspecialchars($admin_username); ?>!</h2>
            <a href="logout.php" class="logout-button">Logout</a>
        </div>

        <div class="dashboard-content">
            <h3>Admin Overview</h3>
            <p>This is your secure admin dashboard. Here you can manage users, transactions, reports, and more.</p>
            <div class="stats-grid">
                <div class="stat-card">
                    <h4>Total Users</h4>
                    <p>1,234,567</p>
                </div>
                <div class="stat-card">
                    <h4>Pending Approvals</h4>
                    <p>45</p>
                </div>
                <div class="stat-card">
                    <h4>Daily Transactions</h4>
                    <p>9,876</p>
                </div>
                <div class="stat-card">
                    <h4>System Health</h4>
                    <p>Optimal</p>
                </div>
            </div>

            <nav class="dashboard-nav">
                <ul>
                   <li><a href="users/users_management.php">User Management</a></li>
                    <li><a href="#">Transaction History</a></li>
                    <li><a href="#">Reports & Analytics</a></li>
                    <li><a href="#">System Settings</a></li>
                </ul>
            </nav>
        </div>
    </div>
    <script src="script.js"></script>
</body>
</html>