<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    require_once('../header.php');
}

// Build query based on filters
$where = [];
$params = [];
$types = '';

if (!empty($_GET['keyword'])) {
    $kw = '%' . $conn->real_escape_string($_GET['keyword']) . '%';
    $where[] = "(asset_number LIKE ? OR friendly_name LIKE ? OR asset_type LIKE ? OR serial_number LIKE ? OR location LIKE ?)";
    $types .= 'sssss';
    $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw;
}
if (!empty($_GET['asset_number'])) {
    $where[] = "asset_number LIKE ?";
    $types .= 's';
    $params[] = '%' . $_GET['asset_number'] . '%';
}
if (!empty($_GET['serial_number'])) {
    $where[] = "serial_number LIKE ?";
    $types .= 's';
    $params[] = '%' . $_GET['serial_number'] . '%';
}
if (!empty($_GET['asset_type'])) {
    $where[] = "asset_type = ?";
    $types .= 's';
    $params[] = $_GET['asset_type'];
}
if (isset($_GET['availability']) && $_GET['availability'] !== '') {
    $where[] = "availability = ?";
    $types .= 's';
    $params[] = $_GET['availability'];
}
if (!empty($_GET['location'])) {
    $where[] = "location = ?";
    $types .= 's';
    $params[] = $_GET['location'];
}

$sql = "SELECT asset_number, friendly_name, asset_type, availability, serial_number, location
        FROM assets";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY asset_number ASC";

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

// Fetch distinct asset types from assets table
$assetTypes = [];
$res = $conn->query("SELECT DISTINCT asset_type FROM assets WHERE asset_type IS NOT NULL AND asset_type != '' ORDER BY asset_type ASC");
while ($row = $res->fetch_assoc()) {
    $assetTypes[] = $row['asset_type'];
}
$res->free();

// Fetch distinct locations from assets table
$locations = [];
$res = $conn->query("SELECT DISTINCT location FROM assets WHERE location IS NOT NULL AND location != '' ORDER BY location ASC");
while ($row = $res->fetch_assoc()) {
    $locations[] = $row['location'];
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

    .asset-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }

    .asset-table th,
    .asset-table td {
        border: 1px solid var(--polaris-border);
        padding: 8px;
        text-align: left;
    }

    .asset-table th {
        background: var(--polaris-divider);
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

                <label for="asset_type">Asset Type</label>
                <select id="asset_type" name="asset_type">
                    <option value="">Any</option>
                    <?php foreach ($assetTypes as $type): ?>
                    <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?>
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

                <label for="location">Location</label>
                <select id="location" name="location">
                    <option value="">Any</option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?php echo htmlspecialchars($loc); ?>"><?php echo htmlspecialchars($loc); ?></option>
                    <?php endforeach; ?>
                </select>

                <div style="display: flex; gap: 10px; margin-top: 15px;">
                    <button type="submit" class="add-btn" style="flex: 1;">Search</button>
                    <a href="manage_assets.php<?php echo $embedded ? '?embedded=1' : ''; ?>" class="add-btn"
                        style="flex: 1; text-align: center;">Reset</a>
                </div>


            </form>
            <a href="add_asset.php" class="add-btn btn-add" target="_top">+ Add New Asset</a>
        </div>


        <div class="main">
            <h2>Asset Results</h2>
            <table class="asset-table">
                <thead>
                    <tr>
                        <th>Asset Number</th>
                        <th>Friendly Name</th>
                        <th>Asset Type</th>
                        <th>Availability</th>
                        <th>Serial Number</th>
                        <th>Location</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($assets) === 0): ?>
                    <tr>
                        <td colspan="6">No assets found.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($assets as $asset): ?>
                    <tr>
                        <td>
                            <a href="edit_asset.php?asset_number=<?php echo urlencode($asset['asset_number']); ?>"
                                style="color: var(--polaris-accent);" target="_top">
                                <?php echo htmlspecialchars($asset['asset_number']); ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars($asset['friendly_name'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($asset['asset_type'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($asset['availability'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($asset['serial_number'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($asset['location'] ?? ''); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</body>

</html>