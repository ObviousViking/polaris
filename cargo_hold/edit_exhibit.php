<?php
// Start session if not already active
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once('../includes/integrity.php');

// Ensure exhibit_id is provided
if (!isset($_GET['exhibit_id'])) {
    die("Exhibit ID not specified.");
}
$exhibit_id = intval($_GET['exhibit_id']);

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', 'C:/wamp64/logs/php_error.log');
error_log("Starting edit_exhibit.php for exhibit ID $exhibit_id");

// Fetch current exhibit details
$stmt = $conn->prepare("SELECT job_id, exhibit_ref, bag_number, item_description, exhibit_type_id, urgency, location_id, delivered_by, allocated_to, summary_of_findings, status FROM exhibits WHERE exhibit_id = ?");
$stmt->bind_param("i", $exhibit_id);
$stmt->execute();
$stmt->bind_result(
    $job_id, $old_exhibit_ref, $old_bag_number, $old_item_description, $old_exhibit_type_id,
    $old_urgency, $old_location_id, $old_delivered_by, $old_allocated_to, $old_summary_of_findings,
    $old_status
);
if (!$stmt->fetch()) {
    die("Exhibit not found.");
}
$stmt->close();

if (is_null($old_summary_of_findings)) {
    $old_summary_of_findings = '';
}

// Normalize old urgency, status, and delivered_by
$old_urgency = empty($old_urgency) ? 'Low' : $old_urgency;
$old_status = empty($old_status) ? 'Not Yet Started' : $old_status;
$old_delivered_by = empty($old_delivered_by) ? '' : $old_delivered_by;
error_log("Initial values for exhibit ID $exhibit_id: urgency='$old_urgency', delivered_by='$old_delivered_by'");

$valid_statuses = ['Not Yet Started', 'Imaging', 'Imaged', 'Being Analysed', 'On Hold', 'Complete'];

// Fetch exhibit types
$exhibitTypes = [];
$result = $conn->query("SELECT exhibit_type_id, type_name FROM exhibit_types ORDER BY type_name");
while ($row = $result->fetch_assoc()) {
    $exhibitTypes[] = $row;
}
$result->free();

// Fetch exhibit locations
$locations = [];
$result = $conn->query("SELECT location_id, location_name FROM exhibit_locations ORDER BY location_name");
while ($row = $result->fetch_assoc()) {
    $locations[] = $row;
}
$result->free();

// Fetch active users for Allocated To dropdown
$activeUsers = [];
$result = $conn->query("SELECT id, CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE active = 1 ORDER BY first_name, last_name");
while ($row = $result->fetch_assoc()) {
    $activeUsers[] = $row;
}
$result->free();

// Get current user's full name
$currentUserId = $_SESSION['user_id'];
$queryUser = "SELECT CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE id = " . intval($currentUserId);
$resUser = $conn->query($queryUser);
$currentUserName = $currentUserId;
if ($resUser && $rowUser = $resUser->fetch_assoc()) {
    $currentUserName = $rowUser['full_name'];
}

// Initialize update message
$updateMessage = "";
if (isset($_GET['updated']) && $_GET['updated'] == 1) {
    $updateMessage = "Exhibit updated successfully.";
}

