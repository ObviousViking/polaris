<?php
// includes/permissions.php
//
// Granular per-user permissions, layered on top of the existing users.role
// column. `super` bypasses every check unconditionally. For everyone else,
// access is whatever's in user_permissions - normally seeded from
// role_default_permissions when a role is assigned (see
// apply_role_default_permissions()), then freely customizable per user.

const PERMISSION_DEFINITIONS = [
    // Case Management
    ['key' => 'case_view', 'label' => 'View Cases', 'description' => 'Search for and view case details, history, and reports.', 'category' => 'Case Management', 'sort_order' => 10],
    ['key' => 'case_create', 'label' => 'Create Cases', 'description' => 'Create new cases.', 'category' => 'Case Management', 'sort_order' => 11],
    ['key' => 'case_edit', 'label' => 'Edit Cases', 'description' => 'Edit case details.', 'category' => 'Case Management', 'sort_order' => 12],
    ['key' => 'case_report', 'label' => 'Generate Case Reports', 'description' => 'Build and export printable case reports.', 'category' => 'Case Management', 'sort_order' => 13],
    ['key' => 'case_update_add', 'label' => 'Add Case Updates', 'description' => 'Post new case updates and communications.', 'category' => 'Case Management', 'sort_order' => 14],
    ['key' => 'case_update_edit', 'label' => 'Edit Case Updates', 'description' => 'Edit existing case updates.', 'category' => 'Case Management', 'sort_order' => 15],
    ['key' => 'case_update_delete', 'label' => 'Delete Case Updates', 'description' => 'Delete case updates.', 'category' => 'Case Management', 'sort_order' => 16],

    // Exhibit Management
    ['key' => 'exhibit_view', 'label' => 'View Exhibits', 'description' => 'Search for and view exhibit details.', 'category' => 'Exhibit Management', 'sort_order' => 20],
    ['key' => 'exhibit_create', 'label' => 'Add Exhibits', 'description' => 'Book in new exhibits and sub-exhibits.', 'category' => 'Exhibit Management', 'sort_order' => 21],
    ['key' => 'exhibit_edit', 'label' => 'Edit Exhibits', 'description' => 'Edit exhibit details and book exhibits out.', 'category' => 'Exhibit Management', 'sort_order' => 22],
    ['key' => 'exhibit_delete', 'label' => 'Delete Exhibits', 'description' => 'Delete and restore exhibits.', 'category' => 'Exhibit Management', 'sort_order' => 23],

    // Examinations
    ['key' => 'examination_view', 'label' => 'View Examinations', 'description' => 'View exhibit examination records and history.', 'category' => 'Examinations', 'sort_order' => 30],
    ['key' => 'examination_edit', 'label' => 'Edit Examinations', 'description' => 'Add and edit exhibit examination process data.', 'category' => 'Examinations', 'sort_order' => 31],

    // Documents & Photos
    ['key' => 'document_manage', 'label' => 'Manage Documents & Photos', 'description' => 'Upload, view, and download case and exhibit documents and photos.', 'category' => 'Documents & Photos', 'sort_order' => 40],

    // Asset Management
    ['key' => 'asset_view', 'label' => 'View Assets', 'description' => 'View the asset register and asset activity log.', 'category' => 'Asset Management', 'sort_order' => 50],
    ['key' => 'asset_manage', 'label' => 'Manage Assets', 'description' => 'Add, edit, check out, and log maintenance on assets.', 'category' => 'Asset Management', 'sort_order' => 51],

    // Lookup Data
    ['key' => 'manage_lookups', 'label' => 'Manage Lookup Data', 'description' => 'Create and edit case types, statuses, exhibit types, locations, customers, forces, and asset types/locations.', 'category' => 'Lookup Data', 'sort_order' => 60],
    ['key' => 'manage_lookups_delete', 'label' => 'Delete Lookup Data', 'description' => 'Delete or deactivate lookup data.', 'category' => 'Lookup Data', 'sort_order' => 61],

    // Tasking
    ['key' => 'task_view', 'label' => 'View Tasking', 'description' => 'View the tasking board.', 'category' => 'Tasking', 'sort_order' => 70],
    ['key' => 'task_manage', 'label' => 'Manage Tasks', 'description' => 'Create and edit tasks.', 'category' => 'Tasking', 'sort_order' => 71],

    // System Administration
    ['key' => 'manage_users', 'label' => 'Manage Users', 'description' => 'Create users and edit user roles/permissions.', 'category' => 'System Administration', 'sort_order' => 80],
    ['key' => 'manage_role_permissions', 'label' => 'Manage Role Permissions', 'description' => 'Edit the default permission bundle for each role.', 'category' => 'System Administration', 'sort_order' => 81],
    ['key' => 'manage_backup', 'label' => 'Backup & Restore', 'description' => 'Download full system backups and restore from a backup.', 'category' => 'System Administration', 'sort_order' => 82],
    ['key' => 'manage_settings', 'label' => 'Manage System Settings', 'description' => 'Configure deletion reasons, report branding, and SLA settings.', 'category' => 'System Administration', 'sort_order' => 83],
    ['key' => 'manage_processes', 'label' => 'Process Builder', 'description' => 'Define examination process templates and fields.', 'category' => 'System Administration', 'sort_order' => 84],
    ['key' => 'view_logs_integrity', 'label' => 'View Logs & Integrity', 'description' => 'View system activity logs and run integrity checks.', 'category' => 'System Administration', 'sort_order' => 85],
    ['key' => 'view_reports', 'label' => 'View System Reports', 'description' => 'View the system-wide reporting dashboard.', 'category' => 'System Administration', 'sort_order' => 86],
];

