<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once '../includes/audit.php';
require_once '../includes/deletion_reason.php';
require_once '../includes/permissions.php';
require_permission($conn, 'manage_lookups');
$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    require_once('../header.php');
}

$canDelete = user_can($conn, (int) $_SESSION['user_id'], 'manage_lookups_delete');

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reactivate_location'])) {
        if (!$canDelete) {
            $message = "Only admins can reactivate locations.";
        } else {
            $reactivate_id = intval($_POST['reactivate_location']);
            $stmt = $conn->prepare("UPDATE asset_locations SET is_active = 1 WHERE id = ?");
            $stmt->bind_param("i", $reactivate_id);
            $stmt->execute();
            $stmt->close();
            log_audit_event($conn, 'asset_location', $reactivate_id, 'REACTIVATE', (int) $_SESSION['user_id']);
            $message = "Location reactivated.";
        }
    } elseif (isset($_POST['delete_location'])) {
        if (!$canDelete) {
            $message = "Only admins can delete locations.";
        } else {
            $delete_id = intval($_POST['delete_location']);

            $nameStmt = $conn->prepare("SELECT location_name FROM asset_locations WHERE id = ?");
            $nameStmt->bind_param("i", $delete_id);
            $nameStmt->execute();
            $nameStmt->bind_result($deletedName);
            $nameStmt->fetch();
            $nameStmt->close();

            // A location with any historical asset reference can't be
            // hard-deleted, so deactivate instead: drops out of the "add
            // asset" dropdown but past records still display correctly.
            $histStmt = $conn->prepare("SELECT COUNT(*) FROM assets WHERE location_id = ?");
            $histStmt->bind_param("i", $delete_id);
            $histStmt->execute();
            $histStmt->bind_result($historicalCount);
            $histStmt->fetch();
            $histStmt->close();

            if ($historicalCount > 0) {
                $stmt = $conn->prepare("UPDATE asset_locations SET is_active = 0 WHERE id = ?");
                $stmt->bind_param("i", $delete_id);
                $stmt->execute();
                $stmt->close();
                log_audit_event($conn, 'asset_location', $delete_id, 'DEACTIVATE', (int) $_SESSION['user_id'], json_encode(['location_name' => $deletedName, 'reason' => 'has assets referencing this location']));
                $message = "\"$deletedName\" is used by $historicalCount asset(s), so it can't be fully deleted - deactivated instead. It won't appear when adding new assets, and can be reactivated later.";
            } elseif (($deleteReason = require_deletion_reason_or_fail($conn)) === false) {
                $message = "A reason is required to delete this location.";
            } else {
                $deleteStmt = $conn->prepare("DELETE FROM asset_locations WHERE id = ?");
                $deleteStmt->bind_param("i", $delete_id);
                $deleteStmt->execute();
                $deleteStmt->close();
                log_audit_event($conn, 'asset_location', $delete_id, 'DELETE', (int) $_SESSION['user_id'], json_encode(['location_name' => $deletedName, 'reason' => $deleteReason]));
                $message = "Location deleted successfully.";
            }
        }
    } else {
        $location_name = strtoupper(trim($_POST['location_name']));
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;

        if ($location_name === '') {
            $message = "Location name cannot be empty.";
        } else {
            $dupCheck = $conn->prepare("SELECT COUNT(*) FROM asset_locations WHERE UPPER(location_name) = ? AND id != ?");
            $dupCheck->bind_param("si", $location_name, $location_id);
            $dupCheck->execute();
            $dupCheck->bind_result($count);
            $dupCheck->fetch();
            $dupCheck->close();

            if ($count > 0) {
                $message = "Duplicate location name found.";
            } else {
                if ($location_id > 0) {
                    $stmt = $conn->prepare("UPDATE asset_locations SET location_name = ? WHERE id = ?");
                    $stmt->bind_param("si", $location_name, $location_id);
                    $stmt->execute();
                    $stmt->close();
                    log_audit_event($conn, 'asset_location', $location_id, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['location_name' => $location_name]));
                    $message = "Location updated.";
                } else {
                    $stmt = $conn->prepare("INSERT INTO asset_locations (location_name) VALUES (?)");
                    $stmt->bind_param("s", $location_name);
                    $stmt->execute();
                    $newId = $conn->insert_id;
                    $stmt->close();
                    log_audit_event($conn, 'asset_location', $newId, 'CREATE', (int) $_SESSION['user_id'], json_encode(['location_name' => $location_name]));
                    $message = "New location added.";
                }
            }
        }
    }
}

