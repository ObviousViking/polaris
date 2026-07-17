<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');

$embedded = isset($_GET['embedded']);

// Initialize search variables.
$custom_ref    = isset($_GET['custom_ref']) ? trim($_GET['custom_ref']) : "";
$case_type     = isset($_GET['case_type']) ? trim($_GET['case_type']) : "";
$created_by    = isset($_GET['created_by']) ? trim($_GET['created_by']) : "";
$oic           = isset($_GET['oic']) ? trim($_GET['oic']) : "";
$operation     = isset($_GET['operation']) ? trim($_GET['operation']) : "";
$keyword       = isset($_GET['keyword']) ? trim($_GET['keyword']) : "";
$suspect       = isset($_GET['suspect']) ? trim($_GET['suspect']) : "";
$fingerprints  = isset($_GET['fingerprints']) ? trim($_GET['fingerprints']) : "";
$dnaCollected  = isset($_GET['dna']) ? trim($_GET['dna']) : "";
$malware       = isset($_GET['malware']) ? trim($_GET['malware']) : "";
$status        = isset($_GET['status']) ? trim($_GET['status']) : "";

$conditions = [];
$typesStr   = "";
$params     = [];

// Only searched if the user actually submitted the form.
$hasSearched = isset($_GET['submit']);

// Build conditions based on provided search criteria.
if (!empty($custom_ref)) {
    $conditions[] = "j.custom_ref LIKE ?";
    $typesStr .= "s";
    $params[] = "%" . $custom_ref . "%";
}
if (!empty($case_type)) {
    $conditions[] = "j.case_type_id = ?";
    $typesStr .= "i";
    $params[] = intval($case_type);
}
if (!empty($created_by)) {
    // jobs.created_by stores an ID, so match against the joined users table.
    $conditions[] = "CONCAT(u.first_name, ' ', u.last_name) LIKE ?";
    $typesStr .= "s";
    $params[] = "%" . $created_by . "%";
}
if (!empty($oic)) {
    $conditions[] = "j.oic LIKE ?";
    $typesStr .= "s";
    $params[] = "%" . $oic . "%";
}
if (!empty($operation)) {
    // jobs.operation stores an ID, so this is an exact match, not a LIKE.
    $conditions[] = "j.operation = ?";
    $typesStr .= "i";
    $params[] = intval($operation);
}
if (!empty($keyword)) {
    // Searching the case background.
    $conditions[] = "j.initial_summary LIKE ?";
    $typesStr .= "s";
    $params[] = "%" . $keyword . "%";
}
if (!empty($suspect)) {
    $conditions[] = "j.suspect LIKE ?";
    $typesStr .= "s";
    $params[] = "%" . $suspect . "%";
}
if (!empty($fingerprints)) {
    if (strtolower($fingerprints) === "yes") {
        $conditions[] = "j.fingerprints = ?";
        $typesStr .= "i";
        $params[] = 1;
    } elseif (strtolower($fingerprints) === "no") {
        $conditions[] = "j.fingerprints = ?";
        $typesStr .= "i";
        $params[] = 0;
    }
}
if (!empty($dnaCollected)) {
    if (strtolower($dnaCollected) === "yes") {
        $conditions[] = "j.dna = ?";
        $typesStr .= "i";
        $params[] = 1;
    } elseif (strtolower($dnaCollected) === "no") {
        $conditions[] = "j.dna = ?";
        $typesStr .= "i";
        $params[] = 0;
    }
}
if (!empty($malware)) {
    if (strtolower($malware) === "yes") {
        $conditions[] = "j.malware = ?";
        $typesStr .= "i";
        $params[] = 1;
    } elseif (strtolower($malware) === "no") {
        $conditions[] = "j.malware = ?";
        $typesStr .= "i";
        $params[] = 0;
    }
}
if (!empty($status)) {
    $conditions[] = "j.status_id = ?";
    $typesStr .= "i";
    $params[] = intval($status);
}

$jobs = [];
if ($hasSearched) {
    $sql = "
        SELECT j.job_id, j.custom_ref, j.date_time, j.oic, j.suspect, j.initial_summary,
               ct.type_name AS case_type_name,
               op.operation_name,
               st.status_name,
               CONCAT(u.first_name, ' ', u.last_name) AS created_by_name
        FROM jobs j
        LEFT JOIN case_types ct ON j.case_type_id = ct.case_type_id
        LEFT JOIN operations op ON j.operation = op.operation_id
        LEFT JOIN job_status st ON j.status_id = st.status_id
        LEFT JOIN users u ON j.created_by = u.id
    ";
    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }
    $sql .= " ORDER BY j.date_time DESC";

    // Prepare and execute the statement.
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
            $jobs[] = $row;
        }
        if (empty($jobs)) {
            $message = "No cases found matching your search criteria.";
        }
        $stmt->close();
    }
}

// Lookup arrays for dropdowns.
$caseTypes = [];
$result = $conn->query("SELECT case_type_id, type_name FROM case_types ORDER BY type_name");
while ($row = $result->fetch_assoc()) {
    $caseTypes[] = $row;
}
$result->free();

$jobStatuses = [];
$result = $conn->query("SELECT status_id, status_name FROM job_status ORDER BY status_name");
while ($row = $result->fetch_assoc()) {
    $jobStatuses[] = $row;
}
$result->free();

