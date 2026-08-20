<?php
// spaceport/all_submissions.php
//
// The full, filterable list of every submission regardless of status -
// review_queue.php only ever shows what's actionable (Pending / More Info
// Requested), so an Approved or Rejected submission had nowhere to browse
// to once it left that queue. This is that browse view, with the linked
// case ref shown once a submission has been approved into a real job.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/permissions.php';
require_once '../includes/spaceport.php';
require_permission($conn, 'submission_review');

$validStatuses = ['Pending', 'More Info Requested', 'Approved', 'Rejected'];
$statusFilter = in_array($_GET['status'] ?? '', $validStatuses, true) ? $_GET['status'] : '';
$search = trim($_GET['search'] ?? '');

$where = [];
$params = [];
$types = '';

if ($statusFilter !== '') {
    $where[] = 's.status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}
if ($search !== '') {
    // Matches the submission's own ref (SUB-000123), suspect, OIC, or the
    // case ref it was approved into.
    $where[] = '(s.suspect LIKE ? OR s.oic LIKE ? OR j.custom_ref LIKE ? OR CONCAT(\'SUB-\', LPAD(s.submission_id, 6, \'0\')) LIKE ?)';
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}

$sql = "
    SELECT s.submission_id, s.status, s.suspect, s.oic, s.submitted_at, s.job_id,
           ct.type_name, j.custom_ref,
           CONCAT(u.first_name, ' ', u.last_name) AS submitted_by_name
    FROM submissions s
    LEFT JOIN case_types ct ON s.case_type_id = ct.case_type_id
    LEFT JOIN jobs j ON s.job_id = j.job_id
    LEFT JOIN users u ON s.submitted_by = u.id
";
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY s.submitted_at DESC';

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$submissions = [];
while ($row = $result->fetch_assoc()) { $submissions[] = $row; }
$stmt->close();

include '../header.php';
?>
<style>
    .content-wrapper { max-width: 1100px; margin: 120px auto 40px auto; padding: 20px; background: var(--polaris-surface-deep); border-radius: 5px; box-shadow: 0 2px 10px rgba(255,255,255,0.1); }
    h2 { text-align: center; margin-bottom: 20px; }
    .filter-bar { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; align-items: end; }
    .filter-bar .field { margin: 0; }
    .filter-bar label { display: block; font-size: 12px; color: var(--polaris-text-dim); margin-bottom: 4px; }
    .filter-bar select, .filter-bar input[type="text"] { padding: 8px; border: 1px solid var(--polaris-border); border-radius: 4px; background: var(--polaris-bg); color: var(--polaris-text); }
    .filter-bar button, .filter-bar a.clear-link { padding: 8px 14px; border: none; border-radius: 4px; background: var(--polaris-accent); color: var(--polaris-text); cursor: pointer; text-decoration: none; font-size: 14px; }
    .filter-bar button:hover, .filter-bar a.clear-link:hover { background: var(--polaris-accent-hover); }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid var(--polaris-border); padding: 8px; text-align: left; font-size: 14px; }
    th { background: var(--polaris-divider); }
    .status-pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; white-space: nowrap; }
    .status-Pending { background: var(--polaris-warning); color: #1a1a1a; }
    .status-Approved { background: var(--polaris-success-strong); color: #fff; }
    .status-Rejected { background: var(--polaris-danger); color: #fff; }
    .status-More_Info_Requested { background: var(--polaris-accent); color: #fff; }
    .btn-small { display: inline-block; padding: 4px 10px; font-size: 13px; border-radius: 3px; background: var(--polaris-accent); color: var(--polaris-text); text-decoration: none; white-space: nowrap; }
    .btn-small:hover { background: var(--polaris-accent-hover); }
    .btn-row { display: flex; gap: 6px; }
    .result-count { color: var(--polaris-text-dim); font-size: 13px; margin-bottom: 10px; }
</style>
<div class="content-wrapper">
    <h2>All Submissions</h2>

    <form method="get" class="filter-bar">
        <div class="field">
            <label for="status">Status</label>
            <select name="status" id="status">
                <option value="">All statuses</option>
                <?php foreach ($validStatuses as $st): ?>
                <option value="<?php echo htmlspecialchars($st); ?>" <?php echo $statusFilter === $st ? 'selected' : ''; ?>><?php echo htmlspecialchars($st); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="search">Search</label>
            <input type="text" name="search" id="search" placeholder="Submission ref, case ref, suspect, OIC..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <button type="submit">Filter</button>
        <?php if ($statusFilter !== '' || $search !== ''): ?>
        <a class="clear-link" href="all_submissions.php">Clear</a>
        <?php endif; ?>
    </form>

    <p class="result-count"><?php echo count($submissions); ?> submission(s)</p>

    <table>
        <tr><th>Ref</th><th>Case Type</th><th>Suspect</th><th>OIC</th><th>Submitted By</th><th>Status</th><th>Submitted</th><th>Case Ref</th><th></th></tr>
        <?php if (empty($submissions)): ?>
        <tr><td colspan="9">No submissions match.</td></tr>
        <?php else: ?>
        <?php foreach ($submissions as $s): ?>
        <tr>
            <td><?php echo htmlspecialchars(submission_ref($s['submission_id'])); ?></td>
            <td><?php echo htmlspecialchars($s['type_name'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($s['suspect'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($s['oic'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($s['submitted_by_name'] ?? ''); ?></td>
            <td><span class="status-pill status-<?php echo str_replace(' ', '_', $s['status']); ?>"><?php echo htmlspecialchars($s['status']); ?></span></td>
            <td><?php echo htmlspecialchars($s['submitted_at']); ?></td>
            <td><?php echo htmlspecialchars($s['custom_ref'] ?? ''); ?></td>
            <td class="btn-row">
                <a class="btn-small" href="view_submission.php?submission_id=<?php echo $s['submission_id']; ?>">View Submission</a>
                <?php if ($s['job_id']): ?>
                <a class="btn-small" href="../cargo_hold/job.php?job_id=<?php echo (int) $s['job_id']; ?>">View Case</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
    </table>
</div>
