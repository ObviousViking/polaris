<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once('../includes/integrity.php');
require_once '../includes/permissions.php';
require_once '../includes/exhibit_receipts.php';
require_permission($conn, 'exhibit_edit');

// Ensure a job_id is provided.
if (!isset($_GET['job_id'])) {
    die("Job ID not specified.");
}
$job_id = intval($_GET['job_id']);

// Query only exhibits for this job that are currently checked out (time_out IS NOT NULL) -
// same field set as add_exhibit.php's form, since details like Bag Number or
// Delivered By may need correcting by the time an exhibit comes back.
$stmt = $conn->prepare("SELECT exhibit_id, exhibit_ref, item_description, bag_number, exhibit_type_id,
                                urgency, location_id, delivered_by, time_out
                        FROM exhibits
                        WHERE job_id = ? AND time_out IS NOT NULL AND deleted_at IS NULL
                        ORDER BY time_out DESC");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$result = $stmt->get_result();

$exhibits = [];
while ($row = $result->fetch_assoc()) {
    $exhibits[] = $row;
}
$stmt->close();

// Exhibit types, same as add_exhibit.php.
$exhibitTypes = [];
$result = $conn->query("SELECT exhibit_type_id, type_name FROM exhibit_types ORDER BY type_name");
while ($row = $result->fetch_assoc()) {
    $exhibitTypes[] = $row;
}
$result->free();

// Active locations only, same as add_exhibit.php - where the exhibit is
// being stored now that it's back.
$locations = [];
$result = $conn->query("SELECT location_id, location_name FROM exhibit_locations WHERE is_active = 1 ORDER BY location_name");
while ($row = $result->fetch_assoc()) {
    $locations[] = $row;
}
$result->free();

$message = "";
$valid_urgencies = ['Low', 'Medium', 'High'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['exhibits']) || !is_array($_POST['exhibits'])) {
        $message = "No exhibits selected.";
    } else {
        $selectedExhibits = $_POST['exhibits'];
        $returnedBy = trim($_POST['returned_by']);

        if (empty($returnedBy)) {
            $message = "Please enter who returned the exhibit(s).";
        } else {
            $exhibitsById = [];
            foreach ($exhibits as $ex) {
                $exhibitsById[$ex['exhibit_id']] = $ex;
            }

            $descriptions = $_POST['item_description'] ?? [];
            $exhibitTypePosts = $_POST['exhibit_type'] ?? [];
            $urgencyPosts = $_POST['urgency'] ?? [];
            $bagNumbers = $_POST['bag_number'] ?? [];
            $deliveredBys = $_POST['delivered_by'] ?? [];
            $locationPosts = $_POST['location'] ?? [];

            // Captured once so every exhibit in this batch (and the
            // receipt) shows the same "returned at" moment.
            $returnTime = date('Y-m-d H:i:s');
            $updated_ids = [];
            $receiptRows = [];

            foreach ($selectedExhibits as $exhibit_id) {
                $exhibit_id = intval($exhibit_id);
                if (!isset($exhibitsById[$exhibit_id])) {
                    continue;
                }
                $old = $exhibitsById[$exhibit_id];

                $new_item_description = trim($descriptions[$exhibit_id] ?? $old['item_description']);
                $new_exhibit_type_id  = intval($exhibitTypePosts[$exhibit_id] ?? $old['exhibit_type_id']);
                $new_urgency          = in_array($urgencyPosts[$exhibit_id] ?? '', $valid_urgencies, true)
                    ? $urgencyPosts[$exhibit_id] : $old['urgency'];
                $new_bag_number       = strtoupper(trim($bagNumbers[$exhibit_id] ?? $old['bag_number']));
                $new_delivered_by     = trim($deliveredBys[$exhibit_id] ?? $old['delivered_by']);
                $new_location_id      = intval($locationPosts[$exhibit_id] ?? 0);

                if (empty($new_exhibit_type_id) || empty($new_location_id) || empty($new_delivered_by)) {
                    $message = "Please fill in Exhibit Type, Location, and Delivered By for exhibit '{$old['exhibit_ref']}'.";
                    break;
                }
                if (strlen($new_bag_number) > 50 || strlen($new_item_description) > 255 || strlen($new_delivered_by) > 255) {
                    $message = "Bag Number exceeds 50 characters, or Description/Delivered By exceeds 255 characters for exhibit '{$old['exhibit_ref']}'.";
                    break;
                }

                $updateStmt = $conn->prepare("
                    UPDATE exhibits
                    SET time_out = NULL, item_description = ?, exhibit_type_id = ?, urgency = ?,
                        bag_number = ?, delivered_by = ?, location_id = ?
                    WHERE exhibit_id = ?
                ");
                $updateStmt->bind_param(
                    "sisssii",
                    $new_item_description,
                    $new_exhibit_type_id,
                    $new_urgency,
                    $new_bag_number,
                    $new_delivered_by,
                    $new_location_id,
                    $exhibit_id
                );
                if (!$updateStmt->execute()) {
                    $message = "Error updating exhibit ID $exhibit_id: " . $updateStmt->error;
                    $updateStmt->close();
                    break;
                }
                $updateStmt->close();

                $newLocationName = '';
                foreach ($locations as $loc) {
                    if ($loc['location_id'] == $new_location_id) {
                        $newLocationName = $loc['location_name'];
                        break;
                    }
                }

                // Field-level diff, same convention as edit_exhibit.php - plus
                // the return-event facts, which aren't a "field" on the
                // exhibit itself.
                $changes = [
                    'returned_by' => $returnedBy,
                    'returned_at' => $returnTime,
                    'location'    => $newLocationName,
                ];
                if ($new_item_description !== ($old['item_description'] ?? '')) {
                    $changes['Item Description'] = ['old' => $old['item_description'], 'new' => $new_item_description];
                }
                if ($new_bag_number !== ($old['bag_number'] ?? '')) {
                    $changes['Bag Number'] = ['old' => $old['bag_number'], 'new' => $new_bag_number];
                }
                if ($new_delivered_by !== ($old['delivered_by'] ?? '')) {
                    $changes['Delivered By'] = ['old' => $old['delivered_by'], 'new' => $new_delivered_by];
                }
                if ((int) $new_exhibit_type_id !== (int) $old['exhibit_type_id']) {
                    $oldType = $newType = '';
                    foreach ($exhibitTypes as $et) {
                        if ($et['exhibit_type_id'] == $old['exhibit_type_id']) {
                            $oldType = $et['type_name'];
                        }
                        if ($et['exhibit_type_id'] == $new_exhibit_type_id) {
                            $newType = $et['type_name'];
                        }
                    }
                    $changes['Exhibit Type'] = ['old' => $oldType, 'new' => $newType];
                }
                if ($new_urgency !== $old['urgency']) {
                    $changes['Urgency'] = ['old' => $old['urgency'], 'new' => $new_urgency];
                }

                $changedBy = (int) $_SESSION['user_id'];
                if (!insert_history_row($conn, 'exhibit_history', $exhibit_id, 'RETURN', $changedBy, json_encode($changes))) {
                    $message = "Error adding history for exhibit ID $exhibit_id: " . $conn->error;
                    break;
                }

                $updated_ids[] = $exhibit_id;
                $receiptRows[] = [
                    'exhibit_id'       => $exhibit_id,
                    'exhibit_ref'      => $old['exhibit_ref'] ?? '',
                    'item_description' => $new_item_description,
                    'time_out'         => $old['time_out'] ?? '',
                    'returned_at'      => $returnTime,
                    'location_name'    => $newLocationName,
                ];
            }

            if (empty($message) && !empty($updated_ids)) {
                $jobStmt = $conn->prepare("SELECT custom_ref FROM jobs WHERE job_id = ?");
                $jobStmt->bind_param("i", $job_id);
                $jobStmt->execute();
                $jobStmt->bind_result($jobCustomRef);
                $jobStmt->fetch();
                $jobStmt->close();

                $receiptId = save_exhibit_receipt_with_rows($conn, $job_id, 'return', $receiptRows, (string) $jobCustomRef, (int) $_SESSION['user_id'], $returnedBy);
                $receiptURL = $receiptId ? "view_receipt.php?receipt_id=" . urlencode($receiptId) : null;

                // A real link avoids popup-blocker issues window.open() would hit here.
                include('../header.php');
                ?>
                <div class="content-wrapper" style="max-width:500px; margin:150px auto 20px; text-align:center;">
                    <h2>Exhibit(s) Booked Back In</h2>
                    <p>The exhibit(s) were checked back in.</p>
                    <?php if ($receiptURL): ?>
                    <p>
                        <a href="<?php echo htmlspecialchars($receiptURL); ?>" target="_blank"
                            style="display:inline-block; padding:5px 10px; background:var(--polaris-accent); color:var(--polaris-text); border-radius:3px; font-size:14px; text-decoration:none; margin-bottom:10px;">
                            View / Print Receipt
                        </a>
                    </p>
                    <?php endif; ?>
                    <p>
                        <a href="job.php?job_id=<?php echo (int) $job_id; ?>"
                            style="display:inline-block; padding:5px 10px; background:var(--polaris-accent); color:var(--polaris-text); border-radius:3px; font-size:14px; text-decoration:none;">
                            Continue to Case
                        </a>
                    </p>
                </div>
                </body>
                </html>
                <?php
                exit();
            } elseif (empty($message)) {
                $message = "No exhibits selected.";
            }
        }
    }
}

