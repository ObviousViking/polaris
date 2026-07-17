<?php
// search_examinations.php
//
// Advanced search across exhibit examination data - Process Builder field
// values and free-text notes, not just the fixed exhibit columns.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');

// Strips HTML from rich-text notes down to plain readable text.
function plain_text_preview(?string $html, ?int $maxLen = null): string
{
    if ($html === null || $html === '') {
        return '';
    }
    $withBreaks = preg_replace('/<\/(p|div|li|br)\s*>|<br\s*\/?>/i', ' ', $html);
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags($withBreaks)));
    if ($maxLen !== null && mb_strlen($plain) > $maxLen) {
        $plain = mb_substr($plain, 0, $maxLen) . '...';
    }
    return $plain;
}

// Rebuilds the current query string with only "page" swapped.
function pagination_url(int $targetPage): string
{
    $params = $_GET;
    $params['page'] = $targetPage;
    return 'search_examinations.php?' . http_build_query($params);
}

$embedded = isset($_GET['embedded']);

$roleStmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$roleStmt->bind_param("i", $_SESSION['user_id']);
$roleStmt->execute();
$roleStmt->bind_result($currentUserRole);
$roleStmt->fetch();
$roleStmt->close();
$canSeeDeleted = ($currentUserRole === 'admin' || $currentUserRole === 'super');

$keyword       = isset($_GET['keyword']) ? trim($_GET['keyword']) : "";
$process_type  = isset($_GET['process_type']) ? trim($_GET['process_type']) : "";
$field_label   = isset($_GET['field_label']) ? trim($_GET['field_label']) : "";
$examiner      = isset($_GET['examiner']) ? trim($_GET['examiner']) : "";
$date_from     = isset($_GET['date_from']) ? trim($_GET['date_from']) : "";
$date_to       = isset($_GET['date_to']) ? trim($_GET['date_to']) : "";
$includeDeleted = $canSeeDeleted && isset($_GET['include_deleted']);

// "list_all" ignores keyword/field and just browses everything matching the other filters.
$mode = isset($_GET['list_all']) ? 'list_all' : (isset($_GET['submit']) ? 'search' : 'none');
$hasSearched = $mode !== 'none';

$perPage = 25;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($page < 1) {
    $page = 1;
}
$totalResults = 0;
$totalPages = 1;

// Runs $sql, returns the executed mysqli_stmt, or null (with $message set) on failure.
function run_bound_query(mysqli $conn, string $sql, string $types, array $params, string &$message)
{
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        $message = "Error preparing query: " . $conn->error;
        return null;
    }
    if (!empty($params)) {
        $bind_names = [$types];
        foreach ($params as $key => $value) {
            $bind_names[] = &$params[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind_names);
    }
    $stmt->execute();
    return $stmt;
}

$includeNotes = $mode === 'none' || isset($_GET['include_notes']);

$results = [];
$message = "";

