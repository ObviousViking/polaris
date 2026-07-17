<?php

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');

$embedded = isset($_GET['embedded']);

$roleStmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$roleStmt->bind_param("i", $_SESSION['user_id']);
$roleStmt->execute();
$roleStmt->bind_result($currentUserRole);
$roleStmt->fetch();
$roleStmt->close();
$canSeeDeleted = ($currentUserRole === 'admin' || $currentUserRole === 'super');

// Initialize search variables
$exhibit_ref   = isset($_GET['exhibit_ref']) ? trim($_GET['exhibit_ref']) : "";
$exhibit_type  = isset($_GET['exhibit_type']) ? trim($_GET['exhibit_type']) : "";
$urgency       = isset($_GET['urgency']) ? trim($_GET['urgency']) : "";
$delivered_by  = isset($_GET['delivered_by']) ? trim($_GET['delivered_by']) : "";
$status        = isset($_GET['status']) ? trim($_GET['status']) : "";
$location      = isset($_GET['location']) ? trim($_GET['location']) : "";
$keyword       = isset($_GET['keyword']) ? trim($_GET['keyword']) : "";
$bag_number    = isset($_GET['bag_number']) ? trim($_GET['bag_number']) : "";
$summary       = isset($_GET['summary']) ? trim($_GET['summary']) : "";
$allocated_to  = isset($_GET['allocated_to']) ? trim($_GET['allocated_to']) : "";
$includeSubExhibits = isset($_GET['include_sub_exhibits']);
$includeDeleted = $canSeeDeleted && isset($_GET['include_deleted']);

$conditions = [];
$typesStr   = "";
$params     = [];

// Build search conditions
if (!empty($exhibit_ref)) {
    $conditions[] = "e.exhibit_ref LIKE ?";
    $typesStr .= "s";
    $params[] = "%$exhibit_ref%";
}
if (!empty($exhibit_type)) {
    $conditions[] = "e.exhibit_type_id = ?";
    $typesStr .= "i";
    $params[] = intval($exhibit_type);
}
if (!empty($urgency)) {
    $conditions[] = "e.urgency = ?";
    $typesStr .= "s";
    $params[] = $urgency;
}
if (!empty($delivered_by)) {
    $conditions[] = "e.delivered_by LIKE ?";
    $typesStr .= "s";
    $params[] = "%$delivered_by%";
}
if (!empty($status)) {
    $conditions[] = "e.status = ?";
    $typesStr .= "s";
    $params[] = $status;
}
if (!empty($location)) {
    $conditions[] = "e.location_id = ?";
    $typesStr .= "i";
    $params[] = intval($location);
}
if (!empty($keyword)) {
    $conditions[] = "e.item_description LIKE ?";
    $typesStr .= "s";
    $params[] = "%$keyword%";
}
if (!empty($bag_number)) {
    $conditions[] = "e.bag_number LIKE ?";
    $typesStr .= "s";
    $params[] = "%$bag_number%";
}
if (!empty($summary)) {
    $conditions[] = "e.summary_of_findings LIKE ?";
    $typesStr .= "s";
    $params[] = "%$summary%";
}
if (!empty($allocated_to)) {
    $conditions[] = "CONCAT(u.first_name, ' ', u.last_name) LIKE ?";
    $typesStr .= "s";
    $params[] = "%$allocated_to%";
}
if (!$includeSubExhibits) {
    // Sub-exhibits (parent_id set) are hidden by default regardless of
    // other search criteria - a checkbox opts back into seeing them.
    $conditions[] = "e.parent_id IS NULL";
}
if (!$includeDeleted) {
    // Same pattern for soft-deleted exhibits - hidden by default, admins
    // can opt back in to see them (e.g. to restore one).
    $conditions[] = "e.deleted_at IS NULL";
}

// Only searched at all if the user actually submitted the form - otherwise
// this page would load every exhibit in the system on every visit, which
// gets slow as the exhibit list grows.
$hasSearched = isset($_GET['submit']);

$exhibits = [];
if ($hasSearched) {
    // Query with joins
    $sql = "SELECT e.exhibit_id, e.exhibit_ref, e.bag_number, e.time_in, e.time_out, e.delivered_by, e.urgency, e.status, e.item_description, e.summary_of_findings,
                   e.parent_id, parent_ex.exhibit_ref AS parent_exhibit_ref,
                   et.type_name AS exhibit_type_name,
                   el.location_name,
                   j.custom_ref, j.job_id,
                   CONCAT(u.first_name, ' ', u.last_name) AS allocated_to_name
            FROM exhibits e
            LEFT JOIN exhibit_types et ON e.exhibit_type_id = et.exhibit_type_id
            LEFT JOIN exhibit_locations el ON e.location_id = el.location_id
            LEFT JOIN users u ON e.allocated_to = u.id
            LEFT JOIN jobs j ON e.job_id = j.job_id
            LEFT JOIN exhibits parent_ex ON e.parent_id = parent_ex.exhibit_id
            WHERE 1";

    if (!empty($conditions)) {
        $sql .= " AND " . implode(" AND ", $conditions);
    }
    $sql .= " ORDER BY e.time_in DESC";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        $message = "Error preparing query: " . $conn->error;
    } else {
        if (!empty($params)) {
            $bind_names = [];
            $bind_names[] = $typesStr;
            foreach ($params as $key => $value) {
                $bind_names[] = &$params[$key];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind_names);
        }
        $stmt->execute();
        $resultSet = $stmt->get_result();
        while ($row = $resultSet->fetch_assoc()) {
            $exhibits[] = $row;
        }
        if (empty($exhibits)) {
            $message = "No exhibits found matching your search criteria.";
        }
        $stmt->close();
    }
}

