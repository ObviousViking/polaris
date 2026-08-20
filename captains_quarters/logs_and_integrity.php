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
$caseItemChain = verify_history_chain($conn, 'case_item_history');
$submissionChain = verify_history_chain($conn, 'submission_history');

const LOGS_PAGE_SIZE = 25;

function count_table_rows(mysqli $conn, string $table): int
{
    $result = $conn->query("SELECT COUNT(*) AS c FROM `$table`");
    return $result ? (int) $result->fetch_assoc()['c'] : 0;
}

// $refLookup, when given, joins back to the table the history row is about
// (e.g. exhibit_history.exhibit_id -> exhibits.exhibit_ref) so the log can
// show a human-readable reference alongside the raw numeric ID.
function fetch_history_page(mysqli $conn, string $table, string $refCol, int $page, bool $showAll, ?array $refLookup = null): array
{
    $idCol = HISTORY_CHAIN_TABLES[$table]['id_col'];
    $limitClause = $showAll ? '' : 'LIMIT ' . LOGS_PAGE_SIZE . ' OFFSET ' . (($page - 1) * LOGS_PAGE_SIZE);

    $refSelect = '';
    $refJoin = '';
    if ($refLookup !== null) {
        ['table' => $rTable, 'id_col' => $rIdCol, 'name_col' => $rNameCol] = $refLookup;
        $refSelect = ", r.$rNameCol AS ref_name";
        $refJoin = "LEFT JOIN `$rTable` r ON r.$rIdCol = h.$refCol";
    }

    $result = $conn->query("
        SELECT h.$idCol AS id, h.$refCol AS ref_id, h.action, h.changed_at,
               CONCAT(u.first_name, ' ', u.last_name) AS changed_by_name
               $refSelect
        FROM `$table` h
        LEFT JOIN users u ON h.changed_by = u.id
        $refJoin
        ORDER BY h.$idCol DESC
        $limitClause
    ");
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function fetch_audit_log_page(mysqli $conn, int $page, bool $showAll): array
{
    $limitClause = $showAll ? '' : 'LIMIT ' . LOGS_PAGE_SIZE . ' OFFSET ' . (($page - 1) * LOGS_PAGE_SIZE);
    $result = $conn->query("
        SELECT a.id, a.entity_type, a.entity_id, a.action, a.changed_at, a.details,
               CONCAT(u.first_name, ' ', u.last_name) AS changed_by_name
        FROM audit_log a
        LEFT JOIN users u ON a.changed_by = u.id
        ORDER BY a.id DESC
        $limitClause
    ");
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

// Builds a link that preserves every other table's pagination state (and
// `embedded`) while overriding just the params relevant to $prefix - so
// paging through one table never resets another.
function logs_page_url(array $overrides): string
{
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return '?' . http_build_query($params);
}

function render_pagination(string $prefix, int $page, int $totalPages, bool $showAll, int $total): string
{
    if ($total <= LOGS_PAGE_SIZE) {
        return '';
    }
    if ($showAll) {
        $html = '<div class="pagination">';
        $html .= '<span class="page-info">Showing all ' . $total . ' records</span>';
        $html .= '<a href="' . htmlspecialchars(logs_page_url([$prefix . '_all' => null, $prefix . '_page' => null])) . '" class="page-link">Paginate (' . LOGS_PAGE_SIZE . '/page)</a>';
        $html .= '</div>';
        return $html;
    }

    $prevDisabled = $page <= 1;
    $nextDisabled = $page >= $totalPages;
    $html = '<div class="pagination">';
    $html .= '<a href="' . htmlspecialchars(logs_page_url([$prefix . '_page' => max(1, $page - 1)])) . '" class="page-link' . ($prevDisabled ? ' disabled' : '') . '">&laquo; Prev</a>';
    $html .= '<span class="page-info">Page ' . $page . ' of ' . $totalPages . ' (' . $total . ' records)</span>';
    $html .= '<a href="' . htmlspecialchars(logs_page_url([$prefix . '_page' => min($totalPages, $page + 1)])) . '" class="page-link' . ($nextDisabled ? ' disabled' : '') . '">Next &raquo;</a>';
    $html .= '<a href="' . htmlspecialchars(logs_page_url([$prefix . '_all' => 1])) . '" class="page-link view-all">View All</a>';
    $html .= '</div>';
    return $html;
}

function paginate_table(mysqli $conn, string $prefix, string $countTable): array
{
    $page = max(1, intval($_GET[$prefix . '_page'] ?? 1));
    $showAll = isset($_GET[$prefix . '_all']);
    $total = count_table_rows($conn, $countTable);
    $totalPages = max(1, (int) ceil($total / LOGS_PAGE_SIZE));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    return ['page' => $page, 'showAll' => $showAll, 'total' => $total, 'totalPages' => $totalPages];
}

$casePagination = paginate_table($conn, 'case', 'case_history');
$exhibitPagination = paginate_table($conn, 'exhibit', 'exhibit_history');
$itemPagination = paginate_table($conn, 'item', 'case_item_history');
$auditPagination = paginate_table($conn, 'audit', 'audit_log');

$recentCaseHistory = fetch_history_page($conn, 'case_history', 'job_id', $casePagination['page'], $casePagination['showAll'], ['table' => 'jobs', 'id_col' => 'job_id', 'name_col' => 'custom_ref']);
$recentExhibitHistory = fetch_history_page($conn, 'exhibit_history', 'exhibit_id', $exhibitPagination['page'], $exhibitPagination['showAll'], ['table' => 'exhibits', 'id_col' => 'exhibit_id', 'name_col' => 'exhibit_ref']);
$recentCaseItemHistory = fetch_history_page($conn, 'case_item_history', 'item_id', $itemPagination['page'], $itemPagination['showAll'], ['table' => 'case_items', 'id_col' => 'item_id', 'name_col' => 'item_ref']);
$recentAuditLog = fetch_audit_log_page($conn, $auditPagination['page'], $auditPagination['showAll']);
?>

<div class="content-wrapper">
    <h2>Logs &amp; Integrity</h2>

    <details class="explainer">
        <summary>What do these checks mean?</summary>
        <p><strong>What this checks:</strong> case history (which also covers case update
            add/edit/delete events), exhibit history, exhibit process history, case item history
            (including handovers to review teams), and the system/admin activity log (the audit trail
            of who changed what, and when) are each chained with two independent layers, so an edited
            or deleted historical record is detectable rather than silently accepted:</p>
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
            <code>exhibit_history</code>, <code>case_item_history</code>, and <code>audit_log</code>
            rows can't normally be edited or deleted at all - the database rejects it outright - so a
            broken chain below means someone bypassed that (e.g. disabled the trigger first), not an
            accident during normal use.</p>
    </details>

    <div class="logs-layout">
    <div class="checks-sidebar">
        <h3 style="margin-top:0;">Integrity Checks</h3>
        <p><a href="javascript:location.reload()" class="action-btn">Re-check now</a></p>

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

        <div class="chain-card <?php echo $caseItemChain['ok'] ? 'chain-ok' : 'chain-bad'; ?>">
            <h3>Produced Item History</h3>
            <?php if ($caseItemChain['error']): ?>
            <p class="chain-headline">Error checking chain: <?php echo htmlspecialchars($caseItemChain['error']); ?></p>
            <?php elseif ($caseItemChain['ok']): ?>
            <p class="chain-headline">&#10003; All <?php echo (int) $caseItemChain['total']; ?> records verified</p>
            <?php else: ?>
            <p class="chain-headline">&#10007; Tampering detected</p>
            <p class="chain-detail">Hash chain broken at record(s):
                <?php echo htmlspecialchars(implode(', ', $caseItemChain['broken_hash']) ?: 'none'); ?></p>
            <p class="chain-detail">HMAC broken at record(s):
                <?php echo htmlspecialchars(implode(', ', $caseItemChain['broken_hmac']) ?: 'none'); ?></p>
            <?php endif; ?>
        </div>

        <div class="chain-card <?php echo $submissionChain['ok'] ? 'chain-ok' : 'chain-bad'; ?>">
            <h3>Case Submission History</h3>
            <?php if ($submissionChain['error']): ?>
            <p class="chain-headline">Error checking chain: <?php echo htmlspecialchars($submissionChain['error']); ?></p>
            <?php elseif ($submissionChain['ok']): ?>
            <p class="chain-headline">&#10003; All <?php echo (int) $submissionChain['total']; ?> records verified</p>
            <?php else: ?>
            <p class="chain-headline">&#10007; Tampering detected</p>
            <p class="chain-detail">Hash chain broken at record(s):
                <?php echo htmlspecialchars(implode(', ', $submissionChain['broken_hash']) ?: 'none'); ?></p>
            <p class="chain-detail">HMAC broken at record(s):
                <?php echo htmlspecialchars(implode(', ', $submissionChain['broken_hmac']) ?: 'none'); ?></p>
            <?php endif; ?>
        </div>
    </div>
    </div>

    <div class="logs-main">
    <h3 style="margin-top:0;">Case History</h3>
    <div class="table-scroll">
    <table class="logs-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Job ID</th>
                <th>Job Ref</th>
                <th>Action</th>
                <th>Changed By</th>
                <th>Changed At</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($recentCaseHistory)): ?>
            <tr>
                <td colspan="6">No case history yet.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($recentCaseHistory as $row): ?>
            <tr>
                <td><?php echo (int) $row['id']; ?></td>
                <td><?php echo (int) $row['ref_id']; ?></td>
                <td><?php echo htmlspecialchars($row['ref_name'] ?? '—'); ?></td>
                <td><?php echo action_badge($row['action']); ?></td>
                <td><?php echo htmlspecialchars($row['changed_by_name'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($row['changed_at']); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php echo render_pagination('case', $casePagination['page'], $casePagination['totalPages'], $casePagination['showAll'], $casePagination['total']); ?>

    <h3 style="margin-top:30px;">Exhibit History</h3>
    <div class="table-scroll">
    <table class="logs-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Exhibit ID</th>
                <th>Exhibit Ref</th>
                <th>Action</th>
                <th>Changed By</th>
                <th>Changed At</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($recentExhibitHistory)): ?>
            <tr>
                <td colspan="6">No exhibit history yet.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($recentExhibitHistory as $row): ?>
            <tr>
                <td><?php echo (int) $row['id']; ?></td>
                <td><?php echo (int) $row['ref_id']; ?></td>
                <td><?php echo htmlspecialchars($row['ref_name'] ?? '—'); ?></td>
                <td><?php echo action_badge($row['action']); ?></td>
                <td><?php echo htmlspecialchars($row['changed_by_name'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($row['changed_at']); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php echo render_pagination('exhibit', $exhibitPagination['page'], $exhibitPagination['totalPages'], $exhibitPagination['showAll'], $exhibitPagination['total']); ?>

    <h3 style="margin-top:30px;">Produced Item History</h3>
    <div class="table-scroll">
    <table class="logs-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Item ID</th>
                <th>Item Ref</th>
                <th>Action</th>
                <th>Changed By</th>
                <th>Changed At</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($recentCaseItemHistory)): ?>
            <tr>
                <td colspan="6">No case item history yet.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($recentCaseItemHistory as $row): ?>
            <tr>
                <td><?php echo (int) $row['id']; ?></td>
                <td><?php echo (int) $row['ref_id']; ?></td>
                <td><?php echo htmlspecialchars($row['ref_name'] ?? '—'); ?></td>
                <td><?php echo action_badge($row['action']); ?></td>
                <td><?php echo htmlspecialchars($row['changed_by_name'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($row['changed_at']); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php echo render_pagination('item', $itemPagination['page'], $itemPagination['totalPages'], $itemPagination['showAll'], $itemPagination['total']); ?>

    <h3 style="margin-top:30px;">System &amp; Admin Activity</h3>
    <p style="color:var(--polaris-text-dim);">Changes to lookup tables (case/exhibit types, locations, forces,
        operations, customers, asset types/locations), users, tasks, assets, and system settings.
        Plain log, not part of the tamper-evident chains above.</p>
    <div class="table-scroll">
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
    <?php echo render_pagination('audit', $auditPagination['page'], $auditPagination['totalPages'], $auditPagination['showAll'], $auditPagination['total']); ?>
    </div>
    </div>
</div>

<style>
.explainer {
    max-width: 800px;
    color: var(--polaris-text-dim);
    font-size: 14px;
    line-height: 1.5;
    margin-bottom: 10px;
}

.explainer summary {
    cursor: pointer;
    color: var(--polaris-text-secondary);
    font-size: 13px;
    padding: 4px 0;
}

.explainer summary:hover {
    color: var(--polaris-text);
}

.explainer[open] summary {
    margin-bottom: 8px;
}

.explainer code {
    background: var(--polaris-divider);
    padding: 1px 5px;
    border-radius: 3px;
}

.logs-layout {
    display: flex;
    align-items: flex-start;
    gap: 20px;
    margin-top: 20px;
}

.logs-main {
    flex: 1;
    min-width: 0;
}

.checks-sidebar {
    flex: 0 0 340px;
}

@media (max-width: 900px) {
    .logs-layout {
        flex-direction: column;
    }

    .checks-sidebar {
        flex: 1 1 auto;
        width: 100%;
    }
}

.chain-status {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.chain-card {
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

.table-scroll {
    width: 100%;
    overflow-x: auto;
}

.logs-table {
    width: 100%;
    min-width: 700px;
    border-collapse: collapse;
    margin-top: 10px;
}

.pagination {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 10px;
    font-size: 13px;
}

.page-link {
    color: var(--polaris-accent);
    text-decoration: none;
    padding: 4px 10px;
    border: 1px solid var(--polaris-border);
    border-radius: 3px;
}

.page-link:hover {
    background: var(--polaris-divider);
}

.page-link.disabled {
    pointer-events: none;
    opacity: 0.35;
}

.page-link.view-all {
    margin-left: auto;
}

.page-info {
    color: var(--polaris-text-secondary);
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
