<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once '../includes/audit.php';
require_once '../includes/permissions.php';
require_permission($conn, 'manage_submission_questions');
require_once('../header.php');

$validTypes = ['text', 'textarea', 'number', 'date', 'checkbox'];
$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $label = trim($_POST['field_label']);
    $key = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($_POST['field_key'])));
    $type = in_array($_POST['field_type'] ?? '', $validTypes, true) ? $_POST['field_type'] : 'text';
    $required = isset($_POST['is_required']) ? 1 : 0;
    $sort = intval($_POST['sort_order'] ?? 0);
    $field_id = isset($_POST['field_id']) ? intval($_POST['field_id']) : 0;

    if ($label === '' || $key === '') {
        $message = "Label and key are required.";
    } elseif ($field_id > 0) {
        $stmt = $conn->prepare("UPDATE submission_question_fields SET field_label=?, field_type=?, is_required=?, sort_order=? WHERE field_id=?");
        $stmt->bind_param("ssiii", $label, $type, $required, $sort, $field_id);
        $stmt->execute();
        $stmt->close();
        log_audit_event($conn, 'submission_question_field', $field_id, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['label' => $label]));
        $message = "Question updated.";
    } else {
        $dup = $conn->prepare("SELECT COUNT(*) FROM submission_question_fields WHERE field_key = ?");
        $dup->bind_param("s", $key);
        $dup->execute();
        $dup->bind_result($count);
        $dup->fetch();
        $dup->close();
        if ($count > 0) {
            $message = "That key is already in use.";
        } else {
            $stmt = $conn->prepare("INSERT INTO submission_question_fields (field_label, field_key, field_type, is_required, sort_order) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssii", $label, $key, $type, $required, $sort);
            $stmt->execute();
            $newId = $conn->insert_id;
            $stmt->close();
            log_audit_event($conn, 'submission_question_field', $newId, 'CREATE', (int) $_SESSION['user_id'], json_encode(['label' => $label]));
            $message = "New question added.";
        }
    }
} elseif (isset($_GET['deactivate'])) {
    $id = intval($_GET['deactivate']);
    $conn->query("UPDATE submission_question_fields SET is_active = 0 WHERE field_id = " . $id);
    log_audit_event($conn, 'submission_question_field', $id, 'DEACTIVATE', (int) $_SESSION['user_id'], null);
    $message = "Question deactivated.";
} elseif (isset($_GET['reactivate'])) {
    $id = intval($_GET['reactivate']);
    $conn->query("UPDATE submission_question_fields SET is_active = 1 WHERE field_id = " . $id);
    log_audit_event($conn, 'submission_question_field', $id, 'REACTIVATE', (int) $_SESSION['user_id'], null);
    $message = "Question reactivated.";
}

$fields = [];
$res = $conn->query("SELECT * FROM submission_question_fields ORDER BY sort_order, field_label");
while ($row = $res->fetch_assoc()) { $fields[] = $row; }
?>
<style>
    body { margin: 0; font-family: Arial, sans-serif; background: var(--polaris-bg); color: var(--polaris-text); padding-top: 120px; }
    .container { max-width: 900px; margin: 20px auto; background: var(--polaris-surface); padding: 20px; border-radius: 5px; box-shadow: 0 2px 10px rgba(255,255,255,0.1); }
    h2 { text-align: center; margin-bottom: 20px; }
    p.hint { font-size: 13px; color: var(--polaris-text-dim); }
    input[type="text"], input[type="number"], select { width: 100%; padding: 8px; margin-bottom: 10px; background: var(--polaris-bg); border: 1px solid var(--polaris-border); color: var(--polaris-text); box-sizing: border-box; }
    table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    th, td { border: 1px solid var(--polaris-border); padding: 8px; text-align: left; }
    th { background: var(--polaris-divider); }
    .action-btn { background: var(--polaris-accent); color: var(--polaris-text); border: none; padding: 5px 10px; cursor: pointer; border-radius: 3px; text-decoration: none; display: inline-block; }
    .action-btn:hover { background: var(--polaris-accent-hover); }
    .message { margin-bottom: 10px; padding: 10px; background: var(--polaris-divider); border-left: 4px solid var(--polaris-accent); }
    .back-btn { display: inline-block; padding: 5px 10px; background: var(--polaris-accent); color: var(--polaris-text); border-radius: 3px; text-decoration: none; font-size: 14px; margin-bottom: 20px; }
</style>
<script>
function populateForm(id, label, type, required, sort) {
    document.getElementById("field_id").value = id;
    document.getElementById("field_label").value = label;
    document.getElementById("field_type").value = type;
    document.getElementById("is_required").checked = required == 1;
    document.getElementById("sort_order").value = sort;
    document.getElementById("field_key").disabled = true;
}
</script>
<div class="container">
    <h2>Manage Submission Questions</h2>
    <p class="hint">Extra questions shown on the case submission form, beyond the built-in fields.</p>
    <?php if (!empty($message)): ?>
    <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="field_id" id="field_id">
        <label>Question Label</label>
        <input type="text" name="field_label" id="field_label" required>
        <label>Key (used internally, letters/numbers/underscore only)</label>
        <input type="text" name="field_key" id="field_key" required>
        <label>Answer Type</label>
        <select name="field_type" id="field_type">
            <?php foreach ($validTypes as $t): ?>
            <option value="<?php echo $t; ?>"><?php echo ucfirst($t); ?></option>
            <?php endforeach; ?>
        </select>
        <label><input type="checkbox" name="is_required" id="is_required" value="1"> Required</label>
        <label>Sort Order</label>
        <input type="number" name="sort_order" id="sort_order" value="0">
        <button type="submit" class="action-btn">Save</button>
    </form>

    <table>
        <thead><tr><th>Label</th><th>Key</th><th>Type</th><th>Required</th><th>Order</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
            <?php foreach ($fields as $f): ?>
            <tr>
                <td><?php echo htmlspecialchars($f['field_label']); ?></td>
                <td><code><?php echo htmlspecialchars($f['field_key']); ?></code></td>
                <td><?php echo htmlspecialchars($f['field_type']); ?></td>
                <td><?php echo $f['is_required'] ? 'Yes' : 'No'; ?></td>
                <td><?php echo (int) $f['sort_order']; ?></td>
                <td><?php echo $f['is_active'] ? 'Active' : 'Inactive'; ?></td>
                <td>
                    <button class="action-btn" onclick="populateForm('<?php echo $f['field_id']; ?>', '<?php echo htmlspecialchars($f['field_label'], ENT_QUOTES); ?>', '<?php echo $f['field_type']; ?>', <?php echo $f['is_required']; ?>, <?php echo $f['sort_order']; ?>)">Edit</button>
                    <?php if ($f['is_active']): ?>
                    <a class="action-btn" href="?deactivate=<?php echo $f['field_id']; ?>">Deactivate</a>
                    <?php else: ?>
                    <a class="action-btn" href="?reactivate=<?php echo $f['field_id']; ?>">Reactivate</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <br>
    <a href="/cargo_hold/manage_system_details.php" class="back-btn">Go Back</a>
</div>
