<?php
// header.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/settings.php';

if (!isset($config)) {
$config = get_storage_settings($conn);
}



// Retrieve the logged-in user's name and notification count
$userName = "Guest"; // Default if not logged in
$unread_count = 0; // Default no notifications
$userTheme = "dark"; // Default if not logged in
$requireDeletionReason = get_require_deletion_reason($conn);

if (isset($_SESSION['user_id'])) {
$user_id = $_SESSION['user_id'];

// Get user's name and theme preference
if ($stmt = $conn->prepare("SELECT first_name, last_name, theme FROM users WHERE id = ? LIMIT 1")) {
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($first_name, $last_name, $userTheme);
if ($stmt->fetch()) {
$userName = $first_name . " " . $last_name;
}
$stmt->close();
}

// Get unread notification count
$notif_query = mysqli_query($conn, "SELECT COUNT(*) as unread FROM notifications WHERE user_id = $user_id AND is_read =
0");
if ($notif_row = mysqli_fetch_assoc($notif_query)) {
$unread_count = $notif_row['unread'];
}
}
?>
<!DOCTYPE html>
<html<?php echo $userTheme === 'light' ? ' data-theme="light"' : ''; ?>>

<head>
    <meta charset="UTF-8">
    <title>Polaris</title>
    <link rel="stylesheet" href="/assets/theme.css">
    <style>
    /* Global box-sizing */
    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        padding: 0;
        background-color: var(--polaris-bg);
        color: var(--polaris-text);
        font-family: Arial, sans-serif;
    }

    /* Fixed header styling */
    header {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 60px;
        background-color: var(--polaris-header-bg);
        border-bottom: 1px solid var(--polaris-border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 20px;
        z-index: 1000;
    }

    header .branding {
        display: flex;
        align-items: center;
    }

    header .branding img {
        height: 40px;
        margin-right: 10px;
    }

    header .branding h1 {
        margin: 0;
        font-size: 24px;
    }

    header .user-info {
        display: flex;
        align-items: center;
        white-space: nowrap;
    }

    header .user-info span {
        margin-right: 10px;
        font-size: 14px;
    }

    header .user-info a {
        text-decoration: none;
        color: var(--polaris-text);
        background-color: var(--polaris-border);
        padding: 5px 10px;
        border-radius: 3px;
        font-size: 14px;
        margin-right: 10px;
    }

    /* Horizontal navigation bar */
    nav {
        position: fixed;
        top: 60px;
        left: 0;
        width: 100%;
        height: 40px;
        background-color: var(--polaris-bg-alt);
        display: flex;
        align-items: center;
        padding: 0 20px;
        z-index: 999;
    }

    nav a {
        margin-right: 20px;
        text-decoration: none;
        color: var(--polaris-text);
        font-size: 16px;
    }

    nav a:hover {
        color: var(--polaris-text-secondary);
    }

    /* Content wrapper with horizontal margins */
    .content-wrapper {
        margin-top: 100px;
        /* header (60px) + nav (40px) */
        padding: 20px;
        max-width: 1200px;
        margin-left: auto;
        margin-right: auto;
    }

    #toast-container {
        position: fixed;
        bottom: 20px;
        right: 20px;
        z-index: 2000;
        display: flex;
        flex-direction: column;
        gap: 10px;
        max-width: 320px;
    }

    .polaris-toast {
        background: var(--polaris-bg-alt);
        color: var(--polaris-text);
        border-left: 4px solid var(--polaris-accent);
        padding: 12px 16px;
        border-radius: 4px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
        font-size: 14px;
        opacity: 1;
        transition: opacity 0.5s ease;
    }
    </style>
</head>

<body>
    <header>

        <div class="branding">
            <a href="/dashboard.php">
                <img src="/logo.png" alt="Logo">
            </a>
            <h1>Polaris</h1>
        </div>

        <div class="user-info">
            <?php
            if (isset($_SESSION['user_id'])) {
                echo '<span>Logged in as: ' . htmlspecialchars($userName) . '</span>';
                echo '<a href="/notifications.php">🔔 (' . $unread_count . ')</a>';
                echo '<a href="/logout.php">Logout</a>';
            }
            ?>
        </div>

    </header>

    <nav>
        <a href="/cargo_hold/ch_dashboard.php">Case Management</a>
        <a href="/captains_quarters/cq_dashboard.php">System Management</a>
        <a href="/logisticshub/lh_dashboard.php">Asset Management</a>
        <a href="/user_profile.php">User Profile</a>
    </nav>

    <script>
    // System Management -> System Settings -> "Require a reason for
    // deletions". This is UX only - the actual enforcement is server-side
    // (see includes/deletion_reason.php), so skipping/scripting around this
    // prompt can't unlock a delete that would otherwise be rejected.
    window.REQUIRE_DELETION_REASON = <?php echo $requireDeletionReason ? 'true' : 'false'; ?>;

    function confirmDeleteWithReason(form, message) {
        if (!window.REQUIRE_DELETION_REASON) {
            return confirm(message);
        }
        var reason = prompt(message + '\n\nA reason is required to delete this:');
        if (reason === null || reason.trim() === '') {
            alert('Deletion cancelled - a reason is required.');
            return false;
        }
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'delete_reason';
        input.value = reason.trim();
        form.appendChild(input);
        return true;
    }
    </script>

    <?php if (isset($_SESSION['user_id'])): ?>
    <div id="toast-container"></div>
    <script src="/assets/notifications.js"></script>
    <script>
    initPolarisNotifications('polaris_seen_notifications_<?php echo (int) $_SESSION['user_id']; ?>');
    </script>
    <?php endif; ?>