<?php
// spaceport/view_submission.php
//
// One page for: the officer checking their own submission's status, a
// reviewer triaging it (approve/reject/request info), and - once a
// reviewer has asked for more info - the officer amending and resubmitting.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/permissions.php';
require_once '../includes/integrity.php';
require_once '../includes/spaceport.php';

$user_id = (int) $_SESSION['user_id'];
$submission_id = isset($_GET['submission_id']) ? intval($_GET['submission_id']) : 0;

$stmt = $conn->prepare("SELECT * FROM submissions WHERE submission_id = ?");
$stmt->bind_param("i", $submission_id);
$stmt->execute();
$submission = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$submission) {
    header("Location: ../dashboard.php");
    exit();
}

$canReview = user_can($conn, $user_id, 'submission_review');
if (!user_can_view_submission($submission, $user_id, $canReview)) {
    echo '<p style="color: var(--polaris-danger);">You do not have permission to view this submission.</p>';
    exit();
}
$isOwner = (int) $submission['submitted_by'] === $user_id;

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canReview) {
    $action = $_POST['action'] ?? '';
    if ($action === 'approve') {
        $jobId = approve_submission($conn, $submission_id, $user_id);
        if ($jobId) {
            header("Location: ../cargo_hold/job.php?job_id=$jobId");
            exit();
        }
        $message = "Could not approve this submission.";
    } elseif ($action === 'reject') {
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') {
            $message = "A reason is required to reject a submission.";
        } elseif (reject_submission($conn, $submission_id, $user_id, $reason)) {
            $message = "Submission rejected.";
        } else {
            $message = "Could not reject this submission.";
        }
    } elseif ($action === 'more_info') {
        $note = trim($_POST['note'] ?? '');
        if ($note === '') {
            $message = "Please describe what's needed from the submitting officer.";
        } elseif (request_submission_info($conn, $submission_id, $user_id, $note)) {
            $message = "More information requested.";
        } else {
            $message = "Could not request more information.";
        }
    }
    if ($message === '' || strpos($message, 'Could not') === false) {
        $stmt = $conn->prepare("SELECT * FROM submissions WHERE submission_id = ?");
        $stmt->bind_param("i", $submission_id);
        $stmt->execute();
        $submission = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $isOwner && $submission['status'] === 'More Info Requested') {
    $oic = trim($_POST['oic'] ?? '');
    $suspect = trim($_POST['suspect'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $incident_number = trim($_POST['incident_number'] ?? '');
    $external_reference = trim($_POST['external_reference'] ?? '');

    $stmt = $conn->prepare("UPDATE submissions SET oic=?, suspect=?, initial_summary=?, incident_number=?, external_reference=?, status='Pending' WHERE submission_id=?");
    $stmt->bind_param("sssssi", $oic, $suspect, $description, $incident_number, $external_reference, $submission_id);
    $stmt->execute();
    $stmt->close();

    insert_history_row($conn, 'submission_history', $submission_id, 'RESUBMITTED', $user_id, json_encode([
        'oic' => $oic, 'suspect' => $suspect,
    ]));
    notify_new_submission($conn, $submission_id);
    $message = "Resubmitted for review.";

    $stmt = $conn->prepare("SELECT * FROM submissions WHERE submission_id = ?");
    $stmt->bind_param("i", $submission_id);
    $stmt->execute();
    $submission = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Related records.
$caseTypeName = '';
if ($submission['case_type_id']) {
    $r = $conn->query("SELECT type_name FROM case_types WHERE case_type_id = " . (int) $submission['case_type_id']);
    $caseTypeName = $r && ($row = $r->fetch_assoc()) ? $row['type_name'] : '';
}

$contacts = [];
$cIds = array_filter([$submission['contact_primary_id'], $submission['contact_secondary_id']]);
if (!empty($cIds)) {
    $ids = implode(',', array_map('intval', $cIds));
    $r = $conn->query("SELECT contact_id, name, role_title, phone, email FROM external_contacts WHERE contact_id IN ($ids)");
    while ($row = $r->fetch_assoc()) { $contacts[$row['contact_id']] = $row; }
}

$exhibits = [];
$stmt = $conn->prepare("
    SELECT se.*, et.type_name FROM submitted_exhibits se
    LEFT JOIN exhibit_types et ON se.exhibit_type_id = et.exhibit_type_id
    WHERE se.submission_id = ?
");
$stmt->bind_param("i", $submission_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) { $exhibits[] = $row; }
$stmt->close();

$keyDates = [];
$stmt = $conn->prepare("SELECT event_date, label FROM submission_key_dates WHERE submission_id = ? ORDER BY event_date");
$stmt->bind_param("i", $submission_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) { $keyDates[] = $row; }
$stmt->close();

$answers = [];
$stmt = $conn->prepare("
    SELECT qf.field_label, qv.value FROM submission_question_values qv
    JOIN submission_question_fields qf ON qv.field_id = qf.field_id
    WHERE qv.submission_id = ?
");
$stmt->bind_param("i", $submission_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) { $answers[] = $row; }
$stmt->close();

$photos = get_subject_photos($conn, $submission_id);

$history = [];
$stmt = $conn->prepare("
    SELECT sh.changed_at, sh.action, sh.changes, CONCAT(u.first_name, ' ', u.last_name) AS changed_by_name
    FROM submission_history sh LEFT JOIN users u ON sh.changed_by = u.id
    WHERE sh.submission_id = ? ORDER BY sh.changed_at DESC
");
$stmt->bind_param("i", $submission_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) { $history[] = $row; }
$stmt->close();

include '../header.php';
?>
<style>
    .content-wrapper { max-width: 900px; margin: 120px auto 40px auto; padding: 20px; background: var(--polaris-surface-deep); border-radius: 5px; box-shadow: 0 2px 10px rgba(255,255,255,0.1); }
    h2 { margin-bottom: 4px; }
    .ref { font-family: monospace; color: var(--polaris-text-dim); margin-bottom: 20px; }
    h3 { margin: 28px 0 10px; padding-top: 16px; border-top: 1px solid var(--polaris-border); }
    .status-pill { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 13px; margin-left: 10px; }
    .status-Pending { background: var(--polaris-warning); color: #1a1a1a; }
    .status-Approved { background: var(--polaris-success-strong); color: #fff; }
    .status-Rejected { background: var(--polaris-danger); color: #fff; }
    .status-More_Info_Requested { background: var(--polaris-accent); color: #fff; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    th, td { border: 1px solid var(--polaris-border); padding: 6px 8px; text-align: left; font-size: 13px; }
    th { background: var(--polaris-divider); }
    .field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 20px; font-size: 14px; }
    .field-grid dt { color: var(--polaris-text-dim); margin: 0; }
    .field-grid dd { margin: 0 0 8px; }
    .message { margin-bottom: 15px; padding: 10px; background: var(--polaris-divider); border-left: 4px solid var(--polaris-accent); font-size: 14px; }
    .action-box { border: 1px solid var(--polaris-border); border-radius: 6px; padding: 15px; margin-bottom: 10px; }
    .action-box h4 { margin: 0 0 10px; }
    textarea, input[type="text"] { width: 100%; padding: 8px; border: 1px solid var(--polaris-border); border-radius: 4px; background: var(--polaris-bg); color: var(--polaris-text); box-sizing: border-box; margin-bottom: 8px; }
    .btn-row { display: flex; gap: 10px; }
    button { padding: 6px 14px; border: none; border-radius: 3px; cursor: pointer; font-size: 14px; color: var(--polaris-text); }
    .btn-approve { background: var(--polaris-success-strong); }
    .btn-reject { background: var(--polaris-error-bg); }
    .btn-more-info { background: var(--polaris-accent); }
    .info-note { background: var(--polaris-panel-alt); padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; }
    .history-action { font-size: 12px; padding: 2px 8px; border-radius: 10px; background: var(--polaris-panel-alt); white-space: nowrap; }
    .history-action-APPROVED { background: var(--polaris-success-strong); color: #fff; }
    .history-action-REJECTED { background: var(--polaris-danger); color: #fff; }
    .history-action-MORE_INFO_REQUESTED { background: var(--polaris-accent); color: #fff; }
    .change-row { margin-bottom: 4px; }
    .change-row:last-child { margin-bottom: 0; }
    .change-old { color: var(--polaris-danger-alt); }
    .change-new { color: var(--polaris-success-strong); }
    .change-none { color: var(--polaris-text-faint); }
    .btn-link { display: inline-block; padding: 4px 12px; font-size: 13px; border-radius: 3px; background: var(--polaris-accent); color: var(--polaris-text); text-decoration: none; }
    .btn-link:hover { background: var(--polaris-accent-hover); }
    .subject-photo-preview { width: 160px; height: 160px; object-fit: cover; border-radius: 8px; display: block; margin-bottom: 8px; }
    .section-hint { font-size: 13px; color: var(--polaris-text-dim); }
</style>
<div class="content-wrapper">
    <h2><?php echo htmlspecialchars(submission_ref($submission_id)); ?>
        <span class="status-pill status-<?php echo str_replace(' ', '_', $submission['status']); ?>"><?php echo htmlspecialchars($submission['status']); ?></span>
    </h2>
    <p class="ref">Submitted <?php echo htmlspecialchars($submission['submitted_at']); ?></p>

    <?php if (!empty($message)): ?>
    <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if ($submission['status'] === 'More Info Requested' && !empty($submission['info_requested_note'])): ?>
    <div class="info-note"><strong>Reviewer asked for:</strong> <?php echo nl2br(htmlspecialchars($submission['info_requested_note'])); ?></div>
    <?php endif; ?>
    <?php if ($submission['status'] === 'Rejected' && !empty($submission['decision_reason'])): ?>
    <div class="info-note"><strong>Rejected:</strong> <?php echo nl2br(htmlspecialchars($submission['decision_reason'])); ?></div>
    <?php endif; ?>

    <h3>Case Details</h3>
    <dl class="field-grid">
        <dt>Case Type</dt><dd><?php echo htmlspecialchars($caseTypeName); ?></dd>
        <dt>OIC</dt><dd><?php echo htmlspecialchars($submission['oic'] ?? ''); ?></dd>
        <dt>Suspect</dt><dd><?php echo htmlspecialchars($submission['suspect'] ?? ''); ?></dd>
        <dt>Incident Number</dt><dd><?php echo htmlspecialchars($submission['incident_number'] ?? ''); ?></dd>
        <dt>External Reference</dt><dd><?php echo htmlspecialchars($submission['external_reference'] ?? ''); ?></dd>
        <dt>Fingerprints / DNA / Malware</dt>
        <dd><?php echo $submission['fingerprints'] ? 'Fingerprints ' : ''; echo $submission['dna'] ? 'DNA ' : ''; echo $submission['malware'] ? 'Malware' : ''; ?></dd>
    </dl>
    <p><?php echo nl2br(htmlspecialchars($submission['initial_summary'] ?? '')); ?></p>

    <?php if (!empty($contacts)): ?>
    <h3>Contacts</h3>
    <table>
        <tr><th>Name</th><th>Role</th><th>Phone</th><th>Email</th></tr>
        <?php foreach ($contacts as $c): ?>
        <tr><td><?php echo htmlspecialchars($c['name']); ?></td><td><?php echo htmlspecialchars($c['role_title'] ?? ''); ?></td><td><?php echo htmlspecialchars($c['phone'] ?? ''); ?></td><td><?php echo htmlspecialchars($c['email'] ?? ''); ?></td></tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <?php if (!empty($exhibits)): ?>
    <h3>Declared Exhibits</h3>
    <table>
        <tr><th>Exhibit Ref</th><th>Type</th><th>Description</th><th>Bag No.</th><th>Seizing Officer</th><th>Seizing Location</th><th>Status</th></tr>
        <?php foreach ($exhibits as $ex): ?>
        <tr>
            <td><?php echo htmlspecialchars($ex['exhibit_ref'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($ex['type_name'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($ex['description'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($ex['bag_number'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($ex['seizing_officer'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($ex['seizing_location'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($ex['reconcile_status']); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <?php if (!empty($keyDates)): ?>
    <h3>Key Dates</h3>
    <table>
        <tr><th>Date</th><th>What</th></tr>
        <?php foreach ($keyDates as $kd): ?>
        <tr><td><?php echo htmlspecialchars($kd['event_date']); ?></td><td><?php echo htmlspecialchars($kd['label']); ?></td></tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <?php if (!empty($answers)): ?>
    <h3>Additional Questions</h3>
    <dl class="field-grid">
        <?php foreach ($answers as $a): ?>
        <dt><?php echo htmlspecialchars($a['field_label']); ?></dt><dd><?php echo htmlspecialchars($a['value']); ?></dd>
        <?php endforeach; ?>
    </dl>
    <?php endif; ?>

    <?php if (!empty($photos)): ?>
    <h3>Subject Photo</h3>
    <img class="subject-photo-preview"
        src="download_subject_photo.php?photo_id=<?php echo (int) $photos[0]['photo_id']; ?>" alt="Subject photo">
    <?php if (count($photos) > 1): ?>
    <table>
        <tr><th>Version</th><th>Uploaded By</th><th>Uploaded At</th><th></th></tr>
        <?php foreach ($photos as $i => $p): ?>
        <tr>
            <td>v<?php echo (int) $p['version']; ?><?php echo $i === 0 ? ' (current)' : ''; ?></td>
            <td><?php echo htmlspecialchars($p['uploaded_by_name'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($p['uploaded_at']); ?></td>
            <td><a class="btn-link" href="download_subject_photo.php?photo_id=<?php echo (int) $p['photo_id']; ?>" onclick="return viewSubjectPhoto(<?php echo (int) $p['photo_id']; ?>);">View</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php else: ?>
    <p class="section-hint">Uploaded by <?php echo htmlspecialchars($photos[0]['uploaded_by_name'] ?? ''); ?> on <?php echo htmlspecialchars($photos[0]['uploaded_at']); ?>.</p>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($isOwner && $submission['status'] === 'More Info Requested'): ?>
    <h3>Amend &amp; Resubmit</h3>
    <p style="font-size:13px; color:var(--polaris-text-dim);">Exhibits, key dates, and answers already declared aren't editable here - only the core case details.</p>
    <form method="post">
        <label>OIC</label>
        <input type="text" name="oic" value="<?php echo htmlspecialchars($submission['oic'] ?? ''); ?>">
        <label>Suspect</label>
        <input type="text" name="suspect" value="<?php echo htmlspecialchars($submission['suspect'] ?? ''); ?>">
        <label>Incident Number</label>
        <input type="text" name="incident_number" value="<?php echo htmlspecialchars($submission['incident_number'] ?? ''); ?>">
        <label>External Reference</label>
        <input type="text" name="external_reference" value="<?php echo htmlspecialchars($submission['external_reference'] ?? ''); ?>">
        <label>Case Background</label>
        <textarea name="description" rows="4"><?php echo htmlspecialchars($submission['initial_summary'] ?? ''); ?></textarea>
        <button type="submit" class="btn-approve">Resubmit for Review</button>
    </form>
    <?php endif; ?>

    <?php if ($canReview && in_array($submission['status'], ['Pending', 'More Info Requested'], true)): ?>
    <h3>Review</h3>
    <div class="action-box">
        <h4>Approve</h4>
        <form method="post" onsubmit="return confirm('Approve this submission and create the case?');">
            <input type="hidden" name="action" value="approve">
            <button type="submit" class="btn-approve">Approve &amp; Create Case</button>
        </form>
    </div>
    <div class="action-box">
        <h4>Request More Information</h4>
        <form method="post">
            <input type="hidden" name="action" value="more_info">
            <textarea name="note" rows="3" placeholder="What's needed from the submitting officer?" required></textarea>
            <button type="submit" class="btn-more-info">Request More Info</button>
        </form>
    </div>
    <div class="action-box">
        <h4>Reject</h4>
        <form method="post">
            <input type="hidden" name="action" value="reject">
            <textarea name="reason" rows="3" placeholder="Reason for rejection" required></textarea>
            <button type="submit" class="btn-reject">Reject</button>
        </form>
    </div>
    <?php endif; ?>

    <h3>History</h3>
    <table>
        <tr><th>When</th><th>Action</th><th>By</th><th>Details</th></tr>
        <?php foreach ($history as $h):
            $actionLabels = [
                'CREATE' => 'Submitted', 'UPDATE' => 'Updated', 'MORE_INFO_REQUESTED' => 'More Info Requested',
                'RESUBMITTED' => 'Resubmitted', 'APPROVED' => 'Approved', 'REJECTED' => 'Rejected',
            ];
        ?>
        <tr>
            <td><?php echo htmlspecialchars($h['changed_at']); ?></td>
            <td><span class="history-action history-action-<?php echo htmlspecialchars($h['action']); ?>"><?php echo htmlspecialchars($actionLabels[$h['action']] ?? $h['action']); ?></span></td>
            <td><?php echo htmlspecialchars($h['changed_by_name'] ?? ''); ?></td>
            <td><?php echo render_history_changes_html($h['changes']); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

<script>
// Same small-popup convention as the exhibit photo/document uploaders on
// captains_log/examination.php - a sized window showing the image directly
// (download_subject_photo.php now sends the real image Content-Type, so the
// browser renders it instead of downloading it) rather than a download or a
// full new tab.
function viewSubjectPhoto(photoId) {
    const width = 700;
    const height = 700;
    const left = (screen.width - width) / 2;
    const top = (screen.height - height) / 2;
    window.open(
        `download_subject_photo.php?photo_id=${photoId}`,
        'SubjectPhoto',
        `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=yes`
    );
    return false;
}
</script>
</div>
