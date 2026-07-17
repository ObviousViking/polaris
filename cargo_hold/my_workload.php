<?php
// my_workload.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';

$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    include '../header.php';
}

$user_id = $_SESSION['user_id'];

// Get user name
$user_query = mysqli_query($conn, "SELECT first_name, last_name FROM users WHERE id = $user_id LIMIT 1");
$user = mysqli_fetch_assoc($user_query);
$user_fullname = htmlspecialchars($user['first_name'] . ' ' . $user['last_name']);

// Fetch tasks
$tasks_result = mysqli_query($conn, "SELECT * FROM tasks WHERE assigned_to = $user_id ORDER BY created_at DESC");

// Fetch exhibits
$exhibits_query = "
    SELECT e.*, j.custom_ref, l.location_name
    FROM exhibits e
    JOIN jobs j ON e.job_id = j.job_id
    LEFT JOIN exhibit_locations l ON e.location_id = l.location_id
    WHERE e.allocated_to = $user_id AND e.deleted_at IS NULL
";
$exhibits_result = mysqli_query($conn, $exhibits_query);

// Fetch exported items (extractions) assigned to this user.
$extractions_stmt = $conn->prepare("
    SELECT ei.item_id, ei.extraction_ref, ei.description, ei.status, ei.extracted_on, j.job_id, j.custom_ref
    FROM exported_items ei
    JOIN jobs j ON ei.job_id = j.job_id
    WHERE ei.assigned_to = ?
    ORDER BY ei.extracted_on DESC
");
$extractions_stmt->bind_param("i", $user_id);
$extractions_stmt->execute();
$extractions_result = $extractions_stmt->get_result();
?>

<!-- Padding to push content below header -->
<?php if (!$embedded): ?>
<div style="margin-top: 150px;"></div>
<?php endif; ?>

<div class="content-wrapper">
    <h2><?php echo $user_fullname; ?>'s Workload</h2>

    <!-- Tasks Section -->
    <h3>Allocated Tasks</h3>
    <div class="table-scroll">
        <table>
            <tr>
                <th>Task Ref</th>
                <th>Description</th>
                <th>Comment</th>
                <th>Status</th>
                <th>Job</th>
                <th>History</th>
            </tr>
            <?php if (mysqli_num_rows($tasks_result) > 0): ?>
            <?php while ($task = mysqli_fetch_assoc($tasks_result)): ?>
            <tr>
                <td><a href="/captains_quarters/edit_task.php?id=<?php echo $task['id']; ?>" target="_top">
                        <?php echo htmlspecialchars($task['task_ref']); ?></a></td>
                <td><?php echo htmlspecialchars($task['description']); ?></td>
                <td><?php echo $task['completion_comment'] ? htmlspecialchars($task['completion_comment']) : '-'; ?></td>
                <td>
                    <?php
                    if ($task['status'] == 'not_started') {
                        echo '<span class="badge badge-warning">Not Started</span>';
                    } elseif ($task['status'] == 'in_progress') {
                        echo '<span class="badge badge-accent">In Progress</span>';
                    } else {
                        echo '<span class="badge badge-success">Completed</span>';
                    }
                    ?>
                </td>
                <td><a href="/cargo_hold/job.php?job_id=<?php echo $task['job_id']; ?>" target="_top">
                        <?php echo htmlspecialchars($task['custom_ref']); ?></a></td>
                <td><a href="/captains_quarters/view_task_history.php?id=<?php echo $task['id']; ?>" target="_top"
                        class="action-btn small">History</a></td>
            </tr>
            <?php endwhile; ?>
            <?php else: ?>
            <tr>
                <td colspan="6" style="text-align:center;">No tasks assigned.</td>
            </tr>
            <?php endif; ?>
        </table>
    </div>

    <!-- Exhibits Section -->
    <h3 style="margin-top: 30px;">Allocated Exhibits</h3>
    <div class="table-scroll">
        <table>
            <tr>
                <th>Exhibit Ref</th>
                <th>Description</th>
                <th>Job</th>
                <th>Priority</th>
                <th>Location</th>
                <th>Booked In</th>
                <th>Action</th>
            </tr>
            <?php if (mysqli_num_rows($exhibits_result) > 0): ?>
            <?php while ($ex = mysqli_fetch_assoc($exhibits_result)): ?>
            <tr>
                <td><a href="/cargo_hold/edit_exhibit.php?exhibit_id=<?php echo $ex['exhibit_id']; ?>" target="_top">
                        <?php echo htmlspecialchars($ex['exhibit_ref']); ?></a></td>
                <td><?php echo htmlspecialchars($ex['item_description']); ?></td>
                <td><a href="/cargo_hold/job.php?job_id=<?php echo $ex['job_id']; ?>" target="_top">
                        <?php echo htmlspecialchars($ex['custom_ref']); ?></a></td>
                <td><?php echo htmlspecialchars($ex['urgency']); ?></td>
                <td><?php echo htmlspecialchars($ex['location_name']); ?></td>
                <td><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($ex['time_in']))); ?></td>
                <td><a href="/captains_log/examination.php?exhibit_id=<?php echo $ex['exhibit_id']; ?>" target="_top"
                        class="action-btn small">Examination</a></td>
            </tr>
            <?php endwhile; ?>
            <?php else: ?>
            <tr>
                <td colspan="7" style="text-align:center;">No exhibits assigned.</td>
            </tr>
            <?php endif; ?>
        </table>
    </div>

    <h3 style="margin-top: 30px;">Allocated Extractions</h3>
    <div class="table-scroll">
        <table>
            <tr>
                <th>Extraction Ref</th>
                <th>Description</th>
                <th>Job</th>
                <th>Status</th>
                <th>Extracted On</th>
                <th>Action</th>
            </tr>
            <?php if (mysqli_num_rows($extractions_result) > 0): ?>
            <?php while ($item = mysqli_fetch_assoc($extractions_result)): ?>
            <tr>
                <td><a href="/cargo_hold/edit_exported_item.php?item_id=<?php echo $item['item_id']; ?>" target="_top">
                        <?php echo htmlspecialchars($item['extraction_ref']); ?></a></td>
                <td><?php echo htmlspecialchars($item['description'] ?? ''); ?></td>
                <td><a href="/cargo_hold/job.php?job_id=<?php echo $item['job_id']; ?>" target="_top">
                        <?php echo htmlspecialchars($item['custom_ref']); ?></a></td>
                <td>
                    <?php
                    $statusBadge = [
                        'Awaiting Review' => 'badge-warning',
                        'Being Reviewed'  => 'badge-accent',
                        'Reviewed'        => 'badge-success',
                        'Not Reviewed'    => 'badge-warning',
                    ][$item['status']] ?? 'badge-accent';
                    ?>
                    <span class="badge <?php echo $statusBadge; ?>"><?php echo htmlspecialchars($item['status']); ?></span>
                </td>
                <td><?php echo $item['extracted_on'] ? htmlspecialchars(date('d-m-Y', strtotime($item['extracted_on']))) : '-'; ?></td>
                <td><a href="/cargo_hold/edit_exported_item.php?item_id=<?php echo $item['item_id']; ?>" target="_top"
                        class="action-btn small">Edit</a></td>
            </tr>
            <?php endwhile; ?>
            <?php else: ?>
            <tr>
                <td colspan="6" style="text-align:center;">No extractions assigned.</td>
            </tr>
            <?php endif; ?>
        </table>
    </div>
