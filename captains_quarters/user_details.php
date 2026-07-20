<?php
// user_details.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/audit.php';
require_once '../includes/permissions.php';
require_permission($conn, 'manage_users');

// Get user ID from URL
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Look up the role as currently stored, so we can tell if it's changing and
// so we can identify the super user by role rather than a hardcoded id.
$old_role = null;
$oldRoleStmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$oldRoleStmt->bind_param("i", $user_id);
$oldRoleStmt->execute();
$oldRoleStmt->bind_result($old_role);
$oldRoleStmt->fetch();
$oldRoleStmt->close();

$is_super_user = ($old_role === 'super');

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

    // The super user's role can never be changed away from 'super'.
    if ($is_super_user) {
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

            // Super's access is hardcoded and not stored as explicit rows.
            if (!$is_super_user) {
                // Assigning a (changed) role lands a fresh one-time snapshot
                // of that role's current default bundle.
                if ($role !== $old_role) {
                    apply_role_default_permissions($conn, $user_id, $role);
                }

                // Apply the submitted checkbox state as overrides on top -
                // grant anything newly checked, revoke anything newly unchecked.
                $validKeys = array_column(PERMISSION_DEFINITIONS, 'key');
                $submittedKeys = array_values(array_intersect($_POST['permissions'] ?? [], $validKeys));
                $currentKeys = get_user_permission_keys($conn, $user_id);

                $toGrant = array_diff($submittedKeys, $currentKeys);
                $toRevoke = array_diff($currentKeys, $submittedKeys);

                if ($toGrant) {
                    $grantStmt = $conn->prepare("INSERT IGNORE INTO user_permissions (user_id, permission_key) VALUES (?, ?)");
                    foreach ($toGrant as $key) {
                        $grantStmt->bind_param("is", $user_id, $key);
                        $grantStmt->execute();
                    }
                    $grantStmt->close();
                }
                if ($toRevoke) {
                    $revokeStmt = $conn->prepare("DELETE FROM user_permissions WHERE user_id = ? AND permission_key = ?");
                    foreach ($toRevoke as $key) {
                        $revokeStmt->bind_param("is", $user_id, $key);
                        $revokeStmt->execute();
                    }
                    $revokeStmt->close();
                }
            }

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

$permissionGroups = get_all_permissions_grouped($conn);
$grantedPermissionKeys = $is_super_user ? [] : get_user_permission_keys($conn, $user_id);

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

        <div>
            <label>Permissions:</label>
            <?php if ($is_super_user): ?>
            <p class="permissions-note">Super users have unrestricted access to everything. This cannot be edited.</p>
            <?php else: ?>
            <p class="permissions-note">Changing the role above and saving will reset these to that role's default
                bundle before applying any changes made here.</p>
            <div class="permissions-grid">
                <?php foreach ($permissionGroups as $category => $perms): ?>
                <div class="permissions-category">
                    <h4><?php echo htmlspecialchars($category); ?></h4>
                    <?php foreach ($perms as $perm): ?>
                    <label class="permission-item">
                        <input type="checkbox" name="permissions[]"
                            value="<?php echo htmlspecialchars($perm['permission_key']); ?>" <?php echo in_array($perm['permission_key'], $grantedPermissionKeys, true) ? 'checked' : ''; ?>>
                        <span class="permission-label"><?php echo htmlspecialchars($perm['label']); ?></span>
                        <?php if (!empty($perm['description'])): ?>
                        <span class="permission-desc"><?php echo htmlspecialchars($perm['description']); ?></span>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
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

    .permissions-note {
        font-weight: normal;
        color: var(--polaris-gray-light-2);
        margin: 0 0 10px 0;
        font-size: 13px;
    }

    .permissions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 15px;
    }

    .permissions-category {
        background: var(--polaris-divider);
        border: 1px solid var(--polaris-border);
        border-radius: 4px;
        padding: 10px 12px;
    }

    .permissions-category h4 {
        margin: 0 0 8px 0;
        color: var(--polaris-text);
        font-size: 14px;
    }

    .permission-item {
        display: block;
        font-weight: normal;
        margin-bottom: 8px;
        cursor: pointer;
    }

    .permission-item input[type="checkbox"] {
        width: auto;
        margin-right: 6px;
    }

    .permission-label {
        color: var(--polaris-text);
    }

    .permission-desc {
        display: block;
        margin-left: 20px;
        font-size: 12px;
        color: var(--polaris-gray-light-2);
    }
    </style>
</body>

</html>