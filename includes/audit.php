<?php
// includes/audit.php
//
// Tamper-evident audit log for admin/config changes (lookup tables, users,
// tasks, assets, settings) - same hash/HMAC chain as case_history and
// exhibit_history (see includes/integrity.php), but its own insert function
// since its columns (entity_type + entity_id) don't fit insert_history_row().

function log_audit_event(mysqli $conn, string $entityType, ?int $entityId, string $action, int $changedBy, ?string $details = null): bool
{
    $changedAt = date('Y-m-d H:i:s');
    $secret = getenv('HISTORY_HMAC_KEY') ?: '';

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
