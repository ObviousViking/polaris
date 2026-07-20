<?php
// delete_update.php
//
// Hard delete. The full content is captured as a CASE_UPDATE_DELETED entry
// in case_history before the row is removed, so nothing is lost. No restore.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/integrity.php';
require_once '../includes/deletion_reason.php';
require_once '../includes/permissions.php';
require_permission($conn, 'case_update_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['update_id'])) {
    header("Location: ../dashboard.php");
    exit();
}

$update_id = intval($_POST['update_id']);

$stmt = $conn->prepare("SELECT * FROM case_updates WHERE update_id = ?");
$stmt->bind_param("i", $update_id);
$stmt->execute();
$update = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$update) {
    header("Location: ../dashboard.php");
    exit();
}
$job_id = (int) $update['job_id'];
$changedBy = (int) $_SESSION['user_id'];

$reason = require_deletion_reason_or_fail($conn);
if ($reason === false) {
    header("Location: job.php?job_id=$job_id&error=reason_required");
    exit();
}

// Snapshot before removing the row - the only place the deleted content survives.
$changes = json_encode([
    'Update ID' => $update_id,
    'Deleted Update' => ['Type' => $update['update_type'], 'Text' => $update['update_text']],
    'Reason' => $reason,
]);

$stmt = $conn->prepare("DELETE FROM case_updates WHERE update_id = ?");
$stmt->bind_param("i", $update_id);
$ok = $stmt->execute();
$stmt->close();

if ($ok) {
    insert_history_row($conn, 'case_history', $job_id, 'CASE_UPDATE_DELETED', $changedBy, $changes);
}

header("Location: job.php?job_id=$job_id");
exit();
