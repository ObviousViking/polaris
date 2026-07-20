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
            <label for="active">Filter by Status:</label>
            <select name="active" id="active" onchange="this.form.submit()">
                <option value="all" <?php echo $filter_active === 'all' ? 'selected' : ''; ?>>All</option>
                <option value="yes" <?php echo $filter_active === 'yes' ? 'selected' : ''; ?>>Active</option>
                <option value="no" <?php echo $filter_active === 'no' ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </form>
        <div class="search-form">
            <label for="search">Search:</label>
            <input type="text" id="search" placeholder="Type to filter users...">
        </div>
    </div>

    <!-- Users Table -->
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
                <td><?php echo $user['active'] ? 'Active' : 'Inactive'; ?></td>
                <td>
                    <a href="user_details.php?id=<?php echo $user['id']; ?>" class="edit-btn" target="_blank"
                        onclick="window.open(this.href, 'editUser', 'width=700,height=650'); return false;">Edit</a>
                    <form method="post" style="display: inline;"
                        onsubmit="return confirm('Reset password to default (Password1!)?');">
                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                        <button type="submit" name="reset_password" class="reset-btn">Reset Password</button>
                    </form>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
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

.filter-search {
    display: flex;
    justify-content: space-between;
    margin-bottom: 20px;
}

.filter-form,
.search-form {
    display: flex;
    align-items: center;
}

.filter-form label,
.search-form label {
    margin-right: 10px;
    font-weight: bold;
    color: var(--polaris-gray-light-2);
}

.filter-form select,
.search-form input {
    padding: 5px;
    border-radius: 4px;
    background-color: var(--polaris-divider);
    color: var(--polaris-text);
    border: 1px solid var(--polaris-border);
}

.search-form input {
    width: 200px;
}

.users-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

.users-table th,
.users-table td {
    padding: 10px;
    border: 1px solid var(--polaris-border);
    text-align: left;
    color: var(--polaris-text);
}

.users-table th {
    background-color: var(--polaris-divider);
}

.users-table td {
    background-color: var(--polaris-surface);
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