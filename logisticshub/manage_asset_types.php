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
    $type_name = strtoupper(trim($_POST['type_name']));
    $type_id = isset($_POST['type_id']) ? intval($_POST['type_id']) : 0;

    if ($type_name === '') {
        $message = "Type name cannot be empty.";
    } else {
        $dupCheck = $conn->prepare("SELECT COUNT(*) FROM asset_types WHERE UPPER(type_name) = ? AND id != ?");
        $dupCheck->bind_param("si", $type_name, $type_id);
        $dupCheck->execute();
        $dupCheck->bind_result($count);
        $dupCheck->fetch();
        $dupCheck->close();

        if ($count > 0) {
            $message = "Duplicate type name found.";
        } else {
            if ($type_id > 0) {
                $stmt = $conn->prepare("UPDATE asset_types SET type_name = ? WHERE id = ?");
                $stmt->bind_param("si", $type_name, $type_id);
                $stmt->execute();
                $stmt->close();
                log_audit_event($conn, 'asset_type', $type_id, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['type_name' => $type_name]));
                $message = "Type updated.";
            } else {
                $stmt = $conn->prepare("INSERT INTO asset_types (type_name) VALUES (?)");
                $stmt->bind_param("s", $type_name);
                $stmt->execute();
                $newId = $conn->insert_id;
                $stmt->close();
                log_audit_event($conn, 'asset_type', $newId, 'CREATE', (int) $_SESSION['user_id'], json_encode(['type_name' => $type_name]));
                $message = "New type added.";
            }
        }
    }
}

$types = [];
$res = $conn->query("SELECT * FROM asset_types ORDER BY type_name");
while ($row = $res->fetch_assoc()) {
    $types[] = $row;
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
    function filterTypes() {
        const input = document.getElementById("filter").value.toUpperCase();
        const rows = document.querySelectorAll("#typesTable tbody tr");
        rows.forEach(row => {
            const name = row.querySelector("td").innerText;
            row.style.display = name.toUpperCase().includes(input) ? "" : "none";
        });
    }

    function populateForm(id, name) {
        document.getElementById("type_id").value = id;
        document.getElementById("type_name").value = name;
    }
    </script>

    <div class="container">
        <h2>Manage Asset Types</h2>

        <?php if (!empty($message)): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="post" action="manage_asset_types.php<?php echo $embedded ? '?embedded=1' : ''; ?>">
            <input type="hidden" name="type_id" id="type_id">
            <label for="type_name">Type Name</label>
            <input type="text" name="type_name" id="type_name" oninput="this.value = this.value.toUpperCase();">
            <button type="submit" class="action-btn">Save</button>
        </form>

        <label for="filter">Filter Types</label>
        <input type="text" id="filter" oninput="filterTypes();">

        <table id="typesTable">
            <thead>
                <tr>
                    <th>Type Name</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($types as $type): ?>
                <tr>
                    <td><?php echo htmlspecialchars($type['type_name']); ?></td>
                    <td>
                        <button class="action-btn"
                            onclick="populateForm('<?php echo $type['id']; ?>', '<?php echo htmlspecialchars($type['type_name'], ENT_QUOTES); ?>')">Edit</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>

</html>