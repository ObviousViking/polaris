<?php
// includes/achievements.php
//
// Achievement catalog + unlock logic for the User Profile achievements panel.
// check_and_unlock_achievements() always recomputes the metric from source
// tables rather than trusting a passed-in count, so it can't be tricked into
// unlocking something the user hasn't actually earned.

// Count-based metrics all follow the same four-tier ladder (1/10/100/1000)
// so progress reads consistently across every achievement family - see
// get_achievement_groups_for_user() for how tiers within a metric are
// grouped and rendered with a progress bar toward the next one.
// Tier thresholds are changed in place (same key, new threshold) rather
// than renamed, so sync_achievement_catalog()'s UPDATE keeps existing
// unlocks intact instead of orphaning old rows - see that function.
const ACHIEVEMENT_DEFINITIONS = [
    ['key' => 'first_login', 'name' => 'First Login', 'description' => 'Logged in for the first time.', 'icon' => '🔑', 'metric' => 'first_login', 'threshold' => 1, 'sort_order' => 1],

    ['key' => 'first_case', 'name' => 'First Case', 'description' => 'Created your first case.', 'icon' => '📁', 'metric' => 'cases_created', 'threshold' => 1, 'sort_order' => 2],
    ['key' => 'case_files_10', 'name' => 'Case Files', 'description' => 'Created 10 cases.', 'icon' => '📂', 'metric' => 'cases_created', 'threshold' => 10, 'sort_order' => 3],
    ['key' => 'caseload_veteran_50', 'name' => 'Caseload Veteran', 'description' => 'Created 100 cases.', 'icon' => '🗄️', 'metric' => 'cases_created', 'threshold' => 100, 'sort_order' => 4],
    ['key' => 'case_legend_1000', 'name' => 'Case Legend', 'description' => 'Created 1000 cases.', 'icon' => '🏛️', 'metric' => 'cases_created', 'threshold' => 1000, 'sort_order' => 5],

    ['key' => 'case_closed', 'name' => 'Case Closed', 'description' => 'Marked your first case Complete.', 'icon' => '✅', 'metric' => 'cases_completed', 'threshold' => 1, 'sort_order' => 6],
    ['key' => 'case_closer_10', 'name' => 'Case Closer', 'description' => 'Completed 10 cases.', 'icon' => '📌', 'metric' => 'cases_completed', 'threshold' => 10, 'sort_order' => 7],
    ['key' => 'resolution_expert_100', 'name' => 'Resolution Expert', 'description' => 'Completed 100 cases.', 'icon' => '🎯', 'metric' => 'cases_completed', 'threshold' => 100, 'sort_order' => 8],
    ['key' => 'resolution_legend_1000', 'name' => 'Resolution Legend', 'description' => 'Completed 1000 cases.', 'icon' => '🏆', 'metric' => 'cases_completed', 'threshold' => 1000, 'sort_order' => 9],

    ['key' => 'first_exhibit', 'name' => 'First Exhibit', 'description' => 'Booked in your first exhibit.', 'icon' => '🔍', 'metric' => 'exhibits_booked_in', 'threshold' => 1, 'sort_order' => 10],
    ['key' => 'evidence_handler_25', 'name' => 'Evidence Handler', 'description' => 'Booked in 10 exhibits.', 'icon' => '🧷', 'metric' => 'exhibits_booked_in', 'threshold' => 10, 'sort_order' => 11],
    ['key' => 'evidence_custodian_100', 'name' => 'Evidence Custodian', 'description' => 'Booked in 100 exhibits.', 'icon' => '🗃️', 'metric' => 'exhibits_booked_in', 'threshold' => 100, 'sort_order' => 12],
    ['key' => 'custody_master_1000', 'name' => 'Chain of Custody Master', 'description' => 'Booked in 1000 exhibits.', 'icon' => '⛓️', 'metric' => 'exhibits_booked_in', 'threshold' => 1000, 'sort_order' => 13],

    ['key' => 'analysis_complete', 'name' => 'Analysis Complete', 'description' => 'Marked your first exhibit Complete.', 'icon' => '🧾', 'metric' => 'exhibits_completed', 'threshold' => 1, 'sort_order' => 14],
    ['key' => 'analyst_10', 'name' => 'Analyst', 'description' => 'Completed 10 exhibits.', 'icon' => '📊', 'metric' => 'exhibits_completed', 'threshold' => 10, 'sort_order' => 15],
    ['key' => 'senior_analyst_100', 'name' => 'Senior Analyst', 'description' => 'Completed 100 exhibits.', 'icon' => '🧠', 'metric' => 'exhibits_completed', 'threshold' => 100, 'sort_order' => 16],
    ['key' => 'analysis_legend_1000', 'name' => 'Analysis Legend', 'description' => 'Completed 1000 exhibits.', 'icon' => '🌟', 'metric' => 'exhibits_completed', 'threshold' => 1000, 'sort_order' => 17],

    ['key' => 'first_examination', 'name' => 'First Examination', 'description' => 'Filled in your first exhibit examination.', 'icon' => '🧪', 'metric' => 'examinations_completed', 'threshold' => 1, 'sort_order' => 18],
    ['key' => 'thorough_20', 'name' => 'Examiner', 'description' => 'Completed 10 exhibit examinations.', 'icon' => '🔬', 'metric' => 'examinations_completed', 'threshold' => 10, 'sort_order' => 19],
    ['key' => 'senior_examiner_100', 'name' => 'Senior Examiner', 'description' => 'Completed 100 exhibit examinations.', 'icon' => '🧬', 'metric' => 'examinations_completed', 'threshold' => 100, 'sort_order' => 20],
    ['key' => 'examination_legend_1000', 'name' => 'Examination Legend', 'description' => 'Completed 1000 exhibit examinations.', 'icon' => '🏅', 'metric' => 'examinations_completed', 'threshold' => 1000, 'sort_order' => 21],

    ['key' => 'task_taker', 'name' => 'Task Taker', 'description' => 'Completed your first task.', 'icon' => '📋', 'metric' => 'tasks_completed', 'threshold' => 1, 'sort_order' => 22],
    ['key' => 'task_crusher_25', 'name' => 'Task Crusher', 'description' => 'Completed 10 tasks.', 'icon' => '💪', 'metric' => 'tasks_completed', 'threshold' => 10, 'sort_order' => 23],
    ['key' => 'task_master_100', 'name' => 'Task Master', 'description' => 'Completed 100 tasks.', 'icon' => '⚡', 'metric' => 'tasks_completed', 'threshold' => 100, 'sort_order' => 24],
    ['key' => 'task_legend_1000', 'name' => 'Task Legend', 'description' => 'Completed 1000 tasks.', 'icon' => '👑', 'metric' => 'tasks_completed', 'threshold' => 1000, 'sort_order' => 25],

    ['key' => 'first_upload', 'name' => 'First Upload', 'description' => 'Uploaded your first document or photo.', 'icon' => '📎', 'metric' => 'uploads_count', 'threshold' => 1, 'sort_order' => 26],
    ['key' => 'well_documented_50', 'name' => 'Well Documented', 'description' => 'Uploaded 10 documents or photos.', 'icon' => '🗂️', 'metric' => 'uploads_count', 'threshold' => 10, 'sort_order' => 27],
    ['key' => 'archivist_100', 'name' => 'Archivist', 'description' => 'Uploaded 100 documents or photos.', 'icon' => '📚', 'metric' => 'uploads_count', 'threshold' => 100, 'sort_order' => 28],
    ['key' => 'documentation_legend_1000', 'name' => 'Documentation Legend', 'description' => 'Uploaded 1000 documents or photos.', 'icon' => '📜', 'metric' => 'uploads_count', 'threshold' => 1000, 'sort_order' => 29],

    ['key' => 'one_year_on', 'name' => 'One Year On', 'description' => 'Account active for 365 days.', 'icon' => '🎉', 'metric' => 'tenure_days', 'threshold' => 365, 'sort_order' => 30],
];

