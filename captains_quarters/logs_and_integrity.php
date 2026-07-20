<?php
// logs_and_integrity.php
//
// Combines Check Database Integrity and View Logs onto one tab.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/integrity.php';
require_once '../includes/audit_render.php';
require_once '../includes/permissions.php';
require_permission($conn, 'view_logs_integrity');

$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    include '../header.php';
}

// Re-runs on every page load - each check is a handful of SELECTs over
// case_history/exhibit_history, cheap even at a few thousand rows.
$caseChain = verify_history_chain($conn, 'case_history');
$exhibitChain = verify_history_chain($conn, 'exhibit_history');
$auditChain = verify_history_chain($conn, 'audit_log');
$processChain = verify_history_chain($conn, 'exhibit_process_history');

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

$recentCaseHistory = fetch_recent_history($conn, 'case_history', 'job_id');
$recentExhibitHistory = fetch_recent_history($conn, 'exhibit_history', 'exhibit_id');
$recentAuditLog = fetch_recent_audit_log($conn);
?>

<div class="content-wrapper">
    <h2>Logs &amp; Integrity</h2>

    <div class="explainer">
        <p><strong>What this checks:</strong> case history (which also covers case update
            add/edit/delete events), exhibit history, exhibit process history, and the system/admin
            activity log (the audit trail of who changed what, and when) are each chained with two
            independent layers, so an edited or deleted historical record is detectable rather than
            silently accepted:</p>
        <ol>
            <li><strong>Hash chain</strong> - each row stores a SHA-256 hash covering its own
                fields plus the previous row's hash, computed automatically by a database trigger.
                Editing any field in any past row, or removing one, changes what its hash should
                be - which no longer matches what's stored.</li>
            <li><strong>HMAC chain</strong> - the same idea, but computed here in the application
                using a secret (<code>HISTORY_HMAC_KEY</code>) the database itself never sees.
                Someone with only database access can, in principle, edit a row and correctly
                recompute layer 1 to match - but they can't reproduce layer 2 without also having
                the application's secret.</li>
        </ol>
        <p><strong>What this doesn't catch:</strong> someone with both full database admin rights
            <em>and</em> the application's environment/secrets could edit history and regenerate
            both chains forward from that point, leaving no trace here. Real protection against
            that requires anchoring the chain outside this database's control entirely (e.g.
            periodically exporting the latest hash somewhere this database admin doesn't control),
            which isn't implemented. Also worth knowing: <code>case_history</code>,
            <code>exhibit_history</code>, and <code>audit_log</code> rows can't normally be edited
            or deleted at all - the database rejects it outright - so a broken chain below means
            someone bypassed that (e.g. disabled the trigger first), not an accident during normal
            use.</p>
    </div>

    <div class="chain-status">
        <div class="chain-card <?php echo $caseChain['ok'] ? 'chain-ok' : 'chain-bad'; ?>">
            <h3>Case History</h3>
            <?php if ($caseChain['error']): ?>
            <p class="chain-headline">Error checking chain: <?php echo htmlspecialchars($caseChain['error']); ?></p>
            <?php elseif ($caseChain['ok']): ?>
            <p class="chain-headline">&#10003; All <?php echo (int) $caseChain['total']; ?> records verified</p>
            <?php else: ?>
            <p class="chain-headline">&#10007; Tampering detected</p>
            <p class="chain-detail">Hash chain broken at record(s):
                <?php echo htmlspecialchars(implode(', ', $caseChain['broken_hash']) ?: 'none'); ?></p>
            <p class="chain-detail">HMAC broken at record(s):
                <?php echo htmlspecialchars(implode(', ', $caseChain['broken_hmac']) ?: 'none'); ?></p>
            <?php endif; ?>
        </div>

        <div class="chain-card <?php echo $exhibitChain['ok'] ? 'chain-ok' : 'chain-bad'; ?>">
            <h3>Exhibit History</h3>
            <?php if ($exhibitChain['error']): ?>
            <p class="chain-headline">Error checking chain: <?php echo htmlspecialchars($exhibitChain['error']); ?></p>
            <?php elseif ($exhibitChain['ok']): ?>
            <p class="chain-headline">&#10003; All <?php echo (int) $exhibitChain['total']; ?> records verified</p>
            <?php else: ?>
            <p class="chain-headline">&#10007; Tampering detected</p>
            <p class="chain-detail">Hash chain broken at record(s):
                <?php echo htmlspecialchars(implode(', ', $exhibitChain['broken_hash']) ?: 'none'); ?></p>
            <p class="chain-detail">HMAC broken at record(s):
                <?php echo htmlspecialchars(implode(', ', $exhibitChain['broken_hmac']) ?: 'none'); ?></p>
            <?php endif; ?>
        </div>

        <div class="chain-card <?php echo $auditChain['ok'] ? 'chain-ok' : 'chain-bad'; ?>">
            <h3>System &amp; Admin Activity</h3>
            <?php if ($auditChain['error']): ?>
            <p class="chain-headline">Error checking chain: <?php echo htmlspecialchars($auditChain['error']); ?></p>
            <?php elseif ($auditChain['ok']): ?>
            <p class="chain-headline">&#10003; All <?php echo (int) $auditChain['total']; ?> records verified</p>
            <?php else: ?>
            <p class="chain-headline">&#10007; Tampering detected</p>
            <p class="chain-detail">Hash chain broken at record(s):
                <?php echo htmlspecialchars(implode(', ', $auditChain['broken_hash']) ?: 'none'); ?></p>
            <p class="chain-detail">HMAC broken at record(s):
                <?php echo htmlspecialchars(implode(', ', $auditChain['broken_hmac']) ?: 'none'); ?></p>
            <?php endif; ?>
        </div>

        <div class="chain-card <?php echo $processChain['ok'] ? 'chain-ok' : 'chain-bad'; ?>">
            <h3>Exhibit Process History</h3>
            <?php if ($processChain['error']): ?>
            <p class="chain-headline">Error checking chain: <?php echo htmlspecialchars($processChain['error']); ?></p>
            <?php elseif ($processChain['ok']): ?>
            <p class="chain-headline">&#10003; All <?php echo (int) $processChain['total']; ?> records verified</p>
            <?php else: ?>
            <p class="chain-headline">&#10007; Tampering detected</p>
            <p class="chain-detail">Hash chain broken at record(s):
                <?php echo htmlspecialchars(implode(', ', $processChain['broken_hash']) ?: 'none'); ?></p>
            <p class="chain-detail">HMAC broken at record(s):
                <?php echo htmlspecialchars(implode(', ', $processChain['broken_hmac']) ?: 'none'); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <p style="margin-top:20px;"><a href="javascript:location.reload()" class="action-btn">Re-check now</a></p>

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
.explainer {
    max-width: 800px;
    color: var(--polaris-text-dim);
    font-size: 14px;
    line-height: 1.5;
}

.explainer code {
    background: var(--polaris-divider);
    padding: 1px 5px;
    border-radius: 3px;
}

.chain-status {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    margin-top: 20px;
}

.chain-card {
    flex: 1;
    min-width: 260px;
    border: 1px solid var(--polaris-border);
    border-radius: 6px;
    padding: 15px;
}

.chain-card h3 {
    margin-top: 0;
}

.chain-ok {
    border-color: var(--polaris-success-strong);
}

.chain-ok .chain-headline {
    color: var(--polaris-success-strong);
}

.chain-bad {
    border-color: var(--polaris-danger-alt);
}

.chain-bad .chain-headline {
    color: var(--polaris-danger-alt);
    font-weight: bold;
}

.chain-detail {
    font-size: 13px;
    color: var(--polaris-text-muted);
}

.action-btn {
    display: inline-block;
    background: var(--polaris-accent);
    color: var(--polaris-text);
    padding: 5px 10px;
    border-radius: 3px;
    font-size: 14px;
    text-decoration: none;
}

.action-btn:hover {
    background: var(--polaris-accent-hover);
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

</body>

</html>
