<?php
// view_task_history.php
//
// Task change history - reads the audit_log entries create_task.php/
// edit_task.php already write, filtered to one task.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/audit_render.php';
require_once '../includes/permissions.php';

$task_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$taskStmt = $conn->prepare("SELECT id, task_ref, custom_ref, description, assigned_to FROM tasks WHERE id = ? LIMIT 1");
$taskStmt->bind_param("i", $task_id);
$taskStmt->execute();
$task = $taskStmt->get_result()->fetch_assoc();
$taskStmt->close();

if (!$task) {
    header("Location: tasking.php");
    exit();
}

// Same rule as edit_task.php: task_manage, or the assigned user.
if (!user_can($conn, (int) $_SESSION['user_id'], 'task_manage') && (int) $task['assigned_to'] !== (int) $_SESSION['user_id']) {
    header("Location: ../dashboard.php");
    exit();
}

$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    include '../header.php';
}

$historyStmt = $conn->prepare("
    SELECT a.id, a.action, a.changed_at, a.details,
           CONCAT(u.first_name, ' ', u.last_name) AS changed_by_name
    FROM audit_log a
    LEFT JOIN users u ON a.changed_by = u.id
    WHERE a.entity_type = 'task' AND a.entity_id = ?
    ORDER BY a.id DESC
");
$historyStmt->bind_param("i", $task_id);
$historyStmt->execute();
$history = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$historyStmt->close();
?>

<div class="content-wrapper">
    <h2>Task History: <?php echo htmlspecialchars($task['task_ref']); ?></h2>
    <p style="color:var(--polaris-text-dim);">
        Case <?php echo htmlspecialchars($task['custom_ref']); ?> -
        <?php echo htmlspecialchars($task['description']); ?>
    </p>

    <div class="table-scroll">
        <table class="logs-table">
            <tr>
                <th>When</th>
                <th>Who</th>
                <th>Action</th>
                <th>Details</th>
            </tr>
            <?php if (empty($history)): ?>
            <tr>
                <td colspan="4">No history recorded yet.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($history as $row): ?>
            <tr>
                <td class="col-when"><?php echo htmlspecialchars($row['changed_at']); ?></td>
                <td><?php echo htmlspecialchars($row['changed_by_name'] ?? 'Unknown'); ?></td>
                <td><?php echo action_badge($row['action']); ?></td>
                <td><?php echo render_audit_details($row['details']); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </table>
    </div>

    <div style="margin-top: 20px;">
        <a href="edit_task.php?id=<?php echo $task_id; ?><?php echo $embedded ? '&embedded=1' : ''; ?>" class="action-btn" onclick="history.back(); return false;">&larr; Back</a>
    </div>
</div>

<style>
.action-btn {
    display: inline-block;
    padding: 5px 10px;
    background-color: var(--polaris-accent);
    color: var(--polaris-text);
    font-size: 14px;
    text-decoration: none;
    border-radius: 3px;
}

.action-btn:hover {
    background-color: var(--polaris-accent-hover);
}

.table-scroll {
    width: 100%;
    overflow-x: auto;
}

.logs-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
}

.logs-table th,
.logs-table td {
    border: 1px solid var(--polaris-border);
    padding: 8px;
    text-align: left;
    font-size: 14px;
}

.logs-table th {
    background: var(--polaris-divider);
}

.col-when {
    white-space: nowrap;
    color: var(--polaris-text-secondary);
}

.action-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: bold;
    white-space: nowrap;
}

.badge-create {
    background: #1b4d2e;
    color: #7ee2a8;
}

.badge-update {
    background: #1a3d5c;
    color: #7ec4f2;
}

.badge-delete {
    background: #4d1b1b;
    color: #f28e8e;
}

.badge-default {
    background: var(--polaris-divider);
    color: var(--polaris-text-secondary);
}

.details-raw {
    margin-top: 4px;
}

.details-raw summary {
    cursor: pointer;
    color: var(--polaris-text-faint);
    font-size: 12px;
}

.details-raw pre {
    background: var(--polaris-black-alt);
    padding: 8px;
    border-radius: 4px;
    font-size: 12px;
    max-width: 500px;
    overflow-x: auto;
    white-space: pre-wrap;
    word-break: break-word;
}
</style>
