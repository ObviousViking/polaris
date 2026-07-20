<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once '../includes/audit.php';
require_once '../includes/permissions.php';
require_permission($conn, 'manage_lookups');
$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    require_once('../header.php');
}

// Handle add or update operation
$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $operation_name = strtoupper(trim($_POST['operation_name']));
    $operation_id = isset($_POST['operation_id']) ? intval($_POST['operation_id']) : 0;

    if ($operation_name === '') {
        $message = "Operation name cannot be empty.";
    } else {
        // Check for duplicates
        $dupCheck = $conn->prepare("SELECT COUNT(*) FROM operations WHERE UPPER(operation_name) = ? AND operation_id != ?");
        $dupCheck->bind_param("si", $operation_name, $operation_id);
        $dupCheck->execute();
        $dupCheck->bind_result($count);
        $dupCheck->fetch();
        $dupCheck->close();

        if ($count > 0) {
            $message = "Duplicate operation name found.";
        } else {
            if ($operation_id > 0) {
                $stmt = $conn->prepare("UPDATE operations SET operation_name = ? WHERE operation_id = ?");
                $stmt->bind_param("si", $operation_name, $operation_id);
                $stmt->execute();
                $stmt->close();
                log_audit_event($conn, 'operation', $operation_id, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['operation_name' => $operation_name]));
                $message = "Operation updated.";
            } else {
                $stmt = $conn->prepare("INSERT INTO operations (operation_name) VALUES (?)");
                $stmt->bind_param("s", $operation_name);
                $stmt->execute();
                $newId = $conn->insert_id;
                $stmt->close();
                log_audit_event($conn, 'operation', $newId, 'CREATE', (int) $_SESSION['user_id'], json_encode(['operation_name' => $operation_name]));
                $message = "New operation added.";
            }
        }
    }
}

// Fetch operations list
$operations = [];
$res = $conn->query("SELECT * FROM operations ORDER BY operation_name");
while ($row = $res->fetch_assoc()) {
    $operations[] = $row;
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
    function filterOperations() {
        const input = document.getElementById("filter").value.toUpperCase();
        const rows = document.querySelectorAll("#operationsTable tbody tr");
        rows.forEach(row => {
            const name = row.querySelector("td").innerText;
            row.style.display = name.toUpperCase().includes(input) ? "" : "none";
        });
    }

    function populateForm(id, name) {
        document.getElementById("operation_id").value = id;
        document.getElementById("operation_name").value = name;
    }
    </script>

    <div class="container">
        <h2>Manage Operations</h2>

        <?php if (!empty($message)): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="post" action="manage_operations.php<?php echo $embedded ? '?embedded=1' : ''; ?>">
            <input type="hidden" name="operation_id" id="operation_id">
            <label for="operation_name">Operation Name</label>
            <input type="text" name="operation_name" id="operation_name"
                oninput="this.value = this.value.toUpperCase();">
            <button type="submit" class="action-btn">Save</button>
        </form>

        <label for="filter">Filter Operations</label>
        <input type="text" id="filter" oninput="filterOperations();">

        <table id="operationsTable">
            <thead>
                <tr>
                    <th>Operation Name</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($operations as $op): ?>
                <tr>
                    <td><?php echo htmlspecialchars($op['operation_name']); ?></td>
                    <td>
                        <button class="action-btn"
                            onclick="populateForm('<?php echo $op['operation_id']; ?>', '<?php echo htmlspecialchars($op['operation_name'], ENT_QUOTES); ?>')">Edit</button>
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