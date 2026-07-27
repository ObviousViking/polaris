<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once('../includes/integrity.php');
require_once '../includes/permissions.php';
require_permission($conn, 'asset_manage');
require_once('../header.php');

// Active types/locations only - a deactivated one shouldn't be assignable
// to a new asset, same as exhibit_locations on add_exhibit.php.
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

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $friendly_name = trim($_POST['friendly_name']);
    $asset_type_id = intval($_POST['asset_type_id']);
    $serial_number = trim($_POST['serial_number']);
    $location_id = !empty($_POST['location_id']) ? intval($_POST['location_id']) : null;
    $availability = trim($_POST['availability']);
    $created_by = (int) $_SESSION['user_id'];

    if ($friendly_name === '' || $asset_type_id === 0) {
        $message = "Please fill in all required fields.";
    } else {
        // Asset number is generated at insert time and retried on a rare
        // concurrent-submission collision, rather than trusting a number
        // computed earlier at page-load time.
        $generatedAssetNumber = null;
        $newAssetId = null;
        for ($attempt = 0; $attempt < 5 && $newAssetId === null; $attempt++) {
            $res = $conn->query("SELECT MAX(id) AS max_id FROM assets");
            $row = $res->fetch_assoc();
            $nextId = ($row['max_id'] ?? 0) + 1 + $attempt;
            $generatedAssetNumber = 'AS-' . str_pad((string) $nextId, 5, '0', STR_PAD_LEFT);

            $stmt = $conn->prepare("INSERT INTO assets (asset_number, friendly_name, asset_type_id, serial_number, location_id, availability, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssisisi", $generatedAssetNumber, $friendly_name, $asset_type_id, $serial_number, $location_id, $availability, $created_by);
            if ($stmt->execute()) {
                $newAssetId = $conn->insert_id;
            } elseif ($conn->errno !== 1062) {
                // Not a duplicate-key error - no point retrying.
                $message = "Error adding asset: " . $stmt->error;
                $stmt->close();
                break;
            }
            $stmt->close();
        }

        if ($newAssetId !== null) {
            $typeName = "";
            foreach ($assetTypes as $t) {
                if ((int) $t['id'] === $asset_type_id) {
                    $typeName = $t['type_name'];
                    break;
                }
            }
            $locationName = "";
            foreach ($locations as $l) {
                if ((int) $l['id'] === $location_id) {
                    $locationName = $l['location_name'];
                    break;
                }
            }

            $changes = json_encode([
                'asset_number' => $generatedAssetNumber,
                'friendly_name' => $friendly_name,
                'asset_type' => $typeName,
                'serial_number' => $serial_number,
                'location' => $locationName,
                'availability' => $availability,
            ]);
            insert_history_row($conn, 'asset_history', $newAssetId, 'CREATE', $created_by, $changes);

            $message = "Asset added successfully with Asset Number: " . htmlspecialchars($generatedAssetNumber);
            echo "<script>
                setTimeout(function() {
                    window.location.href = 'lh_dashboard.php';
                }, 2000);
            </script>";
        } elseif ($message === '') {
            $message = "Error adding asset: could not generate a unique asset number.";
        }
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

            <label for="location_id">Location</label>
            <select name="location_id" id="location_id">
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
