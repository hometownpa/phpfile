<?php
session_start();
require_once '../../Config.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ../index.php'); // Corrected redirect to admin login page
    exit;
}

$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$user_data = null;
$accounts_data = []; // Initialize array to hold account data
$message = '';
$message_type = '';

// Re-fetch message if it came from a redirect (e.g., from manage_users.php after delete)
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = htmlspecialchars($_GET['message']);
    $message_type = htmlspecialchars($_GET['type']);
}

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn === false) {
    die("ERROR: Could not connect to database. " . mysqli_connect_error());
}

// Function to fetch user and account data (can be called initially and after update)
function fetchUserData($conn, $user_id) {
    $userData = null;
    $accountsData = [];

    // Fetch user data for pre-filling the form
    $stmt = mysqli_prepare($conn, "SELECT id, first_name, last_name, email, home_address, phone_number, nationality, date_of_birth, gender, occupation, membership_number, profile_image, created_at FROM users WHERE id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $userData = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
    }

    // Fetch associated account data if user found
    if ($userData) {
        $stmt_accounts = mysqli_prepare($conn, "SELECT id, user_id, account_type, account_number, balance, currency, sort_code, iban, swift_bic FROM accounts WHERE user_id = ? ORDER BY account_type ASC");
        if ($stmt_accounts) {
            mysqli_stmt_bind_param($stmt_accounts, "i", $user_id);
            mysqli_stmt_execute($stmt_accounts);
            $result_accounts = mysqli_stmt_get_result($stmt_accounts);
            while ($row = mysqli_fetch_assoc($result_accounts)) {
                $accountsData[] = $row;
            }
            mysqli_stmt_close($stmt_accounts);
        }
    }
    return ['user_data' => $userData, 'accounts_data' => $accountsData];
}

// Fetch data initially
$fetched_data = fetchUserData($conn, $user_id);
$user_data = $fetched_data['user_data'];
$accounts_data = $fetched_data['accounts_data'];

if (!$user_data && $user_id > 0 && empty($message)) {
    // If user_data is still null and no error message yet, means ID was invalid initially or user not found
    $message = "No user found with the provided ID.";
    $message_type = 'error';
    $user_id = 0; // Invalidate user_id if not found, to prevent form display
}

