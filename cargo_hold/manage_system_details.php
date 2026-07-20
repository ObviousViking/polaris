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
        max-width: 800px;
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

    ul {
        list-style: none;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 15px;
    }

    li {
        margin: 0;
    }

    a.action-btn {
        display: block;
        width: 100%;
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
        box-sizing: border-box;
    }

    a.action-btn:hover {
        background: var(--polaris-accent-hover);
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
        <h2>Manage System Details</h2>
        <ul>
            <li><a href="manage_operations.php<?php echo $embedded ? '?embedded=1' : ''; ?>" class="action-btn">Manage
                    Operations</a></li>
            <li><a href="manage_forces.php<?php echo $embedded ? '?embedded=1' : ''; ?>" class="action-btn">Manage
                    Forces</a></li>
            <li><a href="manage_customers.php<?php echo $embedded ? '?embedded=1' : ''; ?>" class="action-btn">Manage
                    Customers</a></li>
            <li><a href="manage_locations.php<?php echo $embedded ? '?embedded=1' : ''; ?>" class="action-btn">Manage
                    Locations</a></li>
            <li><a href="manage_exhibit_types.php<?php echo $embedded ? '?embedded=1' : ''; ?>"
                    class="action-btn">Manage Exhibit Types</a></li>
            <li><a href="manage_case_status.php<?php echo $embedded ? '?embedded=1' : ''; ?>" class="action-btn">Manage
                    Case Status</a></li>
            <li><a href="manage_case_types.php<?php echo $embedded ? '?embedded=1' : ''; ?>" class="action-btn">Manage
                    Case Types</a></li>
        </ul>
        <hr>
        <br>
        <?php if (!$embedded): ?>
        <a href="/cargo_hold/ch_dashboard.php" class="back-btn">Go Back</a>
        <?php endif; ?>
    </div>
</body>

</html>