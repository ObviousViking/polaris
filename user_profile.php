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
$achievementDisplay = get_achievement_groups_for_user($conn, $user_id);

$stats = [
    ['label' => 'Cases Created', 'value' => compute_metric($conn, 'cases_created', $user_id), 'icon' => '📁'],
    ['label' => 'Exhibits Booked In', 'value' => compute_metric($conn, 'exhibits_booked_in', $user_id), 'icon' => '🔍'],
    ['label' => 'Examinations Completed', 'value' => compute_metric($conn, 'examinations_completed', $user_id), 'icon' => '🧪'],
    ['label' => 'Tasks Completed', 'value' => compute_metric($conn, 'tasks_completed', $user_id), 'icon' => '📋'],
];
?>

<!-- Custom styling for the user profile page -->
<style>
/* header.php's shared .content-wrapper caps out at 1200px and centers
   itself - fine for a form-width page, but this page has a wide
   achievements grid that should actually use the screen, so it opts out
   of both the cap and the centering here. */
.content-wrapper {
    max-width: none;
    margin-left: 0;
    margin-right: 0;
}

/* Same vertical nav-bar pattern as Case Management/System Management
   (.cq-nav / .lh-nav) - a left-hand column of buttons instead of top tabs,
   just swapping their page-navigation links for tab-panel toggling here
   since this is one page, not several. */
.profile-shell {
    display: flex;
    align-items: flex-start;
    gap: 20px;
}

.profile-nav {
    flex: 0 0 200px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    position: sticky;
    top: 120px;
}

.tab-btn {
    display: block;
    width: 100%;
    text-align: left;
    background-color: var(--polaris-border);
    color: var(--polaris-text);
    padding: 12px 15px;
    border: none;
    border-radius: 5px;
    font-size: 15px;
    cursor: pointer;
    transition: background 0.2s ease-in-out;
}

.tab-btn:hover,
.tab-btn.active {
    background-color: var(--polaris-border-hover-2);
}

.tab-btn.active {
    font-weight: bold;
}

.profile-content {
    flex: 1;
    min-width: 0;
}

@media (max-width: 900px) {
    .profile-shell {
        flex-direction: column;
    }

    .profile-nav {
        flex-direction: row;
        flex-wrap: wrap;
        width: 100%;
        position: static;
    }

    .tab-btn {
        flex: 1 1 auto;
        width: auto;
    }
}

.tab-panel {
    display: none;
}

.tab-panel.active {
    display: block;
}

.form-card {
    background: var(--polaris-surface);
    padding: 20px;
    border-radius: 5px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
    max-width: 600px;
}

.form-card.narrow {
    max-width: 420px;
}

.profile-form-body {
    display: flex;
    gap: 25px;
    align-items: flex-start;
    flex-wrap: wrap;
}

.profile-form-fields {
    flex: 1;
    min-width: 220px;
}

.field-row {
    display: flex;
    gap: 15px;
}

.field-row>div {
    flex: 1;
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
    box-sizing: border-box;
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
    max-width: 600px;
}

.success {
    background-color: var(--polaris-success-bg);
    color: var(--polaris-success-text);
}

.error {
    background-color: var(--polaris-error-bg);
    color: var(--polaris-error-text);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}

.stat-box {
    background: var(--polaris-surface);
    border-radius: 5px;
    padding: 15px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
}

.stat-icon {
    font-size: 24px;
    flex-shrink: 0;
}

.stat-value {
    font-size: 20px;
    font-weight: bold;
    color: var(--polaris-text);
}

