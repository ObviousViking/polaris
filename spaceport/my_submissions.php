<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/permissions.php';
require_once '../includes/spaceport.php';
require_permission($conn, 'submission_view_own');

$user_id = (int) $_SESSION['user_id'];
$canViewFullCase = user_can($conn, $user_id, 'case_view');

$submissions = [];
$stmt = $conn->prepare("
    SELECT s.submission_id, s.status, s.suspect, s.submitted_at, s.job_id, ct.type_name
    FROM submissions s
    LEFT JOIN case_types ct ON s.case_type_id = ct.case_type_id
    WHERE s.submitted_by = ?
    ORDER BY s.submitted_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) { $submissions[] = $row; }
$stmt->close();

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
    .status-Approved { background: var(--polaris-success-strong); color: #fff; }
    .status-Rejected { background: var(--polaris-danger); color: #fff; }
    .status-More_Info_Requested { background: var(--polaris-accent); color: #fff; }
    .add-btn { display: inline-block; margin-top: 15px; padding: 5px 10px; background: var(--polaris-accent); color: var(--polaris-text); border-radius: 3px; text-decoration: none; font-size: 14px; }
    .btn-small { display: inline-block; padding: 4px 10px; font-size: 13px; border-radius: 3px; background: var(--polaris-accent); color: var(--polaris-text); text-decoration: none; white-space: nowrap; }
    .btn-small:hover { background: var(--polaris-accent-hover); }
    .btn-row { display: flex; gap: 6px; }
</style>
<div class="content-wrapper">
    <h2>My Submissions</h2>
    <table>
        <tr><th>Ref</th><th>Case Type</th><th>Suspect</th><th>Status</th><th>Submitted</th><th></th></tr>
        <?php if (empty($submissions)): ?>
        <tr><td colspan="6">No submissions yet.</td></tr>
        <?php else: ?>
        <?php foreach ($submissions as $s):
            $caseUrl = $s['job_id'] ? ($canViewFullCase ? "../cargo_hold/job.php?job_id={$s['job_id']}" : "view_case.php?job_id={$s['job_id']}") : null;
        ?>
        <tr>
            <td><?php echo htmlspecialchars(submission_ref($s['submission_id'])); ?></td>
            <td><?php echo htmlspecialchars($s['type_name'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($s['suspect'] ?? ''); ?></td>
            <td><span class="status-pill status-<?php echo str_replace(' ', '_', $s['status']); ?>"><?php echo htmlspecialchars($s['status']); ?></span></td>
            <td><?php echo htmlspecialchars($s['submitted_at']); ?></td>
            <td class="btn-row">
                <a class="btn-small" href="view_submission.php?submission_id=<?php echo $s['submission_id']; ?>">View Submission</a>
                <?php if ($caseUrl): ?>
                <a class="btn-small" href="<?php echo htmlspecialchars($caseUrl); ?>">View Case &amp; Updates</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
    </table>
    <a href="submit_case.php" class="add-btn">Submit New Case</a>
</div>