// Check for triggers
$triggers = $conn->query("SHOW TRIGGERS LIKE 'exhibits'");
$trigger_info = [];
if ($triggers && $triggers->num_rows > 0) {
    while ($trigger = $triggers->fetch_assoc()) {
        $trigger_info[] = $trigger;
    }
    error_log("Triggers found on exhibits table: " . json_encode($trigger_info));
    file_put_contents('C:/wamp64/logs/triggers.log', json_encode($trigger_info, JSON_PRETTY_PRINT));
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve updated field values
    $new_exhibit_ref      = strtoupper(trim($_POST['exhibit_ref']));
    $new_bag_number       = strtoupper(trim($_POST['bag_number']));
    $new_item_description = trim($_POST['item_description']);
    $new_exhibit_type_id  = intval($_POST['exhibit_type']);
    $new_urgency          = trim($_POST['urgency'] ?? '');
    $new_status           = trim($_POST['status'] ?? '');
    $new_location_id      = intval($_POST['location']);
    $new_delivered_by     = trim($_POST['delivered_by']);
    $new_allocated_to     = !empty($_POST['allocated_to']) ? intval($_POST['allocated_to']) : null;
    $new_summary_of_findings = trim($_POST['summary_of_findings']);

    // Strict urgency, status, and delivered_by validation
    $valid_urgencies = ['Low', 'Medium', 'High'];
    $new_urgency = in_array($new_urgency, $valid_urgencies, true) ? $new_urgency : 'Low';
    $new_status = in_array($new_status, $valid_statuses, true) ? $new_status : $old_status;
    if ($new_delivered_by === '0') {
        $new_delivered_by = $old_delivered_by; // Prevent '0' from being saved
        error_log("Validation: delivered_by was '0', reverted to '$old_delivered_by' for exhibit ID $exhibit_id");
    }
    error_log("POST values for exhibit ID $exhibit_id: urgency='" . ($_POST['urgency'] ?? 'unset') . "', new_urgency='$new_urgency', delivered_by='$new_delivered_by', allocated_to='" . ($new_allocated_to ?? 'NULL') . "'");

    // Validate required fields and lengths
    if (empty($new_exhibit_ref) || empty($new_exhibit_type_id) || empty($new_location_id) || empty($new_delivered_by)) {
        $updateMessage = "Please fill in all required fields (Exhibit Ref, Exhibit Type, Location, Delivered By).";
    } elseif (strlen($new_exhibit_ref) > 50 || strlen($new_bag_number) > 50 || strlen($new_item_description) > 255 || strlen($new_delivered_by) > 255 || strlen($new_summary_of_findings) > 65535) {
        $updateMessage = "Exhibit Ref or Bag Number exceeds 50 characters, Description or Delivered By exceeds 255 characters, or Summary of Findings exceeds 65535 characters.";
    } else {
        // Check for duplicate exhibit_ref within job_id (excluding current exhibit)
        $dupCheck = $conn->prepare("SELECT COUNT(*) FROM exhibits WHERE UPPER(exhibit_ref) = ? AND job_id = ? AND exhibit_id != ?");
        $dupCheck->bind_param("sii", $new_exhibit_ref, $job_id, $exhibit_id);
        $dupCheck->execute();
        $dupCheck->bind_result($count);
        $dupCheck->fetch();
        $dupCheck->close();

        if ($count > 0) {
            $updateMessage = "Exhibit reference '$new_exhibit_ref' already exists for this job.";
        } else {
            // Prepare an array to track changes
            $changes = [];
            if ($new_exhibit_ref !== $old_exhibit_ref) {
                $changes['Exhibit Ref'] = ["old" => $old_exhibit_ref, "new" => $new_exhibit_ref];
            }
            if ($new_bag_number !== $old_bag_number) {
                $changes['Bag Number'] = ["old" => $old_bag_number, "new" => $new_bag_number];
            }
            if ($new_item_description !== $old_item_description) {
                $changes['Item Description'] = ["old" => $old_item_description, "new" => $new_item_description];
            }
            if ($new_exhibit_type_id !== $old_exhibit_type_id) {
                $oldType = $newType = "";
                foreach ($exhibitTypes as $et) {
                    if ($et['exhibit_type_id'] == $old_exhibit_type_id) {
                        $oldType = $et['type_name'];
                    }
                    if ($et['exhibit_type_id'] == $new_exhibit_type_id) {
                        $newType = $et['type_name'];
                    }
                }
                $changes['Exhibit Type'] = ["old" => $oldType, "new" => $newType];
            }
            if ($new_urgency !== $old_urgency) {
                $changes['Urgency'] = ["old" => $old_urgency, "new" => $new_urgency];
            }
            if ($new_status !== $old_status) {
                $changes['Status'] = ["old" => $old_status, "new" => $new_status];
            }
            if ($new_location_id !== $old_location_id) {
                $oldLoc = $newLoc = "";
                foreach ($locations as $loc) {
                    if ($loc['location_id'] == $old_location_id) {
                        $oldLoc = $loc['location_name'];
                    }
                    if ($loc['location_id'] == $new_location_id) {
                        $newLoc = $loc['location_name'];
                    }
                }
                $changes['Location'] = ["old" => $oldLoc, "new" => $newLoc];
            }
            if ($new_delivered_by !== $old_delivered_by) {
                $changes['Delivered By'] = ["old" => $old_delivered_by, "new" => $new_delivered_by];
            }
            if ($new_allocated_to !== $old_allocated_to) {
                $oldUser = $newUser = "";
                foreach ($activeUsers as $user) {
                    if ($user['id'] == $old_allocated_to) {
                        $oldUser = $user['full_name'];
                    }
                    if ($user['id'] == $new_allocated_to) {
                        $newUser = $user['full_name'];
                    }
                }
                $changes['Allocated To'] = ["old" => $oldUser, "new" => $newUser];
            }
            if ($new_summary_of_findings !== $old_summary_of_findings) {
                $changes['Summary of Findings'] = ["old" => $old_summary_of_findings, "new" => $new_summary_of_findings];
            }

            if (!empty($changes)) {
                // Start transaction
                $conn->begin_transaction();
                try {
                    // Update urgency first
                    $urgencyStmt = $conn->prepare("UPDATE exhibits SET urgency = ? WHERE exhibit_id = ?");
                    if (!$urgencyStmt) {
                        throw new Exception("Urgency prepare failed: " . $conn->error);
                    }
                    $urgencyStmt->bind_param("si", $new_urgency, $exhibit_id);
                    if (!$urgencyStmt->execute()) {
                        throw new Exception("Urgency update failed: " . $urgencyStmt->error);
                    }
                    $affected_rows = $urgencyStmt->affected_rows;
                    error_log("Urgency update for exhibit ID $exhibit_id, urgency: '$new_urgency', affected rows: $affected_rows");
                    // Check current urgency value
                    $checkStmt = $conn->prepare("SELECT urgency, delivered_by FROM exhibits WHERE exhibit_id = ?");
                    $checkStmt->bind_param("i", $exhibit_id);
                    $checkStmt->execute();
                    $checkStmt->bind_result($current_urgency, $current_delivered_by);
                    $checkStmt->fetch();
                    $checkStmt->close();
                    error_log("Values after urgency update for exhibit ID $exhibit_id: urgency='$current_urgency', delivered_by='$current_delivered_by'");
                    // Check MySQL warnings
                    $warnings = $conn->query("SHOW WARNINGS");
                    if ($warnings && $warnings->num_rows > 0) {
                        while ($warning = $warnings->fetch_assoc()) {
                            error_log("MySQL warning for urgency update: " . json_encode($warning));
                        }
                    }
                    $urgencyStmt->close();

                    // Update other fields (exclude urgency to avoid trigger)
                    $updateStmt = $conn->prepare("UPDATE exhibits SET exhibit_ref = ?, bag_number = ?, item_description = ?, exhibit_type_id = ?, location_id = ?, delivered_by = ?, allocated_to = ?, summary_of_findings = ?, status = ? WHERE exhibit_id = ?");
                    if (!$updateStmt) {
                        throw new Exception("Other fields prepare failed: " . $conn->error);
                    }
                    $updateStmt->bind_param("sssiisissi", $new_exhibit_ref, $new_bag_number, $new_item_description, $new_exhibit_type_id, $new_location_id, $new_delivered_by, $new_allocated_to, $new_summary_of_findings, $new_status, $exhibit_id);
                    if (!$updateStmt->execute()) {
                        throw new Exception("Other fields update failed: " . $updateStmt->error);
                    }
                    $affected_rows = $updateStmt->affected_rows;
                    error_log("Other fields update for exhibit ID $exhibit_id, delivered_by: '$new_delivered_by', allocated_to: '" . ($new_allocated_to ?? 'NULL') . "', affected rows: $affected_rows");
                    // Check current values
                    $checkStmt = $conn->prepare("SELECT urgency, delivered_by FROM exhibits WHERE exhibit_id = ?");
                    $checkStmt->bind_param("i", $exhibit_id);
                    $checkStmt->execute();
                    $checkStmt->bind_result($current_urgency, $current_delivered_by);
                    $checkStmt->fetch();
                    $checkStmt->close();
                    error_log("Values after other fields update for exhibit ID $exhibit_id: urgency='$current_urgency', delivered_by='$current_delivered_by'");
                    // Check MySQL warnings
                    $warnings = $conn->query("SHOW WARNINGS");
                    if ($warnings && $warnings->num_rows > 0) {
                        while ($warning = $warnings->fetch_assoc()) {
                            error_log("MySQL warning for other fields update: " . json_encode($warning));
                        }
                    }
                    $updateStmt->close();

                    // Verify urgency hasn't reverted
                    if ($current_urgency !== $new_urgency) {
                        error_log("Warning: urgency reverted to '$current_urgency' after other fields update, expected '$new_urgency' for exhibit ID $exhibit_id");
                        // Attempt to restore urgency
                        $restoreStmt = $conn->prepare("UPDATE exhibits SET urgency = ? WHERE exhibit_id = ?");
                        $restoreStmt->bind_param("si", $new_urgency, $exhibit_id);
                        $restoreStmt->execute();
                        $restoreStmt->close();
                        // Check again
                        $checkStmt = $conn->prepare("SELECT urgency, delivered_by FROM exhibits WHERE exhibit_id = ?");
                        $checkStmt->bind_param("i", $exhibit_id);
                        $checkStmt->execute();
                        $checkStmt->bind_result($current_urgency, $current_delivered_by);
                        $checkStmt->fetch();
                        $checkStmt->close();
                        error_log("Values after urgency restore for exhibit ID $exhibit_id: urgency='$current_urgency', delivered_by='$current_delivered_by'");
                    }

                    // Insert audit record
                    $historyAction = "UPDATE";
                    $changedBy = $_SESSION['user_id'];
                    $changesJSON = json_encode($changes);
                    if (!insert_history_row($conn, 'exhibit_history', $exhibit_id, $historyAction, $changedBy, $changesJSON)) {
                        throw new Exception("History insert failed: " . $conn->error);
                    }

                    // Commit transaction
                    $conn->commit();
                    error_log("Transaction committed for exhibit ID $exhibit_id");

                    require_once '../includes/achievements.php';
                    check_and_unlock_achievements($conn, (int) $changedBy, 'exhibits_completed');

                    // Refresh old values
                    $old_exhibit_ref      = $new_exhibit_ref;
                    $old_bag_number       = $new_bag_number;
                    $old_item_description = $new_item_description;
                    $old_exhibit_type_id  = $new_exhibit_type_id;
                    $old_urgency          = $new_urgency;
                    $old_status           = $new_status;
                    $old_location_id      = $new_location_id;
                    $old_delivered_by     = $new_delivered_by;
                    $old_allocated_to     = $new_allocated_to;
                    $old_summary_of_findings = $new_summary_of_findings;

                    // Redirect to avoid resubmission
                    header("Location: edit_exhibit.php?exhibit_id=" . $exhibit_id . "&updated=1");
                    exit();
                } catch (Exception $e) {
                    $conn->rollback();
                    error_log("Transaction rolled back for exhibit ID $exhibit_id: " . $e->getMessage());
                    $updateMessage = "Error updating exhibit: " . $e->getMessage();
                }
            } else {
                $updateMessage = "No changes detected.";
            }
        }
    }
}