include('../header.php');
?>

<style>
    .container {
        max-width: 1100px;
        margin: 160px auto 40px auto;
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 5px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
    }

    h2 {
        text-align: center;
        margin-bottom: 20px;
    }

    .message {
        text-align: center;
        margin-bottom: 20px;
        padding: 10px;
        border-radius: 5px;
        font-size: 16px;
    }

    .error {
        background-color: var(--polaris-error-bg);
        color: var(--polaris-error-text);
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
        table-layout: auto;
    }

    table th,
    table td {
        border: 1px solid var(--polaris-border);
        padding: 8px;
        text-align: left;
        font-size: 14px;
    }

    table th {
        background: var(--polaris-divider);
    }

    input[type="text"],
    select {
        width: 100%;
        min-width: 110px;
        padding: 8px;
        border: 1px solid var(--polaris-border);
        border-radius: 4px;
        background: var(--polaris-surface-deep);
        color: var(--polaris-text);
    }

    .form-field {
        margin-bottom: 15px;
    }

    .form-field label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
        color: var(--polaris-text-dim);
    }

    .button-group {
        display: flex;
        gap: 10px;
        margin-top: 20px;
    }

    .button-group button {
        flex: 1;
        padding: 5px 10px;
        font-size: 14px;
        border: none;
        border-radius: 3px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        cursor: pointer;
        transition: background 0.3s ease;
    }

    .button-group button:hover {
        background: var(--polaris-accent-hover);
    }

    a {
        color: var(--polaris-text-dim);
        text-decoration: underline;
    }
    </style>

    <div class="container">
        <h2>Book In Exhibits</h2>
        <?php if (!empty($message)): ?>
        <p class="message error"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <?php if (!empty($exhibits)): ?>
        <form method="post" action="book_in_exhibits.php?job_id=<?php echo $job_id; ?>">
            <table>
                <tr>
                    <th>Select</th>
                    <th>Exhibit Ref</th>
                    <th>Description</th>
                    <th>Exhibit Type</th>
                    <th>Urgency</th>
                    <th>Bag Number</th>
                    <th>Delivered By</th>
                    <th>Location</th>
                    <th>Time Out</th>
                </tr>
                <?php foreach ($exhibits as $exhibit): ?>
                <tr>
                    <td><input type="checkbox" name="exhibits[]" value="<?php echo $exhibit['exhibit_id']; ?>"></td>
                    <td><?php echo htmlspecialchars($exhibit['exhibit_ref']); ?></td>
                    <td>
                        <input type="text" name="item_description[<?php echo $exhibit['exhibit_id']; ?>]"
                            value="<?php echo htmlspecialchars($exhibit['item_description']); ?>">
                    </td>
                    <td>
                        <select name="exhibit_type[<?php echo $exhibit['exhibit_id']; ?>]">
                            <?php foreach ($exhibitTypes as $et): ?>
                            <option value="<?php echo $et['exhibit_type_id']; ?>"
                                <?php echo ($et['exhibit_type_id'] == $exhibit['exhibit_type_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($et['type_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="urgency[<?php echo $exhibit['exhibit_id']; ?>]">
                            <?php foreach ($valid_urgencies as $u): ?>
                            <option value="<?php echo $u; ?>" <?php echo ($exhibit['urgency'] === $u) ? 'selected' : ''; ?>>
                                <?php echo $u; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="text" name="bag_number[<?php echo $exhibit['exhibit_id']; ?>]"
                            value="<?php echo htmlspecialchars($exhibit['bag_number']); ?>"
                            oninput="this.value=this.value.toUpperCase();">
                    </td>
                    <td>
                        <input type="text" name="delivered_by[<?php echo $exhibit['exhibit_id']; ?>]"
                            value="<?php echo htmlspecialchars($exhibit['delivered_by']); ?>">
                    </td>
                    <td>
                        <select name="location[<?php echo $exhibit['exhibit_id']; ?>]">
                            <?php foreach ($locations as $loc): ?>
                            <option value="<?php echo $loc['location_id']; ?>"
                                <?php echo ($loc['location_id'] == $exhibit['location_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($loc['location_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><?php echo htmlspecialchars($exhibit['time_out']); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <div class="form-field">
                <label>Returned By:</label>
                <input type="text" name="returned_by"
                    placeholder="Enter the name of the person returning the exhibits" required>
            </div>
            <div class="button-group">
                <button type="submit">Book In</button>
                <button type="button"
                    onclick="window.location.href='job.php?job_id=<?php echo $job_id; ?>'">Cancel</button>
            </div>
        </form>
        <?php else: ?>
        <p>No exhibits are currently booked out for this job.</p>
        <p><a href="job.php?job_id=<?php echo $job_id; ?>">Return to Job</a></p>
        <?php endif; ?>
    </div>
</body>

</html>
