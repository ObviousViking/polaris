<?php
// includes/deletion_reason.php
//
// Server-side enforcement for the "require a reason for every deletion"
// toggle (System Management -> System Settings -> require_deletion_reason).
// The client-side prompt() in header.php/embedded_header.php's
// confirmDeleteWithReason() is UX only - a client can always skip it (or
// script around it entirely), so this is what actually stops a delete from
// going ahead. Every delete handler in the app calls this before touching
// the database, never trusting $_POST['delete_reason'] on its own.

require_once __DIR__ . '/settings.php';

// Returns false if the toggle is on and no reason was supplied (callers
// must bail out without deleting anything), otherwise the trimmed reason
// string, or null if one wasn't required/given.
function require_deletion_reason_or_fail(mysqli $conn): string|false|null
{
    $reason = trim($_POST['delete_reason'] ?? '');
    if (get_require_deletion_reason($conn) && $reason === '') {
        return false;
    }
    return $reason !== '' ? $reason : null;
}
