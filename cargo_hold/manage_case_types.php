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
    if (isset($_POST['delete_case_type'])) {
        if (!$canDelete) {
            $message = "Only admins can delete case types.";
        } else {
        $delete_id = intval($_POST['delete_case_type']);

        // Check if any cases use this case type
        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM jobs WHERE case_type_id = ?");
        $checkStmt->bind_param("i", $delete_id);
        $checkStmt->execute();
        $checkStmt->bind_result($caseCount);
        $checkStmt->fetch();
        $checkStmt->close();

        if ($caseCount > 0) {
            $message = "Cannot delete case type. Please reassign all associated cases first.";
        } elseif (($deleteReason = require_deletion_reason_or_fail($conn)) === false) {
            $message = "A reason is required to delete this case type.";
        } else {
            $nameStmt = $conn->prepare("SELECT type_name FROM case_types WHERE case_type_id = ?");
            $nameStmt->bind_param("i", $delete_id);
            $nameStmt->execute();
            $nameStmt->bind_result($deletedName);
            $nameStmt->fetch();
            $nameStmt->close();

            $deleteStmt = $conn->prepare("DELETE FROM case_types WHERE case_type_id = ?");
            $deleteStmt->bind_param("i", $delete_id);
            $deleteStmt->execute();
            $deleteStmt->close();
            log_audit_event($conn, 'case_type', $delete_id, 'DELETE', (int) $_SESSION['user_id'], json_encode(['type_name' => $deletedName, 'reason' => $deleteReason]));
            $message = "Case type deleted successfully.";
        }
        }
    } else {
        $type_name = strtoupper(trim($_POST['type_name']));
        $case_type_id = isset($_POST['case_type_id']) ? intval($_POST['case_type_id']) : 0;

        if ($type_name === '') {
            $message = "Case type name cannot be empty.";
        } else {
            $dupCheck = $conn->prepare("SELECT COUNT(*) FROM case_types WHERE UPPER(type_name) = ? AND case_type_id != ?");
            $dupCheck->bind_param("si", $type_name, $case_type_id);
            $dupCheck->execute();
            $dupCheck->bind_result($count);
            $dupCheck->fetch();
            $dupCheck->close();

            if ($count > 0) {
                $message = "Duplicate case type name found.";
            } else {
                if ($case_type_id > 0) {
                    $stmt = $conn->prepare("UPDATE case_types SET type_name = ? WHERE case_type_id = ?");
                    $stmt->bind_param("si", $type_name, $case_type_id);
                    $stmt->execute();
                    $stmt->close();
                    log_audit_event($conn, 'case_type', $case_type_id, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['type_name' => $type_name]));
                    $message = "Case type updated.";
                } else {
                    $stmt = $conn->prepare("INSERT INTO case_types (type_name) VALUES (?)");
                    $stmt->bind_param("s", $type_name);
                    $stmt->execute();
                    $newId = $conn->insert_id;
                    $stmt->close();
                    log_audit_event($conn, 'case_type', $newId, 'CREATE', (int) $_SESSION['user_id'], json_encode(['type_name' => $type_name]));
                    $message = "New case type added.";
                }
            }
        }
    }
}

$case_types = [];
$res = $conn->query("SELECT * FROM case_types ORDER BY type_name");
while ($row = $res->fetch_assoc()) {
    $case_types[] = $row;
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
    function filterCaseTypes() {
        const input = document.getElementById("filter").value.toUpperCase();
        const rows = document.querySelectorAll("#caseTypesTable tbody tr");
        rows.forEach(row => {
            const name = row.querySelector("td").innerText;
            row.style.display = name.toUpperCase().includes(input) ? "" : "none";
        });
    }

    function populateForm(id, name) {
        document.getElementById("case_type_id").value = id;
        document.getElementById("type_name").value = name;
    }
    </script>

    <div class="container">
        <h2>Manage Case Types</h2>

        <?php if (!empty($message)): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="post" action="manage_case_types.php<?php echo $embedded ? '?embedded=1' : ''; ?>">
            <input type="hidden" name="case_type_id" id="case_type_id">
            <label for="type_name">Case Type Name</label>
            <input type="text" name="type_name" id="type_name" oninput="this.value = this.value.toUpperCase();">
            <button type="submit" class="action-btn">Save</button>
        </form>

        <label for="filter">Filter Case Types</label>
        <input type="text" id="filter" oninput="filterCaseTypes();">

        <table id="caseTypesTable">
            <thead>
                <tr>
                    <th>Case Type Name</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($case_types as $type): ?>
                <tr>
                    <td><?php echo htmlspecialchars($type['type_name']); ?></td>
                    <td>
                        <button class="action-btn"
                            onclick="populateForm('<?php echo $type['case_type_id']; ?>', '<?php echo htmlspecialchars($type['type_name'], ENT_QUOTES); ?>')">Edit</button>
                        <?php if ($canDelete): ?>
                        <form method="post" action="manage_case_types.php<?php echo $embedded ? '?embedded=1' : ''; ?>"
                            style="display:inline;">
                            <input type="hidden" name="delete_case_type" value="<?php echo $type['case_type_id']; ?>">
                            <button type="submit" class="action-btn delete-btn"
                                onclick="return confirmDeleteWithReason(this.form, 'Are you sure you want to delete this case type?')">Delete</button>
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