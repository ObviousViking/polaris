<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once '../includes/audit.php';
require_once '../includes/deletion_reason.php';
$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    require_once('../header.php');
}

$roleStmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$roleStmt->bind_param("i", $_SESSION['user_id']);
$roleStmt->execute();
$roleStmt->bind_result($currentUserRole);
$roleStmt->fetch();
$roleStmt->close();
$canDelete = ($currentUserRole === 'admin' || $currentUserRole === 'super');

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reactivate_location'])) {
        if (!$canDelete) {
            $message = "Only admins can reactivate locations.";
        } else {
        $reactivate_id = intval($_POST['reactivate_location']);
        $stmt = $conn->prepare("UPDATE exhibit_locations SET is_active = 1 WHERE location_id = ?");
        $stmt->bind_param("i", $reactivate_id);
        $stmt->execute();
        $stmt->close();
        log_audit_event($conn, 'exhibit_location', $reactivate_id, 'REACTIVATE', (int) $_SESSION['user_id']);
        $message = "Location reactivated.";
        }
    } elseif (isset($_POST['delete_location'])) {
        if (!$canDelete) {
            $message = "Only admins can delete locations.";
        } else {
        $delete_id = intval($_POST['delete_location']);

        // Exhibits still physically at this location block the action; booked-out ones don't.
        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM exhibits WHERE location_id = ? AND time_out IS NULL");
        $checkStmt->bind_param("i", $delete_id);
        $checkStmt->execute();
        $checkStmt->bind_result($currentCount);
        $checkStmt->fetch();
        $checkStmt->close();

        if ($currentCount > 0) {
            $message = "Cannot remove location - $currentCount exhibit(s) are still checked in there. Move or book them out first.";
        } else {
            $nameStmt = $conn->prepare("SELECT location_name FROM exhibit_locations WHERE location_id = ?");
            $nameStmt->bind_param("i", $delete_id);
            $nameStmt->execute();
            $nameStmt->bind_result($deletedName);
            $nameStmt->fetch();
            $nameStmt->close();

            // A location with any historical exhibit reference can't be
            // hard-deleted, so deactivate instead: drops out of the "book
            // in" dropdown but past records still display correctly.
            $histStmt = $conn->prepare("SELECT COUNT(*) FROM exhibits WHERE location_id = ?");
            $histStmt->bind_param("i", $delete_id);
            $histStmt->execute();
            $histStmt->bind_result($historicalCount);
            $histStmt->fetch();
            $histStmt->close();

            if ($historicalCount > 0) {
                $stmt = $conn->prepare("UPDATE exhibit_locations SET is_active = 0 WHERE location_id = ?");
                $stmt->bind_param("i", $delete_id);
                $stmt->execute();
                $stmt->close();
                log_audit_event($conn, 'exhibit_location', $delete_id, 'DEACTIVATE', (int) $_SESSION['user_id'], json_encode(['location_name' => $deletedName, 'reason' => 'has historical exhibit records']));
                $message = "\"$deletedName\" has $historicalCount historical exhibit record(s), so it can't be fully deleted - deactivated instead. It won't appear when booking in new exhibits, and can be reactivated later.";
            } elseif (($deleteReason = require_deletion_reason_or_fail($conn)) === false) {
                $message = "A reason is required to delete this location.";
            } else {
                $deleteStmt = $conn->prepare("DELETE FROM exhibit_locations WHERE location_id = ?");
                $deleteStmt->bind_param("i", $delete_id);
                $deleteStmt->execute();
                $deleteStmt->close();
                log_audit_event($conn, 'exhibit_location', $delete_id, 'DELETE', (int) $_SESSION['user_id'], json_encode(['location_name' => $deletedName, 'reason' => $deleteReason]));
                $message = "Location deleted successfully.";
            }
        }
        }
    } else {
        $location_name = strtoupper(trim($_POST['location_name']));
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;

        if ($location_name === '') {
            $message = "Location name cannot be empty.";
        } else {
            $dupCheck = $conn->prepare("SELECT COUNT(*) FROM exhibit_locations WHERE UPPER(location_name) = ? AND location_id != ?");
            $dupCheck->bind_param("si", $location_name, $location_id);
            $dupCheck->execute();
            $dupCheck->bind_result($count);
            $dupCheck->fetch();
            $dupCheck->close();

            if ($count > 0) {
                $message = "Duplicate location name found.";
            } else {
                if ($location_id > 0) {
                    $stmt = $conn->prepare("UPDATE exhibit_locations SET location_name = ? WHERE location_id = ?");
                    $stmt->bind_param("si", $location_name, $location_id);
                    $stmt->execute();
                    $stmt->close();
                    log_audit_event($conn, 'exhibit_location', $location_id, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['location_name' => $location_name]));
                    $message = "Location updated.";
                } else {
                    $stmt = $conn->prepare("INSERT INTO exhibit_locations (location_name) VALUES (?)");
                    $stmt->bind_param("s", $location_name);
                    $stmt->execute();
                    $newId = $conn->insert_id;
                    $stmt->close();
                    log_audit_event($conn, 'exhibit_location', $newId, 'CREATE', (int) $_SESSION['user_id'], json_encode(['location_name' => $location_name]));
                    $message = "New location added.";
                }
            }
        }
    }
}

$locations = [];
$res = $conn->query("SELECT * FROM exhibit_locations ORDER BY location_name");
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
        <h2>Manage Locations</h2>

        <?php if (!empty($message)): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="post" action="manage_locations.php<?php echo $embedded ? '?embedded=1' : ''; ?>">
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
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($locations as $loc): ?>
                <tr>
                    <td><?php echo htmlspecialchars($loc['location_name']); ?></td>
                    <td><?php echo $loc['is_active'] ? 'Active' : 'Inactive'; ?></td>
                    <td>
                        <button class="action-btn"
                            onclick="populateForm('<?php echo $loc['location_id']; ?>', '<?php echo htmlspecialchars($loc['location_name'], ENT_QUOTES); ?>')">Edit</button>
                        <?php if ($canDelete && $loc['is_active']): ?>
                        <form method="post" action="manage_locations.php<?php echo $embedded ? '?embedded=1' : ''; ?>"
                            style="display:inline;">
                            <input type="hidden" name="delete_location" value="<?php echo $loc['location_id']; ?>">
                            <button type="submit" class="action-btn delete-btn"
                                onclick="return confirmDeleteWithReason(this.form, 'Delete this location? If it has any exhibit history it will be deactivated instead of deleted.')">Delete</button>
                        </form>
                        <?php elseif ($canDelete): ?>
                        <form method="post" action="manage_locations.php<?php echo $embedded ? '?embedded=1' : ''; ?>"
                            style="display:inline;">
                            <input type="hidden" name="reactivate_location" value="<?php echo $loc['location_id']; ?>">
                            <button type="submit" class="action-btn">Reactivate</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <br>
        <?php if (!$embedded): ?>
        <a href="/cargo_hold/manage_system_details.php" class="back-btn">Go Back</a>
        <?php endif; ?>
    </div>
</body>

</html>