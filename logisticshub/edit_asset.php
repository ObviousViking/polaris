<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once('../includes/audit.php');

// Make sure asset_number is provided
if (!isset($_GET['asset_number'])) {
    header("Location: ../404.php");
    exit();
}
$asset_number = strtoupper(trim($_GET['asset_number']));

// Fetch asset
$stmt = $conn->prepare("SELECT * FROM assets WHERE asset_number = ?");
$stmt->bind_param("s", $asset_number);
$stmt->execute();
$result = $stmt->get_result();
$asset = $result->fetch_assoc();
$stmt->close();

if (!$asset) {
    header("Location: ../404.php");
    exit();
}

// header.php must come after both redirect checks above, since it outputs
// HTML immediately and would break the redirect otherwise.
require_once('../header.php');

// Fetch asset types
$assetTypes = [];
$res = $conn->query("SELECT id, type_name FROM asset_types ORDER BY type_name");
while ($row = $res->fetch_assoc()) {
    $assetTypes[] = $row;
}
$res->free();

// Fetch locations
$locations = [];
$res = $conn->query("SELECT id, location_name FROM asset_locations ORDER BY location_name");
while ($row = $res->fetch_assoc()) {
    $locations[] = $row;
}
$res->free();

// Availability statuses
$availabilityOptions = [
    'Deployed',
    'Not Deployed',
    'In Maintenance',
    'Out Of Service',
    'Destroyed'
];

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $friendly_name = trim($_POST['friendly_name']);
    $asset_type = trim($_POST['asset_type']);
    $serial_number = trim($_POST['serial_number']);
    $location = trim($_POST['location']);
    $availability = trim($_POST['availability']);

    $stmt = $conn->prepare("UPDATE assets SET friendly_name = ?, asset_type = ?, serial_number = ?, location = ?, availability = ? WHERE asset_number = ?");
    $stmt->bind_param("ssssss", $friendly_name, $asset_type, $serial_number, $location, $availability, $asset_number);

    if ($stmt->execute()) {
        log_audit_event($conn, 'asset', (int) $asset['id'], 'UPDATE', (int) $_SESSION['user_id'], json_encode(['asset_number' => $asset_number, 'friendly_name' => $friendly_name, 'asset_type' => $asset_type, 'availability' => $availability]));
        $message = "Asset updated successfully.";
        // Refresh asset data after update
        $stmt = $conn->prepare("SELECT * FROM assets WHERE asset_number = ?");
        $stmt->bind_param("s", $asset_number);
        $stmt->execute();
        $result = $stmt->get_result();
        $asset = $result->fetch_assoc();
        $stmt->close();
    } else {
        $message = "Error updating asset: " . $stmt->error;
    }
}
?>
<style>
    /* header.php's own body{} already sets margin/background/color/font-family
       page-wide. This page used to redeclare a second <!DOCTYPE html><html>
       <head> here with its own body{} - browsers tolerate the invalid
       nesting, but that stray rule won the cascade and bled its font into
       the real header/nav above it. The 120px clearance moves onto
       .container's top margin since body no longer carries it. */

    .container {
        max-width: 900px;
        margin: 120px auto 20px auto;
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 5px;
        box-shadow: 0 2px 10px rgba(255, 255, 255, 0.1);
    }

    h2 {
        text-align: center;
        margin-bottom: 30px;
    }

    form {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
    }

    .form-group {
        flex: 1 1 45%;
    }

    label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
        color: var(--polaris-text-dim);
    }

    input[type="text"],
    select {
        width: 100%;
        padding: 8px;
        background: var(--polaris-bg);
        border: 1px solid var(--polaris-border-hover);
        border-radius: 3px;
        color: var(--polaris-text);
        margin-bottom: 15px;
    }

    .readonly {
        background: var(--polaris-divider);
        color: var(--polaris-text-muted);
    }

    .btn-group {
        width: 100%;
        display: flex;
        justify-content: center;
        gap: 20px;
        margin-top: 20px;
    }

    .btn {
        padding: 5px 10px;
        border: none;
        border-radius: 3px;
        cursor: pointer;
        font-size: 14px;
    }

    .save-btn {
        background: var(--polaris-accent);
        color: var(--polaris-text);
    }

    .save-btn:hover {
        background: var(--polaris-accent-hover);
    }

    .cancel-btn {
        background: var(--polaris-accent);
        color: var(--polaris-text);
    }

    .cancel-btn:hover {
        background: var(--polaris-accent-hover);
    }

    .message {
        background: var(--polaris-divider);
        padding: 10px;
        margin-bottom: 20px;
        text-align: center;
        border-left: 4px solid var(--polaris-accent);
    }
    </style>

    <div class="container">
        <h2>Edit Asset: <?php echo htmlspecialchars($asset['asset_number']); ?></h2>

        <?php if (!empty($message)): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="post">

            <div class="form-group">
                <label>Asset Number</label>
                <input type="text" value="<?php echo htmlspecialchars($asset['asset_number']); ?>" class="readonly"
                    readonly>
            </div>

            <div class="form-group">
                <label for="friendly_name">Friendly Name</label>
                <input type="text" id="friendly_name" name="friendly_name"
                    value="<?php echo htmlspecialchars($asset['friendly_name']); ?>" required>
            </div>

            <div class="form-group">
                <label for="asset_type">Asset Type</label>
                <select id="asset_type" name="asset_type" required>
                    <option value="">Select Type</option>
                    <?php foreach ($assetTypes as $type): ?>
                    <option value="<?php echo htmlspecialchars($type['type_name']); ?>"
                        <?php echo ($asset['asset_type'] === $type['type_name']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($type['type_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="serial_number">Serial Number</label>
                <input type="text" id="serial_number" name="serial_number"
                    value="<?php echo htmlspecialchars($asset['serial_number']); ?>">
            </div>

            <div class="form-group">
                <label for="location">Location</label>
                <select id="location" name="location" required>
                    <option value="">Select Location</option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?php echo htmlspecialchars($loc['location_name']); ?>"
                        <?php echo ($asset['location'] === $loc['location_name']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($loc['location_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="availability">Availability</label>
                <select id="availability" name="availability" required>
                    <option value="">Select Status</option>
                    <?php foreach ($availabilityOptions as $status): ?>
                    <option value="<?php echo $status; ?>"
                        <?php echo ($asset['availability'] === $status) ? 'selected' : ''; ?>>
                        <?php echo $status; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="btn-group">
                <button type="submit" class="btn save-btn">Save Changes</button>
                <a href="lh_dashboard.php" class="btn cancel-btn">Cancel</a>
            </div>

        </form>
    </div>
