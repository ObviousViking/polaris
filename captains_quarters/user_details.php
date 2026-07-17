<?php
// user_details.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/audit.php';

// Only admins/super users may view or edit other accounts.
$role_stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$role_stmt->bind_param("i", $_SESSION['user_id']);
$role_stmt->execute();
$role_stmt->bind_result($requesting_role);
$role_stmt->fetch();
$role_stmt->close();

if ($requesting_role !== 'admin' && $requesting_role !== 'super') {
    header("Location: ../dashboard.php");
    exit();
}

// Get user ID from URL
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Initialize message variables
$message = "";
$message_type = "";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name']);
    $last_name  = trim($_POST['last_name']);
    $email      = trim($_POST['email']);
    $role       = trim($_POST['role']);
    $active     = (int)$_POST['active'];

    // If this is the super user (id = 1), force role to remain 'super'
    if ($user_id === 1) {
        $role = 'super';
    }

    // Basic validation
    if (empty($first_name) || empty($last_name) || empty($email) || empty($role)) {
        $message = "Please fill in all fields.";
        $message_type = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email address.";
        $message_type = "error";
    } else {
        // Update user in database
        $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, role = ?, active = ? WHERE id = ?");
        $stmt->bind_param("ssssii", $first_name, $last_name, $email, $role, $active, $user_id);
        if ($stmt->execute()) {
            log_audit_event($conn, 'user', $user_id, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['first_name' => $first_name, 'last_name' => $last_name, 'email' => $email, 'role' => $role, 'active' => $active]));
            $message = "User updated successfully.";
            $message_type = "success";
        } else {
            $message = "Error updating user.";
            $message_type = "error";
        }
        $stmt->close();
    }
}

// Fetch user details
$stmt = $conn->prepare("SELECT first_name, last_name, email, role, active FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo "User not found.";
    exit();
}

// Check if this is the super user
$is_super_user = ($user_id === 1);

$userTheme = "dark";
if ($stmt = $conn->prepare("SELECT theme FROM users WHERE id = ? LIMIT 1")) {
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $stmt->bind_result($userTheme);
    $stmt->fetch();
    $stmt->close();
}
?>

<!DOCTYPE html>
<html<?php echo $userTheme === 'light' ? ' data-theme="light"' : ''; ?>>

<head>
    <meta charset="UTF-8">
    <title>Edit User</title>
    <link rel="stylesheet" href="/assets/theme.css">
</head>

<body>
    <h3>Edit User</h3>
    <?php if ($message): ?>
    <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <form method="post">
        <div>
            <label>First Name:</label>
            <input type="text" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>">
        </div>
        <div>
            <label>Last Name:</label>
            <input type="text" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>">
        </div>
        <div>
            <label>Email:</label>
            <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>">
        </div>
        <div>
            <label>Role:</label>
            <select name="role" <?php echo $is_super_user ? 'disabled' : ''; ?>>
                <?php if ($is_super_user): ?>
                <option value="super" selected>Super</option>
                <?php else: ?>
                <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>>User</option>
                <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                <?php endif; ?>
            </select>
            <?php if ($is_super_user): ?>
            <input type="hidden" name="role" value="super"> <!-- Ensure role is sent as 'super' -->
            <?php endif; ?>
        </div>
        <div>
            <label>Status:</label>
            <select name="active">
                <option value="1" <?php echo $user['active'] ? 'selected' : ''; ?>>Active</option>
                <option value="0" <?php echo !$user['active'] ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </div>
        <input type="submit" value="Save Changes">
    </form>

    <style>
    body {
        background-color: var(--polaris-bg-alt);
        color: var(--polaris-text);
        font-family: Arial, sans-serif;
        padding: 20px;
        margin: 0;
    }

    h3 {
        color: var(--polaris-text);
        margin-top: 0;
    }

    form div {
        margin-bottom: 15px;
    }

    label {
        display: block;
        font-weight: bold;
        color: var(--polaris-gray-light-2);
    }

    input[type="text"],
    input[type="email"],
    select {
        width: 100%;
        padding: 8px;
        border: 1px solid var(--polaris-border);
        border-radius: 4px;
        background-color: var(--polaris-divider);
        color: var(--polaris-text);
        box-sizing: border-box;
    }

    input[type="submit"] {
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border: none;
        padding: 5px 10px;
        border-radius: 3px;
        cursor: pointer;
        font-size: 14px;
    }

    input[type="submit"]:hover {
        background: var(--polaris-accent-hover);
    }

    .message.success {
        color: var(--polaris-alert-success-text);
        background-color: var(--polaris-alert-success-bg);
        border: 1px solid var(--polaris-alert-success-border);
        padding: 10px;
        border-radius: 4px;
        margin-bottom: 15px;
    }

    .message.error {
        color: var(--polaris-alert-danger-text);
        background-color: var(--polaris-alert-danger-bg);
        border: 1px solid var(--polaris-alert-danger-border);
        padding: 10px;
        border-radius: 4px;
        margin-bottom: 15px;
    }
    </style>
</body>

</html>