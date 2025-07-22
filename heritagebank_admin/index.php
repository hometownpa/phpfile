<?php
session_start(); // Start the session at the very beginning

// Check if the admin is already logged in, redirect to dashboard
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: dashboard.php');
    exit;
}

$error_message = '';

// Check if the form has been submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // --- Hardcoded Admin Credentials (DO NOT use in production!) ---
    $admin_username = 'admin@heritagebank.com';
    $admin_password = 'adminpassword123'; // Replace with a strong password in a real scenario

    if ($username === $admin_username && $password === $admin_password) {
        // Authentication successful
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_id'] = 1;
        header('Location: dashboard.php'); // Redirect to the admin dashboard
        exit;
    } else {
        // Authentication failed
        $error_message = 'Invalid username or password. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heritage Bank Admin Login</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <img src="https://i.imgur.com/YmC3kg3.png" alt="Bank Logo" class="logo">
            <h2>Admin Login</h2>
        </div>
        <form action="index.php" method="POST" class="login-form" id="loginForm">
            <?php if (!empty($error_message)): ?>
                <p class="error-message"><?php echo $error_message; ?></p>
            <?php endif; ?>

            <div class="form-group">
                <label for="username">Username / Email</label>
                <input type="text" id="username" name="username" placeholder="Enter your admin email" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter your password" required>
            </div>
            <button type="submit" class="login-button">Login</button>
        </form>
    </div>
    <script src="script.js"></script>
</body>
</html>