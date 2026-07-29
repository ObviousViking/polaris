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
require_once '../includes/process_lookups.php';
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
        $field_type = in_array($_POST['field_type'], ['text', 'textarea', 'number', 'date', 'checkbox', 'hash', 'lookup'], true) ? $_POST['field_type'] : 'text';
        $is_shared_value = isset($_POST['is_shared_value']) ? 1 : 0;

        $hash_algorithm = null;
        if ($field_type === 'hash' && in_array($_POST['hash_algorithm'] ?? '', ['MD5', 'SHA1', 'SHA256'], true)) {
            $hash_algorithm = $_POST['hash_algorithm'];
        }

        $lookup_source = $_POST['lookup_source'] ?? '';
        $lookup_source = ($field_type === 'lookup' && isset(PROCESS_FIELD_LOOKUP_SOURCES[$lookup_source])) ? $lookup_source : null;

        $lookup_asset_type_id = null;
        if ($lookup_source === 'assets' && !empty($_POST['lookup_asset_type_id'])) {
            $candidateId = intval($_POST['lookup_asset_type_id']);
            $atCheck = $conn->prepare("SELECT 1 FROM asset_types WHERE id = ?");
            $atCheck->bind_param("i", $candidateId);
            $atCheck->execute();
            $atCheck->store_result();
            if ($atCheck->num_rows > 0) {
                $lookup_asset_type_id = $candidateId;
            }
            $atCheck->close();
        }

        if ($field_label === '') {
            $message = "Field label cannot be empty.";
        } elseif ($field_type === 'hash' && $hash_algorithm === null) {
            $message = "Please choose a hash algorithm for the hash field.";
        } elseif ($field_type === 'lookup' && $lookup_source === null) {
            $message = "Please choose a source for the lookup field.";
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
                $stmt = $conn->prepare("UPDATE exhibit_metadata_fields SET field_label = ?, field_key = ?, field_type = ?, hash_algorithm = ?, lookup_source = ?, lookup_asset_type_id = ?, is_shared_value = ? WHERE id = ?");
                $stmt->bind_param("sssssiii", $field_label, $field_key, $field_type, $hash_algorithm, $lookup_source, $lookup_asset_type_id, $is_shared_value, $field_id);
                $stmt->execute();
                $stmt->close();
                log_audit_event($conn, 'metadata_field', $field_id, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['field_label' => $field_label, 'field_type' => $field_type, 'hash_algorithm' => $hash_algorithm, 'lookup_source' => $lookup_source, 'lookup_asset_type_id' => $lookup_asset_type_id, 'is_shared_value' => (bool) $is_shared_value]));
                $message = "Field updated. Existing recorded values are unaffected - only new entries follow the new type.";
            } else {
                $maxStmt = $conn->query("SELECT COALESCE(MAX(sort_order), 0) FROM exhibit_metadata_fields");
                $nextOrder = (int) $maxStmt->fetch_row()[0] + 1;

                $stmt = $conn->prepare("INSERT INTO exhibit_metadata_fields (field_label, field_key, field_type, hash_algorithm, lookup_source, lookup_asset_type_id, is_shared_value, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssiii", $field_label, $field_key, $field_type, $hash_algorithm, $lookup_source, $lookup_asset_type_id, $is_shared_value, $nextOrder);
                $stmt->execute();
                $newId = $conn->insert_id;
                $stmt->close();
                log_audit_event($conn, 'metadata_field', $newId, 'CREATE', (int) $_SESSION['user_id'], json_encode(['field_label' => $field_label, 'field_type' => $field_type, 'hash_algorithm' => $hash_algorithm, 'lookup_source' => $lookup_source, 'lookup_asset_type_id' => $lookup_asset_type_id, 'is_shared_value' => (bool) $is_shared_value]));
                $message = "Field added. Pick it from Process Builder \xe2\x80\x93 Fields to include it in a process.";
            }
        }
    }
}

$assetTypes = [];
$atRes = $conn->query("SELECT id, type_name FROM asset_types WHERE is_active = 1 ORDER BY type_name");
while ($row = $atRes->fetch_assoc()) {
    $assetTypes[] = $row;
}
$atRes->free();

