<?php
// manage_process_fields.php - the fields belonging to one process type (see
// manage_processes.php). Each field becomes an input on
// captains_log/manage_exhibit_process.php when someone attaches this
// process to an exhibit.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/audit.php';
require_once '../includes/process_lookups.php';
require_once '../includes/deletion_reason.php';

$stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($role);
$stmt->fetch();
$stmt->close();

if ($role !== 'admin' && $role !== 'super') {
    header("Location: ../dashboard.php");
    exit();
}

$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    require_once '../header.php';
}

$process_type_id = isset($_GET['process_type_id']) ? intval($_GET['process_type_id']) : 0;

$ptStmt = $conn->prepare("SELECT id, name FROM process_types WHERE id = ?");
$ptStmt->bind_param("i", $process_type_id);
$ptStmt->execute();
$ptStmt->bind_result($ptId, $ptName);
if (!$ptStmt->fetch()) {
    die("Process type not found.");
}
$ptStmt->close();

function slugify_field_key(string $label): string
{
    $key = strtolower(trim($label));
    $key = preg_replace('/[^a-z0-9]+/', '_', $key);
    return trim($key, '_') ?: 'field';
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_field'])) {
        $delete_id = intval($_POST['delete_field']);

        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM exhibit_process_values WHERE process_field_id = ?");
        $checkStmt->bind_param("i", $delete_id);
        $checkStmt->execute();
        $checkStmt->bind_result($usageCount);
        $checkStmt->fetch();
        $checkStmt->close();

        if ($usageCount > 0) {
            $message = "Cannot delete - $usageCount recorded value(s) use this field.";
        } elseif (($deleteReason = require_deletion_reason_or_fail($conn)) === false) {
            $message = "A reason is required to delete this field.";
        } else {
            $nameStmt = $conn->prepare("SELECT field_label FROM process_fields WHERE id = ? AND process_type_id = ?");
            $nameStmt->bind_param("ii", $delete_id, $process_type_id);
            $nameStmt->execute();
            $nameStmt->bind_result($deletedLabel);
            $nameStmt->fetch();
            $nameStmt->close();

            $deleteStmt = $conn->prepare("DELETE FROM process_fields WHERE id = ? AND process_type_id = ?");
            $deleteStmt->bind_param("ii", $delete_id, $process_type_id);
            $deleteStmt->execute();
            $deleteStmt->close();
            log_audit_event($conn, 'process_field', $delete_id, 'DELETE', (int) $_SESSION['user_id'], json_encode(['field_label' => $deletedLabel, 'process_type' => $ptName, 'reason' => $deleteReason]));
            $message = "Field removed.";
        }
    } elseif (isset($_POST['move_field'])) {
        // Swap sort_order with the adjacent field in the requested direction.
        $field_id = intval($_POST['move_field']);
        $direction = $_POST['direction'] === 'up' ? 'up' : 'down';

        $curStmt = $conn->prepare("SELECT id, sort_order FROM process_fields WHERE id = ? AND process_type_id = ?");
        $curStmt->bind_param("ii", $field_id, $process_type_id);
        $curStmt->execute();
        $curStmt->bind_result($curId, $curOrder);
        if ($curStmt->fetch()) {
            $curStmt->close();
            $cmp = $direction === 'up' ? '<' : '>';
            $ord = $direction === 'up' ? 'DESC' : 'ASC';
            $adjStmt = $conn->prepare("SELECT id, sort_order FROM process_fields WHERE process_type_id = ? AND sort_order $cmp ? ORDER BY sort_order $ord LIMIT 1");
            $adjStmt->bind_param("ii", $process_type_id, $curOrder);
            $adjStmt->execute();
            $adjStmt->bind_result($adjId, $adjOrder);
            if ($adjStmt->fetch()) {
                $adjStmt->close();
                $swapStmt = $conn->prepare("UPDATE process_fields SET sort_order = ? WHERE id = ?");
                $swapStmt->bind_param("ii", $adjOrder, $curId);
                $swapStmt->execute();
                $swapStmt->bind_param("ii", $curOrder, $adjId);
                $swapStmt->execute();
                $swapStmt->close();
            } else {
                $adjStmt->close();
            }
        } else {
            $curStmt->close();
        }
    } else {
        $field_label = trim($_POST['field_label']);
        $field_type = in_array($_POST['field_type'], ['text', 'textarea', 'number', 'date', 'lookup']) ? $_POST['field_type'] : 'text';
        $lookup_source = $_POST['lookup_source'] ?? '';
        $lookup_source = ($field_type === 'lookup' && isset(PROCESS_FIELD_LOOKUP_SOURCES[$lookup_source])) ? $lookup_source : null;
        $is_required = isset($_POST['is_required']) ? 1 : 0;

        if ($field_label === '') {
            $message = "Field label cannot be empty.";
        } elseif ($field_type === 'lookup' && $lookup_source === null) {
            $message = "Please choose a source for the lookup field.";
        } else {
            $field_key = slugify_field_key($field_label);
            $dupCheck = $conn->prepare("SELECT COUNT(*) FROM process_fields WHERE process_type_id = ? AND field_key = ?");
            $dupCheck->bind_param("is", $process_type_id, $field_key);
            $dupCheck->execute();
            $dupCheck->bind_result($count);
            $dupCheck->fetch();
            $dupCheck->close();

            if ($count > 0) {
                $message = "A field with that name already exists on this process.";
            } else {
                $maxStmt = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM process_fields WHERE process_type_id = ?");
                $maxStmt->bind_param("i", $process_type_id);
                $maxStmt->execute();
                $maxStmt->bind_result($maxOrder);
                $maxStmt->fetch();
                $maxStmt->close();
                $nextOrder = $maxOrder + 1;

                $stmt = $conn->prepare("INSERT INTO process_fields (process_type_id, field_label, field_key, field_type, lookup_source, is_required, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("issssii", $process_type_id, $field_label, $field_key, $field_type, $lookup_source, $is_required, $nextOrder);
                $stmt->execute();
                $newId = $conn->insert_id;
                $stmt->close();
                log_audit_event($conn, 'process_field', $newId, 'CREATE', (int) $_SESSION['user_id'], json_encode(['field_label' => $field_label, 'field_type' => $field_type, 'lookup_source' => $lookup_source, 'is_required' => (bool) $is_required, 'process_type' => $ptName]));
                $message = "Field added.";
            }
        }
    }
}