.stat-label {
    font-size: 11px;
    color: var(--polaris-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.achievements-card {
    background: var(--polaris-surface);
    padding: 20px;
    border-radius: 5px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
}

.achievement-groups {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 12px;
    margin-bottom: 15px;
}

.achievement-group {
    background: var(--polaris-surface-alt);
    border-radius: 6px;
    padding: 12px;
}

.achievement-group.locked {
    opacity: 0.6;
}

.group-header {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 10px;
}

.group-header .icon {
    font-size: 26px;
    line-height: 1;
    flex-shrink: 0;
}

.group-header .name {
    font-weight: bold;
    font-size: 14px;
}

.group-header .tier-name {
    color: var(--polaris-text-secondary);
    font-size: 12px;
}

.progress-bar {
    background: var(--polaris-divider);
    border-radius: 10px;
    height: 8px;
    overflow: hidden;
    margin-bottom: 4px;
}

.progress-fill {
    background: var(--polaris-accent);
    height: 100%;
    border-radius: 10px;
}

.progress-text {
    font-size: 11px;
    color: var(--polaris-text-muted);
    margin-bottom: 8px;
}

.tier-pips {
    display: flex;
    gap: 5px;
}

.tier-pips .pip {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--polaris-divider);
}

.tier-pips .pip.unlocked {
    background: var(--polaris-accent);
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
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
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
    <h2>User Profile for <?php echo htmlspecialchars(trim($first_name . ' ' . $last_name)); ?></h2>
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

    <div class="profile-shell">
        <div class="profile-nav">
            <button type="button" class="tab-btn active" data-tab="info-tab">User Info</button>
            <button type="button" class="tab-btn" data-tab="settings-tab">User Settings</button>
            <button type="button" class="tab-btn" data-tab="password-tab">Change Password</button>
            <button type="button" class="tab-btn" data-tab="achievements-tab">Achievements</button>
        </div>

        <div class="profile-content">
        <!-- User Info + User Settings share one form/endpoint (update_profile.php
             saves them together), so both panels sit inside the same <form> with
             one shared Save button below the User Settings fields. -->
        <form method="post" action="update_profile.php" enctype="multipart/form-data">
        <div id="info-tab" class="tab-panel active">
            <div class="form-card profile-form-body">
                <div>
                    <!-- Display avatar using the URL path -->
                    <img src="<?php echo htmlspecialchars($avatar_dir_url . $avatar); ?>" alt="Avatar" class="avatar">
                    <label for="avatar">Change Avatar:</label>
                    <input type="file" name="avatar" id="avatar" accept="image/*">
                </div>
                <div class="profile-form-fields">
                    <div class="field-row">
                        <div>
                            <label for="first_name">First Name:</label>
                            <input type="text" name="first_name" id="first_name"
                                value="<?php echo htmlspecialchars($first_name); ?>" required>
                        </div>
                        <div>
                            <label for="last_name">Last Name:</label>
                            <input type="text" name="last_name" id="last_name"
                                value="<?php echo htmlspecialchars($last_name); ?>" required>
                        </div>
                    </div>
                    <label>Email:</label>
                    <input type="text" value="<?php echo htmlspecialchars($email); ?>" disabled>
                    <input type="submit" value="Save Changes">
                </div>
            </div>
        </div>

        <div id="settings-tab" class="tab-panel">
            <div class="form-card narrow">
                <label for="theme">Theme:</label>
                <select name="theme" id="theme">
                    <option value="dark" <?php echo $theme === 'dark' ? 'selected' : ''; ?>>Dark</option>
                    <option value="light" <?php echo $theme === 'light' ? 'selected' : ''; ?>>Light</option>
                </select>
                <input type="submit" value="Save Settings">
            </div>
        </div>
    </form>

    <div id="password-tab" class="tab-panel">
        <div class="form-card narrow">
            <form method="post" action="update_password.php">
                <label for="current_password">Current Password:</label>
                <input type="password" name="current_password" id="current_password" required>

                <div class="field-row">
                    <div>
                        <label for="new_password">New Password:</label>
                        <input type="password" name="new_password" id="new_password" required>
                    </div>
                    <div>
                        <label for="confirm_password">Confirm New Password:</label>
                        <input type="password" name="confirm_password" id="confirm_password" required>
                    </div>
                </div>

                <input type="submit" value="Change Password">
            </form>
        </div>
    </div>

    <div id="achievements-tab" class="tab-panel">
        <div class="stats-grid">
            <?php foreach ($stats as $stat): ?>
            <div class="stat-box">
                <div class="stat-icon"><?php echo $stat['icon']; ?></div>
                <div>
                    <div class="stat-value"><?php echo (int) $stat['value']; ?></div>
                    <div class="stat-label"><?php echo htmlspecialchars($stat['label']); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="achievements-card">
            <h3>Achievements</h3>
            <p class="badge"><?php echo str_pad((string) $unlockedCount, 2, '0', STR_PAD_LEFT); ?>/<?php echo str_pad((string) $totalCount, 2, '0', STR_PAD_LEFT); ?> unlocked</p>

            <div class="achievement-groups">
                <?php foreach ($achievementDisplay['groups'] as $group):
                    $displayTier = $group['current_tier'] ?? $group['tiers'][0];
                ?>
                <div class="achievement-group <?php echo $group['current_tier'] === null ? 'locked' : ''; ?>">
                    <div class="group-header">
                        <div class="icon"><?php echo htmlspecialchars($displayTier['icon']); ?></div>
                        <div>
                            <div class="name"><?php echo htmlspecialchars($group['label']); ?></div>
                            <div class="tier-name">
                                <?php echo $group['current_tier'] !== null ? htmlspecialchars($group['current_tier']['name']) : 'Not started yet'; ?>
                            </div>
                        </div>
                    </div>
                    <?php if ($group['progress']): $pct = min(100, (int) round($group['progress']['count'] / max(1, $group['progress']['threshold']) * 100)); ?>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo $pct; ?>%;"></div>
                    </div>
                    <div class="progress-text">
                        <?php echo (int) $group['progress']['count']; ?> / <?php echo (int) $group['progress']['threshold']; ?>
                        toward <?php echo htmlspecialchars($group['next_tier']['name']); ?>
                    </div>
                    <?php else: ?>
                    <div class="progress-text">All tiers unlocked!</div>
                    <?php endif; ?>
                    <div class="tier-pips">
                        <?php foreach ($group['tiers'] as $t): ?>
                        <span class="pip <?php echo $t['unlocked_at'] !== null ? 'unlocked' : ''; ?>"
                            title="<?php echo htmlspecialchars($t['name'] . ' (' . $t['threshold'] . ')'); ?>"></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($achievementDisplay['standalone'])): ?>
            <div class="achievements-grid">
                <?php foreach ($achievementDisplay['standalone'] as $a): ?>
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
            <?php endif; ?>
        </div>
    </div>
        </div>
        </div>
    </div>

<script>
document.querySelectorAll('.tab-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
        document.querySelectorAll('.tab-panel').forEach(function(p) { p.classList.remove('active'); });
        btn.classList.add('active');
        document.getElementById(btn.dataset.tab).classList.add('active');
    });
});
</script>
</body>

</html>
