<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once('../includes/integrity.php');
require_once '../includes/permissions.php';
require_permission($conn, 'asset_maintenance');
$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    require_once('../header.php');
}

$eventTypes = ['Maintenance', 'Calibration', 'Verification', 'Repair', 'Inspection'];
$results = ['Pass', 'Fail', 'N/A'];

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_maintenance'])) {
    $asset_id = intval($_POST['asset_id']);
    $event_type = trim($_POST['event_type'] ?? '');
    $performed_at = trim($_POST['performed_at'] ?? '');
    $performed_by = trim($_POST['performed_by'] ?? '');
    $result = trim($_POST['result'] ?? '');
    $next_due_at = trim($_POST['next_due_at'] ?? '');
    $certificate_reference = trim($_POST['certificate_reference'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $logged_by = (int) $_SESSION['user_id'];

    if ($asset_id === 0 || !in_array($event_type, $eventTypes, true) || $performed_at === '') {
        $message = "Please select an asset, event type, and date performed.";
    } else {
        $resultParam = in_array($result, $results, true) ? $result : null;
        $nextDueParam = $next_due_at !== '' ? $next_due_at : null;
        $performedByParam = $performed_by !== '' ? $performed_by : null;
        $certRefParam = $certificate_reference !== '' ? $certificate_reference : null;
        $notesParam = $notes !== '' ? $notes : null;

        $stmt = $conn->prepare("INSERT INTO asset_maintenance (asset_id, event_type, performed_at, performed_by, result, next_due_at, certificate_reference, notes, logged_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssssssi", $asset_id, $event_type, $performed_at, $performedByParam, $resultParam, $nextDueParam, $certRefParam, $notesParam, $logged_by);
        if ($stmt->execute()) {
            $changes = json_encode([
                'event_type' => $event_type,
                'performed_at' => $performed_at,
                'performed_by' => $performedByParam,
                'result' => $resultParam,
                'next_due_at' => $nextDueParam,
                'certificate_reference' => $certRefParam,
            ]);
            insert_history_row($conn, 'asset_history', $asset_id, 'MAINTENANCE', $logged_by, $changes);
            $message = "Maintenance record logged.";
        } else {
            $message = "Error logging record: " . $stmt->error;
        }
        $stmt->close();
    }
}

$assets = [];
$res = $conn->query("SELECT id, asset_number, friendly_name FROM assets WHERE deleted_at IS NULL ORDER BY asset_number");
while ($row = $res->fetch_assoc()) {
    $assets[] = $row;
}
$res->free();

// Most recent maintenance record per asset drives the "next due" column -
// the latest logged event's due date is what's currently in effect.
$records = [];
$res = $conn->query("
    SELECT m.maintenance_id, m.event_type, m.performed_at, m.performed_by, m.result, m.next_due_at,
           m.certificate_reference, m.notes, a.asset_number, a.friendly_name,
           CONCAT(u.first_name, ' ', u.last_name) AS logged_by_name
    FROM asset_maintenance m
    JOIN assets a ON a.id = m.asset_id
    JOIN users u ON u.id = m.logged_by
    ORDER BY m.performed_at DESC, m.maintenance_id DESC
    LIMIT 200
");
while ($row = $res->fetch_assoc()) {
    $records[] = $row;
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

    .content-wrapper {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }

    h2 {
        margin-top: 0;
    }

    .card {
        background: var(--polaris-surface);
        border-radius: 5px;
        padding: 15px 20px;
        margin-bottom: 20px;
    }

    label {
        display: block;
        margin-top: 10px;
        font-weight: bold;
        color: var(--polaris-text-dim);
        font-size: 13px;
    }

    input[type="text"],
    input[type="date"],
    select,
    textarea {
        width: 100%;
        padding: 6px;
        margin-top: 4px;
        background: var(--polaris-bg);
        border: 1px solid var(--polaris-border);
        color: var(--polaris-text);
        border-radius: 3px;
        box-sizing: border-box;
    }

    .form-row {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
    }

    .form-row>div {
        flex: 1 1 200px;
    }

    .action-btn {
        margin-top: 15px;
        padding: 6px 12px;
        border: none;
        border-radius: 3px;
        cursor: pointer;
        font-size: 13px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
    }

    .action-btn:hover {
        background: var(--polaris-accent-hover);
    }

    .message {
        margin-bottom: 15px;
        padding: 10px;
        background: var(--polaris-divider);
        border-left: 4px solid var(--polaris-accent);
    }

    .table-scroll {
        width: 100%;
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        background: var(--polaris-surface);
    }

    th,
    td {
        padding: 8px 10px;
        text-align: left;
        border-bottom: 1px solid var(--polaris-border);
        font-size: 13px;
        vertical-align: top;
    }

    th {
        background: var(--polaris-divider);
        white-space: nowrap;
    }

    .overdue {
        color: var(--polaris-danger);
        font-weight: bold;
    }

    .badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 11px;
        text-transform: uppercase;
        background: var(--polaris-divider);
        color: var(--polaris-text-secondary);
    }

    .badge-fail {
        background: var(--polaris-error-bg);
        color: var(--polaris-error-text);
    }

    .badge-pass {
        background: var(--polaris-success-bg);
        color: var(--polaris-success-text);
    }

    .empty-message {
        color: var(--polaris-text-muted);
        font-style: italic;
        padding: 10px 0;
    }
</style>

<div class="content-wrapper">
    <h2>Maintenance &amp; Calibration</h2>

    <?php if (!empty($message)): ?>
    <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="card">
        <h3>Log an Event</h3>
        <form method="post">
            <input type="hidden" name="log_maintenance" value="1">
            <div class="form-row">
                <div>
                    <label for="asset_id">Asset</label>
                    <select name="asset_id" id="asset_id" required>
                        <option value="">Select asset</option>
                        <?php foreach ($assets as $a): ?>
                        <option value="<?php echo $a['id']; ?>">
                            <?php echo htmlspecialchars($a['asset_number'] . ' - ' . $a['friendly_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="event_type">Event Type</label>
                    <select name="event_type" id="event_type" required>
                        <option value="">Select type</option>
                        <?php foreach ($eventTypes as $t): ?>
                        <option value="<?php echo $t; ?>"><?php echo $t; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="performed_at">Date Performed</label>
                    <input type="date" name="performed_at" id="performed_at" required>
                </div>
                <div>
                    <label for="performed_by">Performed By</label>
                    <input type="text" name="performed_by" id="performed_by" placeholder="Person or company">
                </div>
                <div>
                    <label for="result">Result</label>
                    <select name="result" id="result">
                        <option value="">-</option>
                        <?php foreach ($results as $r): ?>
                        <option value="<?php echo $r; ?>"><?php echo $r; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="next_due_at">Next Due</label>
                    <input type="date" name="next_due_at" id="next_due_at">
                </div>
                <div>
                    <label for="certificate_reference">Certificate Reference</label>
                    <input type="text" name="certificate_reference" id="certificate_reference">
                </div>
            </div>
            <label for="notes">Notes</label>
            <textarea name="notes" id="notes" rows="2"></textarea>
            <button type="submit" class="action-btn">Log Event</button>
        </form>
    </div>

    <div class="card">
        <h3>Maintenance History</h3>
        <?php if (empty($records)): ?>
        <p class="empty-message">No maintenance records logged yet.</p>
        <?php else: ?>
        <div class="table-scroll">
            <table>
                <tr>
                    <th>Asset</th>
                    <th>Type</th>
                    <th>Performed</th>
                    <th>By</th>
                    <th>Result</th>
                    <th>Next Due</th>
                    <th>Certificate Ref</th>
                    <th>Logged By</th>
                    <th>Notes</th>
                </tr>
                <?php foreach ($records as $r):
                    $isOverdue = $r['next_due_at'] !== null && strtotime($r['next_due_at']) < time();
                    $resultClass = $r['result'] === 'Fail' ? 'badge-fail' : ($r['result'] === 'Pass' ? 'badge-pass' : '');
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['asset_number'] . ' - ' . $r['friendly_name']); ?></td>
                    <td><?php echo htmlspecialchars($r['event_type']); ?></td>
                    <td><?php echo date('d/m/Y', strtotime($r['performed_at'])); ?></td>
                    <td><?php echo htmlspecialchars($r['performed_by'] ?? '-'); ?></td>
                    <td><?php echo $r['result'] ? '<span class="badge ' . $resultClass . '">' . htmlspecialchars($r['result']) . '</span>' : '-'; ?></td>
                    <td class="<?php echo $isOverdue ? 'overdue' : ''; ?>">
                        <?php echo $r['next_due_at'] ? date('d/m/Y', strtotime($r['next_due_at'])) . ($isOverdue ? ' (overdue)' : '') : '-'; ?>
                    </td>
                    <td><?php echo htmlspecialchars($r['certificate_reference'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($r['logged_by_name']); ?></td>
                    <td><?php echo nl2br(htmlspecialchars($r['notes'] ?? '')); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$embedded): ?>
    <a href="lh_dashboard.php" class="action-btn">&larr; Back to Asset Management</a>
    <?php endif; ?>
</div>
