<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/permissions.php';
require_once '../includes/spaceport.php';
require_permission($conn, 'submission_review');

$submissions = [];
$result = $conn->query("
    SELECT s.submission_id, s.status, s.suspect, s.submitted_at, ct.type_name,
           CONCAT(u.first_name, ' ', u.last_name) AS submitted_by_name
    FROM submissions s
    LEFT JOIN case_types ct ON s.case_type_id = ct.case_type_id
    LEFT JOIN users u ON s.submitted_by = u.id
    WHERE s.status IN ('Pending', 'More Info Requested')
    ORDER BY s.submitted_at ASC
");
while ($row = $result->fetch_assoc()) { $submissions[] = $row; }

include '../header.php';
?>
<style>
    .content-wrapper { max-width: 1000px; margin: 120px auto 40px auto; padding: 20px; background: var(--polaris-surface-deep); border-radius: 5px; box-shadow: 0 2px 10px rgba(255,255,255,0.1); }
    h2 { text-align: center; margin-bottom: 20px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid var(--polaris-border); padding: 8px; text-align: left; font-size: 14px; }
    th { background: var(--polaris-divider); }
    .status-pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; }
    .status-Pending { background: var(--polaris-warning); color: #1a1a1a; }
    .status-More_Info_Requested { background: var(--polaris-accent); color: #fff; }
    .btn-small { display: inline-block; padding: 4px 10px; font-size: 13px; border-radius: 3px; background: var(--polaris-accent); color: var(--polaris-text); text-decoration: none; }
    .btn-small:hover { background: var(--polaris-accent-hover); }
</style>
<div class="content-wrapper">
    <h2>Submission Review Queue</h2>
    <p style="text-align:center; margin-top:-10px;"><a class="btn-small" href="all_submissions.php">View All Submissions (including approved/rejected)</a></p>
    <table>
        <tr><th>Ref</th><th>Case Type</th><th>Suspect</th><th>Submitted By</th><th>Status</th><th>Submitted</th><th></th></tr>
        <?php if (empty($submissions)): ?>
        <tr><td colspan="7">Nothing awaiting review.</td></tr>
        <?php else: ?>
        <?php foreach ($submissions as $s): ?>
        <tr>
            <td><?php echo htmlspecialchars(submission_ref($s['submission_id'])); ?></td>
            <td><?php echo htmlspecialchars($s['type_name'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($s['suspect'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($s['submitted_by_name'] ?? ''); ?></td>
            <td><span class="status-pill status-<?php echo str_replace(' ', '_', $s['status']); ?>"><?php echo htmlspecialchars($s['status']); ?></span></td>
            <td><?php echo htmlspecialchars($s['submitted_at']); ?></td>
            <td><a class="btn-small" href="view_submission.php?submission_id=<?php echo $s['submission_id']; ?>">Review Submission</a></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
    </table>
</div>
