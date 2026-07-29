<?php
// manage_process_fields.php - which Metadata Pool fields belong to one
// process type, in what order, and whether each is required. Field
// definitions themselves (label, type, lookup/hash config, shared-value
// behaviour) live solely in the Metadata Pool (manage_metadata_fields.php)
// now - this page only manages the selection, so removing a field from a
// process is just deleting its selection row, always allowed, regardless
// of how many exhibits already have a recorded value for that pool field.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/audit.php';
require_once '../includes/process_lookups.php';
require_once '../includes/permissions.php';
require_permission($conn, 'manage_processes');

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

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_field'])) {
        $delete_id = intval($_POST['delete_field']);
        $stmt = $conn->prepare("SELECT mf.field_label FROM process_fields pf JOIN exhibit_metadata_fields mf ON mf.id = pf.metadata_field_id WHERE pf.id = ? AND pf.process_type_id = ?");
        $stmt->bind_param("ii", $delete_id, $process_type_id);
        $stmt->execute();
        $stmt->bind_result($removedLabel);
        $stmt->fetch();
        $stmt->close();

        $deleteStmt = $conn->prepare("DELETE FROM process_fields WHERE id = ? AND process_type_id = ?");
        $deleteStmt->bind_param("ii", $delete_id, $process_type_id);
        $deleteStmt->execute();
        $deleteStmt->close();
        log_audit_event($conn, 'process_field', $delete_id, 'DELETE', (int) $_SESSION['user_id'], json_encode(['field_label' => $removedLabel, 'process_type' => $ptName]));
        $message = "Field removed from this process.";
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
    } elseif (isset($_POST['toggle_required'])) {
        $field_id = intval($_POST['toggle_required']);
        $is_required = isset($_POST['is_required']) ? 1 : 0;
        $stmt = $conn->prepare("UPDATE process_fields SET is_required = ? WHERE id = ? AND process_type_id = ?");
        $stmt->bind_param("iii", $is_required, $field_id, $process_type_id);
        $stmt->execute();
        $stmt->close();
        log_audit_event($conn, 'process_field', $field_id, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['is_required' => (bool) $is_required, 'process_type' => $ptName]));
        $message = "Field updated.";
    } elseif (isset($_POST['add_field'])) {
        $metadata_field_id = intval($_POST['metadata_field_id'] ?? 0);
        $is_required = isset($_POST['is_required']) ? 1 : 0;

        $checkStmt = $conn->prepare("SELECT field_label FROM exhibit_metadata_fields WHERE id = ? AND is_active = 1");
        $checkStmt->bind_param("i", $metadata_field_id);
        $checkStmt->execute();
        $checkStmt->bind_result($fieldLabel);
        if (!$checkStmt->fetch()) {
            $message = "Please choose a field to add.";
            $checkStmt->close();
        } else {
            $checkStmt->close();
            $dupCheck = $conn->prepare("SELECT COUNT(*) FROM process_fields WHERE process_type_id = ? AND metadata_field_id = ?");
            $dupCheck->bind_param("ii", $process_type_id, $metadata_field_id);
            $dupCheck->execute();
            $dupCheck->bind_result($count);
            $dupCheck->fetch();
            $dupCheck->close();

            if ($count > 0) {
                $message = "That field is already on this process.";
            } else {
                $maxStmt = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM process_fields WHERE process_type_id = ?");
                $maxStmt->bind_param("i", $process_type_id);
                $maxStmt->execute();
                $maxStmt->bind_result($maxOrder);
                $maxStmt->fetch();
                $maxStmt->close();
                $nextOrder = $maxOrder + 1;

                $stmt = $conn->prepare("INSERT INTO process_fields (process_type_id, metadata_field_id, is_required, sort_order) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("iiii", $process_type_id, $metadata_field_id, $is_required, $nextOrder);
                $stmt->execute();
                $newId = $conn->insert_id;
                $stmt->close();
                log_audit_event($conn, 'process_field', $newId, 'CREATE', (int) $_SESSION['user_id'], json_encode(['field_label' => $fieldLabel, 'is_required' => (bool) $is_required, 'process_type' => $ptName]));
                $message = "Field added.";
            }
        }
    }
}

