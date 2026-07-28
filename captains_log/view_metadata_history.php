<?php
// view_metadata_history.php - change log for one exhibit's shared metadata
// pool values. Unlike exhibit_process_history (a full snapshot per save),
// each row here is a single field-level change - see manage_exhibit_process.php's
// write-through and includes/integrity.php for the tamper-evident chain.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/permissions.php';
require_permission($conn, 'examination_view');

$exhibit_id = isset($_GET['exhibit_id']) ? intval($_GET['exhibit_id']) : 0;
if ($exhibit_id <= 0) {
    die("Exhibit not specified.");
}

$exStmt = $conn->prepare("SELECT exhibit_ref FROM exhibits WHERE exhibit_id = ?");
$exStmt->bind_param("i", $exhibit_id);
$exStmt->execute();
$exStmt->bind_result($exhibit_ref);
if (!$exStmt->fetch()) {
    die("Exhibit not found.");
}
$exStmt->close();

$historyRecords = [];
$histQuery = $conn->prepare("SELECT changed_at, action, changed_by, changes FROM exhibit_metadata_history WHERE exhibit_id = ? ORDER BY changed_at DESC, history_id DESC");
$histQuery->bind_param("i", $exhibit_id);
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
        'changes'    => json_decode($row['changes'], true),
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
        min-width: 100px;
    }

    .value-change {
        font-family: 'Courier New', monospace;
        font-size: 13px;
    }

    .value-change .old-value {
        color: var(--polaris-danger-alt);
        text-decoration: line-through;
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
    <h2>Metadata History</h2>
    <p class="subtitle">Change log for exhibit <?php echo htmlspecialchars($exhibit_ref); ?>'s shared metadata pool
        values - every process that includes one of these fields writes here, whichever process it was entered
        against.</p>

    <?php if (empty($historyRecords)): ?>
    <p class="no-history">No metadata pool changes recorded yet.</p>
    <?php else: ?>
    <?php foreach ($historyRecords as $h): ?>
    <div class="history-entry">
        <div class="history-meta">
            <span class="action-badge <?php echo $h['action'] === 'CREATE' ? 'badge-create' : 'badge-update'; ?>">
                <?php echo htmlspecialchars($h['action']); ?>
            </span>
            <?php echo htmlspecialchars($h['changed_by']); ?> &middot; <?php echo htmlspecialchars($h['changed_at']); ?>
        </div>
        <?php $c = $h['changes']; ?>
        <?php if (is_array($c)): ?>
        <div class="snapshot-field"><strong><?php echo htmlspecialchars($c['field_label'] ?? $c['field_key'] ?? 'Field'); ?>:</strong>
            <span class="value-change">
                <?php if (!empty($c['old_value'])): ?>
                <span class="old-value"><?php echo htmlspecialchars($c['old_value']); ?></span> &rarr;
                <?php endif; ?>
                <?php echo $c['new_value'] !== '' && $c['new_value'] !== null ? htmlspecialchars($c['new_value']) : '<em>(cleared)</em>'; ?>
            </span>
        </div>
        <?php if (!empty($c['process'])): ?>
        <div class="snapshot-field"><strong>Via process:</strong> <?php echo htmlspecialchars($c['process']); ?></div>
        <?php endif; ?>
        <?php else: ?>
        <p>No details available.</p>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <a href="examination.php?exhibit_id=<?php echo $exhibit_id; ?>" class="back-btn">&larr; Back to Examine</a>
</div>

</body>

</html>
