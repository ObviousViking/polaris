<?php
// bin/migrate_case_updates_history.php
//
// One-off, run-once-by-hand backfill: copies every row out of
// case_updates_history (retired - case update history now lives in
// case_history, alongside job field edits, instead of its own table) into
// case_history, preserving the original changed_at/changed_by rather than
// stamping "now". Not part of the includes/migrations/*.sql runner - this
// moves data with judgement calls (renaming actions, reshaping the JSON),
// which doesn't fit a plain schema migration.
//
// Run from inside the app container, BEFORE the schema migration that
// drops case_updates_history:
//
//   docker exec -it polaris_app php bin/migrate_case_updates_history.php
//
// Safe to run more than once - skips case_updates_history rows that already
// have a matching case_history entry (matched on the embedded "Update ID"
// + action + original changed_at).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("This script is CLI-only.\n");
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/integrity.php';

$check = $conn->query("SHOW TABLES LIKE 'case_updates_history'");
if (!$check || $check->num_rows === 0) {
    echo "case_updates_history doesn't exist - nothing to migrate.\n";
    exit(0);
}

$actionMap = [
    'CREATE'  => 'CASE_UPDATE_ADDED',
    'UPDATE'  => 'CASE_UPDATE_EDITED',
    'DELETE'  => 'CASE_UPDATE_DELETED',
    'RESTORE' => 'CASE_UPDATE_RESTORED',
];

$rows = $conn->query("SELECT history_id, update_id, action, changed_by, changes, changed_at FROM case_updates_history ORDER BY history_id ASC");
$migrated = 0;
$skipped = 0;

while ($row = $rows->fetch_assoc()) {
    $newAction = $actionMap[$row['action']] ?? $row['action'];

    // Resolve job_id via the still-present case_updates row (soft-delete
    // never removed it - the schema migration that drops deleted_at/
    // deleted_by runs after this script).
    $jobStmt = $conn->prepare("SELECT job_id FROM case_updates WHERE update_id = ?");
    $jobStmt->bind_param("i", $row['update_id']);
    $jobStmt->execute();
    $jobStmt->bind_result($jobId);
    if (!$jobStmt->fetch()) {
        echo "SKIP history_id={$row['history_id']}: update_id={$row['update_id']} no longer exists in case_updates.\n";
        $jobStmt->close();
        $skipped++;
        continue;
    }
    $jobStmt->close();

    // Idempotency guard: has this exact event already been migrated?
    $dupStmt = $conn->prepare("SELECT COUNT(*) FROM case_history WHERE job_id = ? AND action = ? AND changed_at = ? AND changed_by = ?");
    $dupStmt->bind_param("issi", $jobId, $newAction, $row['changed_at'], $row['changed_by']);
    $dupStmt->execute();
    $dupStmt->bind_result($dupCount);
    $dupStmt->fetch();
    $dupStmt->close();
    if ($dupCount > 0) {
        echo "SKIP history_id={$row['history_id']}: already migrated.\n";
        $skipped++;
        continue;
    }

    $old = json_decode($row['changes'], true) ?: [];
    $updateId = (int) $row['update_id'];

    switch ($row['action']) {
        case 'CREATE':
            $newChanges = ['Update ID' => $updateId, 'Type' => $old['type'] ?? null, 'Text' => $old['text'] ?? null];
            break;
        case 'UPDATE':
            $newChanges = ['Update ID' => $updateId, 'State Before Edit' => ['Type' => $old['type'] ?? null, 'Text' => $old['text'] ?? null]];
            break;
        case 'DELETE':
            $before = $old['before'] ?? [];
            $newChanges = ['Update ID' => $updateId, 'Deleted Update' => ['Type' => $before['type'] ?? null, 'Text' => $before['text'] ?? null]];
            break;
        case 'RESTORE':
            $newChanges = ['Update ID' => $updateId, 'Restored - Had Been Deleted At' => $old['restored_from_deleted_at'] ?? null];
            break;
        default:
            $newChanges = ['Update ID' => $updateId] + $old;
    }

    $ok = insert_history_row($conn, 'case_history', (int) $jobId, $newAction, (int) $row['changed_by'], json_encode($newChanges), $row['changed_at']);
    if ($ok) {
        echo "MIGRATED history_id={$row['history_id']} -> case_history job_id=$jobId action=$newAction changed_at={$row['changed_at']}\n";
        $migrated++;
    } else {
        echo "FAILED history_id={$row['history_id']}: " . $conn->error . "\n";
    }
}

echo "\nDone. Migrated: $migrated, Skipped: $skipped\n";
