<?php
// create_user.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/audit.php';
require_once '../includes/permissions.php';
require_permission($conn, 'manage_users');

$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    include '../header.php';
}

// Initialize message variable and message type
$message = "";
$message_type = ""; // Will be "success" or "error"

// A manage_users-only actor may create ordinary accounts, but not ones
// holding a privileged role - otherwise they could mint themselves a fresh
// admin account with the known default password.
$canEditPrivileges = user_can($conn, (int) $_SESSION['user_id'], 'manage_role_permissions');
$roles = get_all_roles($conn);
if (!$canEditPrivileges) {
    $roles = array_values(array_filter($roles, fn($r) => !role_is_privileged($conn, $r['role_key'])));
}
$assignableRoleKeys = array_column($roles, 'role_key');

if ($_SERVER['REQUEST_METHOD'] === "POST") {
    // Retrieve and sanitize form inputs
    $first_name = trim($_POST['first_name']);
    $last_name  = trim($_POST['last_name']);
    $email      = trim($_POST['email']);
    $role_new   = trim($_POST['role']);
    $default_password = "Password1!";    // Default password

    // Basic validation
    if (empty($first_name) || empty($last_name) || empty($email) || empty($role_new)) {
        $message = "Please fill in all fields.";
        $message_type = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email address.";
        $message_type = "error";
    } elseif (!in_array($role_new, $assignableRoleKeys, true)) {
        $message = "Invalid role.";
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
                apply_role_default_permissions($conn, $newUserId, $role_new);
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

    <div class="form-card">
        <p class="note">New accounts are created with the default password <strong>Password1!</strong> - the user
            should change it after their first login.</p>

        <form method="post" action="create_user.php<?php echo $embedded ? '?embedded=1' : ''; ?>">
            <div class="field-row">
                <div>
                    <label for="first_name">First Name</label>
                    <input type="text" name="first_name" id="first_name" required>
                </div>
                <div>
                    <label for="last_name">Last Name</label>
                    <input type="text" name="last_name" id="last_name" required>
                </div>
            </div>

            <label for="email">Email Address</label>
            <input type="email" name="email" id="email" required>

            <label for="role">User Role</label>
            <select name="role" id="role" required>
                <option value="">Select Role</option>
                <?php foreach ($roles as $r): ?>
                <option value="<?php echo htmlspecialchars($r['role_key']); ?>">
                    <?php echo htmlspecialchars($r['label']); ?></option>
                <?php endforeach; ?>
            </select>

            <div class="btn-row">
                <input type="submit" value="Create User">
                <?php if (!$embedded): ?>
                <a href="cq_dashboard.php" class="cancel-btn">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<style>
.content-wrapper {
    max-width: 700px;
    margin: <?php echo $embedded ? '0' : '80px'; ?> 20px 0 20px;
    padding: 20px;
}

.form-card {
    background: var(--polaris-surface);
    border-radius: 8px;
    padding: 20px 25px;
    box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
}

.note {
    background: var(--polaris-divider);
    border-left: 4px solid var(--polaris-accent);
    color: var(--polaris-text-secondary);
    padding: 10px 12px;
    border-radius: 3px;
    font-size: 13px;
    margin: 0 0 20px 0;
}

.field-row {
    display: flex;
    gap: 15px;
}

.field-row>div {
    flex: 1;
}

label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
    color: var(--polaris-text-dim);
    font-size: 14px;
}

input[type="text"],
input[type="email"],
select {
    width: 100%;
    padding: 8px;
    margin-bottom: 15px;
    border: 1px solid var(--polaris-border);
    border-radius: 4px;
    background: var(--polaris-bg);
    color: var(--polaris-text);
    box-sizing: border-box;
}

.btn-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 5px;
}

input[type="submit"] {
    background: var(--polaris-success-strong);
    color: var(--polaris-text);
    padding: 6px 14px;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    font-size: 14px;
}

input[type="submit"]:hover {
    background: var(--polaris-success-strong-hover);
}

.cancel-btn {
    color: var(--polaris-text-secondary);
    text-decoration: none;
    font-size: 14px;
}

.cancel-btn:hover {
    color: var(--polaris-text);
    text-decoration: underline;
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