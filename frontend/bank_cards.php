<?php
session_start();
require_once '../Config.php';

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: ../index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_full_name = $_SESSION['user_full_name'] ?? '';
$user_email = $_SESSION['user_email'] ?? '';

$message = '';
$message_type = '';

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn === false) {
    error_log("ERROR: Could not connect to database. " . mysqli_connect_error());
    $message = "Database connection error. Please try again later.";
    $message_type = 'error';
    exit;
}

// Fetch user's full name and email from the database if not already in session
if (empty($user_full_name) || empty($user_email)) {
    $stmt_user_info = mysqli_prepare($conn, "SELECT full_name, email FROM users WHERE id = ?");
    if ($stmt_user_info) {
        mysqli_stmt_bind_param($stmt_user_info, "i", $user_id);
        mysqli_stmt_execute($stmt_user_info);
        $result_user_info = mysqli_stmt_get_result($stmt_user_info);
        if ($user_db_info = mysqli_fetch_assoc($result_user_info)) {
            $user_full_name = $user_db_info['full_name'];
            $user_email = $user_db_info['email'];
            $_SESSION['user_full_name'] = $user_full_name;
            $_SESSION['user_email'] = $user_email;
        }
        mysqli_stmt_close($stmt_user_info);
    }
}
$user_full_name = $user_full_name ?: 'Bank Customer';
$user_email = $user_email ?: 'default@example.com';

