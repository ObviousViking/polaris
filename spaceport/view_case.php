<?php
// spaceport/view_case.php
//
// A limited, portal-scoped view of a case for the officer who submitted it
// (or anyone else who might later be granted access to it - see
// user_can_view_submission()'s note on case_access as future work). Not the
// full cargo_hold/job.php - just status and case updates, which is all a
// portal account needs.
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
$job_id = isset($_GET['job_id']) ? intval($_GET['job_id']) : 0;

$stmt = $conn->prepare("
    SELECT j.job_id, j.custom_ref, j.date_time, j.initial_summary, j.oic, j.incident_number, j.external_reference,
           ct.type_name AS case_type, st.status_name, s.submission_id, s.submitted_by
    FROM jobs j
    LEFT JOIN case_types ct ON j.case_type_id = ct.case_type_id
    LEFT JOIN job_status st ON j.status_id = st.status_id
    LEFT JOIN submissions s ON s.job_id = j.job_id
    WHERE j.job_id = ?
");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$case = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$case) {
    header("Location: spaceport_dashboard.php");
    exit();
}

$canReviewAll = user_can($conn, $user_id, 'case_view');
$isOwner = (int) ($case['submitted_by'] ?? 0) === $user_id;
if (!$isOwner && !$canReviewAll) {
    echo '<p style="color: var(--polaris-danger);">You do not have permission to view this case.</p>';
    exit();
}

$validUpdateTypes = ['Case Update', 'Communication'];
$validCommTypes = ['Email', 'Phone', 'In Person', 'Other'];

$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($isOwner || $canReviewAll)) {
    $update_text = trim($_POST['update_text'] ?? '');
    $update_type = in_array($_POST['update_type'] ?? '', $validUpdateTypes, true) ? $_POST['update_type'] : 'Case Update';
    $comm_type = null;
    $comm_person = null;
    if ($update_type === 'Communication') {
        $comm_type = in_array($_POST['comm_type'] ?? '', $validCommTypes, true) ? $_POST['comm_type'] : null;
        $comm_person = trim($_POST['comm_person'] ?? '') ?: null;
    }

    if ($update_text === '') {
        $message = "Please enter update text.";
    } elseif ($update_type === 'Communication' && ($comm_type === null || $comm_person === null)) {
        $message = "Please select a communication type and enter who it was with.";
    } else {
        $update_date = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("INSERT INTO case_updates (job_id, user_id, update_type, comm_type, comm_person, update_text, update_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iisssss", $job_id, $user_id, $update_type, $comm_type, $comm_person, $update_text, $update_date);
        if ($stmt->execute()) {
            $updateId = $conn->insert_id;
            insert_history_row($conn, 'case_history', $job_id, 'CASE_UPDATE_ADDED', $user_id, json_encode([
                'Update ID' => $updateId, 'Type' => $update_type, 'Communication Type' => $comm_type,
                'Communication With' => $comm_person, 'Text' => $update_text,
            ]));
            $message = "Update added.";
        } else {
            $message = "Error adding update.";
        }
        $stmt->close();
    }
}

$keyDates = [];
$stmt = $conn->prepare("SELECT event_date, label FROM case_key_dates WHERE job_id = ? ORDER BY event_date");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) { $keyDates[] = $row; }
$stmt->close();

