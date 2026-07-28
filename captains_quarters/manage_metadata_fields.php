<?php
// manage_metadata_fields.php - the "metadata pool": exhibit-identity
// attributes (make, model, serial, IMEI...) that are shared across every
// process rather than owned by one. Process Builder fields can bind to one
// of these (field_type = 'metapool') - see captains_log/manage_exhibit_process.php
// for how a save writes through to exhibit_metadata_values/_history.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/audit.php';
require_once '../includes/permissions.php';
require_permission($conn, 'manage_metadata_pool');

$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    require_once '../header.php';
}

function slugify_metadata_key(string $label): string
{
    $key = strtolower(trim($label));
    $key = preg_replace('/[^a-z0-9]+/', '_', $key);
    return trim($key, '_') ?: 'field';
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reactivate_field'])) {
        $reactivate_id = intval($_POST['reactivate_field']);
        $stmt = $conn->prepare("UPDATE exhibit_metadata_fields SET is_active = 1 WHERE id = ?");
        $stmt->bind_param("i", $reactivate_id);
        $stmt->execute();
        $stmt->close();
        log_audit_event($conn, 'metadata_field', $reactivate_id, 'REACTIVATE', (int) $_SESSION['user_id']);
        $message = "Field reactivated.";
    } elseif (isset($_POST['deactivate_field'])) {
        $deactivate_id = intval($_POST['deactivate_field']);
        $stmt = $conn->prepare("UPDATE exhibit_metadata_fields SET is_active = 0 WHERE id = ?");
        $stmt->bind_param("i", $deactivate_id);
        $stmt->execute();
        $stmt->close();
        log_audit_event($conn, 'metadata_field', $deactivate_id, 'DEACTIVATE', (int) $_SESSION['user_id']);
        $message = "Field deactivated - it won't appear as an option in Process Builder any more, but exhibits that already have a value for it keep it, and it can be reactivated later.";
    } elseif (isset($_POST['move_field'])) {
        $field_id = intval($_POST['move_field']);
        $direction = $_POST['direction'] === 'up' ? 'up' : 'down';

        $curStmt = $conn->prepare("SELECT sort_order FROM exhibit_metadata_fields WHERE id = ?");
        $curStmt->bind_param("i", $field_id);
        $curStmt->execute();
        $curStmt->bind_result($curOrder);
        if ($curStmt->fetch()) {
            $curStmt->close();
            $cmp = $direction === 'up' ? '<' : '>';
            $ord = $direction === 'up' ? 'DESC' : 'ASC';
            $adjStmt = $conn->prepare("SELECT id, sort_order FROM exhibit_metadata_fields WHERE sort_order $cmp ? ORDER BY sort_order $ord LIMIT 1");
            $adjStmt->bind_param("i", $curOrder);
            $adjStmt->execute();
            $adjStmt->bind_result($adjId, $adjOrder);
            if ($adjStmt->fetch()) {
                $adjStmt->close();
                $swapStmt = $conn->prepare("UPDATE exhibit_metadata_fields SET sort_order = ? WHERE id = ?");
                $swapStmt->bind_param("ii", $adjOrder, $field_id);
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
        $field_id = isset($_POST['field_id']) ? intval($_POST['field_id']) : 0;
        $field_label = trim($_POST['field_label']);
        $field_type = in_array($_POST['field_type'], ['text', 'number', 'date', 'checkbox', 'hash'], true) ? $_POST['field_type'] : 'text';

        $hash_algorithm = null;
        if ($field_type === 'hash' && in_array($_POST['hash_algorithm'] ?? '', ['MD5', 'SHA1', 'SHA256'], true)) {
            $hash_algorithm = $_POST['hash_algorithm'];
        }

        if ($field_label === '') {
            $message = "Field label cannot be empty.";
        } elseif ($field_type === 'hash' && $hash_algorithm === null) {
            $message = "Please choose a hash algorithm for the hash field.";
        } else {
            $field_key = slugify_metadata_key($field_label);
            $dupCheck = $conn->prepare("SELECT COUNT(*) FROM exhibit_metadata_fields WHERE field_key = ? AND id != ?");
            $dupCheck->bind_param("si", $field_key, $field_id);
            $dupCheck->execute();
            $dupCheck->bind_result($count);
            $dupCheck->fetch();
            $dupCheck->close();

            if ($count > 0) {
                $message = "A metadata field with that name already exists.";
            } elseif ($field_id > 0) {
                $stmt = $conn->prepare("UPDATE exhibit_metadata_fields SET field_label = ?, field_key = ?, field_type = ?, hash_algorithm = ? WHERE id = ?");
                $stmt->bind_param("ssssi", $field_label, $field_key, $field_type, $hash_algorithm, $field_id);
                $stmt->execute();
                $stmt->close();
                log_audit_event($conn, 'metadata_field', $field_id, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['field_label' => $field_label, 'field_type' => $field_type, 'hash_algorithm' => $hash_algorithm]));
                $message = "Field updated. Existing recorded values are unaffected - only new entries follow the new type.";
            } else {
                $maxStmt = $conn->query("SELECT COALESCE(MAX(sort_order), 0) FROM exhibit_metadata_fields");
                $nextOrder = (int) $maxStmt->fetch_row()[0] + 1;

                $stmt = $conn->prepare("INSERT INTO exhibit_metadata_fields (field_label, field_key, field_type, hash_algorithm, sort_order) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssi", $field_label, $field_key, $field_type, $hash_algorithm, $nextOrder);
                $stmt->execute();
                $newId = $conn->insert_id;
                $stmt->close();
                log_audit_event($conn, 'metadata_field', $newId, 'CREATE', (int) $_SESSION['user_id'], json_encode(['field_label' => $field_label, 'field_type' => $field_type, 'hash_algorithm' => $hash_algorithm]));
                $message = "Field added. Pick it from the \"Metadata Pool\" type when adding a field to a process.";
            }
        }
    }
}

