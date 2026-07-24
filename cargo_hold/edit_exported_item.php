<?php
// edit_exported_item.php
//
// Mirrors edit_task.php's shape: same permission rule, same update pattern -
// but changes now land in exported_item_history (hash-chained, same as
// exhibit_history) rather than the generic audit_log, since these are
// case-evidential records, not admin activity.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/integrity.php';
require_once '../includes/permissions.php';

$item_id = isset($_GET['item_id']) ? intval($_GET['item_id']) : 0;

$stmt = $conn->prepare("SELECT * FROM exported_items WHERE item_id = ?");
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

function exported_item_exhibit_ref(array $exhibits, ?int $exhibitId): string
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

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'details') {
    $description = trim($_POST['description']);
    $status = in_array($_POST['status'] ?? '', $validStatuses, true) ? $_POST['status'] : $item['status'];
    $extracted_on = $_POST['extracted_on'] ?: null;
    $assigned_to = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;
    $source_exhibit_id = !empty($_POST['source_exhibit_id']) ? intval($_POST['source_exhibit_id']) : null;

    $changes = [];
    if ($description !== ($item['description'] ?? '')) {
        $changes['Description'] = ['old' => $item['description'], 'new' => $description];
    }
    if ($status !== $item['status']) {
        $changes['Status'] = ['old' => $item['status'], 'new' => $status];
    }
    if ($extracted_on !== $item['extracted_on']) {
        $changes['Extracted On'] = ['old' => $item['extracted_on'], 'new' => $extracted_on];
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
            'old' => exported_item_exhibit_ref($exhibits, (int) $item['source_exhibit_id'] ?: null),
            'new' => exported_item_exhibit_ref($exhibits, $source_exhibit_id),
        ];
    }

    $stmt = $conn->prepare("UPDATE exported_items SET description = ?, status = ?, extracted_on = ?, assigned_to = ?, source_exhibit_id = ? WHERE item_id = ?");
    $stmt->bind_param("sssiii", $description, $status, $extracted_on, $assigned_to, $source_exhibit_id, $item_id);
    if ($stmt->execute()) {
        if (!empty($changes)) {
            insert_history_row($conn, 'exported_item_history', $item_id, 'UPDATE', (int) $_SESSION['user_id'], json_encode($changes));
        }
        $message = "Exported item updated.";

        // Refresh for display below.
        $stmt2 = $conn->prepare("SELECT * FROM exported_items WHERE item_id = ?");
        $stmt2->bind_param("i", $item_id);
        $stmt2->execute();
        $item = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();
    } else {
        $message = "Error updating item: " . $stmt->error;
    }
    $stmt->close();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'handover') {
    $handedTo = trim($_POST['handed_to'] ?? '');
    $handoverDate = $_POST['handover_date'] ?: date('Y-m-d');

    if ($handedTo === '') {
        $message = "Please enter who the item was handed to.";
    } else {
        $handoverAt = $handoverDate . ' ' . date('H:i:s');
        $stmt = $conn->prepare("UPDATE exported_items SET last_handed_to = ?, last_handed_to_at = ? WHERE item_id = ?");
        $stmt->bind_param("ssi", $handedTo, $handoverAt, $item_id);
        if ($stmt->execute()) {
            insert_history_row($conn, 'exported_item_history', $item_id, 'HANDOVER', (int) $_SESSION['user_id'], json_encode([
                'handed_to' => $handedTo,
                'handover_date' => $handoverDate,
            ]));
            $message = "Handover recorded.";

            $stmt2 = $conn->prepare("SELECT * FROM exported_items WHERE item_id = ?");
            $stmt2->bind_param("i", $item_id);
            $stmt2->execute();
            $item = $stmt2->get_result()->fetch_assoc();
            $stmt2->close();
        } else {
            $message = "Error recording handover: " . $stmt->error;
        }
        $stmt->close();
    }
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
    select {
        width: 100%;
        padding: 8px;
        background: var(--polaris-bg);
        border: 1px solid var(--polaris-border);
        color: var(--polaris-text);
        border-radius: 4px;
        font-size: 14px;
        box-sizing: border-box;
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
</style>

<div class="container">
    <h2>Edit Exported Item: <?php echo htmlspecialchars($item['extraction_ref']); ?></h2>

    <?php if (!empty($message)): ?>
    <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="form" value="details">
        <label for="extraction_ref">Extraction Reference</label>
        <input type="text" id="extraction_ref" value="<?php echo htmlspecialchars($item['extraction_ref']); ?>" readonly>

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

        <label for="status">Status</label>
        <select name="status" id="status" required>
            <?php foreach ($validStatuses as $statusOption): ?>
            <option value="<?php echo htmlspecialchars($statusOption); ?>" <?php echo $item['status'] === $statusOption ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($statusOption); ?>
            </option>
            <?php endforeach; ?>
        </select>

        <label for="extracted_on">Extracted On</label>
        <input type="date" name="extracted_on" id="extracted_on" value="<?php echo htmlspecialchars($item['extracted_on'] ?? ''); ?>">

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

    <h3>Record Handover</h3>
    <p class="handover-summary">
        <?php if (!empty($item['last_handed_to'])): ?>
        Last handed to <strong><?php echo htmlspecialchars($item['last_handed_to']); ?></strong>
        on <?php echo htmlspecialchars($item['last_handed_to_at']); ?>.
        <?php else: ?>
        No handover recorded yet.
        <?php endif; ?>
        <a href="view_exported_item_history.php?item_id=<?php echo $item_id; ?>">View full history</a>
    </p>
    <form method="post">
        <input type="hidden" name="form" value="handover">
        <label for="handed_to">Handed To</label>
        <input type="text" name="handed_to" id="handed_to" placeholder="Name of person/team the item was given to" required>

        <label for="handover_date">Date</label>
        <input type="date" name="handover_date" id="handover_date" value="<?php echo date('Y-m-d'); ?>">

        <div class="btn-group">
            <button type="submit">Record Handover</button>
        </div>
    </form>
</div>
