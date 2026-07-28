<?php
// bin/migrate_case_items_data.php
//
// One-off backfill: copies rows from the retired exported_items/
// produced_exhibits tables into the consolidated case_items table, and
// exported_items' history into case_item_history - preserving original
// timestamps and full HMAC chain integrity (which a raw SQL INSERT could
// never produce, since insert_history_row() computes the HMAC layer here
// in PHP using a secret the database never sees). Run after the schema
// migration that creates case_items/case_item_types/case_item_history:
//
//   docker exec -it polaris_app php bin/migrate_case_items_data.php
//
// Safe to run more than once. exported_items/produced_exhibits/
// exported_item_history are left untouched - nothing is deleted here.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("This script is CLI-only.\n");
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/integrity.php';

$check = $conn->query("SHOW TABLES LIKE 'exported_items'");
if (!$check || $check->num_rows === 0) {
    echo "exported_items doesn't exist - nothing to migrate.\n";
    exit(0);
}

function get_type_id(mysqli $conn, string $typeName): int
{
    $stmt = $conn->prepare("SELECT type_id FROM case_item_types WHERE type_name = ?");
    $stmt->bind_param("s", $typeName);
    $stmt->execute();
    $stmt->bind_result($typeId);
    if (!$stmt->fetch()) {
        $stmt->close();
        die("case_item_types is missing the '$typeName' row - run the 014_case_items.sql migration first.\n");
    }
    $stmt->close();
    return (int) $typeId;
}

// Tracks refs already used per job (job_id|UPPER(ref)) so refs colliding
// across the two source tables (no DB-level unique constraint existed on
// either) get auto-suffixed instead of failing case_items' new
// UNIQUE(job_id, item_ref) constraint.
$usedRefs = [];
function unique_ref(mysqli $conn, array &$usedRefs, int $jobId, string $ref): string
{
    $candidate = $ref;
    $suffix = 1;
    while (true) {
        $key = $jobId . '|' . strtoupper($candidate);
        if (!isset($usedRefs[$key])) {
            $check = $conn->prepare("SELECT COUNT(*) FROM case_items WHERE job_id = ? AND UPPER(item_ref) = ?");
            $upper = strtoupper($candidate);
            $check->bind_param("is", $jobId, $upper);
            $check->execute();
            $check->bind_result($count);
            $check->fetch();
            $check->close();
            if ($count == 0) {
                $usedRefs[$key] = true;
                return $candidate;
            }
        }
        $suffix++;
        $candidate = $ref . '-' . $suffix;
    }
}

$exportedTypeId = get_type_id($conn, 'EXPORTED ITEM');
$producedTypeId = get_type_id($conn, 'PRODUCED EXHIBIT');

$idMap = ['exported' => [], 'produced' => []];
$migratedItems = 0;
$skippedItems = 0;

// --- exported_items -> case_items ---
$rows = $conn->query("SELECT * FROM exported_items ORDER BY item_id ASC");
while ($row = $rows->fetch_assoc()) {
    $oldId = (int) $row['item_id'];

    // Idempotency guard: has this exact source row already been migrated?
    // (job_id + original ref, since a re-run would otherwise try to
    // auto-suffix a brand new duplicate every time.)
    $dupStmt = $conn->prepare("SELECT item_id FROM case_items WHERE job_id = ? AND UPPER(item_ref) = ? AND type_id = ?");
    $upperRef = strtoupper($row['extraction_ref']);
    $dupStmt->bind_param("isi", $row['job_id'], $upperRef, $exportedTypeId);
    $dupStmt->execute();
    $dupStmt->bind_result($existingId);
    if ($dupStmt->fetch()) {
        $dupStmt->close();
        echo "SKIP exported_items.item_id=$oldId: already migrated (case_items.item_id=$existingId).\n";
        $idMap['exported'][$oldId] = (int) $existingId;
        $skippedItems++;
        continue;
    }
    $dupStmt->close();

    $ref = unique_ref($conn, $usedRefs, (int) $row['job_id'], $row['extraction_ref']);

    $stmt = $conn->prepare("
        INSERT INTO case_items
            (job_id, type_id, source_exhibit_id, item_ref, description, status, notes,
             created_on, created_by, assigned_to, last_handed_to, last_handed_to_at)
        VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "iiissssiiss",
        $row['job_id'],
        $exportedTypeId,
        $row['source_exhibit_id'],
        $ref,
        $row['description'],
        $row['status'],
        $row['extracted_on'],
        $row['extracted_by'],
        $row['assigned_to'],
        $row['last_handed_to'],
        $row['last_handed_to_at']
    );
    if (!$stmt->execute()) {
        echo "FAILED exported_items.item_id=$oldId: " . $stmt->error . "\n";
        $stmt->close();
        continue;
    }
    $newId = $conn->insert_id;
    $stmt->close();
    $idMap['exported'][$oldId] = $newId;
    echo "MIGRATED exported_items.item_id=$oldId -> case_items.item_id=$newId (ref=$ref)\n";
    $migratedItems++;
}

