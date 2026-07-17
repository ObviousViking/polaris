<?php
session_start();
require_once('../db.php');

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

if (!isset($_GET['exhibit_id'])) {
    die("Exhibit ID not specified.");
}

$exhibit_id = intval($_GET['exhibit_id']);

// Fetch Exhibit Details
$stmt = $conn->prepare("SELECT exhibit_ref FROM exhibits WHERE exhibit_id = ?");
$stmt->bind_param("i", $exhibit_id);
$stmt->execute();
$stmt->bind_result($exhibit_ref);
if (!$stmt->fetch()) {
    die("Exhibit not found.");
}
$stmt->close();

// Fetch Exhibit History
$historyRecords = [];
$histQuery = $conn->prepare("SELECT changed_at, action, changed_by, changes FROM exhibit_history WHERE exhibit_id = ? ORDER BY changed_at DESC");
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
        'changes'    => $row['changes']
    ];
}
$histQuery->close();

// Include your normal header
include('../header.php');
?>

<style>
    /* header.php's own body{} already sets background/color/font-family
       page-wide. This page used to redeclare a second <!DOCTYPE html><html>
       <head> here with its own body{} - browsers tolerate the invalid
       nesting, but that stray rule won the cascade and bled its font into
       the real header/nav above it. Scoped to .container instead, and the
       header-clearance padding moves here too since body no longer carries it. */
    .container {
        max-width: 1200px;
        margin: 100px auto 0 auto;
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

    .change-box em {
        color: var(--polaris-text-muted);
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
        <h2>History for Exhibit: <?php echo htmlspecialchars($exhibit_ref); ?></h2>

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
                                    echo "<p><strong>" . htmlspecialchars($field) . ":</strong><br>";
                                    if ($field === 'Summary of Findings') {
                                        echo "<div class='change-box'><em>Old:</em><br>" . nl2br(htmlspecialchars($oldValue)) . "</div>";
                                        echo "<div class='change-box'><em>New:</em><br>" . nl2br(htmlspecialchars($newValue)) . "</div>";
                                    } else {
                                        echo "<div class='change-box'>Old: " . htmlspecialchars($oldValue) . "<br>New: " . htmlspecialchars($newValue) . "</div>";
                                    }
                                    echo "</p>";
                                } elseif (is_array($values)) {
                                    // A nested snapshot (e.g. a full before-state on delete) reads as
                                    // its own field:value list rather than one raw JSON blob.
                                    echo "<p><strong>" . htmlspecialchars($field) . ":</strong></p>";
                                    echo "<div class='change-box'>";
                                    foreach ($values as $subKey => $subVal) {
                                        if ($subVal === null || $subVal === '') {
                                            continue;
                                        }
                                        $subValStr = is_array($subVal) ? json_encode($subVal) : (string) $subVal;
                                        echo htmlspecialchars((string) $subKey) . ": " . htmlspecialchars($subValStr) . "<br>";
                                    }
                                    echo "</div>";
                                } else {
                                    if ($values === null || $values === '') {
                                        continue;
                                    }
                                    echo "<p><strong>" . htmlspecialchars($field) . ":</strong> " . htmlspecialchars((string) $values) . "</p>";
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
        <p class="no-history">No history available for this exhibit.</p>
        <?php endif; ?>

        <a href="edit_exhibit.php?exhibit_id=<?php echo $exhibit_id; ?>" class="back-btn">Back to Edit Exhibit</a>
    </div>
</body>

</html>