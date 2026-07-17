<?php
// edit_task.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/audit.php';

$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    include '../header.php';
}

// Check admin privileges (assumes an admin has role 'admin' or 'super').
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($role);
$stmt->fetch();
$stmt->close();

$task_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch the task
$task_query = mysqli_query($conn, "SELECT * FROM tasks WHERE id = $task_id");
if (mysqli_num_rows($task_query) != 1) {
    echo '<p style="color: var(--polaris-danger);">Task not found.</p>';
    exit();
}
$task = mysqli_fetch_assoc($task_query);

// Only an admin/super user, or the user the task is assigned to, may edit it.
if ($role !== 'admin' && $role !== 'super' && (int)$task['assigned_to'] !== (int)$_SESSION['user_id']) {
    echo '<p style="color: var(--polaris-danger);">You do not have permission to edit this task.</p>';
    exit();
}

// Save the original assigned_to for notification check
$original_assigned_to = $task['assigned_to'];

// Fetch users for assigning
$user_result = mysqli_query($conn, "SELECT id, first_name, last_name FROM users WHERE is_active = 1");

// Track error or success
$message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $status = mysqli_real_escape_string($conn, $_POST['status']);
    $completion_comment = mysqli_real_escape_string($conn, $_POST['completion_comment']);

    $update_parts = [
        "status = '$status'",
        "completion_comment = '$completion_comment'"
    ];

    // Tracks what actually changed, for the audit trail.
    $changes = [];
    if ($status !== $task['status']) {
        $changes['status'] = ['from' => $task['status'], 'to' => $status];
    }
    if ($completion_comment !== ($task['completion_comment'] ?? '')) {
        $changes['completion_comment'] = ['from' => $task['completion_comment'], 'to' => $completion_comment];
    }

    $job_ref_error = false;

    if ($role == 'admin' || $role == 'super') {
        $assigned_to = intval($_POST['assigned_to']);
        $custom_ref = trim($_POST['custom_ref']);
        $description = mysqli_real_escape_string($conn, $_POST['description']);

        // Job ref is free text - validate it actually exists and resolve job_id.
        $jobLookup = $conn->prepare("SELECT job_id FROM jobs WHERE custom_ref = ? LIMIT 1");
        $jobLookup->bind_param("s", $custom_ref);
        $jobLookup->execute();
        $jobLookup->bind_result($resolved_job_id);
        if (!$jobLookup->fetch()) {
            $job_ref_error = true;
            $message = '<p style="color: var(--polaris-danger);">No case found with Custom Ref "' . htmlspecialchars($custom_ref) . '". Task was not updated.</p>';
        }
        $jobLookup->close();

        if (!$job_ref_error) {
            $custom_ref_escaped = mysqli_real_escape_string($conn, $custom_ref);
            $update_parts[] = "assigned_to = '$assigned_to'";
            $update_parts[] = "custom_ref = '$custom_ref_escaped'";
            $update_parts[] = "job_id = " . (int) $resolved_job_id;
            $update_parts[] = "description = '$description'";

            if ($assigned_to !== (int) $task['assigned_to']) {
                $changes['assigned_to'] = ['from' => (int) $task['assigned_to'], 'to' => $assigned_to];
            }
            if ($custom_ref !== $task['custom_ref']) {
                $changes['custom_ref'] = ['from' => $task['custom_ref'], 'to' => $custom_ref];
            }
            if ($description !== $task['description']) {
                $changes['description'] = ['from' => $task['description'], 'to' => $description];
            }
        }
    }

    if (!$job_ref_error) {
        $update_query = "UPDATE tasks SET " . implode(", ", $update_parts) . " WHERE id = $task_id";

        if (mysqli_query($conn, $update_query)) {
            // Task updated successfully
            if (!empty($changes)) {
                log_audit_event($conn, 'task', $task_id, 'UPDATE', (int) $_SESSION['user_id'], json_encode($changes));
            }
            $message = '<p style="color: var(--polaris-success-strong);">Task updated successfully.</p>';

            // If assigned_to has changed, create a notification
            if (($role == 'admin' || $role == 'super') && isset($assigned_to) && $assigned_to != $original_assigned_to) {
                $notif_message = "Task Reassigned: " . $task['task_ref'];
                $insert_notif = "INSERT INTO notifications (user_id, type, message)
                             VALUES ('$assigned_to', 'task_reassigned', '$notif_message')";
                mysqli_query($conn, $insert_notif);
            }

            // Refresh task details after update
            $task_query = mysqli_query($conn, "SELECT * FROM tasks WHERE id = $task_id");
            $task = mysqli_fetch_assoc($task_query);

            if ($status === 'completed') {
                require_once '../includes/achievements.php';
                check_and_unlock_achievements($conn, (int) $original_assigned_to, 'tasks_completed');
            }
        } else {
            $message = '<p style="color: var(--polaris-danger);">Error updating task: ' . mysqli_error($conn) . '</p>';
        }
    }
}
?>

