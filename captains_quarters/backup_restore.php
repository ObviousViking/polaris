<?php
// backup_restore.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/permissions.php';
require_permission($conn, 'manage_backup');

$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    include '../header.php';
}

$restoreMessage = $_SESSION['restore_message'] ?? null;
$restoreMessageType = $_SESSION['restore_message_type'] ?? 'error';
unset($_SESSION['restore_message'], $_SESSION['restore_message_type']);
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
    <h2>Backup / Restore</h2>

    <?php if ($restoreMessage): ?>
    <div class="message <?php echo htmlspecialchars($restoreMessageType); ?>">
        <?php echo htmlspecialchars($restoreMessage); ?>
    </div>
    <?php endif; ?>

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