$fields = [];
$res = $conn->query("
    SELECT pf.id, pf.is_required, pf.sort_order,
           mf.id AS metadata_field_id, mf.field_label, mf.field_type, mf.hash_algorithm,
           mf.lookup_source, mf.lookup_asset_type_id, mf.is_shared_value,
           at.type_name AS lookup_asset_type_name,
           (SELECT COUNT(*) FROM exhibit_process_values v WHERE v.metadata_field_id = mf.id) AS usage_count
    FROM process_fields pf
    JOIN exhibit_metadata_fields mf ON mf.id = pf.metadata_field_id
    LEFT JOIN asset_types at ON at.id = mf.lookup_asset_type_id
    WHERE pf.process_type_id = $process_type_id
    ORDER BY pf.sort_order
");
while ($row = $res->fetch_assoc()) {
    $fields[] = $row;
}
$res->free();

$selectedIds = array_column($fields, 'metadata_field_id');

$availableFields = [];
$avRes = $conn->query("SELECT id, field_label, field_type, hash_algorithm, is_shared_value FROM exhibit_metadata_fields WHERE is_active = 1 ORDER BY sort_order");
while ($row = $avRes->fetch_assoc()) {
    if (!in_array((int) $row['id'], $selectedIds, true)) {
        $availableFields[] = $row;
    }
}
$avRes->free();

function describe_field_type(array $f): string
{
    $label = $f['field_type'] === 'date' ? 'Date/Time' : ($f['field_type'] === 'checkbox' ? 'Tick box' : ($f['field_type'] === 'textarea' ? 'Long text' : ucfirst($f['field_type'])));
    if ($f['field_type'] === 'hash' && !empty($f['hash_algorithm'])) {
        $label .= ' (' . $f['hash_algorithm'] . ')';
    }
    if ($f['field_type'] === 'lookup' && !empty($f['lookup_source']) && isset(PROCESS_FIELD_LOOKUP_SOURCES[$f['lookup_source']])) {
        $label .= ' (' . PROCESS_FIELD_LOOKUP_SOURCES[$f['lookup_source']]['label'];
        if (!empty($f['lookup_asset_type_name'])) {
            $label .= ': ' . $f['lookup_asset_type_name'];
        }
        $label .= ')';
    }
    return $label;
}
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
        max-width: 1400px;
        margin: 20px 20px 0 20px;
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
        box-sizing: border-box;
    }

    h2 {
        font-size: 24px;
        margin-bottom: 5px;
    }

    .subtitle {
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

    .add-field-row {
        display: flex;
        gap: 10px;
        align-items: flex-end;
        flex-wrap: wrap;
        max-width: 700px;
    }

    .add-field-row>div {
        flex: 1;
        min-width: 200px;
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

    .shared-badge {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--polaris-text-faint);
    }

    .muted {
        color: var(--polaris-text-faint);
        font-size: 13px;
    }
</style>

<div class="container">
    <h2><?php echo htmlspecialchars($ptName); ?></h2>
    <p class="subtitle">Fields on this process</p>

    <?php if (!empty($message)): ?>
    <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post" id="add_field_form"
        action="manage_process_fields.php?process_type_id=<?php echo $process_type_id; ?><?php echo $embedded ? '&embedded=1' : ''; ?>">
        <input type="hidden" name="add_field" value="1">
        <div class="add-field-row">
            <div>
                <label for="metadata_field_id">Add a field from the Metadata Pool</label>
                <select name="metadata_field_id" id="metadata_field_id">
                    <option value="">Select a field&hellip;</option>
                    <?php foreach ($availableFields as $af): ?>
                    <option value="<?php echo $af['id']; ?>">
                        <?php echo htmlspecialchars($af['field_label']); ?> - <?php echo htmlspecialchars(describe_field_type($af)); ?><?php echo $af['is_shared_value'] ? ' (Shared)' : ''; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($availableFields)): ?>
                <p class="muted" style="margin:0;">Every active Metadata Pool field is already on this process, or none
                    are defined yet - add one in
                    <a href="manage_metadata_fields.php<?php echo $embedded ? '?embedded=1' : ''; ?>">Metadata Pool</a>
                    first.</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="checkbox-row">
            <input type="checkbox" name="is_required" id="add_is_required" value="1">
            <label for="add_is_required" style="margin:0;">Required</label>
        </div>
        <button type="submit" class="action-btn btn-add">Add Field</button>
    </form>

    <table>
        <thead>
            <tr>
                <th>Label</th>
                <th>Type</th>
                <th>Shared?</th>
                <th>Required</th>
                <th>In Use</th>
                <th>Order</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($fields)): ?>
            <tr>
                <td colspan="7" style="color:var(--polaris-text-faint);">No fields on this process yet. Every process
                    also gets a free-text notes field automatically - only add fields here for anything you want
                    captured in a structured, always-in-the-same-place way.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($fields as $i => $f): ?>
            <tr>
                <td><?php echo htmlspecialchars($f['field_label']); ?></td>
                <td><?php echo htmlspecialchars(describe_field_type($f)); ?></td>
                <td><span class="shared-badge"><?php echo $f['is_shared_value'] ? 'Shared' : 'Per-process'; ?></span></td>
                <td>
                    <form method="post"
                        action="manage_process_fields.php?process_type_id=<?php echo $process_type_id; ?><?php echo $embedded ? '&embedded=1' : ''; ?>"
                        style="display:inline; margin:0;">
                        <input type="hidden" name="toggle_required" value="<?php echo $f['id']; ?>">
                        <label style="display:inline; margin:0;">
                            <input type="checkbox" name="is_required" value="1" onchange="this.form.submit()"
                                <?php echo $f['is_required'] ? 'checked' : ''; ?>>
                            <?php echo $f['is_required'] ? '<span class="required-yes">Required</span>' : 'Optional'; ?>
                        </label>
                    </form>
                </td>
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
                            onclick="return confirm('Remove this field from the process? It will stop appearing on new examinations, but nothing already recorded is affected.');">Remove</button>
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
