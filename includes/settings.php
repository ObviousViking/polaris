<?php
// includes/settings.php
//
// DB-backed app settings, keyed off a single root data directory with fixed
// subfolders (avatars/exhibit photos/exhibit documents/etc) underneath it.

const DATA_ROOT_DEFAULT = '/var/www/polaris-data/';
const DATA_ROOT_URL = '/polaris_uploads/';

function get_data_root(mysqli $conn): string
{
    $root = DATA_ROOT_DEFAULT;

    $stmt = @$conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'data_root_dir' LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $stmt->bind_result($value);
        if ($stmt->fetch() && !empty($value)) {
            $root = $value;
        }
        $stmt->close();
    }

    return rtrim($root, '/\\') . '/';
}

// Shows the host-side path in the UI instead of the container's internal mount point.
function get_data_host_path_display(): string
{
    $hostPath = getenv('DATA_HOST_PATH');
    return $hostPath !== false && $hostPath !== '' ? $hostPath : DATA_ROOT_DEFAULT;
}

function save_data_root(mysqli $conn, string $root): bool
{
    $root = rtrim(trim($root), '/\\') . '/';

    $stmt = $conn->prepare("
        INSERT INTO settings (setting_key, setting_value) VALUES ('data_root_dir', ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("s", $root);
    $result = $stmt->execute();
    $stmt->close();

    return $result;
}

const STRATEGY_DUE_SLA_DAYS_DEFAULT = 90; // matches the old hardcoded "+3 months"

function get_strategy_due_sla_days(mysqli $conn): int
{
    $days = STRATEGY_DUE_SLA_DAYS_DEFAULT;

    $stmt = @$conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'strategy_due_sla_days' LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $stmt->bind_result($value);
        if ($stmt->fetch() && $value !== null && $value !== '' && ctype_digit((string) $value)) {
            $days = (int) $value;
        }
        $stmt->close();
    }

    return $days;
}

function save_strategy_due_sla_days(mysqli $conn, int $days): bool
{
    if ($days < 1) {
        return false;
    }

    $stmt = $conn->prepare("
        INSERT INTO settings (setting_key, setting_value) VALUES ('strategy_due_sla_days', ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    if (!$stmt) {
        return false;
    }
    $daysStr = (string) $days;
    $stmt->bind_param("s", $daysStr);
    $result = $stmt->execute();
    $stmt->close();

    return $result;
}

// When on, deletes require a non-empty reason - see includes/deletion_reason.php.
function get_require_deletion_reason(mysqli $conn): bool
{
    $required = false;

    $stmt = @$conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'require_deletion_reason' LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $stmt->bind_result($value);
        if ($stmt->fetch()) {
            $required = $value === '1';
        }
        $stmt->close();
    }

    return $required;
}

function save_require_deletion_reason(mysqli $conn, bool $required): bool
{
    $value = $required ? '1' : '0';

    $stmt = $conn->prepare("
        INSERT INTO settings (setting_key, setting_value) VALUES ('require_deletion_reason', ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("s", $value);
    $result = $stmt->execute();
    $stmt->close();

    return $result;
}

function get_storage_settings(mysqli $conn): array
{
    $root = get_data_root($conn);
    $urlRoot = DATA_ROOT_URL;

    return [
        'paths' => [
            'avatar_dir_fs'    => $root . 'avatars/',
            'avatar_dir_url'   => $urlRoot . 'avatars/',
            'photo_dir_fs'     => $root . 'exhibit-photos/',
            'photo_dir_url'    => $urlRoot . 'exhibit-photos/',
            'document_dir_fs'  => $root . 'exhibit-documents/',
            'document_dir_url' => $urlRoot . 'exhibit-documents/',
            'case_document_dir_fs'  => $root . 'case-documents/',
            'case_document_dir_url' => $urlRoot . 'case-documents/',
            'report_logo_dir_fs'    => $root . 'report-branding/',
            'report_logo_dir_url'   => $urlRoot . 'report-branding/',
            'receipt_dir_fs'        => $root . 'exhibit-receipts/',
        ],
    ];
}

// Stored filename only - combine with get_storage_settings()'s report_logo_dir paths.
function get_report_logo_filename(mysqli $conn): ?string
{
    $filename = null;

    $stmt = @$conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'report_logo_filename' LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $stmt->bind_result($value);
        if ($stmt->fetch() && !empty($value)) {
            $filename = $value;
        }
        $stmt->close();
    }

    return $filename;
}

function save_report_logo_filename(mysqli $conn, ?string $filename): bool
{
    $stmt = $conn->prepare("
        INSERT INTO settings (setting_key, setting_value) VALUES ('report_logo_filename', ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    if (!$stmt) {
        return false;
    }
    $value = $filename ?? '';
    $stmt->bind_param("s", $value);
    $result = $stmt->execute();
    $stmt->close();

    return $result;
}
