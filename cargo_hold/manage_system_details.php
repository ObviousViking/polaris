<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../includes/permissions.php';
require_permission($conn, 'manage_lookups');
$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    require_once('../header.php');
}
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
        max-width: 1400px;
        margin: 20px 20px 0 20px;
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
        box-sizing: border-box;
    }

    h2 {
        font-size: 24px;
        margin-bottom: 5px;
    }

    .subtitle {
        color: var(--polaris-text-faint);
        margin-bottom: 20px;
    }

    .details-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 15px;
    }

    .detail-card {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
        padding: 20px 15px;
        background: var(--polaris-surface-alt);
        color: var(--polaris-text);
        border: 1px solid var(--polaris-border);
        border-radius: 6px;
        text-decoration: none;
        text-align: center;
        font-size: 14px;
        transition: background 0.2s ease, border-color 0.2s ease;
    }

    .detail-card:hover {
        background: var(--polaris-border-hover-2);
        border-color: var(--polaris-accent);
    }

    .detail-card .icon {
        font-size: 28px;
        line-height: 1;
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
        <h2>Manage Lookup Data</h2>
        <p class="subtitle">Reference data used throughout Polaris - operations, forces, customers, locations, and
            case/exhibit type lists.</p>
        <div class="details-grid">
            <a href="manage_operations.php<?php echo $embedded ? '?embedded=1' : ''; ?>" class="detail-card">
                <span class="icon">🎯</span> Operations</a>
            <a href="manage_forces.php<?php echo $embedded ? '?embedded=1' : ''; ?>" class="detail-card">
                <span class="icon">🛡️</span> Forces</a>
            <a href="manage_customers.php<?php echo $embedded ? '?embedded=1' : ''; ?>" class="detail-card">
                <span class="icon">🧑‍💼</span> Customers</a>
            <a href="manage_locations.php<?php echo $embedded ? '?embedded=1' : ''; ?>" class="detail-card">
                <span class="icon">📍</span> Locations</a>
            <a href="manage_exhibit_types.php<?php echo $embedded ? '?embedded=1' : ''; ?>" class="detail-card">
                <span class="icon">🏷️</span> Exhibit Types</a>
            <a href="manage_case_status.php<?php echo $embedded ? '?embedded=1' : ''; ?>" class="detail-card">
                <span class="icon">🚦</span> Case Status</a>
            <a href="manage_case_types.php<?php echo $embedded ? '?embedded=1' : ''; ?>" class="detail-card">
                <span class="icon">📁</span> Case Types</a>
            <a href="manage_case_item_types.php<?php echo $embedded ? '?embedded=1' : ''; ?>" class="detail-card">
                <span class="icon">📦</span> Case Item Types</a>
        </div>
        <?php if (!$embedded): ?>
        <br>
        <a href="/captains_quarters/cq_dashboard.php" class="back-btn">Go Back</a>
        <?php endif; ?>
    </div>
</body>

</html>