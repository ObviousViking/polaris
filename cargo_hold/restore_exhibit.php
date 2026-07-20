<?php
// restore_exhibit.php - undoes delete_exhibit.php: clears deleted_at/
// deleted_by and logs a RESTORE entry to exhibit_history.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/integrity.php';
require_once '../includes/permissions.php';
require_permission($conn, 'exhibit_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['exhibit_id'])) {
    header("Location: ../dashboard.php");
    exit();
}

$exhibit_id = intval($_POST['exhibit_id']);

$stmt = $conn->prepare("SELECT job_id, deleted_at FROM exhibits WHERE exhibit_id = ?");
$stmt->bind_param("i", $exhibit_id);
$stmt->execute();
$exhibit = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$exhibit) {
    header("Location: ../dashboard.php");
    exit();
}
$job_id = (int) $exhibit['job_id'];

if ($exhibit['deleted_at'] === null) {
    header("Location: job.php?job_id=$job_id");
    exit();
}

$changedBy = (int) $_SESSION['user_id'];
$wasDeletedAt = $exhibit['deleted_at'];

$stmt = $conn->prepare("UPDATE exhibits SET deleted_at = NULL, deleted_by = NULL WHERE exhibit_id = ?");
$stmt->bind_param("i", $exhibit_id);
$ok = $stmt->execute();
$stmt->close();

if ($ok) {
    $changes = json_encode(['restored_from_deleted_at' => $wasDeletedAt]);
    insert_history_row($conn, 'exhibit_history', $exhibit_id, 'RESTORE', $changedBy, $changes);
}

header("Location: job.php?job_id=$job_id");
exit();
