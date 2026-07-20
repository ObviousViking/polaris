<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once('../includes/settings.php');
require_once '../includes/audit.php';
$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    require_once('../header.php');
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data_root = trim($_POST['data_root'] ?? '');

    if ($data_root === '') {
        $message = "Data storage root cannot be empty.";
    } elseif (save_data_root($conn, $data_root)) {
        log_audit_event($conn, 'setting', null, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['setting_key' => 'data_root_dir', 'setting_value' => $data_root]));
        $message = "Storage settings updated.";
    } else {
        $message = "Error updating storage settings.";
    }
}

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

    label {
        display: block;
        margin: 10px 0 5px;
        color: var(--polaris-text-secondary);
    }

    input[type="text"] {
        width: 100%;
        max-width: 100%;
        padding: 8px;
        margin-bottom: 10px;
        background: var(--polaris-bg);
        border: 1px solid var(--polaris-border);
        color: var(--polaris-text);
        box-sizing: border-box;
        border-radius: 4px;
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
        transition: background 0.3s ease;
    }

    .action-btn:hover {
        background: var(--polaris-accent-hover);
    }

    .message {
        margin-bottom: 10px;
        padding: 10px;
        background: var(--polaris-divider);
        border-left: 4px solid var(--polaris-accent);
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
        <h2>Manage Storage Settings</h2>
        <p style="color:var(--polaris-text-dim);">Avatars, exhibit photos, and exhibit documents are all stored
            under this root path (in fixed subfolders). Host path:
            <strong><?php echo htmlspecialchars(get_data_host_path_display()); ?></strong> - only
            change the field below if you've changed the volume mount in docker-compose.yml.</p>

        <?php if (!empty($message)): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="post" action="manage_storage_settings.php<?php echo $embedded ? '?embedded=1' : ''; ?>">
            <label for="data_root">Data storage root (container path)</label>
            <input type="text" name="data_root" id="data_root"
                value="<?php echo htmlspecialchars($data_root); ?>">
            <button type="submit" class="action-btn">Save</button>
        </form>

        <br>
        <?php if (!$embedded): ?>
        <a href="cq_dashboard.php" class="back-btn">Go Back</a>
        <?php endif; ?>
    </div>
</body>

</html>
