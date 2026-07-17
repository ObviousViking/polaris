<?php
// includes/audit.php
//
// Tamper-evident "who changed what, when" log for admin/config mutations
// that sit outside the case/exhibit history chains (see
// includes/integrity.php - those exist for legal chain-of-custody over
// cases/exhibits specifically). Lookup tables (case types, exhibit types,
// locations, forces, operations, customers, case statuses), users, tasks,
// assets, and system settings (SLA, storage path) had no trail before this.
//
// audit_log uses the same two-layer hash/HMAC chain as case_history and
// exhibit_history (see includes/polaris_create.sql for the trigger that
// computes prev_hash/row_hash, and includes/integrity.php's
// verify_history_chain(), which audit_log is registered under too - so
// Check Database Integrity verifies all three chains). It doesn't reuse
// insert_history_row() because its column set (entity_type + entity_id
// instead of a single ref column) doesn't fit that function's signature.

function log_audit_event(mysqli $conn, string $entityType, ?int $entityId, string $action, int $changedBy, ?string $details = null): bool
{
    $changedAt = date('Y-m-d H:i:s');
    $secret = getenv('HISTORY_HMAC_KEY') ?: '';

    // Mirrors insert_history_row()'s locking in includes/integrity.php - the
    // HMAC needs a read-then-write round trip in PHP (unlike the trigger's
    // hash chain, which is atomic), so two concurrent inserts could
    // otherwise both read the same "previous" hmac_hash.
    $lockName = 'polaris_history_hmac_audit_log';
    $lockResult = $conn->query("SELECT GET_LOCK('" . $conn->real_escape_string($lockName) . "', 5) AS got");
    $gotLock = $lockResult && ($lockRow = $lockResult->fetch_assoc()) && (int) $lockRow['got'] === 1;
    if (!$gotLock) {
        error_log("log_audit_event: could not acquire lock");
        return false;
    }

    $ok = false;
    try {
        $prevHmac = str_repeat('0', 64);
        $prevResult = $conn->query("SELECT hmac_hash FROM audit_log ORDER BY id DESC LIMIT 1");
        if ($prevResult && ($prevRow = $prevResult->fetch_assoc()) && !empty($prevRow['hmac_hash'])) {
            $prevHmac = $prevRow['hmac_hash'];
        }

        $payload = implode('|', [$entityType, $entityId ?? '', $action, $changedBy, $changedAt, $details ?? '']) . '|' . $prevHmac;
        $hmacHash = hash_hmac('sha256', $payload, $secret);

        $stmt = $conn->prepare("
            INSERT INTO audit_log (entity_type, entity_id, action, changed_by, changed_at, details, prev_hmac, hmac_hash)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param("sisissss", $entityType, $entityId, $action, $changedBy, $changedAt, $details, $prevHmac, $hmacHash);
            $ok = $stmt->execute();
            $stmt->close();
        }
    } finally {
        $conn->query("SELECT RELEASE_LOCK('" . $conn->real_escape_string($lockName) . "')");
    }

    return $ok;
}