if ($mode === 'list_all') {
    $conditions = [];
    $params = [];
    $types = "";

    if (!empty($process_type)) {
        $conditions[] = "pt.id = ?";
        $types .= "i";
        $params[] = intval($process_type);
    }
    if (!empty($examiner)) {
        $conditions[] = "CONCAT(u.first_name, ' ', u.last_name) LIKE ?";
        $types .= "s";
        $params[] = "%$examiner%";
    }
    if (!empty($date_from)) {
        $conditions[] = "DATE(ep.created_at) >= ?";
        $types .= "s";
        $params[] = $date_from;
    }
    if (!empty($date_to)) {
        $conditions[] = "DATE(ep.created_at) <= ?";
        $types .= "s";
        $params[] = $date_to;
    }
    if (!$includeDeleted) {
        $conditions[] = "e.deleted_at IS NULL";
    }
    $where = empty($conditions) ? "" : (" AND " . implode(" AND ", $conditions));

    $baseSql = "
        SELECT
            ep.id AS exhibit_process_id,
            pt.name AS process_type_name,
            ep.created_at, ep.updated_at,
            ep.free_text AS notes_text,
            e.exhibit_id, e.exhibit_ref, e.item_description, e.status AS exhibit_status,
            et.type_name AS exhibit_type_name,
            j.job_id, j.custom_ref,
            CONCAT(u.first_name, ' ', u.last_name) AS examiner_name
        FROM exhibit_processes ep
        JOIN process_types pt ON pt.id = ep.process_type_id
        JOIN exhibits e ON e.exhibit_id = ep.exhibit_id
        JOIN exhibit_types et ON et.exhibit_type_id = e.exhibit_type_id
        JOIN jobs j ON j.job_id = e.job_id
        LEFT JOIN users u ON u.id = ep.created_by
        WHERE 1" . $where;

    $countStmt = run_bound_query($conn, "SELECT COUNT(*) AS total FROM ($baseSql) AS combined", $types, $params, $message);
    if ($countStmt !== null) {
        $totalResults = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $countStmt->close();
        $totalPages = max(1, (int) ceil($totalResults / $perPage));
        $page = min($page, $totalPages);

        $pageTypes = $types . "ii";
        $pageParams = $params;
        $pageParams[] = $perPage;
        $pageParams[] = ($page - 1) * $perPage;

        $stmt = run_bound_query($conn, $baseSql . " ORDER BY ep.created_at DESC LIMIT ? OFFSET ?", $pageTypes, $pageParams, $message);
        if ($stmt !== null) {
            $resultSet = $stmt->get_result();
            while ($row = $resultSet->fetch_assoc()) {
                $results[] = $row;
            }
            if (empty($results)) {
                $message = "No examinations recorded yet matching your filters.";
            }
            $stmt->close();
        }
    }
} elseif ($mode === 'search') {
    // Shared filter fragments for both the field-value and free-text-notes branches.
    $commonConditions = [];
    $commonParams = [];
    $commonTypes = "";

    if (!empty($process_type)) {
        $commonConditions[] = "pt.id = ?";
        $commonTypes .= "i";
        $commonParams[] = intval($process_type);
    }
    if (!empty($examiner)) {
        $commonConditions[] = "CONCAT(u.first_name, ' ', u.last_name) LIKE ?";
        $commonTypes .= "s";
        $commonParams[] = "%$examiner%";
    }
    if (!empty($date_from)) {
        $commonConditions[] = "DATE(ep.created_at) >= ?";
        $commonTypes .= "s";
        $commonParams[] = $date_from;
    }
    if (!empty($date_to)) {
        $commonConditions[] = "DATE(ep.created_at) <= ?";
        $commonTypes .= "s";
        $commonParams[] = $date_to;
    }
    if (!$includeDeleted) {
        $commonConditions[] = "e.deleted_at IS NULL";
    }

    $commonWhere = empty($commonConditions) ? "" : (" AND " . implode(" AND ", $commonConditions));

    // Branch 1: structured field values. DISTINCT ep.id keeps one examination
    // to one result row even if several fields match the keyword.
    $fieldConditions = [];
    $fieldParams = [];
    $fieldTypes = "";
    if (!empty($keyword)) {
        $fieldConditions[] = "epv.value LIKE ?";
        $fieldTypes .= "s";
        $fieldParams[] = "%$keyword%";
    }
    if (!empty($field_label)) {
        $fieldConditions[] = "pf.field_label LIKE ?";
        $fieldTypes .= "s";
        $fieldParams[] = "%$field_label%";
    }
    $fieldWhere = empty($fieldConditions) ? "" : (" AND " . implode(" AND ", $fieldConditions));

    $sql = "
        SELECT DISTINCT ep.id AS exhibit_process_id, ep.created_at
        FROM exhibit_process_values epv
        JOIN process_fields pf ON pf.id = epv.process_field_id
        JOIN exhibit_processes ep ON ep.id = epv.exhibit_process_id
        JOIN process_types pt ON pt.id = ep.process_type_id
        JOIN exhibits e ON e.exhibit_id = ep.exhibit_id
        JOIN jobs j ON j.job_id = e.job_id
        LEFT JOIN users u ON u.id = ep.created_by
        WHERE epv.value IS NOT NULL AND epv.value != ''" . $fieldWhere . $commonWhere;

    $allParams = array_merge($fieldParams, $commonParams);
    $allTypes = $fieldTypes . $commonTypes;

    // Branch 2: free-text notes. Plain UNION so a match in both branches
    // still collapses to a single id.
    if ($includeNotes) {
        $notesConditions = [];
        $notesParams = [];
        $notesTypes = "";
        if (!empty($keyword)) {
            $notesConditions[] = "ep.free_text LIKE ?";
            $notesTypes .= "s";
            $notesParams[] = "%$keyword%";
        }
        $notesWhere = empty($notesConditions) ? "" : (" AND " . implode(" AND ", $notesConditions));

        $sql .= "
        UNION
        SELECT DISTINCT ep.id AS exhibit_process_id, ep.created_at
        FROM exhibit_processes ep
        JOIN process_types pt ON pt.id = ep.process_type_id
        JOIN exhibits e ON e.exhibit_id = ep.exhibit_id
        JOIN jobs j ON j.job_id = e.job_id
        LEFT JOIN users u ON u.id = ep.created_by
        WHERE ep.free_text IS NOT NULL AND ep.free_text != ''" . $notesWhere . $commonWhere;

        $allParams = array_merge($allParams, $notesParams, $commonParams);
        $allTypes .= $notesTypes . $commonTypes;
    }

    $baseSql = $sql;

    $countStmt = run_bound_query($conn, "SELECT COUNT(*) AS total FROM ($baseSql) AS combined", $allTypes, $allParams, $message);
    $matchedIds = [];
    if ($countStmt !== null) {
        $totalResults = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $countStmt->close();
        $totalPages = max(1, (int) ceil($totalResults / $perPage));
        $page = min($page, $totalPages);

        $pageTypes = $allTypes . "ii";
        $pageParams = $allParams;
        $pageParams[] = $perPage;
        $pageParams[] = ($page - 1) * $perPage;

        $idStmt = run_bound_query($conn, $baseSql . " ORDER BY created_at DESC LIMIT ? OFFSET ?", $pageTypes, $pageParams, $message);
        if ($idStmt !== null) {
            $idResult = $idStmt->get_result();
            while ($row = $idResult->fetch_assoc()) {
                $matchedIds[] = (int) $row['exhibit_process_id'];
            }
            $idStmt->close();
        }
    }

    if (!empty($matchedIds)) {
        $idPlaceholders = implode(',', array_fill(0, count($matchedIds), '?'));
        $detailSql = "
            SELECT
                ep.id AS exhibit_process_id,
                pt.name AS process_type_name,
                ep.created_at,
                ep.free_text AS notes_text,
                e.exhibit_id, e.exhibit_ref, e.item_description, e.status AS exhibit_status,
                et.type_name AS exhibit_type_name,
                j.job_id, j.custom_ref,
                CONCAT(u.first_name, ' ', u.last_name) AS examiner_name
            FROM exhibit_processes ep
            JOIN process_types pt ON pt.id = ep.process_type_id
            JOIN exhibits e ON e.exhibit_id = ep.exhibit_id
            JOIN exhibit_types et ON et.exhibit_type_id = e.exhibit_type_id
            JOIN jobs j ON j.job_id = e.job_id
            LEFT JOIN users u ON u.id = ep.created_by
            WHERE ep.id IN ($idPlaceholders)";
        $detailStmt = run_bound_query($conn, $detailSql, str_repeat('i', count($matchedIds)), $matchedIds, $message);

        $detailsById = [];
        if ($detailStmt !== null) {
            $detailResult = $detailStmt->get_result();
            while ($row = $detailResult->fetch_assoc()) {
                $detailsById[(int) $row['exhibit_process_id']] = $row;
            }
            $detailStmt->close();
        }

        // Which specific field(s) matched, per examination.
        $matchedFieldsById = [];
        if (!empty($fieldConditions)) {
            $mfSql = "
                SELECT epv.exhibit_process_id, pf.field_label, epv.value
                FROM exhibit_process_values epv
                JOIN process_fields pf ON pf.id = epv.process_field_id
                WHERE epv.exhibit_process_id IN ($idPlaceholders) AND epv.value IS NOT NULL AND epv.value != ''" . $fieldWhere;
            $mfTypes = str_repeat('i', count($matchedIds)) . $fieldTypes;
            $mfParams = array_merge($matchedIds, $fieldParams);
            $mfStmt = run_bound_query($conn, $mfSql, $mfTypes, $mfParams, $message);
            if ($mfStmt !== null) {
                $mfResult = $mfStmt->get_result();
                while ($row = $mfResult->fetch_assoc()) {
                    $matchedFieldsById[(int) $row['exhibit_process_id']][] = ['label' => $row['field_label'], 'value' => $row['value']];
                }
                $mfStmt->close();
            }
        }

        foreach ($matchedIds as $id) {
            if (!isset($detailsById[$id])) {
                continue;
            }
            $row = $detailsById[$id];
            $row['matched_fields'] = $matchedFieldsById[$id] ?? [];
            // Display only - whether to show a "Free-text notes" chip on this row.
            $row['notes_matched'] = $includeNotes && !empty($keyword)
                && stripos(plain_text_preview($row['notes_text']), $keyword) !== false;
            $results[] = $row;
        }
    }

    if (empty($results)) {
        $message = "No examination data found matching your search criteria.";
    }
}

