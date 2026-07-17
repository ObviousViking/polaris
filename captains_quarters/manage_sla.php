<?php
// manage_sla.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/settings.php';
require_once '../includes/audit.php';

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

$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    include '../header.php';
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $days = intval($_POST['sla_days'] ?? 0);
    if ($days < 1) {
        $message = "SLA must be at least 1 day.";
    } elseif (save_strategy_due_sla_days($conn, $days)) {
        log_audit_event($conn, 'setting', null, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['setting_key' => 'strategy_due_sla_days', 'setting_value' => $days]));
        $message = "SLA updated.";
    } else {
        $message = "Error updating SLA.";
    }
}

$slaDays = get_strategy_due_sla_days($conn);
?>
<style>
    body {
        padding-top: <?php echo $embedded ? '0' : '120px'; ?>;
    }

    .container {
        max-width: 600px;
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

    input[type="number"] {
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
        <h2>Configure SLA</h2>
        <p style="color:var(--polaris-text-dim);">When a new case is created, "Strategy Due" is set automatically
            to this many days after "Strategy Set" (which is always the case's creation time).
            Changing this only affects cases created from now on - existing cases keep whatever
            due date they already have.</p>

        <?php if (!empty($message)): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="post">
            <label for="sla_days">Strategy Due SLA (days)</label>
            <input type="number" name="sla_days" id="sla_days" min="1" value="<?php echo (int) $slaDays; ?>">
            <button type="submit" class="action-btn">Save</button>
        </form>

        <?php if (!$embedded): ?>
        <br>
        <a href="cq_dashboard.php" class="back-btn">Go Back</a>
        <?php endif; ?>
    </div>
</body>

</html>
