<?php
// restore_asset.php - undoes delete_asset.php: clears deleted_at/deleted_by
// and logs a RESTORE entry to asset_history.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/integrity.php';
require_once '../includes/permissions.php';
require_permission($conn, 'asset_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['asset_id'])) {
    header("Location: lh_dashboard.php");
    exit();
}

$asset_id = intval($_POST['asset_id']);

$stmt = $conn->prepare("SELECT deleted_at FROM assets WHERE id = ?");
$stmt->bind_param("i", $asset_id);
$stmt->execute();
$stmt->bind_result($deletedAt);
if (!$stmt->fetch()) {
    $stmt->close();
    header("Location: lh_dashboard.php");
    exit();
}
$stmt->close();

if ($deletedAt === null) {
    header("Location: manage_assets.php");
    exit();
}

$changedBy = (int) $_SESSION['user_id'];

$stmt = $conn->prepare("UPDATE assets SET deleted_at = NULL, deleted_by = NULL WHERE id = ?");
$stmt->bind_param("i", $asset_id);
$ok = $stmt->execute();
$stmt->close();

if ($ok) {
    insert_history_row($conn, 'asset_history', $asset_id, 'RESTORE', $changedBy, json_encode(['restored_from_deleted_at' => $deletedAt]));
}

header("Location: manage_assets.php");
exit();
