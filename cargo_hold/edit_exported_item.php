<?php
// edit_exported_item.php
//
// Mirrors edit_task.php's shape: same permission rule, same audit-logged update pattern.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/audit.php';
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

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $description = trim($_POST['description']);
    $status = in_array($_POST['status'] ?? '', $validStatuses, true) ? $_POST['status'] : $item['status'];
    $extracted_on = $_POST['extracted_on'] ?: null;
    $assigned_to = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;

    $changes = [];
    if ($description !== ($item['description'] ?? '')) {
        $changes['description'] = ['from' => $item['description'], 'to' => $description];
    }
    if ($status !== $item['status']) {
        $changes['status'] = ['from' => $item['status'], 'to' => $status];
    }
    if ($extracted_on !== $item['extracted_on']) {
        $changes['extracted_on'] = ['from' => $item['extracted_on'], 'to' => $extracted_on];
    }
    if ($assigned_to !== (int) $item['assigned_to']) {
        $changes['assigned_to'] = ['from' => (int) $item['assigned_to'], 'to' => $assigned_to];
    }

    $stmt = $conn->prepare("UPDATE exported_items SET description = ?, status = ?, extracted_on = ?, assigned_to = ? WHERE item_id = ?");
    $stmt->bind_param("sssii", $description, $status, $extracted_on, $assigned_to, $item_id);
    if ($stmt->execute()) {
        if (!empty($changes)) {
            log_audit_event($conn, 'exported_item', $item_id, 'UPDATE', (int) $_SESSION['user_id'], json_encode($changes));
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
</style>

<div class="container">
    <h2>Edit Exported Item: <?php echo htmlspecialchars($item['extraction_ref']); ?></h2>

    <?php if (!empty($message)): ?>
    <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post">
        <label for="extraction_ref">Extraction Reference</label>
        <input type="text" id="extraction_ref" value="<?php echo htmlspecialchars($item['extraction_ref']); ?>" readonly>

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
</div>
