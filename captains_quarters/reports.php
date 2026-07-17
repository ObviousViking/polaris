<?php
// reports.php
//
// System Reports - operational KPIs pulled directly from the schema.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';

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

function scalar(mysqli $conn, string $sql)
{
    $result = $conn->query($sql);
    if (!$result) {
        return 0;
    }
    $row = $result->fetch_row();
    return $row ? $row[0] : 0;
}

// --- KPI tiles ---
// "Overdue"/"Due soon" use strategy_due/strategy_complete rather than
// job_status, since status names are a free-text lookup table.
$totalCases = (int) scalar($conn, "SELECT COUNT(*) FROM jobs");
$overdueCases = (int) scalar($conn, "SELECT COUNT(*) FROM jobs WHERE strategy_complete IS NULL AND strategy_due < NOW()");
$dueSoonCases = (int) scalar($conn, "SELECT COUNT(*) FROM jobs WHERE strategy_complete IS NULL AND strategy_due BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)");
$checkedInExhibits = (int) scalar($conn, "SELECT COUNT(*) FROM exhibits WHERE time_out IS NULL AND deleted_at IS NULL");
$avgTurnaroundDays = scalar($conn, "SELECT AVG(TIMESTAMPDIFF(HOUR, time_in, time_out)) / 24 FROM exhibits WHERE time_out IS NOT NULL AND deleted_at IS NULL");
$avgTurnaroundDays = $avgTurnaroundDays !== null ? round((float) $avgTurnaroundDays, 1) : null;
$openTasks = (int) scalar($conn, "SELECT COUNT(*) FROM tasks WHERE status != 'completed' AND is_active = 1");

// --- Case status breakdown ---
$caseStatusRows = [];
$result = $conn->query("
    SELECT COALESCE(st.status_name, 'Unassigned') AS label, COUNT(*) AS c
    FROM jobs j
    LEFT JOIN job_status st ON j.status_id = st.status_id
    GROUP BY label
    ORDER BY c DESC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $caseStatusRows[] = $row;
    }
}

// --- Exhibit status breakdown (includes sub-exhibits) ---
$exhibitStatusRows = [];
$result = $conn->query("
    SELECT COALESCE(status, 'Unknown') AS label, COUNT(*) AS c
    FROM exhibits
    WHERE deleted_at IS NULL
    GROUP BY label
    ORDER BY c DESC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $exhibitStatusRows[] = $row;
    }
}

// --- Workload by analyst (open exhibits + open tasks per user) ---
// on_hold_exhibits is a subset of open_exhibits, called out separately.
$workloadRows = [];
$result = $conn->query("
    SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name,
        (SELECT COUNT(*) FROM exhibits e WHERE e.allocated_to = u.id AND e.status != 'Complete' AND e.deleted_at IS NULL) AS open_exhibits,
        (SELECT COUNT(*) FROM exhibits e WHERE e.allocated_to = u.id AND e.status = 'On Hold' AND e.deleted_at IS NULL) AS on_hold_exhibits,
        (SELECT COUNT(*) FROM tasks t WHERE t.assigned_to = u.id AND t.status != 'completed' AND t.is_active = 1) AS open_tasks
    FROM users u
    WHERE u.is_active = 1
    HAVING open_exhibits > 0 OR open_tasks > 0
    ORDER BY (open_exhibits + open_tasks) DESC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $workloadRows[] = $row;
    }
}

// --- Throughput, last 6 months (zero-filled so quiet months still show) ---
function last_n_months(int $n): array
{
    $months = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $ts = strtotime("-$i months");
        $months[date('Y-m', $ts)] = date('M Y', $ts);
    }
    return $months;
}

