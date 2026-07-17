<?php
// view_logs.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/integrity.php';
require_once '../includes/audit_render.php';

// Check admin privileges (assumes an admin has role 'admin' or 'super').
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

function fetch_recent_history(mysqli $conn, string $table, string $refCol, int $limit = 25): array
{
    $idCol = HISTORY_CHAIN_TABLES[$table]['id_col'];
    $result = $conn->query("
        SELECT h.$idCol AS id, h.$refCol AS ref_id, h.action, h.changed_at,
               CONCAT(u.first_name, ' ', u.last_name) AS changed_by_name
        FROM `$table` h
        LEFT JOIN users u ON h.changed_by = u.id
        ORDER BY h.$idCol DESC
        LIMIT $limit
    ");
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function fetch_recent_audit_log(mysqli $conn, int $limit = 50): array
{
    $result = $conn->query("
        SELECT a.id, a.entity_type, a.entity_id, a.action, a.changed_at, a.details,
               CONCAT(u.first_name, ' ', u.last_name) AS changed_by_name
        FROM audit_log a
        LEFT JOIN users u ON a.changed_by = u.id
        ORDER BY a.id DESC
        LIMIT $limit
    ");
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

// action_badge() / render_audit_details() now live in
// includes/audit_render.php, shared with view_task_history.php.

$recentCaseHistory = fetch_recent_history($conn, 'case_history', 'job_id');
$recentExhibitHistory = fetch_recent_history($conn, 'exhibit_history', 'exhibit_id');
$recentAuditLog = fetch_recent_audit_log($conn);
?>

<div class="content-wrapper">
    <h2>View Logs</h2>
    <p style="color:var(--polaris-text-dim);">Recent case and exhibit history (audit trail) entries. Every row here
        is tamper-evident - see
        <a href="check_integrity.php<?php echo $embedded ? '?embedded=1' : ''; ?>">Check Database Integrity</a> to
        verify nothing's been altered.</p>

    <h3 style="margin-top:30px;">Recent Case History</h3>
    <table class="logs-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Job ID</th>
                <th>Action</th>
                <th>Changed By</th>
                <th>Changed At</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($recentCaseHistory)): ?>
            <tr>
                <td colspan="5">No case history yet.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($recentCaseHistory as $row): ?>
            <tr>
                <td><?php echo (int) $row['id']; ?></td>
                <td><?php echo (int) $row['ref_id']; ?></td>
                <td><?php echo action_badge($row['action']); ?></td>
                <td><?php echo htmlspecialchars($row['changed_by_name'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($row['changed_at']); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <h3 style="margin-top:30px;">Recent Exhibit History</h3>
    <table class="logs-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Exhibit ID</th>
                <th>Action</th>
                <th>Changed By</th>
                <th>Changed At</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($recentExhibitHistory)): ?>
            <tr>
                <td colspan="5">No exhibit history yet.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($recentExhibitHistory as $row): ?>
            <tr>
                <td><?php echo (int) $row['id']; ?></td>
                <td><?php echo (int) $row['ref_id']; ?></td>
                <td><?php echo action_badge($row['action']); ?></td>
                <td><?php echo htmlspecialchars($row['changed_by_name'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($row['changed_at']); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <h3 style="margin-top:30px;">Recent System &amp; Admin Activity</h3>
    <p style="color:var(--polaris-text-dim);">Changes to lookup tables (case/exhibit types, locations, forces,
        operations, customers, asset types/locations), users, tasks, assets, and system settings.
        Plain log, not part of the tamper-evident chains above.</p>
    <table class="logs-table">
        <thead>
            <tr>
                <th>When</th>
                <th>Who</th>
                <th>Action</th>
                <th>Item</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($recentAuditLog)): ?>
            <tr>
                <td colspan="5">No admin activity logged yet.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($recentAuditLog as $row): ?>
            <tr>
                <td class="col-when"><?php echo htmlspecialchars($row['changed_at']); ?></td>
                <td><?php echo htmlspecialchars($row['changed_by_name'] ?? ''); ?></td>
                <td><?php echo action_badge($row['action']); ?></td>
                <td>
                    <?php echo htmlspecialchars($row['entity_type']); ?><?php if ($row['entity_id'] !== null): ?>
                    <span style="color:var(--polaris-text-faint);">#<?php echo (int) $row['entity_id']; ?></span>
                    <?php endif; ?>
                </td>
                <td><?php echo render_audit_details($row['details']); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<style>
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

</body>

</html>