$fields = [];
$res = $conn->query("
    SELECT pf.id, pf.field_label, pf.field_type, pf.lookup_source, pf.is_required, pf.sort_order,
           (SELECT COUNT(*) FROM exhibit_process_values v WHERE v.process_field_id = pf.id) AS usage_count
    FROM process_fields pf
    WHERE pf.process_type_id = $process_type_id
    ORDER BY pf.sort_order
");
while ($row = $res->fetch_assoc()) {
    $fields[] = $row;
}
$res->free();
?>
<style>
    body {
        margin: 0;
        font-family: Arial, sans-serif;
        background: var(--polaris-bg);
        color: var(--polaris-text);
        padding-top: <?php echo $embedded ? '0' : '120px'; ?>;
    }

    .container {
        max-width: 900px;
        margin: 20px auto;
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
        box-sizing: border-box;
    }

    h2 {
        text-align: center;
        font-size: 24px;
        margin-bottom: 5px;
    }

    .subtitle {
        text-align: center;
        color: var(--polaris-text-faint);
        margin-bottom: 20px;
    }

    label {
        display: block;
        margin: 10px 0 5px;
        color: var(--polaris-text-secondary);
    }

    input[type="text"],
    select {
        width: 100%;
        max-width: 100%;
        padding: 8px;
        margin-bottom: 10px;
        background: var(--polaris-bg);
        border: 1px solid var(--polaris-border);
        color: var(--polaris-text);
        box-sizing: border-box;
        border-radius: 4px;
    }

    .field-form-row {
        display: flex;
        gap: 10px;
        align-items: flex-end;
        flex-wrap: wrap;
    }

    .field-form-row>div {
        flex: 1;
        min-width: 150px;
    }

    .checkbox-row {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 10px;
    }

    .checkbox-row input {
        width: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
    }

    th,
    td {
        border: 1px solid var(--polaris-border);
        padding: 8px;
        text-align: left;
    }

    th {
        background: var(--polaris-divider);
    }

    .action-btn {
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border: none;
        padding: 5px 10px;
        cursor: pointer;
        border-radius: 3px;
        text-decoration: none;
        display: inline-block;
        transition: background 0.3s ease;
        font-size: 13px;
    }

    .action-btn:hover {
        background: var(--polaris-accent-hover);
    }

    .btn-add {
        background: var(--polaris-success-strong);
    }

    .btn-add:hover {
        background: var(--polaris-success-strong-hover);
    }

    .delete-btn {
        background: var(--polaris-error-bg);
        color: var(--polaris-text);
    }

    .delete-btn:hover {
        background: var(--polaris-danger);
    }

    .message {
        margin-bottom: 10px;
        padding: 10px;
        background: var(--polaris-divider);
        border-left: 4px solid var(--polaris-accent);
    }

    .back-btn {
        display: inline-block;
        padding: 5px 10px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border: none;
        border-radius: 3px;
        text-decoration: none;
        text-align: center;
        font-size: 14px;
        cursor: pointer;
        transition: background 0.3s ease;
        margin-bottom: 20px;
    }

    .back-btn:hover {
        background: var(--polaris-accent-hover);
    }

    .required-yes {
        color: var(--polaris-warning);
        font-weight: bold;
    }
</style>

<div class="container">
    <h2><?php echo htmlspecialchars($ptName); ?></h2>
    <p class="subtitle">Manage Fields</p>

    <?php if (!empty($message)): ?>
    <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post"
        action="manage_process_fields.php?process_type_id=<?php echo $process_type_id; ?><?php echo $embedded ? '&embedded=1' : ''; ?>">
        <div class="field-form-row">
            <div>
                <label for="field_label">Field Label</label>
                <input type="text" name="field_label" id="field_label" placeholder="e.g. IMEI 1">
            </div>
            <div>
                <label for="field_type">Type</label>
                <select name="field_type" id="field_type" onchange="toggleLookupSource()">
                    <option value="text">Text</option>
                    <option value="textarea">Long text</option>
                    <option value="number">Number</option>
                    <option value="date">Date</option>
                    <option value="lookup">Lookup (dropdown from another list)</option>
                </select>
            </div>
            <div id="lookup_source_row" style="display:none;">
                <label for="lookup_source">Source</label>
                <select name="lookup_source" id="lookup_source">
                    <?php foreach (PROCESS_FIELD_LOOKUP_SOURCES as $key => $src): ?>
                    <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($src['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="checkbox-row">
            <input type="checkbox" name="is_required" id="is_required" value="1">
            <label for="is_required" style="margin:0;">Required</label>
        </div>
        <button type="submit" class="action-btn btn-add">Add Field</button>
    </form>

    <script>
    function toggleLookupSource() {
        var isLookup = document.getElementById('field_type').value === 'lookup';
        document.getElementById('lookup_source_row').style.display = isLookup ? '' : 'none';
    }
    </script>

    <table>
        <thead>
            <tr>
                <th>Label</th>
                <th>Type</th>
                <th>Required</th>
                <th>In Use</th>
                <th>Order</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($fields)): ?>
            <tr>
                <td colspan="6" style="color:var(--polaris-text-faint);">No fields defined yet. Every process also gets a free-text
                    notes field automatically - only add fields here for anything you want captured in a
                    structured, always-in-the-same-place way.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($fields as $i => $f): ?>
            <tr>
                <td><?php echo htmlspecialchars($f['field_label']); ?></td>
                <td><?php
                    echo htmlspecialchars(ucfirst($f['field_type']));
                    if ($f['field_type'] === 'lookup' && $f['lookup_source'] && isset(PROCESS_FIELD_LOOKUP_SOURCES[$f['lookup_source']])) {
                        echo ' (' . htmlspecialchars(PROCESS_FIELD_LOOKUP_SOURCES[$f['lookup_source']]['label']) . ')';
                    }
                ?></td>
                <td><?php echo $f['is_required'] ? '<span class="required-yes">Required</span>' : 'Optional'; ?></td>
                <td><?php echo (int) $f['usage_count']; ?></td>
                <td>
                    <form method="post"
                        action="manage_process_fields.php?process_type_id=<?php echo $process_type_id; ?><?php echo $embedded ? '&embedded=1' : ''; ?>"
                        style="display:inline;">
                        <input type="hidden" name="move_field" value="<?php echo $f['id']; ?>">
                        <input type="hidden" name="direction" value="up">
                        <button type="submit" class="action-btn" <?php echo $i === 0 ? 'disabled' : ''; ?>>&uarr;</button>
                    </form>
                    <form method="post"
                        action="manage_process_fields.php?process_type_id=<?php echo $process_type_id; ?><?php echo $embedded ? '&embedded=1' : ''; ?>"
                        style="display:inline;">
                        <input type="hidden" name="move_field" value="<?php echo $f['id']; ?>">
                        <input type="hidden" name="direction" value="down">
                        <button type="submit" class="action-btn"
                            <?php echo $i === count($fields) - 1 ? 'disabled' : ''; ?>>&darr;</button>
                    </form>
                </td>
                <td>
                    <form method="post"
                        action="manage_process_fields.php?process_type_id=<?php echo $process_type_id; ?><?php echo $embedded ? '&embedded=1' : ''; ?>"
                        style="display:inline;">
                        <input type="hidden" name="delete_field" value="<?php echo $f['id']; ?>">
                        <button type="submit" class="action-btn delete-btn"
                            onclick="return confirmDeleteWithReason(this.form, 'Remove this field? Only possible if no exhibit has a value recorded for it.')">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <br>
    <?php if (!$embedded): ?>
    <a href="manage_processes.php" class="back-btn">&larr; Back to Process Builder</a>
    <?php endif; ?>
</div>

</body>

</html>
