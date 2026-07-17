<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/audit.php';
require_once '../header.php';

if (!isset($_GET['job_id'])) {
    echo "Job ID not specified.";
    exit();
}
$job_id = intval($_GET['job_id']);
$message = "";

// Fetch logged-in user's ID and name
$user_id = intval($_SESSION['user_id']);
$stmt = $conn->prepare("SELECT id, CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($extracted_by_id, $extracted_by_name);
$stmt->fetch();
$stmt->close();

if (!$extracted_by_name) {
    $extracted_by_name = "Unknown User";
    $extracted_by_id = null;
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
    $extraction_ref = strtoupper(trim($_POST['extraction_ref']));
    $description = trim($_POST['description']);
    $status = $_POST['status'];
    $extracted_on = $_POST['extracted_on'] ?: date('Y-m-d');
    $assigned_to_id = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;

    if ($extraction_ref === '') {
        $message = "Extraction reference is required.";
    } elseif (!in_array($status, ['Awaiting Review', 'Being Reviewed', 'Reviewed', 'Not Reviewed'])) {
        $message = "Invalid status selected.";
    } else {
        $dupCheck = $conn->prepare("SELECT COUNT(*) FROM exported_items WHERE UPPER(extraction_ref) = ? AND job_id = ?");
        $dupCheck->bind_param("si", $extraction_ref, $job_id);
        $dupCheck->execute();
        $dupCheck->bind_result($count);
        $dupCheck->fetch();
        $dupCheck->close();

        if ($count > 0) {
            $message = "Extraction reference already exists for this job.";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO exported_items (job_id, extraction_ref, description, status, extracted_on, extracted_by, assigned_to)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isssssi", $job_id, $extraction_ref, $description, $status, $extracted_on, $extracted_by_id, $assigned_to_id);
            if ($stmt->execute()) {
                $newItemId = $conn->insert_id;
                log_audit_event($conn, 'exported_item', $newItemId, 'CREATE', (int) $_SESSION['user_id'], json_encode(['extraction_ref' => $extraction_ref, 'description' => $description, 'status' => $status, 'assigned_to' => $assigned_to_id]));
                $message = "Exported item added successfully.";
            } else {
                $message = "Error adding exported item.";
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
    select {
        width: 100%;
        padding: 8px;
        background: var(--polaris-bg);
        border: 1px solid var(--polaris-border);
        color: var(--polaris-text);
        border-radius: 4px;
        font-size: 14px;
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
        <h2>Add Exported Item</h2>

        <?php if (!empty($message)): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="post">
            <label for="extraction_ref">Extraction Reference</label>
            <input type="text" name="extraction_ref" id="extraction_ref"
                oninput="this.value = this.value.toUpperCase();" required>
            <label for="description">Description</label>
            <input type="text" name="description" id="description">
            <label for="status">Status</label>
            <select name="status" id="status" required>
                <option value="Awaiting Review">Awaiting Review</option>
                <option value="Being Reviewed">Being Reviewed</option>
                <option value="Reviewed">Reviewed</option>
                <option value="Not Reviewed">Not Reviewed</option>
            </select>
            <label for="extracted_on">Extracted On</label>
            <input type="date" name="extracted_on" id="extracted_on" value="<?php echo date('Y-m-d'); ?>" readonly>
            <label for="extracted_by">Extracted By</label>
            <input type="text" id="extracted_by" value="<?php echo htmlspecialchars($extracted_by_name); ?>" readonly>
            <input type="hidden" name="extracted_by" value="<?php echo htmlspecialchars($extracted_by_id); ?>">
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