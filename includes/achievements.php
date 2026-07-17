<?php
// includes/achievements.php
//
// Achievement catalog + unlock logic for the User Profile achievements panel.
// check_and_unlock_achievements() always recomputes the metric from source
// tables rather than trusting a passed-in count, so it can't be tricked into
// unlocking something the user hasn't actually earned.

const ACHIEVEMENT_DEFINITIONS = [
    ['key' => 'first_login', 'name' => 'First Login', 'description' => 'Logged in for the first time.', 'icon' => '🔑', 'metric' => 'first_login', 'threshold' => 1, 'sort_order' => 1],
    ['key' => 'first_case', 'name' => 'First Case', 'description' => 'Created your first case.', 'icon' => '📁', 'metric' => 'cases_created', 'threshold' => 1, 'sort_order' => 2],
    ['key' => 'case_files_10', 'name' => 'Case Files', 'description' => 'Created 10 cases.', 'icon' => '📂', 'metric' => 'cases_created', 'threshold' => 10, 'sort_order' => 3],
    ['key' => 'caseload_veteran_50', 'name' => 'Caseload Veteran', 'description' => 'Created 50 cases.', 'icon' => '🗄️', 'metric' => 'cases_created', 'threshold' => 50, 'sort_order' => 4],
    ['key' => 'case_closed', 'name' => 'Case Closed', 'description' => 'Marked your first case Complete.', 'icon' => '✅', 'metric' => 'cases_completed', 'threshold' => 1, 'sort_order' => 5],
    ['key' => 'first_exhibit', 'name' => 'First Exhibit', 'description' => 'Booked in your first exhibit.', 'icon' => '🔍', 'metric' => 'exhibits_booked_in', 'threshold' => 1, 'sort_order' => 6],
    ['key' => 'evidence_handler_25', 'name' => 'Evidence Handler', 'description' => 'Booked in 25 exhibits.', 'icon' => '🧷', 'metric' => 'exhibits_booked_in', 'threshold' => 25, 'sort_order' => 7],
    ['key' => 'evidence_custodian_100', 'name' => 'Evidence Custodian', 'description' => 'Booked in 100 exhibits.', 'icon' => '🗃️', 'metric' => 'exhibits_booked_in', 'threshold' => 100, 'sort_order' => 8],
    ['key' => 'analysis_complete', 'name' => 'Analysis Complete', 'description' => 'Marked your first exhibit Complete.', 'icon' => '🧾', 'metric' => 'exhibits_completed', 'threshold' => 1, 'sort_order' => 9],
    ['key' => 'first_examination', 'name' => 'First Examination', 'description' => 'Filled in your first exhibit examination.', 'icon' => '🧪', 'metric' => 'examinations_completed', 'threshold' => 1, 'sort_order' => 10],
    ['key' => 'thorough_20', 'name' => 'Thorough', 'description' => 'Completed 20 exhibit examinations.', 'icon' => '🔬', 'metric' => 'examinations_completed', 'threshold' => 20, 'sort_order' => 11],
    ['key' => 'task_taker', 'name' => 'Task Taker', 'description' => 'Completed your first task.', 'icon' => '📋', 'metric' => 'tasks_completed', 'threshold' => 1, 'sort_order' => 12],
    ['key' => 'task_crusher_25', 'name' => 'Task Crusher', 'description' => 'Completed 25 tasks.', 'icon' => '💪', 'metric' => 'tasks_completed', 'threshold' => 25, 'sort_order' => 13],
    ['key' => 'first_upload', 'name' => 'First Upload', 'description' => 'Uploaded your first document or photo.', 'icon' => '📎', 'metric' => 'uploads_count', 'threshold' => 1, 'sort_order' => 14],
    ['key' => 'well_documented_50', 'name' => 'Well Documented', 'description' => 'Uploaded 50 documents or photos.', 'icon' => '🗂️', 'metric' => 'uploads_count', 'threshold' => 50, 'sort_order' => 15],
    ['key' => 'one_year_on', 'name' => 'One Year On', 'description' => 'Account active for 365 days.', 'icon' => '🎉', 'metric' => 'tenure_days', 'threshold' => 365, 'sort_order' => 16],
];

