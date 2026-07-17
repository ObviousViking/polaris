<?php
// tasking.php
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

$user_result = mysqli_query($conn, "SELECT id, first_name, last_name FROM users WHERE is_active = 1");
$user_filter = isset($_GET['user_id']) ? intval($_GET['user_id']) : '';

// Allowlist - $_GET['status'] must never be concatenated into SQL unescaped.
$validStatuses = ['not_started', 'in_progress', 'completed'];
$rawStatus = isset($_GET['status']) ? $_GET['status'] : 'not_completed';
$status_filter = ($rawStatus === 'all' || $rawStatus === 'not_completed' || in_array($rawStatus, $validStatuses, true))
    ? $rawStatus
    : 'not_completed';

$conditions = [];
$params = [];
$types = "";

if (!empty($user_filter)) {
    $conditions[] = "assigned_to = ?";
    $types .= "i";
    $params[] = $user_filter;
}

if ($status_filter === 'not_completed') {
    $conditions[] = "status != 'completed'";
} elseif ($status_filter !== 'all') {
    $conditions[] = "status = ?";
    $types .= "s";
    $params[] = $status_filter;
}

$query = "SELECT tasks.*, users.first_name, users.last_name
          FROM tasks
          JOIN users ON tasks.assigned_to = users.id"
    . (empty($conditions) ? "" : " WHERE " . implode(" AND ", $conditions))
    . " ORDER BY tasks.created_at DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $bind_names = [$types];
    foreach ($params as $key => $value) {
        $bind_names[] = &$params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_names);
}
$stmt->execute();
$result = $stmt->get_result();
?>

<!-- Padding to push content well below header -->
<?php if (!$embedded): ?>
<div style="margin-top: 150px;"></div>
<?php endif; ?>

<div class="content-wrapper">
    <h2>Task Management</h2>

    <div class="toolbar">
        <a href="create_task.php<?php echo $embedded ? '?embedded=1' : ''; ?>" class="action-btn">Create New Task</a>
    </div>

    <!-- Filters -->
    <form method="GET" class="filters">
        <?php if ($embedded): ?>
        <input type="hidden" name="embedded" value="1">
        <?php endif; ?>
        <div class="field">
            <label for="user_id">Filter by User:</label>
            <select name="user_id" id="user_id">
                <option value="">All Users</option>
                <?php while ($user = mysqli_fetch_assoc($user_result)): ?>
                <option value="<?php echo $user['id']; ?>" <?php if ($user_filter == $user['id']) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="field">
            <label for="status">Filter by Status:</label>
            <select name="status" id="status">
                <option value="all" <?php if ($status_filter == 'all') echo 'selected'; ?>>All Tasks</option>
                <option value="not_completed" <?php if ($status_filter == 'not_completed') echo 'selected'; ?>>Not
                    Completed</option>
                <option value="not_started" <?php if ($status_filter == 'not_started') echo 'selected'; ?>>Not
                    Started</option>
                <option value="in_progress" <?php if ($status_filter == 'in_progress') echo 'selected'; ?>>In
                    Progress</option>
                <option value="completed" <?php if ($status_filter == 'completed') echo 'selected'; ?>>Completed
                </option>
            </select>
        </div>

        <button type="submit" class="action-btn">Apply Filters</button>
    </form>

    <div class="table-scroll">
        <table>
            <tr>
                <th>Task Ref</th>
                <th>Job Ref</th>
                <th>Description</th>
                <th>Assigned To</th>
                <th>Status</th>
                <th>History</th>
            </tr>
            <?php if (mysqli_num_rows($result) > 0): ?>
            <?php while ($task = mysqli_fetch_assoc($result)): ?>
            <tr>
                <td><a href="edit_task.php?id=<?php echo $task['id']; ?><?php echo $embedded ? '&embedded=1' : ''; ?>">
                        <?php echo htmlspecialchars($task['task_ref']); ?></a></td>
                <td><a href="/cargo_hold/job.php?job_id=<?php echo $task['job_id']; ?>" target="_top">
                        <?php echo htmlspecialchars($task['custom_ref']); ?></a></td>
                <td><?php echo htmlspecialchars($task['description']); ?></td>
                <td><?php echo htmlspecialchars($task['first_name'] . ' ' . $task['last_name']); ?></td>
                <td>
                    <?php
                    if ($task['status'] == 'not_started') {
                        echo '<span class="badge badge-warning">Not Started</span>';
                    } elseif ($task['status'] == 'in_progress') {
                        echo '<span class="badge badge-accent">In Progress</span>';
                    } elseif ($task['status'] == 'completed') {
                        echo '<span class="badge badge-success">Completed</span>';
                    }
                    ?>
                </td>
                <td><a href="view_task_history.php?id=<?php echo $task['id']; ?><?php echo $embedded ? '&embedded=1' : ''; ?>"
                        class="action-btn small">History</a></td>
            </tr>
            <?php endwhile; ?>
            <?php else: ?>
            <tr>
                <td colspan="6" style="text-align:center;">No tasks found.</td>
            </tr>
            <?php endif; ?>
        </table>
    </div>
</div>

<style>
.toolbar {
    margin-bottom: 15px;
}

.action-btn {
    display: inline-block;
    padding: 6px 12px;
    background-color: var(--polaris-accent);
    color: var(--polaris-text);
    font-size: 13px;
    text-decoration: none;
    border: none;
    border-radius: 3px;
    cursor: pointer;
}

.action-btn:hover {
    background-color: var(--polaris-accent-hover);
}

.action-btn.small {
    padding: 3px 8px;
    font-size: 12px;
}

.filters {
    display: flex;
    align-items: flex-end;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.filters .field label {
    display: block;
    margin-bottom: 3px;
    font-weight: bold;
    color: var(--polaris-text-secondary);
    font-size: 13px;
}

.filters select {
    padding: 6px;
    background-color: var(--polaris-surface-alt);
    color: var(--polaris-text);
    border: 1px solid var(--polaris-border-hover);
    border-radius: 3px;
    font-size: 13px;
}

.table-scroll {
    width: 100%;
    overflow-x: auto;
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