<!-- Padding to push content below header -->
<?php if (!$embedded): ?>
<div style="margin-top: 150px;"></div>
<?php endif; ?>

<div class="container"
    style="max-width: 800px; margin: 0 auto; padding: 20px; background-color: var(--polaris-surface-deep); border-radius: 8px;">

    <h1 style="margin-bottom: 20px;">Edit Task <?php echo htmlspecialchars($task['task_ref']); ?></h1>

    <?php echo $message; ?>

    <form method="POST"
        action="edit_task.php?id=<?php echo $task_id; ?><?php echo $embedded ? '&embedded=1' : ''; ?>">

        <?php if ($role == 'admin' || $role == 'super') { ?>
        <div style="margin-bottom: 15px;">
            <label for="description">Task Description:</label><br>
            <textarea name="description" id="description" rows="5"
                style="width:100%; padding:8px; background-color:var(--polaris-surface-alt); color:var(--polaris-text); border:1px solid var(--polaris-border-hover);"
                required><?php echo htmlspecialchars($task['description']); ?></textarea>
        </div>

        <div style="margin-bottom: 15px;">
            <label for="custom_ref">Job Custom Ref:</label><br>
            <input type="text" name="custom_ref" id="custom_ref" required
                style="width:100%; padding:8px; background-color:var(--polaris-surface-alt); color:var(--polaris-text); border:1px solid var(--polaris-border-hover);"
                value="<?php echo htmlspecialchars($task['custom_ref']); ?>">
        </div>

        <div style="margin-bottom: 15px;">
            <label for="assigned_to">Assign To:</label><br>
            <select name="assigned_to" id="assigned_to" required
                style="width:100%; padding:8px; background-color:var(--polaris-surface-alt); color:var(--polaris-text); border:1px solid var(--polaris-border-hover);">
                <?php
                    mysqli_data_seek($user_result, 0); // reset pointer
                    while ($user = mysqli_fetch_assoc($user_result)) { ?>
                <option value="<?php echo $user['id']; ?>"
                    <?php if ($task['assigned_to'] == $user['id']) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                </option>
                <?php } ?>
            </select>
        </div>
        <?php } else { ?>
        <p style="margin-bottom: 20px; color: var(--polaris-text);">
            <?php echo nl2br(htmlspecialchars($task['description'])); ?>
        </p>
        <?php } ?>

        <div style="margin-bottom: 15px;">
            <label for="status">Status:</label><br>
            <select name="status" id="status" required
                style="width:100%; padding:8px; background-color:var(--polaris-surface-alt); color:var(--polaris-text); border:1px solid var(--polaris-border-hover);">
                <option value="not_started" <?php if ($task['status'] == 'not_started') echo 'selected'; ?>>Not Started
                </option>
                <option value="in_progress" <?php if ($task['status'] == 'in_progress') echo 'selected'; ?>>In Progress
                </option>
                <option value="completed" <?php if ($task['status'] == 'completed') echo 'selected'; ?>>Completed
                </option>
            </select>
        </div>

        <div style="margin-bottom: 15px;">
            <label for="completion_comment">Reply / Completion Comment:</label><br>
            <textarea name="completion_comment" id="completion_comment" rows="5"
                style="width:100%; padding:8px; background-color:var(--polaris-surface-alt); color:var(--polaris-text); border:1px solid var(--polaris-border-hover);"><?php echo htmlspecialchars($task['completion_comment'] ?? ''); ?></textarea>
        </div>

        <div style="margin-top: 20px;">
            <button type="submit"
                style="padding: 5px 10px; background-color: var(--polaris-accent); color: var(--polaris-text); font-size: 14px; border: none; border-radius: 3px;">
                Save Changes
            </button>
            <a href="tasking.php<?php echo $embedded ? '?embedded=1' : ''; ?>"
                onclick="history.back(); return false;"
                style="padding: 5px 10px; background-color: var(--polaris-accent); color: var(--polaris-text); font-size: 14px; text-decoration: none; border-radius: 3px; margin-left: 10px;">
                Cancel
            </a>
            <a href="view_task_history.php?id=<?php echo $task_id; ?><?php echo $embedded ? '&embedded=1' : ''; ?>"
                style="padding: 5px 10px; background-color: var(--polaris-accent); color: var(--polaris-text); font-size: 14px; text-decoration: none; border-radius: 3px; margin-left: 10px;">
                View History
            </a>
        </div>

    </form>

</div>