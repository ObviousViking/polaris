<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once('../includes/audit.php');
require_once('../header.php');

// No PHP header() redirect on success (uses a JS setTimeout redirect instead).

// Fetch asset types
$assetTypes = [];
$res = $conn->query("SELECT id, type_name FROM asset_types ORDER BY type_name ASC");
while ($row = $res->fetch_assoc()) {
    $assetTypes[] = $row;
}
$res->free();

// Fetch locations
$locations = [];
$res = $conn->query("SELECT id, location_name FROM asset_locations ORDER BY location_name ASC");
while ($row = $res->fetch_assoc()) {
    $locations[] = $row;
}
$res->free();

// Always generate the next asset number on page load
$res = $conn->query("SELECT MAX(id) AS max_id FROM assets");
$row = $res->fetch_assoc();
$nextId = $row ? ($row['max_id'] + 1) : 1;
$generatedAssetNumber = 'AS-' . str_pad($nextId, 5, '0', STR_PAD_LEFT);

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $friendly_name = trim($_POST['friendly_name']);
    $asset_type_id = intval($_POST['asset_type_id']);
    $serial_number = trim($_POST['serial_number']);
    $location_id = intval($_POST['location_id']);
    $availability = trim($_POST['availability']);

    // Fetch asset_type text
    $typeQuery = $conn->prepare("SELECT type_name FROM asset_types WHERE id = ?");
    $typeQuery->bind_param("i", $asset_type_id);
    $typeQuery->execute();
    $typeQuery->bind_result($asset_type);
    $typeQuery->fetch();
    $typeQuery->close();

    // Fetch location text
    $locQuery = $conn->prepare("SELECT location_name FROM asset_locations WHERE id = ?");
    $locQuery->bind_param("i", $location_id);
    $locQuery->execute();
    $locQuery->bind_result($location);
    $locQuery->fetch();
    $locQuery->close();

    // Insert asset (using already generated number)
    $stmt = $conn->prepare("INSERT INTO assets (asset_number, friendly_name, asset_type, serial_number, location, availability) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("ssssss", $generatedAssetNumber, $friendly_name, $asset_type, $serial_number, $location, $availability);
        if ($stmt->execute()) {
    $newAssetId = $conn->insert_id;
    log_audit_event($conn, 'asset', $newAssetId, 'CREATE', (int) $_SESSION['user_id'], json_encode(['asset_number' => $generatedAssetNumber, 'friendly_name' => $friendly_name, 'asset_type' => $asset_type]));
    $message = "Asset added successfully with Asset Number: " . htmlspecialchars($generatedAssetNumber);

    // REDIRECT after 3 seconds
    echo "<script>
        setTimeout(function() {
            window.location.href = 'lh_dashboard.php';
        }, 2000);
    </script>";
} else {
    $message = "Error adding asset: " . $stmt->error;
} 
        $stmt->close();
    } else {
        $message = "Prepare failed: " . $conn->error;
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
        max-width: 800px;
        margin: 120px auto 20px auto;
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 5px;
        box-shadow: 0 2px 10px rgba(255, 255, 255, 0.1);
    }

    h2 {
        text-align: center;
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

    .submit-btn,
    .cancel-btn {
        margin-top: 20px;
        padding: 5px 10px;
        border: none;
        border-radius: 3px;
        cursor: pointer;
        font-size: 14px;
    }

    .submit-btn {
        background: var(--polaris-success-strong);
        color: var(--polaris-text);
    }

    .submit-btn:hover {
        background: var(--polaris-success-strong-hover);
    }

    .cancel-btn {
        background: var(--polaris-accent);
        color: var(--polaris-text);
        margin-left: 10px;
    }

    .cancel-btn:hover {
        background: var(--polaris-accent-hover);
    }

    .message {
        margin-bottom: 15px;
        padding: 10px;
        background: var(--polaris-divider);
        color: var(--polaris-text);
        border-left: 4px solid var(--polaris-accent);
    }

    .btn-group {
        display: flex;
        justify-content: flex-start;
        gap: 10px;
    }
    </style>

    <div class="container">
        <h2>Add Asset</h2>
        <?php if (!empty($message)): ?>
        <div class="message"><?php echo $message; ?></div>
        <?php endif; ?>

        <form method="post">
            <label for="asset_number">Asset Number</label>
            <input type="text" id="asset_number" name="asset_number"
                value="<?php echo htmlspecialchars($generatedAssetNumber); ?>" readonly disabled>

            <label for="friendly_name">Friendly Name*</label>
            <input type="text" name="friendly_name" id="friendly_name" required>

            <label for="asset_type_id">Asset Type*</label>
            <select name="asset_type_id" id="asset_type_id" required>
                <option value="">Select Type</option>
                <?php foreach ($assetTypes as $type): ?>
                <option value="<?php echo $type['id']; ?>"><?php echo htmlspecialchars($type['type_name']); ?></option>
                <?php endforeach; ?>
            </select>

            <label for="serial_number">Serial Number</label>
            <input type="text" name="serial_number" id="serial_number">

            <label for="location_id">Location*</label>
            <select name="location_id" id="location_id" required>
                <option value="">Select Location</option>
                <?php foreach ($locations as $loc): ?>
                <option value="<?php echo $loc['id']; ?>"><?php echo htmlspecialchars($loc['location_name']); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <label for="availability">Availability*</label>
            <select name="availability" id="availability" required>
                <option value="Deployed">Deployed</option>
                <option value="Not Deployed">Not Deployed</option>
                <option value="In Maintenance">In Maintenance</option>
                <option value="Out Of Service">Out Of Service</option>
                <option value="Destroyed">Destroyed</option>
            </select>

            <div class="btn-group">
                <button type="submit" class="submit-btn">Add Asset</button>
                <a href="lh_dashboard.php" class="cancel-btn">Cancel</a>
            </div>
        </form>
    </div>