// Friendly group label for each multi-tier metric, used by
// get_achievement_groups_for_user() - metrics not listed here (first_login,
// tenure_days) have only one tier and render as standalone cards instead.
const ACHIEVEMENT_METRIC_LABELS = [
    'cases_created' => 'Cases Created',
    'cases_completed' => 'Cases Completed',
    'exhibits_booked_in' => 'Exhibits Booked In',
    'exhibits_completed' => 'Exhibits Completed',
    'examinations_completed' => 'Examinations Completed',
    'tasks_completed' => 'Tasks Completed',
    'uploads_count' => 'Documents Uploaded',
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
        SELECT a.achievement_key, a.name, a.description, a.icon, a.metric, a.threshold, ua.unlocked_at
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

// Multi-tier metrics (see ACHIEVEMENT_METRIC_LABELS) grouped into one card
// per metric - current tier, next tier, and live progress toward it.
// Single-tier achievements (first_login, tenure_days) come back under
// 'standalone' for a plain badge display instead.
function get_achievement_groups_for_user(mysqli $conn, int $userId): array
{
    $rows = get_achievements_for_user($conn, $userId);

    $byMetric = [];
    foreach ($rows as $row) {
        $byMetric[$row['metric']][] = $row;
    }

    $groups = [];
    $standalone = [];
    foreach ($byMetric as $metric => $tiers) {
        if (!isset(ACHIEVEMENT_METRIC_LABELS[$metric]) || count($tiers) < 2) {
            $standalone = array_merge($standalone, $tiers);
            continue;
        }

        usort($tiers, fn($a, $b) => (int) $a['threshold'] <=> (int) $b['threshold']);

        $currentTier = null;
        $nextTier = null;
        foreach ($tiers as $t) {
            if ($t['unlocked_at'] !== null) {
                $currentTier = $t;
            } elseif ($nextTier === null) {
                $nextTier = $t;
            }
        }

        $progress = null;
        if ($nextTier !== null) {
            $progress = ['count' => compute_metric($conn, $metric, $userId), 'threshold' => (int) $nextTier['threshold']];
        }

        $groups[] = [
            'metric' => $metric,
            'label' => ACHIEVEMENT_METRIC_LABELS[$metric],
            'tiers' => $tiers,
            'current_tier' => $currentTier,
            'next_tier' => $nextTier,
            'progress' => $progress,
        ];
    }

    // $byMetric's key order already follows $rows (sort_order), so $groups
    // needs no separate sort.
    return ['groups' => $groups, 'standalone' => $standalone];
}