$operations = [];
$result = $conn->query("SELECT operation_id, operation_name FROM operations ORDER BY operation_name");
while ($row = $result->fetch_assoc()) {
    $operations[] = $row;
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
            <form method="get" action="search_cases.php">
                <?php if ($embedded): ?>
                <input type="hidden" name="embedded" value="1">
                <?php endif; ?>
                <div class="field">
                    <label for="custom_ref">Custom Ref:</label>
                    <input type="text" id="custom_ref" name="custom_ref"
                        value="<?php echo htmlspecialchars($custom_ref); ?>">
                </div>
                <div class="field">
                    <label for="case_type">Case Type:</label>
                    <select id="case_type" name="case_type">
                        <option value="">All</option>
                        <?php foreach ($caseTypes as $ct): ?>
                        <option value="<?php echo $ct['case_type_id']; ?>"
                            <?php if ($ct['case_type_id'] == $case_type) echo "selected"; ?>>
                            <?php echo htmlspecialchars($ct['type_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="created_by">Created By:</label>
                    <input type="text" id="created_by" name="created_by"
                        value="<?php echo htmlspecialchars($created_by); ?>">
                </div>
                <div class="field">
                    <label for="oic">OIC:</label>
                    <input type="text" id="oic" name="oic" value="<?php echo htmlspecialchars($oic); ?>">
                </div>
                <div class="field">
                    <label for="operation">Operation:</label>
                    <select id="operation" name="operation">
                        <option value="">All</option>
                        <?php foreach ($operations as $op): ?>
                        <option value="<?php echo $op['operation_id']; ?>"
                            <?php if ($op['operation_id'] == $operation) echo "selected"; ?>>
                            <?php echo htmlspecialchars($op['operation_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="keyword">Case Background (Keyword):</label>
                    <input type="text" id="keyword" name="keyword" value="<?php echo htmlspecialchars($keyword); ?>">
                </div>
                <div class="field">
                    <label for="suspect">Suspect:</label>
                    <input type="text" id="suspect" name="suspect" value="<?php echo htmlspecialchars($suspect); ?>">
                </div>
                <div class="field">
                    <label for="fingerprints">Fingerprints:</label>
                    <select id="fingerprints" name="fingerprints">
                        <option value="">Any</option>
                        <option value="Yes" <?php if (strtolower($fingerprints) === "yes") echo "selected"; ?>>Yes
                        </option>
                        <option value="No" <?php if (strtolower($fingerprints) === "no") echo "selected"; ?>>No</option>
                    </select>
                </div>
                <div class="field">
                    <label for="dna">DNA Collected:</label>
                    <select id="dna" name="dna">
                        <option value="">Any</option>
                        <option value="Yes" <?php if (strtolower($dnaCollected) === "yes") echo "selected"; ?>>Yes
                        </option>
                        <option value="No" <?php if (strtolower($dnaCollected) === "no") echo "selected"; ?>>No</option>
                    </select>
                </div>
                <div class="field">
                    <label for="malware">Malware Involved:</label>
                    <select id="malware" name="malware">
                        <option value="">Any</option>
                        <option value="Yes" <?php if (strtolower($malware) === "yes") echo "selected"; ?>>Yes</option>
                        <option value="No" <?php if (strtolower($malware) === "no") echo "selected"; ?>>No</option>
                    </select>
                </div>
                <div class="field">
                    <label for="status">Status:</label>
                    <select id="status" name="status">
                        <option value="">Any</option>
                        <?php foreach ($jobStatuses as $js): ?>
                        <option value="<?php echo $js['status_id']; ?>"
                            <?php if ($js['status_id'] == $status) echo "selected"; ?>>
                            <?php echo htmlspecialchars($js['status_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="submit" name="submit" value="Search">
                <button type="button" class="reset-btn"
                    onclick="window.location.href='search_cases.php<?php echo $embedded ? '?embedded=1' : ''; ?>';">Reset</button>
            </form>
        </div>

        <!-- Right Panel: Search Results -->
        <div class="results">
            <h2>Search Results</h2>
            <?php if (!$hasSearched): ?>
            <p>Enter your search criteria and click Search.</p>
            <?php elseif (!empty($jobs)): ?>
            <table>
                <tr>
                    <th>Custom Ref</th>
                    <th>Date/Time</th>
                    <th>Case Type</th>
                    <th>Operation</th>
                    <th>Status</th>
                    <th>OIC</th>
                    <th>Created By</th>
                    <th>Suspect</th>
                    <th>Case Background</th>
                </tr>
                <?php foreach ($jobs as $job): ?>
                <tr>
                    <td>
                        <a href="job.php?job_id=<?php echo urlencode($job['job_id']); ?>"
                            target="_top">
                            <?php echo htmlspecialchars($job['custom_ref']); ?>
                        </a>
                    </td>
                    <td><?php echo htmlspecialchars($job['date_time']); ?></td>
                    <td><?php echo htmlspecialchars($job['case_type_name'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($job['operation_name'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($job['status_name'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($job['oic']); ?></td>
                    <td><?php echo htmlspecialchars($job['created_by_name'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($job['suspect']); ?></td>
                    <td><?php echo htmlspecialchars($job['initial_summary']); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php else: ?>
            <p>No cases found.</p>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>