<?php
// manage_process_fields.php - the fields belonging to one process type.
// Each field becomes an input on captains_log/manage_exhibit_process.php.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/audit.php';
require_once '../includes/process_lookups.php';
require_once '../includes/deletion_reason.php';
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
        $field_id = isset($_POST['field_id']) ? intval($_POST['field_id']) : 0;
        $field_label = trim($_POST['field_label']);
        $field_type = in_array($_POST['field_type'], ['text', 'textarea', 'number', 'date', 'lookup', 'checkbox', 'hash', 'metapool']) ? $_POST['field_type'] : 'text';
        $lookup_source = $_POST['lookup_source'] ?? '';
        $lookup_source = ($field_type === 'lookup' && isset(PROCESS_FIELD_LOOKUP_SOURCES[$lookup_source])) ? $lookup_source : null;
        $is_required = isset($_POST['is_required']) ? 1 : 0;

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

        $hash_algorithm = null;
        if ($field_type === 'hash' && in_array($_POST['hash_algorithm'] ?? '', ['MD5', 'SHA1', 'SHA256'], true)) {
            $hash_algorithm = $_POST['hash_algorithm'];
        }

        $metadata_field_id = null;
        if ($field_type === 'metapool' && !empty($_POST['metadata_field_id'])) {
            $candidateId = intval($_POST['metadata_field_id']);
            $mfCheck = $conn->prepare("SELECT 1 FROM exhibit_metadata_fields WHERE id = ? AND is_active = 1");
            $mfCheck->bind_param("i", $candidateId);
            $mfCheck->execute();
            $mfCheck->store_result();
            if ($mfCheck->num_rows > 0) {
                $metadata_field_id = $candidateId;
            }
            $mfCheck->close();
        }

        if ($field_label === '') {
            $message = "Field label cannot be empty.";
        } elseif ($field_type === 'lookup' && $lookup_source === null) {
            $message = "Please choose a source for the lookup field.";
        } elseif ($field_type === 'hash' && $hash_algorithm === null) {
            $message = "Please choose a hash algorithm for the hash field.";
        } elseif ($field_type === 'metapool' && $metadata_field_id === null) {
            $message = "Please choose a metadata pool field.";
        } else {
            $field_key = slugify_field_key($field_label);
            $dupCheck = $conn->prepare("SELECT COUNT(*) FROM process_fields WHERE process_type_id = ? AND field_key = ? AND id != ?");
            $dupCheck->bind_param("isi", $process_type_id, $field_key, $field_id);
            $dupCheck->execute();
            $dupCheck->bind_result($count);
            $dupCheck->fetch();
            $dupCheck->close();

            if ($count > 0) {
                $message = "A field with that name already exists on this process.";
            } elseif ($field_id > 0) {
                $stmt = $conn->prepare("UPDATE process_fields SET field_label = ?, field_key = ?, field_type = ?, lookup_source = ?, lookup_asset_type_id = ?, hash_algorithm = ?, metadata_field_id = ?, is_required = ? WHERE id = ? AND process_type_id = ?");
                $stmt->bind_param("ssssisiiii", $field_label, $field_key, $field_type, $lookup_source, $lookup_asset_type_id, $hash_algorithm, $metadata_field_id, $is_required, $field_id, $process_type_id);
                $stmt->execute();
                $stmt->close();
                log_audit_event($conn, 'process_field', $field_id, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['field_label' => $field_label, 'field_type' => $field_type, 'lookup_source' => $lookup_source, 'lookup_asset_type_id' => $lookup_asset_type_id, 'hash_algorithm' => $hash_algorithm, 'metadata_field_id' => $metadata_field_id, 'is_required' => (bool) $is_required, 'process_type' => $ptName]));
                $message = "Field updated.";
            } else {
                $maxStmt = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM process_fields WHERE process_type_id = ?");
                $maxStmt->bind_param("i", $process_type_id);
                $maxStmt->execute();
                $maxStmt->bind_result($maxOrder);
                $maxStmt->fetch();
                $maxStmt->close();
                $nextOrder = $maxOrder + 1;

                $stmt = $conn->prepare("INSERT INTO process_fields (process_type_id, field_label, field_key, field_type, lookup_source, lookup_asset_type_id, hash_algorithm, metadata_field_id, is_required, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("issssisiii", $process_type_id, $field_label, $field_key, $field_type, $lookup_source, $lookup_asset_type_id, $hash_algorithm, $metadata_field_id, $is_required, $nextOrder);
                $stmt->execute();
                $newId = $conn->insert_id;
                $stmt->close();
                log_audit_event($conn, 'process_field', $newId, 'CREATE', (int) $_SESSION['user_id'], json_encode(['field_label' => $field_label, 'field_type' => $field_type, 'lookup_source' => $lookup_source, 'lookup_asset_type_id' => $lookup_asset_type_id, 'hash_algorithm' => $hash_algorithm, 'metadata_field_id' => $metadata_field_id, 'is_required' => (bool) $is_required, 'process_type' => $ptName]));
                $message = "Field added.";
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

$metadataFields = [];
$mfRes = $conn->query("SELECT id, field_label FROM exhibit_metadata_fields WHERE is_active = 1 ORDER BY sort_order");
while ($row = $mfRes->fetch_assoc()) {
    $metadataFields[] = $row;
}
$mfRes->free();

$fields = [];
$res = $conn->query("
    SELECT pf.id, pf.field_label, pf.field_type, pf.lookup_source, pf.lookup_asset_type_id, pf.hash_algorithm, pf.metadata_field_id, pf.is_required, pf.sort_order,
           at.type_name AS lookup_asset_type_name,
           mf.field_label AS metadata_field_label,
           (SELECT COUNT(*) FROM exhibit_process_values v WHERE v.process_field_id = pf.id) AS usage_count
    FROM process_fields pf
    LEFT JOIN asset_types at ON at.id = pf.lookup_asset_type_id
    LEFT JOIN exhibit_metadata_fields mf ON mf.id = pf.metadata_field_id
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
        max-width: 700px;
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

    .muted {
        color: var(--polaris-text-faint);
        font-size: 13px;
    }
</style>

<div class="container">
    <h2><?php echo htmlspecialchars($ptName); ?></h2>
    <p class="subtitle">Manage Fields</p>

    <?php if (!empty($message)): ?>
    <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post" id="field_form"
        action="manage_process_fields.php?process_type_id=<?php echo $process_type_id; ?><?php echo $embedded ? '&embedded=1' : ''; ?>">
        <input type="hidden" name="field_id" id="field_id" value="">
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
                    <option value="date">Date/Time</option>
                    <option value="checkbox">Tick box</option>
                    <option value="hash">Hash</option>
                    <option value="lookup">Lookup (dropdown from another list)</option>
                    <option value="metapool">Metadata Pool (shared exhibit attribute)</option>
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
            <div id="hash_algorithm_row" style="display:none;">
                <label for="hash_algorithm">Hash Algorithm</label>
                <select name="hash_algorithm" id="hash_algorithm">
                    <option value="MD5">MD5</option>
                    <option value="SHA1">SHA1</option>
                    <option value="SHA256">SHA256</option>
                </select>
            </div>
            <div id="metadata_field_row" style="display:none;">
                <label for="metadata_field_id">Metadata Field</label>
                <select name="metadata_field_id" id="metadata_field_id" onchange="applyMetadataFieldLabel()">
                    <option value="">Select&hellip;</option>
                    <?php foreach ($metadataFields as $mf): ?>
                    <option value="<?php echo $mf['id']; ?>"><?php echo htmlspecialchars($mf['field_label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($metadataFields)): ?>
                <p class="muted" style="margin:0;">No metadata pool fields defined yet - add some in
                    <a href="manage_metadata_fields.php<?php echo $embedded ? '?embedded=1' : ''; ?>">Metadata Pool</a> first.</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="checkbox-row">
            <input type="checkbox" name="is_required" id="is_required" value="1">
            <label for="is_required" style="margin:0;">Required</label>
        </div>
        <button type="submit" class="action-btn btn-add" id="field_submit_btn">Add Field</button>
        <button type="button" class="action-btn" id="field_cancel_btn" style="display:none;" onclick="resetFieldForm()">Cancel Edit</button>
    </form>

    <script>
    function toggleLookupSource() {
        var type = document.getElementById('field_type').value;
        var isLookup = type === 'lookup';
        var isHash = type === 'hash';
        var isMetapool = type === 'metapool';
        document.getElementById('lookup_source_row').style.display = isLookup ? '' : 'none';
        document.getElementById('hash_algorithm_row').style.display = isHash ? '' : 'none';
        document.getElementById('metadata_field_row').style.display = isMetapool ? '' : 'none';
        toggleAssetTypeRow();
    }

    function applyMetadataFieldLabel() {
        var select = document.getElementById('metadata_field_id');
        var labelInput = document.getElementById('field_label');
        var chosen = select.options[select.selectedIndex];
        if (chosen && chosen.value && !labelInput.value) {
            labelInput.value = chosen.textContent;
        }
    }

    function populateFieldForm(id, label, type, lookupSource, lookupAssetTypeId, hashAlgorithm, metadataFieldId, isRequired) {
        document.getElementById('field_id').value = id;
        document.getElementById('field_label').value = label;
        document.getElementById('field_type').value = type;
        document.getElementById('lookup_source').value = lookupSource || '';
        document.getElementById('lookup_asset_type_id').value = lookupAssetTypeId || '';
        document.getElementById('hash_algorithm').value = hashAlgorithm || 'MD5';
        document.getElementById('metadata_field_id').value = metadataFieldId || '';
        document.getElementById('is_required').checked = !!isRequired;
        toggleLookupSource();
        document.getElementById('field_submit_btn').textContent = 'Save Changes';
        document.getElementById('field_cancel_btn').style.display = '';
        document.getElementById('field_form').scrollIntoView({ behavior: 'smooth' });
    }

    function resetFieldForm() {
        document.getElementById('field_form').reset();
        document.getElementById('field_id').value = '';
        document.getElementById('field_submit_btn').textContent = 'Add Field';
        document.getElementById('field_cancel_btn').style.display = 'none';
        toggleLookupSource();
    }

    function toggleAssetTypeRow() {
        var isLookup = document.getElementById('field_type').value === 'lookup';
        var isAssets = document.getElementById('lookup_source').value === 'assets';
        document.getElementById('lookup_asset_type_row').style.display = (isLookup && isAssets) ? '' : 'none';
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
                    echo $f['field_type'] === 'date' ? 'Date/Time' : ($f['field_type'] === 'checkbox' ? 'Tick box' : ($f['field_type'] === 'metapool' ? 'Metadata Pool' : htmlspecialchars(ucfirst($f['field_type']))));
                    if ($f['field_type'] === 'lookup' && $f['lookup_source'] && isset(PROCESS_FIELD_LOOKUP_SOURCES[$f['lookup_source']])) {
                        echo ' (' . htmlspecialchars(PROCESS_FIELD_LOOKUP_SOURCES[$f['lookup_source']]['label']);
                        if (!empty($f['lookup_asset_type_name'])) {
                            echo ': ' . htmlspecialchars($f['lookup_asset_type_name']);
                        }
                        echo ')';
                    }
                    if ($f['field_type'] === 'hash' && $f['hash_algorithm']) {
                        echo ' (' . htmlspecialchars($f['hash_algorithm']) . ')';
                    }
                    if ($f['field_type'] === 'metapool' && $f['metadata_field_label']) {
                        echo ' (' . htmlspecialchars($f['metadata_field_label']) . ')';
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
                    <button type="button" class="action-btn"
                        onclick="populateFieldForm(<?php echo $f['id']; ?>, '<?php echo htmlspecialchars($f['field_label'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($f['field_type'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($f['lookup_source'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars((string) ($f['lookup_asset_type_id'] ?? ''), ENT_QUOTES); ?>', '<?php echo htmlspecialchars($f['hash_algorithm'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars((string) ($f['metadata_field_id'] ?? ''), ENT_QUOTES); ?>', <?php echo $f['is_required'] ? 'true' : 'false'; ?>)">Edit</button>
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
