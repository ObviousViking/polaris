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

// Handle add or update force
$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $force_name = strtoupper(trim($_POST['force_name']));
    $force_id = isset($_POST['force_id']) ? intval($_POST['force_id']) : 0;

    if ($force_name === '') {
        $message = "Force name cannot be empty.";
    } else {
        // Check for duplicates
        $dupCheck = $conn->prepare("SELECT COUNT(*) FROM forces WHERE UPPER(force_name) = ? AND id != ?");
        $dupCheck->bind_param("si", $force_name, $force_id);
        $dupCheck->execute();
        $dupCheck->bind_result($count);
        $dupCheck->fetch();
        $dupCheck->close();

        if ($count > 0) {
            $message = "Duplicate force name found.";
        } else {
            if ($force_id > 0) {
                $stmt = $conn->prepare("UPDATE forces SET force_name = ? WHERE id = ?");
                $stmt->bind_param("si", $force_name, $force_id);
                $stmt->execute();
                $stmt->close();
                log_audit_event($conn, 'force', $force_id, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['force_name' => $force_name]));
                $message = "Force updated.";
            } else {
                $stmt = $conn->prepare("INSERT INTO forces (force_name) VALUES (?)");
                $stmt->bind_param("s", $force_name);
                $stmt->execute();
                $newId = $conn->insert_id;
                $stmt->close();
                log_audit_event($conn, 'force', $newId, 'CREATE', (int) $_SESSION['user_id'], json_encode(['force_name' => $force_name]));
                $message = "New force added.";
            }
        }
    }
}

// Fetch forces list
$forces = [];
$res = $conn->query("SELECT * FROM forces ORDER BY force_name");
while ($row = $res->fetch_assoc()) {
    $forces[] = $row;
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
    function filterForces() {
        const input = document.getElementById("filter").value.toUpperCase();
        const rows = document.querySelectorAll("#forcesTable tbody tr");
        rows.forEach(row => {
            const name = row.querySelector("td").innerText;
            row.style.display = name.toUpperCase().includes(input) ? "" : "none";
        });
    }

    function populateForm(id, name) {
        document.getElementById("force_id").value = id;
        document.getElementById("force_name").value = name;
    }
    </script>

    <div class="container">
        <h2>Manage Forces</h2>

        <?php if (!empty($message)): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="post" action="manage_forces.php<?php echo $embedded ? '?embedded=1' : ''; ?>">
            <input type="hidden" name="force_id" id="force_id">
            <label for="force_name">Force Name</label>
            <input type="text" name="force_name" id="force_name" oninput="this.value = this.value.toUpperCase();">
            <button type="submit" class="action-btn">Save</button>
        </form>

        <label for="filter">Filter Forces</label>
        <input type="text" id="filter" oninput="filterForces();">

        <table id="forcesTable">
            <thead>
                <tr>
                    <th>Force Name</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($forces as $force): ?>
                <tr>
                    <td><?php echo htmlspecialchars($force['force_name']); ?></td>
                    <td>
                        <button class="action-btn"
                            onclick="populateForm('<?php echo $force['id']; ?>', '<?php echo htmlspecialchars($force['force_name'], ENT_QUOTES); ?>')">Edit</button>
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