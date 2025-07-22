<?php
// C:\xampp\htdocs\heritagebank\frontend\verify_code.php
session_start();
require_once '../Config.php'; // Path from frontend/ to project root
require_once '../functions.php'; // Path from frontend/ to project root

$message = '';
$message_type = '';

// Check if 2FA process is pending, otherwise redirect to login
if (!isset($_SESSION['2fa_pending']) || $_SESSION['2fa_pending'] !== true || !isset($_SESSION['temp_user_id'])) {
    header('Location: ../indx.php');
    exit;
}

$user_id = $_SESSION['temp_user_id'];

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn === false) {
    die("ERROR: Could not connect to database. " . mysqli_connect_error());
}

// Fetch user's email to resend code if needed
$stmt_user_email = mysqli_prepare($conn, "SELECT email, first_name, last_name FROM users WHERE id = ?");
$user_email = '';
$user_full_name = '';
if ($stmt_user_email) {
    mysqli_stmt_bind_param($stmt_user_email, "i", $user_id);
    mysqli_stmt_execute($stmt_user_email);
    $result_user_email = mysqli_stmt_get_result($stmt_user_email);
    if ($user_data = mysqli_fetch_assoc($result_user_email)) {
        $user_email = $user_data['email'];
        $user_full_name = htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']);
    }
    mysqli_stmt_close($stmt_user_email);
}


// --- Handle Code Resend ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_code'])) {
    // Generate a new 6-digit verification code
    $new_verification_code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $new_expiry_time = date('Y-m-d H:i:s', strtotime('+10 minutes')); // New code expires in 10 minutes

    // Update the database with the new code and expiry
    $stmt_update_code = mysqli_prepare($conn, "UPDATE users SET 2fa_code = ?, 2fa_code_expiry = ? WHERE id = ?");
    if ($stmt_update_code) {
        mysqli_stmt_bind_param($stmt_update_code, "ssi", $new_verification_code, $new_expiry_time, $user_id);
        if (mysqli_stmt_execute($stmt_update_code)) {

            // Send the new verification email
            $email_subject = "HomeTown Bank PA New Login Verification Code";
            $email_body = "Dear " . $user_full_name . ",\n\n";
            $email_body .= "You requested a new verification code. Your new code is: <strong>" . $new_verification_code . "</strong>\n\n";
            $email_body .= "This code will expire in 10 minutes. Please enter it on the verification page to access your account.\n\n";
            $email_body .= "Sincerely,\nHomeTwon Bank";

            $email_sent = send_smtp_email($user_email, $email_subject, $email_body, strip_tags($email_body));

            if ($email_sent) {
                $message = "A new verification code has been sent to your registered email address.";
                $message_type = 'success';
            } else {
                $message = "Failed to resend verification email. Please try again or contact support.";
                $message_type = 'error';
                error_log("Resend 2FA email failed for user ID: " . $user_id);
            }
        } else {
            $message = "Failed to update new verification code. Please try again.";
            $message_type = 'error';
            error_log("Failed to update new 2FA code for user ID: " . $user_id . " Error: " . mysqli_error($conn));
        }
        mysqli_stmt_close($stmt_update_code);
    } else {
        $message = "Database error during resend code preparation. Please try again.";
        $message_type = 'error';
    }
}


