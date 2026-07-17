<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once '../includes/audit.php';
$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    require_once('../header.php');
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($locations as $loc): ?>
                <tr>
                    <td><?php echo htmlspecialchars($loc['location_name']); ?></td>
                    <td>
                        <button class="action-btn"
                            onclick="populateForm('<?php echo $loc['id']; ?>', '<?php echo htmlspecialchars($loc['location_name'], ENT_QUOTES); ?>')">Edit</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>

</html>