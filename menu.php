<?php
// menu.php
?>
<style>
/* Sidebar styling */
#sidebar {
    position: fixed;
    top: 60px;
    /* below header */
    bottom: 30px;
    /* above footer */
    left: 0;
    width: 250px;
    background-color: var(--polaris-header-bg);
    overflow-y: auto;
    z-index: 999;
    padding-top: 20px;
}

#sidebar a {
    display: block;
    padding: 12px 20px;
    text-decoration: none;
    color: var(--polaris-text);
    font-size: 18px;
}

#sidebar a:hover {
    background-color: var(--polaris-border);
}
</style>

<div id="sidebar">
    <a href="cargo_hold.php">Cargo Hold</a>
    <a href="captains_log.php">Captains Log</a>
    <a href="/captains_quarters/cq_dashboard.php">Captains Quarters</a>
    <a href="user_profile.php">User Profile</a>
    <a href="about.php">About</a>
</div>