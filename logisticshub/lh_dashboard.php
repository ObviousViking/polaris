<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once('../header.php');
?>
<div class="lh-shell">
    <div class="lh-nav">
        <a href="manage_assets.php?embedded=1" target="lh-content">Manage Assets</a>
        <a href="manage_asset_types.php?embedded=1" target="lh-content">Asset Types</a>
        <a href="manage_asset_locations.php?embedded=1" target="lh-content">Asset Locations</a>
        <a href="maintenance.php?embedded=1" target="lh-content">Maintenance & Calibration</a>
        <a href="checkout.php?embedded=1" target="lh-content">Asset Checkout</a>
        <a href="audit_log.php?embedded=1" target="lh-content">Audit Logs</a>
    </div>
    <iframe name="lh-content" class="lh-content" srcdoc="<!DOCTYPE html><html<?php echo $userTheme === 'light' ? " data-theme='light'" : ''; ?>><head><meta charset='UTF-8'><link rel='stylesheet' href='/assets/theme.css'><style>body{margin:0;padding:20px;font-family:Arial,sans-serif;background:var(--polaris-bg);color:var(--polaris-text-muted);}h2{color:var(--polaris-text);margin:0 0 10px;}</style></head><body><h2>Asset Management</h2><p>Choose an option on the left.</p></body></html>"></iframe>
</div>

<style>
.lh-shell {
    display: flex;
    position: fixed;
    top: 100px;
    left: 0;
    right: 0;
    bottom: 0;
}

.lh-nav {
    flex: 0 0 200px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    background: var(--polaris-surface);
    padding: 15px 10px;
    box-sizing: border-box;
    height: 100%;
    overflow-y: auto;
}

.lh-nav a {
    display: block;
    background-color: var(--polaris-border);
    color: var(--polaris-text);
    padding: 12px 15px;
    text-decoration: none;
    border-radius: 5px;
    font-size: 15px;
    transition: background 0.2s ease-in-out;
}

.lh-nav a:hover,
.lh-nav a.active {
    background-color: var(--polaris-border-hover-2);
}

.lh-content {
    flex: 1;
    min-width: 0;
    height: 100%;
    border: none;
    background: var(--polaris-bg);
}

@media (max-width: 900px) {
    .lh-shell {
        flex-direction: column;
    }

    .lh-nav {
        flex-direction: row;
        flex-wrap: wrap;
        height: auto;
        width: 100%;
    }

    .lh-nav a {
        flex: 1 1 auto;
    }

    .lh-content {
        flex: 1;
    }
}
</style>

<script>
document.querySelectorAll('.lh-nav a').forEach(function(link) {
    link.addEventListener('click', function() {
        document.querySelectorAll('.lh-nav a').forEach(function(l) {
            l.classList.remove('active');
        });
        link.classList.add('active');
    });
});
</script>

</body>

</html>