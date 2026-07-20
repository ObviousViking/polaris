<?php
// view_process_history.php - full version history for one exhibit process
// record. Each row is a complete snapshot of what the record looked like at that point.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/permissions.php';
require_permission($conn, 'examination_view');

$exhibit_process_id = isset($_GET['exhibit_process_id']) ? intval($_GET['exhibit_process_id']) : 0;
if ($exhibit_process_id <= 0) {
    die("Process record not specified.");
}

$epStmt = $conn->prepare("
    SELECT ep.exhibit_id, pt.name AS process_name, e.exhibit_ref
    FROM exhibit_processes ep
    JOIN process_types pt ON ep.process_type_id = pt.id
    JOIN exhibits e ON ep.exhibit_id = e.exhibit_id
    WHERE ep.id = ?
");
$epStmt->bind_param("i", $exhibit_process_id);
$epStmt->execute();
$ep = $epStmt->get_result()->fetch_assoc();
$epStmt->close();

if (!$ep) {
    die("Process record not found.");
}

$historyRecords = [];
$histQuery = $conn->prepare("SELECT changed_at, action, changed_by, changes FROM exhibit_process_history WHERE exhibit_process_id = ? ORDER BY changed_at DESC");
$histQuery->bind_param("i", $exhibit_process_id);
$histQuery->execute();
$histResult = $histQuery->get_result();
while ($row = $histResult->fetch_assoc()) {
    $changedByName = $row['changed_by'];
    $resUser = $conn->query("SELECT CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE id = " . intval($row['changed_by']));
    if ($resUser && $userRow = $resUser->fetch_assoc()) {
        $changedByName = $userRow['full_name'];
    }
    $historyRecords[] = [
        'changed_at' => $row['changed_at'],
        'action'     => $row['action'],
        'changed_by' => $changedByName,
        'changes'    => $row['changes'],
    ];
}
$histQuery->close();

include '../header.php';
?>
<style>
    .container {
        max-width: 900px;
        margin: 120px auto 40px auto;
        background: var(--polaris-surface);
        padding: 30px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        color: var(--polaris-gray-e0);
    }

    h2 {
        font-size: 1.6em;
        margin-bottom: 4px;
        color: var(--polaris-text);
    }

    .subtitle {
        color: var(--polaris-text-faint);
        margin-bottom: 20px;
    }

    .history-entry {
        background: var(--polaris-surface-deep);
        border-left: 3px solid var(--polaris-accent);
        border-radius: 4px;
        padding: 15px;
        margin-bottom: 15px;
    }

    .history-meta {
        font-size: 13px;
        color: var(--polaris-text-muted);
        margin-bottom: 10px;
    }

    .action-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 12px;
        font-weight: bold;
        margin-right: 8px;
    }

    .badge-create {
        background: #1b4d2e;
        color: #7ee2a8;
    }

    .badge-update {
        background: #1a3d5c;
        color: #7ec4f2;
    }

    .snapshot-field {
        font-size: 14px;
        margin: 4px 0;
    }

    .snapshot-field strong {
        color: var(--polaris-text-placeholder);
        display: inline-block;
        min-width: 160px;
    }

    .no-history {
        font-style: italic;
        color: var(--polaris-text-faint);
        padding: 20px;
        text-align: center;
    }

    .back-btn {
        display: inline-block;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        padding: 5px 10px;
        text-decoration: none;
        border-radius: 3px;
        font-size: 14px;
        margin-top: 15px;
    }

    .back-btn:hover {
        background: var(--polaris-accent-hover);
    }
</style>

<div class="container">
    <h2><?php echo htmlspecialchars($ep['process_name']); ?></h2>
    <p class="subtitle">Version history for exhibit <?php echo htmlspecialchars($ep['exhibit_ref']); ?> - each entry
        below is the full state of the record immediately before that change was made.</p>

    <?php if (empty($historyRecords)): ?>
    <p class="no-history">No history yet.</p>
    <?php else: ?>
    <?php foreach ($historyRecords as $h): ?>
    <div class="history-entry">
        <div class="history-meta">
            <span class="action-badge <?php echo $h['action'] === 'CREATE' ? 'badge-create' : 'badge-update'; ?>">
                <?php echo htmlspecialchars($h['action']); ?>
            </span>
            <?php echo htmlspecialchars($h['changed_by']); ?> &middot; <?php echo htmlspecialchars($h['changed_at']); ?>
        </div>
        <?php
        $snapshot = json_decode($h['changes'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($snapshot)) {
            foreach ($snapshot as $label => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                echo '<div class="snapshot-field"><strong>' . htmlspecialchars((string) $label) . ':</strong> ' . nl2br(htmlspecialchars((string) $value)) . '</div>';
            }
        } else {
            echo '<p>No details available.</p>';
        }
        ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <a href="examination.php?exhibit_id=<?php echo (int) $ep['exhibit_id']; ?>" class="back-btn">&larr; Back to Examine</a>
</div>

</body>

</html>
