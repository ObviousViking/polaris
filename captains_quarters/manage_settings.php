<?php
// manage_settings.php
//
// Combines Deletion Reasons, Report Branding, SLA, and Storage Settings
// onto one tab. Storage Settings needs to stay open to normal users (see
// cq_dashboard.php), so this page has no hard gate - the other three cards
// are conditionally rendered (and their POST handlers re-checked) against
// the manage_settings permission instead.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/settings.php';
require_once '../includes/audit.php';
require_once '../includes/permissions.php';

$isAdmin = user_can($conn, (int) $_SESSION['user_id'], 'manage_settings');

$deletionMessage = "";
$slaMessage = "";
$storageMessage = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deletion_reason_settings_form']) && $isAdmin) {
    $required = ($_POST['require_deletion_reason_toggle'] ?? '0') === '1';
    if (save_require_deletion_reason($conn, $required)) {
        log_audit_event($conn, 'setting', null, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['setting_key' => 'require_deletion_reason', 'setting_value' => $required ? '1' : '0']));
        $deletionMessage = $required ? "Deletion reasons are now required." : "Deletion reasons are no longer required.";
    } else {
        $deletionMessage = "Error updating setting.";
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sla_settings_form']) && $isAdmin) {
    $days = intval($_POST['sla_days'] ?? 0);
    if ($days < 1) {
        $slaMessage = "SLA must be at least 1 day.";
    } elseif (save_strategy_due_sla_days($conn, $days)) {
        log_audit_event($conn, 'setting', null, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['setting_key' => 'strategy_due_sla_days', 'setting_value' => $days]));
        $slaMessage = "SLA updated.";
    } else {
        $slaMessage = "Error updating SLA.";
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['storage_settings_form'])) {
    $data_root = trim($_POST['data_root'] ?? '');
    if ($data_root === '') {
        $storageMessage = "Data storage root cannot be empty.";
    } elseif (save_data_root($conn, $data_root)) {
        log_audit_event($conn, 'setting', null, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['setting_key' => 'data_root_dir', 'setting_value' => $data_root]));
        $storageMessage = "Storage settings updated.";
    } else {
        $storageMessage = "Error updating storage settings.";
    }
}

$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    include '../header.php';
}

$logoMessage = $_SESSION['logo_message'] ?? null;
$logoMessageType = $_SESSION['logo_message_type'] ?? 'error';
unset($_SESSION['logo_message'], $_SESSION['logo_message_type']);

$requireDeletionReasonSetting = get_require_deletion_reason($conn);

$reportLogoFilename = get_report_logo_filename($conn);
$reportLogoUrl = null;
if ($reportLogoFilename !== null) {
    $storageConfig = get_storage_settings($conn);
    $reportLogoUrl = $storageConfig['paths']['report_logo_dir_url'] . $reportLogoFilename;
}