$fields = [];
$res = $conn->query("
    SELECT mf.id, mf.field_label, mf.field_key, mf.field_type, mf.hash_algorithm,
           mf.lookup_source, mf.lookup_asset_type_id, mf.is_shared_value, mf.is_active, mf.sort_order,
           at.type_name AS lookup_asset_type_name,
           (SELECT COUNT(*) FROM exhibit_metadata_values v WHERE v.metadata_field_id = mf.id) AS exhibit_count,
           (SELECT COUNT(*) FROM process_fields pf WHERE pf.metadata_field_id = mf.id) AS process_field_count
    FROM exhibit_metadata_fields mf
    LEFT JOIN asset_types at ON at.id = mf.lookup_asset_type_id
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
    <p class="subtitle">The full catalog of data points a process can ask for - make, model, serial, IMEI, an
        acquisition hash, an examination date, whatever your lab records. Define each field once here, then pick it
        from the list in Process Builder - Fields when building a process; nothing gets defined inside a process
        directly any more. Mark a field "Shared" if every process should read/write the same current value for that
        exhibit (e.g. IMEI, make, model), or leave it unshared if each process instance should keep its own
        independent value (e.g. a hash or a timestamp that can legitimately differ between separate runs).</p>

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
                <select name="field_type" id="field_type" onchange="toggleTypeRows()">
                    <option value="text">Text</option>
                    <option value="textarea">Long text</option>
                    <option value="number">Number</option>
                    <option value="date">Date/Time</option>
                    <option value="checkbox">Tick box</option>
                    <option value="hash">Hash</option>
                    <option value="lookup">Lookup (dropdown from another list)</option>
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
            <div id="lookup_source_row" style="display:none;">
                <label for="lookup_source">Source</label>
                <select name="lookup_source" id="lookup_source" onchange="toggleAssetTypeRow()">
                    <?php foreach (PROCESS_FIELD_LOOKUP_SOURCES as $key => $src): ?>
                    <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($src['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="lookup_asset_type_row" style="display:none;">
                <label for="lookup_asset_type_id">Asset Type</label>
                <select name="lookup_asset_type_id" id="lookup_asset_type_id">
                    <option value="">Any type</option>
                    <?php foreach ($assetTypes as $at): ?>
                    <option value="<?php echo $at['id']; ?>"><?php echo htmlspecialchars($at['type_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="checkbox-row">
            <input type="checkbox" name="is_shared_value" id="is_shared_value" value="1" checked>
            <label for="is_shared_value" style="margin:0;">Shared value - one current value per exhibit, synced across every process that includes it</label>
        </div>
        <button type="submit" class="action-btn btn-add" id="field_submit_btn">Add Field</button>
        <button type="button" class="action-btn" id="field_cancel_btn" style="display:none;" onclick="resetFieldForm()">Cancel Edit</button>
    </form>

    <script>
    function toggleTypeRows() {
        var type = document.getElementById('field_type').value;
        document.getElementById('hash_algorithm_row').style.display = type === 'hash' ? '' : 'none';
        document.getElementById('lookup_source_row').style.display = type === 'lookup' ? '' : 'none';
        toggleAssetTypeRow();
    }

    function toggleAssetTypeRow() {
        var isLookup = document.getElementById('field_type').value === 'lookup';
        var isAssets = document.getElementById('lookup_source').value === 'assets';
        document.getElementById('lookup_asset_type_row').style.display = (isLookup && isAssets) ? '' : 'none';
    }

    function populateFieldForm(id, label, type, hashAlgorithm, lookupSource, lookupAssetTypeId, isSharedValue) {
        document.getElementById('field_id').value = id;
        document.getElementById('field_label').value = label;
        document.getElementById('field_type').value = type;
        document.getElementById('hash_algorithm').value = hashAlgorithm || 'MD5';
        document.getElementById('lookup_source').value = lookupSource || '';
        document.getElementById('lookup_asset_type_id').value = lookupAssetTypeId || '';
        document.getElementById('is_shared_value').checked = !!isSharedValue;
        toggleTypeRows();
        document.getElementById('field_submit_btn').textContent = 'Save Changes';
        document.getElementById('field_cancel_btn').style.display = '';
        document.getElementById('field_form').scrollIntoView({ behavior: 'smooth' });
    }

    function resetFieldForm() {
        document.getElementById('field_form').reset();
        document.getElementById('field_id').value = '';
        document.getElementById('field_submit_btn').textContent = 'Add Field';
        document.getElementById('field_cancel_btn').style.display = 'none';
        toggleTypeRows();
    }
    </script>

    <table>
        <thead>
            <tr>
                <th>Label</th>
                <th>Type</th>
                <th>Shared?</th>
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
                <td colspan="8" class="muted">No metadata fields defined yet.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($fields as $i => $f): ?>
            <tr class="<?php echo $f['is_active'] ? '' : 'inactive-row'; ?>">
                <td><?php echo htmlspecialchars($f['field_label']); ?></td>
                <td><?php
                    echo $f['field_type'] === 'date' ? 'Date/Time' : ($f['field_type'] === 'checkbox' ? 'Tick box' : ($f['field_type'] === 'textarea' ? 'Long text' : htmlspecialchars(ucfirst($f['field_type']))));
                    if ($f['field_type'] === 'hash' && $f['hash_algorithm']) {
                        echo ' (' . htmlspecialchars($f['hash_algorithm']) . ')';
                    }
                    if ($f['field_type'] === 'lookup' && $f['lookup_source'] && isset(PROCESS_FIELD_LOOKUP_SOURCES[$f['lookup_source']])) {
                        echo ' (' . htmlspecialchars(PROCESS_FIELD_LOOKUP_SOURCES[$f['lookup_source']]['label']);
                        if (!empty($f['lookup_asset_type_name'])) {
                            echo ': ' . htmlspecialchars($f['lookup_asset_type_name']);
                        }
                        echo ')';
                    }
                ?></td>
                <td><?php echo $f['is_shared_value'] ? 'Shared' : 'Per-process'; ?></td>
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
                        onclick="populateFieldForm(<?php echo $f['id']; ?>, '<?php echo htmlspecialchars($f['field_label'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($f['field_type'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($f['hash_algorithm'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($f['lookup_source'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars((string) ($f['lookup_asset_type_id'] ?? ''), ENT_QUOTES); ?>', <?php echo $f['is_shared_value'] ? 'true' : 'false'; ?>)">Edit</button>
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
