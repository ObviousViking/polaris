<?php
// setup.php
session_start();
require_once 'db.php';
require_once 'includes/settings.php';

// Only ever creates the first user. Refuses to run again once one exists.
$existing = $conn->query("SELECT COUNT(*) AS total FROM users");
if ($existing && ($row = $existing->fetch_assoc()) && (int)$row['total'] > 0) {
    header("Location: login.php");
    exit();
}

// If db.php didn't die() before this, the connection already succeeded.
$db_status_ok = true;
$db_host_display = getenv('DB_HOST') . ':' . (getenv('DB_PORT') ?: '3306');
$db_name_display = getenv('DB_NAME');
$db_user_display = getenv('DB_USER');

// Pre-filled with the mounted data volume; editable later from Storage Settings.
$data_root = get_data_root($conn);
$data_host_display = get_data_host_path_display();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['restore_backup'])) {
    require_once 'includes/backup.php';
    if (trim($_POST['confirm_phrase'] ?? '') !== 'RESTORE') {
        $error = "Restore cancelled - you must type RESTORE exactly to confirm.";
    } elseif (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        $uploadError = $_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $error = ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE)
            ? "Backup file is larger than this server currently allows to upload."
            : "No backup file was uploaded, or the upload failed.";
    } else {
        $result = backup_restore_archive($conn, $_FILES['backup_file']['tmp_name'], $_FILES['backup_file']['name']);
        if ($result['ok']) {
            header("Location: login.php?restored=1");
            exit();
        }
        $error = $result['error'];
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['restore_backup'])) {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';
    $data_root  = trim($_POST['data_root'] ?? '') ?: $data_root;
}

// Re-checked on every load so the status shown always matches what's about to be saved.
$data_root_exists = is_dir($data_root);
if (!$data_root_exists) {
    @mkdir($data_root, 0775, true);
    $data_root_exists = is_dir($data_root);
}
$data_root_writable = $data_root_exists && is_writable($data_root);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['restore_backup'])) {
    if ($first_name === '' || $last_name === '' || $email === '' || $password === '') {
        $error = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } elseif (!$data_root_writable) {
        $error = "The data storage path isn't writable - fix the path or its permissions before continuing.";
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, password, role) VALUES (?, ?, ?, ?, 'super')");
        $stmt->bind_param("ssss", $first_name, $last_name, $email, $hashed_password);

        if ($stmt->execute()) {
            // Log the new super user straight in and drop into the app.
            $new_user_id = $stmt->insert_id;
            $stmt->close();

            save_data_root($conn, $data_root);

            session_regenerate_id(true);
            $_SESSION['user_id'] = $new_user_id;
            header("Location: dashboard.php");
            exit();
        } else {
            $error = "Error: " . $conn->error;
            $stmt->close();
        }
    }
}

