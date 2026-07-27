<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once '../includes/permissions.php';
require_permission($conn, 'asset_view');
$canManage = user_can($conn, (int) $_SESSION['user_id'], 'asset_manage');
$canDelete = user_can($conn, (int) $_SESSION['user_id'], 'asset_delete');
$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    require_once('../header.php');
}

// Build query based on filters
$where = ["a.deleted_at IS NULL"];
$params = [];
$types = '';

if (!empty($_GET['keyword'])) {
    $kw = '%' . $_GET['keyword'] . '%';
    $where[] = "(a.asset_number LIKE ? OR a.friendly_name LIKE ? OR t.type_name LIKE ? OR a.serial_number LIKE ? OR l.location_name LIKE ?)";
    $types .= 'sssss';
    $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw;
}
if (!empty($_GET['asset_number'])) {
    $where[] = "a.asset_number LIKE ?";
    $types .= 's';
    $params[] = '%' . $_GET['asset_number'] . '%';
}
if (!empty($_GET['serial_number'])) {
    $where[] = "a.serial_number LIKE ?";
    $types .= 's';
    $params[] = '%' . $_GET['serial_number'] . '%';
}
if (!empty($_GET['asset_type_id'])) {
    $where[] = "a.asset_type_id = ?";
    $types .= 'i';
    $params[] = intval($_GET['asset_type_id']);
}
if (isset($_GET['availability']) && $_GET['availability'] !== '') {
    $where[] = "a.availability = ?";
    $types .= 's';
    $params[] = $_GET['availability'];
}
if (!empty($_GET['location_id'])) {
    $where[] = "a.location_id = ?";
    $types .= 'i';
    $params[] = intval($_GET['location_id']);
}

$sql = "
    SELECT a.id, a.asset_number, a.friendly_name, t.type_name, a.availability, a.serial_number, l.location_name,
           co.checkout_id, CONCAT(u.first_name, ' ', u.last_name) AS checked_out_to_name,
           (SELECT m.next_due_at FROM asset_maintenance m WHERE m.asset_id = a.id ORDER BY m.performed_at DESC, m.maintenance_id DESC LIMIT 1) AS next_maintenance_due
    FROM assets a
    JOIN asset_types t ON t.id = a.asset_type_id
    LEFT JOIN asset_locations l ON l.id = a.location_id
    LEFT JOIN asset_checkouts co ON co.asset_id = a.id AND co.checked_in_at IS NULL
    LEFT JOIN users u ON u.id = co.checked_out_to
";

$sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY a.asset_number ASC";

$stmt = $conn->prepare($sql);
if ($types && $stmt) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$assets = [];
while ($row = $result->fetch_assoc()) {
    $assets[] = $row;
}
$stmt->close();

// Active types/locations for the filter dropdowns.
$assetTypes = [];
$res = $conn->query("SELECT id, type_name FROM asset_types WHERE is_active = 1 ORDER BY type_name ASC");
while ($row = $res->fetch_assoc()) {
    $assetTypes[] = $row;
}
$res->free();

