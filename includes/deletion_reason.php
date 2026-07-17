<?php
// includes/deletion_reason.php
//
// Server-side enforcement for the "require a reason for every deletion"
// toggle - the client-side prompt is UX only, this is what actually blocks it.

require_once __DIR__ . '/settings.php';

// Returns false if a reason was required but missing, otherwise the reason (or null).
function require_deletion_reason_or_fail(mysqli $conn): string|false|null
{
    $reason = trim($_POST['delete_reason'] ?? '');
    if (get_require_deletion_reason($conn) && $reason === '') {
        return false;
    }
    return $reason !== '' ? $reason : null;
}
