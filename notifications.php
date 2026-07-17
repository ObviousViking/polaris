<?php
// notifications.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once 'db.php';

// Handle marking a single notification as read
if (isset($_GET['mark_read'])) {
    $notif_id = intval($_GET['mark_read']);
    $user_id = $_SESSION['user_id'];

    $update = "UPDATE notifications SET is_read = 1 WHERE id = $notif_id AND user_id = $user_id";
    mysqli_query($conn, $update);

    header("Location: notifications.php" . (isset($_GET['show_read']) ? '?show_read=1' : ''));
    exit();
}

// Handle marking ALL notifications as read
if (isset($_GET['mark_all_read'])) {
    $user_id = $_SESSION['user_id'];

    $update_all = "UPDATE notifications SET is_read = 1 WHERE user_id = $user_id AND is_read = 0";
    mysqli_query($conn, $update_all);

    header("Location: notifications.php" . (isset($_GET['show_read']) ? '?show_read=1' : ''));
    exit();
}

// header.php must come after both redirect branches above, since it outputs
// HTML immediately and would break the redirect otherwise.
include 'header.php';

// Read notifications hidden by default; the toggle opts back into full history.
$showRead = isset($_GET['show_read']);

$user_id = $_SESSION['user_id'];
$query = "SELECT * FROM notifications WHERE user_id = $user_id" . ($showRead ? "" : " AND is_read = 0") . " ORDER BY created_at DESC";
$result = mysqli_query($conn, $query);

$unreadCountQuery = mysqli_query($conn, "SELECT COUNT(*) AS c FROM notifications WHERE user_id = $user_id AND is_read = 0");
$unreadCount = (int) (mysqli_fetch_assoc($unreadCountQuery)['c'] ?? 0);
?>

<div class="content-wrapper">
    <h2>Notifications</h2>

    <div class="toolbar">
        <a href="notifications.php<?php echo $showRead ? '' : '?show_read=1'; ?>" class="action-btn">
            <?php echo $showRead ? 'Hide Read Notifications' : 'Show Read Notifications'; ?>
        </a>
        <?php if ($unreadCount > 0): ?>
        <a href="notifications.php?mark_all_read=1<?php echo $showRead ? '&show_read=1' : ''; ?>" class="action-btn">
            Mark All as Read
        </a>
        <?php endif; ?>
    </div>

    <?php if (mysqli_num_rows($result) > 0): ?>
    <div class="table-scroll">
        <table>
            <tr>
                <th>Message</th>
                <th>Date</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
            <?php while ($notif = mysqli_fetch_assoc($result)): ?>
            <tr class="<?php echo $notif['is_read'] ? 'read-row' : ''; ?>">
                <td><?php echo htmlspecialchars($notif['message']); ?></td>
                <td><?php echo date('d/m/Y H:i', strtotime($notif['created_at'])); ?></td>
                <td>
                    <?php if ($notif['is_read']): ?>
                    <span class="badge">Read</span>
                    <?php else: ?>
                    <span class="badge badge-unread">Unread</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!$notif['is_read']): ?>
                    <a href="notifications.php?mark_read=<?php echo $notif['id']; ?><?php echo $showRead ? '&show_read=1' : ''; ?>"
                        class="action-btn small">
                        Mark as Read
                    </a>
                    <?php else: ?>
                    -
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>
    <?php else: ?>
    <p class="empty-message"><?php echo $showRead ? 'No notifications yet.' : 'No unread notifications.'; ?></p>
    <?php endif; ?>

    <div class="toolbar" style="margin-top: 20px;">
        <a href="dashboard.php" class="action-btn">&larr; Back to Dashboard</a>
    </div>
</div>

<style>
.toolbar {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
    flex-wrap: wrap;
}

.action-btn {
    display: inline-block;
    padding: 6px 12px;
    background-color: var(--polaris-accent);
    color: var(--polaris-text);
    font-size: 13px;
    text-decoration: none;
    border-radius: 3px;
}

.action-btn:hover {
    background-color: var(--polaris-accent-hover);
}

.action-btn.small {
    padding: 3px 8px;
    font-size: 12px;
}

.table-scroll {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    background: var(--polaris-surface);
    border-radius: 5px;
}

th, td {
    padding: 8px 10px;
    text-align: left;
    border-bottom: 1px solid var(--polaris-border);
    font-size: 13px;
    vertical-align: top;
}

th {
    background: var(--polaris-divider);
    white-space: nowrap;
}

tr:last-child td {
    border-bottom: none;
}

tr:hover td {
    background: var(--polaris-surface-alt);
}

.read-row {
    opacity: 0.6;
}

.badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background: var(--polaris-divider);
    color: var(--polaris-text-secondary);
    white-space: nowrap;
}

.badge-unread {
    background: var(--polaris-accent);
    color: var(--polaris-text);
}

.empty-message {
    text-align: center;
    margin-top: 50px;
    color: var(--polaris-text-muted);
}
</style>