// Retrieve exhibit history
$historyRecords = [];
$histQuery = $conn->prepare("SELECT changed_at, action, changed_by, changes FROM exhibit_history WHERE exhibit_id = ? ORDER BY changed_at DESC");
$histQuery->bind_param("i", $exhibit_id);
$histQuery->execute();
$histResult = $histQuery->get_result();
while ($row = $histResult->fetch_assoc()) {
    $changedByName = $row['changed_by'];
    $resUser = $conn->query("SELECT CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE id = " . intval($row['changed_by']));
    if ($resUser && $userRow = $resUser->fetch_assoc()) {
        $changedByName = $rowUser['full_name'];
    }
    $historyRecords[] = [
        'changed_at' => $row['changed_at'],
        'action'     => $row['action'],
        'changed_by' => $changedByName,
        'changes'    => $row['changes']
    ];
}
$histQuery->close();

// Include header
include('../header.php');
?>

<style>
    /* header.php's own body{} already sets margin/background/color/font-family
       page-wide. This page used to redeclare a second <!DOCTYPE html><html>
       <head> here with its own body{} - browsers tolerate the invalid
       nesting, but that stray rule won the cascade and bled its font into
       the real header/nav above it. The 120px clearance moves onto
       .container's top margin since body no longer carries it. */

    .container {
        max-width: 800px;
        margin: 140px auto 20px auto;
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 5px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    }

    h2 {
        text-align: center;
        margin-bottom: 20px;
    }

    form label {
        display: block;
        font-weight: bold;
        margin-bottom: 5px;
        color: var(--polaris-text-dim);
    }

    form input[type="text"],
    form select,
    form textarea {
        width: 100%;
        padding: 8px;
        margin-bottom: 15px;
        border: 1px solid var(--polaris-text-secondary);
        border-radius: 3px;
        background: var(--polaris-bg);
        color: var(--polaris-text);
    }

    form button {
        padding: 5px 10px;
        border: none;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border-radius: 3px;
        cursor: pointer;
        font-size: 14px;
    }

    form button:hover {
        background: var(--polaris-accent-hover);
    }

    .message {
        text-align: center;
        padding: 10px;
        margin-bottom: 20px;
        border-radius: 3px;
    }

    .success {
        background: var(--polaris-success-bg);
        color: var(--polaris-success-text);
    }

    .error {
        background: var(--polaris-error-bg);
        color: var(--polaris-error-text);
    }

    .history-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 30px;
    }

    .history-table th,
    .history-table td {
        border: 1px solid var(--polaris-border);
        padding: 8px;
        font-size: 13px;
        text-align: left;
    }

    .history-table th {
        background: var(--polaris-divider);
    }

    .history-changes p {
        margin: 0 0 5px 0;
        line-height: 1.4;
    }

    .history-changes strong {
        display: inline-block;
        width: 140px;
    }

    .cancel-btn {
        padding: 5px 10px;
        border: none;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border-radius: 3px;
        cursor: pointer;
        margin-top: 10px;
        font-size: 14px;
    }

    .cancel-btn:hover {
        background: var(--polaris-accent-hover);
    }
    </style>
    <script>
    function toUpperCase(el) {
        el.value = el.value.toUpperCase();
    }
    </script>

    <div class="container">
        <h2>Edit Exhibit</h2>

        <?php if (!empty($updateMessage)): ?>
        <div class="message <?php echo (strpos($updateMessage, 'successfully') !== false) ? 'success' : 'error'; ?>">
            <?php echo htmlspecialchars($updateMessage); ?>
        </div>
        <?php endif; ?>

        <form method="post" action="edit_exhibit.php?exhibit_id=<?php echo $exhibit_id; ?>">
            <div style="display: flex; flex-wrap: wrap; gap: 20px;">
                <!-- LEFT COLUMN -->
                <div style="flex: 1 1 45%;">
                    <label for="exhibit_ref">Exhibit Ref</label>
                    <input type="text" id="exhibit_ref" name="exhibit_ref"
                        value="<?php echo htmlspecialchars($old_exhibit_ref); ?>" required oninput="toUpperCase(this);">

                    <label for="exhibit_type">Exhibit Type</label>
                    <select id="exhibit_type" name="exhibit_type" required>
                        <option value="">Select Type</option>
                        <?php foreach ($exhibitTypes as $et): ?>
                        <option value="<?php echo $et['exhibit_type_id']; ?>"
                            <?php echo ($et['exhibit_type_id'] == $old_exhibit_type_id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($et['type_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="urgency">Urgency</label>
                    <select id="urgency" name="urgency" required>
                        <option value="Low" <?php echo ($old_urgency === 'Low') ? 'selected' : ''; ?>>Low</option>
                        <option value="Medium" <?php echo ($old_urgency === 'Medium') ? 'selected' : ''; ?>>Medium
                        </option>
                        <option value="High" <?php echo ($old_urgency === 'High') ? 'selected' : ''; ?>>High</option>
                    </select>

                    <label for="status">Status</label>
                    <select id="status" name="status" required>
                        <?php foreach ($valid_statuses as $statusOption): ?>
                        <option value="<?php echo htmlspecialchars($statusOption); ?>"
                            <?php echo ($old_status === $statusOption) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($statusOption); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="allocated_to">Allocated To</label>
                    <select id="allocated_to" name="allocated_to">
                        <option value="">Select User</option>
                        <?php foreach ($activeUsers as $user): ?>
                        <option value="<?php echo $user['id']; ?>"
                            <?php echo ($user['id'] == $old_allocated_to) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['full_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- RIGHT COLUMN -->
                <div style="flex: 1 1 45%;">
                    <label for="item_description">Description</label>
                    <input type="text" id="item_description" name="item_description"
                        value="<?php echo htmlspecialchars($old_item_description); ?>">

                    <label for="bag_number">Bag Number</label>
                    <input type="text" id="bag_number" name="bag_number"
                        value="<?php echo htmlspecialchars($old_bag_number); ?>" required oninput="toUpperCase(this);">

                    <label for="location">Location</label>
                    <select id="location" name="location" required>
                        <?php if (!empty($locations)): ?>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?php echo $loc['location_id']; ?>"
                            <?php echo ($loc['location_id'] == $old_location_id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($loc['location_name']); ?>
                        </option>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <option value="">No locations available</option>
                        <?php endif; ?>
                    </select>

                    <label for="delivered_by">Delivered By</label>
                    <input type="text" id="delivered_by" name="delivered_by"
                        value="<?php echo htmlspecialchars($old_delivered_by); ?>" required>
                </div>
            </div>

            <hr style="margin-top:40px; margin-bottom:20px;">

            <!-- Summary of Findings -->
            <div>
                <label for="summary_of_findings">Summary of Findings</label>
                <textarea id="summary_of_findings" name="summary_of_findings" rows="5"
                    style="width:100%;"><?php echo htmlspecialchars($old_summary_of_findings); ?></textarea>
            </div>

            <hr style="margin-top:40px; margin-bottom:20px;">

            <div style="text-align: center; margin-top: 20px;">
                <button type="submit">Save Changes</button>
                <button type="button" class="cancel-btn"
                    onclick="history.back();">Cancel</button>
                <button type="button" class="cancel-btn"
                    onclick="window.location.href='view_exhibit_history.php?exhibit_id=<?php echo $exhibit_id; ?>'">
                    View History
                </button>
            </div>
        </form>
    </div>
</body>

</html>