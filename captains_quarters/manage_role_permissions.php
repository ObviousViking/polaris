<?php
// manage_role_permissions.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/audit.php';
require_once '../includes/permissions.php';
require_permission($conn, 'manage_role_permissions');

$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    include '../header.php';
}

// super is hardcoded and bypasses every check - its bundle isn't stored or editable.
$roles = get_all_roles($conn);
$editableRoles = array_column($roles, 'role_key');

$message = "";
$message_type = "";
$activeRole = $editableRoles[0] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_permissions';

    if ($action === 'create_role') {
        $newKey = strtolower(trim($_POST['new_role_key'] ?? ''));
        $newLabel = trim($_POST['new_role_label'] ?? '');
        $error = create_role($conn, $newKey, $newLabel);
        if ($error) {
            $message = $error;
            $message_type = "error";
        } else {
            log_audit_event($conn, 'roles', 0, 'CREATE', (int) $_SESSION['user_id'], json_encode(['role_key' => $newKey, 'label' => $newLabel]));
            $message = "Role '" . htmlspecialchars($newLabel) . "' created. Set its default permissions below.";
            $message_type = "success";
            $roles = get_all_roles($conn);
            $editableRoles = array_column($roles, 'role_key');
        }
        $activeRole = $newKey;
    } elseif ($action === 'delete_role') {
        $roleToDelete = trim($_POST['role'] ?? '');
        $error = delete_role($conn, $roleToDelete);
        if ($error) {
            $message = $error;
            $message_type = "error";
            $activeRole = $roleToDelete;
        } else {
            log_audit_event($conn, 'roles', 0, 'DELETE', (int) $_SESSION['user_id'], json_encode(['role_key' => $roleToDelete]));
            $message = "Role '" . htmlspecialchars($roleToDelete) . "' deleted.";
            $message_type = "success";
            $roles = get_all_roles($conn);
            $editableRoles = array_column($roles, 'role_key');
            $activeRole = $editableRoles[0] ?? '';
        }
    } else {
        $role = trim($_POST['role'] ?? '');
        if (!in_array($role, $editableRoles, true)) {
            $message = "Invalid role.";
            $message_type = "error";
        } else {
            $validKeys = array_column(PERMISSION_DEFINITIONS, 'key');
            $submittedKeys = array_values(array_intersect($_POST['permissions'] ?? [], $validKeys));

            $conn->begin_transaction();
            $del = $conn->prepare("DELETE FROM role_default_permissions WHERE role = ?");
            $del->bind_param("s", $role);
            $del->execute();
            $del->close();

            if ($submittedKeys) {
                $ins = $conn->prepare("INSERT INTO role_default_permissions (role, permission_key) VALUES (?, ?)");
                foreach ($submittedKeys as $key) {
                    $ins->bind_param("ss", $role, $key);
                    $ins->execute();
                }
                $ins->close();
            }
            $conn->commit();

            log_audit_event($conn, 'role_default_permissions', 0, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['role' => $role, 'permissions' => $submittedKeys]));
            $message = "Default permissions for '" . htmlspecialchars($role) . "' updated. This does not change any existing user's already-granted permissions.";
            $message_type = "success";
        }
        $activeRole = $role;
    }
}

$roleLabels = array_column($roles, 'label', 'role_key');
$roleIsBuiltin = array_column($roles, 'is_builtin', 'role_key');

$permissionGroups = get_all_permissions_grouped($conn);

$roleDefaults = [];
foreach ($editableRoles as $r) {
    $roleDefaults[$r] = [];
    $stmt = $conn->prepare("SELECT permission_key FROM role_default_permissions WHERE role = ?");
    $stmt->bind_param("s", $r);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $roleDefaults[$r][] = $row['permission_key'];
    }
    $stmt->close();
}

if (!in_array($activeRole, $editableRoles, true)) {
    $activeRole = $editableRoles[0] ?? '';
}
?>