// --- Handle Code Verification ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_code'])) {
    $entered_code = trim($_POST['verification_code'] ?? '');

    if (empty($entered_code)) {
        $message = 'Please enter the verification code.';
        $message_type = 'error';
    } else {
        // Fetch the stored 2FA code and its expiry from the database
        $stmt_fetch_code = mysqli_prepare($conn, "SELECT 2fa_code, 2fa_code_expiry FROM users WHERE id = ?");
        if ($stmt_fetch_code) {
            mysqli_stmt_bind_param($stmt_fetch_code, "i", $user_id);
            mysqli_stmt_execute($stmt_fetch_code);
            $result = mysqli_stmt_get_result($stmt_fetch_code);

            if ($row = mysqli_fetch_assoc($result)) {
                $stored_code = $row['2fa_code'];
                $stored_expiry = $row['2fa_code_expiry'];
                $current_time = date('Y-m-d H:i:s');

                if ($entered_code === $stored_code && $current_time < $stored_expiry) {
                    // Code is valid and not expired
                    $_SESSION['user_logged_in'] = true; // Mark user as fully logged in
                    $_SESSION['user_id'] = $user_id;    // Store actual user ID in session
                    // Clear 2FA specific session variables
                    unset($_SESSION['2fa_pending']);
                    unset($_SESSION['temp_user_id']);

                    // Optionally, clear the 2fa_code in the database after successful login
                    $stmt_clear_code = mysqli_prepare($conn, "UPDATE users SET 2fa_code = NULL, 2fa_code_expiry = NULL WHERE id = ?");
                    if ($stmt_clear_code) {
                        mysqli_stmt_bind_param($stmt_clear_code, "i", $user_id);
                        mysqli_stmt_execute($stmt_clear_code);
                        mysqli_stmt_close($stmt_clear_code);
                    }

                    header('Location: dashboard.php'); // Redirect to dashboard
                    exit;
                } else if ($current_time >= $stored_expiry) {
                    $message = 'Verification code has expired. Please request a new one.';
                    $message_type = 'error';
                } else {
                    $message = 'Invalid verification code. Please try again.';
                    $message_type = 'error';
                }
            } else {
                $message = 'No verification code found for your account. Please log in again to generate one.';
                $message_type = 'error';
            }
            mysqli_stmt_close($stmt_fetch_code);
        } else {
            $message = "Database error during code fetch. Please try again.";
            $message_type = 'error';
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
    <title>Heritage Bank - Verify Code</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        /* Specific styles for the verify code page, extending from style.css */
        body.verify-page {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center; /* Center vertically */
            min-height: 100vh;
            background-color: #f4f7f6; /* Match main body background */
            font-family: 'Roboto', sans-serif;
        }

        .verify-container {
            background-color: #fff;
            padding: 30px 40px;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 450px; /* Slightly smaller than login container */
            box-sizing: border-box;
            text-align: center;
        }

        .verify-container h1 {
            font-size: 1.8em;
            color: #333;
            margin-bottom: 20px;
        }

        .verify-container p {
            color: #555;
            margin-bottom: 25px;
            line-height: 1.5;
        }

        .verify-container .form-group {
            margin-bottom: 25px;
            text-align: left;
        }

        .verify-container label {
            display: block;
            margin-bottom: 8px;
            font-size: 0.9em;
            color: #333;
            font-weight: bold;
        }

        .verify-container input[type="text"] {
            width: calc(100% - 22px);
            padding: 12px 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 1.1em;
            text-align: center; /* Center the code input */
            letter-spacing: 2px; /* Space out characters for code readability */
            box-sizing: border-box;
        }

        .verify-container input[type="text"]:focus {
            border-color: #0056b3;
            outline: none;
            box-shadow: 0 0 0 2px rgba(0, 86, 179, 0.2);
        }

        .button-primary {
            background-color: #007bff;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 4px;
            font-size: 1em;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            transition: background-color 0.3s ease;
            margin-bottom: 15px; /* Space before resend */
        }

        .button-primary:hover {
            background-color: #0056b3;
        }

        .resend-link {
            font-size: 0.9em;
            color: #555;
        }

        .resend-link button {
            background: none;
            border: none;
            color: #0056b3;
            text-decoration: underline;
            cursor: pointer;
            font-size: 1em;
            font-family: 'Roboto', sans-serif;
            padding: 0;
        }

        .resend-link button:hover {
            color: #007bff;
        }

        /* Message styling from main style.css */
        .message {
            padding: 10px 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-size: 0.95em;
            text-align: center;
        }

        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        @media (max-width: 600px) {
            .verify-container {
                padding: 20px 25px;
                margin: 20px; /* Add margin on smaller screens */
            }
            .verify-container h1 {
                font-size: 1.5em;
            }
            .verify-container input[type="text"] {
                padding: 10px;
                font-size: 1em;
            }
        }
    </style>
</head>
<body class="verify-page">
    <div class="verify-container">
        <h1>Verify Your Login</h1>
        <p>A 6-digit verification code has been sent to your registered email address (<?php echo htmlspecialchars($user_email); ?>). Please enter it below.</p>

        <?php if (!empty($message)): ?>
            <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <form action="verify_code.php" method="POST">
            <div class="form-group">
                <label for="verification_code">Verification Code</label>
                <input type="text" id="verification_code" name="verification_code" required maxlength="6" pattern="\d{6}" title="Please enter a 6-digit code">
            </div>
            <button type="submit" name="verify_code" class="button-primary">Verify Code</button>
        </form>

        <p class="resend-link">
            Didn't receive the code?
            <form action="verify_code.php" method="POST" style="display:inline;">
                <button type="submit" name="resend_code">Resend Code</button>
            </form>
        </p>
    </div>
</body>
</html>