$slaDays = get_strategy_due_sla_days($conn);
$data_root = get_data_root($conn);
?>
<style>
    body {
        margin: 0;
        font-family: Arial, sans-serif;
        background: var(--polaris-bg);
        color: var(--polaris-text);
        padding-top: <?php echo $embedded ? '0' : '120px'; ?>;
    }

    .container {
        max-width: 800px;
        margin: 20px auto;
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
        box-sizing: border-box;
    }

    h2 {
        text-align: center;
        font-size: 24px;
        margin-bottom: 20px;
    }

    h3 {
        margin-bottom: 5px;
    }

    .card {
        background: var(--polaris-surface-alt);
        border-radius: 6px;
        padding: 15px 20px;
        margin-bottom: 20px;
    }

    .card p {
        color: var(--polaris-text-secondary);
        font-size: 14px;
    }

    .action-btn {
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border: none;
        padding: 5px 10px;
        cursor: pointer;
        border-radius: 3px;
        text-decoration: none;
        display: inline-block;
        font-size: 14px;
        transition: background 0.3s ease;
    }

    .action-btn:hover {
        background: var(--polaris-accent-hover);
    }

    .danger-btn {
        background: var(--polaris-error-bg);
        color: var(--polaris-error-text);
    }

    .danger-btn:hover {
        background: var(--polaris-danger);
    }

    label {
        display: block;
        margin: 12px 0 5px;
        color: var(--polaris-text-secondary);
        font-size: 14px;
    }

    input[type="text"],
    input[type="number"],
    input[type="file"] {
        width: 100%;
        max-width: 100%;
        padding: 8px;
        background: var(--polaris-bg);
        border: 1px solid var(--polaris-border);
        color: var(--polaris-text);
        box-sizing: border-box;
        border-radius: 4px;
    }

    .message {
        margin-top: 10px;
        margin-bottom: 10px;
        padding: 10px;
        border-radius: 3px;
        font-size: 14px;
    }

    .message.error {
        background-color: var(--polaris-error-bg);
        color: var(--polaris-error-text);
    }

    .message.success {
        background-color: var(--polaris-success-bg);
        color: var(--polaris-success-text);
    }

    .toggle-row {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .toggle-row label {
        margin: 0;
        color: var(--polaris-text);
        font-size: 15px;
    }

    .back-btn {
        display: inline-block;
        padding: 5px 10px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border: none;
        border-radius: 3px;
        text-decoration: none;
        text-align: center;
        font-size: 14px;
        cursor: pointer;
        transition: background 0.3s ease;
        margin-bottom: 20px;
    }

    .back-btn:hover {
        background: var(--polaris-accent-hover);
    }
</style>

<div class="container">
    <h2>Settings</h2>

    <?php if ($isAdmin): ?>
    <div class="card">
        <h3>Deletion Reasons</h3>
        <p>When on, deleting anything in Polaris - exhibits, case updates, case types, statuses, exhibit locations,
            and Process Builder templates/fields - requires the person deleting it to type a reason first. The reason
            is recorded alongside the deletion in the audit trail.</p>
        <form method="post" action="manage_settings.php<?php echo $embedded ? '?embedded=1' : ''; ?>">
            <input type="hidden" name="deletion_reason_settings_form" value="1">
            <input type="hidden" name="require_deletion_reason_toggle" value="0">
            <div class="toggle-row">
                <input type="checkbox" id="require_deletion_reason_toggle_cb" name="require_deletion_reason_toggle"
                    value="1" <?php echo $requireDeletionReasonSetting ? 'checked' : ''; ?>
                    onchange="this.form.submit()">
                <label for="require_deletion_reason_toggle_cb">Require a reason for every deletion</label>
            </div>
        </form>
        <?php if ($deletionMessage): ?>
        <div class="message success"><?php echo htmlspecialchars($deletionMessage); ?></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Report Branding</h3>
        <p>Logo shown on the printable Case Report letterhead. PNG or JPG, max 2MB.</p>
        <?php if ($logoMessage): ?>
        <div class="message <?php echo htmlspecialchars($logoMessageType); ?>"><?php echo htmlspecialchars($logoMessage); ?></div>
        <?php endif; ?>
        <?php if ($reportLogoUrl): ?>
        <div style="margin:10px 0;">
            <img src="<?php echo htmlspecialchars($reportLogoUrl); ?>" alt="Current report logo"
                style="max-height:70px; max-width:260px; background:#fff; padding:6px; border-radius:4px;">
        </div>
        <form method="post" action="upload_report_logo.php" style="display:inline;">
            <input type="hidden" name="remove_logo" value="1">
            <button type="submit" class="action-btn danger-btn"
                onclick="return confirm('Remove the report logo?');">Remove Logo</button>
        </form>
        <?php else: ?>
        <p class="empty-note" style="color: var(--polaris-text-secondary); font-style:italic;">No logo uploaded yet.</p>
        <?php endif; ?>
        <form method="post" action="upload_report_logo.php" enctype="multipart/form-data" style="margin-top:10px;">
            <label for="logo">Upload new logo</label>
            <input type="file" name="logo" id="logo" accept=".png,.jpg,.jpeg" required>
            <button type="submit" class="action-btn" style="margin-top:10px;">Upload</button>
        </form>
    </div>

    <div class="card">
        <h3>Configure SLA</h3>
        <p>When a new case is created, "Strategy Due" is set automatically to this many days after "Strategy Set"
            (the case's creation time). Changing this only affects cases created from now on - existing cases keep
            whatever due date they already have.</p>
        <form method="post" action="manage_settings.php<?php echo $embedded ? '?embedded=1' : ''; ?>">
            <input type="hidden" name="sla_settings_form" value="1">
            <label for="sla_days">Strategy Due SLA (days)</label>
            <input type="number" name="sla_days" id="sla_days" min="1" value="<?php echo (int) $slaDays; ?>">
            <button type="submit" class="action-btn">Save</button>
        </form>
        <?php if ($slaMessage): ?>
        <div class="message success"><?php echo htmlspecialchars($slaMessage); ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <h3>Manage Storage Settings</h3>
        <p>Avatars, exhibit photos, and exhibit documents are all stored under this root path (in fixed subfolders).
            Host path: <strong><?php echo htmlspecialchars(get_data_host_path_display()); ?></strong> - only change
            the field below if you've changed the volume mount in docker-compose.yml.</p>
        <form method="post" action="manage_settings.php<?php echo $embedded ? '?embedded=1' : ''; ?>">
            <input type="hidden" name="storage_settings_form" value="1">
            <label for="data_root">Data storage root (container path)</label>
            <input type="text" name="data_root" id="data_root" value="<?php echo htmlspecialchars($data_root); ?>">
            <button type="submit" class="action-btn">Save</button>
        </form>
        <?php if ($storageMessage): ?>
        <div class="message success"><?php echo htmlspecialchars($storageMessage); ?></div>
        <?php endif; ?>
    </div>

    <?php if (!$embedded): ?>
    <a href="cq_dashboard.php" class="back-btn">Go Back</a>
    <?php endif; ?>
</div>