// Syncs the achievements table from ACHIEVEMENT_DEFINITIONS; no-op once caught up.
function sync_achievement_catalog(mysqli $conn): void
{
    $expected = count(ACHIEVEMENT_DEFINITIONS);
    $result = @$conn->query("SELECT COUNT(*) AS c FROM achievements");
    if (!$result) {
        return; // table doesn't exist yet
    }
    $row = $result->fetch_assoc();
    if ((int) $row['c'] === $expected) {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO achievements (achievement_key, name, description, icon, metric, threshold, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description),
            icon = VALUES(icon), metric = VALUES(metric), threshold = VALUES(threshold), sort_order = VALUES(sort_order)
    ");
    if (!$stmt) {
        return;
    }
    foreach (ACHIEVEMENT_DEFINITIONS as $def) {
        $stmt->bind_param("ssssssi", $def['key'], $def['name'], $def['description'], $def['icon'], $def['metric'], $def['threshold'], $def['sort_order']);
        $stmt->execute();
    }
    $stmt->close();
}

// Fresh query against the real data for a given metric - never cached/passed-in.
function compute_metric(mysqli $conn, string $metric, int $userId): int
{
    switch ($metric) {
        case 'first_login':
            return 1;

        case 'cases_created':
            // jobs.created_by is stored as a varchar.
            $stmt = $conn->prepare("SELECT COUNT(*) FROM jobs WHERE created_by = ?");
            $userIdStr = (string) $userId;
            $stmt->bind_param("s", $userIdStr);
            break;

        case 'cases_completed':
            $stmt = $conn->prepare("
                SELECT COUNT(DISTINCT ch.job_id) FROM case_history ch
                JOIN jobs j ON j.job_id = ch.job_id
                JOIN job_status s ON s.status_id = j.status_id
                WHERE ch.changed_by = ? AND s.status_name = 'Complete'
            ");
            $stmt->bind_param("i", $userId);
            break;

        case 'exhibits_booked_in':
            $stmt = $conn->prepare("SELECT COUNT(*) FROM exhibits WHERE created_by = ?");
            $stmt->bind_param("i", $userId);
            break;

        case 'exhibits_completed':
            $stmt = $conn->prepare("
                SELECT COUNT(DISTINCT eh.exhibit_id) FROM exhibit_history eh
                JOIN exhibits e ON e.exhibit_id = eh.exhibit_id
                WHERE eh.changed_by = ? AND e.status = 'Complete'
            ");
            $stmt->bind_param("i", $userId);
            break;

        case 'examinations_completed':
            $stmt = $conn->prepare("SELECT COUNT(*) FROM exhibit_processes WHERE created_by = ?");
            $stmt->bind_param("i", $userId);
            break;

        case 'tasks_completed':
            $stmt = $conn->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status = 'completed'");
            $stmt->bind_param("i", $userId);
            break;

        case 'uploads_count':
            $stmt = $conn->prepare("
                SELECT
                    (SELECT COUNT(*) FROM case_documents WHERE uploaded_by = ?) +
                    (SELECT COUNT(*) FROM exhibit_documents WHERE uploaded_by = ?) +
                    (SELECT COUNT(*) FROM exhibit_photos WHERE uploaded_by = ?)
            ");
            $stmt->bind_param("iii", $userId, $userId, $userId);
            break;

        case 'tenure_days':
            $stmt = $conn->prepare("SELECT DATEDIFF(NOW(), created_at) FROM users WHERE id = ?");
            $stmt->bind_param("i", $userId);
            break;

        default:
            return 0;
    }

    if (!$stmt) {
        return 0;
    }
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return (int) $count;
}

// Call after any write that could move a metric forward.
function check_and_unlock_achievements(mysqli $conn, int $userId, string $metric): array
{
    $stmt = $conn->prepare("
        SELECT a.id, a.name, a.icon, a.threshold FROM achievements a
        LEFT JOIN user_achievements ua ON ua.achievement_id = a.id AND ua.user_id = ?
        WHERE a.metric = ? AND ua.id IS NULL
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param("is", $userId, $metric);
    $stmt->execute();
    $result = $stmt->get_result();
    $pending = [];
    while ($row = $result->fetch_assoc()) {
        $pending[] = $row;
    }
    $stmt->close();

    if (empty($pending)) {
        return [];
    }

    $count = compute_metric($conn, $metric, $userId);
    $newlyUnlocked = [];

    foreach ($pending as $p) {
        if ($count >= (int) $p['threshold']) {
            $ins = $conn->prepare("INSERT IGNORE INTO user_achievements (user_id, achievement_id, unlocked_at) VALUES (?, ?, NOW())");
            $ins->bind_param("ii", $userId, $p['id']);
            $ins->execute();
            if ($ins->affected_rows > 0) {
                $newlyUnlocked[] = ['name' => $p['name'], 'icon' => $p['icon']];
            }
            $ins->close();
        }
    }

    return $newlyUnlocked;
}

// Every achievement in catalog order, with this user's unlocked state/date.
function get_achievements_for_user(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare("
        SELECT a.achievement_key, a.name, a.description, a.icon, ua.unlocked_at
        FROM achievements a
        LEFT JOIN user_achievements ua ON ua.achievement_id = a.id AND ua.user_id = ?
        ORDER BY a.sort_order
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}