// Decide which step the wizard should open on. A fresh load always starts at
// step 1; an error re-opens whichever step it belongs to so the user isn't
// dropped back at the beginning.
$openStep = 1;
$openRestore = false;
if (isset($error)) {
    if (isset($_POST['restore_backup'])) {
        $openRestore = true;
    } elseif (strpos($error, 'data storage path') !== false) {
        $openStep = 2;
    } else {
        $openStep = 3;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Polaris Setup</title>
    <link rel="stylesheet" href="/assets/theme.css">
    <style>
    body {
        margin: 0;
        font-family: Arial, sans-serif;
        background: var(--polaris-bg);
        color: var(--polaris-text);
        display: flex;
        justify-content: center;
        padding: 40px 20px;
        box-sizing: border-box;
    }

    .container {
        max-width: 560px;
        width: 100%;
        padding: 20px;
        background: var(--polaris-surface);
        border-radius: 8px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
        box-sizing: border-box;
    }

    .branding {
        display: flex;
        justify-content: center;
        margin-bottom: 10px;
    }

    .branding img {
        height: 64px;
    }

    h1 {
        text-align: center;
        font-size: 24px;
        margin-bottom: 20px;
    }

    .step {
        border: 1px solid var(--polaris-border);
        border-radius: 6px;
        padding: 15px;
        margin-bottom: 20px;
    }

    .step h2 {
        margin: 0 0 10px;
        font-size: 16px;
        color: var(--polaris-text-dim);
    }

    .status-line {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
        margin-bottom: 4px;
    }

    .status-ok {
        color: var(--polaris-success-strong);
    }

    .status-bad {
        color: var(--polaris-danger-alt);
    }

    .status-detail {
        font-size: 13px;
        color: var(--polaris-text-muted);
        margin: 4px 0 0 22px;
    }

    .form-group {
        margin-bottom: 15px;
    }

    label {
        display: block;
        margin-bottom: 5px;
        color: var(--polaris-text-secondary);
    }

    input[type="text"],
    input[type="email"],
    input[type="password"] {
        width: 100%;
        max-width: 100%;
        padding: 8px;
        border: 1px solid var(--polaris-border);
        border-radius: 4px;
        background: var(--polaris-divider);
        color: var(--polaris-text);
        box-sizing: border-box;
    }

    input[type="submit"] {
        width: 100%;
        padding: 10px;
        background: var(--polaris-success-strong);
        border: none;
        border-radius: 4px;
        color: var(--polaris-text);
        font-size: 16px;
        cursor: pointer;
    }

    input[type="submit"]:hover {
        background: var(--polaris-success-strong-hover);
    }

    .error {
        color: var(--polaris-danger-alt);
        margin-bottom: 15px;
        text-align: center;
    }

    .info {
        color: var(--polaris-text-secondary);
        margin-bottom: 15px;
        text-align: center;
        font-size: 14px;
    }

    input[type="file"] {
        width: 100%;
        max-width: 100%;
        padding: 8px;
        border: 1px solid var(--polaris-border);
        border-radius: 4px;
        background: var(--polaris-divider);
        color: var(--polaris-text);
        box-sizing: border-box;
    }

    .setup-divider {
        display: flex;
        align-items: center;
        text-align: center;
        color: var(--polaris-text-faint);
        font-size: 13px;
        margin: 20px 0;
    }

    .setup-divider::before,
    .setup-divider::after {
        content: "";
        flex: 1;
        border-bottom: 1px solid var(--polaris-border);
    }

    .setup-divider span {
        padding: 0 10px;
    }

    input[type="submit"].restore-submit {
        background: var(--polaris-error-bg);
        color: var(--polaris-error-text);
    }

    input[type="submit"].restore-submit:hover {
        background: var(--polaris-danger);
    }

    .wizard-progress {
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 20px;
    }

    .wizard-dot {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        min-width: 26px;
        border-radius: 50%;
        border: 1px solid var(--polaris-border);
        color: var(--polaris-text-muted);
        font-size: 13px;
    }

    .wizard-dot.is-active {
        border-color: var(--polaris-success-strong);
        color: var(--polaris-text);
        background: var(--polaris-success-strong);
    }

    .wizard-dot.is-done {
        border-color: var(--polaris-success-strong);
        color: var(--polaris-success-strong);
    }

    .wizard-connector {
        flex: 1;
        height: 1px;
        background: var(--polaris-border);
        margin: 0 6px;
        max-width: 40px;
    }

    .wizard-panel {
        display: none;
    }

    .wizard-panel.is-active {
        display: block;
    }

    .btn-row {
        display: flex;
        gap: 10px;
        margin-top: 15px;
    }

    .btn-row input[type="submit"],
    .btn-row button {
        margin: 0;
    }

    button.btn-secondary {
        flex: 0 0 auto;
        padding: 10px 16px;
        background: transparent;
        border: 1px solid var(--polaris-border);
        border-radius: 4px;
        color: var(--polaris-text-secondary);
        font-size: 14px;
        cursor: pointer;
    }

    button.btn-secondary:hover {
        background: var(--polaris-divider);
    }

    .summary-list {
        list-style: none;
        margin: 0 0 15px;
        padding: 0;
        font-size: 14px;
    }

    .summary-list li {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        padding: 6px 0;
        border-bottom: 1px solid var(--polaris-border);
    }

    .summary-list li:last-child {
        border-bottom: none;
    }

    .summary-label {
        color: var(--polaris-text-muted);
    }

    .summary-value {
        color: var(--polaris-text);
        text-align: right;
        word-break: break-all;
    }

    .restore-toggle {
        text-align: center;
        font-size: 13px;
        margin-top: 10px;
    }

    .restore-toggle a,
    .back-to-setup {
        color: var(--polaris-text-secondary);
        cursor: pointer;
    }
    </style>
</head>

<body>
    <div class="container">
        <div class="branding">
            <img src="/logo.png" alt="Polaris logo">
        </div>
        <h1>Polaris Setup</h1>

        <?php if (isset($error)): ?>
        <div class="error">
            <p><?php echo htmlspecialchars($error); ?></p>
        </div>
        <?php endif; ?>

        <div id="wizard" data-open-step="<?php echo (int)$openStep; ?>"
            data-open-restore="<?php echo $openRestore ? '1' : '0'; ?>">

            <div class="wizard-progress" id="wizardProgress">
                <div class="wizard-dot" data-dot="1">1</div>
                <div class="wizard-connector"></div>
                <div class="wizard-dot" data-dot="2">2</div>
                <div class="wizard-connector"></div>
                <div class="wizard-dot" data-dot="3">3</div>
                <div class="wizard-connector"></div>
                <div class="wizard-dot" data-dot="4">4</div>
            </div>

            <form method="POST" action="" id="setupForm">

                <div class="wizard-panel" data-panel="1">
                    <div class="step">
                        <h2>1. Database Connection</h2>
                        <div class="status-line status-ok">&#10003; Connected</div>
                        <div class="status-detail">
                            <?php echo htmlspecialchars($db_user_display); ?>@<?php echo htmlspecialchars($db_host_display); ?>
                            / <?php echo htmlspecialchars($db_name_display); ?>
                        </div>
                    </div>
                    <div class="btn-row">
                        <button type="button" class="btn-secondary" data-goto="2" style="flex:1;">Next</button>
                    </div>
                    <p class="restore-toggle">Already running Polaris elsewhere?
                        <a id="showRestore">Restore from an existing backup instead</a>
                    </p>
                </div>

                <div class="wizard-panel" data-panel="2">
                    <div class="step">
                        <h2>2. Data Storage</h2>
                        <div class="status-line <?php echo $data_root_writable ? 'status-ok' : 'status-bad'; ?>">
                            <?php echo $data_root_writable ? '&#10003; Writable' : '&#10007; Not writable'; ?>
                        </div>
                        <div class="status-detail">Host path: <?php echo htmlspecialchars($data_host_display); ?></div>
                        <div class="status-detail">Avatars, exhibit photos, exhibit documents, and produced item
                            files are stored here (in fixed subfolders). Editable later from Case Management ->
                            Manage System Details.</div>
                    </div>
                    <div class="form-group">
                        <label for="data_root">Data storage root (container path - only change this if you
                            know what you're doing)</label>
                        <input type="text" id="data_root" name="data_root"
                            value="<?php echo htmlspecialchars($data_root); ?>">
                    </div>
                    <div class="btn-row">
                        <button type="button" class="btn-secondary" data-goto="1">Back</button>
                        <button type="button" class="btn-secondary" data-goto="3" style="flex:1;">Next</button>
                    </div>
                </div>

                <div class="wizard-panel" data-panel="3">
                    <div class="step">
                        <h2>3. Create Super User</h2>
                        <p class="info" style="text-align:left;">This account is purely for administrative
                            purposes and shouldn't be used as your personal account.</p>
                        <div class="form-group">
                            <label for="first_name">First Name</label>
                            <input type="text" id="first_name" name="first_name"
                                value="<?php echo htmlspecialchars($first_name ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name</label>
                            <input type="text" id="last_name" name="last_name"
                                value="<?php echo htmlspecialchars($last_name ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email"
                                value="<?php echo htmlspecialchars($email ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" required>
                        </div>
                    </div>
                    <div class="btn-row">
                        <button type="button" class="btn-secondary" data-goto="2">Back</button>
                        <button type="button" class="btn-secondary" data-goto="4" style="flex:1;">Next</button>
                    </div>
                </div>

                <div class="wizard-panel" data-panel="4">
                    <div class="step">
                        <h2>4. Review &amp; Create</h2>
                        <ul class="summary-list">
                            <li>
                                <span class="summary-label">Database</span>
                                <span class="summary-value"><?php echo htmlspecialchars($db_user_display); ?>@<?php echo htmlspecialchars($db_host_display); ?></span>
                            </li>
                            <li>
                                <span class="summary-label">Storage path</span>
                                <span class="summary-value" id="summaryDataRoot"></span>
                            </li>
                            <li>
                                <span class="summary-label">Name</span>
                                <span class="summary-value" id="summaryName"></span>
                            </li>
                            <li>
                                <span class="summary-label">Email</span>
                                <span class="summary-value" id="summaryEmail"></span>
                            </li>
                        </ul>
                    </div>
                    <div class="btn-row">
                        <button type="button" class="btn-secondary" data-goto="3">Back</button>
                        <input type="submit" value="Create Super User &amp; Continue">
                    </div>
                </div>

            </form>
        </div>

        <form method="POST" action="" enctype="multipart/form-data" id="restoreForm" style="display:none;"
            onsubmit="return confirm('This will replace the empty database that was just created with everything in the backup you upload. Are you sure?');">
            <div class="step">
                <h2>Restore from an Existing Backup</h2>
                <p class="info" style="text-align:left;">Already running Polaris elsewhere? Restore a full backup
                    (database + uploaded files) here instead of creating a new super user - it brings its own users,
                    cases, and settings with it.</p>
                <div class="form-group">
                    <label for="backup_file">Backup file (.tar.gz)</label>
                    <input type="file" name="backup_file" id="backup_file" accept=".gz,.tar.gz,.tgz" required>
                </div>
                <div class="form-group">
                    <label for="confirm_phrase">Type RESTORE to confirm</label>
                    <input type="text" name="confirm_phrase" id="confirm_phrase" autocomplete="off" required>
                </div>
                <div class="btn-row">
                    <input type="submit" name="restore_backup" value="Restore from Backup" class="restore-submit">
                </div>
                <p class="restore-toggle"><a class="back-to-setup" id="hideRestore">&larr; Back to setup</a></p>
            </div>
        </form>
    </div>

    <script>
    (function() {
        var wizard = document.getElementById('wizard');
        var panels = wizard.querySelectorAll('.wizard-panel');
        var dots = wizard.querySelectorAll('.wizard-dot');
        var restoreForm = document.getElementById('restoreForm');
        var showRestore = document.getElementById('showRestore');
        var hideRestore = document.getElementById('hideRestore');

        function showPanel(step) {
            panels.forEach(function(panel) {
                panel.classList.toggle('is-active', panel.getAttribute('data-panel') === String(step));
            });
            dots.forEach(function(dot) {
                var dotStep = parseInt(dot.getAttribute('data-dot'), 10);
                dot.classList.toggle('is-active', dotStep === step);
                dot.classList.toggle('is-done', dotStep < step);
            });
            if (step === 4) {
                populateSummary();
            }
        }

        function populateSummary() {
            var dataRoot = document.getElementById('data_root').value;
            var firstName = document.getElementById('first_name').value;
            var lastName = document.getElementById('last_name').value;
            var email = document.getElementById('email').value;
            document.getElementById('summaryDataRoot').textContent = dataRoot;
            document.getElementById('summaryName').textContent = (firstName + ' ' + lastName).trim();
            document.getElementById('summaryEmail').textContent = email;
        }

        function validatePanel(step) {
            var panel = wizard.querySelector('.wizard-panel[data-panel="' + step + '"]');
            var fields = panel.querySelectorAll('input[required]');
            for (var i = 0; i < fields.length; i++) {
                if (!fields[i].reportValidity()) {
                    return false;
                }
            }
            return true;
        }

        wizard.querySelectorAll('[data-goto]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var current = wizard.querySelector('.wizard-panel.is-active').getAttribute('data-panel');
                var target = parseInt(btn.getAttribute('data-goto'), 10);
                // Only validate when moving forward off the current panel.
                if (target > parseInt(current, 10) && !validatePanel(current)) {
                    return;
                }
                showPanel(target);
            });
        });

        function openRestore() {
            wizard.style.display = 'none';
            restoreForm.style.display = 'block';
        }

        function closeRestore() {
            wizard.style.display = 'block';
            restoreForm.style.display = 'none';
            showPanel(1);
        }

        showRestore.addEventListener('click', openRestore);
        hideRestore.addEventListener('click', closeRestore);

        var openStep = parseInt(wizard.getAttribute('data-open-step'), 10) || 1;
        var openRestoreFlag = wizard.getAttribute('data-open-restore') === '1';
        if (openRestoreFlag) {
            openRestore();
        } else {
            showPanel(openStep);
        }
    })();
    </script>
</body>

</html>
