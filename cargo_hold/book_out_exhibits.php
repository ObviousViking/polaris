<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once('../includes/integrity.php');

// Ensure a job_id is provided.
if (!isset($_GET['job_id'])) {
    die("Job ID not specified.");
}
$job_id = intval($_GET['job_id']);

// Query only exhibits for this job that have not been checked out (time_out IS NULL)
$stmt = $conn->prepare("SELECT exhibit_id, exhibit_ref, item_description, bag_number, time_in, delivered_by
                        FROM exhibits
                        WHERE job_id = ? AND time_out IS NULL AND deleted_at IS NULL
                        ORDER BY time_in DESC");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$result = $stmt->get_result();

$exhibits = [];
while ($row = $result->fetch_assoc()) {
    $exhibits[] = $row;
}
$stmt->close();

$message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get selected exhibit IDs (as an array) and the "Booked Out To" value.
    if (!isset($_POST['exhibits']) || !is_array($_POST['exhibits'])) {
        $message = "No exhibits selected.";
    } else {
        $selectedExhibits = $_POST['exhibits'];
        $bookedOutTo = trim($_POST['booked_out_to']); // This is used for the receipt only.
        
        if (empty($bookedOutTo)) {
            $message = "Please enter the name of the person to book out to.";
        } else {
            // Look up descriptive details for each selected exhibit, for the audit record.
            $exhibitsById = [];
            foreach ($exhibits as $ex) {
                $exhibitsById[$ex['exhibit_id']] = $ex;
            }

            // Update each selected exhibit: set time_out to the current timestamp.
            $timeOut = date('Y-m-d H:i:s');
            $updated_ids = [];
            $updateStmt = $conn->prepare("UPDATE exhibits SET time_out = ? WHERE exhibit_id = ?");
            if (!$updateStmt) {
                die("Prepare failed: " . $conn->error);
            }
            $changedBy = $_SESSION['user_id'];
            foreach ($selectedExhibits as $exhibit_id) {
                $exhibit_id = intval($exhibit_id);
                $updateStmt->bind_param("si", $timeOut, $exhibit_id);
                if (!$updateStmt->execute()) {
                    $message = "Error updating exhibit ID $exhibit_id: " . $updateStmt->error;
                    break;
                }

                $changesJSON = json_encode([
                    'exhibit_ref'   => $exhibitsById[$exhibit_id]['exhibit_ref'] ?? '',
                    'booked_out_to' => $bookedOutTo,
                    'time_out'      => $timeOut,
                ]);
                if (!insert_history_row($conn, 'exhibit_history', $exhibit_id, 'BOOK_OUT', $changedBy, $changesJSON)) {
                    $message = "Error adding history for exhibit ID $exhibit_id: " . $conn->error;
                    break;
                }

                $updated_ids[] = $exhibit_id;
            }
            $updateStmt->close();
            if (empty($message)) {
                $ids = implode(',', $updated_ids);
                $receiptURL = "exhibit_receipt.php?ids=" . urlencode($ids) .
              "&job_id=" . urlencode($job_id) .
              "&type=out" .
              "&booked_out_to=" . urlencode($bookedOutTo);

                // A JS window.open() here would run from a script on page load
                // (the response to this POST) rather than synchronously inside a
                // click handler, which most browsers' popup blockers silently
                // block. A real link the user clicks always works instead.
                include('../header.php');
                ?>
                <div class="content-wrapper" style="max-width:500px; margin:150px auto 20px; text-align:center;">
                    <h2>Exhibit(s) Booked Out</h2>
                    <p>The exhibit(s) were booked out to <?php echo htmlspecialchars($bookedOutTo); ?>.</p>
                    <p>
                        <a href="<?php echo htmlspecialchars($receiptURL); ?>" target="_blank"
                            style="display:inline-block; padding:5px 10px; background:var(--polaris-accent); color:var(--polaris-text); border-radius:3px; font-size:14px; text-decoration:none; margin-bottom:10px;">
                            View / Print Receipt
                        </a>
                    </p>
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
            }
        }
    }
}

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
        max-width: 1000px;
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
        text-align: center;
        font-size: 14px;
    }

    table th {
        background: var(--polaris-divider);
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

    .form-field input[type="text"] {
        width: 100%;
        padding: 8px;
        border: 1px solid var(--polaris-border);
        border-radius: 4px;
        background: var(--polaris-surface-deep);
        color: var(--polaris-text);
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
        <h2>Book Out Exhibits</h2>
        <?php if (!empty($message)): ?>
        <p class="message error"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <?php if (!empty($exhibits)): ?>
        <form method="post" action="book_out_exhibits.php?job_id=<?php echo $job_id; ?>">
            <table>
                <tr>
                    <th>Select</th>
                    <th>Exhibit Ref</th>
                    <th>Description</th>
                    <th>Bag Number</th>
                    <th>Time In</th>
                </tr>
                <?php foreach ($exhibits as $exhibit): ?>
                <tr>
                    <td><input type="checkbox" name="exhibits[]" value="<?php echo $exhibit['exhibit_id']; ?>"></td>
                    <td><?php echo htmlspecialchars($exhibit['exhibit_ref']); ?></td>
                    <td><?php echo htmlspecialchars($exhibit['item_description']); ?></td>
                    <td><?php echo htmlspecialchars($exhibit['bag_number']); ?></td>
                    <td><?php echo htmlspecialchars($exhibit['time_in']); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <div class="form-field">
                <label>Booked Out To:</label>
                <input type="text" name="booked_out_to"
                    placeholder="Enter the name of the person receiving the exhibits" required>
            </div>
            <div class="button-group">
                <button type="submit">Book Out</button>
                <button type="button"
                    onclick="window.location.href='job.php?job_id=<?php echo $job_id; ?>'">Cancel</button>
            </div>
        </form>
        <?php else: ?>
        <p>No exhibits available for book-out.</p>
        <p><a href="job.php?job_id=<?php echo $job_id; ?>">Return to Job</a></p>
        <?php endif; ?>
    </div>
</body>

</html>