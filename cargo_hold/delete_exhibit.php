<?php
// delete_exhibit.php
//
// Soft-delete only - marks deleted_at/deleted_by and logs a before-snapshot
// to exhibit_history. See restore_exhibit.php to undo.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/integrity.php';
require_once '../includes/deletion_reason.php';
require_once '../includes/permissions.php';
require_permission($conn, 'exhibit_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['exhibit_id'])) {
    header("Location: ../dashboard.php");
    exit();
}

$exhibit_id = intval($_POST['exhibit_id']);

$stmt = $conn->prepare("SELECT * FROM exhibits WHERE exhibit_id = ?");
$stmt->bind_param("i", $exhibit_id);
$stmt->execute();
$exhibit = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$exhibit) {
    header("Location: ../dashboard.php");
    exit();
}
$job_id = (int) $exhibit['job_id'];

// Already deleted - nothing to do, just bounce back.
if ($exhibit['deleted_at'] !== null) {
    header("Location: job.php?job_id=$job_id");
    exit();
}

$reason = require_deletion_reason_or_fail($conn);
if ($reason === false) {
    header("Location: job.php?job_id=$job_id&error=reason_required");
    exit();
}

$changedBy = (int) $_SESSION['user_id'];
$now = date('Y-m-d H:i:s');

$stmt = $conn->prepare("UPDATE exhibits SET deleted_at = ?, deleted_by = ? WHERE exhibit_id = ?");
$stmt->bind_param("sii", $now, $changedBy, $exhibit_id);
$ok = $stmt->execute();
$stmt->close();

if ($ok) {
    // Full before-state resolved to names, matching the BOOK_IN/UPDATE entries.
    $typeStmt = $conn->prepare("SELECT type_name FROM exhibit_types WHERE exhibit_type_id = ?");
    $typeStmt->bind_param("i", $exhibit['exhibit_type_id']);
    $typeStmt->execute();
    $typeStmt->bind_result($exhibitTypeName);
    $typeStmt->fetch();
    $typeStmt->close();

    $locStmt = $conn->prepare("SELECT location_name FROM exhibit_locations WHERE location_id = ?");
    $locStmt->bind_param("i", $exhibit['location_id']);
    $locStmt->execute();
    $locStmt->bind_result($locationName);
    $locStmt->fetch();
    $locStmt->close();

    $allocatedToName = null;
    if (!empty($exhibit['allocated_to'])) {
        $userStmt = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE id = ?");
        $userStmt->bind_param("i", $exhibit['allocated_to']);
        $userStmt->execute();
        $userStmt->bind_result($allocatedToName);
        $userStmt->fetch();
        $userStmt->close();
    }

    $snapshot = [
        'exhibit_ref' => $exhibit['exhibit_ref'],
        'item_description' => $exhibit['item_description'],
        'exhibit_type' => $exhibitTypeName,
        'bag_number' => $exhibit['bag_number'],
        'urgency' => $exhibit['urgency'],
        'status' => $exhibit['status'],
        'location' => $locationName,
        'allocated_to' => $allocatedToName,
        'time_in' => $exhibit['time_in'],
        'time_out' => $exhibit['time_out'],
    ];

    $changes = json_encode(['before' => $snapshot, 'reason' => $reason !== '' ? $reason : null]);
    insert_history_row($conn, 'exhibit_history', $exhibit_id, 'DELETE', $changedBy, $changes);
}

header("Location: job.php?job_id=$job_id");
exit();
