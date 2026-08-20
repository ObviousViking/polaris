<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once('../includes/integrity.php');
require_once '../includes/permissions.php';
require_once '../includes/spaceport.php';
require_permission($conn, 'case_edit');

// Ensure a job_id is provided.
if (!isset($_GET['job_id'])) {
    echo "Job ID not specified.";
    exit();
}
$job_id = intval($_GET['job_id']);

// Retrieve current job details.
$stmt = $conn->prepare("
    SELECT 
        j.job_id,
        j.custom_ref,
        j.initial_summary,
        j.oic,
        j.operation,
        op.operation_name,
        j.customer_id,
        j.lead_force_id,
        j.suspect,
        j.fingerprints,
        j.dna,
        j.malware,
        j.status_id,
        j.strategy_set,
        j.strategy_due,
        j.strategy_complete,
        j.case_type_id
    FROM jobs j
    LEFT JOIN operations op ON j.operation = op.operation_id
    WHERE j.job_id = ?
");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$stmt->bind_result(
    $job_id,
    $custom_ref,
    $initial_summary,
    $oic,
    $operation_id,
    $operation_name,
    $customer_id,
    $lead_force_id,
    $suspect,
    $fingerprints,
    $dna,
    $malware,
    $status_id,
    $strategy_set,
    $strategy_due,
    $strategy_complete,
    $case_type_id
);
if (!$stmt->fetch()) {
    echo "Job not found.";
    exit();
}
$stmt->close();

// Retrieve lookup data
function getLookupData($conn, $query) {
    $data = [];
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $result->free();
    return $data;
}

$caseTypes   = getLookupData($conn, "SELECT case_type_id, type_name FROM case_types ORDER BY type_name");
$jobStatuses = getLookupData($conn, "SELECT status_id, status_name FROM job_status ORDER BY status_name");
$operations  = getLookupData($conn, "SELECT operation_id, operation_name FROM operations ORDER BY operation_name");
$forces      = getLookupData($conn, "SELECT id, force_name FROM forces ORDER BY force_name");
$customers   = getLookupData($conn, "SELECT customer_id, name FROM customers ORDER BY name");

// If this case originated as a Spaceport submission, its subject photo and
// submission-question answers live there (keyed to submission_id, not
// job_id - see includes/migrations/023_spaceport.sql).
$originSubmissionId = null;
$subStmt = $conn->prepare("SELECT submission_id FROM submissions WHERE job_id = ?");
$subStmt->bind_param("i", $job_id);
$subStmt->execute();
$subStmt->bind_result($originSubmissionId);
$subStmt->fetch();
$subStmt->close();

$subjectPhotos = $originSubmissionId ? get_subject_photos($conn, $originSubmissionId) : [];

$questionFields = [];
$questionAnswers = [];
if ($originSubmissionId) {
    $result = $conn->query("SELECT field_id, field_label, field_type, is_required FROM submission_question_fields WHERE is_active = 1 ORDER BY sort_order, field_label");
    while ($row = $result->fetch_assoc()) { $questionFields[] = $row; }

    $stmt = $conn->prepare("SELECT field_id, value FROM submission_question_values WHERE submission_id = ?");
    $stmt->bind_param("i", $originSubmissionId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $questionAnswers[$row['field_id']] = $row['value']; }
    $stmt->close();
}

$keyDates = [];
$result = $conn->query("SELECT key_date_id, event_date, label FROM case_key_dates WHERE job_id = $job_id ORDER BY event_date");
while ($row = $result->fetch_assoc()) { $keyDates[] = $row; }

$message = "";

// Stores names, not raw foreign keys, so the audit trail is readable.
function lookupName(array $rows, string $idCol, string $nameCol, $id): ?string
{
    if ($id === null || $id === '') {
        return null;
    }
    foreach ($rows as $row) {
        if ($row[$idCol] == $id) {
            return $row[$nameCol];
        }
    }
    return null;
}