$locations = [];
$res = $conn->query("SELECT * FROM asset_locations ORDER BY location_name");
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
        max-width: 800px;
        margin: 20px auto;
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 5px;
        box-shadow: 0 2px 10px rgba(255, 255, 255, 0.1);
    }

    h2 {
        text-align: center;
        margin-bottom: 20px;
    }

    input[type="text"] {
        width: 100%;
        padding: 8px;
        margin-bottom: 10px;
        background: var(--polaris-bg);
        border: 1px solid var(--polaris-border);
        color: var(--polaris-text);
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
    }

    th,
    td {
        border: 1px solid var(--polaris-border);
        padding: 8px;
        text-align: left;
    }

    th {
        background: var(--polaris-divider);
    }

    .action-btn {
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border: none;
        padding: 5px 10px;
        cursor: pointer;
        border-radius: 3px;
    }

    .action-btn:hover {
        background: var(--polaris-accent-hover);
    }

    .delete-btn {
        background: var(--polaris-error-bg);
        color: var(--polaris-text);
    }

    .delete-btn:hover {
        background: var(--polaris-danger);
    }

    .message {
        margin-bottom: 10px;
        padding: 10px;
        background: var(--polaris-divider);
        border-left: 4px solid var(--polaris-accent);
    }
    </style>
    <script>
    function filterLocations() {
        const input = document.getElementById("filter").value.toUpperCase();
        const rows = document.querySelectorAll("#locationsTable tbody tr");
        rows.forEach(row => {
            const name = row.querySelector("td").innerText;
            row.style.display = name.toUpperCase().includes(input) ? "" : "none";
        });
    }

    function populateForm(id, name) {
        document.getElementById("location_id").value = id;
        document.getElementById("location_name").value = name;
    }
    </script>

    <div class="container">
        <h2>Manage Asset Locations</h2>

        <?php if (!empty($message)): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="post" action="manage_asset_locations.php<?php echo $embedded ? '?embedded=1' : ''; ?>">
            <input type="hidden" name="location_id" id="location_id">
            <label for="location_name">Location Name</label>
            <input type="text" name="location_name" id="location_name" oninput="this.value = this.value.toUpperCase();">
            <button type="submit" class="action-btn">Save</button>
        </form>

        <label for="filter">Filter Locations</label>
        <input type="text" id="filter" oninput="filterLocations();">

        <table id="locationsTable">
            <thead>
                <tr>
                    <th>Location Name</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($locations as $loc): ?>
                <tr>
                    <td><?php echo htmlspecialchars($loc['location_name']); ?></td>
                    <td><?php echo $loc['is_active'] ? 'Active' : 'Inactive'; ?></td>
                    <td>
                        <button class="action-btn"
                            onclick="populateForm('<?php echo $loc['id']; ?>', '<?php echo htmlspecialchars($loc['location_name'], ENT_QUOTES); ?>')">Edit</button>
                        <?php if ($canDelete && $loc['is_active']): ?>
                        <form method="post" action="manage_asset_locations.php<?php echo $embedded ? '?embedded=1' : ''; ?>"
                            style="display:inline;">
                            <input type="hidden" name="delete_location" value="<?php echo $loc['id']; ?>">
                            <button type="submit" class="action-btn delete-btn"
                                onclick="return confirmDeleteWithReason(this.form, 'Delete this location? If it has any asset history it will be deactivated instead of deleted.')">Delete</button>
                        </form>
                        <?php elseif ($canDelete): ?>
                        <form method="post" action="manage_asset_locations.php<?php echo $embedded ? '?embedded=1' : ''; ?>"
                            style="display:inline;">
                            <input type="hidden" name="reactivate_location" value="<?php echo $loc['id']; ?>">
                            <button type="submit" class="action-btn">Reactivate</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
