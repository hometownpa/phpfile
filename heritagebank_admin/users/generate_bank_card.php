<?php
session_start();
require_once '../../Config.php'; // Adjust path if necessary, depending on file location

// Check if the admin is NOT logged in, redirect to login page
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ../index.php'); // Corrected logout redirect, assuming ../index.php is your login
    exit;
}

$message = '';
$message_type = '';
$generated_card_info = null; // To display the mock card details
$display_account_selection = false; // Flag for the two-step process
$user_for_card_generation = null; // Stores user data after search
$accounts_for_card_generation = []; // Stores accounts for the selected user

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn === false) {
    // It's better to log the error and display a generic message in production
    error_log("ERROR: Could not connect to database. " . mysqli_connect_error());
    die("Database connection error. Please try again later."); // For development, die is fine.
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'find_user') {
        $user_identifier = trim($_POST['user_identifier'] ?? '');

        if (empty($user_identifier)) {
            $message = 'Please provide a user email or membership number.';
            $message_type = 'error';
        } else {
            // Find the user
            $stmt = mysqli_prepare($conn, "SELECT id, first_name, last_name, email, membership_number FROM users WHERE email = ? OR membership_number = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "ss", $user_identifier, $user_identifier);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $user_for_card_generation = mysqli_fetch_assoc($result);
                mysqli_stmt_close($stmt);

                if ($user_for_card_generation) {
                    // User found, now fetch their accounts
                    $user_id = $user_for_card_generation['id'];
                    // Ensure you select 'id' as 'account_id' for consistency with HTML form values
                    $account_stmt = mysqli_prepare($conn, "SELECT id AS account_id, account_number, account_type, balance, currency FROM accounts WHERE user_id = ?");
                    if ($account_stmt) {
                        mysqli_stmt_bind_param($account_stmt, "i", $user_id);
                        mysqli_stmt_execute($account_stmt);
                        $accounts_result = mysqli_stmt_get_result($account_stmt);
                        while ($row = mysqli_fetch_assoc($accounts_result)) {
                            $accounts_for_card_generation[] = $row;
                        }
                        mysqli_stmt_close($account_stmt);

                        if (empty($accounts_for_card_generation)) {
                            $message = 'User found, but has no associated bank accounts. Cannot generate card.';
                            $message_type = 'error';
                        } else {
                            $display_account_selection = true; // Show the second part of the form
                            $message = 'User found. Please select an account to generate a card for.';
                            $message_type = 'success';
                        }
                    } else {
                        $message = 'Database query preparation failed for accounts: ' . mysqli_error($conn);
                        $message_type = 'error';
                    }
                } else {
                    $message = 'User not found with the provided identifier.';
                    $message_type = 'error';
                }
            } else {
                $message = 'Database query preparation failed for user search: ' . mysqli_error($conn);
                $message_type = 'error';
            }
        }
    } elseif ($action === 'generate_card') {
        $user_id = intval($_POST['user_id_hidden'] ?? 0);
        $account_id = intval($_POST['account_id'] ?? 0);

        if ($user_id <= 0 || $account_id <= 0) {
            $message = 'Invalid user or account selected. Please try again.';
            $message_type = 'error';
            // Attempt to re-display form if user_id is known for better UX
            if ($user_id > 0) {
                $display_account_selection = true;
                // Re-fetch user and accounts data for form persistence
                $stmt_user_re = mysqli_prepare($conn, "SELECT id, first_name, last_name, email, membership_number FROM users WHERE id = ?");
                if ($stmt_user_re) {
                    mysqli_stmt_bind_param($stmt_user_re, "i", $user_id);
                    mysqli_stmt_execute($stmt_user_re);
                    $user_for_card_generation = mysqli_fetch_assoc($stmt_user_re);
                    mysqli_stmt_close($stmt_user_re);
                }
                $account_stmt_re = mysqli_prepare($conn, "SELECT id AS account_id, account_number, account_type, balance, currency FROM accounts WHERE user_id = ?");
                if ($account_stmt_re) {
                    mysqli_stmt_bind_param($account_stmt_re, "i", $user_id);
                    mysqli_stmt_execute($account_stmt_re);
                    $accounts_result_re = mysqli_stmt_get_result($account_stmt_re);
                    while ($row = mysqli_fetch_assoc($accounts_result_re)) {
                        $accounts_for_card_generation[] = $row;
                    }
                    mysqli_stmt_close($account_stmt_re);
                }
            }
        } else {
            // Get user's full name for card holder
            $user_name_stmt = mysqli_prepare($conn, "SELECT first_name, last_name FROM users WHERE id = ?");
            $card_holder_name = '';
            if ($user_name_stmt) {
                mysqli_stmt_bind_param($user_name_stmt, "i", $user_id);
                mysqli_stmt_execute($user_name_stmt);
                $name_result = mysqli_stmt_get_result($user_name_stmt);
                if ($name_row = mysqli_fetch_assoc($name_result)) {
                    $card_holder_name = strtoupper($name_row['first_name'] . ' ' . $name_row['last_name']);
                }
                mysqli_stmt_close($user_name_stmt);
            }

            if (empty($card_holder_name)) {
                $message = 'Could not retrieve user name for card generation.';
                $message_type = 'error';
            } else {
                // --- Simulate Card Generation with Type and Prefixes ---
                $card_types = ['Visa', 'MasterCard', 'Verve'];
                $card_type = $card_types[array_rand($card_types)]; // Randomly pick a type

                $prefix = '';
                if ($card_type === 'Visa') {
                    $prefix = '4';
                } elseif ($card_type === 'MasterCard') {
                    // Common MasterCard prefixes are 51-55
                    $mc_prefixes = ['51', '52', '53', '54', '55'];
                    $prefix = $mc_prefixes[array_rand($mc_prefixes)];
                } elseif ($card_type === 'Verve') {
                    $prefix = '5061'; // Common Verve prefix
                }

                // Generate remaining digits for a 16-digit number
                $remaining_digits_length = 16 - strlen($prefix);
                $random_digits = '';
                for ($i = 0; $i < $remaining_digits_length; $i++) {
                    $random_digits .= mt_rand(0, 9);
                }
                $card_number_raw = $prefix . $random_digits;
                $card_number_display = wordwrap($card_number_raw, 4, ' ', true);

                $expiry_month = str_pad(mt_rand(1, 12), 2, '0', STR_PAD_LEFT);
                $expiry_year_full = date('Y') + mt_rand(3, 7); // 3 to 7 years from current year
                $expiry_year_short = date('y', strtotime($expiry_year_full . '-01-01'));
                $cvv = str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);

                // Get the created_at date from POST, default to current time if not provided or invalid
                $admin_created_at_str = trim($_POST['created_at'] ?? '');
                try {
                    $dateTimeObj = new DateTime($admin_created_at_str);
                    $admin_created_at = $dateTimeObj->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    $admin_created_at = date('Y-m-d H:i:s'); // Fallback to current time
                }

                // Optional: Check if a card already exists for this account
                // This prevents multiple active cards for the same account, adjust logic as needed
                $check_card_stmt = mysqli_prepare($conn, "SELECT id FROM bank_cards WHERE account_id = ? AND is_active = TRUE");
                if ($check_card_stmt) {
                    mysqli_stmt_bind_param($check_card_stmt, "i", $account_id);
                    mysqli_stmt_execute($check_card_stmt);
                    mysqli_stmt_store_result($check_card_stmt);
                    if (mysqli_stmt_num_rows($check_card_stmt) > 0) {
                        $message = 'A bank card already exists and is active for the selected account. Cannot generate a new one.';
                        $message_type = 'error';
                    }
                    mysqli_stmt_close($check_card_stmt);
                }

                if ($message_type !== 'error') {
                    // Insert card details into the database
                    // PIN is not set by admin, it will be set by the user
                    $initial_pin_hash = NULL; // Store NULL initially for the PIN
                    // Set is_active to 1 (active) assuming card is functional upon generation.
                    // Change to 0 if you want user activation to be mandatory for card function by setting PIN.
                    $is_active = 1;

                    $insert_stmt = mysqli_prepare($conn, "INSERT INTO bank_cards (user_id, account_id, card_number, card_type, expiry_month, expiry_year, cvv, card_holder_name, is_active, created_at, updated_at, pin) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if ($insert_stmt) {
                        $current_timestamp = date('Y-m-d H:i:s'); // For updated_at

                        // Corrected bind_param: "iissssssisss" (12 characters for 12 variables)
                        mysqli_stmt_bind_param($insert_stmt, "iissssssisss",
                            $user_id,
                            $account_id, // Link the card to the specific account
                            $card_number_raw,
                            $card_type,
                            $expiry_month,
                            $expiry_year_full, // Ensure this is stored as a string or correct integer type in DB
                            $cvv,
                            $card_holder_name,
                            $is_active,
                            $admin_created_at,
                            $current_timestamp,
                            $initial_pin_hash // PIN is NULL initially, which is handled by 's'
                        );
                        if (mysqli_stmt_execute($insert_stmt)) {
                            $message = "Mock {$card_type} card generated and stored successfully for " . $card_holder_name . ".";
                            $message_type = 'success';
                            $generated_card_info = [
                                'holder_name' => $card_holder_name,
                                'card_number' => $card_number_display,
                                'expiry_date' => $expiry_month . '/' . $expiry_year_short, // Display short year (e.g., 28)
                                'cvv' => $cvv,
                                'card_type' => $card_type // Pass type for styling
                            ];
                            // Clear inputs after successful generation to show initial form
                            $_POST = array(); // Clears all POST data to reset the form
                            $display_account_selection = false; // Reset to step 1
                            $user_for_card_generation = null;
                            $accounts_for_card_generation = [];
                        } else {
                            $message = "Error storing card details: " . mysqli_error($conn);
                            $message_type = 'error';
                        }
                        mysqli_stmt_close($insert_stmt);
                    } else {
                        $message = "Database insert query preparation failed: " . mysqli_error($conn);
                        $message_type = 'error';
                    }
                }
            }
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
    <title>HomeTown Bank - Generate Bank Card</title>
    <link rel="stylesheet" href="../style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        /* General card display styles */
        .card-display {
            color: white;
            padding: 25px;
            border-radius: 15px;
            width: 350px;
            margin: 30px auto;
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
            position: relative;
            font-family: 'Roboto Mono', monospace;
            background: linear-gradient(45deg, #004d40, #00796b); /* Default: Dark teal gradient */
        }
        /* Specific card type gradients */
        .card-display.visa {
            background: linear-gradient(45deg, #2a4b8d, #3f60a9); /* Blue tones for Visa */
        }
        .card-display.mastercard {
            background: linear-gradient(45deg, #eb001b, #ff5f00); /* Red-orange tones for MasterCard */
        }
        .card-display.verve {
            background: linear-gradient(45deg, #006633, #009933); /* Green tones for Verve */
        }

        .card-display h4 {
            margin-top: 0;
            font-size: 1.1em;
            color: rgba(255,255,255,0.8);
        }
        .card-display .chip {
            width: 50px;
            height: 35px;
            background-color: #d4af37; /* Gold color */
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .card-display .card-number {
            font-size: 1.6em;
            letter-spacing: 2px;
            margin-bottom: 20px;
        }
        .card-display .card-footer {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            font-size: 0.9em;
        }
        .card-display .card-footer .label {
            font-size: 0.7em;
            opacity: 0.7;
            margin-bottom: 3px;
        }
        .card-display .card-footer .value {
            font-weight: bold;
        }
        .card-logo {
            position: absolute;
            bottom: 25px;
            right: 25px;
            height: 40px; /* Adjust size as needed */
        }
        /* Styles for the two-step form sections */
        .form-section {
            border: 1px solid #eee;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            background-color: #f9f9f9;
        }
        .user-info-display {
            background-color: #e9ecef;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-weight: bold;
            color: #333;
        }
        /* Message styles (retained/consistent with your current setup) */
        .message.success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; padding: 10px; border-radius: 5px; margin-bottom: 15px; }
        .message.error { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; padding: 10px; border-radius: 5px; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <img src="../../images/logo.png" alt="HomeTown Bank Logo" class="logo">
            <h2>Generate Bank Card</h2>
            <a href="../logout.php" class="logout-button">Logout</a>
        </div>

        <div class="dashboard-content">
            <?php if (!empty($message)): ?>
                <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <?php if (!$display_account_selection): // Step 1: Find User ?>
                <div class="form-section">
                    <h3>Find User to Generate Card For</h3>
                    <form action="generate_bank_card.php" method="POST" class="form-standard">
                        <input type="hidden" name="action" value="find_user">
                        <div class="form-group">
                            <label for="user_identifier">User Email or Membership Number</label>
                            <input type="text" id="user_identifier" name="user_identifier" value="<?php echo htmlspecialchars($_POST['user_identifier'] ?? ''); ?>" placeholder="e.g., user@example.com or 123456789012" required>
                            <small>Enter the user's email or 12-digit numeric membership number to find their accounts.</small>
                        </div>
                        <button type="submit" class="button-primary">Find User</button>
                    </form>
                </div>
            <?php else: // Step 2: Select Account and Generate Card ?>
                <div class="form-section">
                    <h3>Generate Card for:
                        <?php echo htmlspecialchars($user_for_card_generation['first_name'] . ' ' . $user_for_card_generation['last_name']); ?>
                        (<?php echo htmlspecialchars($user_for_card_generation['email']); ?>)
                    </h3>
                    <div class="user-info-display">
                        Membership No: <?php echo htmlspecialchars($user_for_card_generation['membership_number']); ?>
                    </div>
                    <form action="generate_bank_card.php" method="POST" class="form-standard">
                        <input type="hidden" name="action" value="generate_card">
                        <input type="hidden" name="user_id_hidden" value="<?php echo htmlspecialchars($user_for_card_generation['id']); ?>">

                        <div class="form-group">
                            <label for="account_id">Select Account for Card</label>
                            <select id="account_id" name="account_id" required>
                                <option value="">-- Select an Account --</option>
                                <?php foreach ($accounts_for_card_generation as $account): ?>
                                    <option value="<?php echo htmlspecialchars($account['account_id']); ?>"
                                        <?php echo (($_POST['account_id'] ?? '') == $account['account_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($account['account_type'] . ' (' . $account['account_number'] . ') - ' . $account['currency'] . ' ' . number_format($account['balance'], 2)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small>The card will be linked to this specific account.</small>
                        </div>

                        <div class="form-group">
                            <label for="created_at">Card Creation Date/Time</label>
                            <input type="datetime-local" id="created_at" name="created_at"
                                value="<?php echo htmlspecialchars(date('Y-m-d\TH:i:s')); ?>" required>
                            <small>Set the exact date and time this card is considered 'created'.</small>
                        </div>

                        <button type="submit" class="button-primary">Generate Card</button>
                    </form>
                    <p><a href="generate_bank_card.php" class="back-link">Start a New Card Generation</a></p>
                </div>
            <?php endif; ?>

            <?php if ($generated_card_info): ?>
                <div class="card-display <?php echo strtolower($generated_card_info['card_type']); ?>">
                    <h4>HOMETOWN BANK</h4>
                    <div class="chip"></div>
                    <div class="card-number"><?php echo $generated_card_info['card_number']; ?></div>
                    <div class="card-footer">
                        <div>
                            <div class="label">CARD HOLDER</div>
                            <div class="value"><?php echo htmlspecialchars($generated_card_info['holder_name']); ?></div>
                        </div>
                        <div>
                            <div class="label">EXPIRES</div>
                            <div class="value"><?php echo htmlspecialchars($generated_card_info['expiry_date']); ?></div>
                        </div>
                    </div>
                    <p style="font-size: 0.8em; text-align: center; margin-top: 10px;">
                        CVV: <?php echo $generated_card_info['cvv']; ?> (DO NOT store or display real CVVs!)
                    </p>
                    <?php
                        $card_logo_path = '';
                        if (strtolower($generated_card_info['card_type']) === 'visa') {
                            $card_logo_path = '../../images/visa_logo.png';
                        } elseif (strtolower($generated_card_info['card_type']) === 'mastercard') {
                            $card_logo_path = '../../images/mastercard_logo.png';
                        } elseif (strtolower($generated_card_info['card_type']) === 'verve') {
                            $card_logo_path = '../../images/verve_logo.png';
                        }
                    ?>
                    <?php if ($card_logo_path): ?>
                        <img src="<?php echo $card_logo_path; ?>" alt="<?php echo $generated_card_info['card_type']; ?> Logo" class="card-logo">
                    <?php endif; ?>
                </div>
                <p style="text-align: center; font-size: 0.9em; color: #666;">
                    *Reminder: In a real system, full card numbers and CVVs are highly sensitive and should be handled with extreme security (e.g., tokenization, never storing CVV). The user will set their PIN from their dashboard.
                </p>
            <?php endif; ?>

            <p><a href="users_management.php" class="back-link">&larr; Back to User Management</a></p>
        </div>
    </div>
    <script src="../script.js"></script>
</body>
</html>