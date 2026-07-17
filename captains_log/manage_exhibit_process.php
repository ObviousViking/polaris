<?php
// manage_exhibit_process.php
//
// Fills in (or edits) one examination process against an exhibit. Every
// create/edit writes a full snapshot into exhibit_process_history (a
// snapshot rather than a diff, since fields are dynamic per process type).
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/integrity.php';
require_once '../includes/process_lookups.php';

$exhibit_process_id = isset($_GET['exhibit_process_id']) ? intval($_GET['exhibit_process_id']) : 0;
$isEdit = $exhibit_process_id > 0;

if ($isEdit) {
    $epStmt = $conn->prepare("
        SELECT ep.id, ep.exhibit_id, ep.process_type_id, ep.free_text, pt.name AS process_name
        FROM exhibit_processes ep
        JOIN process_types pt ON ep.process_type_id = pt.id
        WHERE ep.id = ?
    ");
    $epStmt->bind_param("i", $exhibit_process_id);
    $epStmt->execute();
    $existing = $epStmt->get_result()->fetch_assoc();
    $epStmt->close();

    if (!$existing) {
        die("Process record not found.");
    }
    $exhibit_id = (int) $existing['exhibit_id'];
    $process_type_id = (int) $existing['process_type_id'];
    $process_name = $existing['process_name'];
    $free_text_value = $existing['free_text'];

    $existingValues = [];
    $valStmt = $conn->prepare("SELECT process_field_id, value FROM exhibit_process_values WHERE exhibit_process_id = ?");
    $valStmt->bind_param("i", $exhibit_process_id);
    $valStmt->execute();
    $valResult = $valStmt->get_result();
    while ($row = $valResult->fetch_assoc()) {
        $existingValues[(int) $row['process_field_id']] = $row['value'];
    }
    $valStmt->close();
} else {
    $exhibit_id = isset($_GET['exhibit_id']) ? intval($_GET['exhibit_id']) : 0;
    $process_type_id = isset($_GET['process_type_id']) ? intval($_GET['process_type_id']) : 0;

    $ptStmt = $conn->prepare("SELECT name FROM process_types WHERE id = ? AND is_active = 1");
    $ptStmt->bind_param("i", $process_type_id);
    $ptStmt->execute();
    $ptStmt->bind_result($process_name);
    if ($exhibit_id <= 0 || !$ptStmt->fetch()) {
        die("Invalid exhibit or process type.");
    }
    $ptStmt->close();
    $free_text_value = '';
    $existingValues = [];
}

// Confirm the exhibit exists and grab its ref for the page title/breadcrumb.
$exStmt = $conn->prepare("SELECT exhibit_ref FROM exhibits WHERE exhibit_id = ?");
$exStmt->bind_param("i", $exhibit_id);
$exStmt->execute();
$exStmt->bind_result($exhibit_ref);
if (!$exStmt->fetch()) {
    die("Exhibit not found.");
}
$exStmt->close();

// Field definitions for this process type, in display order.
$fields = [];
$fieldStmt = $conn->prepare("SELECT id, field_label, field_key, field_type, lookup_source, is_required FROM process_fields WHERE process_type_id = ? ORDER BY sort_order");
$fieldStmt->bind_param("i", $process_type_id);
$fieldStmt->execute();
$fieldResult = $fieldStmt->get_result();
while ($row = $fieldResult->fetch_assoc()) {
    if ($row['field_type'] === 'lookup') {
        $row['lookup_options'] = get_process_field_lookup_options($conn, $row['lookup_source']);
    }
    $fields[] = $row;
}
$fieldStmt->close();

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_free_text = trim($_POST['free_text'] ?? '');
    $fieldValues = [];
    $missingRequired = [];

    foreach ($fields as $f) {
        $raw = trim($_POST['field_' . $f['id']] ?? '');
        if ($f['is_required'] && $raw === '') {
            $missingRequired[] = $f['field_label'];
        }
        if ($f['field_type'] === 'number' && $raw !== '' && !is_numeric($raw)) {
            $missingRequired[] = $f['field_label'] . ' (must be a number)';
        }
        $fieldValues[(int) $f['id']] = $raw;
    }

    if (!empty($missingRequired)) {
        $message = "Please check: " . implode(', ', $missingRequired);
    } else {
        $userId = (int) $_SESSION['user_id'];

        if ($isEdit) {
            // Snapshot the state as it exists right now, before overwriting it.
            $oldSnapshot = ['process' => $process_name, 'free_text' => $free_text_value];
            foreach ($fields as $f) {
                $oldVal = $existingValues[(int) $f['id']] ?? null;
                if ($oldVal !== null && $oldVal !== '') {
                    $oldSnapshot[$f['field_label']] = $oldVal;
                }
            }

            $stmt = $conn->prepare("UPDATE exhibit_processes SET free_text = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("sii", $new_free_text, $userId, $exhibit_process_id);
            $stmt->execute();
            $stmt->close();

            $upsert = $conn->prepare("
                INSERT INTO exhibit_process_values (exhibit_process_id, process_field_id, value)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE value = VALUES(value)
            ");
            foreach ($fieldValues as $fieldId => $val) {
                $upsert->bind_param("iis", $exhibit_process_id, $fieldId, $val);
                $upsert->execute();
            }
            $upsert->close();

            insert_history_row($conn, 'exhibit_process_history', $exhibit_process_id, 'UPDATE', $userId, json_encode($oldSnapshot));
        } else {
            $stmt = $conn->prepare("INSERT INTO exhibit_processes (exhibit_id, process_type_id, free_text, created_by) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iisi", $exhibit_id, $process_type_id, $new_free_text, $userId);
            $stmt->execute();
            $exhibit_process_id = $conn->insert_id;
            $stmt->close();

            $insertVal = $conn->prepare("INSERT INTO exhibit_process_values (exhibit_process_id, process_field_id, value) VALUES (?, ?, ?)");
            foreach ($fieldValues as $fieldId => $val) {
                $insertVal->bind_param("iis", $exhibit_process_id, $fieldId, $val);
                $insertVal->execute();
            }
            $insertVal->close();

            $newSnapshot = ['process' => $process_name, 'free_text' => $new_free_text];
            foreach ($fields as $f) {
                $val = $fieldValues[(int) $f['id']] ?? '';
                if ($val !== '') {
                    $newSnapshot[$f['field_label']] = $val;
                }
            }
            insert_history_row($conn, 'exhibit_process_history', $exhibit_process_id, 'CREATE', $userId, json_encode($newSnapshot));

            require_once '../includes/achievements.php';
            check_and_unlock_achievements($conn, $userId, 'examinations_completed');
        }

        header("Location: examination.php?exhibit_id=" . $exhibit_id);
        exit();
    }
}

include '../header.php';
?>
<style>
    .content-wrapper {
        max-width: 700px;
        margin: 120px auto 40px auto;
        padding: 20px;
        background: var(--polaris-surface);
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(255, 255, 255, 0.1);
    }

    h2 {
        margin-top: 0;
        text-align: center;
    }

    .subtitle {
        text-align: center;
        color: var(--polaris-text-faint);
        margin-bottom: 20px;
    }

    label {
        display: block;
        margin: 15px 0 5px;
        font-weight: bold;
        color: var(--polaris-gray-e0);
    }

    .required-star {
        color: var(--polaris-danger-alt);
    }

    input[type="text"],
    input[type="number"],
    input[type="date"],
    textarea {
        width: 100%;
        padding: 8px;
        border: 1px solid var(--polaris-border);
        border-radius: 4px;
        background: var(--polaris-bg);
        color: var(--polaris-text);
        box-sizing: border-box;
        font-family: inherit;
    }

    textarea {
        min-height: 70px;
        resize: vertical;
    }

    #free_text_editor {
        height: 160px;
        /* Always white/black - this is the Quill editing canvas, not part
           of the app chrome, so it should not follow the theme. */
        background: #fff;
        color: #000;
        border-radius: 0 0 4px 4px;
    }

    select {
        width: 100%;
        padding: 8px;
        border: 1px solid var(--polaris-border);
        border-radius: 4px;
        background: var(--polaris-bg);
        color: var(--polaris-text);
        box-sizing: border-box;
        font-family: inherit;
    }

    .message {
        text-align: center;
        margin-bottom: 15px;
        padding: 10px;
        border-radius: 5px;
        background: var(--polaris-error-bg);
        color: var(--polaris-error-text);
    }

    .button-group {
        display: flex;
        justify-content: space-between;
        margin-top: 25px;
    }

    .btn-submit,
    .btn-cancel {
        padding: 5px 10px;
        border: none;
        border-radius: 3px;
        cursor: pointer;
        font-size: 14px;
        text-decoration: none;
        text-align: center;
        width: 48%;
    }

    .btn-submit {
        background: var(--polaris-accent);
        color: var(--polaris-text);
    }

    .btn-submit:hover {
        background: var(--polaris-accent-hover);
    }

    .btn-cancel {
        background: var(--polaris-accent);
        color: var(--polaris-text);
    }

    .btn-cancel:hover {
        background: var(--polaris-accent-hover);
    }
</style>

<div class="content-wrapper">
    <h2><?php echo htmlspecialchars($process_name); ?></h2>
    <p class="subtitle">Exhibit <?php echo htmlspecialchars($exhibit_ref); ?><?php echo $isEdit ? ' - editing existing record' : ''; ?></p>

    <?php if (!empty($message)): ?>
    <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post"
        action="manage_exhibit_process.php?<?php echo $isEdit ? 'exhibit_process_id=' . $exhibit_process_id : 'exhibit_id=' . $exhibit_id . '&process_type_id=' . $process_type_id; ?>">
        <?php foreach ($fields as $f): ?>
        <label for="field_<?php echo $f['id']; ?>">
            <?php echo htmlspecialchars($f['field_label']); ?>
            <?php if ($f['is_required']): ?><span class="required-star">*</span><?php endif; ?>
        </label>
        <?php $val = $existingValues[(int) $f['id']] ?? ''; ?>
        <?php if ($f['field_type'] === 'textarea'): ?>
        <textarea name="field_<?php echo $f['id']; ?>" id="field_<?php echo $f['id']; ?>"
            <?php echo $f['is_required'] ? 'required' : ''; ?>><?php echo htmlspecialchars($val); ?></textarea>
        <?php elseif ($f['field_type'] === 'number'): ?>
        <input type="number" step="any" name="field_<?php echo $f['id']; ?>" id="field_<?php echo $f['id']; ?>"
            value="<?php echo htmlspecialchars($val); ?>" <?php echo $f['is_required'] ? 'required' : ''; ?>>
        <?php elseif ($f['field_type'] === 'date'): ?>
        <input type="date" name="field_<?php echo $f['id']; ?>" id="field_<?php echo $f['id']; ?>"
            value="<?php echo htmlspecialchars($val); ?>" <?php echo $f['is_required'] ? 'required' : ''; ?>>
        <?php elseif ($f['field_type'] === 'lookup'): ?>
        <select name="field_<?php echo $f['id']; ?>" id="field_<?php echo $f['id']; ?>"
            <?php echo $f['is_required'] ? 'required' : ''; ?>>
            <option value="">Select&hellip;</option>
            <?php foreach ($f['lookup_options'] as $opt): ?>
            <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $opt === $val ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($opt); ?></option>
            <?php endforeach; ?>
            <?php if ($val !== '' && !in_array($val, $f['lookup_options'], true)): ?>
            <option value="<?php echo htmlspecialchars($val); ?>" selected><?php echo htmlspecialchars($val); ?> (no
                longer in list)</option>
            <?php endif; ?>
        </select>
        <?php else: ?>
        <input type="text" name="field_<?php echo $f['id']; ?>" id="field_<?php echo $f['id']; ?>"
            value="<?php echo htmlspecialchars($val); ?>" <?php echo $f['is_required'] ? 'required' : ''; ?>>
        <?php endif; ?>
        <?php endforeach; ?>

        <label for="free_text">Notes</label>
        <!-- Hidden textarea holds the value actually submitted; Quill (self-hosted,
             same as cargo_hold/add_update.php's case updates) edits into a visible
             div and its HTML is copied across on submit. -->
        <textarea id="free_text" name="free_text" style="display:none;"><?php echo htmlspecialchars($free_text_value); ?></textarea>
        <div id="free_text_editor"></div>

        <div class="button-group">
            <button type="submit" class="btn-submit">Save</button>
            <a href="examination.php?exhibit_id=<?php echo $exhibit_id; ?>" class="btn-cancel">Cancel</a>
        </div>
    </form>
</div>

<link href="../js/quill/quill.snow.css" rel="stylesheet">
<script src="../js/quill/quill.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    var hidden = document.getElementById('free_text');
    var quill = new Quill('#free_text_editor', {
        theme: 'snow',
        modules: {
            toolbar: [
                ['bold', 'italic', 'underline'],
                [{ list: 'ordered' }, { list: 'bullet' }],
                ['link'],
            ]
        }
    });
    quill.root.innerHTML = hidden.value;

    hidden.closest('form').addEventListener('submit', function() {
        hidden.value = quill.root.innerHTML;
    });
});
</script>

</body>

</html>
