<?php
// system_settings.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';

// Check admin privileges (assumes an admin has role 'admin' or 'super').
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($role);
$stmt->fetch();
$stmt->close();

if ($role !== 'admin' && $role !== 'super') {
    header("Location: ../dashboard.php");
    exit();
}

require_once '../includes/settings.php';
require_once '../includes/audit.php';

$settingsMessage = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deletion_reason_settings_form'])) {
    $required = ($_POST['require_deletion_reason_toggle'] ?? '0') === '1';
    if (save_require_deletion_reason($conn, $required)) {
        log_audit_event($conn, 'setting', null, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['setting_key' => 'require_deletion_reason', 'setting_value' => $required ? '1' : '0']));
        $settingsMessage = $required ? "Deletion reasons are now required." : "Deletion reasons are no longer required.";
    } else {
        $settingsMessage = "Error updating setting.";
    }
}

$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    include '../header.php';
}

$restoreMessage = $_SESSION['restore_message'] ?? null;
$restoreMessageType = $_SESSION['restore_message_type'] ?? 'error';
unset($_SESSION['restore_message'], $_SESSION['restore_message_type']);

$requireDeletionReasonSetting = get_require_deletion_reason($conn);

$reportLogoFilename = get_report_logo_filename($conn);
$reportLogoUrl = null;
if ($reportLogoFilename !== null) {
    $storageConfig = get_storage_settings($conn);
    $reportLogoUrl = $storageConfig['paths']['report_logo_dir_url'] . $reportLogoFilename;
}
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

    .checkbox-row {
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 12px 0;
        font-size: 14px;
        color: var(--polaris-text-secondary);
    }

    .checkbox-row input {
        width: auto;
    }

    .message {
        margin-bottom: 15px;
        padding: 10px;
        border-radius: 3px;
        font-size: 14px;
    }

    .error {
        background-color: var(--polaris-error-bg);
        color: var(--polaris-error-text);
    }

    .success {
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
    <h2>System Settings</h2>

    <?php if ($restoreMessage): ?>
    <div class="message <?php echo htmlspecialchars($restoreMessageType); ?>">
        <?php echo htmlspecialchars($restoreMessage); ?>
    </div>
    <?php endif; ?>

    <?php if ($settingsMessage): ?>
    <div class="message success"><?php echo htmlspecialchars($settingsMessage); ?></div>
    <?php endif; ?>

    <div class="card">
        <h3>Deletion Reasons</h3>
        <p>When on, deleting anything in Polaris - exhibits, case updates, case types, statuses, exhibit locations,
            and Process Builder templates/fields - requires the person deleting it to type a reason first. The reason
            is recorded alongside the deletion in the audit trail.</p>
        <form method="post" action="system_settings.php<?php echo $embedded ? '?embedded=1' : ''; ?>">
            <input type="hidden" name="deletion_reason_settings_form" value="1">
            <input type="hidden" name="require_deletion_reason_toggle" value="0">
            <div class="toggle-row">
                <input type="checkbox" id="require_deletion_reason_toggle_cb" name="require_deletion_reason_toggle"
                    value="1" <?php echo $requireDeletionReasonSetting ? 'checked' : ''; ?>
                    onchange="this.form.submit()">
                <label for="require_deletion_reason_toggle_cb">Require a reason for every deletion</label>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Report Branding</h3>
        <p>Logo shown on the printable Case Report letterhead. PNG or JPG, max 2MB.</p>
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
        <h3>Backup</h3>
        <p>Downloads a single archive containing a full database dump and every uploaded file (avatars, exhibit
            photos, exhibit documents, case documents). Keep it somewhere safe - it contains everything, including
            password hashes.</p>
        <a href="backup_download.php" class="action-btn">Download Full Backup</a>
    </div>

    <div class="card">
        <h3>Restore</h3>
        <p><strong>This replaces the entire database and every uploaded file with what's in the backup you
                upload.</strong> Anything created or changed since that backup was taken - cases, exhibits, users,
            tasks, everything - will be gone. This cannot be undone from inside the app. You'll be logged out
            afterwards and need to sign in again.</p>

        <form method="post" action="restore_process.php" enctype="multipart/form-data"
            onsubmit="return confirm('This will permanently replace the entire database and all uploaded files. Are you absolutely sure?');">
            <label for="backup_file">Backup file (.tar.gz)</label>
            <input type="file" name="backup_file" id="backup_file" accept=".gz,.tar.gz,.tgz" required>

            <label for="confirm_phrase">Type RESTORE to confirm</label>
            <input type="text" name="confirm_phrase" id="confirm_phrase" autocomplete="off" required>

            <div class="checkbox-row">
                <input type="checkbox" id="ack" required>
                <label for="ack" style="margin:0;">I understand this permanently overwrites all current data and
                    cannot be undone.</label>
            </div>

            <button type="submit" class="action-btn danger-btn" style="margin-top:10px;">Restore from Backup</button>
        </form>
    </div>

    <?php if (!$embedded): ?>
    <a href="cq_dashboard.php" class="back-btn">Go Back</a>
    <?php endif; ?>
</div>
