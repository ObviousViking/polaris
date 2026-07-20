<?php
// cq_dashboard.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/permissions.php';

$userId = (int) $_SESSION['user_id'];

// Everyone can reach this shell (Settings needs to be open to normal users
// for its Storage Settings card) - each nav item below is shown only if
// the user actually has the matching permission, and each linked page
// still enforces its own check if reached directly regardless of the nav.
$canManageUsers = user_can($conn, $userId, 'manage_users');
$canManageRolePermissions = user_can($conn, $userId, 'manage_role_permissions');
$canViewTasking = user_can($conn, $userId, 'task_view');
$canManageBackup = user_can($conn, $userId, 'manage_backup');
$canViewLogsIntegrity = user_can($conn, $userId, 'view_logs_integrity');
$canManageProcesses = user_can($conn, $userId, 'manage_processes');
$canViewReports = user_can($conn, $userId, 'view_reports');

include '../header.php';
?>

<div class="cq-shell">
    <div class="cq-nav">
        <?php if ($canManageUsers): ?>
        <a href="manage_users.php?embedded=1" target="cq-content">Manage Users</a>
        <a href="create_user.php?embedded=1" target="cq-content">Create User</a>
        <?php endif; ?>
        <?php if ($canManageRolePermissions): ?>
        <a href="manage_role_permissions.php?embedded=1" target="cq-content">Manage Role Permissions</a>
        <?php endif; ?>
        <?php if ($canViewTasking): ?>
        <a href="tasking.php?embedded=1" target="cq-content">Tasking</a>
        <?php endif; ?>
        <?php if ($canManageBackup): ?>
        <a href="backup_restore.php?embedded=1" target="cq-content">Backup / Restore</a>
        <?php endif; ?>
        <a href="manage_settings.php?embedded=1" target="cq-content">Settings</a>
        <?php if ($canViewLogsIntegrity): ?>
        <a href="logs_and_integrity.php?embedded=1" target="cq-content">Logs &amp; Integrity</a>
        <?php endif; ?>
        <?php if ($canManageProcesses): ?>
        <a href="manage_processes.php?embedded=1" target="cq-content">Process Builder</a>
        <?php endif; ?>
        <?php if ($canViewReports): ?>
        <a href="reports.php?embedded=1" target="cq-content">System Reports</a>
        <?php endif; ?>
    </div>
    <iframe name="cq-content" class="cq-content" srcdoc="<!DOCTYPE html><html<?php echo $userTheme === 'light' ? " data-theme='light'" : ''; ?>><head><meta charset='UTF-8'><link rel='stylesheet' href='/assets/theme.css'><style>body{margin:0;padding:20px;font-family:Arial,sans-serif;background:var(--polaris-bg);color:var(--polaris-text-muted);}h2{color:var(--polaris-text);margin:0 0 10px;}</style></head><body><h2>System Management</h2><p>Choose an option on the left.</p></body></html>"></iframe>
</div>

<style>
.cq-shell {
    display: flex;
    position: fixed;
    top: 100px;
    left: 0;
    right: 0;
    bottom: 0;
}

.cq-nav {
    flex: 0 0 200px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    background: var(--polaris-surface);
    padding: 15px 10px;
    box-sizing: border-box;
    height: 100%;
    overflow-y: auto;
}

.cq-nav a {
    display: block;
    background-color: var(--polaris-border);
    color: var(--polaris-text);
    padding: 12px 15px;
    text-decoration: none;
    border-radius: 5px;
    font-size: 15px;
    transition: background 0.2s ease-in-out;
}

.cq-nav a:hover,
.cq-nav a.active {
    background-color: var(--polaris-border-hover-2);
}

.cq-content {
    flex: 1;
    min-width: 0;
    height: 100%;
    border: none;
    background: var(--polaris-bg);
}

@media (max-width: 900px) {
    .cq-shell {
        flex-direction: column;
    }

    .cq-nav {
        flex-direction: row;
        flex-wrap: wrap;
        height: auto;
        width: 100%;
    }

    .cq-nav a {
        flex: 1 1 auto;
    }

    .cq-content {
        flex: 1;
    }
}
</style>

<script>
// Highlight whichever nav link was last clicked.
document.querySelectorAll('.cq-nav a').forEach(function(link) {
    link.addEventListener('click', function() {
        document.querySelectorAll('.cq-nav a').forEach(function(l) {
            l.classList.remove('active');
        });
        link.classList.add('active');
    });
});
</script>

</body>

</html>