$fields = [];
$res = $conn->query("
    SELECT mf.id, mf.field_label, mf.field_key, mf.field_type, mf.hash_algorithm, mf.is_active, mf.sort_order,
           (SELECT COUNT(*) FROM exhibit_metadata_values v WHERE v.metadata_field_id = mf.id) AS exhibit_count,
           (SELECT COUNT(*) FROM process_fields pf WHERE pf.metadata_field_id = mf.id) AS process_field_count
    FROM exhibit_metadata_fields mf
    ORDER BY mf.sort_order
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

    .field-form-row {
        display: flex;
        gap: 10px;
        align-items: flex-end;
        flex-wrap: wrap;
        max-width: 600px;
    }

    .field-form-row>div {
        flex: 1;
        min-width: 150px;
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

    .inactive-row {
        opacity: 0.55;
    }

    .muted {
        color: var(--polaris-text-faint);
        font-size: 13px;
    }
</style>

<div class="container">
    <h2>Metadata Pool</h2>
    <p class="subtitle">Shared exhibit attributes - make, model, serial, IMEI, and anything else that describes the
        exhibit itself rather than one process. Add a field here, then pick it from the "Metadata Pool" type when
        adding a field to a process in Process Builder. Every process that includes it reads and writes the same
        value per exhibit, with its own change history.</p>

    <?php if (!empty($message)): ?>
    <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post" id="field_form"
        action="manage_metadata_fields.php<?php echo $embedded ? '?embedded=1' : ''; ?>">
        <input type="hidden" name="field_id" id="field_id" value="">
        <div class="field-form-row">
            <div>
                <label for="field_label">Field Label</label>
                <input type="text" name="field_label" id="field_label" placeholder="e.g. Serial Number">
            </div>
            <div>
                <label for="field_type">Type</label>
                <select name="field_type" id="field_type" onchange="toggleHashRow()">
                    <option value="text">Text</option>
                    <option value="number">Number</option>
                    <option value="date">Date/Time</option>
                    <option value="checkbox">Tick box</option>
                    <option value="hash">Hash</option>
                </select>
            </div>
            <div id="hash_algorithm_row" style="display:none;">
                <label for="hash_algorithm">Hash Algorithm</label>
                <select name="hash_algorithm" id="hash_algorithm">
                    <option value="MD5">MD5</option>
                    <option value="SHA1">SHA1</option>
                    <option value="SHA256">SHA256</option>
                </select>
            </div>
        </div>
        <button type="submit" class="action-btn btn-add" id="field_submit_btn">Add Field</button>
        <button type="button" class="action-btn" id="field_cancel_btn" style="display:none;" onclick="resetFieldForm()">Cancel Edit</button>
    </form>

    <script>
    function toggleHashRow() {
        var isHash = document.getElementById('field_type').value === 'hash';
        document.getElementById('hash_algorithm_row').style.display = isHash ? '' : 'none';
    }

    function populateFieldForm(id, label, type, hashAlgorithm) {
        document.getElementById('field_id').value = id;
        document.getElementById('field_label').value = label;
        document.getElementById('field_type').value = type;
        document.getElementById('hash_algorithm').value = hashAlgorithm || 'MD5';
        toggleHashRow();
        document.getElementById('field_submit_btn').textContent = 'Save Changes';
        document.getElementById('field_cancel_btn').style.display = '';
        document.getElementById('field_form').scrollIntoView({ behavior: 'smooth' });
    }

    function resetFieldForm() {
        document.getElementById('field_form').reset();
        document.getElementById('field_id').value = '';
        document.getElementById('field_submit_btn').textContent = 'Add Field';
        document.getElementById('field_cancel_btn').style.display = 'none';
        toggleHashRow();
    }
    </script>

    <table>
        <thead>
            <tr>
                <th>Label</th>
                <th>Type</th>
                <th>Used By</th>
                <th>On Exhibits</th>
                <th>Status</th>
                <th>Order</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($fields)): ?>
            <tr>
                <td colspan="7" class="muted">No metadata fields defined yet.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($fields as $i => $f): ?>
            <tr class="<?php echo $f['is_active'] ? '' : 'inactive-row'; ?>">
                <td><?php echo htmlspecialchars($f['field_label']); ?></td>
                <td><?php
                    echo $f['field_type'] === 'date' ? 'Date/Time' : ($f['field_type'] === 'checkbox' ? 'Tick box' : htmlspecialchars(ucfirst($f['field_type'])));
                    if ($f['field_type'] === 'hash' && $f['hash_algorithm']) {
                        echo ' (' . htmlspecialchars($f['hash_algorithm']) . ')';
                    }
                ?></td>
                <td><?php echo (int) $f['process_field_count']; ?> process field(s)</td>
                <td><?php echo (int) $f['exhibit_count']; ?> exhibit(s)</td>
                <td><?php echo $f['is_active'] ? 'Active' : 'Inactive'; ?></td>
                <td>
                    <form method="post" action="manage_metadata_fields.php<?php echo $embedded ? '?embedded=1' : ''; ?>" style="display:inline;">
                        <input type="hidden" name="move_field" value="<?php echo $f['id']; ?>">
                        <input type="hidden" name="direction" value="up">
                        <button type="submit" class="action-btn" <?php echo $i === 0 ? 'disabled' : ''; ?>>&uarr;</button>
                    </form>
                    <form method="post" action="manage_metadata_fields.php<?php echo $embedded ? '?embedded=1' : ''; ?>" style="display:inline;">
                        <input type="hidden" name="move_field" value="<?php echo $f['id']; ?>">
                        <input type="hidden" name="direction" value="down">
                        <button type="submit" class="action-btn" <?php echo $i === count($fields) - 1 ? 'disabled' : ''; ?>>&darr;</button>
                    </form>
                </td>
                <td>
                    <button type="button" class="action-btn"
                        onclick="populateFieldForm(<?php echo $f['id']; ?>, '<?php echo htmlspecialchars($f['field_label'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($f['field_type'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($f['hash_algorithm'] ?? '', ENT_QUOTES); ?>')">Edit</button>
                    <?php if ($f['is_active']): ?>
                    <form method="post" action="manage_metadata_fields.php<?php echo $embedded ? '?embedded=1' : ''; ?>" style="display:inline;">
                        <input type="hidden" name="deactivate_field" value="<?php echo $f['id']; ?>">
                        <button type="submit" class="action-btn delete-btn"
                            onclick="return confirm('Deactivate this field? It will stop appearing as an option in Process Builder. Exhibits that already have a value keep it.');">Deactivate</button>
                    </form>
                    <?php else: ?>
                    <form method="post" action="manage_metadata_fields.php<?php echo $embedded ? '?embedded=1' : ''; ?>" style="display:inline;">
                        <input type="hidden" name="reactivate_field" value="<?php echo $f['id']; ?>">
                        <button type="submit" class="action-btn">Reactivate</button>
                    </form>
                    <?php endif; ?>
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
