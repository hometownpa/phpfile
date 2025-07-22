<?php
session_start();
require_once '../Config.php';

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: ../index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn === false) {
    error_log("ERROR: Could not connect to database. " . mysqli_connect_error());
    die("Database connection error. Please try again later.");
}

// Handle PIN setting/card activation logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $card_id = filter_input(INPUT_POST, 'card_id', FILTER_VALIDATE_INT);
    $new_pin = filter_input(INPUT_POST, 'new_pin', FILTER_SANITIZE_NUMBER_INT);
    $confirm_pin = filter_input(INPUT_POST, 'confirm_pin', FILTER_SANITIZE_NUMBER_INT);
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    if ($action === 'set_pin' || $action === 'activate_card') {
        if (!$card_id) {
            $message = 'Invalid card selected.';
            $message_type = 'error';
        } elseif (empty($new_pin) || empty($confirm_pin) || strlen($new_pin) != 4) { // Assuming 4-digit PIN
            $message = 'PIN must be a 4-digit number.';
            $message_type = 'error';
        } elseif ($new_pin !== $confirm_pin) {
            $message = 'New PIN and confirm PIN do not match.';
            $message_type = 'error';
        } else {
            // Hash the PIN (NEVER store plain PINs!)
            $hashed_pin = password_hash($new_pin, PASSWORD_DEFAULT);

            try {
                // Update card status to active and set PIN
                $stmt = mysqli_prepare($conn, "UPDATE bank_cards SET pin = ?, is_active = 1, updated_at = NOW() WHERE id = ? AND user_id = ?");
                if (!$stmt) {
                    throw new Exception("PIN update statement prep failed: " . mysqli_error($conn));
                }
                mysqli_stmt_bind_param($stmt, "sii", $hashed_pin, $card_id, $user_id);

                if (mysqli_stmt_execute($stmt)) {
                    if (mysqli_stmt_affected_rows($stmt) > 0) {
                        $message = 'Card activated and PIN set successfully!';
                        $message_type = 'success';
                    } else {
                        $message = 'No changes made. Card not found or already active/PIN set.';
                        $message_type = 'info';
                    }
                } else {
                    throw new Exception("Failed to update PIN: " . mysqli_error($conn));
                }
                mysqli_stmt_close($stmt);
            } catch (Exception $e) {
                error_log("Error setting PIN: " . $e->getMessage());
                $message = "Error setting PIN: " . $e->getMessage();
                $message_type = 'error';
            }
        }
    }
}

// Fetch user's inactive cards for activation, or all cards if showing all
$cards_to_manage = [];
try {
    $stmt_cards = mysqli_prepare($conn, "SELECT id, card_type, card_network, card_number, is_active FROM bank_cards WHERE user_id = ? ORDER BY is_active ASC, created_at DESC");
    if ($stmt_cards) {
        mysqli_stmt_bind_param($stmt_cards, "i", $user_id);
        mysqli_stmt_execute($stmt_cards);
        $result_cards = mysqli_stmt_get_result($stmt_cards);
        while ($row = mysqli_fetch_assoc($result_cards)) {
            $row['display_card_number'] = '**** **** **** ' . substr($row['card_number'], -4);
            $cards_to_manage[] = $row;
        }
        mysqli_stmt_close($stmt_cards);
    }
} catch (Exception $e) {
    error_log("Error fetching cards for PIN management: " . $e->getMessage());
    $message = "Error loading cards for management.";
    $message_type = 'error';
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hometown Bank PA - Activate Card & Set PIN</title>
    <link rel="stylesheet" href="bank_cards.css"> <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Add specific styles for this page if needed */
        .card-management-section {
            background-color: #f9f9f9;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .card-management-section h2 {
            color: #333;
            margin-bottom: 20px;
            text-align: center;
        }
        .card-select-container {
            margin-bottom: 25px;
            text-align: center;
        }
        .card-select-container label {
            display: block;
            margin-bottom: 10px;
            font-weight: bold;
            color: #555;
        }
        .card-select-container select {
            width: 80%;
            max-width: 400px;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 1em;
        }
        .pin-form-container {
            background-color: #e6f7ff; /* Light blue background */
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #b3e0ff;
        }
        .pin-form-container h3 {
            text-align: center;
            color: #007bff;
            margin-bottom: 20px;
        }
        .pin-form-container .form-group {
            margin-bottom: 15px;
        }
        .pin-form-container label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .pin-form-container input[type="password"] {
            width: calc(100% - 22px); /* Account for padding and border */
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 1.1em;
            text-align: center;
            letter-spacing: 2px;
        }
        .pin-form-container button {
            display: block;
            width: 100%;
            padding: 12px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 1.1em;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        .pin-form-container button:hover {
            background-color: #0056b3;
        }
        .message.success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; padding: 10px; border-radius: 5px; margin-bottom: 15px; text-align: center; }
        .message.error { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; padding: 10px; border-radius: 5px; margin-bottom: 15px; text-align: center; }
        .message.info { color: #0c5460; background-color: #d1ecf1; border-color: #bee5eb; padding: 10px; border-radius: 5px; margin-bottom: 15px; text-align: center; }

    </style>
</head>
<body>
    <header class="header">
        <div class="logo">
            <a href="user-dashboard.php">
                <img src="https://i.imgur.com/YmC3kg3.png" alt="Hometown Bank PA Logo">
            </a>
        </div>
        <h1>Card Activation & PIN Management</h1>
        <nav class="header-nav">
            <a href="bank_cards.php" class="back-to-dashboard">
                <i class="fas fa-arrow-left"></i> Back to Manage Cards
            </a>
        </nav>
    </header>

    <main class="main-content">
        <section class="card-management-section">
            <?php if (!empty($message)): ?>
                <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <h2>Activate Your Card or Reset PIN</h2>
            <?php if (empty($cards_to_manage)): ?>
                <p class="no-data-message">You do not have any cards to manage at this time. <a href="bank_cards.php">Order a new card</a>.</p>
            <?php else: ?>
                <form action="activate_card.php" method="POST" class="pin-form-container">
                    <div class="card-select-container">
                        <label for="card_id">Select a Card:</label>
                        <select id="card_id" name="card_id" required>
                            <option value="">-- Select a Card --</option>
                            <?php foreach ($cards_to_manage as $card): ?>
                                <option value="<?php echo htmlspecialchars($card['id']); ?>"
                                    <?php echo (isset($_POST['card_id']) && $_POST['card_id'] == $card['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($card['card_network'] . ' ' . $card['card_type'] . ' (' . $card['display_card_number'] . ') - ' . ($card['is_active'] ? 'Active' : 'Inactive')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small>Only inactive cards will be activated upon PIN setting.</small>
                    </div>

                    <div class="form-group">
                        <label for="new_pin">Enter New 4-Digit PIN:</label>
                        <input type="password" id="new_pin" name="new_pin" minlength="4" maxlength="4" pattern="[0-9]{4}" placeholder="••••" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_pin">Confirm New 4-Digit PIN:</label>
                        <input type="password" id="confirm_pin" name="confirm_pin" minlength="4" maxlength="4" pattern="[0-9]{4}" placeholder="••••" required>
                    </div>

                    <input type="hidden" name="action" value="set_pin">
                    <button type="submit"><i class="fas fa-lock" style="margin-right: 8px;"></i> Activate Card & Set PIN</button>
                </form>
            <?php endif; ?>
        </section>
    </main>

</body>
</html>