// Lookup dropdowns
$exhibitTypes = [];
$result = $conn->query("SELECT exhibit_type_id, type_name FROM exhibit_types ORDER BY type_name");
while ($row = $result->fetch_assoc()) {
    $exhibitTypes[] = $row;
}
$result->free();

$locations = [];
$result = $conn->query("SELECT location_id, location_name FROM exhibit_locations ORDER BY location_name");
while ($row = $result->fetch_assoc()) {
    $locations[] = $row;
}
$result->free();

$statusOptions = ["Not Yet Started", "Imaging", "Imaged", "Being Analysed", "On Hold", "Complete"];

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
    .sidebar select {
        width: 100%;
        padding: 6px;
        border: 1px solid var(--polaris-border);
        border-radius: 3px;
        background: var(--polaris-bg);
        color: var(--polaris-text);
        font-size: 13px;
    }

    .sidebar input[type="submit"],
    .sidebar button.reset-btn {
        background: var(--polaris-accent);
        color: var(--polaris-text);
        padding: 5px 10px;
        border: none;
        border-radius: 3px;
        cursor: pointer;
        width: 100%;
        font-size: 14px;
        margin-top: 10px;
    }

    .sidebar input[type="submit"]:hover,
    .sidebar button.reset-btn:hover {
        background: var(--polaris-accent-hover);
    }

    .results {
        flex: 1;
        min-width: 0;
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 5px;
        overflow-x: auto;
    }

    .results h2 {
        font-size: 18px;
        margin-top: 0;
        margin-bottom: 15px;
    }

    .results table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }

    .results table th,
    .results table td {
        border: 1px solid var(--polaris-border);
        padding: 6px;
        text-align: left;
        font-size: 13px;
    }

    .results table th {
        background: var(--polaris-divider);
    }

    .results table tr:nth-child(even) {
        background: var(--polaris-bg-alt);
    }

    .results table tr:hover {
        background: var(--polaris-border);
    }

    .results a {
        color: var(--polaris-accent);
        text-decoration: underline;
    }
    </style>

    <div class="flex-container">
        <!-- Left Sidebar: Search Criteria -->
        <div class="sidebar">
            <h2>Search Criteria</h2>
            <form method="get" action="search_exhibits.php">
                <?php if ($embedded): ?>
                <input type="hidden" name="embedded" value="1">
                <?php endif; ?>
                <div class="field">
                    <label for="exhibit_ref">Exhibit Ref:</label>
                    <input type="text" id="exhibit_ref" name="exhibit_ref"
                        value="<?php echo htmlspecialchars($exhibit_ref); ?>">
                </div>
                <div class="field">
                    <label for="exhibit_type">Exhibit Type:</label>
                    <select id="exhibit_type" name="exhibit_type">
                        <option value="">All</option>
                        <?php foreach ($exhibitTypes as $et): ?>
                        <option value="<?php echo $et['exhibit_type_id']; ?>"
                            <?php if ($et['exhibit_type_id'] == $exhibit_type) echo "selected"; ?>>
                            <?php echo htmlspecialchars($et['type_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="urgency">Urgency:</label>
                    <select id="urgency" name="urgency">
                        <option value="">Any</option>
                        <option value="Low" <?php if ($urgency === "Low") echo "selected"; ?>>Low</option>
                        <option value="Medium" <?php if ($urgency === "Medium") echo "selected"; ?>>Medium</option>
                        <option value="High" <?php if ($urgency === "High") echo "selected"; ?>>High</option>
                    </select>
                </div>
                <div class="field">
                    <label for="delivered_by">Delivered By:</label>
                    <input type="text" id="delivered_by" name="delivered_by"
                        value="<?php echo htmlspecialchars($delivered_by); ?>">
                </div>
                <div class="field">
                    <label for="status">Status:</label>
                    <select id="status" name="status">
                        <option value="">Any</option>
                        <?php foreach ($statusOptions as $opt): ?>
                        <option value="<?php echo $opt; ?>" <?php if ($status === $opt) echo "selected"; ?>>
                            <?php echo htmlspecialchars($opt); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="location">Location:</label>
                    <select id="location" name="location">
                        <option value="">Any</option>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?php echo $loc['location_id']; ?>"
                            <?php if ($location == $loc['location_id']) echo "selected"; ?>>
                            <?php echo htmlspecialchars($loc['location_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="allocated_to">Allocated To:</label>
                    <input type="text" id="allocated_to" name="allocated_to"
                        value="<?php echo htmlspecialchars($allocated_to); ?>">
                </div>

                <div class="field">
                    <label for="bag_number">Bag Number:</label>
                    <input type="text" id="bag_number" name="bag_number"
                        value="<?php echo htmlspecialchars($bag_number); ?>">
                </div>

                <div class="field">
                    <label for="keyword">Item Description (Keyword):</label>
                    <input type="text" id="keyword" name="keyword" value="<?php echo htmlspecialchars($keyword); ?>">
                </div>

                <div class="field">
                    <label for="summary">Summary of Findings (Keyword):</label>
                    <input type="text" id="summary" name="summary" value="<?php echo htmlspecialchars($summary); ?>">
                </div>

                <div class="field">
                    <label style="display:inline-flex; align-items:center; gap:6px;">
                        <input type="checkbox" id="include_sub_exhibits" name="include_sub_exhibits" value="1"
                            style="width:auto;" <?php if ($includeSubExhibits) echo "checked"; ?>>
                        Include sub-exhibits
                    </label>
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
                <button type="button" class="reset-btn"
                    onclick="window.location.href='search_exhibits.php<?php echo $embedded ? '?embedded=1' : ''; ?>';">Reset</button>
            </form>
        </div>

        <!-- Right Panel: Search Results -->
        <div class="results">
            <h2>Search Results</h2>
            <?php if (!$hasSearched): ?>
            <p>Enter your search criteria and click Search.</p>
            <?php elseif (!empty($exhibits)): ?>
            <table>
                <tr>
                    <th>Job Ref</th>
                    <th>Exhibit Ref</th>
                    <th>Item Description</th>
                    <th>Exhibit Type</th>
                    <th>Urgency</th>
                    <th>Bag Number</th>
                    <th>Delivered By</th>
                    <th>Time In</th>
                    <th>Time Out / Location</th>
                    <th>Status</th>
                    <th>Allocated To</th>
                    <th>Summary of Findings</th>

                </tr>
                <?php foreach ($exhibits as $ex): ?>
                <tr>
                    <td><a href="../cargo_hold/job.php?job_id=<?= $ex['job_id'] ?>"
                            target="_top"><?= htmlspecialchars($ex['custom_ref']) ?></a>
                    </td>

                    <td>
                        <a href="edit_exhibit.php?exhibit_id=<?php echo urlencode($ex['exhibit_id']); ?>"
                            target="_top">
                            <?php echo htmlspecialchars(!empty($ex['exhibit_ref']) ? $ex['exhibit_ref'] : "N/A"); ?>
                        </a>
                        <?php if (!empty($ex['parent_id'])): ?>
                        <br><span style="font-size:11px; color:var(--polaris-text-muted);">sub-exhibit of
                            <?php echo htmlspecialchars($ex['parent_exhibit_ref'] ?? '?'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars(!empty($ex['item_description']) ? $ex['item_description'] : "N/A"); ?>
                    </td>
                    <td><?php echo htmlspecialchars(!empty($ex['exhibit_type_name']) ? $ex['exhibit_type_name'] : "N/A"); ?>
                    </td>
                    <td><?php echo htmlspecialchars(!empty($ex['urgency']) ? $ex['urgency'] : "N/A"); ?></td>
                    <td><?php echo htmlspecialchars(!empty($ex['bag_number']) ? $ex['bag_number'] : "N/A"); ?></td>
                    <td><?php echo htmlspecialchars(!empty($ex['delivered_by']) ? $ex['delivered_by'] : "N/A"); ?></td>
                    <td><?php echo htmlspecialchars(!empty($ex['time_in']) ? $ex['time_in'] : "N/A"); ?></td>
                    <td>
                        <?php 
                                    // If time_out is set, display time_out; otherwise show location name.
                                    echo htmlspecialchars(!empty($ex['time_out']) ? $ex['time_out'] : (!empty($ex['location_name']) ? $ex['location_name'] : "N/A"));
                                ?>
                    </td>
                    <td><?php echo htmlspecialchars(!empty($ex['status']) ? $ex['status'] : "N/A"); ?></td>
                    <td><?php echo htmlspecialchars($ex['allocated_to_name'] ?? 'Unassigned'); ?></td>
                    <td>
                        <?php
                        $findings = $ex['summary_of_findings'] ?? '';
                        echo htmlspecialchars($findings !== '' ? (mb_strlen($findings) > 80 ? mb_substr($findings, 0, 80) . '...' : $findings) : 'N/A');
                        ?>
                    </td>

                </tr>
                <?php endforeach; ?>
            </table>
            <?php else: ?>
            <p>No exhibits found.</p>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>