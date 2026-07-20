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
$editableRoles = ['user', 'admin'];

$message = "";
$message_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
}

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
?>

<div class="content-wrapper">
    <h2>Manage Role Permissions</h2>
    <p class="page-note">Set the default permission bundle each role gets. Changing a role's defaults here only
        affects users assigned to that role afterward - it does not retroactively change permissions already
        granted to existing users.</p>

    <?php if ($message): ?>
    <p class="message <?php echo $message_type; ?>"><?php echo $message; ?></p>
    <?php endif; ?>

    <div class="role-tabs">
        <?php foreach ($editableRoles as $i => $r): ?>
        <button type="button" class="role-tab-btn <?php echo $i === 0 ? 'active' : ''; ?>"
            data-role="<?php echo htmlspecialchars($r); ?>"><?php echo ucfirst(htmlspecialchars($r)); ?></button>
        <?php endforeach; ?>
    </div>

    <?php foreach ($editableRoles as $i => $r): ?>
    <form method="post" class="role-panel" data-role-panel="<?php echo htmlspecialchars($r); ?>"
        style="<?php echo $i === 0 ? '' : 'display:none;'; ?>">
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
        <input type="submit" value="Save '<?php echo htmlspecialchars(ucfirst($r)); ?>' Defaults">
    </form>
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
