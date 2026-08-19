<?php
// edit_case_item.php
//
// Mirrors edit_task.php's shape: same permission rule, same update pattern -
// changes land in case_item_history (hash-chained, same as exhibit_history)
// rather than the generic audit_log, since these are case-evidential
// records, not admin activity.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/integrity.php';
require_once '../includes/permissions.php';

$item_id = isset($_GET['item_id']) ? intval($_GET['item_id']) : 0;

$stmt = $conn->prepare("SELECT * FROM case_items WHERE item_id = ?");
$stmt->bind_param("i", $item_id);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$item) {
    header("Location: ../dashboard.php");
    exit();
}

// Only a user with exhibit_edit, or the assigned user, may edit it.
if (!user_can($conn, (int) $_SESSION['user_id'], 'exhibit_edit') && (int) $item['assigned_to'] !== (int) $_SESSION['user_id']) {
    echo '<p style="color: var(--polaris-danger);">You do not have permission to edit this item.</p>';
    exit();
}

$job_id = (int) $item['job_id'];
$validStatuses = ['Awaiting Review', 'Being Reviewed', 'Reviewed', 'Not Reviewed'];

// File versions for this item, newest first - the current version is
// simply the highest `version` per item_id (see
// includes/migrations/022_produced_items.sql).
$files = [];
$fileStmt = $conn->prepare("
    SELECT cif.file_id, cif.version, cif.original_filename, cif.file_size, cif.explainer, cif.uploaded_at,
           CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by_name
    FROM case_item_files cif
    LEFT JOIN users u ON cif.uploaded_by = u.id
    WHERE cif.item_id = ?
    ORDER BY cif.version DESC
");
$fileStmt->bind_param("i", $item_id);
$fileStmt->execute();
$fileResult = $fileStmt->get_result();
while ($row = $fileResult->fetch_assoc()) {
    $files[] = $row;
}
$fileStmt->close();

$users = [];
$res = $conn->query("SELECT id, CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE is_active = 1 ORDER BY first_name, last_name");
while ($row = $res->fetch_assoc()) {
    $users[] = $row;
}
$res->free();

$exhibits = [];
$exStmt = $conn->prepare("SELECT exhibit_id, exhibit_ref FROM exhibits WHERE job_id = ? AND deleted_at IS NULL ORDER BY exhibit_ref");
$exStmt->bind_param("i", $job_id);
$exStmt->execute();
$exResult = $exStmt->get_result();
while ($row = $exResult->fetch_assoc()) {
    $exhibits[] = $row;
}
$exStmt->close();

// All types (not just active) so a historically-used-but-now-deactivated
// type still shows correctly on an existing item.
$types = [];
$typeResult = $conn->query("SELECT type_id, type_name, is_active FROM case_item_types ORDER BY type_name");
while ($row = $typeResult->fetch_assoc()) {
    $types[] = $row;
}

function case_item_exhibit_ref(array $exhibits, ?int $exhibitId): string
{
    if (!$exhibitId) {
        return '';
    }
    foreach ($exhibits as $ex) {
        if ((int) $ex['exhibit_id'] === $exhibitId) {
            return $ex['exhibit_ref'];
        }
    }
    return '';
}

function case_item_type_name(array $types, ?int $typeId): string
{
    if (!$typeId) {
        return '';
    }
    foreach ($types as $t) {
        if ((int) $t['type_id'] === $typeId) {
            return $t['type_name'];
        }
    }
    return '';
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'details') {
    $type_id = intval($_POST['type_id'] ?? 0);
    $description = trim($_POST['description']);
    $notes = trim($_POST['notes'] ?? '');
    $status = in_array($_POST['status'] ?? '', $validStatuses, true) ? $_POST['status'] : $item['status'];
    $created_on = $_POST['created_on'] ?: null;
    $assigned_to = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;
    $source_exhibit_id = !empty($_POST['source_exhibit_id']) ? intval($_POST['source_exhibit_id']) : null;
    $file_count = ($_POST['file_count'] ?? '') !== '' ? intval($_POST['file_count']) : null;

    $changes = [];
    if ($type_id !== (int) $item['type_id']) {
        $changes['Type'] = ['old' => case_item_type_name($types, (int) $item['type_id']), 'new' => case_item_type_name($types, $type_id)];
    }
    if ($description !== ($item['description'] ?? '')) {
        $changes['Description'] = ['old' => $item['description'], 'new' => $description];
    }
    if ($notes !== ($item['notes'] ?? '')) {
        $changes['Notes'] = ['old' => $item['notes'], 'new' => $notes];
    }
    if ($status !== $item['status']) {
        $changes['Status'] = ['old' => $item['status'], 'new' => $status];
    }
    if ($created_on !== $item['created_on']) {
        $changes['Created On'] = ['old' => $item['created_on'], 'new' => $created_on];
    }
    $oldAssignedTo = $item['assigned_to'] !== null ? (int) $item['assigned_to'] : null;
    if ($assigned_to !== $oldAssignedTo) {
        $oldUser = $newUser = '';
        foreach ($users as $u) {
            if ((int) $u['id'] === $oldAssignedTo) {
                $oldUser = $u['full_name'];
            }
            if ((int) $u['id'] === $assigned_to) {
                $newUser = $u['full_name'];
            }
        }
        $changes['Assigned To'] = ['old' => $oldUser, 'new' => $newUser];
    }
    if ($source_exhibit_id !== ((int) $item['source_exhibit_id'] ?: null)) {
        $changes['Source Exhibit'] = [
            'old' => case_item_exhibit_ref($exhibits, (int) $item['source_exhibit_id'] ?: null),
            'new' => case_item_exhibit_ref($exhibits, $source_exhibit_id),
        ];
    }
    if ($file_count !== ($item['file_count'] !== null ? (int) $item['file_count'] : null)) {
        $changes['Number of Files'] = ['old' => $item['file_count'], 'new' => $file_count];
    }

    if (empty($type_id)) {
        $message = "Please select a type.";
    } else {
        $stmt = $conn->prepare("UPDATE case_items SET type_id = ?, description = ?, notes = ?, status = ?, created_on = ?, assigned_to = ?, source_exhibit_id = ?, file_count = ? WHERE item_id = ?");
        $stmt->bind_param("issssiiii", $type_id, $description, $notes, $status, $created_on, $assigned_to, $source_exhibit_id, $file_count, $item_id);
        if ($stmt->execute()) {
            if (!empty($changes)) {
                insert_history_row($conn, 'case_item_history', $item_id, 'UPDATE', (int) $_SESSION['user_id'], json_encode($changes));
            }
            $message = "Produced item updated.";

            // Refresh for display below.
            $stmt2 = $conn->prepare("SELECT * FROM case_items WHERE item_id = ?");
            $stmt2->bind_param("i", $item_id);
            $stmt2->execute();
            $item = $stmt2->get_result()->fetch_assoc();
            $stmt2->close();
        } else {
            $message = "Error updating item: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Flash message from upload_case_item_file.php's redirect back here.
if (empty($message) && isset($_SESSION['case_item_message'])) {
    $message = $_SESSION['case_item_message'];
    unset($_SESSION['case_item_message']);
}

include '../header.php';
?>
<style>
    /* header.php's own body{} already sets margin/background/color/font-family
       page-wide - scoped to .container instead, which already clears the
       fixed header via its margin-top. */
    .container {
        max-width: 800px;
        margin: 120px auto 20px;
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
        box-sizing: border-box;
    }

    h2 {
        font-size: 24px;
        margin-bottom: 20px;
        text-align: center;
    }

    h3 {
        font-size: 18px;
        margin: 30px 0 15px;
        border-top: 1px solid var(--polaris-border);
        padding-top: 20px;
    }

    form {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    label {
        font-size: 14px;
        color: var(--polaris-text-dim);
    }

    input[type="text"],
    input[type="date"],
    select,
    textarea {
        width: 100%;
        padding: 8px;
        background: var(--polaris-bg);
        border: 1px solid var(--polaris-border);
        color: var(--polaris-text);
        border-radius: 4px;
        font-size: 14px;
        box-sizing: border-box;
        font-family: inherit;
    }

    input[readonly] {
        background: var(--polaris-divider);
        cursor: not-allowed;
    }

    .btn-group {
        display: flex;
        gap: 10px;
        margin-top: 10px;
    }

    button,
    .back-btn {
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border: none;
        padding: 5px 10px;
        border-radius: 3px;
        font-size: 14px;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
    }

    button:hover,
    .back-btn:hover {
        background: var(--polaris-accent-hover);
    }

    .message {
        margin-bottom: 15px;
        padding: 10px;
        background: var(--polaris-divider);
        border-left: 4px solid var(--polaris-accent);
        font-size: 14px;
    }

    .handover-summary {
        font-size: 14px;
        color: var(--polaris-text-dim);
        margin-bottom: 15px;
    }

    .files-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 15px;
        font-size: 14px;
    }

    .files-table th,
    .files-table td {
        border: 1px solid var(--polaris-border);
        padding: 8px;
        text-align: left;
    }

    .files-table th {
        background: var(--polaris-divider);
    }

    .version-badge {
        display: inline-block;
        padding: 1px 6px;
        border-radius: 3px;
        background: var(--polaris-panel-alt);
        color: var(--polaris-text-dim);
        font-size: 12px;
    }
</style>

<div class="container">
    <h2>Edit Produced Item: <?php echo htmlspecialchars($item['item_ref']); ?></h2>

    <?php if (!empty($message)): ?>
    <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="form" value="details">
        <label for="item_ref">Item Reference</label>
        <input type="text" id="item_ref" value="<?php echo htmlspecialchars($item['item_ref']); ?>" readonly>

        <label for="type_id">Type</label>
        <select name="type_id" id="type_id" required>
            <?php foreach ($types as $t): ?>
            <option value="<?php echo $t['type_id']; ?>"
                <?php echo ((int) $item['type_id'] === (int) $t['type_id']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($t['type_name']); ?><?php echo empty($t['is_active']) ? ' (inactive)' : ''; ?>
            </option>
            <?php endforeach; ?>
        </select>

        <label for="source_exhibit_id">Source Exhibit</label>
        <select name="source_exhibit_id" id="source_exhibit_id">
            <option value="">None / not derived from a single exhibit</option>
            <?php foreach ($exhibits as $ex): ?>
            <option value="<?php echo $ex['exhibit_id']; ?>"
                <?php echo ((int) $item['source_exhibit_id'] === (int) $ex['exhibit_id']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($ex['exhibit_ref']); ?>
            </option>
            <?php endforeach; ?>
        </select>

        <label for="description">Description</label>
        <input type="text" name="description" id="description" value="<?php echo htmlspecialchars($item['description'] ?? ''); ?>">

        <label for="notes">Notes</label>
        <textarea name="notes" id="notes" rows="4"><?php echo htmlspecialchars($item['notes'] ?? ''); ?></textarea>

        <label for="file_count">Number of Files</label>
        <input type="text" name="file_count" id="file_count" inputmode="numeric" pattern="[0-9]*"
            value="<?php echo htmlspecialchars($item['file_count'] ?? ''); ?>"
            placeholder="Not all item types need this - leave blank if not applicable">

        <label for="status">Status</label>
        <select name="status" id="status" required>
            <?php foreach ($validStatuses as $statusOption): ?>
            <option value="<?php echo htmlspecialchars($statusOption); ?>" <?php echo $item['status'] === $statusOption ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($statusOption); ?>
            </option>
            <?php endforeach; ?>
        </select>

        <label for="created_on">Created On</label>
        <input type="date" name="created_on" id="created_on" value="<?php echo htmlspecialchars($item['created_on'] ?? ''); ?>">

        <label for="assigned_to">Assigned To</label>
        <select name="assigned_to" id="assigned_to">
            <option value="">Unassigned</option>
            <?php foreach ($users as $user): ?>
            <option value="<?php echo $user['id']; ?>" <?php echo (int) $item['assigned_to'] === (int) $user['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($user['full_name']); ?>
            </option>
            <?php endforeach; ?>
        </select>

        <div class="btn-group">
            <button type="submit">Save Changes</button>
            <a href="job.php?job_id=<?php echo $job_id; ?>" class="back-btn" onclick="history.back(); return false;">Cancel</a>
        </div>
    </form>

    <h3>Book Out / Book In</h3>
    <p class="handover-summary">
        <?php if (!empty($item['booked_out_at']) && empty($item['returned_at'])): ?>
        Currently booked out to <strong><?php echo htmlspecialchars($item['booked_out_to']); ?></strong>
        on <?php echo htmlspecialchars($item['booked_out_at']); ?>.
        <?php elseif (!empty($item['booked_out_at'])): ?>
        Last booked out to <strong><?php echo htmlspecialchars($item['booked_out_to']); ?></strong>
        on <?php echo htmlspecialchars($item['booked_out_at']); ?>,
        returned <?php echo htmlspecialchars($item['returned_at']); ?>.
        <?php else: ?>
        Not currently booked out.
        <?php endif; ?>
        <a href="view_case_item_history.php?item_id=<?php echo $item_id; ?>">View full history</a>
    </p>
    <div class="btn-group">
        <a class="back-btn" href="book_out_case_items.php?job_id=<?php echo $job_id; ?>">Book Out</a>
        <a class="back-btn" href="book_in_case_items.php?job_id=<?php echo $job_id; ?>">Book In</a>
    </div>

    <h3>Files</h3>
    <?php if (!empty($files)): ?>
    <table class="files-table">
        <tr>
            <th>Version</th>
            <th>Filename</th>
            <th>Explainer</th>
            <th>Uploaded By</th>
            <th>Uploaded At</th>
            <th></th>
        </tr>
        <?php foreach ($files as $i => $f): ?>
        <tr>
            <td><span class="version-badge">v<?php echo (int) $f['version']; ?><?php echo $i === 0 ? ' (current)' : ''; ?></span></td>
            <td><?php echo htmlspecialchars($f['original_filename']); ?></td>
            <td><?php echo nl2br(htmlspecialchars($f['explainer'] ?? '')); ?></td>
            <td><?php echo htmlspecialchars($f['uploaded_by_name'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($f['uploaded_at']); ?></td>
            <td><a href="download_case_item_file.php?file_id=<?php echo (int) $f['file_id']; ?>">Download</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php else: ?>
    <p class="handover-summary">No files uploaded yet.</p>
    <?php endif; ?>
    <form method="post" action="upload_case_item_file.php" enctype="multipart/form-data">
        <input type="hidden" name="item_id" value="<?php echo $item_id; ?>">
        <input type="hidden" name="job_id" value="<?php echo $job_id; ?>">
        <label for="item_file"><?php echo empty($files) ? 'Upload File' : 'Upload New Version'; ?></label>
        <input type="file" name="item_file" id="item_file" required>

        <label for="explainer">Explainer <?php echo empty($files) ? '(optional)' : '(what changed in this version?)'; ?></label>
        <textarea name="explainer" id="explainer" rows="3"></textarea>

        <div class="btn-group">
            <button type="submit">Upload</button>
        </div>
    </form>
</div>
