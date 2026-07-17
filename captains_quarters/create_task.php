<?php
// create_task.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/audit.php';

$embedded = isset($_GET['embedded']) || isset($_POST['embedded']);
$error = ''; // Track error message

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $custom_ref = mysqli_real_escape_string($conn, trim($_POST['custom_ref']));
    $assigned_to = intval($_POST['assigned_to']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);

    // Validate if custom_ref exists in jobs table
    $job_check = mysqli_query($conn, "SELECT job_id FROM jobs WHERE custom_ref = '$custom_ref' LIMIT 1");

    if (mysqli_num_rows($job_check) > 0) {
        // Valid custom ref - Insert Task
        $job_row = mysqli_fetch_assoc($job_check);
        $job_id = intval($job_row['job_id']);

        $last_task = mysqli_query($conn, "SELECT id FROM tasks ORDER BY id DESC LIMIT 1");
        $last_id = 0;
        if ($row = mysqli_fetch_assoc($last_task)) {
            $last_id = $row['id'];
        }
        $next_id = $last_id + 1;
        $task_ref = 'T' . str_pad($next_id, 4, '0', STR_PAD_LEFT);

        $insert = "INSERT INTO tasks (task_ref, custom_ref, job_id, assigned_to, description, status)
                   VALUES ('$task_ref', '$custom_ref', '$job_id', '$assigned_to', '$description', 'not_started')";
        
        if (mysqli_query($conn, $insert)) {
            $new_task_id = mysqli_insert_id($conn);
            log_audit_event($conn, 'task', $new_task_id, 'CREATE', (int) $_SESSION['user_id'], json_encode(['task_ref' => $task_ref, 'custom_ref' => $custom_ref, 'assigned_to' => $assigned_to, 'description' => $description]));

            // Task inserted, now create notification
            $notif_message = "New Task Assigned: " . $task_ref;
            $insert_notif = "INSERT INTO notifications (user_id, type, message)
                             VALUES ('$assigned_to', 'task_assigned', '$notif_message')";
            mysqli_query($conn, $insert_notif);

            header("Location: tasking.php" . ($embedded ? '?embedded=1' : ''));
            exit();
        } else {
            $error = "Error inserting task: " . mysqli_error($conn);
        }
    } else {
        $error = "Custom Ref not found! Please check the reference and try again.";
    }
}


// Fetch active users
$user_result = mysqli_query($conn, "SELECT id, first_name, last_name FROM users WHERE is_active = 1");

if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    include '../header.php';
}
?>

<!-- Padding to push content below header -->
<?php if (!$embedded): ?>
<div style="margin-top: 150px;"></div>
<?php endif; ?>

<div class="container"
    style="max-width: 800px; margin: 0 auto; padding: 20px; background-color: var(--polaris-surface-deep); border-radius: 8px;">

    <h1 style="margin-bottom: 20px;">Create New Task</h1>

    <?php if (!empty($error)) { ?>
    <div style="background-color: var(--polaris-error-bg); color: var(--polaris-error-text); padding: 10px; margin-bottom: 20px; border-radius: 5px;">
        <?php echo htmlspecialchars($error); ?>
    </div>
    <?php } ?>

    <form method="POST" action="create_task.php<?php echo $embedded ? '?embedded=1' : ''; ?>">

        <div style="margin-bottom: 15px;">
            <label for="custom_ref">Job Custom Ref:</label><br>
            <input type="text" name="custom_ref" id="custom_ref" required
                style="width:100%; padding:8px; background-color:var(--polaris-surface-alt); color:var(--polaris-text); border:1px solid var(--polaris-border-hover);"
                value="<?php echo isset($_POST['custom_ref']) ? htmlspecialchars($_POST['custom_ref']) : ''; ?>">
        </div>

        <div style="margin-bottom: 15px;">
            <label for="assigned_to">Assign To:</label><br>
            <select name="assigned_to" id="assigned_to" required
                style="width:100%; padding:8px; background-color:var(--polaris-surface-alt); color:var(--polaris-text); border:1px solid var(--polaris-border-hover);">
                <option value="">-- Select User --</option>
                <?php while ($user = mysqli_fetch_assoc($user_result)) { ?>
                <option value="<?php echo $user['id']; ?>" <?php
                        if (isset($_POST['assigned_to']) && $_POST['assigned_to'] == $user['id']) echo 'selected';
                        ?>>
                    <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                </option>
                <?php } ?>
            </select>
        </div>

        <div style="margin-bottom: 15px;">
            <label for="description">Task Description:</label><br>
            <textarea name="description" id="description" rows="5"
                style="width:100%; padding:8px; background-color:var(--polaris-surface-alt); color:var(--polaris-text); border:1px solid var(--polaris-border-hover);"
                required><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
        </div>

        <div style="margin-top: 20px;">
            <button type="submit"
                style="padding: 5px 10px; background-color: var(--polaris-success-strong); color: var(--polaris-text); font-size: 14px; border: none; border-radius: 3px;">
                Create Task
            </button>
            <a href="tasking.php<?php echo $embedded ? '?embedded=1' : ''; ?>"
                style="padding: 5px 10px; background-color: var(--polaris-accent); color: var(--polaris-text); font-size: 14px; text-decoration: none; border-radius: 3px; margin-left: 10px;">
                Cancel
            </a>
        </div>

    </form>

</div>