// --- produced_exhibits -> case_items ---
$rows = $conn->query("SELECT * FROM produced_exhibits ORDER BY exhibit_id ASC");
while ($row = $rows->fetch_assoc()) {
    $oldId = (int) $row['exhibit_id'];

    $dupStmt = $conn->prepare("SELECT item_id FROM case_items WHERE job_id = ? AND UPPER(item_ref) = ? AND type_id = ?");
    $upperRef = strtoupper($row['exhibit_ref']);
    $dupStmt->bind_param("isi", $row['job_id'], $upperRef, $producedTypeId);
    $dupStmt->execute();
    $dupStmt->bind_result($existingId);
    if ($dupStmt->fetch()) {
        $dupStmt->close();
        echo "SKIP produced_exhibits.exhibit_id=$oldId: already migrated (case_items.item_id=$existingId).\n";
        $idMap['produced'][$oldId] = (int) $existingId;
        $skippedItems++;
        continue;
    }
    $dupStmt->close();

    $ref = unique_ref($conn, $usedRefs, (int) $row['job_id'], $row['exhibit_ref']);

    $stmt = $conn->prepare("
        INSERT INTO case_items
            (job_id, type_id, source_exhibit_id, item_ref, description, status, notes,
             created_on, created_by, assigned_to, last_handed_to, last_handed_to_at)
        VALUES (?, ?, NULL, ?, ?, 'Awaiting Review', NULL, ?, ?, NULL, NULL, NULL)
    ");
    $stmt->bind_param(
        "iisssi",
        $row['job_id'],
        $producedTypeId,
        $ref,
        $row['description'],
        $row['produced_date'],
        $row['extracted_by']
    );
    if (!$stmt->execute()) {
        echo "FAILED produced_exhibits.exhibit_id=$oldId: " . $stmt->error . "\n";
        $stmt->close();
        continue;
    }
    $newId = $conn->insert_id;
    $stmt->close();
    $idMap['produced'][$oldId] = $newId;
    echo "MIGRATED produced_exhibits.exhibit_id=$oldId -> case_items.item_id=$newId (ref=$ref)\n";
    $migratedItems++;

    // produced_exhibits never had a history table - synthesize one CREATE
    // entry so the migrated item's history view isn't misleadingly empty.
    $changedBy = (int) ($row['extracted_by'] ?? 0);
    if ($changedBy > 0) {
        $changedAt = $row['produced_date'] ? $row['produced_date'] . ' 00:00:00' : date('Y-m-d H:i:s');
        $dupHistStmt = $conn->prepare("SELECT COUNT(*) FROM case_item_history WHERE item_id = ? AND action = 'CREATE'");
        $dupHistStmt->bind_param("i", $newId);
        $dupHistStmt->execute();
        $dupHistStmt->bind_result($histCount);
        $dupHistStmt->fetch();
        $dupHistStmt->close();
        if ($histCount == 0) {
            insert_history_row($conn, 'case_item_history', $newId, 'CREATE', $changedBy, json_encode([
                'item_ref' => $ref,
                'description' => $row['description'],
                'migrated_from' => 'produced_exhibits',
            ]), $changedAt);
        }
    }
}

// --- exported_item_history -> case_item_history ---
$migratedHistory = 0;
$skippedHistory = 0;
$rows = $conn->query("SELECT * FROM exported_item_history ORDER BY history_id ASC");
while ($row = $rows->fetch_assoc()) {
    $oldHistId = (int) $row['history_id'];
    $newItemId = $idMap['exported'][(int) $row['item_id']] ?? null;
    if ($newItemId === null) {
        echo "SKIP exported_item_history.history_id=$oldHistId: source item_id={$row['item_id']} was never migrated.\n";
        $skippedHistory++;
        continue;
    }

    $dupStmt = $conn->prepare("SELECT COUNT(*) FROM case_item_history WHERE item_id = ? AND action = ? AND changed_at = ? AND changed_by = ?");
    $dupStmt->bind_param("issi", $newItemId, $row['action'], $row['changed_at'], $row['changed_by']);
    $dupStmt->execute();
    $dupStmt->bind_result($dupCount);
    $dupStmt->fetch();
    $dupStmt->close();
    if ($dupCount > 0) {
        echo "SKIP exported_item_history.history_id=$oldHistId: already migrated.\n";
        $skippedHistory++;
        continue;
    }

    $ok = insert_history_row($conn, 'case_item_history', $newItemId, $row['action'], (int) $row['changed_by'], $row['changes'], $row['changed_at']);
    if ($ok) {
        echo "MIGRATED exported_item_history.history_id=$oldHistId -> case_item_history item_id=$newItemId action={$row['action']}\n";
        $migratedHistory++;
    } else {
        echo "FAILED exported_item_history.history_id=$oldHistId: " . $conn->error . "\n";
    }
}

echo "\nDone. Items migrated: $migratedItems, skipped: $skippedItems. History rows migrated: $migratedHistory, skipped: $skippedHistory.\n";
