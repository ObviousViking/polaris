<?php
// manage_users.php
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

// Handle filter
$filter_active = isset($_GET['active']) ? $_GET['active'] : 'all';
$where_clause = "";
if ($filter_active === 'yes') {
    $where_clause = " WHERE active = 1";
} elseif ($filter_active === 'no') {
    $where_clause = " WHERE active = 0";
}

// Handle password reset
$message = "";
$message_type = "";
if (isset($_POST['reset_password'])) {
    $reset_user_id = (int)$_POST['user_id'];

    $targetRoleStmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $targetRoleStmt->bind_param("i", $reset_user_id);
    $targetRoleStmt->execute();
    $targetRoleStmt->bind_result($targetRole);
    $targetRoleStmt->fetch();
    $targetRoleStmt->close();

    // Resetting a privileged account's password is itself a privilege
    // escalation path (log in as the reset account) - require
    // manage_role_permissions, same as editing that account's role/permissions.
    if ($targetRole !== null && role_is_privileged($conn, $targetRole) && !user_can($conn, (int) $_SESSION['user_id'], 'manage_role_permissions')) {
        $message = "You don't have permission to reset this account's password.";
        $message_type = "error";
    } else {
        $default_password = "Password1!";
        $hashed_password = password_hash($default_password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hashed_password, $reset_user_id);
        if ($stmt->execute()) {
            log_audit_event($conn, 'user', $reset_user_id, 'PASSWORD_RESET', (int) $_SESSION['user_id']);
            $message = "Password reset successfully to default (Password1!).";
            $message_type = "success";
        } else {
            $message = "Error resetting password.";
            $message_type = "error";
        }
        $stmt->close();
    }
}

// Fetch users
$query = "SELECT id, first_name, last_name, email, role, active FROM users" . $where_clause;
$result = $conn->query($query);
?>

<div class="content-wrapper">
    <h2>Manage Users</h2>

    <?php if ($message): ?>
    <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <!-- Filter and Search -->
    <div class="filter-search">
        <form method="get" action="manage_users.php" class="filter-form">
            <div class="field">
                <label for="active">Filter by Status</label>
                <select name="active" id="active" onchange="this.form.submit()">
                    <option value="all" <?php echo $filter_active === 'all' ? 'selected' : ''; ?>>All</option>
                    <option value="yes" <?php echo $filter_active === 'yes' ? 'selected' : ''; ?>>Active</option>
                    <option value="no" <?php echo $filter_active === 'no' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
        </form>
        <div class="field search-form">
            <label for="search">Search</label>
            <input type="text" id="search" placeholder="Type to filter users...">
        </div>
    </div>

    <!-- Users Table -->
    <div class="table-scroll">
        <table class="users-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>First Name</th>
                    <th>Last Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="userTableBody">
                <?php while ($user = $result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($user['id']); ?></td>
                    <td><?php echo htmlspecialchars($user['first_name']); ?></td>
                    <td><?php echo htmlspecialchars($user['last_name']); ?></td>
                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                    <td><?php echo htmlspecialchars($user['role']); ?></td>
                    <td><span class="badge <?php echo $user['active'] ? 'badge-active' : ''; ?>"><?php echo $user['active'] ? 'Active' : 'Inactive'; ?></span></td>
                    <td>
                        <a href="user_details.php?id=<?php echo $user['id']; ?>" class="edit-btn small" target="_blank"
                            onclick="window.open(this.href, 'editUser', 'width=700,height=650'); return false;">Edit</a>
                        <form method="post" style="display: inline;"
                            onsubmit="return confirm('Reset password to default (Password1!)?');">
                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                            <button type="submit" name="reset_password" class="reset-btn small">Reset Password</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
body {
    background-color: var(--polaris-bg-alt);
    color: var(--polaris-text);
}

.content-wrapper {
    margin: <?php echo $embedded ? '0' : '80px'; ?> auto 0 auto;
    padding: 20px;
    max-width: 1400px;
}

.filter-search {
    display: flex;
    align-items: flex-end;
    gap: 25px;
    flex-wrap: wrap;
    margin-bottom: 20px;
    padding: 15px 20px;
    background: var(--polaris-surface);
    border-radius: 5px;
}

.field label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
    color: var(--polaris-text-secondary);
    font-size: 13px;
}

.filter-form select,
.search-form input {
    padding: 6px 8px;
    border-radius: 4px;
    background-color: var(--polaris-surface-alt);
    color: var(--polaris-text);
    border: 1px solid var(--polaris-border-hover);
    font-size: 13px;
}

.search-form input {
    width: 240px;
}

.table-scroll {
    width: 100%;
    overflow-x: auto;
}

.users-table {
    width: 100%;
    border-collapse: collapse;
    background: var(--polaris-surface);
    border-radius: 5px;
}

.users-table th,
.users-table td {
    padding: 8px 12px;
    text-align: left;
    border-bottom: 1px solid var(--polaris-border);
    color: var(--polaris-text);
    font-size: 13px;
    vertical-align: middle;
}

.users-table th {
    background-color: var(--polaris-divider);
    white-space: nowrap;
}

.users-table tr:last-child td {
    border-bottom: none;
}

.users-table tr:hover td {
    background: var(--polaris-surface-alt);
}

.badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background: var(--polaris-divider);
    color: var(--polaris-text-secondary);
}

.badge-active {
    background: var(--polaris-success-bg);
    color: var(--polaris-success-text);
}

.edit-btn,
.reset-btn {
    background: var(--polaris-accent);
    color: var(--polaris-text);
    padding: 5px 10px;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    margin-right: 5px;
    display: inline-block;
    text-decoration: none;
    font-size: 13px;
}

.edit-btn.small,
.reset-btn.small {
    padding: 4px 8px;
    font-size: 12px;
}

.edit-btn:hover {
    background: var(--polaris-accent-hover);
}

.reset-btn {
    background: var(--polaris-error-bg);
    color: var(--polaris-error-text);
}

.reset-btn:hover {
    background: var(--polaris-danger);
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
    const searchInput = document.getElementById('search');
    const tableBody = document.getElementById('userTableBody');
    const rows = tableBody.getElementsByTagName('tr');

    // Search functionality
    searchInput.addEventListener('input', function() {
        const searchText = this.value.toLowerCase();
        Array.from(rows).forEach(row => {
            const cells = row.getElementsByTagName('td');
            let match = false;
            for (let i = 0; i < cells.length - 1; i++) { // Exclude Actions column
                const cellText = cells[i].textContent.toLowerCase();
                if (cellText.includes(searchText)) {
                    match = true;
                    break;
                }
            }
            row.style.display = match ? '' : 'none';
        });
    });
});
</script>

</body>

</html>