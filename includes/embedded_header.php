<?php
// includes/embedded_header.php
//
// Minimal chrome for a page loaded inside the System Management nav+iframe
// shell (captains_quarters/cq_dashboard.php) - just enough dark theming to
// match the site, without re-rendering the full site header/nav (which
// would otherwise duplicate inside the iframe alongside the outer page's
// own header/nav).
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/settings.php';

$userTheme = "dark";
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    if ($stmt = $conn->prepare("SELECT theme FROM users WHERE id = ? LIMIT 1")) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($userTheme);
        $stmt->fetch();
        $stmt->close();
    }
}
$requireDeletionReason = get_require_deletion_reason($conn);
?>
<!DOCTYPE html>
<html lang="en"<?php echo $userTheme === 'light' ? ' data-theme="light"' : ''; ?>>

<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="/assets/theme.css">
    <style>
    body {
        margin: 0;
        padding: 20px;
        font-family: Arial, sans-serif;
        background: var(--polaris-bg);
        color: var(--polaris-text);
    }

    /* Pages that don't define their own layout container rely on this
       class from header.php, which includes a 100px top margin to clear
       the site header/nav - not needed here since neither is rendered. */
    .content-wrapper {
        padding: 20px;
        max-width: 1200px;
        margin: 0 auto;
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
    <script>
    // Same reason-required-to-delete UX as header.php - see the comment
    // there. Server-side enforcement lives in includes/deletion_reason.php.
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
