<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once('../includes/integrity.php');
require_once '../includes/permissions.php';
require_once '../includes/produced_item_receipts.php';
require_permission($conn, 'exhibit_edit');

// Ensure a job_id is provided.
if (!isset($_GET['job_id'])) {
    die("Job ID not specified.");
}
$job_id = intval($_GET['job_id']);

// Query only produced items for this job that are currently booked out.
$stmt = $conn->prepare("
    SELECT ci.item_id, ci.item_ref, ci.description, ci.file_count, ci.booked_out_to, ci.booked_out_at, t.type_name
    FROM case_items ci
    JOIN case_item_types t ON ci.type_id = t.type_id
    WHERE ci.job_id = ? AND ci.booked_out_at IS NOT NULL AND ci.returned_at IS NULL
    ORDER BY ci.item_ref
");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();

$message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['items']) || !is_array($_POST['items'])) {
        $message = "No items selected.";
    } else {
        $selectedItems = $_POST['items'];
        $returnedBy = trim($_POST['returned_by']);

        if (empty($returnedBy)) {
            $message = "Please enter who returned the item(s).";
        } else {
            $itemsById = [];
            foreach ($items as $it) {
                $itemsById[$it['item_id']] = $it;
            }

            // Captured once so every item in this batch (and the receipt)
            // shows the same "returned at" moment.
            $returnTime = date('Y-m-d H:i:s');
            $updated_ids = [];
            $receiptRows = [];

            $updateStmt = $conn->prepare("UPDATE case_items SET returned_at = ? WHERE item_id = ?");
            $changedBy = (int) $_SESSION['user_id'];
            foreach ($selectedItems as $item_id) {
                $item_id = intval($item_id);
                if (!isset($itemsById[$item_id])) {
                    continue;
                }
                $old = $itemsById[$item_id];

                $updateStmt->bind_param("si", $returnTime, $item_id);
                if (!$updateStmt->execute()) {
                    $message = "Error updating item ID $item_id: " . $updateStmt->error;
                    break;
                }

                $changesJSON = json_encode([
                    'item_ref'    => $old['item_ref'] ?? '',
                    'returned_by' => $returnedBy,
                    'returned_at' => $returnTime,
                ]);
                if (!insert_history_row($conn, 'case_item_history', $item_id, 'BOOK_IN', $changedBy, $changesJSON)) {
                    $message = "Error adding history for item ID $item_id: " . $conn->error;
                    break;
                }

                $updated_ids[] = $item_id;
                $receiptRows[] = [
                    'item_id'       => $item_id,
                    'item_ref'      => $old['item_ref'] ?? '',
                    'type_name'     => $old['type_name'] ?? '',
                    'description'   => $old['description'] ?? '',
                    'file_count'    => $old['file_count'],
                    'booked_out_at' => $old['booked_out_at'] ?? '',
                    'returned_at'   => $returnTime,
                ];
            }
            $updateStmt->close();

            if (empty($message) && !empty($updated_ids)) {
                $jobStmt = $conn->prepare("SELECT custom_ref FROM jobs WHERE job_id = ?");
                $jobStmt->bind_param("i", $job_id);
                $jobStmt->execute();
                $jobStmt->bind_result($jobCustomRef);
                $jobStmt->fetch();
                $jobStmt->close();

                $receiptId = save_produced_item_receipt_with_rows($conn, $job_id, 'in', $receiptRows, (string) $jobCustomRef, (int) $_SESSION['user_id'], $returnedBy);
                $receiptURL = $receiptId ? "view_produced_item_receipt.php?receipt_id=" . urlencode($receiptId) : null;

                include('../header.php');
                ?>
                <div class="content-wrapper" style="max-width:500px; margin:150px auto 20px; text-align:center;">
                    <h2>Produced Item(s) Booked Back In</h2>
                    <p>The item(s) were checked back in.</p>
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
                $message = "No items selected.";
            }
        }
    }
}

include('../header.php');
?>

<style>
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
        <h2>Book In Produced Items</h2>
        <?php if (!empty($message)): ?>
        <p class="message error"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <?php if (!empty($items)): ?>
        <form method="post" action="book_in_case_items.php?job_id=<?php echo $job_id; ?>">
            <table>
                <tr>
                    <th>Select</th>
                    <th>Item Ref</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th>No. of Files</th>
                    <th>Booked Out To</th>
                    <th>Booked Out At</th>
                </tr>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><input type="checkbox" name="items[]" value="<?php echo $item['item_id']; ?>"></td>
                    <td><?php echo htmlspecialchars($item['item_ref']); ?></td>
                    <td><?php echo htmlspecialchars($item['type_name']); ?></td>
                    <td><?php echo htmlspecialchars($item['description'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($item['file_count'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($item['booked_out_to'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($item['booked_out_at'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <div class="form-field">
                <label>Returned By:</label>
                <input type="text" name="returned_by"
                    placeholder="Enter the name of the person returning the item(s)" required>
            </div>
            <div class="button-group">
                <button type="submit">Book In</button>
                <button type="button"
                    onclick="window.location.href='job.php?job_id=<?php echo $job_id; ?>'">Cancel</button>
            </div>
        </form>
        <?php else: ?>
        <p>No produced items are currently booked out for this job.</p>
        <p><a href="job.php?job_id=<?php echo $job_id; ?>">Return to Job</a></p>
        <?php endif; ?>
    </div>
</body>

</html>
