<?php
// spaceport/submit_case.php
//
// Officer-facing submission form. Creates a `submissions` staging row with
// no job_id - it only becomes a real case once a reviewer approves it (see
// includes/spaceport.php).
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/integrity.php';
require_once '../includes/settings.php';
require_once '../includes/permissions.php';
require_once '../includes/spaceport.php';
require_permission($conn, 'submission_create');

$user_id = (int) $_SESSION['user_id'];

$caseTypes = [];
$result = $conn->query("SELECT case_type_id, type_name FROM case_types ORDER BY type_name");
while ($row = $result->fetch_assoc()) { $caseTypes[] = $row; }

$operations = [];
$result = $conn->query("SELECT operation_id, operation_name FROM operations ORDER BY operation_name");
while ($row = $result->fetch_assoc()) { $operations[] = $row; }

$customers = [];
$result = $conn->query("SELECT customer_id, name FROM customers ORDER BY name");
while ($row = $result->fetch_assoc()) { $customers[] = $row; }

$forces = [];
$result = $conn->query("SELECT id, force_name FROM forces ORDER BY force_name");
while ($row = $result->fetch_assoc()) { $forces[] = $row; }

$contacts = [];
$result = $conn->query("SELECT contact_id, name, role_title FROM external_contacts WHERE is_active = 1 ORDER BY name");
while ($row = $result->fetch_assoc()) { $contacts[] = $row; }

$exhibitTypes = [];
$result = $conn->query("SELECT exhibit_type_id, type_name FROM exhibit_types ORDER BY type_name");
while ($row = $result->fetch_assoc()) { $exhibitTypes[] = $row; }

