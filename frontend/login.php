<?php
// C:\xampp\htdocs\heritagebank\frontend\login.php
session_start();
require_once '../Config.php'; // Correct path from frontend/ to project root
require_once '../functions.php'; // Correct path from frontend/ to project root

$message = '';
$message_type = '';

// Check if user is already fully logged in (after 2FA)
if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true) {
    header('Location: dashboard.php'); // Assuming dashboard.php is also in the frontend/ folder
    exit;
} elseif (isset($_SESSION['2fa_pending']) && $_SESSION['2fa_pending'] === true && isset($_SESSION['temp_user_id'])) {
    header('Location: verify_code.php'); // Assuming verify_code.php is also in the frontend/ folder
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $last_name = trim($_POST['last_name'] ?? '');
    $membership_number = trim($_POST['membership_number'] ?? '');

    if (empty($last_name) || empty($membership_number)) {
        $message = 'Please enter both your Last Name and Membership Number.';
        $message_type = 'error';
    } else {
        $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn === false) {
            die("ERROR: Could not connect to database. " . mysqli_connect_error());
        }

        // Prepare the SQL statement to find the user by last_name and membership_number
        $stmt = mysqli_prepare($conn, "SELECT id, first_name, last_name, email FROM users WHERE last_name = ? AND membership_number = ?");

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ss", $last_name, $membership_number);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            if ($user = mysqli_fetch_assoc($result)) {
                // User found, now initiate 2FA
                $user_id = $user['id'];
                $user_email = $user['email'];
                $user_full_name = htmlspecialchars($user['first_name'] . ' ' . $user['last_name']);

                // Generate a 6-digit verification code
                $verification_code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                $expiry_time = date('Y-m-d H:i:s', strtotime('+10 minutes')); // Code expires in 10 minutes

                // Store the code and expiry in the database
                $stmt_update_code = mysqli_prepare($conn, "UPDATE users SET 2fa_code = ?, 2fa_code_expiry = ? WHERE id = ?");
                if ($stmt_update_code) {
                    mysqli_stmt_bind_param($stmt_update_code, "ssi", $verification_code, $expiry_time, $user_id);
                    if (mysqli_stmt_execute($stmt_update_code)) {

                        // --- START OF EMAIL BODY DESIGN ---
                        $email_subject = "Heritage Bank Login Verification Code";
                        $email_plain_body = "Dear " . $user_full_name . ",\n\n";
                        $email_plain_body .= "Your verification code for Heritage Bank login is: " . $verification_code . "\n\n";
                        $email_plain_body .= "This code will expire in 10 minutes. Please enter it on the verification page to access your account.\n\n";
                        $email_plain_body .= "If you did not attempt to log in, please secure your account immediately.\n\n";
                        $email_plain_body .= "Sincerely,\nHeritage Bank";

                        $email_html_body = '
                        <!DOCTYPE html>
                        <html lang="en">
                        <head>
                            <meta charset="UTF-8">
                            <meta name="viewport" content="width=device-width, initial-scale=1.0">
                            <title>Heritage Bank - Verification Code</title>
                            <style>
                                body {
                                    font-family: "Roboto", sans-serif;
                                    background-color: #f4f7f6;
                                    margin: 0;
                                    padding: 0;
                                    -webkit-text-size-adjust: 100%;
                                    -ms-text-size-adjust: 100%;
                                    width: 100% !important;
                                }
                                .email-container {
                                    max-width: 600px;
                                    margin: 20px auto;
                                    background-color: #ffffff;
                                    border-radius: 8px;
                                    box-shadow: 0 0 10px rgba(0,0,0,0.05);
                                    overflow: hidden;
                                }
                                .header {
                                    background-color: #0056b3; /* Dark blue */
                                    padding: 20px;
                                    text-align: center;
                                    color: #ffffff;
                                    font-size: 24px;
                                    font-weight: bold;
                                }
                                .content {
                                    padding: 30px;
                                    color: #333333;
                                    line-height: 1.6;
                                    text-align: center;
                                }
                                .content p {
                                    margin-bottom: 15px;
                                }
                                .verification-code {
                                    display: inline-block;
                                    background-color: #e6f0fa; /* Light blue background */
                                    color: #0056b3; /* Dark blue text */
                                    font-size: 32px;
                                    font-weight: bold;
                                    padding: 15px 30px;
                                    border-radius: 5px;
                                    margin: 20px 0;
                                    letter-spacing: 3px;
                                    text-align: center;
                                }
                                .expiry-info {
                                    font-size: 0.9em;
                                    color: #777777;
                                    margin-top: 20px;
                                }
                                .footer {
                                    background-color: #f0f0f0;
                                    padding: 20px;
                                    text-align: center;
                                    font-size: 0.85em;
                                    color: #666666;
                                    border-top: 1px solid #eeeeee;
                                }
                                .button {
                                    display: inline-block;
                                    background-color: #007bff;
                                    color: #ffffff;
                                    padding: 10px 20px;
                                    text-decoration: none;
                                    border-radius: 5px;
                                    font-weight: bold;
                                    margin-top: 20px;
                                }
                            </style>
                        </head>
                        <body>
                            <div class="email-container">
                                <div class="header">
                                    Heritage Bank
                                </div>
                                <div class="content">
                                    <p>Dear ' . $user_full_name . ',</p>
                                    <p>Thank you for logging in to Heritage Bank Online Banking. To complete your login, please use the following verification code:</p>
                                    <div class="verification-code">' . $verification_code . '</div>
                                    <p class="expiry-info">This code is valid for <strong>10 minutes</strong> and should be entered on the verification page.</p>
                                    <p>If you did not attempt to log in or request this code, please ignore this email or contact us immediately if you suspect unauthorized activity on your account.</p>
                                    <p>For security reasons, do not share this code with anyone.</p>
                                </div>
                                <div class="footer">
                                    <p>&copy; ' . date('Y') . ' Heritage Bank. All rights reserved.</p>
                                    <p>This is an automated email, please do not reply.</p>
                                </div>
                            </div>
                        </body>
                        </html>
                        ';
                        // --- END OF EMAIL BODY DESIGN ---

                        // Using send_smtp_email function from functions.php
                        // The sendEmail function from your functions.php will need to support HTML content.
                        // Assuming sendEmail takes: ($to, $subject, $html_body, $alt_plain_body)
                        $email_sent = sendEmail($user_email, $email_subject, $email_html_body, $email_plain_body);

                        if ($email_sent) {
                            // Set session variables for the 2FA pending state
                            $_SESSION['2fa_pending'] = true;
                            $_SESSION['temp_user_id'] = $user_id; // Store user ID temporarily
                            $_SESSION['temp_user_email'] = $user_email; // Store email to display if needed on verify_code.php

                            $message = "A verification code has been sent to your registered email address. Please check your inbox and enter the code to proceed.";
                            $message_type = 'success';
                            header('Location: verify_code.php'); // Redirect to the code verification page
                            exit;
                        } else {
                            $message = "Failed to send verification email. Please try again or contact support.";
                            $message_type = 'error';
                            // It's crucial not to expose why the email failed for security
                            error_log("2FA email send failed for user ID: " . $user_id . " - Error: " . error_get_last()['message'] ?? 'Unknown SMTP error'); // Improved logging
                        }

                    } else {
                        $message = "Failed to store verification code. Please try again.";
                        $message_type = 'error';
                        error_log("Failed to update 2FA code for user ID: " . $user_id . " Error: " . mysqli_error($conn));
                    }
                    mysqli_stmt_close($stmt_update_code);
                } else {
                    $message = "Database error during code storage preparation. Please try again.";
                    $message_type = 'error';
                    error_log("2FA update statement prep failed: " . mysqli_error($conn));
                }

            } else {
                $message = 'Invalid Last Name or Membership Number.';
                $message_type = 'error';
            }
            mysqli_stmt_close($stmt);
        } else {
            $message = "Database query preparation failed. Please try again.";
            $message_type = 'error';
        }
        mysqli_close($conn);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heritage Bank - Online Banking Login</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