</div>

<style>
.action-btn {
    display: inline-block;
    padding: 6px 12px;
    background-color: var(--polaris-accent);
    color: var(--polaris-text);
    font-size: 13px;
    text-decoration: none;
    border-radius: 3px;
}

.action-btn:hover {
    background-color: var(--polaris-accent-hover);
}

.action-btn.small {
    padding: 3px 8px;
    font-size: 12px;
}

.table-scroll {
    width: 100%;
    overflow-x: auto;
    margin-top: 10px;
}

table {
    width: 100%;
    border-collapse: collapse;
    background: var(--polaris-surface);
    border-radius: 5px;
}

th, td {
    padding: 8px 10px;
    text-align: left;
    border-bottom: 1px solid var(--polaris-border);
    font-size: 13px;
    vertical-align: top;
}

th {
    background: var(--polaris-divider);
    white-space: nowrap;
}

tr:last-child td {
    border-bottom: none;
}

tr:hover td {
    background: var(--polaris-surface-alt);
}

table a {
    color: var(--polaris-accent);
    text-decoration: none;
}

table a:hover {
    text-decoration: underline;
}

.badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.badge-warning {
    background: var(--polaris-warning);
    color: #1a1a1a;
}

.badge-accent {
    background: var(--polaris-accent);
    color: var(--polaris-text);
}

.badge-success {
    background: var(--polaris-success-bg);
    color: var(--polaris-success-text);
}
</style>