// --- Handle AJAX requests (fetching cards or ordering new card) ---
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'fetch_cards') {
        $bank_cards = [];
        try {
            $stmt = mysqli_prepare($conn, "SELECT id, card_number, card_type, expiry_month, expiry_year, cvv, card_holder_name, is_active, card_network, account_id FROM bank_cards WHERE user_id = ?");
            if (!$stmt) {
                throw new Exception("Card lookup statement prep failed: " . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            while ($row = mysqli_fetch_assoc($result)) {
                $row['display_card_number'] = '**** **** **** ' . substr($row['card_number'], -4);
                $row['card_holder_name'] = strtoupper($user_full_name);
                $row['display_expiry'] = str_pad($row['expiry_month'], 2, '0', STR_PAD_LEFT) . '/' . substr($row['expiry_year'], 2, 2);
                $row['display_cvv'] = $row['cvv']; // DANGER: For mock only
                unset($row['card_number']);
                unset($row['cvv']); // Remove raw CVV before sending to frontend
                $bank_cards[] = $row;
            }
            mysqli_stmt_close($stmt);
            echo json_encode(['success' => true, 'cards' => $bank_cards]);
        } catch (Exception $e) {
            error_log("Error fetching bank cards (AJAX): " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => "Error fetching cards: " . $e->getMessage()]);
        } finally {
            mysqli_close($conn); // Close connection for AJAX requests
        }
        exit; // IMPORTANT: Exit after sending JSON
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'order_card') {
        $cardType = filter_input(INPUT_POST, 'cardType', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $cardNetwork = filter_input(INPUT_POST, 'cardNetwork', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $shippingAddress = filter_input(INPUT_POST, 'shippingAddress', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $account_id = filter_input(INPUT_POST, 'accountId', FILTER_VALIDATE_INT);

        if (empty($cardType) || empty($cardNetwork) || empty($shippingAddress) || !$account_id) {
            echo json_encode(['success' => false, 'message' => 'All fields are required for ordering a new card, including the linked bank account.']);
            exit;
        }

        $prefix = '';
        if ($cardNetwork === 'Visa') {
            $prefix = '4';
        } elseif ($cardNetwork === 'Mastercard') {
            $mc_prefixes = ['51', '52', '53', '54', '55'];
            $prefix = $mc_prefixes[array_rand($mc_prefixes)];
        } elseif ($cardNetwork === 'Amex') {
            $prefix = (mt_rand(0, 1) === 0) ? '34' : '37';
        } elseif ($cardNetwork === 'Verve') {
            $prefix = '5061';
        } else {
            $prefix = '9';
        }

        $card_number_length = ($cardNetwork === 'Amex') ? 15 : 16;
        $remaining_digits_length = $card_number_length - strlen($prefix);
        $random_digits = '';
        for ($i = 0; $i < $remaining_digits_length; $i++) {
            $random_digits .= mt_rand(0, 9);
        }
        $mock_card_number = $prefix . $random_digits;

        $mock_expiry_month = str_pad(mt_rand(1, 12), 2, '0', STR_PAD_LEFT);
        $mock_expiry_year = date('Y') + mt_rand(3, 7);
        $mock_cvv = str_pad(mt_rand(0, ($cardNetwork === 'Amex' ? 9999 : 999)), ($cardNetwork === 'Amex' ? 4 : 3), '0', STR_PAD_LEFT);
        $mock_card_holder_name = strtoupper($user_full_name);
        $initial_pin_hash = NULL;

        try {
            $stmt_account = mysqli_prepare($conn, "SELECT account_type, account_number FROM accounts WHERE id = ? AND user_id = ?");
            if (!$stmt_account) {
                throw new Exception("Account lookup statement prep failed: " . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt_account, "ii", $account_id, $user_id);
            mysqli_stmt_execute($stmt_account);
            $result_account = mysqli_stmt_get_result($stmt_account);
            $account_data = mysqli_fetch_assoc($result_account);
            mysqli_stmt_close($stmt_account);

            if (!$account_data) {
                throw new Exception("Selected account not found or does not belong to the user.");
            }
            $linked_account_type = $account_data['account_type'];
            $linked_account_number = $account_data['account_number'];

            $stmt = mysqli_prepare($conn, "INSERT INTO bank_cards (user_id, account_id, card_number, card_type, expiry_month, expiry_year, cvv, card_holder_name, is_active, card_network, shipping_address, pin) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception("Card order statement prep failed: " . mysqli_error($conn));
            }

            $is_active = 0;
            mysqli_stmt_bind_param($stmt, "iissssisssis",
                $user_id,
                $account_id,
                $mock_card_number,
                $cardType,
                $mock_expiry_month,
                $mock_expiry_year,
                $mock_cvv, // DANGER: Remove in production
                $mock_card_holder_name,
                $is_active,
                $cardNetwork,
                $shippingAddress,
                $initial_pin_hash
            );

            if (mysqli_stmt_execute($stmt)) {
                $inserted_card_id = mysqli_insert_id($conn);

                $subject = "Your HomeTown Bank Card Order Confirmation";
                $body = "Dear " . htmlspecialchars($user_full_name) . ",\n\n"
                      . "Thank you for ordering a new " . htmlspecialchars($cardNetwork) . " " . htmlspecialchars($cardType) . " card linked to your " . htmlspecialchars($linked_account_type) . " account (" . htmlspecialchars($linked_account_number) . ") from HomeTown Bank PA.\n\n"
                      . "Your order for a new card (ID: " . $inserted_card_id . ") has been successfully placed.\n"
                      . "Card Type: " . htmlspecialchars($cardType) . "\n"
                      . "Card Network: " . htmlspecialchars($cardNetwork) . "\n"
                      . "Linked Account: " . htmlspecialchars($linked_account_type) . " (No: " . htmlspecialchars($linked_account_number) . ")\n"
                      . "Shipping Address: " . htmlspecialchars($shippingAddress) . "\n\n"
                      . "Your card is currently being processed and will be shipped to the address provided. You will receive it within 5-7 business days.\n\n"
                      . "Once you receive your card, please log in to your dashboard to activate it and set your PIN.\n\n"
                      . "If you have any questions, please contact our customer support.\n\n"
                      . "Sincerely,\n"
                      . "The HomeTown Bank PA Team";

                $headers = "From: hometownbankpa@gmail.com\r\n";
                $headers .= "Reply-To: support@hometownbankpa.com\r\n";
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

                if (mail($user_email, $subject, $body, $headers)) {
                    $email_status = "Email sent successfully.";
                } else {
                    $email_status = "Failed to send email. Please check server logs.";
                    error_log("Failed to send card order confirmation email to " . $user_email . " for user_id: " . $user_id);
                }

                echo json_encode(['success' => true, 'message' => 'Your card order has been placed successfully! ' . $email_status]);

            } else {
                throw new Exception("Failed to save card order: " . mysqli_error($conn));
            }
            mysqli_stmt_close($stmt);
        } catch (Exception $e) {
            error_log("Error ordering new card (AJAX): " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => "Error placing order: " . $e->getMessage()]);
        } finally {
            if ($conn) { // Ensure connection is closed only if it was opened
                mysqli_close($conn);
            }
        }
        exit; // IMPORTANT: Exit after sending JSON
    }
    // If an AJAX request comes in but no specific action is matched (now only fetch_cards or order_card)
    echo json_encode(['success' => false, 'message' => 'Invalid AJAX action provided to bank_cards.php.']);
    exit;
}

// Non-AJAX request, render the HTML page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hometown Bank PA - Manage Cards</title>
    <link rel="stylesheet" href="bank_cards.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Your inline CSS remains here */
        .card-item { background: linear-gradient(45deg, #004d40, #00796b); color: white; padding: 25px; border-radius: 15px; width: 350px; margin: 20px auto; box-shadow: 0 10px 20px rgba(0,0,0,0.2); position: relative; font-family: 'Roboto Mono', monospace; display: flex; flex-direction: column; justify-content: space-between; min-height: 200px; }
        .card-item.visa { background: linear-gradient(45deg, #2a4b8d, #3f60a9); }
        .card-item.mastercard { background: linear-gradient(45deg, #eb001b, #ff5f00); }
        .card-item.amex { background: linear-gradient(45deg, #0081c7, #26a5d4); }
        .card-item.verve { background: linear-gradient(45deg, #006633, #009933); }
        .card-item h4 { margin-top: 0; font-size: 1.1em; color: rgba(255,255,255,0.8); }
        .card-item .chip { width: 50px; height: 35px; background-color: #d4af37; border-radius: 5px; margin-bottom: 20px; }
        .card-item .card-number { font-size: 1.6em; letter-spacing: 2px; margin-bottom: 20px; word-break: break-all; }
        .card-item .card-footer { display: flex; justify-content: space-between; align-items: flex-end; font-size: 0.9em; width: 100%; }
        .card-item .card-footer .label { font-size: 0.7em; opacity: 0.7; margin-bottom: 3px; }
        .card-item .card-footer .value { font-weight: bold; }
        .card-item .card-logo { position: absolute; bottom: 25px; right: 25px; height: 40px; }
        .card-status { font-size: 0.9em; text-align: right; margin-top: 10px; opacity: 0.9; }
        .card-status.active { color: #d4edda; }
        .card-status.inactive { color: #f8d7da; }
        .loading-message, .no-data-message { text-align: center; padding: 20px; color: #555; font-size: 1.1em; }
        .card-list { display: flex; flex-wrap: wrap; justify-content: center; gap: 20px; padding: 20px 0; }
    </style>
</head>
<body>

    <header class="header">
        <div class="logo">
            <a href="dashboard.php"> <img src="/frontend/images/hometown_bank_logo.png" alt="Hometown Bank PA Logo">
            </a>
        </div>
        <h1>Manage My Cards</h1>
        <nav class="header-nav">
            <a href="dashboard.php" class="back-to-dashboard"> <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </nav>
    </header>

    <main class="main-content">
        <?php if (!empty($message)): ?>
            <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <section class="cards-section">
            <h2>Your Current Cards</h2>
            <p id="cardsLoadingMessage" class="loading-message">
                <i class="fas fa-spinner fa-spin"></i> Loading your cards...
            </p>
            <div id="userCardList" class="card-list">
                <p class="no-data-message" id="noCardsMessage" style="display:none;">No bank cards found. Order a new one below!</p>
            </div>
        </section>

        <section class="order-card-section">
            <h2>Order a New Card</h2>
            <form id="orderCardForm">
                <input type="hidden" name="action" value="order_card">
                <div class="form-group">
                    <label for="cardType">Card Type:</label>
                    <select id="cardType" name="cardType" required>
                        <option value="">Select Card Type</option>
                        <option value="Debit">Debit Card</option>
                        <option value="Credit">Credit Card</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="cardNetwork">Card Network:</label>
                    <select id="cardNetwork" name="cardNetwork" required>
                        <option value="">Select Card Network</option>
                        <option value="Visa">Visa</option>
                        <option value="Mastercard">Mastercard</option>
                        <option value="Amex">American Express</option>
                        <option value="Verve">Verve</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="accountId">Link to Account:</label>
                    <select id="accountId" name="accountId" required>
                        <option value="">-- Fetching Accounts --</option>
                        </select>
                </div>

                <div class="form-group">
                    <label for="shippingAddress">Shipping Address:</label>
                    <textarea id="shippingAddress" name="shippingAddress" placeholder="Your full shipping address" rows="3" required></textarea>
                </div>

                <button type="submit" class="submit-button">
                    <i class="fas fa-credit-card" style="margin-right: 8px;"></i> Place Card Order
                </button>
            </form>
        </section>

        <section class="manage-pin-section">
            <h2>Manage Card PIN & Activation</h2>
            <p>To activate a new card or set/change your existing card's PIN, please visit the <a href="activate_card.php">Card Activation & PIN Management page</a>.</p>
        </section>
    </main>

    <div class="message-box-overlay" id="messageBoxOverlay">
        <div class="message-box-content" id="messageBoxContentWrapper">
            <p id="messageBoxContent"></p>
            <button id="messageBoxButton">OK</button>
        </div>
    </div>

    <script>
        const currentUserId = <?php echo json_encode($user_id); ?>;
        const currentUserFullName = <?php echo json_encode($user_full_name); ?>;
        const PHP_BASE_URL = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
        const FRONTEND_BASE_URL = '/frontend/';
    </script>
    <script src="cards.js"></script>
</body>
</html>