// Permissions the 'user' role gets by default - everything that's not
// currently gated to admin/super, so shipping this doesn't take away
// anything a normal user could already do.
const USER_ROLE_DEFAULT_PERMISSIONS = [
    'case_view', 'case_create', 'case_edit', 'case_report', 'case_update_add', 'case_update_edit',
    'exhibit_view', 'exhibit_create', 'exhibit_edit',
    'examination_view', 'examination_edit',
    'document_manage',
    'asset_view', 'asset_manage',
    'manage_lookups',
];

function sync_permission_catalog(mysqli $conn): void
{
    $expected = count(PERMISSION_DEFINITIONS);
    $result = @$conn->query("SELECT COUNT(*) AS c FROM permissions");
    if (!$result) {
        return; // table doesn't exist yet
    }
    $row = $result->fetch_assoc();
    if ((int) $row['c'] === $expected) {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO permissions (permission_key, label, description, category, sort_order)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description),
            category = VALUES(category), sort_order = VALUES(sort_order)
    ");
    if (!$stmt) {
        return;
    }
    foreach (PERMISSION_DEFINITIONS as $def) {
        $stmt->bind_param("ssssi", $def['key'], $def['label'], $def['description'], $def['category'], $def['sort_order']);
        $stmt->execute();
    }
    $stmt->close();
}

// True if $userId can do $permissionKey - super always can.
function user_can(mysqli $conn, int $userId, string $permissionKey): bool
{
    static $roleCache = [];
    if (!array_key_exists($userId, $roleCache)) {
        $stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->bind_result($role);
        $stmt->fetch();
        $stmt->close();
        $roleCache[$userId] = $role;
    }
    if ($roleCache[$userId] === 'super') {
        return true;
    }

    $stmt = $conn->prepare("SELECT 1 FROM user_permissions WHERE user_id = ? AND permission_key = ? LIMIT 1");
    $stmt->bind_param("is", $userId, $permissionKey);
    $stmt->execute();
    $stmt->store_result();
    $found = $stmt->num_rows > 0;
    $stmt->close();

    return $found;
}

// Redirects away if the current session user lacks $permissionKey - the
// one-line replacement for the old inline role-check block.
function require_permission(mysqli $conn, string $permissionKey, string $redirectTo = '../dashboard.php'): void
{
    if (!user_can($conn, (int) $_SESSION['user_id'], $permissionKey)) {
        header("Location: $redirectTo");
        exit();
    }
}

// Resets $userId's permissions to exactly match $role's current default
// bundle - a one-time snapshot, not a live binding. Used on user creation
// and when a user's role is changed.
function apply_role_default_permissions(mysqli $conn, int $userId, string $role): void
{
    $stmt = $conn->prepare("DELETE FROM user_permissions WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("
        INSERT INTO user_permissions (user_id, permission_key)
        SELECT ?, permission_key FROM role_default_permissions WHERE role = ?
    ");
    $stmt->bind_param("is", $userId, $role);
    $stmt->execute();
    $stmt->close();
}

// One-time seed of role_default_permissions from USER_ROLE_DEFAULT_PERMISSIONS
// ('user') and every permission key ('admin') - never re-runs once the
// table has any rows, since admins are expected to edit it afterward via
// manage_role_permissions.php.
function seed_role_default_permissions_if_empty(mysqli $conn): void
{
    $result = @$conn->query("SELECT COUNT(*) AS c FROM role_default_permissions");
    if (!$result) {
        return; // table doesn't exist yet
    }
    $row = $result->fetch_assoc();
    if ((int) $row['c'] > 0) {
        return;
    }

    $stmt = $conn->prepare("INSERT INTO role_default_permissions (role, permission_key) VALUES (?, ?)");
    if (!$stmt) {
        return;
    }
    $role = 'user';
    foreach (USER_ROLE_DEFAULT_PERMISSIONS as $key) {
        $stmt->bind_param("ss", $role, $key);
        $stmt->execute();
    }
    $role = 'admin';
    foreach (PERMISSION_DEFINITIONS as $def) {
        $stmt->bind_param("ss", $role, $def['key']);
        $stmt->execute();
    }
    $stmt->close();
}

// One-time grandfathering: every pre-existing user/admin account gets its
// role's default bundle, so shipping this system doesn't take away
// anything anyone could already do. Tracked via a settings flag rather
// than "does this user have zero permission rows", since an admin may
// deliberately leave a user with none.
function grandfather_existing_users(mysqli $conn): void
{
    $result = @$conn->query("SELECT setting_value FROM settings WHERE setting_key = 'permissions_grandfathered' LIMIT 1");
    if (!$result) {
        return; // settings table doesn't exist yet
    }
    if ($result->fetch_assoc()) {
        return; // already done
    }

    $result = $conn->query("SELECT id, role FROM users WHERE role IN ('user', 'admin')");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            apply_role_default_permissions($conn, (int) $row['id'], $row['role']);
        }
    }

    $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('permissions_grandfathered', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");
}

