<?php
// manage_processes.php - "Process Builder": define named examination
// processes. Field definitions for each live in manage_process_fields.php.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/audit.php';
require_once '../includes/deletion_reason.php';
require_once '../includes/permissions.php';
require_permission($conn, 'manage_processes');

$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    require_once '../header.php';
}

$message = "";

// Syncs which exhibit types a process is scoped to - deletes whatever's
// there and reinserts exactly what's checked, simplest way to guarantee the
// table matches the form with no stale rows left behind.
function sync_process_exhibit_types(mysqli $conn, int $processTypeId, array $exhibitTypeIds): void
{
    $conn->begin_transaction();
    try {
        $del = $conn->prepare("DELETE FROM process_type_exhibit_types WHERE process_type_id = ?");
        $del->bind_param("i", $processTypeId);
        $del->execute();
        $del->close();

        if (!empty($exhibitTypeIds)) {
            $ins = $conn->prepare("INSERT INTO process_type_exhibit_types (process_type_id, exhibit_type_id) VALUES (?, ?)");
            foreach ($exhibitTypeIds as $etId) {
                $ins->bind_param("ii", $processTypeId, $etId);
                $ins->execute();
            }
            $ins->close();
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log("sync_process_exhibit_types failed for process_type_id=$processTypeId: " . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['deactivate_process_type'])) {
        $deactivate_id = intval($_POST['deactivate_process_type']);
        $stmt = $conn->prepare("UPDATE process_types SET is_active = 0 WHERE id = ?");
        $stmt->bind_param("i", $deactivate_id);
        $stmt->execute();
        $stmt->close();
        log_audit_event($conn, 'process_type', $deactivate_id, 'DEACTIVATE', (int) $_SESSION['user_id']);
        $message = "Process deactivated - it won't appear as an option on the examination page any more, but exhibits that already have it recorded keep it, and it can be reactivated later.";
    } elseif (isset($_POST['reactivate_process_type'])) {
        $reactivate_id = intval($_POST['reactivate_process_type']);
        $stmt = $conn->prepare("UPDATE process_types SET is_active = 1 WHERE id = ?");
        $stmt->bind_param("i", $reactivate_id);
        $stmt->execute();
        $stmt->close();
        log_audit_event($conn, 'process_type', $reactivate_id, 'REACTIVATE', (int) $_SESSION['user_id']);
        $message = "Process reactivated.";
    } elseif (isset($_POST['reorder_process_types'])) {
        // Drag-and-drop reorder - the client sends every row's id in its new
        // order, so sort_order just becomes each id's position in that list.
        $ids = array_filter(array_map('intval', explode(',', $_POST['reorder_ids'] ?? '')));
        $stmt = $conn->prepare("UPDATE process_types SET sort_order = ? WHERE id = ?");
        $order = 1;
        foreach ($ids as $id) {
            $stmt->bind_param("ii", $order, $id);
            $stmt->execute();
            $order++;
        }
        $stmt->close();
    } elseif (isset($_POST['move_up']) || isset($_POST['move_down'])) {
        // Swaps this process's sort_order with whichever neighbour is
        // immediately above/below it, so reordering never needs to
        // renumber the whole table.
        $moveId = intval($_POST['move_up'] ?? $_POST['move_down']);
        $movingUp = isset($_POST['move_up']);

        $curStmt = $conn->prepare("SELECT sort_order FROM process_types WHERE id = ?");
        $curStmt->bind_param("i", $moveId);
        $curStmt->execute();
        $curStmt->bind_result($curOrder);
        if ($curStmt->fetch()) {
            $curStmt->close();

            $neighborSql = $movingUp
                ? "SELECT id, sort_order FROM process_types WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1"
                : "SELECT id, sort_order FROM process_types WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1";
            $neighborStmt = $conn->prepare($neighborSql);
            $neighborStmt->bind_param("i", $curOrder);
            $neighborStmt->execute();
            $neighborStmt->bind_result($neighborId, $neighborOrder);
            if ($neighborStmt->fetch()) {
                $neighborStmt->close();
                $swapStmt = $conn->prepare("UPDATE process_types SET sort_order = ? WHERE id = ?");
                $swapStmt->bind_param("ii", $neighborOrder, $moveId);
                $swapStmt->execute();
                $swapStmt->bind_param("ii", $curOrder, $neighborId);
                $swapStmt->execute();
                $swapStmt->close();
            } else {
                $neighborStmt->close();
            }
        } else {
            $curStmt->close();
        }
    } elseif (isset($_POST['delete_process_type'])) {
        $delete_id = intval($_POST['delete_process_type']);

        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM exhibit_processes WHERE process_type_id = ?");
        $checkStmt->bind_param("i", $delete_id);
        $checkStmt->execute();
        $checkStmt->bind_result($usageCount);
        $checkStmt->fetch();
        $checkStmt->close();

        if ($usageCount > 0) {
            $message = "Cannot delete - $usageCount exhibit(s) have this process recorded against them.";
        } elseif (($deleteReason = require_deletion_reason_or_fail($conn)) === false) {
            $message = "A reason is required to delete this process type.";
        } else {
            $nameStmt = $conn->prepare("SELECT name FROM process_types WHERE id = ?");
            $nameStmt->bind_param("i", $delete_id);
            $nameStmt->execute();
            $nameStmt->bind_result($deletedName);
            $nameStmt->fetch();
            $nameStmt->close();

            // Safe to remove the field definitions too.
            $delFields = $conn->prepare("DELETE FROM process_fields WHERE process_type_id = ?");
            $delFields->bind_param("i", $delete_id);
            $delFields->execute();
            $delFields->close();

            $deleteStmt = $conn->prepare("DELETE FROM process_types WHERE id = ?");
            $deleteStmt->bind_param("i", $delete_id);
            $deleteStmt->execute();
            $deleteStmt->close();
            log_audit_event($conn, 'process_type', $delete_id, 'DELETE', (int) $_SESSION['user_id'], json_encode(['name' => $deletedName, 'reason' => $deleteReason]));
            $message = "Process type deleted.";
        }
    } else {
        $name = trim($_POST['name']);
        $description = trim($_POST['description'] ?? '');
        $process_type_id = isset($_POST['process_type_id']) ? intval($_POST['process_type_id']) : 0;
        $selectedExhibitTypeIds = array_map('intval', $_POST['exhibit_types'] ?? []);

        if ($name === '') {
            $message = "Process name cannot be empty.";
        } else {
            $dupCheck = $conn->prepare("SELECT COUNT(*) FROM process_types WHERE UPPER(name) = UPPER(?) AND id != ?");
            $dupCheck->bind_param("si", $name, $process_type_id);
            $dupCheck->execute();
            $dupCheck->bind_result($count);
            $dupCheck->fetch();
            $dupCheck->close();

            if ($count > 0) {
                $message = "A process with that name already exists.";
            } else {
                if ($process_type_id > 0) {
                    $stmt = $conn->prepare("UPDATE process_types SET name = ?, description = ? WHERE id = ?");
                    $stmt->bind_param("ssi", $name, $description, $process_type_id);
                    $stmt->execute();
                    $stmt->close();
                    log_audit_event($conn, 'process_type', $process_type_id, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['name' => $name, 'description' => $description]));
                    sync_process_exhibit_types($conn, $process_type_id, $selectedExhibitTypeIds);
                    $message = "Process updated.";
                } else {
                    $userId = (int) $_SESSION['user_id'];
                    $nextOrderResult = $conn->query("SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM process_types");
                    $nextOrder = (int) $nextOrderResult->fetch_assoc()['next_order'];
                    $stmt = $conn->prepare("INSERT INTO process_types (name, description, sort_order, created_by) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("ssii", $name, $description, $nextOrder, $userId);
                    $stmt->execute();
                    $newId = $conn->insert_id;
                    $stmt->close();
                    log_audit_event($conn, 'process_type', $newId, 'CREATE', $userId, json_encode(['name' => $name, 'description' => $description]));
                    sync_process_exhibit_types($conn, $newId, $selectedExhibitTypeIds);
                    $message = "New process created. Add fields to it below.";
                }
            }
        }
    }
}

$processTypes = [];
$res = $conn->query("
    SELECT pt.id, pt.name, pt.description, pt.sort_order, pt.is_active,
           (SELECT COUNT(*) FROM process_fields pf WHERE pf.process_type_id = pt.id) AS field_count,
           (SELECT COUNT(*) FROM exhibit_processes ep WHERE ep.process_type_id = pt.id) AS usage_count
    FROM process_types pt
    ORDER BY pt.sort_order ASC
");
while ($row = $res->fetch_assoc()) {
    $processTypes[] = $row;
}
$res->free();
$processTypeCount = count($processTypes);

$exhibitTypes = [];
$etRes = $conn->query("SELECT exhibit_type_id, type_name FROM exhibit_types ORDER BY type_name");
while ($row = $etRes->fetch_assoc()) {
    $exhibitTypes[] = $row;
}
$etRes->free();

// process_type_id => [exhibit_type_id, ...], used to pre-check the right
// boxes when editing an existing process.
$assignedByProcess = [];
$petRes = $conn->query("SELECT process_type_id, exhibit_type_id FROM process_type_exhibit_types");
while ($row = $petRes->fetch_assoc()) {
    $assignedByProcess[(int) $row['process_type_id']][] = (int) $row['exhibit_type_id'];
}
$petRes->free();
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
        margin-bottom: 20px;
    }

    form {
        max-width: 500px;
    }

    label {
        display: block;
        margin: 10px 0 5px;
        color: var(--polaris-text-secondary);
    }

    input[type="text"],
    textarea {
        width: 100%;
        max-width: 100%;
        padding: 8px;
        margin-bottom: 10px;
        background: var(--polaris-bg);
        border: 1px solid var(--polaris-border);
        color: var(--polaris-text);
        box-sizing: border-box;
        border-radius: 4px;
        font-family: inherit;
    }

    textarea {
        min-height: 60px;
        resize: vertical;
    }

    .table-scroll {
        width: 100%;
        overflow-x: auto;
    }

    table {
        width: 100%;
        min-width: 900px;
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
        margin: 2px 5px 2px 0;
        transition: background 0.3s ease;
    }

    .action-btn:hover {
        background: var(--polaris-accent-hover);
    }

    .delete-btn {
        background: var(--polaris-error-bg);
        color: var(--polaris-text);
    }

    .delete-btn:hover {
        background: var(--polaris-danger);
    }

    .order-cell {
        white-space: nowrap;
        display: flex;
        gap: 6px;
    }

    .order-btn {
        background: var(--polaris-divider);
        color: var(--polaris-text);
        border: 1px solid var(--polaris-border);
        border-radius: 3px;
        width: 26px;
        height: 26px;
        flex-shrink: 0;
        cursor: pointer;
        font-size: 14px;
        line-height: 1;
    }

    .inactive-row {
        opacity: 0.55;
    }

    .drag-handle {
        cursor: grab;
        color: var(--polaris-text-faint);
        font-size: 16px;
        padding: 0 4px;
        user-select: none;
    }

    tr.dragging {
        opacity: 0.4;
    }

    tr.drag-over {
        box-shadow: inset 0 2px 0 var(--polaris-accent);
    }

    .order-btn:hover:not(:disabled) {
        background: var(--polaris-accent);
    }

    .order-btn:disabled {
        opacity: 0.3;
        cursor: default;
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

    .muted {
        color: var(--polaris-text-faint);
        font-size: 13px;
    }

    .exhibit-type-checklist {
        display: flex;
        flex-wrap: wrap;
        gap: 10px 16px;
        margin-bottom: 10px;
    }

    .checklist-item {
        display: flex;
        align-items: center;
        gap: 6px;
        font-weight: normal;
        color: var(--polaris-text);
        margin: 0;
    }

    .checklist-item input {
        width: auto;
    }
</style>

<div class="container">
    <h2>Process Builder</h2>
    <p class="muted">Define the examination processes analysts can attach to an
        exhibit (Captain's Log &rarr; Examine &rarr; Add Process). Every field a process asks for is picked from the
        <?php if (user_can($conn, (int) $_SESSION['user_id'], 'manage_metadata_pool')): ?>
        <a href="manage_metadata_fields.php<?php echo $embedded ? '?embedded=1' : ''; ?>">Metadata Pool</a>
        <?php else: ?>
        Metadata Pool
        <?php endif; ?>
        via "Fields" below - define new data points there first, then attach the ones this process needs. By default a
        process is offered on every exhibit type - to restrict it to specific types, check them below when creating
        or editing the process.
    </p>

    <?php if (!empty($message)): ?>
    <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post" action="manage_processes.php<?php echo $embedded ? '?embedded=1' : ''; ?>">
        <input type="hidden" name="process_type_id" id="process_type_id">
        <label for="name">Process Name</label>
        <input type="text" name="name" id="name">
        <label for="description">Description (optional)</label>
        <textarea name="description" id="description"></textarea>
        <label>Assign to Exhibit Types</label>
        <div class="exhibit-type-checklist">
            <?php foreach ($exhibitTypes as $et): ?>
            <label class="checklist-item">
                <input type="checkbox" name="exhibit_types[]" value="<?php echo $et['exhibit_type_id']; ?>">
                <?php echo htmlspecialchars($et['type_name']); ?>
            </label>
            <?php endforeach; ?>
        </div>
        <p class="muted" style="margin: 4px 0 10px;">Leave all unchecked to offer this process on every exhibit type
            (default). Check specific types to restrict it to just those.</p>
        <button type="submit" class="action-btn">Save</button>
    </form>

    <p class="muted">Processes appear in this order in the "Add Process" list on the examination page.</p>

    <div class="table-scroll">
    <table>
        <thead>
            <tr>
                <th>Order</th>
                <th>Process</th>
                <th>Description</th>
                <th>Fields</th>
                <th>In Use</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($processTypes)): ?>
            <tr>
                <td colspan="7" class="muted">No processes defined yet.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($processTypes as $index => $pt): ?>
            <tr class="<?php echo $pt['is_active'] ? '' : 'inactive-row'; ?>" draggable="true" data-id="<?php echo $pt['id']; ?>">
                <td class="order-cell">
                    <span class="drag-handle" title="Drag to reorder">&#9776;</span>
                    <form method="post" action="manage_processes.php<?php echo $embedded ? '?embedded=1' : ''; ?>" style="display:inline;">
                        <input type="hidden" name="move_up" value="<?php echo $pt['id']; ?>">
                        <button type="submit" class="order-btn" <?php echo $index === 0 ? 'disabled' : ''; ?> title="Move up">&uarr;</button>
                    </form>
                    <form method="post" action="manage_processes.php<?php echo $embedded ? '?embedded=1' : ''; ?>" style="display:inline;">
                        <input type="hidden" name="move_down" value="<?php echo $pt['id']; ?>">
                        <button type="submit" class="order-btn" <?php echo $index === $processTypeCount - 1 ? 'disabled' : ''; ?> title="Move down">&darr;</button>
                    </form>
                </td>
                <td><?php echo htmlspecialchars($pt['name']); ?></td>
                <td><?php echo htmlspecialchars($pt['description'] ?? ''); ?></td>
                <td><?php echo (int) $pt['field_count']; ?></td>
                <td><?php echo (int) $pt['usage_count']; ?></td>
                <td><?php echo $pt['is_active'] ? 'Active' : 'Inactive'; ?></td>
                <td>
                    <a class="action-btn"
                        href="manage_process_fields.php?process_type_id=<?php echo $pt['id']; ?><?php echo $embedded ? '&embedded=1' : ''; ?>">Fields</a>
                    <button class="action-btn"
                        data-exhibit-types="<?php echo htmlspecialchars(implode(',', $assignedByProcess[$pt['id']] ?? [])); ?>"
                        onclick="populateForm(this, '<?php echo $pt['id']; ?>', '<?php echo htmlspecialchars($pt['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($pt['description'] ?? '', ENT_QUOTES); ?>')">Edit</button>
                    <?php if ($pt['is_active']): ?>
                    <form method="post" action="manage_processes.php<?php echo $embedded ? '?embedded=1' : ''; ?>"
                        style="display:inline;">
                        <input type="hidden" name="deactivate_process_type" value="<?php echo $pt['id']; ?>">
                        <button type="submit" class="action-btn delete-btn"
                            onclick="return confirm('Deactivate this process? It will stop appearing as an option on the examination page. Exhibits that already have it recorded keep it.');">Deactivate</button>
                    </form>
                    <?php else: ?>
                    <form method="post" action="manage_processes.php<?php echo $embedded ? '?embedded=1' : ''; ?>"
                        style="display:inline;">
                        <input type="hidden" name="reactivate_process_type" value="<?php echo $pt['id']; ?>">
                        <button type="submit" class="action-btn">Reactivate</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" action="manage_processes.php<?php echo $embedded ? '?embedded=1' : ''; ?>"
                        style="display:inline;">
                        <input type="hidden" name="delete_process_type" value="<?php echo $pt['id']; ?>">
                        <button type="submit" class="action-btn delete-btn"
                            onclick="return confirmDeleteWithReason(this.form, 'Delete this process type? Only possible if no exhibit has it recorded.')">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <form method="post" action="manage_processes.php<?php echo $embedded ? '?embedded=1' : ''; ?>" id="reorder-form" style="display:none;">
        <input type="hidden" name="reorder_process_types" value="1">
        <input type="hidden" name="reorder_ids" id="reorder-ids">
    </form>

    <br>
    <?php if (!$embedded): ?>
    <a href="cq_dashboard.php" class="back-btn">Go Back</a>
    <?php endif; ?>
</div>

<script>
function populateForm(btn, id, name, description) {
    document.getElementById("process_type_id").value = id;
    document.getElementById("name").value = name;
    document.getElementById("description").value = description;
    const ids = (btn.dataset.exhibitTypes || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    document.querySelectorAll('input[name="exhibit_types[]"]').forEach(function (cb) {
        cb.checked = ids.includes(cb.value);
    });
}

// Drag-and-drop reordering - an alternative to the up/down buttons, not a
// replacement (buttons stay for keyboard/screen-reader use).
(function () {
    const tbody = document.querySelector('table tbody');
    if (!tbody) return;
    let draggingRow = null;

    tbody.querySelectorAll('tr[draggable="true"]').forEach(function (row) {
        row.addEventListener('dragstart', function () {
            draggingRow = row;
            row.classList.add('dragging');
        });
        row.addEventListener('dragend', function () {
            row.classList.remove('dragging');
            tbody.querySelectorAll('tr').forEach(function (r) { r.classList.remove('drag-over'); });
            if (draggingRow) {
                const ids = Array.from(tbody.querySelectorAll('tr[data-id]')).map(function (r) { return r.dataset.id; });
                document.getElementById('reorder-ids').value = ids.join(',');
                document.getElementById('reorder-form').submit();
            }
        });
        row.addEventListener('dragover', function (e) {
            e.preventDefault();
            if (!draggingRow || draggingRow === row) return;
            tbody.querySelectorAll('tr').forEach(function (r) { r.classList.remove('drag-over'); });
            row.classList.add('drag-over');
            const rect = row.getBoundingClientRect();
            const before = (e.clientY - rect.top) < rect.height / 2;
            tbody.insertBefore(draggingRow, before ? row : row.nextSibling);
        });
    });
})();
</script>

</body>

</html>