</head>
<body class="login-page">
    <div class="barclays-header">
        <img src="../images/logo.png" alt="Heritage Bank Logo" class="logo-barclays">
        <div class="secure-label">
            <span class="lock-icon">&#128274;</span> Secure
        </div>
    </div>

    <div class="barclays-login-container">
        <h1>Log in to Online Banking</h1>
        <p class="login-prompt">How would you like to log in?</p>

        <div class="login-tabs">
            <div class="tab active" data-tab="membership-login">Membership number</div>
        </div>

        <div class="tab-content active" id="membership-login">
            <p class="register-link-top">Not registered for Online Banking? <a href="register.php">Register now.</a></p>

            <?php if (!empty($message)): ?>
                <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <form action="login.php" method="POST" class="barclays-form">
                <div class="form-group-barclays">
                    <label for="last_name">Last name</label>
                    <input type="text" id="last_name" name="last_name" required value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
                </div>
                <div class="form-group-barclays">
                    <label for="membership_number">Membership number (12 digits)</label>
                    <input type="text" id="membership_number" name="membership_number" required pattern="\d{12}" title="Membership number must be 12 digits" value="<?php echo htmlspecialchars($_POST['membership_number'] ?? ''); ?>">
                    <span class="info-icon" title="Your unique 12-digit membership ID assigned by the bank.">&#9432;</span>
                </div>

                <p class="forgot-link">
                    <a href="#">Don't know your membership number?</a>
                </p>

                <div class="form-group-barclays checkbox-group">
                    <input type="checkbox" id="remember_me" name="remember_me">
                    <label for="remember_me">Remember my last name and login method (optional)</label>
                    <small>Don't tick the box if you're using a public or shared device</small>
                </div>

                <button type="submit" name="login" class="button-barclays-primary">Continue</button>
            </form>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Get the tab for 'Membership number'
            const membershipTab = document.querySelector('.tab[data-tab="membership-login"]');
            // Get the content for 'Membership number'
            const membershipContent = document.getElementById('membership-login');

            // Ensure the 'Membership number' tab is active and its content is displayed
            if (membershipTab) {
                membershipTab.classList.add('active');
            }
            if (membershipContent) {
                membershipContent.style.display = 'block';
                membershipContent.classList.add('active');
            }

            // Optional: If you keep the disabled tabs visually, their click handlers don't need to do anything
            // beyond the initial setup, as only 'membership-login' has content.
            const tabs = document.querySelectorAll('.login-tabs .tab');
            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    // This logic only processes if the clicked tab is 'membership-login'
                    if (this.dataset.tab === 'membership-login') {
                        // Remove 'active' from all tabs and add to the clicked one
                        tabs.forEach(t => t.classList.remove('active'));
                        this.classList.add('active');

                        // Ensure only 'membership-login' content is active/displayed
                        if (membershipContent) {
                            membershipContent.classList.add('active');
                            membershipContent.style.display = 'block';
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>