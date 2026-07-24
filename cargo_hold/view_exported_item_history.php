<?php
session_start();
require_once('../db.php');

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../includes/permissions.php';
require_permission($conn, 'exhibit_view');

if (!isset($_GET['item_id'])) {
    die("Item ID not specified.");
}

$item_id = intval($_GET['item_id']);

// Fetch Item Details
$stmt = $conn->prepare("SELECT extraction_ref, job_id FROM exported_items WHERE item_id = ?");
$stmt->bind_param("i", $item_id);
$stmt->execute();
$stmt->bind_result($extraction_ref, $job_id);
if (!$stmt->fetch()) {
    die("Exported item not found.");
}
$stmt->close();

// Fetch History
$historyRecords = [];
$histQuery = $conn->prepare("SELECT changed_at, action, changed_by, changes FROM exported_item_history WHERE item_id = ? ORDER BY changed_at DESC");
$histQuery->bind_param("i", $item_id);
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
        'changes'    => $row['changes']
    ];
}
$histQuery->close();

include('../header.php');
?>

<style>
    .container {
        max-width: 1200px;
        margin: 130px auto 40px auto;
        background: var(--polaris-surface);
        padding: 30px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        color: var(--polaris-gray-e0);
        font-family: 'Segoe UI', Arial, sans-serif;
    }

    h2 {
        font-size: 1.8em;
        margin-bottom: 20px;
        color: var(--polaris-text);
        border-bottom: 2px solid var(--polaris-border-hover);
        padding-bottom: 10px;
    }

    table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        margin-top: 20px;
        background: var(--polaris-divider);
        border-radius: 8px;
        overflow: hidden;
    }

    th,
    td {
        padding: 12px 15px;
        text-align: left;
        border-bottom: 1px solid var(--polaris-border);
    }

    th {
        background: var(--polaris-panel-alt);
        color: var(--polaris-text);
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.9em;
    }

    td {
        background: var(--polaris-surface-alt);
        font-size: 0.95em;
    }

    tr:last-child td {
        border-bottom: none;
    }

    tr:hover td {
        background: var(--polaris-panel-alt);
        transition: background 0.2s ease;
    }

    .history-changes p {
        margin: 0 0 8px 0;
        line-height: 1.5;
    }

    .history-changes strong {
        display: inline-block;
        width: 160px;
        color: var(--polaris-text-placeholder);
    }

    .change-box {
        background: var(--polaris-panel-alt);
        padding: 10px;
        margin: 5px 0;
        border-radius: 4px;
        border-left: 3px solid var(--polaris-border-hover-2);
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
        margin-top: 20px;
        font-size: 14px;
        transition: background 0.3s ease;
    }

    .back-btn:hover {
        background: var(--polaris-accent-hover);
    }

    @media (max-width: 768px) {
        .container {
            padding: 15px;
            margin: 0 10px;
        }

        table {
            font-size: 0.9em;
        }

        th,
        td {
            padding: 10px;
        }

        .history-changes strong {
            width: 120px;
        }
    }
    </style>

    <div class="container">
        <h2>History for Exported Item: <?php echo htmlspecialchars($extraction_ref); ?></h2>

        <?php if (!empty($historyRecords)): ?>
        <table>
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Action</th>
                    <th>Handled By</th>
                    <th>Changes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($historyRecords as $history): ?>
                <tr>
                    <td><?php echo htmlspecialchars($history['changed_at']); ?></td>
                    <td><?php echo htmlspecialchars($history['action']); ?></td>
                    <td><?php echo htmlspecialchars($history['changed_by']); ?></td>
                    <td class="history-changes">
                        <?php
                        $changesArray = json_decode($history['changes'], true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($changesArray)) {
                            foreach ($changesArray as $field => $values) {
                                if (is_array($values) && array_key_exists('old', $values) && array_key_exists('new', $values)) {
                                    $oldValue = is_null($values['old']) ? '(none)' : (string) $values['old'];
                                    $newValue = is_null($values['new']) ? '(none)' : (string) $values['new'];
                                    echo "<p><strong>" . htmlspecialchars((string) $field) . ":</strong><br>";
                                    echo "<div class='change-box'>Old: " . htmlspecialchars($oldValue) . "<br>New: " . htmlspecialchars($newValue) . "</div>";
                                    echo "</p>";
                                } else {
                                    if ($values === null || $values === '') {
                                        continue;
                                    }
                                    echo "<p><strong>" . htmlspecialchars((string) $field) . ":</strong> " . htmlspecialchars((string) $values) . "</p>";
                                }
                            }
                        } else {
                            echo "<p>Changes not available.</p>";
                        }
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="no-history">No history available for this item.</p>
        <?php endif; ?>

        <a href="job.php?job_id=<?php echo $job_id; ?>" class="back-btn">Back to Case</a>
    </div>
</body>

</html>