$locations = [];
$res = $conn->query("SELECT id, location_name FROM asset_locations WHERE is_active = 1 ORDER BY location_name ASC");
while ($row = $res->fetch_assoc()) {
    $locations[] = $row;
}
$res->free();

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
        display: flex;
        width: 100%;
        margin: 0;
        padding: 20px;
        gap: 20px;
        box-sizing: border-box;
        align-items: flex-start;
    }

    .sidebar {
        flex: 0 0 280px;
        width: 280px;
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 5px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    }

    .main {
        flex: 1;
        min-width: 0;
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 5px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        overflow-x: auto;
    }

    h2 {
        margin-top: 0;
        margin-bottom: 20px;
    }

    label {
        display: block;
        margin-top: 10px;
        font-weight: bold;
        color: var(--polaris-text-dim);
    }

    input[type="text"],
    select {
        width: 100%;
        padding: 8px;
        margin-top: 5px;
        background: var(--polaris-bg);
        border: 1px solid var(--polaris-border);
        color: var(--polaris-text);
    }

    .table-scroll {
        width: 100%;
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
        background: var(--polaris-surface);
    }

    th,
    td {
        border-bottom: 1px solid var(--polaris-border);
        padding: 8px 10px;
        text-align: left;
        font-size: 13px;
        vertical-align: top;
    }

    th {
        background: var(--polaris-divider);
        white-space: nowrap;
    }

    .overdue {
        color: var(--polaris-danger);
        font-weight: bold;
    }

    .add-btn {
        display: inline-block;
        margin-top: 15px;
        padding: 5px 10px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border: none;
        border-radius: 3px;
        cursor: pointer;
        text-decoration: none;
        font-size: 14px;
    }

    .add-btn:hover {
        background: var(--polaris-accent-hover);
    }

    .btn-add {
        background: var(--polaris-success-strong);
    }

    .btn-add:hover {
        background: var(--polaris-success-strong-hover);
    }

    .action-link {
        font-size: 12px;
        margin-right: 8px;
    }

    .delete-btn {
        background: none;
        border: none;
        color: var(--polaris-danger);
        cursor: pointer;
        font-size: 12px;
        padding: 0;
        text-decoration: underline;
    }
    </style>

    <div class="container">
        <div class="sidebar">
            <h2>Search Assets</h2>
            <form method="get" action="manage_assets.php">
                <?php if ($embedded): ?>
                <input type="hidden" name="embedded" value="1">
                <?php endif; ?>
                <label for="keyword">Keyword</label>
                <input type="text" id="keyword" name="keyword" placeholder="Search all fields">

                <label for="asset_number">Asset Number</label>
                <input type="text" id="asset_number" name="asset_number">

                <label for="serial_number">Serial Number</label>
                <input type="text" id="serial_number" name="serial_number">

                <label for="asset_type_id">Asset Type</label>
                <select id="asset_type_id" name="asset_type_id">
                    <option value="">Any</option>
                    <?php foreach ($assetTypes as $type): ?>
                    <option value="<?php echo $type['id']; ?>"><?php echo htmlspecialchars($type['type_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <label for="availability">Availability</label>
                <select id="availability" name="availability">
                    <option value="">Any</option>
                    <option value="Deployed">Deployed</option>
                    <option value="Not Deployed">Not Deployed</option>
                    <option value="In Maintenance">In Maintenance</option>
                    <option value="Out Of Service">Out Of Service</option>
                    <option value="Destroyed">Destroyed</option>
                </select>

                <label for="location_id">Location</label>
                <select id="location_id" name="location_id">
                    <option value="">Any</option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?php echo $loc['id']; ?>"><?php echo htmlspecialchars($loc['location_name']); ?></option>
                    <?php endforeach; ?>
                </select>

                <div style="display: flex; gap: 10px; margin-top: 15px;">
                    <button type="submit" class="add-btn" style="flex: 1;">Search</button>
                    <a href="manage_assets.php<?php echo $embedded ? '?embedded=1' : ''; ?>" class="add-btn"
                        style="flex: 1; text-align: center;">Reset</a>
                </div>


            </form>
            <?php if ($canManage): ?>
            <a href="add_asset.php" class="add-btn btn-add" target="_top">+ Add New Asset</a>
            <?php endif; ?>
        </div>


        <div class="main">
            <h2>Asset Results</h2>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Asset Number</th>
                            <th>Friendly Name</th>
                            <th>Asset Type</th>
                            <th>Availability</th>
                            <th>Checked Out To</th>
                            <th>Serial Number</th>
                            <th>Location</th>
                            <th>Next Maintenance Due</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($assets) === 0): ?>
                        <tr>
                            <td colspan="9">No assets found.</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($assets as $asset):
                            $isOverdue = $asset['next_maintenance_due'] !== null && strtotime($asset['next_maintenance_due']) < time();
                        ?>
                        <tr>
                            <td>
                                <a href="edit_asset.php?asset_number=<?php echo urlencode($asset['asset_number']); ?>"
                                    style="color: var(--polaris-accent);" target="_top">
                                    <?php echo htmlspecialchars($asset['asset_number']); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($asset['friendly_name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($asset['type_name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($asset['availability'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($asset['checked_out_to_name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($asset['serial_number'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($asset['location_name'] ?? '-'); ?></td>
                            <td class="<?php echo $isOverdue ? 'overdue' : ''; ?>">
                                <?php echo $asset['next_maintenance_due'] ? date('d/m/Y', strtotime($asset['next_maintenance_due'])) . ($isOverdue ? ' (overdue)' : '') : '-'; ?>
                            </td>
                            <td>
                                <a class="action-link" href="audit_log.php?asset_id=<?php echo (int) $asset['id']; ?>" target="_top">History</a>
                                <?php if ($canDelete && !$asset['checkout_id']): ?>
                                <form method="post" action="delete_asset.php" style="display:inline;" target="_top">
                                    <input type="hidden" name="asset_id" value="<?php echo (int) $asset['id']; ?>">
                                    <button type="submit" class="delete-btn"
                                        onclick="return confirmDeleteWithReason(this.form, 'Delete asset <?php echo htmlspecialchars(addslashes($asset['asset_number'])); ?>?')">Delete</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