<div class="content-wrapper">
    <h2>Manage Role Permissions</h2>
    <p class="page-note">Set the default permission bundle each role gets. Changing a role's defaults here only
        affects users assigned to that role afterward - it does not retroactively change permissions already
        granted to existing users.</p>

    <?php if ($message): ?>
    <p class="message <?php echo $message_type; ?>"><?php echo $message; ?></p>
    <?php endif; ?>

    <form method="post" class="add-role-form">
        <input type="hidden" name="action" value="create_role">
        <label>New Role Key
            <input type="text" name="new_role_key" placeholder="e.g. case_officer" pattern="[a-z][a-z0-9_]{0,49}"
                title="Lowercase letters, numbers, and underscores only." required></label>
        <label>Display Label
            <input type="text" name="new_role_label" placeholder="e.g. Case Officer" required></label>
        <button type="submit">+ Add Role</button>
    </form>

    <div class="role-tabs">
        <?php foreach ($editableRoles as $r): ?>
        <button type="button" class="role-tab-btn <?php echo $r === $activeRole ? 'active' : ''; ?>"
            data-role="<?php echo htmlspecialchars($r); ?>"><?php echo htmlspecialchars($roleLabels[$r]); ?></button>
        <?php endforeach; ?>
    </div>

    <?php foreach ($editableRoles as $r): ?>
    <div class="role-panel" data-role-panel="<?php echo htmlspecialchars($r); ?>"
        style="<?php echo $r === $activeRole ? '' : 'display:none;'; ?>">
        <form method="post">
            <input type="hidden" name="action" value="save_permissions">
            <input type="hidden" name="role" value="<?php echo htmlspecialchars($r); ?>">
            <div class="permissions-grid">
                <?php foreach ($permissionGroups as $category => $perms): ?>
                <div class="permissions-category">
                    <h4><?php echo htmlspecialchars($category); ?></h4>
                    <?php foreach ($perms as $perm): ?>
                    <label class="permission-item">
                        <input type="checkbox" name="permissions[]"
                            value="<?php echo htmlspecialchars($perm['permission_key']); ?>" <?php echo in_array($perm['permission_key'], $roleDefaults[$r], true) ? 'checked' : ''; ?>>
                        <span class="permission-label"><?php echo htmlspecialchars($perm['label']); ?></span>
                        <?php if (!empty($perm['description'])): ?>
                        <span class="permission-desc"><?php echo htmlspecialchars($perm['description']); ?></span>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <input type="submit" value="Save '<?php echo htmlspecialchars($roleLabels[$r]); ?>' Defaults">
        </form>
        <?php if (empty($roleIsBuiltin[$r])): ?>
        <form method="post" class="delete-role-form"
            onsubmit="return confirm('Delete the &quot;<?php echo htmlspecialchars(addslashes($roleLabels[$r])); ?>&quot; role? This only works if no users currently have it.');">
            <input type="hidden" name="action" value="delete_role">
            <input type="hidden" name="role" value="<?php echo htmlspecialchars($r); ?>">
            <button type="submit" class="delete-role-btn">Delete '<?php echo htmlspecialchars($roleLabels[$r]); ?>'
                Role</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<style>
body {
    background-color: var(--polaris-bg-alt);
    color: var(--polaris-text);
}

.content-wrapper {
    max-width: 1000px;
    margin: <?php echo $embedded ? '0' : '80px'; ?> auto 0 auto;
    padding: 20px;
}

.page-note {
    color: var(--polaris-gray-light-2);
    font-size: 13px;
    max-width: 800px;
}

.add-role-form {
    display: flex;
    align-items: flex-end;
    gap: 15px;
    background: var(--polaris-surface);
    border: 1px solid var(--polaris-border);
    border-radius: 4px;
    padding: 12px 15px;
    margin-bottom: 20px;
}

.add-role-form label {
    display: flex;
    flex-direction: column;
    font-size: 12px;
    color: var(--polaris-gray-light-2);
    gap: 4px;
}

.add-role-form input[type="text"] {
    padding: 6px 8px;
    border-radius: 3px;
    border: 1px solid var(--polaris-border);
    background: var(--polaris-divider);
    color: var(--polaris-text);
}

.add-role-form button {
    background: var(--polaris-success-strong);
    color: var(--polaris-text);
    border: none;
    padding: 8px 14px;
    border-radius: 3px;
    cursor: pointer;
    font-size: 14px;
}

.add-role-form button:hover {
    background: var(--polaris-success-strong-hover);
}

.delete-role-form {
    margin-top: 10px;
}

.delete-role-btn {
    background: var(--polaris-error-bg);
    color: var(--polaris-error-text);
    border: none;
    padding: 8px 16px;
    border-radius: 3px;
    cursor: pointer;
    font-size: 14px;
}

.delete-role-btn:hover {
    background: var(--polaris-danger);
}

.role-tabs {
    display: flex;
    gap: 6px;
    margin-bottom: 15px;
}

.role-tab-btn {
    background: var(--polaris-divider);
    color: var(--polaris-text);
    border: 1px solid var(--polaris-border);
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
}

.role-tab-btn.active,
.role-tab-btn:hover {
    background: var(--polaris-accent);
}

.permissions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.permissions-category {
    background: var(--polaris-surface);
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

input[type="submit"] {
    background: var(--polaris-success-strong);
    color: var(--polaris-text);
    padding: 8px 16px;
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tabs = document.querySelectorAll('.role-tab-btn');
    const panels = document.querySelectorAll('[data-role-panel]');
    tabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            tabs.forEach(function(t) {
                t.classList.remove('active');
            });
            tab.classList.add('active');
            const role = tab.getAttribute('data-role');
            panels.forEach(function(panel) {
                panel.style.display = panel.getAttribute('data-role-panel') === role ? '' : 'none';
            });
        });
    });
});
</script>

</body>

</html>