// Lookup dropdowns.
$processTypes = [];
$result = $conn->query("SELECT id, name FROM process_types ORDER BY name");
while ($row = $result->fetch_assoc()) {
    $processTypes[] = $row;
}
$result->free();

$fieldLabelOptions = [];
$result = $conn->query("SELECT DISTINCT field_label FROM process_fields ORDER BY field_label");
while ($row = $result->fetch_assoc()) {
    $fieldLabelOptions[] = $row['field_label'];
}
$result->free();

if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    include('../header.php');
}
?>
<style>
    body {
        margin: 0;
        font-family: Arial, sans-serif;
        background: var(--polaris-bg);
        color: var(--polaris-text);
        padding-top: <?php echo $embedded ? '0' : '120px'; ?>;
    }

    .flex-container {
        display: flex;
        width: 100%;
        margin: 0;
        padding: 20px;
        gap: 20px;
        box-sizing: border-box;
        align-items: flex-start;
    }

    .sidebar {
        flex: 0 0 280px;
        background: var(--polaris-surface);
        padding: 15px;
        border-radius: 5px;
    }

    .sidebar h2 {
        font-size: 18px;
        margin-top: 0;
        margin-bottom: 15px;
    }

    .sidebar .field {
        margin-bottom: 10px;
    }

    .sidebar label {
        display: block;
        margin-bottom: 3px;
        font-weight: bold;
        color: var(--polaris-text-secondary);
        font-size: 13px;
    }

    .sidebar input[type="text"],
    .sidebar input[type="date"],
    .sidebar select {
        width: 100%;
        padding: 6px;
        border: 1px solid var(--polaris-border);
        border-radius: 3px;
        background: var(--polaris-bg);
        color: var(--polaris-text);
        font-size: 13px;
        box-sizing: border-box;
    }

    .sidebar input[type="submit"] {
        width: 100%;
        padding: 5px 10px;
        margin-top: 10px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border: none;
        border-radius: 3px;
        cursor: pointer;
        font-size: 14px;
    }

    .sidebar input[type="submit"]:hover {
        background: var(--polaris-accent-hover);
    }

    .reset-btn,
    .list-all-btn {
        width: 100%;
        padding: 5px 10px;
        margin-top: 8px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border: none;
        border-radius: 3px;
        cursor: pointer;
        font-size: 14px;
    }

    .reset-btn:hover,
    .list-all-btn:hover {
        background: var(--polaris-accent-hover);
    }

    .results {
        flex: 1;
        min-width: 0;
    }

    .results h2 {
        font-size: 18px;
        margin-top: 0;
    }

    .table-scroll {
        width: 100%;
        overflow-x: auto;
        border-radius: 5px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        background: var(--polaris-surface);
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

    .results table a {
        color: var(--polaris-accent);
        text-decoration: none;
    }

    .results table a:hover {
        text-decoration: underline;
    }

    .match-value,
    .notes-value {
        word-break: break-word;
        max-width: 280px;
    }

    .notes-value {
        color: var(--polaris-text-secondary);
    }

    mark {
        background: var(--polaris-warning);
        color: #1a1a1a;
        padding: 0 2px;
        border-radius: 2px;
    }

    .badge {
        display: inline-block;
        background: var(--polaris-divider);
        color: var(--polaris-text-secondary);
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        white-space: nowrap;
    }

    .results-pager {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        margin-top: 15px;
        font-size: 13px;
        color: var(--polaris-text-muted);
    }

    .results-pager a {
        padding: 4px 12px;
        border-radius: 3px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        text-decoration: none;
    }

    .results-pager a:hover {
        background: var(--polaris-accent-hover);
    }

    .results-pager a.disabled {
        background: var(--polaris-divider);
        color: var(--polaris-text-faint-2);
        pointer-events: none;
    }
</style>

<div class="flex-container">
    <!-- Left Panel: Search Filters -->
    <div class="sidebar">
        <h2>Advanced Examination Search</h2>
        <form method="get" action="search_examinations.php">
            <?php if ($embedded): ?>
            <input type="hidden" name="embedded" value="1">
            <?php endif; ?>

            <div class="field">
                <label for="keyword">Keyword:</label>
                <input type="text" id="keyword" name="keyword" placeholder="e.g. IMEI, device model, note text..."
                    value="<?php echo htmlspecialchars($keyword); ?>">
            </div>

            <div class="field">
                <label for="process_type">Process Type:</label>
                <select id="process_type" name="process_type">
                    <option value="">Any</option>
                    <?php foreach ($processTypes as $pt): ?>
                    <option value="<?php echo $pt['id']; ?>" <?php if ($process_type == $pt['id']) echo "selected"; ?>>
                        <?php echo htmlspecialchars($pt['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="field_label">Field Label Contains:</label>
                <input type="text" id="field_label" name="field_label" list="field_label_options"
                    placeholder="e.g. tool, IMEI, serial..."
                    value="<?php echo htmlspecialchars($field_label); ?>">
                <datalist id="field_label_options">
                    <?php foreach ($fieldLabelOptions as $label): ?>
                    <option value="<?php echo htmlspecialchars($label); ?>">
                    <?php endforeach; ?>
                </datalist>
                <small style="color:var(--polaris-text-muted); font-size:11px;">Matches by label text across every
                    process type - e.g. "tool" finds Acquisition Tool, Recovery Tool, Imaging Tool, etc. all at
                    once. Leave blank to search all fields.</small>
            </div>

            <div class="field">
                <label style="display:inline-flex; align-items:center; gap:6px;">
                    <input type="checkbox" id="include_notes" name="include_notes" value="1"
                        style="width:auto;" <?php if ($includeNotes) echo "checked"; ?>>
                    Include free-text notes
                </label>
            </div>

            <div class="field">
                <label for="examiner">Examiner:</label>
                <input type="text" id="examiner" name="examiner" value="<?php echo htmlspecialchars($examiner); ?>">
            </div>

            <div class="field">
                <label for="date_from">Examined From:</label>
                <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>

            <div class="field">
                <label for="date_to">Examined To:</label>
                <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>

            <?php if ($canSeeDeleted): ?>
            <div class="field">
                <label style="display:inline-flex; align-items:center; gap:6px;">
                    <input type="checkbox" id="include_deleted" name="include_deleted" value="1"
                        style="width:auto;" <?php if ($includeDeleted) echo "checked"; ?>>
                    Include deleted exhibits
                </label>
            </div>
            <?php endif; ?>

            <input type="submit" name="submit" value="Search">
            <input type="submit" name="list_all" value="List All Examinations" class="list-all-btn"
                title="Ignores Keyword and Specific Field - lists every examination matching the other filters">
            <button type="button" class="reset-btn"
                onclick="window.location.href='search_examinations.php<?php echo $embedded ? '?embedded=1' : ''; ?>';">Reset</button>
        </form>
    </div>

    <!-- Right Panel: Search Results -->
    <div class="results">
        <h2>
            <?php echo $mode === 'list_all' ? 'All Examinations' : 'Search Results'; ?>
            <?php echo $hasSearched && $totalResults > 0 ? ' (' . $totalResults . ')' : ''; ?>
        </h2>
        <?php if (!$hasSearched): ?>
        <p>Enter your search criteria and click Search, or click List All Examinations to browse everything.</p>
        <?php elseif (empty($results)): ?>
        <p><?php echo htmlspecialchars($message); ?></p>
        <?php elseif ($mode === 'list_all'): ?>
        <div class="table-scroll">
        <table>
            <tr>
                <th>Case Ref</th>
                <th>Exhibit Ref</th>
                <th>Item Description</th>
                <th>Exhibit Type</th>
                <th>Status</th>
                <th>Process Type</th>
                <th>Notes</th>
                <th>Examiner</th>
                <th>Created</th>
                <th>Last Updated</th>
            </tr>
            <?php foreach ($results as $r): ?>
            <tr>
                <td><a href="job.php?job_id=<?php echo $r['job_id']; ?>" target="_top">
                        <?php echo htmlspecialchars($r['custom_ref']); ?></a></td>
                <td><a href="/captains_log/examination.php?exhibit_id=<?php echo $r['exhibit_id']; ?>" target="_top">
                        <?php echo htmlspecialchars($r['exhibit_ref']); ?></a></td>
                <td><?php echo htmlspecialchars($r['item_description']); ?></td>
                <td><?php echo htmlspecialchars($r['exhibit_type_name']); ?></td>
                <td><?php echo htmlspecialchars($r['exhibit_status']); ?></td>
                <td><span class="badge"><?php echo htmlspecialchars($r['process_type_name']); ?></span></td>
                <td class="notes-value" title="<?php echo htmlspecialchars(plain_text_preview($r['notes_text'])); ?>">
                    <?php echo htmlspecialchars(plain_text_preview($r['notes_text'], 150)) ?: '-'; ?>
                </td>
                <td><?php echo htmlspecialchars($r['examiner_name'] ?: 'Unknown'); ?></td>
                <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                <td><?php echo htmlspecialchars($r['updated_at'] ?: '-'); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php else: ?>
        <div class="table-scroll">
        <table>
            <tr>
                <th>Case Ref</th>
                <th>Exhibit Ref</th>
                <th>Item Description</th>
                <th>Exhibit Type</th>
                <th>Process Type</th>
                <th>Matched On</th>
                <th>Examiner</th>
                <th>Date</th>
            </tr>
            <?php foreach ($results as $r): ?>
            <tr>
                <td><a href="job.php?job_id=<?php echo $r['job_id']; ?>" target="_top">
                        <?php echo htmlspecialchars($r['custom_ref']); ?></a></td>
                <td><a href="/captains_log/examination.php?exhibit_id=<?php echo $r['exhibit_id']; ?>" target="_top">
                        <?php echo htmlspecialchars($r['exhibit_ref']); ?></a></td>
                <td><?php echo htmlspecialchars($r['item_description']); ?></td>
                <td><?php echo htmlspecialchars($r['exhibit_type_name']); ?></td>
                <td><span class="badge"><?php echo htmlspecialchars($r['process_type_name']); ?></span></td>
                <td class="match-value">
                    <?php
                        $chips = [];
                        foreach ($r['matched_fields'] as $match) {
                            $chip = htmlspecialchars($match['label']) . ': ' . htmlspecialchars(plain_text_preview($match['value'], 80));
                            if (!empty($keyword)) {
                                $chip = preg_replace('/(' . preg_quote($keyword, '/') . ')/i', '<mark>$1</mark>', $chip);
                            }
                            $chips[] = $chip;
                        }
                        if ($r['notes_matched']) {
                            $notesChip = 'Free-text notes: ' . htmlspecialchars(plain_text_preview($r['notes_text'], 80));
                            if (!empty($keyword)) {
                                $notesChip = preg_replace('/(' . preg_quote($keyword, '/') . ')/i', '<mark>$1</mark>', $notesChip);
                            }
                            $chips[] = $notesChip;
                        }
                        echo $chips ? implode('<br>', $chips) : '-';
                    ?>
                </td>
                <td><?php echo htmlspecialchars($r['examiner_name'] ?: 'Unknown'); ?></td>
                <td><?php echo htmlspecialchars($r['created_at']); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>

        <?php if ($hasSearched && $totalPages > 1): ?>
        <div class="results-pager">
            <a href="<?php echo htmlspecialchars(pagination_url($page - 1)); ?>"
                class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">&laquo; Prev</a>
            <span>Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
            <a href="<?php echo htmlspecialchars(pagination_url($page + 1)); ?>"
                class="<?php echo $page >= $totalPages ? 'disabled' : ''; ?>">Next &raquo;</a>
        </div>
        <?php endif; ?>
    </div>
</div>