function zero_fill_month_counts(mysqli $conn, string $sql, array $monthLabels): array
{
    $counts = array_fill_keys(array_keys($monthLabels), 0);
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if (isset($counts[$row['ym']])) {
                $counts[$row['ym']] = (int) $row['c'];
            }
        }
    }
    $rows = [];
    foreach ($monthLabels as $ym => $label) {
        $rows[] = ['label' => $label, 'c' => $counts[$ym]];
    }
    return $rows;
}

$monthLabels = last_n_months(6);
$casesPerMonth = zero_fill_month_counts($conn, "
    SELECT DATE_FORMAT(date_time, '%Y-%m') AS ym, COUNT(*) AS c
    FROM jobs
    WHERE date_time >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY ym
", $monthLabels);
$exhibitsPerMonth = zero_fill_month_counts($conn, "
    SELECT DATE_FORMAT(time_in, '%Y-%m') AS ym, COUNT(*) AS c
    FROM exhibits
    WHERE time_in >= DATE_SUB(NOW(), INTERVAL 6 MONTH) AND deleted_at IS NULL
    GROUP BY ym
", $monthLabels);

// Plain HTML bars sized as a % of the row max.
function render_bar_rows(array $rows, string $labelKey, string $valueKey): string
{
    if (empty($rows)) {
        return '<p class="empty-note">No data yet.</p>';
    }
    $max = max(array_column($rows, $valueKey));
    $max = $max > 0 ? $max : 1;
    $html = '<div class="bar-chart">';
    foreach ($rows as $row) {
        $pct = round(($row[$valueKey] / $max) * 100);
        $html .= '<div class="bar-row">'
            . '<div class="bar-label">' . htmlspecialchars((string) $row[$labelKey]) . '</div>'
            . '<div class="bar-track"><div class="bar-fill" style="width:' . $pct . '%;"></div></div>'
            . '<div class="bar-value">' . (int) $row[$valueKey] . '</div>'
            . '</div>';
    }
    $html .= '</div>';
    return $html;
}
?>

<div class="content-wrapper">
    <h2>System Reports</h2>
    <p style="color:var(--polaris-text-dim);">Draft v1 - operational snapshot across all cases and exhibits.
        "Overdue"/"Due soon" are measured against each case's Strategy Due date (see
        <a href="manage_sla.php<?php echo $embedded ? '?embedded=1' : ''; ?>">Configure SLA</a>).
        Deleted exhibits are excluded throughout.</p>

    <div class="kpi-grid">
        <div class="kpi-tile">
            <div class="kpi-value"><?php echo $totalCases; ?></div>
            <div class="kpi-label">Total Cases</div>
        </div>
        <div class="kpi-tile <?php echo $overdueCases > 0 ? 'kpi-critical' : 'kpi-good'; ?>">
            <div class="kpi-value"><?php echo $overdueCases; ?></div>
            <div class="kpi-label">Cases Overdue</div>
        </div>
        <div class="kpi-tile <?php echo $dueSoonCases > 0 ? 'kpi-warning' : 'kpi-good'; ?>">
            <div class="kpi-value"><?php echo $dueSoonCases; ?></div>
            <div class="kpi-label">Due Within 7 Days</div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-value"><?php echo $checkedInExhibits; ?></div>
            <div class="kpi-label">Exhibits Checked In</div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-value"><?php echo $avgTurnaroundDays !== null ? $avgTurnaroundDays . 'd' : '-'; ?></div>
            <div class="kpi-label">Avg Exhibit Turnaround</div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-value"><?php echo $openTasks; ?></div>
            <div class="kpi-label">Open Tasks</div>
        </div>
    </div>

    <div class="report-grid">
        <div class="report-card">
            <h3>Cases by Status</h3>
            <?php echo render_bar_rows($caseStatusRows, 'label', 'c'); ?>
        </div>

        <div class="report-card">
            <h3>Exhibits by Status</h3>
            <?php echo render_bar_rows($exhibitStatusRows, 'label', 'c'); ?>
        </div>

        <div class="report-card">
            <h3>Cases Opened (Last 6 Months)</h3>
            <?php echo render_bar_rows($casesPerMonth, 'label', 'c'); ?>
        </div>

        <div class="report-card">
            <h3>Exhibits Booked In (Last 6 Months)</h3>
            <?php echo render_bar_rows($exhibitsPerMonth, 'label', 'c'); ?>
        </div>
    </div>

    <div class="report-card" style="margin-top:20px;">
        <h3>Workload by Analyst</h3>
        <?php if (empty($workloadRows)): ?>
        <p class="empty-note">No open exhibits or tasks currently allocated to anyone.</p>
        <?php else: ?>
        <table class="workload-table">
            <thead>
                <tr>
                    <th>Analyst</th>
                    <th>Open Exhibits</th>
                    <th>On Hold</th>
                    <th>Open Tasks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($workloadRows as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                    <td><?php echo (int) $row['open_exhibits']; ?></td>
                    <td<?php echo $row['on_hold_exhibits'] > 0 ? ' class="cell-on-hold"' : ''; ?>>
                        <?php echo (int) $row['on_hold_exhibits']; ?>
                    </td>
                    <td><?php echo (int) $row['open_tasks']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<style>
.content-wrapper {
    max-width: 1100px;
    margin: <?php echo $embedded ? '0' : '120px'; ?> auto 40px auto;
    padding: 20px;
}

.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
    margin: 20px 0 30px;
}

.kpi-tile {
    background: var(--polaris-surface);
    border: 1px solid var(--polaris-border);
    border-radius: 8px;
    padding: 16px;
    text-align: center;
}

.kpi-value {
    font-size: 28px;
    font-weight: bold;
    color: var(--polaris-text);
}

.kpi-label {
    margin-top: 4px;
    font-size: 13px;
    color: var(--polaris-text-muted);
}

.kpi-tile.kpi-critical {
    border-color: var(--polaris-danger-alt);
}

.kpi-tile.kpi-critical .kpi-value {
    color: var(--polaris-danger-alt);
}

.kpi-tile.kpi-warning {
    border-color: var(--polaris-warning);
}

.kpi-tile.kpi-warning .kpi-value {
    color: var(--polaris-warning);
}

.kpi-tile.kpi-good {
    border-color: var(--polaris-success-strong);
}

.kpi-tile.kpi-good .kpi-value {
    color: var(--polaris-success-strong);
}

.report-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
    gap: 16px;
}

