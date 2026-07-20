<?php
// captains_quarters/upload_report_logo.php
//
// Admin-only handler for the Report Branding logo used on the printable Case Report.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/permissions.php';
require_permission($conn, 'manage_settings');

require_once '../includes/settings.php';
require_once '../includes/audit.php';

$config = get_storage_settings($conn);
$logoDir = $config['paths']['report_logo_dir_fs'];
if (!is_dir($logoDir)) {
    mkdir($logoDir, 0755, true);
}

$allowed_extensions = ['png', 'jpg', 'jpeg'];
const REPORT_LOGO_MAX_BYTES = 2 * 1024 * 1024; // 2MB - this is a small letterhead image, not evidence.

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_logo'])) {
    $existing = get_report_logo_filename($conn);
    if ($existing !== null) {
        @unlink($logoDir . $existing);
        save_report_logo_filename($conn, null);
        log_audit_event($conn, 'setting', null, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['setting_key' => 'report_logo_filename', 'action' => 'removed']));
        $_SESSION['logo_message'] = "Report logo removed.";
        $_SESSION['logo_message_type'] = 'success';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
    $original_name = basename($_FILES['logo']['name']);
    $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

    if (!in_array($extension, $allowed_extensions, true)) {
        $_SESSION['logo_message'] = "File type not allowed. Permitted types: " . implode(', ', $allowed_extensions) . ".";
        $_SESSION['logo_message_type'] = 'error';
    } elseif ($_FILES['logo']['size'] > REPORT_LOGO_MAX_BYTES) {
        $_SESSION['logo_message'] = "Logo file is too large (max 2MB).";
        $_SESSION['logo_message_type'] = 'error';
    } elseif (@getimagesize($_FILES['logo']['tmp_name']) === false) {
        $_SESSION['logo_message'] = "That file doesn't look like a valid image.";
        $_SESSION['logo_message_type'] = 'error';
    } else {
        $existing = get_report_logo_filename($conn);
        $new_filename = 'logo_' . time() . '.' . $extension;
        $target_path = $logoDir . $new_filename;

        if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_path)) {
            save_report_logo_filename($conn, $new_filename);
            if ($existing !== null && $existing !== $new_filename) {
                @unlink($logoDir . $existing);
            }
            log_audit_event($conn, 'setting', null, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['setting_key' => 'report_logo_filename', 'action' => 'uploaded']));
            $_SESSION['logo_message'] = "Report logo updated.";
            $_SESSION['logo_message_type'] = 'success';
        } else {
            $_SESSION['logo_message'] = "Upload failed. Please check file permissions or try a smaller file.";
            $_SESSION['logo_message_type'] = 'error';
        }
    }
}

header("Location: manage_settings.php");
exit();
