<?php
// delete_asset.php
//
// Soft-delete only - marks deleted_at/deleted_by and logs a before-snapshot
// to asset_history. See restore_asset.php to undo.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/integrity.php';
require_once '../includes/deletion_reason.php';
require_once '../includes/permissions.php';
require_permission($conn, 'asset_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['asset_id'])) {
    header("Location: lh_dashboard.php");
    exit();
}

$asset_id = intval($_POST['asset_id']);

$stmt = $conn->prepare("SELECT * FROM assets WHERE id = ?");
$stmt->bind_param("i", $asset_id);
$stmt->execute();
$asset = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$asset) {
    header("Location: lh_dashboard.php");
    exit();
}

if ($asset['deleted_at'] !== null) {
    header("Location: manage_assets.php?error=already_deleted");
    exit();
}

// An asset currently checked out has to be checked in first - deleting it
// mid-loan would silently orphan the open checkout record.
$openCheckoutStmt = $conn->prepare("SELECT COUNT(*) FROM asset_checkouts WHERE asset_id = ? AND checked_in_at IS NULL");
$openCheckoutStmt->bind_param("i", $asset_id);
$openCheckoutStmt->execute();
$openCheckoutStmt->bind_result($openCount);
$openCheckoutStmt->fetch();
$openCheckoutStmt->close();

if ($openCount > 0) {
    header("Location: manage_assets.php?error=checked_out");
    exit();
}

$reason = require_deletion_reason_or_fail($conn);
if ($reason === false) {
    header("Location: manage_assets.php?error=reason_required");
    exit();
}

$changedBy = (int) $_SESSION['user_id'];
$now = date('Y-m-d H:i:s');

$stmt = $conn->prepare("UPDATE assets SET deleted_at = ?, deleted_by = ? WHERE id = ?");
$stmt->bind_param("sii", $now, $changedBy, $asset_id);
$ok = $stmt->execute();
$stmt->close();

if ($ok) {
    $changes = [
        'asset_number' => $asset['asset_number'],
        'friendly_name' => $asset['friendly_name'],
        'serial_number' => $asset['serial_number'],
        'availability' => $asset['availability'],
    ];
    if ($reason !== null) {
        $changes['reason'] = $reason;
    }
    insert_history_row($conn, 'asset_history', $asset_id, 'DELETE', $changedBy, json_encode($changes));
}

header("Location: manage_assets.php");
exit();
