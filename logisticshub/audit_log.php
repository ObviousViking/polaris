<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once '../includes/permissions.php';
require_permission($conn, 'asset_view');
$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    require_once('../header.php');
}

$asset_id = isset($_GET['asset_id']) ? intval($_GET['asset_id']) : 0;
$scopedAsset = null;

if ($asset_id > 0) {
    $stmt = $conn->prepare("SELECT asset_number, friendly_name FROM assets WHERE id = ?");
    $stmt->bind_param("i", $asset_id);
    $stmt->execute();
    $scopedAsset = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$historyRecords = [];
if ($scopedAsset) {
    $histQuery = $conn->prepare("SELECT changed_at, action, changed_by, changes FROM asset_history WHERE asset_id = ? ORDER BY changed_at DESC");
    $histQuery->bind_param("i", $asset_id);
    $histQuery->execute();
    $histResult = $histQuery->get_result();
    while ($row = $histResult->fetch_assoc()) {
        $historyRecords[] = $row;
    }
    $histQuery->close();
} else {
    // System-wide log: most recent events across every asset.
    $histResult = $conn->query("
        SELECT h.asset_id, h.changed_at, h.action, h.changed_by, h.changes, a.asset_number, a.friendly_name
        FROM asset_history h
        LEFT JOIN assets a ON a.id = h.asset_id
        ORDER BY h.changed_at DESC
        LIMIT 200
    ");
    while ($row = $histResult->fetch_assoc()) {
        $historyRecords[] = $row;
    }
}

// Resolve changed_by ids to names in one pass.
$userNames = [];
$res = $conn->query("SELECT id, CONCAT(first_name, ' ', last_name) AS full_name FROM users");
while ($row = $res->fetch_assoc()) {
    $userNames[(int) $row['id']] = $row['full_name'];
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

    .history-changes p {
        margin: 0 0 6px 0;
        line-height: 1.4;
    }

    .history-changes strong {
        display: inline-block;
        width: 140px;
    }

    .change-box {
        background: var(--polaris-divider);
        padding: 6px 8px;
        margin: 3px 0;
        border-radius: 4px;
        border-left: 3px solid var(--polaris-border-hover-2);
    }

    .badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 11px;
        text-transform: uppercase;
        background: var(--polaris-divider);
        color: var(--polaris-text-secondary);
        white-space: nowrap;
    }

    .empty-message {
        color: var(--polaris-text-muted);
        font-style: italic;
        padding: 10px 0;
    }

    .action-btn {
        display: inline-block;
        padding: 6px 12px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border-radius: 3px;
        text-decoration: none;
        font-size: 13px;
        margin-top: 15px;
    }

    .action-btn:hover {
        background: var(--polaris-accent-hover);
    }
</style>

<div class="content-wrapper">
    <?php if ($scopedAsset): ?>
    <h2>History for Asset: <?php echo htmlspecialchars($scopedAsset['asset_number'] . ' - ' . $scopedAsset['friendly_name']); ?></h2>
    <?php else: ?>
    <h2>Asset Audit Log</h2>
    <p style="color: var(--polaris-text-secondary); font-size: 13px;">Most recent 200 events across all assets.</p>
    <?php endif; ?>

    <?php if (empty($historyRecords)): ?>
    <p class="empty-message">No history available.</p>
    <?php else: ?>
    <div class="table-scroll">
        <table>
            <tr>
                <th>Timestamp</th>
                <?php if (!$scopedAsset): ?>
                <th>Asset</th>
                <?php endif; ?>
                <th>Action</th>
                <th>Handled By</th>
                <th>Changes</th>
            </tr>
            <?php foreach ($historyRecords as $history): ?>
            <tr>
                <td><?php echo htmlspecialchars((string) $history['changed_at']); ?></td>
                <?php if (!$scopedAsset): ?>
                <td>
                    <?php if (!empty($history['asset_number'])): ?>
                    <a href="edit_asset.php?asset_number=<?php echo urlencode($history['asset_number']); ?>" target="_top">
                        <?php echo htmlspecialchars($history['asset_number'] . ' - ' . $history['friendly_name']); ?>
                    </a>
                    <?php else: ?>
                    <em>(deleted asset #<?php echo (int) $history['asset_id']; ?>)</em>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <td><span class="badge"><?php echo htmlspecialchars($history['action']); ?></span></td>
                <td><?php echo htmlspecialchars($userNames[(int) $history['changed_by']] ?? (string) $history['changed_by']); ?></td>
                <td class="history-changes">
                    <?php
                    $changesArray = json_decode($history['changes'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($changesArray)) {
                        foreach ($changesArray as $field => $values) {
                            if ($values === null || $values === '') {
                                continue;
                            }
                            if (is_array($values) && array_key_exists('old', $values) && array_key_exists('new', $values)) {
                                $oldValue = is_null($values['old']) ? '(none)' : (string) $values['old'];
                                $newValue = is_null($values['new']) ? '(none)' : (string) $values['new'];
                                echo "<p><strong>" . htmlspecialchars((string) $field) . ":</strong><br>";
                                echo "<div class='change-box'>Old: " . htmlspecialchars($oldValue) . "<br>New: " . htmlspecialchars($newValue) . "</div></p>";
                            } else {
                                $valStr = is_array($values) ? json_encode($values) : (string) $values;
                                echo "<p><strong>" . htmlspecialchars((string) $field) . ":</strong> " . htmlspecialchars($valStr) . "</p>";
                            }
                        }
                    } else {
                        echo "<p>Changes not available.</p>";
                    }
                    ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($scopedAsset): ?>
    <a href="edit_asset.php?asset_number=<?php echo urlencode($scopedAsset['asset_number']); ?>" class="action-btn">&larr; Back to Asset</a>
    <?php elseif (!$embedded): ?>
    <a href="lh_dashboard.php" class="action-btn">&larr; Back to Asset Management</a>
    <?php endif; ?>
</div>
