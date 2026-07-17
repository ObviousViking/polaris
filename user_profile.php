<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
include 'header.php';
require_once 'db.php';
require_once 'includes/settings.php';
require_once 'includes/achievements.php';

// Load configuration for file paths.
$config = get_storage_settings($conn);
$avatar_dir_url = $config['paths']['avatar_dir_url']; // Use URL path for display

$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT first_name, last_name, email, avatar, theme FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($first_name, $last_name, $email, $avatar, $theme);
$stmt->fetch();
$stmt->close();

// Use a default avatar if none is set.
if (empty($avatar)) {
    $avatar = 'default_avatar.png'; // Ensure this file exists in the configured avatar_dir_fs
}

$achievements = get_achievements_for_user($conn, $user_id);
$unlockedCount = count(array_filter($achievements, fn($a) => $a['unlocked_at'] !== null));
$totalCount = count($achievements);
?>

<!-- Custom styling for the user profile page -->
<style>
.profile-container {
    max-width: 800px;
    margin: 0 auto;
    padding: 20px;
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
}

.profile-card,
.password-card {
    background: var(--polaris-surface);
    padding: 20px;
    border-radius: 5px;
    flex: 1 1 300px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
}

.profile-card h3,
.password-card h3 {
    margin-top: 0;
    margin-bottom: 15px;
    font-size: 20px;
}

.avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    display: block;
    margin-bottom: 20px;
    object-fit: cover;
}

label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
    font-size: 14px;
}

input[type="text"],
input[type="password"],
input[type="file"],
select {
    width: 100%;
    padding: 10px;
    margin-bottom: 15px;
    border: 1px solid var(--polaris-border);
    border-radius: 3px;
    background: var(--polaris-bg);
    color: var(--polaris-text);
}

input[type="submit"] {
    background: var(--polaris-accent);
    border: none;
    padding: 5px 10px;
    border-radius: 3px;
    color: var(--polaris-text);
    font-size: 14px;
    cursor: pointer;
    transition: background 0.2s;
}

input[type="submit"]:hover {
    background: var(--polaris-accent-hover);
}

.message {
    margin-bottom: 15px;
    padding: 10px;
    border-radius: 3px;
    font-size: 14px;
}

.success {
    background-color: var(--polaris-success-bg);
    color: var(--polaris-success-text);
}

.error {
    background-color: var(--polaris-error-bg);
    color: var(--polaris-error-text);
}

.achievements-card {
    max-width: 800px;
    margin: 20px auto 0 auto;
    background: var(--polaris-surface);
    padding: 20px;
    border-radius: 5px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
}

.achievements-card h3 {
    margin-top: 0;
    margin-bottom: 10px;
    font-size: 20px;
}

.achievements-card .badge {
    display: inline-block;
    background: var(--polaris-border);
    color: var(--polaris-text-secondary);
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 15px;
}

.achievements-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 12px;
}

.achievement {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    background: var(--polaris-surface-alt);
    border-radius: 6px;
    padding: 12px;
}

.achievement.locked {
    opacity: 0.45;
}

.achievement .icon {
    font-size: 28px;
    line-height: 1;
    flex-shrink: 0;
}

.achievement .name {
    font-weight: bold;
    margin-bottom: 2px;
}

.achievement .description {
    color: var(--polaris-text-secondary);
    font-size: 13px;
    margin-bottom: 4px;
}

.achievement .unlocked-at {
    color: var(--polaris-text-muted);
    font-size: 12px;
}
</style>

<div class="content-wrapper">
    <h2>User Profile</h2>
    <?php
    if (isset($_SESSION['profile_message'])) {
        echo '<div class="message success">' . htmlspecialchars($_SESSION['profile_message']) . '</div>';
        unset($_SESSION['profile_message']);
    }
    if (isset($_SESSION['password_error'])) {
        echo '<div class="message error">' . htmlspecialchars($_SESSION['password_error']) . '</div>';
        unset($_SESSION['password_error']);
    }
    if (isset($_SESSION['password_success'])) {
        echo '<div class="message success">' . htmlspecialchars($_SESSION['password_success']) . '</div>';
        unset($_SESSION['password_success']);
    }
    ?>

    <div class="profile-container">
        <!-- Profile Information Card -->
        <div class="profile-card">
            <h3>Profile Information</h3>
            <!-- Display avatar using the URL path -->
            <img src="<?php echo htmlspecialchars($avatar_dir_url . $avatar); ?>" alt="Avatar" class="avatar">
            <form method="post" action="update_profile.php" enctype="multipart/form-data">
                <label for="avatar">Change Avatar:</label>
                <input type="file" name="avatar" id="avatar" accept="image/*">

                <label for="first_name">First Name:</label>
                <input type="text" name="first_name" id="first_name"
                    value="<?php echo htmlspecialchars($first_name); ?>" required>

                <label for="last_name">Last Name:</label>
                <input type="text" name="last_name" id="last_name" value="<?php echo htmlspecialchars($last_name); ?>"
                    required>

                <label for="theme">Theme:</label>
                <select name="theme" id="theme">
                    <option value="dark" <?php echo $theme === 'dark' ? 'selected' : ''; ?>>Dark</option>
                    <option value="light" <?php echo $theme === 'light' ? 'selected' : ''; ?>>Light</option>
                </select>

                <input type="submit" value="Update Profile">
            </form>
        </div>

        <!-- Change Password Card -->
        <div class="password-card">
            <h3>Change Password</h3>
            <form method="post" action="update_password.php">
                <label for="current_password">Current Password:</label>
                <input type="password" name="current_password" id="current_password" required>

                <label for="new_password">New Password:</label>
                <input type="password" name="new_password" id="new_password" required>

                <label for="confirm_password">Confirm New Password:</label>
                <input type="password" name="confirm_password" id="confirm_password" required>

                <input type="submit" value="Change Password">
            </form>
        </div>
    </div>

    <div class="achievements-card">
        <h3>Achievements</h3>
        <p class="badge"><?php echo str_pad((string) $unlockedCount, 2, '0', STR_PAD_LEFT); ?>/<?php echo str_pad((string) $totalCount, 2, '0', STR_PAD_LEFT); ?> unlocked</p>
        <div class="achievements-grid">
            <?php foreach ($achievements as $a): ?>
            <div class="achievement <?php echo $a['unlocked_at'] === null ? 'locked' : ''; ?>">
                <div class="icon"><?php echo htmlspecialchars($a['icon']); ?></div>
                <div>
                    <div class="name"><?php echo htmlspecialchars($a['name']); ?></div>
                    <div class="description"><?php echo htmlspecialchars($a['description']); ?></div>
                    <?php if ($a['unlocked_at'] !== null): ?>
                    <div class="unlocked-at">Unlocked <?php echo date('d/m/Y', strtotime($a['unlocked_at'])); ?></div>
                    <?php else: ?>
                    <div class="unlocked-at">Locked</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
</body>

</html>