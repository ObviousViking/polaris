<?php
// cargo_hold/book_in_exhibits.php
//
// "Bring this exhibit into the store" - unified for two cases that are the
// same real-world event from the store's point of view: an exhibit coming
// back after being checked out, and an exhibit declared via a case
// submission arriving at the lab for the first time. Both are listed and
// booked in together; add_exhibit.php stays for genuinely new, undeclared
// exhibits with nothing to reconcile against.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once('../includes/integrity.php');
require_once '../includes/permissions.php';
require_once '../includes/exhibit_receipts.php';
require_permission($conn, 'exhibit_edit');

// Ensure a job_id is provided.
if (!isset($_GET['job_id'])) {
    die("Job ID not specified.");
}
$job_id = intval($_GET['job_id']);

// Query only exhibits for this job that are currently checked out (time_out IS NOT NULL) -
// same field set as add_exhibit.php's form, since details like Bag Number or
// Delivered By may need correcting by the time an exhibit comes back.
$stmt = $conn->prepare("SELECT exhibit_id, exhibit_ref, item_description, bag_number, exhibit_type_id,
                                urgency, location_id, delivered_by, time_out
                        FROM exhibits
                        WHERE job_id = ? AND time_out IS NOT NULL AND deleted_at IS NULL
                        ORDER BY time_out DESC");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$result = $stmt->get_result();

$exhibits = [];
while ($row = $result->fetch_assoc()) {
    $exhibits[] = $row;
}
$stmt->close();

// Exhibits declared via a case submission but not yet physically in the
// store - 'Missing' is included too (not just 'Declared'), since an item
// flagged missing might still turn up later and should stay bookable
// rather than being a dead end.
$originSubmissionId = null;
$subStmt = $conn->prepare("SELECT submission_id FROM submissions WHERE job_id = ?");
$subStmt->bind_param("i", $job_id);
$subStmt->execute();
$subStmt->bind_result($originSubmissionId);
$subStmt->fetch();
$subStmt->close();

$declaredExhibits = [];
if ($originSubmissionId) {
    $stmt = $conn->prepare("
        SELECT submitted_exhibit_id, exhibit_type_id, exhibit_ref, description, bag_number, seizing_officer, seizing_location, reconcile_status
        FROM submitted_exhibits
        WHERE submission_id = ? AND reconcile_status != 'Received'
        ORDER BY submitted_exhibit_id
    ");
    $stmt->bind_param("i", $originSubmissionId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $declaredExhibits[] = $row;
    }
    $stmt->close();
}

// Exhibit types, same as add_exhibit.php.
$exhibitTypes = [];
$result = $conn->query("SELECT exhibit_type_id, type_name FROM exhibit_types ORDER BY type_name");
while ($row = $result->fetch_assoc()) {
    $exhibitTypes[] = $row;
}
$result->free();

// Active locations only, same as add_exhibit.php - where the exhibit is
// being stored now that it's here.
$locations = [];
$result = $conn->query("SELECT location_id, location_name FROM exhibit_locations WHERE is_active = 1 ORDER BY location_name");
while ($row = $result->fetch_assoc()) {
    $locations[] = $row;
}
$result->free();

$message = "";
$valid_urgencies = ['Low', 'Medium', 'High'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['exhibits']) || !is_array($_POST['exhibits'])) {
        $message = "No exhibits selected.";
    } else {
        $selectedKeys = $_POST['exhibits'];
        $receivedBy = trim($_POST['received_by'] ?? '');

        if (empty($receivedBy)) {
            $message = "Please enter who returned/delivered the exhibit(s).";
        } else {
            $exhibitsById = [];
            foreach ($exhibits as $ex) {
                $exhibitsById[$ex['exhibit_id']] = $ex;
            }
            $declaredById = [];
            foreach ($declaredExhibits as $de) {
                $declaredById[$de['submitted_exhibit_id']] = $de;
            }

            // Per-row fields are keyed by the same "E123"/"D45" string used
            // in the checkbox value - not a plain exhibit_id - so a
            // declared row (no exhibit_id yet) and a real one never collide.
            $exhibitRefPosts = $_POST['exhibit_ref'] ?? [];
            $descriptions = $_POST['item_description'] ?? [];
            $exhibitTypePosts = $_POST['exhibit_type'] ?? [];
            $urgencyPosts = $_POST['urgency'] ?? [];
            $bagNumbers = $_POST['bag_number'] ?? [];
            $deliveredBys = $_POST['delivered_by'] ?? [];
            $locationPosts = $_POST['location'] ?? [];

            // Captured once so every exhibit in this batch (and the
            // receipt) shows the same moment.
            $eventTime = date('Y-m-d H:i:s');
            $updated_ids = [];
            $receiptRows = [];
            $changedBy = (int) $_SESSION['user_id'];

            foreach ($selectedKeys as $key) {
                $kind = substr($key, 0, 1);
                $id = intval(substr($key, 1));

                $newLocationId = intval($locationPosts[$key] ?? 0);
                $newLocationName = '';
                foreach ($locations as $loc) {
                    if ($loc['location_id'] == $newLocationId) {
                        $newLocationName = $loc['location_name'];
                        break;
                    }
                }

                if ($kind === 'E' && isset($exhibitsById[$id])) {
                    $old = $exhibitsById[$id];

                    $new_item_description = trim($descriptions[$key] ?? $old['item_description']);
                    $new_exhibit_type_id  = intval($exhibitTypePosts[$key] ?? $old['exhibit_type_id']);
                    $new_urgency          = in_array($urgencyPosts[$key] ?? '', $valid_urgencies, true)
                        ? $urgencyPosts[$key] : $old['urgency'];
                    $new_bag_number       = strtoupper(trim($bagNumbers[$key] ?? $old['bag_number']));
                    $new_delivered_by     = trim($deliveredBys[$key] ?? $old['delivered_by']);

                    if (empty($new_exhibit_type_id) || empty($newLocationId) || empty($new_delivered_by)) {
                        $message = "Please fill in Exhibit Type, Location, and Delivered By for exhibit '{$old['exhibit_ref']}'.";
                        break;
                    }
                    if (strlen($new_bag_number) > 50 || strlen($new_item_description) > 255 || strlen($new_delivered_by) > 255) {
                        $message = "Bag Number exceeds 50 characters, or Description/Delivered By exceeds 255 characters for exhibit '{$old['exhibit_ref']}'.";
                        break;
                    }

                    $updateStmt = $conn->prepare("
                        UPDATE exhibits
                        SET time_out = NULL, item_description = ?, exhibit_type_id = ?, urgency = ?,
                            bag_number = ?, delivered_by = ?, location_id = ?
                        WHERE exhibit_id = ?
                    ");
                    $updateStmt->bind_param(
                        "sisssii",
                        $new_item_description,
                        $new_exhibit_type_id,
                        $new_urgency,
                        $new_bag_number,
                        $new_delivered_by,
                        $newLocationId,
                        $id
                    );
                    if (!$updateStmt->execute()) {
                        $message = "Error updating exhibit ID $id: " . $updateStmt->error;
                        $updateStmt->close();
                        break;
                    }
                    $updateStmt->close();

                    // Field-level diff, same convention as edit_exhibit.php - plus
                    // the return-event facts, which aren't a "field" on the
                    // exhibit itself.
                    $changes = [
                        'returned_by' => $receivedBy,
                        'returned_at' => $eventTime,
                        'location'    => $newLocationName,
                    ];
                    if ($new_item_description !== ($old['item_description'] ?? '')) {
                        $changes['Item Description'] = ['old' => $old['item_description'], 'new' => $new_item_description];
                    }
                    if ($new_bag_number !== ($old['bag_number'] ?? '')) {
                        $changes['Bag Number'] = ['old' => $old['bag_number'], 'new' => $new_bag_number];
                    }
                    if ($new_delivered_by !== ($old['delivered_by'] ?? '')) {
                        $changes['Delivered By'] = ['old' => $old['delivered_by'], 'new' => $new_delivered_by];
                    }
                    if ((int) $new_exhibit_type_id !== (int) $old['exhibit_type_id']) {
                        $oldType = $newType = '';
                        foreach ($exhibitTypes as $et) {
                            if ($et['exhibit_type_id'] == $old['exhibit_type_id']) {
                                $oldType = $et['type_name'];
                            }
                            if ($et['exhibit_type_id'] == $new_exhibit_type_id) {
                                $newType = $et['type_name'];
                            }
                        }
                        $changes['Exhibit Type'] = ['old' => $oldType, 'new' => $newType];
                    }
                    if ($new_urgency !== $old['urgency']) {
                        $changes['Urgency'] = ['old' => $old['urgency'], 'new' => $new_urgency];
                    }

                    if (!insert_history_row($conn, 'exhibit_history', $id, 'RETURN', $changedBy, json_encode($changes))) {
                        $message = "Error adding history for exhibit ID $id: " . $conn->error;
                        break;
                    }

                    $updated_ids[] = $id;
                    $receiptRows[] = [
                        'exhibit_id'       => $id,
                        'exhibit_ref'      => $old['exhibit_ref'] ?? '',
                        'item_description' => $new_item_description,
                        'time_out'         => $old['time_out'] ?? '',
                        'returned_at'      => $eventTime,
                        'location_name'    => $newLocationName,
                    ];
                } elseif ($kind === 'D' && isset($declaredById[$id])) {
                    $old = $declaredById[$id];

                    $new_exhibit_ref      = strtoupper(trim($exhibitRefPosts[$key] ?? ''));
                    $new_item_description = trim($descriptions[$key] ?? $old['description']);
                    $new_exhibit_type_id  = intval($exhibitTypePosts[$key] ?? $old['exhibit_type_id']);
                    $new_urgency          = in_array($urgencyPosts[$key] ?? '', $valid_urgencies, true)
                        ? $urgencyPosts[$key] : 'Low';
                    $new_bag_number       = strtoupper(trim($bagNumbers[$key] ?? $old['bag_number']));
                    $new_delivered_by     = trim($deliveredBys[$key] ?? $receivedBy);

                    if (empty($new_exhibit_ref) || empty($new_exhibit_type_id) || empty($newLocationId) || empty($new_delivered_by)) {
                        $message = "Please fill in Exhibit Ref, Exhibit Type, Location, and Delivered By for the declared item '{$old['description']}'.";
                        break;
                    }
                    if (strlen($new_exhibit_ref) > 50 || strlen($new_bag_number) > 50 || strlen($new_item_description) > 255 || strlen($new_delivered_by) > 255) {
                        $message = "Exhibit Ref or Bag Number exceeds 50 characters, or Description/Delivered By exceeds 255 characters for '{$old['description']}'.";
                        break;
                    }

                    // Staff must confirm they've actually checked the physical
                    // bag against what was declared - named by declared id
                    // (not a positional array), since an unchecked checkbox
                    // never submits at all and would otherwise silently
                    // misalign against the other per-row fields.
                    if (!isset($_POST['bag_checked_D' . $id])) {
                        $message = "Please confirm bag details have been checked against the declaration for '{$old['description']}'.";
                        break;
                    }

                    $dupCheck = $conn->prepare("SELECT COUNT(*) FROM exhibits WHERE UPPER(exhibit_ref) = ? AND job_id = ?");
                    $dupCheck->bind_param("si", $new_exhibit_ref, $job_id);
                    $dupCheck->execute();
                    $dupCheck->bind_result($dupCount);
                    $dupCheck->fetch();
                    $dupCheck->close();
                    if ($dupCount > 0) {
                        $message = "Exhibit reference '$new_exhibit_ref' already exists for this job.";
                        break;
                    }

                    $insStmt = $conn->prepare("
                        INSERT INTO exhibits
                        (job_id, barcode, time_in, time_out, exhibit_type_id, bag_number, exhibit_ref, urgency, location_id, delivered_by, item_description, status, created_by)
                        VALUES (?, '', ?, NULL, ?, ?, ?, ?, ?, ?, ?, 'Not Yet Started', ?)
                    ");
                    $insStmt->bind_param(
                        "isisssissi",
                        $job_id,
                        $eventTime,
                        $new_exhibit_type_id,
                        $new_bag_number,
                        $new_exhibit_ref,
                        $new_urgency,
                        $newLocationId,
                        $new_delivered_by,
                        $new_item_description,
                        $changedBy
                    );
                    if (!$insStmt->execute()) {
                        $message = "Error booking in declared exhibit '$new_exhibit_ref': " . $insStmt->error;
                        $insStmt->close();
                        break;
                    }
                    $newExhibitId = $conn->insert_id;
                    $insStmt->close();

                    // Same richer field set add_exhibit.php has always logged
                    // on a fresh BOOK_IN - plus, where what's actually here
                    // differs from what was declared (e.g. a bag number a
                    // digit off), that field is an old/new pair right in this
                    // entry rather than only visible on the submission's own
                    // history.
                    $bookInTypeName = '';
                    foreach ($exhibitTypes as $et) {
                        if ($et['exhibit_type_id'] == $new_exhibit_type_id) {
                            $bookInTypeName = $et['type_name'];
                            break;
                        }
                    }
                    $bookInChanges = [
                        'exhibit_ref' => ($new_exhibit_ref !== ($old['exhibit_ref'] ?? ''))
                            ? ['old' => $old['exhibit_ref'], 'new' => $new_exhibit_ref] : $new_exhibit_ref,
                        'bag_number' => ($new_bag_number !== ($old['bag_number'] ?? ''))
                            ? ['old' => $old['bag_number'], 'new' => $new_bag_number] : $new_bag_number,
                        'item_description' => ($new_item_description !== ($old['description'] ?? ''))
                            ? ['old' => $old['description'], 'new' => $new_item_description] : $new_item_description,
                        'exhibit_type' => $bookInTypeName,
                        'urgency' => $new_urgency,
                        'location' => $newLocationName,
                        'delivered_by' => $new_delivered_by,
                        'declared_via' => 'Case Submission',
                    ];
                    if (!insert_history_row($conn, 'exhibit_history', $newExhibitId, 'BOOK_IN', $changedBy, json_encode($bookInChanges))) {
                        $message = "Error adding history for exhibit ID $newExhibitId: " . $conn->error;
                        break;
                    }

                    $reconcileStmt = $conn->prepare("UPDATE submitted_exhibits SET reconcile_status = 'Received', matched_exhibit_id = ? WHERE submitted_exhibit_id = ?");
                    $reconcileStmt->bind_param("ii", $newExhibitId, $id);
                    $reconcileStmt->execute();
                    $reconcileStmt->close();

                    // Same discrepancy facts, mirrored onto the submission's
                    // own history so reviewing the case submission doesn't
                    // require cross-referencing the exhibit separately.
                    $reconcileChanges = [
                        'Declared Exhibit' => $old['exhibit_ref'] ?: ($old['bag_number'] ?: "#$id"),
                        'Reconcile Status' => ['old' => $old['reconcile_status'], 'new' => 'Received'],
                    ];
                    if ($new_exhibit_ref !== ($old['exhibit_ref'] ?? '')) {
                        $reconcileChanges['Exhibit Ref'] = ['old' => $old['exhibit_ref'], 'new' => $new_exhibit_ref];
                    }
                    if ($new_bag_number !== ($old['bag_number'] ?? '')) {
                        $reconcileChanges['Bag Number'] = ['old' => $old['bag_number'], 'new' => $new_bag_number];
                    }
                    if ($new_item_description !== ($old['description'] ?? '')) {
                        $reconcileChanges['Description'] = ['old' => $old['description'], 'new' => $new_item_description];
                    }
                    insert_history_row($conn, 'submission_history', $originSubmissionId, 'UPDATE', $changedBy, json_encode($reconcileChanges));

                    $updated_ids[] = $newExhibitId;
                    $receiptRows[] = [
                        'exhibit_id'       => $newExhibitId,
                        'exhibit_ref'      => $new_exhibit_ref,
                        'item_description' => $new_item_description,
                        'time_out'         => 'New arrival (declared via Case Submissions)',
                        'returned_at'      => $eventTime,
                        'location_name'    => $newLocationName,
                    ];
                }
            }

            if (empty($message) && !empty($updated_ids)) {
                $jobStmt = $conn->prepare("SELECT custom_ref FROM jobs WHERE job_id = ?");
                $jobStmt->bind_param("i", $job_id);
                $jobStmt->execute();
                $jobStmt->bind_result($jobCustomRef);
                $jobStmt->fetch();
                $jobStmt->close();

                $receiptId = save_exhibit_receipt_with_rows($conn, $job_id, 'return', $receiptRows, (string) $jobCustomRef, $changedBy, $receivedBy);
                $receiptURL = $receiptId ? "view_receipt.php?receipt_id=" . urlencode($receiptId) : null;

                // A real link avoids popup-blocker issues window.open() would hit here.
                include('../header.php');
                ?>
                <div class="content-wrapper" style="max-width:500px; margin:150px auto 20px; text-align:center;">
                    <h2>Exhibit(s) Booked In</h2>
                    <p>The exhibit(s) are now in the store.</p>
                    <?php if ($receiptURL): ?>
                    <p>
                        <a href="<?php echo htmlspecialchars($receiptURL); ?>" target="_blank"
                            style="display:inline-block; padding:5px 10px; background:var(--polaris-accent); color:var(--polaris-text); border-radius:3px; font-size:14px; text-decoration:none; margin-bottom:10px;">
                            View / Print Receipt
                        </a>
                    </p>
                    <?php endif; ?>
                    <p>
                        <a href="job.php?job_id=<?php echo (int) $job_id; ?>"
                            style="display:inline-block; padding:5px 10px; background:var(--polaris-accent); color:var(--polaris-text); border-radius:3px; font-size:14px; text-decoration:none;">
                            Continue to Case
                        </a>
                    </p>
                </div>
                </body>
                </html>
                <?php
                exit();
            } elseif (empty($message)) {
                $message = "No exhibits selected.";
            }
        }
    }
}

include('../header.php');
?>

<style>
    .container {
        max-width: 1200px;
        margin: 160px auto 40px auto;
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 5px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
    }

    h2 {
        text-align: center;
        margin-bottom: 20px;
    }

    .message {
        text-align: center;
        margin-bottom: 20px;
        padding: 10px;
        border-radius: 5px;
        font-size: 16px;
    }

    .error {
        background-color: var(--polaris-error-bg);
        color: var(--polaris-error-text);
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
        table-layout: auto;
    }

    table th,
    table td {
        border: 1px solid var(--polaris-border);
        padding: 8px;
        text-align: left;
        font-size: 14px;
    }

    table th {
        background: var(--polaris-divider);
    }

    tr.declared-row td {
        background: color-mix(in srgb, var(--polaris-warning) 12%, transparent);
    }

    .not-arrived-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 12px;
        background: var(--polaris-warning);
        color: #1a1a1a;
        white-space: nowrap;
        margin-bottom: 6px;
    }

    .bag-checked-label {
        display: flex;
        align-items: flex-start;
        gap: 4px;
        font-size: 12px;
        font-weight: normal;
        color: var(--polaris-text-dim);
        white-space: normal;
        min-width: 140px;
    }

    input[type="text"],
    select {
        width: 100%;
        min-width: 110px;
        padding: 8px;
        border: 1px solid var(--polaris-border);
        border-radius: 4px;
        background: var(--polaris-surface-deep);
        color: var(--polaris-text);
    }

    .form-field {
        margin-bottom: 15px;
    }

    .form-field label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
        color: var(--polaris-text-dim);
    }

    .button-group {
        display: flex;
        gap: 10px;
        margin-top: 20px;
    }

    .button-group button {
        flex: 1;
        padding: 5px 10px;
        font-size: 14px;
        border: none;
        border-radius: 3px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        cursor: pointer;
        transition: background 0.3s ease;
    }

    .button-group button:hover {
        background: var(--polaris-accent-hover);
    }

    a {
        color: var(--polaris-text-dim);
        text-decoration: underline;
    }
    </style>

    <div class="container">
        <h2>Book In Exhibits</h2>
        <?php if (!empty($message)): ?>
        <p class="message error"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <?php if (!empty($exhibits) || !empty($declaredExhibits)): ?>
        <form method="post" action="book_in_exhibits.php?job_id=<?php echo $job_id; ?>">
            <table>
                <tr>
                    <th>Select</th>
                    <th>Exhibit Ref</th>
                    <th>Description</th>
                    <th>Exhibit Type</th>
                    <th>Urgency</th>
                    <th>Bag Number</th>
                    <th>Delivered By</th>
                    <th>Location</th>
                    <th>Was</th>
                </tr>
                <?php foreach ($exhibits as $exhibit): $key = 'E' . $exhibit['exhibit_id']; ?>
                <tr>
                    <td><input type="checkbox" name="exhibits[]" value="<?php echo $key; ?>" onchange="toggleRowRequired(this)"></td>
                    <td><?php echo htmlspecialchars($exhibit['exhibit_ref']); ?></td>
                    <td>
                        <input type="text" name="item_description[<?php echo $key; ?>]"
                            value="<?php echo htmlspecialchars($exhibit['item_description']); ?>">
                    </td>
                    <td>
                        <select name="exhibit_type[<?php echo $key; ?>]">
                            <?php foreach ($exhibitTypes as $et): ?>
                            <option value="<?php echo $et['exhibit_type_id']; ?>"
                                <?php echo ($et['exhibit_type_id'] == $exhibit['exhibit_type_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($et['type_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="urgency[<?php echo $key; ?>]">
                            <?php foreach ($valid_urgencies as $u): ?>
                            <option value="<?php echo $u; ?>" <?php echo ($exhibit['urgency'] === $u) ? 'selected' : ''; ?>>
                                <?php echo $u; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="text" name="bag_number[<?php echo $key; ?>]"
                            value="<?php echo htmlspecialchars($exhibit['bag_number']); ?>"
                            oninput="this.value=this.value.toUpperCase();">
                    </td>
                    <td>
                        <input type="text" name="delivered_by[<?php echo $key; ?>]"
                            value="<?php echo htmlspecialchars($exhibit['delivered_by']); ?>">
                    </td>
                    <td>
                        <select name="location[<?php echo $key; ?>]">
                            <?php foreach ($locations as $loc): ?>
                            <option value="<?php echo $loc['location_id']; ?>"
                                <?php echo ($loc['location_id'] == $exhibit['location_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($loc['location_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>Checked out <?php echo htmlspecialchars($exhibit['time_out']); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php foreach ($declaredExhibits as $declared): $key = 'D' . $declared['submitted_exhibit_id']; ?>
                <tr class="declared-row">
                    <td><input type="checkbox" name="exhibits[]" value="<?php echo $key; ?>" onchange="toggleRowRequired(this)"></td>
                    <td>
                        <input type="text" name="exhibit_ref[<?php echo $key; ?>]"
                            value="<?php echo htmlspecialchars($declared['exhibit_ref'] ?? ''); ?>"
                            placeholder="Assigned by seizing officer" oninput="this.value=this.value.toUpperCase();">
                    </td>
                    <td>
                        <input type="text" name="item_description[<?php echo $key; ?>]"
                            value="<?php echo htmlspecialchars($declared['description'] ?? ''); ?>">
                    </td>
                    <td>
                        <select name="exhibit_type[<?php echo $key; ?>]">
                            <option value="">Select Type</option>
                            <?php foreach ($exhibitTypes as $et): ?>
                            <option value="<?php echo $et['exhibit_type_id']; ?>"
                                <?php echo ($et['exhibit_type_id'] == $declared['exhibit_type_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($et['type_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="urgency[<?php echo $key; ?>]">
                            <?php foreach ($valid_urgencies as $u): ?>
                            <option value="<?php echo $u; ?>" <?php echo $u === 'Low' ? 'selected' : ''; ?>><?php echo $u; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="text" name="bag_number[<?php echo $key; ?>]"
                            value="<?php echo htmlspecialchars($declared['bag_number'] ?? ''); ?>"
                            oninput="this.value=this.value.toUpperCase();">
                    </td>
                    <td>
                        <input type="text" name="delivered_by[<?php echo $key; ?>]"
                            value="<?php echo htmlspecialchars($declared['seizing_officer'] ?? ''); ?>">
                    </td>
                    <td>
                        <select name="location[<?php echo $key; ?>]" class="conditional-required">
                            <option value="">Select Location</option>
                            <?php foreach ($locations as $loc): ?>
                            <option value="<?php echo $loc['location_id']; ?>"><?php echo htmlspecialchars($loc['location_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <span class="not-arrived-badge">Not Arrived</span>
                        <label class="bag-checked-label">
                            <input type="checkbox" name="bag_checked_<?php echo $key; ?>" value="1" class="conditional-required">
                            Bag details checked against declaration
                        </label>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <div class="form-field">
                <label>Received/Returned By:</label>
                <input type="text" name="received_by"
                    placeholder="Enter the name of the person delivering or returning the exhibits" required>
            </div>
            <div class="button-group">
                <button type="submit">Book In Selected</button>
                <button type="button"
                    onclick="window.location.href='job.php?job_id=<?php echo $job_id; ?>'">Cancel</button>
            </div>
        </form>
        <script>
        // A row's location (and, for a declared item, its bag-checked
        // confirmation) only needs to be filled in if that row is actually
        // being booked in this batch - marking them required unconditionally
        // in the markup would block submitting rows you've left unselected.
        function toggleRowRequired(checkbox) {
            var row = checkbox.closest('tr');
            row.querySelectorAll('.conditional-required').forEach(function(el) {
                el.required = checkbox.checked;
            });
        }
        </script>
        <?php else: ?>
        <p>No exhibits are currently outstanding for this job.</p>
        <p><a href="job.php?job_id=<?php echo $job_id; ?>">Return to Job</a></p>
        <?php endif; ?>
    </div>
</body>

</html>