$questions = [];
$result = $conn->query("SELECT field_id, field_label, field_key, field_type, is_required FROM submission_question_fields WHERE is_active = 1 ORDER BY sort_order, field_label");
while ($row = $result->fetch_assoc()) { $questions[] = $row; }

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $case_type_id = intval($_POST['case_type'] ?? 0);
    $oic = trim($_POST['oic'] ?? '');
    $operation_id = !empty($_POST['operation']) ? intval($_POST['operation']) : null;
    $customer_id = !empty($_POST['customer']) ? intval($_POST['customer']) : null;
    $lead_force_id = !empty($_POST['lead_force']) ? intval($_POST['lead_force']) : null;
    $suspect = trim($_POST['suspect'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $fingerprints = isset($_POST['fingerprints']) ? 1 : 0;
    $dna = isset($_POST['dna']) ? 1 : 0;
    $malware = isset($_POST['malware']) ? 1 : 0;
    $incident_number = trim($_POST['incident_number'] ?? '');
    $external_reference = trim($_POST['external_reference'] ?? '');
    $contact_primary_id = !empty($_POST['contact_primary']) ? intval($_POST['contact_primary']) : null;
    $contact_secondary_id = !empty($_POST['contact_secondary']) ? intval($_POST['contact_secondary']) : null;

    if (empty($case_type_id)) {
        $message = "Please select a Case Type.";
    } else {
        $stmt = $conn->prepare("
            INSERT INTO submissions
                (case_type_id, initial_summary, oic, operation, customer_id, lead_force_id, suspect,
                 fingerprints, dna, malware, incident_number, external_reference,
                 contact_primary_id, contact_secondary_id, submitted_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "issiiisiiissiii",
            $case_type_id, $description, $oic, $operation_id, $customer_id, $lead_force_id, $suspect,
            $fingerprints, $dna, $malware, $incident_number, $external_reference,
            $contact_primary_id, $contact_secondary_id, $user_id
        );
        $stmt->execute();
        $submissionId = $conn->insert_id;
        $stmt->close();

        // Declared exhibits - parallel arrays, one index per row.
        $exTypes = $_POST['exhibit_type'] ?? [];
        $exRefs = $_POST['exhibit_ref'] ?? [];
        $exDescriptions = $_POST['exhibit_description'] ?? [];
        $exBagNumbers = $_POST['bag_number'] ?? [];
        $exSeizingOfficers = $_POST['seizing_officer'] ?? [];
        $exSeizingLocations = $_POST['seizing_location'] ?? [];
        if (!empty($exTypes)) {
            $insEx = $conn->prepare("INSERT INTO submitted_exhibits (submission_id, exhibit_type_id, exhibit_ref, description, bag_number, seizing_officer, seizing_location) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($exTypes as $i => $typeId) {
                $typeId = intval($typeId);
                if (empty($typeId)) {
                    continue;
                }
                $ref = strtoupper(trim($exRefs[$i] ?? ''));
                $desc = trim($exDescriptions[$i] ?? '');
                $bag = trim($exBagNumbers[$i] ?? '');
                $officer = trim($exSeizingOfficers[$i] ?? '');
                $loc = trim($exSeizingLocations[$i] ?? '');
                $insEx->bind_param("iisssss", $submissionId, $typeId, $ref, $desc, $bag, $officer, $loc);
                $insEx->execute();
            }
            $insEx->close();
        }

        // Key dates - parallel arrays.
        $kdDates = $_POST['key_date'] ?? [];
        $kdLabels = $_POST['key_date_label'] ?? [];
        if (!empty($kdDates)) {
            $insKd = $conn->prepare("INSERT INTO submission_key_dates (submission_id, event_date, label) VALUES (?, ?, ?)");
            foreach ($kdDates as $i => $date) {
                $date = trim($date);
                $label = trim($kdLabels[$i] ?? '');
                if ($date === '' || $label === '') {
                    continue;
                }
                $insKd->bind_param("iss", $submissionId, $date, $label);
                $insKd->execute();
            }
            $insKd->close();
        }

        // Admin-defined questions.
        if (!empty($questions)) {
            $insQ = $conn->prepare("INSERT INTO submission_question_values (submission_id, field_id, value) VALUES (?, ?, ?)");
            foreach ($questions as $q) {
                $key = 'question_' . $q['field_id'];
                $val = isset($_POST[$key]) ? (is_array($_POST[$key]) ? '1' : trim($_POST[$key])) : '';
                if ($val === '') {
                    continue;
                }
                $insQ->bind_param("iis", $submissionId, $q['field_id'], $val);
                $insQ->execute();
            }
            $insQ->close();
        }

        // Subject photo - optional, version 1.
        if (isset($_FILES['subject_photo']) && $_FILES['subject_photo']['error'] === UPLOAD_ERR_OK) {
            $imageInfo = @getimagesize($_FILES['subject_photo']['tmp_name']);
            $allowedTypes = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
            if ($imageInfo !== false && isset($allowedTypes[$imageInfo[2]])) {
                $ext = $allowedTypes[$imageInfo[2]];
                $storage = get_storage_settings($conn);
                $dir = $storage['paths']['subject_photo_dir_fs'] . $submissionId . '/';
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $stored = 'v1_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $target = $dir . $stored;
                if (move_uploaded_file($_FILES['subject_photo']['tmp_name'], $target)) {
                    $origName = basename($_FILES['subject_photo']['name']);
                    $insPhoto = $conn->prepare("INSERT INTO subject_photos (submission_id, version, original_filename, stored_filename, file_path, uploaded_by) VALUES (?, 1, ?, ?, ?, ?)");
                    $insPhoto->bind_param("isssi", $submissionId, $origName, $stored, $target, $user_id);
                    $insPhoto->execute();
                    $insPhoto->close();
                }
            }
        }

        insert_history_row($conn, 'submission_history', $submissionId, 'CREATE', $user_id, json_encode([
            'case_type_id' => $case_type_id,
            'oic' => $oic,
            'suspect' => $suspect,
        ]));
        notify_new_submission($conn, $submissionId);

        header("Location: submission_submitted.php?submission_id=$submissionId");
        exit();
    }
}

include '../header.php';
?>

<style>
    .content-wrapper {
        max-width: 900px;
        margin: 120px auto 40px auto;
        padding: 20px;
        background-color: var(--polaris-surface-deep);
        border-radius: 5px;
        box-shadow: 0 2px 10px rgba(255, 255, 255, 0.1);
    }

    h2 { color: var(--polaris-text); text-align: center; margin-bottom: 8px; }
    h3 {
        color: var(--polaris-text);
        margin: 30px 0 12px;
        padding-top: 20px;
        border-top: 1px solid var(--polaris-border);
    }
    .section-hint { color: var(--polaris-text-dim); font-size: 13px; margin: -6px 0 16px; }

    .form-columns { display: flex; gap: 20px; flex-wrap: wrap; }
    .column { flex: 1 1 calc(50% - 20px); }
    .field { margin-bottom: 15px; }

    label { display: block; margin-bottom: 5px; font-weight: bold; color: var(--polaris-gray-e0); }

    input[type="text"], input[type="number"], input[type="date"], input[type="file"],
    textarea, select {
        width: 100%;
        padding: 8px;
        border: 1px solid var(--polaris-text-secondary);
        border-radius: 4px;
        background-color: rgba(255, 255, 255, 0.1);
        color: var(--polaris-gray-e0);
        box-sizing: border-box;
    }
    select option { background-color: var(--polaris-bg); color: var(--polaris-gray-e0); }

    .checkbox-group { display: flex; gap: 10px; }
    .checkbox-group label { display: inline-block; font-weight: normal; }

    .repeat-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    .repeat-table th { text-align: left; font-size: 12px; color: var(--polaris-text-dim); padding: 4px 6px; }
    .repeat-table td { padding: 4px 6px; }
    .repeat-table input, .repeat-table select { font-size: 13px; }
    .remove-row { background: var(--polaris-error-bg); color: var(--polaris-error-text); border: none; border-radius: 3px; padding: 6px 10px; cursor: pointer; }

    .add-row-btn {
        background: var(--polaris-border); color: var(--polaris-text); border: none;
        padding: 6px 12px; border-radius: 3px; cursor: pointer; font-size: 13px; margin-top: 4px;
    }
    .add-row-btn:hover { background: var(--polaris-border-hover); }

    .quantity-helper { display: flex; gap: 8px; align-items: end; margin-bottom: 16px; }
    .quantity-helper .field { margin-bottom: 0; flex: 1; }

    input[type="submit"] {
        background: var(--polaris-success-strong); color: var(--polaris-text);
        padding: 8px 16px; border: none; border-radius: 3px; cursor: pointer;
        font-size: 15px; margin-top: 20px;
    }
    input[type="submit"]:hover { background: var(--polaris-success-strong-hover); }

    .message { text-align: center; margin-bottom: 20px; padding: 10px; border-radius: 5px; }
    .error { background-color: var(--polaris-error-bg); color: var(--polaris-error-text); }
</style>

<div class="content-wrapper">
    <h2>Submit a Case</h2>
    <p class="section-hint" style="text-align:center;">This is filed for review - it won't have a case number until it's approved.</p>

    <?php if (!empty($message)): ?>
    <div class="message error"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <h3>Case Details</h3>
        <div class="form-columns">
            <div class="column">
                <div class="field">
                    <label>Case Type (required)</label>
                    <select name="case_type" required>
                        <option value="">Select Case Type</option>
                        <?php foreach ($caseTypes as $ct): ?>
                        <option value="<?php echo $ct['case_type_id']; ?>"><?php echo htmlspecialchars($ct['type_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Operation</label>
                    <select name="operation">
                        <option value="">Select Operation</option>
                        <?php foreach ($operations as $op): ?>
                        <option value="<?php echo $op['operation_id']; ?>"><?php echo htmlspecialchars($op['operation_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>OIC</label>
                    <input type="text" name="oic" placeholder="Enter Officer In Charge">
                </div>
                <div class="field">
                    <label>Incident Number (optional)</label>
                    <input type="text" name="incident_number" placeholder="Not every force issues one">
                </div>
                <div class="field">
                    <label>External Reference (optional)</label>
                    <input type="text" name="external_reference" placeholder="Reference used by another lab, if any">
                </div>
                <div class="field">
                    <label>Case Background</label>
                    <textarea name="description" rows="5" placeholder="Enter case background"></textarea>
                </div>
            </div>
            <div class="column">
                <div class="field">
                    <label>Customer</label>
                    <select name="customer">
                        <option value="">Select Customer</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?php echo $c['customer_id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Lead Force (optional)</label>
                    <select name="lead_force">
                        <option value="">Select Lead Force</option>
                        <?php foreach ($forces as $f): ?>
                        <option value="<?php echo $f['id']; ?>"><?php echo htmlspecialchars($f['force_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Suspect</label>
                    <input type="text" name="suspect" placeholder="Enter suspect name">
                </div>
                <div class="field checkbox-group">
                    <label><input type="checkbox" name="fingerprints" value="1"> Fingerprints</label>
                    <label><input type="checkbox" name="dna" value="1"> DNA</label>
                    <label><input type="checkbox" name="malware" value="1"> Malware</label>
                </div>
                <div class="field">
                    <label>Subject Photo (optional)</label>
                    <input type="file" name="subject_photo" accept="image/*">
                </div>
            </div>
        </div>

        <h3>Contacts</h3>
        <p class="section-hint">Picked from the external contacts list - manage it under Manage Lookup Data.</p>
        <div class="form-columns">
            <div class="column">
                <div class="field">
                    <label>Primary Contact</label>
                    <select name="contact_primary">
                        <option value="">Select Contact</option>
                        <?php foreach ($contacts as $c): ?>
                        <option value="<?php echo $c['contact_id']; ?>"><?php echo htmlspecialchars($c['name'] . ($c['role_title'] ? ' - ' . $c['role_title'] : '')); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="column">
                <div class="field">
                    <label>Secondary Contact</label>
                    <select name="contact_secondary">
                        <option value="">Select Contact</option>
                        <?php foreach ($contacts as $c): ?>
                        <option value="<?php echo $c['contact_id']; ?>"><?php echo htmlspecialchars($c['name'] . ($c['role_title'] ? ' - ' . $c['role_title'] : '')); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <h3>Exhibits</h3>
        <p class="section-hint">Declared now, checked against what's actually received when the exhibits are booked in.</p>
        <div class="quantity-helper">
            <div class="field">
                <label>Add multiple of a type</label>
                <select id="qty_type">
                    <option value="">Select Type</option>
                    <?php foreach ($exhibitTypes as $et): ?>
                    <option value="<?php echo $et['exhibit_type_id']; ?>"><?php echo htmlspecialchars($et['type_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="max-width:100px;">
                <label>Quantity</label>
                <input type="number" id="qty_count" min="1" value="1">
            </div>
            <button type="button" class="add-row-btn" onclick="addExhibitRows()">Add</button>
        </div>
        <table class="repeat-table" id="exhibitsTable">
            <thead>
                <tr><th>Exhibit Ref</th><th>Type</th><th>Description</th><th>Bag No.</th><th>Seizing Officer</th><th>Seizing Location</th><th></th></tr>
            </thead>
            <tbody></tbody>
        </table>
        <button type="button" class="add-row-btn" onclick="addExhibitRow()">+ Add Exhibit Row</button>

        <h3>Key Dates</h3>
        <table class="repeat-table" id="keyDatesTable">
            <thead><tr><th>Date</th><th>What is it?</th><th></th></tr></thead>
            <tbody></tbody>
        </table>
        <button type="button" class="add-row-btn" onclick="addKeyDateRow()">+ Add Key Date</button>

        <?php if (!empty($questions)): ?>
        <h3>Additional Questions</h3>
        <?php foreach ($questions as $q): ?>
        <div class="field">
            <label><?php echo htmlspecialchars($q['field_label']); ?><?php echo $q['is_required'] ? ' (required)' : ''; ?></label>
            <?php $qname = 'question_' . $q['field_id']; ?>
            <?php if ($q['field_type'] === 'textarea'): ?>
            <textarea name="<?php echo $qname; ?>" rows="3" <?php echo $q['is_required'] ? 'required' : ''; ?>></textarea>
            <?php elseif ($q['field_type'] === 'checkbox'): ?>
            <label style="font-weight:normal;"><input type="checkbox" name="<?php echo $qname; ?>" value="1"> Yes</label>
            <?php elseif ($q['field_type'] === 'date'): ?>
            <input type="date" name="<?php echo $qname; ?>" <?php echo $q['is_required'] ? 'required' : ''; ?>>
            <?php elseif ($q['field_type'] === 'number'): ?>
            <input type="number" name="<?php echo $qname; ?>" <?php echo $q['is_required'] ? 'required' : ''; ?>>
            <?php else: ?>
            <input type="text" name="<?php echo $qname; ?>" <?php echo $q['is_required'] ? 'required' : ''; ?>>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <input type="submit" value="Submit for Review">
    </form>
</div>

<template id="exhibitRowTemplate">
    <tr>
        <td><input type="text" name="exhibit_ref[]" placeholder="Assigned by seizing officer" oninput="this.value=this.value.toUpperCase();"></td>
        <td>
            <select name="exhibit_type[]">
                <option value="">Select Type</option>
                <?php foreach ($exhibitTypes as $et): ?>
                <option value="<?php echo $et['exhibit_type_id']; ?>"><?php echo htmlspecialchars($et['type_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td><input type="text" name="exhibit_description[]"></td>
        <td><input type="text" name="bag_number[]" oninput="this.value=this.value.toUpperCase();"></td>
        <td><input type="text" name="seizing_officer[]"></td>
        <td><input type="text" name="seizing_location[]"></td>
        <td><button type="button" class="remove-row" onclick="this.closest('tr').remove()">Remove</button></td>
    </tr>
</template>

<template id="keyDateRowTemplate">
    <tr>
        <td><input type="date" name="key_date[]"></td>
        <td><input type="text" name="key_date_label[]" placeholder="e.g. Court date, Bail date"></td>
        <td><button type="button" class="remove-row" onclick="this.closest('tr').remove()">Remove</button></td>
    </tr>
</template>

<script>
function addExhibitRow(typeId) {
    const tpl = document.getElementById('exhibitRowTemplate');
    const row = tpl.content.cloneNode(true);
    if (typeId) {
        row.querySelector('select[name="exhibit_type[]"]').value = typeId;
    }
    document.querySelector('#exhibitsTable tbody').appendChild(row);
}
function addExhibitRows() {
    const typeId = document.getElementById('qty_type').value;
    const count = parseInt(document.getElementById('qty_count').value, 10) || 1;
    if (!typeId) {
        alert('Select a type first.');
        return;
    }
    for (let i = 0; i < count; i++) {
        addExhibitRow(typeId);
    }
}
function addKeyDateRow() {
    const tpl = document.getElementById('keyDateRowTemplate');
    document.querySelector('#keyDatesTable tbody').appendChild(tpl.content.cloneNode(true));
}
</script>