$updates = [];
$stmt = $conn->prepare("
    SELECT cu.update_id, cu.update_text, cu.update_date, cu.update_type, cu.comm_type, cu.comm_person,
           CONCAT(u.first_name, ' ', u.last_name) AS by_name
    FROM case_updates cu JOIN users u ON cu.user_id = u.id
    WHERE cu.job_id = ? ORDER BY cu.update_date DESC
");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) { $updates[] = $row; }
$stmt->close();

include '../header.php';
?>
<style>
    .content-wrapper { max-width: 800px; margin: 120px auto 40px auto; padding: 20px; background: var(--polaris-surface-deep); border-radius: 5px; box-shadow: 0 2px 10px rgba(255,255,255,0.1); }
    h2 { margin-bottom: 4px; }
    .ref { font-family: monospace; color: var(--polaris-text-dim); margin-bottom: 20px; }
    h3 { margin: 28px 0 10px; padding-top: 16px; border-top: 1px solid var(--polaris-border); }
    .field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 20px; font-size: 14px; margin-bottom: 10px; }
    .field-grid dt { color: var(--polaris-text-dim); margin: 0; }
    .field-grid dd { margin: 0 0 8px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    th, td { border: 1px solid var(--polaris-border); padding: 6px 8px; text-align: left; font-size: 13px; }
    th { background: var(--polaris-divider); }
    .message { margin-bottom: 15px; padding: 10px; background: var(--polaris-divider); border-left: 4px solid var(--polaris-accent); font-size: 14px; }
    .update-card { border: 1px solid var(--polaris-border); border-radius: 6px; padding: 10px 14px; margin-bottom: 10px; }
    .update-meta { font-size: 12px; color: var(--polaris-text-dim); margin-bottom: 6px; }
    textarea, select, input[type="text"] { width: 100%; padding: 8px; border: 1px solid var(--polaris-border); border-radius: 4px; background: var(--polaris-bg); color: var(--polaris-text); box-sizing: border-box; margin-bottom: 8px; }
    label { display: block; font-size: 13px; color: var(--polaris-text-dim); margin-bottom: 4px; }
    button { padding: 6px 14px; border: none; border-radius: 3px; cursor: pointer; font-size: 14px; background: var(--polaris-success-strong); color: var(--polaris-text); }
</style>
<div class="content-wrapper">
    <h2><?php echo htmlspecialchars($case['custom_ref'] ?: 'Case'); ?></h2>
    <p class="ref">Status: <?php echo htmlspecialchars($case['status_name'] ?? ''); ?></p>

    <?php if (!empty($message)): ?>
    <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <dl class="field-grid">
        <dt>Case Type</dt><dd><?php echo htmlspecialchars($case['case_type'] ?? ''); ?></dd>
        <dt>OIC</dt><dd><?php echo htmlspecialchars($case['oic'] ?? ''); ?></dd>
        <dt>Incident Number</dt><dd><?php echo htmlspecialchars($case['incident_number'] ?? ''); ?></dd>
        <dt>External Reference</dt><dd><?php echo htmlspecialchars($case['external_reference'] ?? ''); ?></dd>
    </dl>
    <p><?php echo nl2br(htmlspecialchars($case['initial_summary'] ?? '')); ?></p>

    <?php if (!empty($keyDates)): ?>
    <h3>Key Dates</h3>
    <table>
        <tr><th>Date</th><th>What</th></tr>
        <?php foreach ($keyDates as $kd): ?>
        <tr><td><?php echo htmlspecialchars($kd['event_date']); ?></td><td><?php echo htmlspecialchars($kd['label']); ?></td></tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <h3>Case Updates</h3>
    <form method="post" id="updateForm">
        <label for="update_type">Type</label>
        <select name="update_type" id="update_type">
            <?php foreach ($validUpdateTypes as $t): ?>
            <option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option>
            <?php endforeach; ?>
        </select>
        <div id="comm_fields" style="display:none;">
            <label for="comm_type">Communication Type</label>
            <select name="comm_type" id="comm_type">
                <?php foreach ($validCommTypes as $ct): ?>
                <option value="<?php echo htmlspecialchars($ct); ?>"><?php echo htmlspecialchars($ct); ?></option>
                <?php endforeach; ?>
            </select>
            <label for="comm_person">Person</label>
            <input type="text" name="comm_person" id="comm_person" placeholder="Who was this communication with?">
        </div>
        <label for="update_text">Details</label>
        <textarea name="update_text" id="update_text" rows="3" placeholder="Add an update..." required></textarea>
        <button type="submit">Add Update</button>
    </form>
    <script>
    function toggleCommFields() {
        document.getElementById('comm_fields').style.display =
            document.getElementById('update_type').value === 'Communication' ? '' : 'none';
    }
    document.getElementById('update_type').addEventListener('change', toggleCommFields);
    </script>
    <?php if (empty($updates)): ?>
    <p style="color:var(--polaris-text-dim); font-size:14px;">No updates yet.</p>
    <?php else: ?>
    <?php foreach ($updates as $u): ?>
    <div class="update-card">
        <div class="update-meta">
            <?php echo htmlspecialchars($u['by_name']); ?> &middot; <?php echo htmlspecialchars($u['update_date']); ?>
            &middot; <?php echo htmlspecialchars($u['update_type']); ?>
            <?php if ($u['update_type'] === 'Communication'): ?>
            (<?php echo htmlspecialchars($u['comm_type'] ?? ''); ?> with <?php echo htmlspecialchars($u['comm_person'] ?? ''); ?>)
            <?php endif; ?>
        </div>
        <div><?php echo nl2br(htmlspecialchars($u['update_text'])); ?></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($case['submission_id'])): ?>
    <p style="margin-top:20px;"><a href="view_submission.php?submission_id=<?php echo (int) $case['submission_id']; ?>" class="btn-link">View Original Submission</a></p>
    <?php endif; ?>
</div>
