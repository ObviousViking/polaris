<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/integrity.php';
require_once '../includes/permissions.php';
require_permission($conn, 'exhibit_edit');
require_once '../header.php';

if (!isset($_GET['job_id'])) {
    echo "Job ID not specified.";
    exit();
}
$job_id = intval($_GET['job_id']);
$message = "";

// Exhibits this item could be a working copy of, for traceability.
$exhibits = [];
$exStmt = $conn->prepare("SELECT exhibit_id, exhibit_ref FROM exhibits WHERE job_id = ? AND deleted_at IS NULL ORDER BY exhibit_ref");
$exStmt->bind_param("i", $job_id);
$exStmt->execute();
$exResult = $exStmt->get_result();
while ($row = $exResult->fetch_assoc()) {
    $exhibits[] = $row;
}
$exStmt->close();

// Active item types, admin-configurable via manage_case_item_types.php.
$types = [];
$typeResult = $conn->query("SELECT type_id, type_name FROM case_item_types WHERE is_active = 1 ORDER BY type_name");
while ($row = $typeResult->fetch_assoc()) {
    $types[] = $row;
}

// Fetch logged-in user's ID and name
$user_id = intval($_SESSION['user_id']);
$stmt = $conn->prepare("SELECT id, CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($created_by_id, $created_by_name);
$stmt->fetch();
$stmt->close();

if (!$created_by_name) {
    $created_by_name = "Unknown User";
    $created_by_id = null;
}

// Fetch all users for assigned_to dropdown
$users = [];
$stmt = $conn->prepare("SELECT id, CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE is_active = 1 ORDER BY first_name, last_name");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $users[] = ['id' => $row['id'], 'full_name' => $row['full_name']];
}
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_ref = strtoupper(trim($_POST['item_ref']));
    $type_id = intval($_POST['type_id']);
    $description = trim($_POST['description']);
    $notes = trim($_POST['notes']);
    $status = $_POST['status'];
    $created_on = $_POST['created_on'] ?: date('Y-m-d');
    $assigned_to_id = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;
    $source_exhibit_id = !empty($_POST['source_exhibit_id']) ? intval($_POST['source_exhibit_id']) : null;
    $file_count = ($_POST['file_count'] ?? '') !== '' ? intval($_POST['file_count']) : null;

    if ($item_ref === '') {
        $message = "Item reference is required.";
    } elseif (empty($type_id)) {
        $message = "Please select a type.";
    } elseif (!in_array($status, ['Awaiting Review', 'Being Reviewed', 'Reviewed', 'Not Reviewed'])) {
        $message = "Invalid status selected.";
    } else {
        $dupCheck = $conn->prepare("SELECT COUNT(*) FROM case_items WHERE UPPER(item_ref) = ? AND job_id = ?");
        $dupCheck->bind_param("si", $item_ref, $job_id);
        $dupCheck->execute();
        $dupCheck->bind_result($count);
        $dupCheck->fetch();
        $dupCheck->close();

        if ($count > 0) {
            $message = "Item reference already exists for this job.";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO case_items (job_id, type_id, source_exhibit_id, item_ref, description, status, notes, file_count, created_on, created_by, assigned_to)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iiissssisii", $job_id, $type_id, $source_exhibit_id, $item_ref, $description, $status, $notes, $file_count, $created_on, $created_by_id, $assigned_to_id);
            if ($stmt->execute()) {
                $newItemId = $conn->insert_id;
                $sourceExhibitRef = '';
                foreach ($exhibits as $ex) {
                    if ($ex['exhibit_id'] == $source_exhibit_id) {
                        $sourceExhibitRef = $ex['exhibit_ref'];
                        break;
                    }
                }
                $typeName = '';
                foreach ($types as $t) {
                    if ($t['type_id'] == $type_id) {
                        $typeName = $t['type_name'];
                        break;
                    }
                }
                insert_history_row($conn, 'case_item_history', $newItemId, 'CREATE', (int) $_SESSION['user_id'], json_encode([
                    'item_ref' => $item_ref,
                    'type' => $typeName,
                    'description' => $description,
                    'notes' => $notes,
                    'status' => $status,
                    'source_exhibit' => $sourceExhibitRef,
                    'assigned_to' => $assigned_to_id,
                    'file_count' => $file_count,
                ]));
                $message = "Produced item added successfully.";
            } else {
                $message = "Error adding produced item.";
            }
            $stmt->close();
        }
    }
}
?>

