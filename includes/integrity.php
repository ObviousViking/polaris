<?php
// includes/integrity.php
//
// Tamper-evident hash chains for case_history/exhibit_history and friends.
// Two layers: a plain SHA2 chain from a MySQL trigger, and an HMAC-SHA256
// chain computed here using HISTORY_HMAC_KEY (an app-only env var the DB
// never sees) - forging the HMAC layer needs both DB and app access.
//
// audit_log uses the same chain via its own insert function, log_audit_event()
// in includes/audit.php, but is checked by the same verify_history_chain() here.

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

// $changedAt defaults to now; pass it explicitly only when backfilling
// historical rows with a real original timestamp.
function insert_history_row(mysqli $conn, string $table, int $refId, string $action, int $changedBy, ?string $changes, ?string $changedAt = null): bool
{
    if (!isset(HISTORY_CHAIN_TABLES[$table])) {
        return false;
    }

    $idCol = HISTORY_CHAIN_TABLES[$table]['id_col'];
    $refCol = HISTORY_CHAIN_TABLES[$table]['ref_col'];
    $secret = getenv('HISTORY_HMAC_KEY') ?: '';
    $changedAt = $changedAt ?? date('Y-m-d H:i:s');

    // Named lock prevents two concurrent inserts reading the same "previous" hmac_hash.
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

        // Continue from what's stored so one tampered row doesn't cascade.
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
