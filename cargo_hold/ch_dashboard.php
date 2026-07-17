<?php
// cargo_hold/ch_dashboard.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
include '../header.php';
?>

<div class="ch-shell">
    <div class="ch-nav">
        <a href="create_case.php?embedded=1" target="ch-content">Create Case</a>
        <a href="search_cases.php?embedded=1" target="ch-content">Search Cases</a>
        <a href="search_exhibits.php?embedded=1" target="ch-content">Search Exhibits</a>
        <a href="search_examinations.php?embedded=1" target="ch-content">Search Examinations</a>
        <a href="manage_system_details.php?embedded=1" target="ch-content">Manage System Details</a>
        <a href="my_workload.php?embedded=1" target="ch-content">My Workload</a>
    </div>
    <iframe name="ch-content" class="ch-content" srcdoc="<!DOCTYPE html><html<?php echo $userTheme === 'light' ? " data-theme='light'" : ''; ?>><head><meta charset='UTF-8'><link rel='stylesheet' href='/assets/theme.css'><style>body{margin:0;padding:20px;font-family:Arial,sans-serif;background:var(--polaris-bg);color:var(--polaris-text-muted);}h2{color:var(--polaris-text);margin:0 0 10px;}</style></head><body><h2>Case Management</h2><p>Choose an option on the left.</p></body></html>"></iframe>
</div>

<!-- Inline CSS for the Cargo Hold dashboard layout -->
<style>
.ch-shell {
    display: flex;
    position: fixed;
    top: 100px;
    left: 0;
    right: 0;
    bottom: 0;
}

.ch-nav {
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

.ch-nav a {
    display: block;
    background-color: var(--polaris-border);
    color: var(--polaris-text);
    padding: 12px 15px;
    text-decoration: none;
    border-radius: 5px;
    font-size: 15px;
    transition: background 0.2s ease-in-out;
}

.ch-nav a:hover,
.ch-nav a.active {
    background-color: var(--polaris-border-hover-2);
}

.ch-content {
    flex: 1;
    min-width: 0;
    height: 100%;
    border: none;
    background: var(--polaris-bg);
}

@media (max-width: 900px) {
    .ch-shell {
        flex-direction: column;
    }

    .ch-nav {
        flex-direction: row;
        flex-wrap: wrap;
        height: auto;
        width: 100%;
    }

    .ch-nav a {
        flex: 1 1 auto;
    }

    .ch-content {
        flex: 1;
    }
}
</style>

<script>
document.querySelectorAll('.ch-nav a').forEach(function(link) {
    link.addEventListener('click', function() {
        document.querySelectorAll('.ch-nav a').forEach(function(l) {
            l.classList.remove('active');
        });
        link.classList.add('active');
    });
});
</script>

</body>

</html>