// Handle form submission for updating user and accounts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user_id > 0) {
    // Collect updated user data
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $home_address = trim($_POST['home_address'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $nationality = trim($_POST['nationality'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $occupation = trim($_POST['occupation'] ?? '');
    $membership_number = trim($_POST['membership_number'] ?? '');
    $admin_created_at = trim($_POST['admin_created_at'] ?? '');
    $new_password = $_POST['new_password'] ?? '';

    $old_profile_image = $user_data['profile_image'] ?? null; // Get current image path to potentially delete it

    // Collect updated account data
    $submitted_accounts = $_POST['accounts'] ?? []; // This will be an array of arrays

    // --- Validation ---
    // Basic user data validation
    if (empty($first_name) || empty($last_name) || empty($email) || empty($home_address) || empty($phone_number) || empty($nationality) || empty($date_of_birth) || empty($gender) || empty($occupation) || empty($membership_number) || empty($admin_created_at)) {
        $message = 'All user fields (except new password and profile image) are required.';
        $message_type = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Invalid email format.';
        $message_type = 'error';
    } elseif (!empty($new_password) && strlen($new_password) < 6) {
        $message = 'New password must be at least 6 characters long.';
        $message_type = 'error';
    } elseif (!in_array($gender, ['Male', 'Female', 'Other'])) {
        $message = 'Invalid gender selected.';
        $message_type = 'error';
    } elseif (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $date_of_birth) || !strtotime($date_of_birth)) {
        $message = 'Invalid Date of Birth format. Please use YYYY-MM-DD.';
        $message_type = 'error';
    } elseif (!DateTime::createFromFormat('Y-m-d\TH:i', $admin_created_at)) {
        $message = 'Invalid "Created At" date/time format. Please use YYYY-MM-DDTHH:MM (e.g., 2025-07-01T14:30).';
        $message_type = 'error';
    } else {
        // Validation for account data
        $account_validation_error = false;
        foreach ($submitted_accounts as $index => $account) {
            $acc_type = trim($account['account_type'] ?? '');
            $acc_number = trim($account['account_number'] ?? '');
            $balance = floatval($account['balance'] ?? 0);
            $currency = trim($account['currency'] ?? '');
            $sort_code = trim($account['sort_code'] ?? '');
            $iban = trim($account['iban'] ?? '');
            $swift_bic = trim($account['swift_bic'] ?? '');

            if (empty($acc_type) || empty($acc_number) || empty($currency)) {
                $message = "Account " . ($index + 1) . ": Account type, number, and currency are required.";
                $message_type = 'error';
                $account_validation_error = true;
                break;
            }
            if (!is_numeric($balance) || $balance < 0) {
                $message = "Account " . ($index + 1) . ": Balance must be a non-negative number.";
                $message_type = 'error';
                $account_validation_error = true;
                break;
            }
            if (!in_array($currency, ['USD', 'EUR', 'GBP', 'NGN'])) { // Adjust allowed currencies as needed
                $message = "Account " . ($index + 1) . ": Invalid currency selected.";
                $message_type = 'error';
                $account_validation_error = true;
                break;
            }
            // Add specific validation for IBAN/SWIFT/Sort Code if needed (e.g., regex patterns)
            if ($currency === 'EUR' && empty($iban)) {
                $message = "Account " . ($index + 1) . ": IBAN is required for EUR accounts.";
                $message_type = 'error';
                $account_validation_error = true;
                break;
            }
            if ($currency === 'GBP' && empty($sort_code)) {
                $message = "Account " . ($index + 1) . ": Sort Code is required for GBP accounts.";
                $message_type = 'error';
                $account_validation_error = true;
                break;
            }
        }

        if (!$account_validation_error) {
            // --- Handle Profile Image Upload (if new image is provided) ---
            $profile_image_path = $old_profile_image; // Default to existing image
            $image_upload_success = true;

            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $file_tmp_path = $_FILES['profile_image']['tmp_name'];
                $file_name = $_FILES['profile_image']['name'];
                $file_size = $_FILES['profile_image']['size'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
                if (!in_array($file_ext, $allowed_ext)) {
                    $message = 'Invalid image file type. Only JPG, JPEG, PNG, GIF are allowed.';
                    $message_type = 'error';
                    $image_upload_success = false;
                } elseif ($file_size > 5 * 1024 * 1024) { // 5MB limit
                    $message = 'Image file size exceeds 5MB limit.';
                    $message_type = 'error';
                    $image_upload_success = false;
                } else {
                    define('UPLOAD_DIR', '../../uploads/profile_images/');
                    if (!is_dir(UPLOAD_DIR)) { mkdir(UPLOAD_DIR, 0777, true); }

                    $new_file_name = uniqid('profile_', true) . '.' . $file_ext;
                    $target_file_path = UPLOAD_DIR . $new_file_name;

                    if (move_uploaded_file($file_tmp_path, $target_file_path)) {
                        $profile_image_path = 'uploads/profile_images/' . $new_file_name;
                        // Delete old image if a new one was uploaded successfully
                        if ($old_profile_image && file_exists('../../' . $old_profile_image)) {
                            unlink('../../' . $old_profile_image);
                        }
                    } else {
                        $message = 'Failed to upload new profile image.';
                        $message_type = 'error';
                        $image_upload_success = false;
                    }
                }
            }

            // Proceed only if image upload was successful or no image was provided/changed
            if ($image_upload_success) {
                // --- Start Transaction ---
                mysqli_autocommit($conn, FALSE);
                $transaction_success = true;

                // 1. Update User Details
                $sql = "UPDATE users SET first_name=?, last_name=?, email=?, home_address=?, phone_number=?, nationality=?, date_of_birth=?, gender=?, occupation=?, membership_number=?, profile_image=?, created_at=? ";
                $params = "ssssssssssss";

                $values = [
                    &$first_name, &$last_name, &$email, &$home_address, &$phone_number,
                    &$nationality, &$date_of_birth, &$gender, &$occupation, &$membership_number, &$profile_image_path, &$admin_created_at
                ];

                if (!empty($new_password)) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $sql .= ", password_hash=? ";
                    $params .= "s";
                    $values[] = &$hashed_password;
                }

                $sql .= "WHERE id = ?";
                $params .= "i";
                $values[] = &$user_id;

                $stmt_user = mysqli_prepare($conn, $sql);
                if ($stmt_user) {
                    // Using call_user_func_array for dynamic bind_param due to optional password
                    call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmt_user, $params], $values));
                    if (!mysqli_stmt_execute($stmt_user)) {
                        if (mysqli_errno($conn) == 1062) { // Duplicate entry error code
                            $message = "Error updating user: Email address already exists for another user.";
                        } else {
                            $message = "Error updating user: " . mysqli_error($conn);
                        }
                        $message_type = 'error';
                        $transaction_success = false;
                    }
                    mysqli_stmt_close($stmt_user);
                } else {
                    $message = "Database query preparation failed for user update: " . mysqli_error($conn);
                    $message_type = 'error';
                    $transaction_success = false;
                }

                // 2. Update/Insert Account Details (Only update for now, no add/delete from this form)
                if ($transaction_success) {
                    foreach ($submitted_accounts as $account) {
                        $account_id = intval($account['id'] ?? 0); // Get existing account ID
                        $acc_type = trim($account['account_type'] ?? '');
                        $acc_number = trim($account['account_number'] ?? '');
                        $balance = floatval($account['balance'] ?? 0);
                        $currency = trim($account['currency'] ?? '');
                        $sort_code = trim($account['sort_code'] ?? '');
                        $iban = trim($account['iban'] ?? '');
                        $swift_bic = trim($account['swift_bic'] ?? '');

                        // If sort_code, iban, swift_bic are empty, set them to NULL in DB
                        // This ensures consistency for optional fields
                        $sort_code = empty($sort_code) ? null : $sort_code;
                        $iban = empty($iban) ? null : $iban;
                        $swift_bic = empty($swift_bic) ? null : $swift_bic;

                        $stmt_account = mysqli_prepare($conn, "UPDATE accounts SET account_type=?, account_number=?, balance=?, currency=?, sort_code=?, iban=?, swift_bic=? WHERE id=? AND user_id=?");
                        if ($stmt_account) {
                            // The 's' for sort_code, iban, swift_bic should be 's' even if they are null, as bind_param handles null for string types.
                            mysqli_stmt_bind_param($stmt_account, "ssdssssii", $acc_type, $acc_number, $balance, $currency, $sort_code, $iban, $swift_bic, $account_id, $user_id);
                            if (!mysqli_stmt_execute($stmt_account)) {
                                $message = "Error updating account " . htmlspecialchars($acc_number) . ": " . mysqli_error($conn);
                                $message_type = 'error';
                                $transaction_success = false;
                                break; // Exit loop on first error
                            }
                            mysqli_stmt_close($stmt_account);
                        } else {
                            $message = "Database query preparation failed for account update: " . mysqli_error($conn);
                            $message_type = 'error';
                            $transaction_success = false;
                            break; // Exit loop on first error
                        }
                    }
                }

                // --- Finalize Transaction ---
                if ($transaction_success) {
                    mysqli_commit($conn);
                    $message = "User and account details updated successfully!";
                    $message_type = 'success';
                    // Re-fetch updated data to refresh the form with latest changes
                    $fetched_data = fetchUserData($conn, $user_id);
                    $user_data = $fetched_data['user_data'];
                    $accounts_data = $fetched_data['accounts_data'];

                } else {
                    mysqli_rollback($conn);
                    if (empty($message)) { // Fallback message if specific error wasn't set during account loop
                        $message = "Failed to update user or account details. Please try again.";
                    }
                }
                mysqli_autocommit($conn, TRUE); // Re-enable autocommit
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
    <title>Heritage Bank - Edit User</title>
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
            max-width: 900px; /* Adjusted max-width for forms */
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

        /* Form styling */
        .form-standard .form-group {
            margin-bottom: 15px;
        }

        .form-standard label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }

        .form-standard input[type="text"],
        .form-standard input[type="email"],
        .form-standard input[type="tel"],
        .form-standard input[type="date"],
        .form-standard input[type="datetime-local"],
        .form-standard input[type="number"],
        .form-standard input[type="password"],
        .form-standard select,
        .form-standard textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box; /* Include padding in width */
            font-size: 1em;
        }

        .form-standard button.button-primary {
            background-color: #007bff;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1.1em;
            font-weight: bold;
            transition: background-color 0.3s ease;
        }

        .form-standard button.button-primary:hover {
            background-color: #0056b3;
        }

        /* Image Preview */
        .profile-image-preview {
            max-width: 150px;
            height: auto;
            border: 1px solid #ddd;
            margin-top: 10px;
            display: block;
        }
        .form-standard .form-group small {
            color: #666;
            font-size: 0.85em;
            margin-top: 5px;
            display: block;
        }
        /* Account Section Styling */
        .account-section {
            border: 1px solid #e0e0e0; /* Lighter border */
            padding: 20px;
            margin-bottom: 25px;
            border-radius: 8px;
            background-color: #fcfcfc; /* Slightly lighter background */
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.05); /* subtle inner shadow */
        }
        .account-section h4 {
            margin-top: 0;
            margin-bottom: 18px; /* More space below heading */
            color: #004494; /* Match header blue */
            font-size: 1.3em;
            border-bottom: 1px solid #eee; /* Separator line */
            padding-bottom: 10px;
        }
        .account-section .form-group {
            margin-bottom: 15px; /* Consistent spacing */
        }
        .account-section .form-group label {
            color: #555;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #004494;
            text-decoration: none;
            font-weight: bold;
            transition: color 0.3s ease;
        }
        .back-link:hover {
            color: #0056b3;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <img src="../../images/logo.png" alt="Heritage Bank Logo" class="logo">
            <h2>Edit User</h2>
            <a href="../logout.php" class="logout-button">Logout</a>
        </div>

        <div class="dashboard-content">
            <?php if (!empty($message)): ?>
                <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <?php if ($user_data): ?>
            <form action="edit_user.php?id=<?php echo $user_id; ?>" method="POST" class="form-standard" enctype="multipart/form-data">
                <h3>User Information</h3>
                <div class="form-group">
                    <label for="id">User ID:</label>
                    <input type="text" id="id" name="id" value="<?php echo htmlspecialchars($user_data['id']); ?>" readonly>
                </div>
                <div class="form-group">
                    <label for="membership_number">Membership Number:</label>
                    <input type="text" id="membership_number" name="membership_number" value="<?php echo htmlspecialchars($user_data['membership_number']); ?>" required>
                    <small>Membership number can be updated.</small>
                </div>
                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user_data['first_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user_data['last_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="home_address">Home Address</label>
                    <textarea id="home_address" name="home_address" rows="3" required><?php echo htmlspecialchars($user_data['home_address']); ?></textarea>
                </div>
                <div class="form-group">
                    <label for="phone_number">Phone Number</label>
                    <input type="tel" id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($user_data['phone_number']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="nationality">Nationality</label>
                    <input type="text" id="nationality" name="nationality" value="<?php echo htmlspecialchars($user_data['nationality']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="date_of_birth">Date of Birth</label>
                    <input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars($user_data['date_of_birth']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="gender">Gender</label>
                    <select id="gender" name="gender" required>
                        <option value="">-- Select Gender --</option>
                        <option value="Male" <?php echo ($user_data['gender'] == 'Male') ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo ($user_data['gender'] == 'Female') ? 'selected' : ''; ?>>Female</option>
                        <option value="Other" <?php echo ($user_data['gender'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="occupation">Occupation</label>
                    <input type="text" id="occupation" name="occupation" value="<?php echo htmlspecialchars($user_data['occupation']); ?>" required>
                </div>

                <div class="form-group">
                    <label for="profile_image">Profile Image (Max 5MB, JPG, PNG, GIF)</label>
                    <input type="file" id="profile_image" name="profile_image" accept="image/*">
                    <?php if (!empty($user_data['profile_image'])): ?>
                        <p>Current Image:</p>
                        <img src="../../<?php echo htmlspecialchars($user_data['profile_image']); ?>" alt="Profile Image" class="profile-image-preview">
                    <?php else: ?>
                        <p>No profile image uploaded.</p>
                    <?php endif; ?>
                    <small>Upload a new image to replace the current one.</small>
                </div>

                <div class="form-group">
                    <label for="admin_created_at">Account Creation Date & Time</label>
                    <input type="datetime-local" id="admin_created_at" name="admin_created_at" value="<?php echo htmlspecialchars(date('Y-m-d\TH:i', strtotime($user_data['created_at']))); ?>" required>
                    <small>Set the exact date and time the user account was created.</small>
                </div>

                <div class="form-group">
                    <label for="new_password">New Password (leave blank to keep current)</label>
                    <input type="password" id="new_password" name="new_password" placeholder="Enter new password if changing">
                    <small>If you leave this blank, the user's password will not change.</small>
                </div>

                <hr style="margin: 30px 0;">

                <h3>Bank Accounts</h3>
                <?php if (empty($accounts_data)): ?>
                    <p>No bank accounts found for this user.</p>
                <?php else: ?>
                    <?php foreach ($accounts_data as $index => $account): ?>
                        <div class="account-section">
                            <h4>Account #<?php echo $index + 1; ?> (ID: <?php echo htmlspecialchars($account['id']); ?>) - <?php echo htmlspecialchars($account['account_type']); ?></h4>
                            <input type="hidden" name="accounts[<?php echo $index; ?>][id]" value="<?php echo htmlspecialchars($account['id']); ?>">

                            <div class="form-group">
                                <label for="account_type_<?php echo $index; ?>">Account Type:</label>
                                <select id="account_type_<?php echo $index; ?>" name="accounts[<?php echo $index; ?>][account_type]" required>
                                    <option value="">-- Select Account Type --</option>
                                    <option value="Checking" <?php echo ($account['account_type'] == 'Checking') ? 'selected' : ''; ?>>Checking</option>
                                    <option value="Savings" <?php echo ($account['account_type'] == 'Savings') ? 'selected' : ''; ?>>Savings</option>
                                    <option value="Current" <?php echo ($account['account_type'] == 'Current') ? 'selected' : ''; ?>>Current</option>
                                    <option value="Fixed Deposit" <?php echo ($account['account_type'] == 'Fixed Deposit') ? 'selected' : ''; ?>>Fixed Deposit</option>
                                    <option value="Loan" <?php echo ($account['account_type'] == 'Loan') ? 'selected' : ''; ?>>Loan</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="account_number_<?php echo $index; ?>">Account Number:</label>
                                <input type="text" id="account_number_<?php echo $index; ?>" name="accounts[<?php echo $index; ?>][account_number]" value="<?php echo htmlspecialchars($account['account_number']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="balance_<?php echo $index; ?>">Balance:</label>
                                <input type="number" step="0.01" id="balance_<?php echo $index; ?>" name="accounts[<?php echo $index; ?>][balance]" value="<?php echo htmlspecialchars($account['balance']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="currency_<?php echo $index; ?>">Currency:</label>
                                <select id="currency_<?php echo $index; ?>" name="accounts[<?php echo $index; ?>][currency]" required>
                                    <option value="">-- Select Currency --</option>
                                    <option value="USD" <?php echo ($account['currency'] == 'USD') ? 'selected' : ''; ?>>USD</option>
                                    <option value="EUR" <?php echo ($account['currency'] == 'EUR') ? 'selected' : ''; ?>>EUR</option>
                                    <option value="GBP" <?php echo ($account['currency'] == 'GBP') ? 'selected' : ''; ?>>GBP</option>
                                    <option value="NGN" <?php echo ($account['currency'] == 'NGN') ? 'selected' : ''; ?>>NGN</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="sort_code_<?php echo $index; ?>">Sort Code (e.g., 12-34-56 for GBP):</label>
                                <input type="text" id="sort_code_<?php echo $index; ?>" name="accounts[<?php echo $index; ?>][sort_code]" value="<?php echo htmlspecialchars($account['sort_code'] ?? ''); ?>">
                                <small>Required for GBP accounts.</small>
                            </div>
                            <div class="form-group">
                                <label for="iban_<?php echo $index; ?>">IBAN (e.g., for EUR accounts):</label>
                                <input type="text" id="iban_<?php echo $index; ?>" name="accounts[<?php echo $index; ?>][iban]" value="<?php echo htmlspecialchars($account['iban'] ?? ''); ?>">
                                <small>Required for EUR accounts.</small>
                            </div>
                            <div class="form-group">
                                <label for="swift_bic_<?php echo $index; ?>">SWIFT/BIC:</label>
                                <input type="text" id="swift_bic_<?php echo $index; ?>" name="accounts[<?php echo $index; ?>][swift_bic]" value="<?php echo htmlspecialchars($account['swift_bic'] ?? ''); ?>">
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <button type="submit" class="button-primary">Update User & Accounts</button>
            </form>
            <?php endif; ?>

            <p><a href="manage_users.php" class="back-link">&larr; Back to Manage Users</a></p>
        </div>
    </div>
    <script src="../script.js"></script>
</body>
</html>