// datetime-local inputs submit '' (needs NULL) and use 'T' as the separator
// (MySQL needs a space), so both need converting.
function normalizeDatetimeLocal($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    return str_replace('T', ' ', $value);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_initial_summary = trim($_POST['initial_summary']);
    $new_oic             = trim($_POST['oic']);
    $new_operation_id    = isset($_POST['operation']) && is_numeric($_POST['operation']) ? intval($_POST['operation']) : $operation_id;
    $new_customer        = !empty($_POST['customer']) ? intval($_POST['customer']) : NULL;
    $new_lead_force      = !empty($_POST['lead_force']) ? intval($_POST['lead_force']) : NULL;
    $new_suspect         = trim($_POST['suspect']);
    $new_fingerprints    = isset($_POST['fingerprints']) ? 1 : 0;
    $new_dna             = isset($_POST['dna']) ? 1 : 0;
    $new_malware         = isset($_POST['malware']) ? 1 : 0;
    $new_status          = intval($_POST['status']);
    $new_strategy_set    = normalizeDatetimeLocal($_POST['strategy_set'] ?? $strategy_set);
    $new_strategy_due    = normalizeDatetimeLocal($_POST['strategy_due'] ?? $strategy_due);
    $new_strategy_complete = normalizeDatetimeLocal($_POST['strategy_complete'] ?? $strategy_complete);
    $new_case_type       = intval($_POST['case_type']);

    $changes = [];

    if ($new_initial_summary !== $initial_summary) {
        $changes['Case Background'] = ["old" => $initial_summary, "new" => $new_initial_summary];
    }
    if ($new_oic !== $oic) {
        $changes['OIC'] = ["old" => $oic, "new" => $new_oic];
    }
    if ($new_operation_id !== $operation_id) {
        $oldOpName = $newOpName = null;
        foreach ($operations as $op) {
            if ($op['operation_id'] == $operation_id) $oldOpName = $op['operation_name'];
            if ($op['operation_id'] == $new_operation_id) $newOpName = $op['operation_name'];
        }
        if ($oldOpName !== $newOpName) {
            $changes['Operation'] = ["old" => $oldOpName, "new" => $newOpName];
        }
    }
    if ($new_customer !== $customer_id) {
        $changes['Customer'] = [
            "old" => lookupName($customers, 'customer_id', 'name', $customer_id),
            "new" => lookupName($customers, 'customer_id', 'name', $new_customer),
        ];
    }
    if ($new_lead_force !== $lead_force_id) {
        $changes['Lead Force'] = [
            "old" => lookupName($forces, 'id', 'force_name', $lead_force_id),
            "new" => lookupName($forces, 'id', 'force_name', $new_lead_force),
        ];
    }
    if ($new_suspect !== $suspect) {
        $changes['Suspect'] = ["old" => $suspect, "new" => $new_suspect];
    }
    if ($new_fingerprints !== $fingerprints) {
        $changes['Fingerprints'] = ["old" => $fingerprints ? "Yes" : "No", "new" => $new_fingerprints ? "Yes" : "No"];
    }
    if ($new_dna !== $dna) {
        $changes['DNA'] = ["old" => $dna ? "Yes" : "No", "new" => $new_dna ? "Yes" : "No"];
    }
    if ($new_malware !== $malware) {
        $changes['Malware'] = ["old" => $malware ? "Yes" : "No", "new" => $new_malware ? "Yes" : "No"];
    }
    if ($new_status !== $status_id) {
        $changes['Status'] = [
            "old" => lookupName($jobStatuses, 'status_id', 'status_name', $status_id),
            "new" => lookupName($jobStatuses, 'status_id', 'status_name', $new_status),
        ];
    }
    // Compares normalized values so setting or clearing a date both count as a change.
    $trackDatetimeChange = function ($label, $old, $new) use (&$changes) {
        $oldNorm = !empty($old) ? date('Y-m-d H:i', strtotime($old)) : null;
        $newNorm = !empty($new) ? date('Y-m-d H:i', strtotime($new)) : null;
        if ($oldNorm !== $newNorm) {
            $changes[$label] = ["old" => $old, "new" => $new];
        }
    };
    $trackDatetimeChange('Strategy Set', $strategy_set, $new_strategy_set);
    $trackDatetimeChange('Strategy Due', $strategy_due, $new_strategy_due);
    $trackDatetimeChange('Strategy Complete', $strategy_complete, $new_strategy_complete);
    if ($new_case_type !== $case_type_id) {
        $changes['Case Type'] = [
            "old" => lookupName($caseTypes, 'case_type_id', 'type_name', $case_type_id),
            "new" => lookupName($caseTypes, 'case_type_id', 'type_name', $new_case_type),
        ];
    }

    if (!empty($changes)) {
        $stmt = $conn->prepare("UPDATE jobs 
            SET initial_summary = ?, oic = ?, operation = ?, customer_id = ?, lead_force_id = ?, suspect = ?, 
                fingerprints = ?, dna = ?, malware = ?, status_id = ?, 
                strategy_set = ?, strategy_due = ?, strategy_complete = ?, case_type_id = ? 
            WHERE job_id = ?");
        $stmt->bind_param(
            "ssiiisiiissssii",
            $new_initial_summary,
            $new_oic,
            $new_operation_id,
            $new_customer,
            $new_lead_force,
            $new_suspect,
            $new_fingerprints,
            $new_dna,
            $new_malware,
            $new_status,
            $new_strategy_set,
            $new_strategy_due,
            $new_strategy_complete,
            $new_case_type,
            $job_id
        );

        if ($stmt->execute()) {
            $stmt->close();
            $historyAction = "UPDATE";
            $changedBy = $_SESSION['user_id'];
            $changesJSON = json_encode($changes);
            insert_history_row($conn, 'case_history', $job_id, $historyAction, $changedBy, $changesJSON);

            require_once '../includes/achievements.php';
            check_and_unlock_achievements($conn, (int) $changedBy, 'cases_completed');
        } else {
            $message = "Error updating case: " . $stmt->error;
            $stmt->close();
        }
    }

    if (empty($message)) {
        $historyUserId = (int) $_SESSION['user_id'];

        // Key dates - a repeatable list with no per-row audit trail, so
        // replaced wholesale rather than diffed row-by-row; the before/after
        // sets are still compared and logged as one change so a court-date
        // correction, say, doesn't happen invisibly.
        if (isset($_POST['key_date'])) {
            $oldKeyDatesStr = implode('; ', array_map(fn($kd) => "{$kd['event_date']}: {$kd['label']}", $keyDates));

            $conn->query("DELETE FROM case_key_dates WHERE job_id = $job_id");
            $kdDates = $_POST['key_date'];
            $kdLabels = $_POST['key_date_label'] ?? [];
            $insKd = $conn->prepare("INSERT INTO case_key_dates (job_id, event_date, label, created_by) VALUES (?, ?, ?, ?)");
            $newKeyDatePairs = [];
            foreach ($kdDates as $i => $date) {
                $date = trim($date);
                $label = trim($kdLabels[$i] ?? '');
                if ($date === '' || $label === '') {
                    continue;
                }
                $insKd->bind_param("issi", $job_id, $date, $label, $historyUserId);
                $insKd->execute();
                $newKeyDatePairs[] = "$date: $label";
            }
            $insKd->close();

            $newKeyDatesStr = implode('; ', $newKeyDatePairs);
            if ($newKeyDatesStr !== $oldKeyDatesStr) {
                $keyDateChange = json_encode([
                    'Key Dates' => ['old' => $oldKeyDatesStr ?: '(none)', 'new' => $newKeyDatesStr ?: '(none)'],
                ]);
                insert_history_row($conn, 'case_history', $job_id, 'UPDATE', $historyUserId, $keyDateChange);
                // Key dates live on the case (case_key_dates.job_id), not the
                // submission, so this only ever landed in case_history -
                // invisible to the submitting officer, who only ever sees
                // submission_history (Spaceport's My Submissions/View
                // Submission pages, same as Submission Questions above).
                if ($originSubmissionId) {
                    insert_history_row($conn, 'submission_history', $originSubmissionId, 'UPDATE', $historyUserId, $keyDateChange);
                }
            }
        }

        // Submission-question answers, if this case came via Spaceport -
        // tracked per-question against the previously saved answer, same as
        // any other case field, so "Encryption? No -> Yes" doesn't slip
        // through unrecorded.
        if ($originSubmissionId && !empty($questionFields)) {
            $upsertQ = $conn->prepare("INSERT INTO submission_question_values (submission_id, field_id, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
            $questionChanges = [];
            foreach ($questionFields as $qf) {
                $key = 'question_' . $qf['field_id'];
                $isCheckbox = $qf['field_type'] === 'checkbox';
                // A checkbox is never ambiguous - unchecked genuinely means
                // "0", not "wasn't part of this submission" - so it always
                // gets a value to save, unlike a blank text/date/number
                // answer, which is left unstored (sparse by design, see
                // includes/migrations/023_spaceport.sql).
                $val = $isCheckbox
                    ? (isset($_POST[$key]) ? '1' : '0')
                    : trim($_POST[$key] ?? '');
                $oldVal = $questionAnswers[$qf['field_id']] ?? '';

                // A blank non-checkbox answer never overwrites the stored
                // value (sparse by design - see the skip below), so it must
                // not be logged as a change either. Previously this recorded
                // "-> (blank)" in submission_history/case_history for every
                // field the form happened to submit empty, even though the
                // DB row underneath was untouched - a false entry in what's
                // meant to be the audit trail.
                if ($val === '' && !$isCheckbox) {
                    continue;
                }

                if ($val !== $oldVal) {
                    $displayOld = $isCheckbox ? ($oldVal ? 'Yes' : 'No') : $oldVal;
                    $displayNew = $isCheckbox ? ($val ? 'Yes' : 'No') : $val;
                    $questionChanges[$qf['field_label']] = ['old' => $displayOld ?: '(blank)', 'new' => $displayNew ?: '(blank)'];
                }
                $upsertQ->bind_param("iis", $originSubmissionId, $qf['field_id'], $val);
                $upsertQ->execute();
            }
            $upsertQ->close();
            if (!empty($questionChanges)) {
                insert_history_row($conn, 'submission_history', $originSubmissionId, 'UPDATE', $historyUserId, json_encode($questionChanges));
            }
        }

        // Subject photo - a new version, not an overwrite (see
        // includes/migrations/023_spaceport.sql). Only possible for a case
        // that originated as a Spaceport submission. Every failure path
        // here used to fall through silently - the redirect to job.php ran
        // regardless, so a bad image or a directory move_uploaded_file()
        // couldn't write into (e.g. one left root-owned by a CLI seed run)
        // just looked like nothing happened. Now tracked and surfaced via
        // job.php's ?error= banner (see its $pageError block).
        $photoUploadFailed = false;
        if ($originSubmissionId && isset($_FILES['subject_photo']) && $_FILES['subject_photo']['error'] === UPLOAD_ERR_OK) {
            $imageInfo = @getimagesize($_FILES['subject_photo']['tmp_name']);
            $allowedImageTypes = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
            if ($imageInfo !== false && isset($allowedImageTypes[$imageInfo[2]])) {
                $verStmt = $conn->prepare("SELECT COALESCE(MAX(version), 0) + 1 FROM subject_photos WHERE submission_id = ?");
                $verStmt->bind_param("i", $originSubmissionId);
                $verStmt->execute();
                $verStmt->bind_result($nextVersion);
                $verStmt->fetch();
                $verStmt->close();

                $ext = $allowedImageTypes[$imageInfo[2]];
                $photoDir = get_storage_settings($conn)['paths']['subject_photo_dir_fs'] . $originSubmissionId . '/';
                if (!is_dir($photoDir)) {
                    mkdir($photoDir, 0755, true);
                }
                $storedPhoto = "v{$nextVersion}_" . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $photoTarget = $photoDir . $storedPhoto;
                if (move_uploaded_file($_FILES['subject_photo']['tmp_name'], $photoTarget)) {
                    $origPhotoName = basename($_FILES['subject_photo']['name']);
                    $photoUserId = (int) $_SESSION['user_id'];
                    $insPhoto = $conn->prepare("INSERT INTO subject_photos (submission_id, version, original_filename, stored_filename, file_path, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
                    $insPhoto->bind_param("iisssi", $originSubmissionId, $nextVersion, $origPhotoName, $storedPhoto, $photoTarget, $photoUserId);
                    $insPhoto->execute();
                    $insPhoto->close();
                    insert_history_row($conn, 'submission_history', $originSubmissionId, 'UPDATE', $photoUserId, json_encode(['Subject Photo' => "uploaded version $nextVersion (via case details)"]));
                } else {
                    $photoUploadFailed = true;
                    error_log("edit_job.php: move_uploaded_file failed for subject photo, submission_id=$originSubmissionId, target=$photoTarget");
                }
            } else {
                $photoUploadFailed = true;
                error_log("edit_job.php: rejected subject photo upload for submission_id=$originSubmissionId - not a recognised image type");
            }
        }

        if ($photoUploadFailed) {
            header("Location: job.php?job_id=" . $job_id . "&error=photo_upload_failed");
        } else {
            header("Location: job.php?job_id=" . $job_id);
        }
        exit();
    }
}



include('../header.php');
?>


<style>
    /* header.php's own body{} already sets margin/background/color/font-family
       page-wide. This page used to redeclare a second <!DOCTYPE html><html>
       <head> here after the include - browsers tolerate the invalid
       nesting, but the stray markup risked winning the cascade against
       header.php's own rules. .content-wrapper already clears the fixed
       header via its margin-top, so nothing needed to move here. */
    .content-wrapper {
        max-width: 800px;
        margin: 120px auto 20px auto;
        padding: 20px;
        background-color: var(--polaris-surface-deep);
        border-radius: 5px;
        box-shadow: 0 2px 10px rgba(255, 255, 255, 0.1);
    }

    h2 {
        color: var(--polaris-text);
        text-align: center;
        margin-bottom: 20px;
    }

    .form-columns {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
    }

    .column {
        flex: 1 1 calc(50% - 20px);
    }

    .field {
        margin-bottom: 15px;
    }

    label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
        color: var(--polaris-gray-e0);
    }

    input[type="text"],
    input[type="number"],
    input[type="datetime-local"],
    textarea,
    select {
        width: 100%;
        padding: 8px;
        border: 1px solid var(--polaris-text-secondary);
        border-radius: 4px;
        background-color: rgba(255, 255, 255, 0.1);
        color: var(--polaris-gray-e0);
    }

    /* Most browsers render <option> with their own solid background rather
       than inheriting the <select>'s semi-transparent one, which without
       this left the dropdown list showing light text on a white background. */
    select option {
        background-color: var(--polaris-bg);
        color: var(--polaris-gray-e0);
    }

    input[readonly] {
        background-color: rgba(255, 255, 255, 0.05);
    }

    .checkbox-group {
        display: flex;
        gap: 10px;
    }

    .checkbox-group label {
        display: inline-block;
    }

    .button-group {
        display: flex;
        justify-content: space-between;
        width: 100%;
        margin-top: 20px;
        gap: 10px;
    }

    .button-group .action-btn {
        flex: 1;
        padding: 5px 10px;
        font-size: 14px;
        border: none;
        border-radius: 3px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        text-align: center;
        text-decoration: none;
        cursor: pointer;
        transition: background 0.3s ease;
    }

    .button-group .action-btn:hover {
        background: var(--polaris-accent-hover);
    }

    .message {
        text-align: center;
        margin-bottom: 20px;
        padding: 10px;
        border-radius: 5px;
        font-size: 16px;
    }

    .success {
        background-color: var(--polaris-success-bg);
        color: var(--polaris-success-text);
    }

    .error {
        background-color: var(--polaris-error-bg);
        color: var(--polaris-error-text);
    }

    h3 {
        margin: 30px 0 12px;
        padding-top: 20px;
        border-top: 1px solid var(--polaris-border);
        color: var(--polaris-text);
    }

    .section-hint {
        font-size: 13px;
        color: var(--polaris-text-dim);
        margin: -6px 0 16px;
    }

    .subject-photo-preview {
        width: 140px;
        height: 140px;
        object-fit: cover;
        border-radius: 8px;
        display: block;
        margin-bottom: 8px;
    }

    .repeat-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 10px;
    }

    .repeat-table th {
        text-align: left;
        font-size: 12px;
        color: var(--polaris-text-dim);
        padding: 4px 6px;
    }

    .repeat-table td {
        padding: 4px 6px;
    }

    .remove-row {
        background: var(--polaris-error-bg);
        color: var(--polaris-error-text);
        border: none;
        border-radius: 3px;
        padding: 6px 10px;
        cursor: pointer;
    }

    .add-row-btn {
        background: var(--polaris-border);
        color: var(--polaris-text);
        border: none;
        padding: 6px 12px;
        border-radius: 3px;
        cursor: pointer;
        font-size: 13px;
        margin-top: 4px;
    }

    .add-row-btn:hover {
        background: var(--polaris-border-hover);
    }
    </style>

    <div class="content-wrapper">
        <h2>View Full Details</h2>
        <?php if (!empty($message)): ?>
        <div class="message <?php echo (strpos($message, 'Error') !== false) ? 'error' : 'success'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        <form method="post" action="edit_job.php?job_id=<?php echo $job_id; ?>" enctype="multipart/form-data">
            <div class="form-columns">
                <div class="column">
                    <div class="field">
                        <label>Case Ref</label>
                        <input type="text" value="<?php echo htmlspecialchars($custom_ref); ?>" readonly>
                    </div>
                    <div class="field">
                        <label>Case Type</label>
                        <select name="case_type">
                            <?php foreach ($caseTypes as $ct): ?>
                            <option value="<?php echo $ct['case_type_id']; ?>"
                                <?php if ($ct['case_type_id'] == $case_type_id) echo "selected"; ?>>
                                <?php echo htmlspecialchars($ct['type_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Operation</label>
                        <select name="operation">
                            <?php foreach ($operations as $op): ?>
                            <option value="<?php echo $op['operation_id']; ?>"
                                <?php if ($op['operation_id'] == $operation_id) echo "selected"; ?>>
                                <?php echo htmlspecialchars($op['operation_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>OIC</label>
                        <input type="text" name="oic" value="<?php echo htmlspecialchars($oic); ?>"
                            placeholder="Enter Officer In Charge">
                    </div>
                    <div class="field">
                        <label>Case Background</label>
                        <textarea name="initial_summary" rows="5"
                            placeholder="Enter case background"><?php echo htmlspecialchars($initial_summary); ?></textarea>
                    </div>
                    <div class="field checkbox-group">
                        <label><input type="checkbox" name="fingerprints" value="1"
                                <?php if ($fingerprints) echo "checked"; ?>> Fingerprints</label>
                        <label><input type="checkbox" name="dna" value="1" <?php if ($dna) echo "checked"; ?>> DNA</label>
                        <label><input type="checkbox" name="malware" value="1"
                                <?php if ($malware) echo "checked"; ?>> Malware</label>
                    </div>
                </div>
                <div class="column">
                    <div class="field">
                        <label>Customer</label>
                        <select name="customer">
                            <option value="">Select Customer</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?php echo $c['customer_id']; ?>"
                                <?php if ($c['customer_id'] == $customer_id) echo "selected"; ?>>
                                <?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Lead Force</label>
                        <select name="lead_force">
                            <option value="">Select Lead Force</option>
                            <?php foreach ($forces as $f): ?>
                            <option value="<?php echo $f['id']; ?>"
                                <?php if ($f['id'] == $lead_force_id) echo "selected"; ?>>
                                <?php echo htmlspecialchars($f['force_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Suspect</label>
                        <input type="text" name="suspect" value="<?php echo htmlspecialchars($suspect); ?>"
                            placeholder="Enter suspect name">
                    </div>
                    <div class="field">
                        <label>Status</label>
                        <select name="status" required>
                            <?php foreach ($jobStatuses as $js): ?>
                            <option value="<?php echo $js['status_id']; ?>"
                                <?php if ($js['status_id'] == $status_id) echo "selected"; ?>>
                                <?php echo htmlspecialchars($js['status_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Strategy Set</label>
                        <input type="datetime-local" name="strategy_set"
                            value="<?php echo htmlspecialchars(date('Y-m-d\TH:i', strtotime($strategy_set))); ?>">
                    </div>
                    <div class="field">
                        <label>Strategy Due</label>
                        <input type="datetime-local" name="strategy_due"
                            value="<?php echo $strategy_due ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($strategy_due))) : ''; ?>">
                    </div>
                    <div class="field">
                        <label>Strategy Complete</label>
                        <input type="datetime-local" name="strategy_complete"
                            value="<?php echo $strategy_complete ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($strategy_complete))) : ''; ?>">
                    </div>
                </div>
            </div>

            <?php if ($originSubmissionId): ?>
            <h3>Subject Photo</h3>
            <?php if (!empty($subjectPhotos)): ?>
            <img class="subject-photo-preview"
                src="/spaceport/download_subject_photo.php?photo_id=<?php echo (int) $subjectPhotos[0]['photo_id']; ?>"
                alt="Subject photo">
            <p class="section-hint">Current version (v<?php echo (int) $subjectPhotos[0]['version']; ?>). <a
                    href="/spaceport/view_submission.php?submission_id=<?php echo $originSubmissionId; ?>">View all versions</a>
                on the original submission.</p>
            <?php else: ?>
            <p class="section-hint">No subject photo uploaded.</p>
            <?php endif; ?>
            <div class="field">
                <label><?php echo empty($subjectPhotos) ? 'Upload Subject Photo' : 'Replace Subject Photo'; ?></label>
                <input type="file" name="subject_photo" accept="image/*">
            </div>
            <?php endif; ?>

            <p class="section-hint">Exhibits (including any not yet arrived) are on the <a
                    href="job.php?job_id=<?php echo $job_id; ?>">case page</a>.</p>

            <h3>Key Dates</h3>
            <table class="repeat-table" id="keyDatesTable">
                <thead><tr><th>Date</th><th>What is it?</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($keyDates as $kd): ?>
                    <tr>
                        <td><input type="date" name="key_date[]" value="<?php echo htmlspecialchars($kd['event_date']); ?>"></td>
                        <td><input type="text" name="key_date_label[]" value="<?php echo htmlspecialchars($kd['label']); ?>"></td>
                        <td><button type="button" class="remove-row" onclick="this.closest('tr').remove()">Remove</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" class="add-row-btn" onclick="addKeyDateRow()">+ Add Key Date</button>

            <?php if ($originSubmissionId && !empty($questionFields)): ?>
            <h3>Submission Questions</h3>
            <?php foreach ($questionFields as $qf):
                $qname = 'question_' . $qf['field_id'];
                $qval = $questionAnswers[$qf['field_id']] ?? '';
            ?>
            <div class="field">
                <label><?php echo htmlspecialchars($qf['field_label']); ?></label>
                <?php if ($qf['field_type'] === 'textarea'): ?>
                <textarea name="<?php echo $qname; ?>" rows="3"><?php echo htmlspecialchars($qval); ?></textarea>
                <?php elseif ($qf['field_type'] === 'checkbox'): ?>
                <label style="font-weight:normal;"><input type="checkbox" name="<?php echo $qname; ?>" value="1" <?php echo $qval ? 'checked' : ''; ?>> Yes</label>
                <?php elseif ($qf['field_type'] === 'date'): ?>
                <input type="date" name="<?php echo $qname; ?>" value="<?php echo htmlspecialchars($qval); ?>">
                <?php elseif ($qf['field_type'] === 'number'): ?>
                <input type="number" name="<?php echo $qname; ?>" value="<?php echo htmlspecialchars($qval); ?>">
                <?php else: ?>
                <input type="text" name="<?php echo $qname; ?>" value="<?php echo htmlspecialchars($qval); ?>">
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <div class="button-group">
                <button type="submit" class="action-btn">Save</button>
                <a href="view_case_history.php?job_id=<?php echo $job_id; ?>" class="action-btn">View History</a>
                <button type="button" class="action-btn"
                    onclick="window.location.href='job.php?job_id=<?php echo $job_id; ?>'">Cancel</button>
            </div>
        </form>
    </div>
    <template id="keyDateRowTemplate">
        <tr>
            <td><input type="date" name="key_date[]"></td>
            <td><input type="text" name="key_date_label[]" placeholder="e.g. Court date, Bail date"></td>
            <td><button type="button" class="remove-row" onclick="this.closest('tr').remove()">Remove</button></td>
        </tr>
    </template>
    <script>
    function addKeyDateRow() {
        var tpl = document.getElementById('keyDateRowTemplate');
        document.querySelector('#keyDatesTable tbody').appendChild(tpl.content.cloneNode(true));
    }
    </script>
</body>

</html>