<?php
// manage_processes.php - "Process Builder": define named examination
// processes (e.g. "Mobile Phone Extraction") that show up as an option when
// filling in an exhibit's examination record. Field definitions for each
// process live in manage_process_fields.php.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/audit.php';
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

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_process_type'])) {
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

            // Safe to remove the field definitions too - nothing references
            // them yet if nothing referenced the process type itself.
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
                    $message = "Process updated.";
                } else {
                    $userId = (int) $_SESSION['user_id'];
                    $stmt = $conn->prepare("INSERT INTO process_types (name, description, created_by) VALUES (?, ?, ?)");
                    $stmt->bind_param("ssi", $name, $description, $userId);
                    $stmt->execute();
                    $newId = $conn->insert_id;
                    $stmt->close();
                    log_audit_event($conn, 'process_type', $newId, 'CREATE', $userId, json_encode(['name' => $name, 'description' => $description]));
                    $message = "New process created. Add fields to it below.";
                }
            }
        }
    }
}

$processTypes = [];
$res = $conn->query("
    SELECT pt.id, pt.name, pt.description,
           (SELECT COUNT(*) FROM process_fields pf WHERE pf.process_type_id = pt.id) AS field_count,
           (SELECT COUNT(*) FROM exhibit_processes ep WHERE ep.process_type_id = pt.id) AS usage_count
    FROM process_types pt
    ORDER BY pt.name
");
while ($row = $res->fetch_assoc()) {
    $processTypes[] = $row;
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
        margin-bottom: 20px;
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
</style>

<div class="container">
    <h2>Process Builder</h2>
    <p class="muted" style="text-align:center;">Define the examination processes analysts can attach to an
        exhibit (Captain's Log &rarr; Examine &rarr; Add Process), and which fields each one asks for.</p>

    <?php if (!empty($message)): ?>
    <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post" action="manage_processes.php<?php echo $embedded ? '?embedded=1' : ''; ?>">
        <input type="hidden" name="process_type_id" id="process_type_id">
        <label for="name">Process Name</label>
        <input type="text" name="name" id="name">
        <label for="description">Description (optional)</label>
        <textarea name="description" id="description"></textarea>
        <button type="submit" class="action-btn">Save</button>
    </form>

    <table>
        <thead>
            <tr>
                <th>Process</th>
                <th>Description</th>
                <th>Fields</th>
                <th>In Use</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($processTypes)): ?>
            <tr>
                <td colspan="5" class="muted">No processes defined yet.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($processTypes as $pt): ?>
            <tr>
                <td><?php echo htmlspecialchars($pt['name']); ?></td>
                <td><?php echo htmlspecialchars($pt['description'] ?? ''); ?></td>
                <td><?php echo (int) $pt['field_count']; ?></td>
                <td><?php echo (int) $pt['usage_count']; ?></td>
                <td>
                    <a class="action-btn"
                        href="manage_process_fields.php?process_type_id=<?php echo $pt['id']; ?><?php echo $embedded ? '&embedded=1' : ''; ?>">Fields</a>
                    <button class="action-btn"
                        onclick="populateForm('<?php echo $pt['id']; ?>', '<?php echo htmlspecialchars($pt['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($pt['description'] ?? '', ENT_QUOTES); ?>')">Edit</button>
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

    <br>
    <?php if (!$embedded): ?>
    <a href="cq_dashboard.php" class="back-btn">Go Back</a>
    <?php endif; ?>
</div>

<script>
function populateForm(id, name, description) {
    document.getElementById("process_type_id").value = id;
    document.getElementById("name").value = name;
    document.getElementById("description").value = description;
}
</script>

</body>

</html>