.report-card {
    background: var(--polaris-surface);
    border: 1px solid var(--polaris-border);
    border-radius: 8px;
    padding: 16px;
}

.report-card h3 {
    margin: 0 0 12px;
    font-size: 16px;
    color: var(--polaris-gray-e0);
}

.empty-note {
    color: var(--polaris-text-faint-2);
    font-size: 13px;
}

.bar-chart {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.bar-row {
    display: grid;
    grid-template-columns: 140px 1fr 36px;
    align-items: center;
    gap: 8px;
}

.bar-label {
    font-size: 13px;
    color: var(--polaris-text-secondary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.bar-track {
    background: var(--polaris-bg);
    border-radius: 3px;
    height: 14px;
    overflow: hidden;
}

.bar-fill {
    background: #2196f3;
    height: 100%;
    border-radius: 3px;
    transition: width 0.2s ease;
}

.bar-row:hover .bar-fill {
    background: #4fc3f7;
}

.bar-value {
    font-size: 13px;
    color: var(--polaris-text-secondary);
    text-align: right;
}

.workload-table {
    width: 100%;
    border-collapse: collapse;
}

.workload-table th,
.workload-table td {
    border: 1px solid var(--polaris-border);
    padding: 8px;
    text-align: left;
    font-size: 14px;
}

.workload-table th {
    background: var(--polaris-divider);
}

.cell-on-hold {
    background: rgba(240, 173, 78, 0.15);
    color: var(--polaris-warning);
    font-weight: bold;
}
</style>

</body>

</html>