<style>
    /* header.php's own body{} already sets margin/background/color/font-family
       page-wide. This page used to redeclare a second <!DOCTYPE html><html>
       <head> here with its own body{} - browsers tolerate the invalid
       nesting, but that stray rule won the cascade and bled its font into
       the real header/nav above it. Scoped to .container instead, which
       already clears the fixed header via its margin-top. */

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
    select,
    textarea {
        width: 100%;
        padding: 8px;
        background: var(--polaris-bg);
        border: 1px solid var(--polaris-border);
        color: var(--polaris-text);
        border-radius: 4px;
        font-size: 14px;
        box-sizing: border-box;
        font-family: inherit;
    }

    input[readonly] {
        background: var(--polaris-divider);
        cursor: not-allowed;
    }

    button {
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border: none;
        padding: 5px 10px;
        border-radius: 3px;
        font-size: 14px;
        cursor: pointer;
        transition: background 0.3s ease;
        align-self: flex-start;
    }

    button:hover {
        background: var(--polaris-accent-hover);
    }

    .message {
        margin-bottom: 15px;
        padding: 10px;
        background: var(--polaris-divider);
        border-left: 4px solid var(--polaris-accent);
        font-size: 14px;
    }

    a {
        color: var(--polaris-accent);
        text-decoration: none;
    }

    a:hover {
        text-decoration: underline;
    }
    </style>

    <div class="container">
        <h2>Add Produced Item</h2>

        <?php if (!empty($message)): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="post">
            <label for="item_ref">Item Reference</label>
            <input type="text" name="item_ref" id="item_ref" oninput="this.value = this.value.toUpperCase();"
                required>
            <label for="type_id">Type</label>
            <select name="type_id" id="type_id" required>
                <option value="">Select Type</option>
                <?php foreach ($types as $t): ?>
                <option value="<?php echo $t['type_id']; ?>"><?php echo htmlspecialchars($t['type_name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <label for="source_exhibit_id">Source Exhibit</label>
            <select name="source_exhibit_id" id="source_exhibit_id">
                <option value="">None / not derived from a single exhibit</option>
                <?php foreach ($exhibits as $ex): ?>
                <option value="<?php echo $ex['exhibit_id']; ?>">
                    <?php echo htmlspecialchars($ex['exhibit_ref']); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <label for="description">Description</label>
            <input type="text" name="description" id="description">
            <label for="notes">Notes</label>
            <textarea name="notes" id="notes" rows="4"></textarea>
            <label for="file_count">Number of Files</label>
            <input type="text" name="file_count" id="file_count" inputmode="numeric" pattern="[0-9]*"
                placeholder="Not all item types need this - leave blank if not applicable">
            <label for="status">Status</label>
            <select name="status" id="status" required>
                <option value="Awaiting Review">Awaiting Review</option>
                <option value="Being Reviewed">Being Reviewed</option>
                <option value="Reviewed">Reviewed</option>
                <option value="Not Reviewed">Not Reviewed</option>
            </select>
            <label for="created_on">Created On</label>
            <input type="date" name="created_on" id="created_on" value="<?php echo date('Y-m-d'); ?>" readonly>
            <label for="created_by">Created By</label>
            <input type="text" id="created_by" value="<?php echo htmlspecialchars($created_by_name); ?>" readonly>
            <input type="hidden" name="created_by" value="<?php echo htmlspecialchars($created_by_id); ?>">
            <label for="assigned_to">Assigned To</label>
            <select name="assigned_to" id="assigned_to">
                <option value="">Unassigned</option>
                <?php foreach ($users as $user): ?>
                <option value="<?php echo htmlspecialchars($user['id']); ?>">
                    <?php echo htmlspecialchars($user['full_name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Save</button>
        </form>
        <br>
        <a href="job.php?job_id=<?php echo htmlspecialchars($job_id); ?>" class="back-btn">Go Back</a>

    </div>
</body>

</html>
