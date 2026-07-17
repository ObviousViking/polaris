<?php
// create_user.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/audit.php';

// Check that the user has admin privileges (allow admin or super)
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($role);
$stmt->fetch();
$stmt->close();

if ($role !== 'admin' && $role !== 'super') {
    header("Location: ../dashboard.php");
    exit();
}

$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    include '../header.php';
}

// Initialize message variable and message type
$message = "";
$message_type = ""; // Will be "success" or "error"

if ($_SERVER['REQUEST_METHOD'] === "POST") {
    // Retrieve and sanitize form inputs
    $first_name = trim($_POST['first_name']);
    $last_name  = trim($_POST['last_name']);
    $email      = trim($_POST['email']);
    $role_new   = trim($_POST['role']);  // Expected values: user or admin
    $default_password = "Password1!";    // Default password

    // Basic validation
    if (empty($first_name) || empty($last_name) || empty($email) || empty($role_new)) {
        $message = "Please fill in all fields.";
        $message_type = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email address.";
        $message_type = "error";
    } else {
        // Check if the email is already registered
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $message = "Email already registered.";
            $message_type = "error";
        } else {
            // Hash the default password
            $hashed_password = password_hash($default_password, PASSWORD_DEFAULT);
            // Insert the new user
            $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, password, role) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $first_name, $last_name, $email, $hashed_password, $role_new);
            if ($stmt->execute()) {
                $newUserId = $conn->insert_id;
                log_audit_event($conn, 'user', $newUserId, 'CREATE', (int) $_SESSION['user_id'], json_encode(['first_name' => $first_name, 'last_name' => $last_name, 'email' => $email, 'role' => $role_new]));
                $message = "User created successfully with default password: Password1!";
                $message_type = "success";
            } else {
                $message = "Error creating user.";
                $message_type = "error";
            }
        }
        $stmt->close();
    }
}
?>

<div class="content-wrapper">
    <h2>Create New User</h2>
    <?php if ($message): ?>
    <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <form method="post" action="create_user.php">
        <div>
            <label for="first_name">First Name:</label>
            <input type="text" name="first_name" id="first_name" required>
        </div>
        <div>
            <label for="last_name">Last Name:</label>
            <input type="text" name="last_name" id="last_name" required>
        </div>
        <div>
            <label for="email">Email Address:</label>
            <input type="email" name="email" id="email" required>
        </div>
        <div>
            <label for="role">User Role:</label>
            <select name="role" id="role" required>
                <option value="">Select Role</option>
                <option value="user">User</option>
                <option value="admin">Admin</option>
            </select>
        </div>
        <div>
            <input type="submit" value="Create User">
        </div>
    </form>
</div>

<style>
.content-wrapper {
    max-width: 600px;
    margin: <?php echo $embedded ? '0' : '80px'; ?> auto 0 auto;
    padding: 20px;
}

form div {
    margin-bottom: 15px;
}

label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
}

input[type="text"],
input[type="email"],
select {
    width: 100%;
    padding: 8px;
    border: 1px solid var(--polaris-text-secondary);
    border-radius: 4px;
}

input[type="submit"] {
    background: var(--polaris-success-strong);
    color: var(--polaris-text);
    padding: 5px 10px;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    font-size: 14px;
}

input[type="submit"]:hover {
    background: var(--polaris-success-strong-hover);
}

.message.success {
    color: var(--polaris-alert-success-text);
    background-color: var(--polaris-alert-success-bg);
    border: 1px solid var(--polaris-alert-success-border);
    padding: 10px;
    border-radius: 4px;
}

.message.error {
    color: var(--polaris-alert-danger-text);
    background-color: var(--polaris-alert-danger-bg);
    border: 1px solid var(--polaris-alert-danger-border);
    padding: 10px;
    border-radius: 4px;
}
</style>

</body>

</html>