// One-time-per-role seed of the built-in roles into the roles catalog.
// Uses INSERT IGNORE so it never overwrites a label an admin has since
// customized. 'super' is included here for completeness/display purposes
// only - it's never offered on a role dropdown (see get_all_roles()) since
// it bypasses every permission check and can't be assigned through the UI.
function sync_roles_catalog(mysqli $conn): void
{
    $result = @$conn->query("SELECT COUNT(*) AS c FROM roles");
    if (!$result) {
        return; // table doesn't exist yet
    }
    $stmt = $conn->prepare("INSERT IGNORE INTO roles (role_key, label, is_builtin) VALUES (?, ?, 1)");
    if (!$stmt) {
        return;
    }
    foreach (['user' => 'User', 'admin' => 'Admin', 'super' => 'Super'] as $key => $label) {
        $stmt->bind_param("ss", $key, $label);
        $stmt->execute();
    }
    $stmt->close();
}

// All assignable roles (built-in and custom), for populating role
// dropdowns and the Manage Role Permissions tabs. Excludes 'super' - it
// has no editable bundle and can't be assigned through the UI.
function get_all_roles(mysqli $conn): array
{
    $roles = [];
    $result = $conn->query("SELECT role_key, label, is_builtin FROM roles WHERE role_key != 'super' ORDER BY is_builtin DESC, label ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $roles[] = $row;
        }
    }
    return $roles;
}

// Creates a new custom role. Returns an error string, or null on success.
function create_role(mysqli $conn, string $roleKey, string $label): ?string
{
    if (!preg_match('/^[a-z][a-z0-9_]{0,49}$/', $roleKey)) {
        return "Role key must start with a lowercase letter and contain only lowercase letters, numbers, and underscores.";
    }
    if (trim($label) === '') {
        return "Display label is required.";
    }

    $stmt = $conn->prepare("SELECT 1 FROM roles WHERE role_key = ? LIMIT 1");
    $stmt->bind_param("s", $roleKey);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    if ($exists) {
        return "A role with that key already exists.";
    }

    $trimmedLabel = trim($label);
    $stmt = $conn->prepare("INSERT INTO roles (role_key, label, is_builtin) VALUES (?, ?, 0)");
    $stmt->bind_param("ss", $roleKey, $trimmedLabel);
    $stmt->execute();
    $stmt->close();
    return null;
}

// Deletes a custom (non-builtin) role, provided no users currently hold
// it. Returns an error string, or null on success.
function delete_role(mysqli $conn, string $roleKey): ?string
{
    $stmt = $conn->prepare("SELECT is_builtin FROM roles WHERE role_key = ? LIMIT 1");
    $stmt->bind_param("s", $roleKey);
    $stmt->execute();
    $stmt->bind_result($isBuiltin);
    $found = $stmt->fetch();
    $stmt->close();
    if (!$found) {
        return "Role not found.";
    }
    if ($isBuiltin) {
        return "Built-in roles can't be deleted.";
    }

    $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE role = ?");
    $stmt->bind_param("s", $roleKey);
    $stmt->execute();
    $stmt->bind_result($userCount);
    $stmt->fetch();
    $stmt->close();
    if ($userCount > 0) {
        return "Can't delete - $userCount user(s) still have this role. Reassign them first.";
    }

    $del = $conn->prepare("DELETE FROM role_default_permissions WHERE role = ?");
    $del->bind_param("s", $roleKey);
    $del->execute();
    $del->close();

    $del = $conn->prepare("DELETE FROM roles WHERE role_key = ?");
    $del->bind_param("s", $roleKey);
    $del->execute();
    $del->close();

    return null;
}

// All permissions grouped by category, in sort_order, for rendering a
// checkbox list (used by user_details.php and manage_role_permissions.php).
function get_all_permissions_grouped(mysqli $conn): array
{
    $grouped = [];
    $result = $conn->query("SELECT permission_key, label, description, category FROM permissions ORDER BY sort_order ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $grouped[$row['category']][] = $row;
        }
    }
    return $grouped;
}

// The set of permission_keys currently granted to $userId (not counting
// the super bypass).
function get_user_permission_keys(mysqli $conn, int $userId): array
{
    $keys = [];
    $stmt = $conn->prepare("SELECT permission_key FROM user_permissions WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $keys[] = $row['permission_key'];
    }
    $stmt->close();
    return $keys;
}
