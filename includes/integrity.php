<?php
// includes/integrity.php
//
// Two independent tamper-evidence layers on case_history/exhibit_history:
//
// 1. prev_hash/row_hash - plain SHA2, computed by a MySQL trigger (see
//    includes/polaris_create.sql). Zero application code needed, applies to
//    any insert regardless of code path, but anyone with DB admin rights can
//    read the trigger definition and forge a consistent chain after editing
//    a row, since the trigger has no access to anything outside the DB.
//
// 2. prev_hmac/hmac_hash - HMAC-SHA256 computed here, in PHP, using
//    HISTORY_HMAC_KEY (an app-container-only env var the database itself
//    can never see). Forging this layer requires BOTH database write access
//    AND the app's environment - a genuinely narrower requirement than
//    database access alone.
//
// Neither layer defends against someone with full DB admin access who ALSO
// has the app's env vars (a full app+DB compromise), or who tampers with
// data from before this feature existed. Real protection against a fully
// privileged rewrite requires anchoring the chain tip somewhere outside
// this database's control entirely, which this does not attempt.
//
// All 3 insert sites (add_exhibit.php, edit_exhibit.php, edit_job.php) call
// insert_history_row() below rather than building their own INSERT, so the
// HMAC logic exists in exactly one place.
//
// audit_log (admin/config changes - lookup tables, users, tasks, assets,
// settings) uses the same two-layer chain, but has its own insert function -
// log_audit_event() in includes/audit.php - since its column set (entity_type
// + entity_id instead of a single ref column) doesn't fit insert_history_row()
// as-is. verify_history_chain() below is generic enough to check it too, as
// long as it's registered in HISTORY_CHAIN_TABLES.

const HISTORY_CHAIN_TABLES = [
    'case_history' => [
        'id_col' => 'history_id',
        'ref_col' => 'job_id',
        'fields' => ['job_id', 'action', 'changed_by', 'changed_at', 'changes'],
    ],
    'exhibit_history' => [
        'id_col' => 'history_id',
        'ref_col' => 'exhibit_id',
        'fields' => ['exhibit_id', 'action', 'changed_by', 'changed_at', 'changes'],
    ],
    'audit_log' => [
        'id_col' => 'id',
        'ref_col' => 'entity_id',
        'fields' => ['entity_type', 'entity_id', 'action', 'changed_by', 'changed_at', 'details'],
    ],
    'exhibit_process_history' => [
        'id_col' => 'history_id',
        'ref_col' => 'exhibit_process_id',
        'fields' => ['exhibit_process_id', 'action', 'changed_by', 'changed_at', 'changes'],
    ],
];

// $changedAt: normally omitted (defaults to now) - only passed explicitly
// when backfilling historical rows from an old source with a real original
// timestamp (see bin/migrate_case_updates_history.php), so the migrated
// entry doesn't misrepresent when the event actually happened.
function insert_history_row(mysqli $conn, string $table, int $refId, string $action, int $changedBy, ?string $changes, ?string $changedAt = null): bool
{
    if (!isset(HISTORY_CHAIN_TABLES[$table])) {
        return false;
    }

    $idCol = HISTORY_CHAIN_TABLES[$table]['id_col'];
    $refCol = HISTORY_CHAIN_TABLES[$table]['ref_col'];
    $secret = getenv('HISTORY_HMAC_KEY') ?: '';
    $changedAt = $changedAt ?? date('Y-m-d H:i:s');

    // The trigger's plain-hash chain is atomic (runs inside the INSERT
    // itself), but this HMAC needs a read-then-write round trip in PHP, so
    // it's wrapped in a named lock to stop two concurrent inserts both
    // reading the same "previous" hmac_hash.
    $lockName = 'polaris_history_hmac_' . $table;
    $lockResult = $conn->query("SELECT GET_LOCK('" . $conn->real_escape_string($lockName) . "', 5) AS got");
    $gotLock = $lockResult && ($lockRow = $lockResult->fetch_assoc()) && (int) $lockRow['got'] === 1;
    if (!$gotLock) {
        error_log("insert_history_row: could not acquire lock for $table");
        return false;
    }

    $ok = false;
    try {
        $prevHmac = str_repeat('0', 64);
        $prevResult = $conn->query("SELECT hmac_hash FROM `$table` ORDER BY $idCol DESC LIMIT 1");
        if ($prevResult && ($prevRow = $prevResult->fetch_assoc()) && !empty($prevRow['hmac_hash'])) {
            $prevHmac = $prevRow['hmac_hash'];
        }

        $payload = implode('|', [$refId, $action, $changedBy, $changedAt, $changes ?? '']) . '|' . $prevHmac;
        $hmacHash = hash_hmac('sha256', $payload, $secret);

        $stmt = $conn->prepare("
            INSERT INTO `$table` ($refCol, action, changed_by, changed_at, changes, prev_hmac, hmac_hash)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param("isissss", $refId, $action, $changedBy, $changedAt, $changes, $prevHmac, $hmacHash);
            $ok = $stmt->execute();
            $stmt->close();
        }
    } finally {
        $conn->query("SELECT RELEASE_LOCK('" . $conn->real_escape_string($lockName) . "')");
    }

    return $ok;
}

function verify_history_chain(mysqli $conn, string $table): array
{
    if (!isset(HISTORY_CHAIN_TABLES[$table])) {
        return ['ok' => false, 'error' => 'Unknown table', 'total' => 0, 'broken_hash' => [], 'broken_hmac' => []];
    }

    $idCol = HISTORY_CHAIN_TABLES[$table]['id_col'];
    $fields = HISTORY_CHAIN_TABLES[$table]['fields'];
    $fieldList = implode(', ', $fields);
    $secret = getenv('HISTORY_HMAC_KEY') ?: '';

    $result = $conn->query("SELECT $idCol AS id, $fieldList, prev_hash, row_hash, prev_hmac, hmac_hash FROM `$table` ORDER BY $idCol ASC");
    if (!$result) {
        return ['ok' => false, 'error' => $conn->error, 'total' => 0, 'broken_hash' => [], 'broken_hmac' => []];
    }

    $expectedPrevHash = str_repeat('0', 64);
    $expectedPrevHmac = str_repeat('0', 64);
    $total = 0;
    $brokenHash = [];
    $brokenHmac = [];

    while ($row = $result->fetch_assoc()) {
        $total++;

        $parts = [];
        foreach ($fields as $f) {
            $parts[] = $row[$f] ?? '';
        }
        $payload = implode('|', $parts);

        $expectedHash = hash('sha256', $payload . '|' . $expectedPrevHash);
        if ($row['prev_hash'] !== $expectedPrevHash || $row['row_hash'] !== $expectedHash) {
            $brokenHash[] = (int) $row['id'];
        }

        $expectedHmac = hash_hmac('sha256', $payload . '|' . $expectedPrevHmac, $secret);
        if ($row['prev_hmac'] !== $expectedPrevHmac || $row['hmac_hash'] !== $expectedHmac) {
            $brokenHmac[] = (int) $row['id'];
        }

        // Continue each chain from what's actually stored, so a single
        // tampered row is reported once rather than cascading into every
        // row after it looking broken too.
        $expectedPrevHash = $row['row_hash'];
        $expectedPrevHmac = $row['hmac_hash'];
    }

    return [
        'ok' => empty($brokenHash) && empty($brokenHmac),
        'error' => null,
        'total' => $total,
        'broken_hash' => $brokenHash,
        'broken_hmac' => $brokenHmac,
    ];
}
