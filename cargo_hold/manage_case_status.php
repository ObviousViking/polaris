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
    if (isset($_POST['delete_status'])) {
        if (!$canDelete) {
            $message = "Only admins can delete statuses.";
        } else {
        $delete_id = intval($_POST['delete_status']);

        // Check if any cases use this status
        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM jobs WHERE status_id = ?");
        $checkStmt->bind_param("i", $delete_id);
        $checkStmt->execute();
        $checkStmt->bind_result($caseCount);
        $checkStmt->fetch();
        $checkStmt->close();

        if ($caseCount > 0) {
            $message = "Cannot delete status. Please reassign all associated cases first.";
        } elseif (($deleteReason = require_deletion_reason_or_fail($conn)) === false) {
            $message = "A reason is required to delete this status.";
        } else {
            $nameStmt = $conn->prepare("SELECT status_name FROM job_status WHERE status_id = ?");
            $nameStmt->bind_param("i", $delete_id);
            $nameStmt->execute();
            $nameStmt->bind_result($deletedName);
            $nameStmt->fetch();
            $nameStmt->close();

            $deleteStmt = $conn->prepare("DELETE FROM job_status WHERE status_id = ?");
            $deleteStmt->bind_param("i", $delete_id);
            $deleteStmt->execute();
            $deleteStmt->close();
            log_audit_event($conn, 'job_status', $delete_id, 'DELETE', (int) $_SESSION['user_id'], json_encode(['status_name' => $deletedName, 'reason' => $deleteReason]));
            $message = "Status deleted successfully.";
        }
        }
    } else {
        $status_name = strtoupper(trim($_POST['status_name']));
        $status_id = isset($_POST['status_id']) ? intval($_POST['status_id']) : 0;

        if ($status_name === '') {
            $message = "Status name cannot be empty.";
        } else {
            $dupCheck = $conn->prepare("SELECT COUNT(*) FROM job_status WHERE UPPER(status_name) = ? AND status_id != ?");
            $dupCheck->bind_param("si", $status_name, $status_id);
            $dupCheck->execute();
            $dupCheck->bind_result($count);
            $dupCheck->fetch();
            $dupCheck->close();

            if ($count > 0) {
                $message = "Duplicate status name found.";
            } else {
                if ($status_id > 0) {
                    $stmt = $conn->prepare("UPDATE job_status SET status_name = ? WHERE status_id = ?");
                    $stmt->bind_param("si", $status_name, $status_id);
                    $stmt->execute();
                    $stmt->close();
                    log_audit_event($conn, 'job_status', $status_id, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['status_name' => $status_name]));
                    $message = "Status updated.";
                } else {
                    $stmt = $conn->prepare("INSERT INTO job_status (status_name) VALUES (?)");
                    $stmt->bind_param("s", $status_name);
                    $stmt->execute();
                    $newId = $conn->insert_id;
                    $stmt->close();
                    log_audit_event($conn, 'job_status', $newId, 'CREATE', (int) $_SESSION['user_id'], json_encode(['status_name' => $status_name]));
                    $message = "New status added.";
                }
            }
        }
    }
}

$statuses = [];
$res = $conn->query("SELECT * FROM job_status ORDER BY status_name");
while ($row = $res->fetch_assoc()) {
    $statuses[] = $row;
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
        border-radius: 8px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
        box-sizing: border-box;
    }

    h2 {
        text-align: center;
        font-size: 24px;
        margin-bottom: 20px;
    }

    input[type="text"] {
        width: 100%;
        max-width: 100%;
        padding: 8px;
        margin-bottom: 10px;
        background: var(--polaris-bg);
        border: 1px solid var(--polaris-border);
        color: var(--polaris-text);
        box-sizing: border-box;
        border-radius: 4px;
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
        text-decoration: none;
        display: inline-block;
        transition: background 0.3s ease;
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
    function filterStatuses() {
        const input = document.getElementById("filter").value.toUpperCase();
        const rows = document.querySelectorAll("#statusTable tbody tr");
        rows.forEach(row => {
            const name = row.querySelector("td").innerText;
            row.style.display = name.toUpperCase().includes(input) ? "" : "none";
        });
    }

    function populateForm(id, name) {
        document.getElementById("status_id").value = id;
        document.getElementById("status_name").value = name;
    }
    </script>

    <div class="container">
        <h2>Manage Case Status</h2>

        <?php if (!empty($message)): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="post" action="manage_case_status.php<?php echo $embedded ? '?embedded=1' : ''; ?>">
            <input type="hidden" name="status_id" id="status_id">
            <label for="status_name">Status Name</label>
            <input type="text" name="status_name" id="status_name" oninput="this.value = this.value.toUpperCase();">
            <button type="submit" class="action-btn">Save</button>
        </form>

        <label for="filter">Filter Statuses</label>
        <input type="text" id="filter" oninput="filterStatuses();">

        <table id="statusTable">
            <thead>
                <tr>
                    <th>Status Name</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($statuses as $status): ?>
                <tr>
                    <td><?php echo htmlspecialchars($status['status_name']); ?></td>
                    <td>
                        <button class="action-btn"
                            onclick="populateForm('<?php echo $status['status_id']; ?>', '<?php echo htmlspecialchars($status['status_name'], ENT_QUOTES); ?>')">Edit</button>
                        <?php if ($canDelete): ?>
                        <form method="post"
                            action="manage_case_status.php<?php echo $embedded ? '?embedded=1' : ''; ?>"
                            style="display:inline;">
                            <input type="hidden" name="delete_status" value="<?php echo $status['status_id']; ?>">
                            <button type="submit" class="action-btn delete-btn"
                                onclick="return confirmDeleteWithReason(this.form, 'Are you sure you want to delete this status?')">Delete</button>
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