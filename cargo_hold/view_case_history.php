<?php
session_start();
require_once('../db.php');

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

if (!isset($_GET['job_id'])) {
    die("Job ID not specified.");
}

$job_id = intval($_GET['job_id']);

// Fetch Case Details
$stmt = $conn->prepare("SELECT custom_ref FROM jobs WHERE job_id = ?");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$stmt->bind_result($custom_ref);
if (!$stmt->fetch()) {
    die("Case not found.");
}
$stmt->close();

// Fetch Case History
$historyRecords = [];
$histQuery = $conn->prepare("SELECT changed_at, action, changed_by, changes FROM case_history WHERE job_id = ? ORDER BY changed_at DESC");
$histQuery->bind_param("i", $job_id);
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

// Rich-text fields (e.g. a case update's Text) store HTML from the Quill
// editor - block-level tags become newlines first so multi-paragraph/list
// content doesn't run together as one wall of text, then tags are
// stripped, since this is a plain-text audit trail, not a rich-text
// viewer.
function history_plain_text($value)
{
    if ($value === null) {
        return '';
    }
    if (is_array($value)) {
        $value = json_encode($value);
    }
    $str = (string) $value;
    $str = preg_replace('/<(br|\/p|\/li|\/div|\/h[1-6])\s*\/?>/i', "\n", $str);
    return trim(strip_tags($str));
}

// Flattens a changes JSON blob (old/new pairs, one level of nested
// snapshot wrapper, or plain scalars - see add_update.php/edit_job.php/
// delete_update.php) into a flat list of {field, old, new} rows for a
// simple two/three-column table. "Update ID" and similar raw-ID fields are
// dropped - they don't help a reader, only a DB admin.
function flatten_history_changes(array $data, array &$rows = []): array
{
    foreach ($data as $field => $value) {
        if (preg_match('/\bid$/i', (string) $field)) {
            continue;
        }
        if (is_array($value) && array_key_exists('old', $value) && array_key_exists('new', $value)) {
            $rows[] = ['field' => $field, 'old' => $value['old'], 'new' => $value['new']];
        } elseif (is_array($value)) {
            flatten_history_changes($value, $rows);
        } else {
            if ($value === null || $value === '') {
                continue;
            }
            $rows[] = ['field' => $field, 'old' => null, 'new' => $value];
        }
    }
    return $rows;
}

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
        margin: 0;
        line-height: 1.5;
        color: var(--polaris-text-faint);
        font-style: italic;
    }

    .mini-changes-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.9em;
    }

    .mini-changes-table td {
        background: none;
        border: none;
        border-bottom: 1px solid var(--polaris-border);
        padding: 6px 8px;
        vertical-align: top;
    }

    .mini-changes-table tr:last-child td {
        border-bottom: none;
    }

    .mct-field {
        width: 120px;
        font-weight: 600;
        color: var(--polaris-text-placeholder);
        white-space: nowrap;
    }

    .mct-value {
        white-space: pre-line;
        word-break: break-word;
    }

    .mct-old {
        color: var(--polaris-text-faint);
        text-decoration: line-through;
    }

    .mct-arrow {
        margin: 0 6px;
        color: var(--polaris-text-muted);
    }

    .mct-new {
        color: var(--polaris-text);
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
        <h2>History for Case: <?php echo htmlspecialchars($custom_ref); ?></h2>

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
                        $rows = (json_last_error() === JSON_ERROR_NONE && is_array($changesArray))
                            ? flatten_history_changes($changesArray)
                            : [];
                        if (!empty($rows)) {
                            echo '<table class="mini-changes-table"><tbody>';
                            foreach ($rows as $row) {
                                $fieldLabel = htmlspecialchars((string) $row['field']);
                                $newDisplay = nl2br(htmlspecialchars(history_plain_text($row['new'])));
                                if ($row['old'] !== null) {
                                    $oldDisplay = nl2br(htmlspecialchars(history_plain_text($row['old'])));
                                    echo "<tr><td class=\"mct-field\">$fieldLabel</td><td class=\"mct-value\">"
                                        . "<span class=\"mct-old\">$oldDisplay</span>"
                                        . "<span class=\"mct-arrow\">&rarr;</span>"
                                        . "<span class=\"mct-new\">$newDisplay</span></td></tr>";
                                } else {
                                    echo "<tr><td class=\"mct-field\">$fieldLabel</td><td class=\"mct-value\">$newDisplay</td></tr>";
                                }
                            }
                            echo '</tbody></table>';
                        } else {
                            echo "<p>No details available.</p>";
                        }
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="no-history">No history available for this case.</p>
        <?php endif; ?>

        <a href="edit_job.php?job_id=<?php echo $job_id; ?>" class="back-btn" onclick="history.back(); return false;">&larr; Back</a>
    </div>
</body>

</html>
