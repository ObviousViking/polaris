<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once('../includes/integrity.php');

// Ensure a job_id is provided.
if (!isset($_GET['job_id'])) {
    echo "Job ID not specified.";
    exit();
}
$job_id = intval($_GET['job_id']);

// Retrieve current job details.
$stmt = $conn->prepare("
    SELECT 
        j.job_id,
        j.custom_ref,
        j.initial_summary,
        j.oic,
        j.operation,
        op.operation_name,
        j.customer_id,
        j.lead_force_id,
        j.suspect,
        j.fingerprints,
        j.dna,
        j.malware,
        j.status_id,
        j.strategy_set,
        j.strategy_due,
        j.strategy_complete,
        j.case_type_id
    FROM jobs j
    LEFT JOIN operations op ON j.operation = op.operation_id
    WHERE j.job_id = ?
");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$stmt->bind_result(
    $job_id,
    $custom_ref,
    $initial_summary,
    $oic,
    $operation_id,
    $operation_name,
    $customer_id,
    $lead_force_id,
    $suspect,
    $fingerprints,
    $dna,
    $malware,
    $status_id,
    $strategy_set,
    $strategy_due,
    $strategy_complete,
    $case_type_id
);
if (!$stmt->fetch()) {
    echo "Job not found.";
    exit();
}
$stmt->close();

// Retrieve lookup data
function getLookupData($conn, $query) {
    $data = [];
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $result->free();
    return $data;
}

$caseTypes   = getLookupData($conn, "SELECT case_type_id, type_name FROM case_types ORDER BY type_name");
$jobStatuses = getLookupData($conn, "SELECT status_id, status_name FROM job_status ORDER BY status_name");
$operations  = getLookupData($conn, "SELECT operation_id, operation_name FROM operations ORDER BY operation_name");
$forces      = getLookupData($conn, "SELECT id, force_name FROM forces ORDER BY force_name");
$customers   = getLookupData($conn, "SELECT customer_id, name FROM customers ORDER BY name");

$message = "";

// Stores names, not raw foreign keys, so the audit trail is readable.
function lookupName(array $rows, string $idCol, string $nameCol, $id): ?string
{
    if ($id === null || $id === '') {
        return null;
    }
    foreach ($rows as $row) {
        if ($row[$idCol] == $id) {
            return $row[$nameCol];
        }
    }
    return null;
}

// datetime-local inputs submit '' (needs NULL) and use 'T' as the separator
// (MySQL needs a space), so both need converting.
function normalizeDatetimeLocal($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    return str_replace('T', ' ', $value);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_initial_summary = trim($_POST['initial_summary']);
    $new_oic             = trim($_POST['oic']);
    $new_operation_id    = isset($_POST['operation']) && is_numeric($_POST['operation']) ? intval($_POST['operation']) : $operation_id;
    $new_customer        = !empty($_POST['customer']) ? intval($_POST['customer']) : NULL;
    $new_lead_force      = !empty($_POST['lead_force']) ? intval($_POST['lead_force']) : NULL;
    $new_suspect         = trim($_POST['suspect']);
    $new_fingerprints    = isset($_POST['fingerprints']) ? 1 : 0;
    $new_dna             = isset($_POST['dna']) ? 1 : 0;
    $new_malware         = isset($_POST['malware']) ? 1 : 0;
    $new_status          = intval($_POST['status']);
    $new_strategy_set    = normalizeDatetimeLocal($_POST['strategy_set'] ?? $strategy_set);
    $new_strategy_due    = normalizeDatetimeLocal($_POST['strategy_due'] ?? $strategy_due);
    $new_strategy_complete = normalizeDatetimeLocal($_POST['strategy_complete'] ?? $strategy_complete);
    $new_case_type       = intval($_POST['case_type']);

    $changes = [];

    if ($new_initial_summary !== $initial_summary) {
        $changes['Case Background'] = ["old" => $initial_summary, "new" => $new_initial_summary];
    }
    if ($new_oic !== $oic) {
        $changes['OIC'] = ["old" => $oic, "new" => $new_oic];
    }
    if ($new_operation_id !== $operation_id) {
        $oldOpName = $newOpName = null;
        foreach ($operations as $op) {
            if ($op['operation_id'] == $operation_id) $oldOpName = $op['operation_name'];
            if ($op['operation_id'] == $new_operation_id) $newOpName = $op['operation_name'];
        }
        if ($oldOpName !== $newOpName) {
            $changes['Operation'] = ["old" => $oldOpName, "new" => $newOpName];
        }
    }
    if ($new_customer !== $customer_id) {
        $changes['Customer'] = [
            "old" => lookupName($customers, 'customer_id', 'name', $customer_id),
            "new" => lookupName($customers, 'customer_id', 'name', $new_customer),
        ];
    }
    if ($new_lead_force !== $lead_force_id) {
        $changes['Lead Force'] = [
            "old" => lookupName($forces, 'id', 'force_name', $lead_force_id),
            "new" => lookupName($forces, 'id', 'force_name', $new_lead_force),
        ];
    }
    if ($new_suspect !== $suspect) {
        $changes['Suspect'] = ["old" => $suspect, "new" => $new_suspect];
    }
    if ($new_fingerprints !== $fingerprints) {
        $changes['Fingerprints'] = ["old" => $fingerprints ? "Yes" : "No", "new" => $new_fingerprints ? "Yes" : "No"];
    }
    if ($new_dna !== $dna) {
        $changes['DNA'] = ["old" => $dna ? "Yes" : "No", "new" => $new_dna ? "Yes" : "No"];
    }
    if ($new_malware !== $malware) {
        $changes['Malware'] = ["old" => $malware ? "Yes" : "No", "new" => $new_malware ? "Yes" : "No"];
    }
    if ($new_status !== $status_id) {
        $changes['Status'] = [
            "old" => lookupName($jobStatuses, 'status_id', 'status_name', $status_id),
            "new" => lookupName($jobStatuses, 'status_id', 'status_name', $new_status),
        ];
    }
    // Compares normalized values so setting or clearing a date both count as a change.
    $trackDatetimeChange = function ($label, $old, $new) use (&$changes) {
        $oldNorm = !empty($old) ? date('Y-m-d H:i', strtotime($old)) : null;
        $newNorm = !empty($new) ? date('Y-m-d H:i', strtotime($new)) : null;
        if ($oldNorm !== $newNorm) {
            $changes[$label] = ["old" => $old, "new" => $new];
        }
    };
    $trackDatetimeChange('Strategy Set', $strategy_set, $new_strategy_set);
    $trackDatetimeChange('Strategy Due', $strategy_due, $new_strategy_due);
    $trackDatetimeChange('Strategy Complete', $strategy_complete, $new_strategy_complete);
    if ($new_case_type !== $case_type_id) {
        $changes['Case Type'] = [
            "old" => lookupName($caseTypes, 'case_type_id', 'type_name', $case_type_id),
            "new" => lookupName($caseTypes, 'case_type_id', 'type_name', $new_case_type),
        ];
    }

    if (!empty($changes)) {
        $stmt = $conn->prepare("UPDATE jobs 
            SET initial_summary = ?, oic = ?, operation = ?, customer_id = ?, lead_force_id = ?, suspect = ?, 
                fingerprints = ?, dna = ?, malware = ?, status_id = ?, 
                strategy_set = ?, strategy_due = ?, strategy_complete = ?, case_type_id = ? 
            WHERE job_id = ?");
        $stmt->bind_param(
            "ssiiisiiissssii",
            $new_initial_summary,
            $new_oic,
            $new_operation_id,
            $new_customer,
            $new_lead_force,
            $new_suspect,
            $new_fingerprints,
            $new_dna,
            $new_malware,
            $new_status,
            $new_strategy_set,
            $new_strategy_due,
            $new_strategy_complete,
            $new_case_type,
            $job_id
        );

        if ($stmt->execute()) {
            $stmt->close();
            $historyAction = "UPDATE";
            $changedBy = $_SESSION['user_id'];
            $changesJSON = json_encode($changes);
            insert_history_row($conn, 'case_history', $job_id, $historyAction, $changedBy, $changesJSON);

            require_once '../includes/achievements.php';
            check_and_unlock_achievements($conn, (int) $changedBy, 'cases_completed');

            header("Location: job.php?job_id=" . $job_id);
            exit();
        } else {
            $message = "Error updating case: " . $stmt->error;
            $stmt->close();
        }
    } else {
        $message = "No changes detected.";
    }
}



include('../header.php');
?>


<style>
    /* header.php's own body{} already sets margin/background/color/font-family
       page-wide. This page used to redeclare a second <!DOCTYPE html><html>
       <head> here after the include - browsers tolerate the invalid
       nesting, but the stray markup risked winning the cascade against
       header.php's own rules. .content-wrapper already clears the fixed
       header via its margin-top, so nothing needed to move here. */
    .content-wrapper {
        max-width: 800px;
        margin: 120px auto 20px auto;
        padding: 20px;
        background-color: var(--polaris-surface-deep);
        border-radius: 5px;
        box-shadow: 0 2px 10px rgba(255, 255, 255, 0.1);
    }

    h2 {
        color: var(--polaris-text);
        text-align: center;
        margin-bottom: 20px;
    }

    .form-columns {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
    }

    .column {
        flex: 1 1 calc(50% - 20px);
    }

    .field {
        margin-bottom: 15px;
    }

    label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
        color: var(--polaris-gray-e0);
    }

    input[type="text"],
    input[type="number"],
    input[type="datetime-local"],
    textarea,
    select {
        width: 100%;
        padding: 8px;
        border: 1px solid var(--polaris-text-secondary);
        border-radius: 4px;
        background-color: rgba(255, 255, 255, 0.1);
        color: var(--polaris-gray-e0);
    }

    /* Most browsers render <option> with their own solid background rather
       than inheriting the <select>'s semi-transparent one, which without
       this left the dropdown list showing light text on a white background. */
    select option {
        background-color: var(--polaris-bg);
        color: var(--polaris-gray-e0);
    }

    input[readonly] {
        background-color: rgba(255, 255, 255, 0.05);
    }

    .checkbox-group {
        display: flex;
        gap: 10px;
    }

    .checkbox-group label {
        display: inline-block;
    }

    .button-group {
        display: flex;
        justify-content: space-between;
        width: 100%;
        margin-top: 20px;
        gap: 10px;
    }

    .button-group .action-btn {
        flex: 1;
        padding: 5px 10px;
        font-size: 14px;
        border: none;
        border-radius: 3px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        text-align: center;
        text-decoration: none;
        cursor: pointer;
        transition: background 0.3s ease;
    }

    .button-group .action-btn:hover {
        background: var(--polaris-accent-hover);
    }

    .message {
        text-align: center;
        margin-bottom: 20px;
        padding: 10px;
        border-radius: 5px;
        font-size: 16px;
    }

    .success {
        background-color: var(--polaris-success-bg);
        color: var(--polaris-success-text);
    }

    .error {
        background-color: var(--polaris-error-bg);
        color: var(--polaris-error-text);
    }
    </style>

    <div class="content-wrapper">
        <h2>Edit Case</h2>
        <?php if (!empty($message)): ?>
        <div class="message <?php echo (strpos($message, 'Error') !== false) ? 'error' : 'success'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        <form method="post" action="edit_job.php?job_id=<?php echo $job_id; ?>">
            <div class="form-columns">
                <div class="column">
                    <div class="field">
                        <label>Custom Ref</label>
                        <input type="text" value="<?php echo htmlspecialchars($custom_ref); ?>" readonly>
                    </div>
                    <div class="field">
                        <label>Case Type</label>
                        <select name="case_type">
                            <?php foreach ($caseTypes as $ct): ?>
                            <option value="<?php echo $ct['case_type_id']; ?>"
                                <?php if ($ct['case_type_id'] == $case_type_id) echo "selected"; ?>>
                                <?php echo htmlspecialchars($ct['type_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Operation</label>
                        <select name="operation">
                            <?php foreach ($operations as $op): ?>
                            <option value="<?php echo $op['operation_id']; ?>"
                                <?php if ($op['operation_id'] == $operation_id) echo "selected"; ?>>
                                <?php echo htmlspecialchars($op['operation_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>OIC</label>
                        <input type="text" name="oic" value="<?php echo htmlspecialchars($oic); ?>"
                            placeholder="Enter Officer In Charge">
                    </div>
                    <div class="field">
                        <label>Case Background</label>
                        <textarea name="initial_summary" rows="5"
                            placeholder="Enter case background"><?php echo htmlspecialchars($initial_summary); ?></textarea>
                    </div>
                    <div class="field checkbox-group">
                        <label><input type="checkbox" name="fingerprints" value="1"
                                <?php if ($fingerprints) echo "checked"; ?>> Fingerprints</label>
                        <label><input type="checkbox" name="dna" value="1" <?php if ($dna) echo "checked"; ?>> DNA</label>
                        <label><input type="checkbox" name="malware" value="1"
                                <?php if ($malware) echo "checked"; ?>> Malware</label>
                    </div>
                </div>
                <div class="column">
                    <div class="field">
                        <label>Customer</label>
                        <select name="customer">
                            <option value="">Select Customer</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?php echo $c['customer_id']; ?>"
                                <?php if ($c['customer_id'] == $customer_id) echo "selected"; ?>>
                                <?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Lead Force</label>
                        <select name="lead_force">
                            <option value="">Select Lead Force</option>
                            <?php foreach ($forces as $f): ?>
                            <option value="<?php echo $f['id']; ?>"
                                <?php if ($f['id'] == $lead_force_id) echo "selected"; ?>>
                                <?php echo htmlspecialchars($f['force_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Suspect</label>
                        <input type="text" name="suspect" value="<?php echo htmlspecialchars($suspect); ?>"
                            placeholder="Enter suspect name">
                    </div>
                    <div class="field">
                        <label>Status</label>
                        <select name="status" required>
                            <?php foreach ($jobStatuses as $js): ?>
                            <option value="<?php echo $js['status_id']; ?>"
                                <?php if ($js['status_id'] == $status_id) echo "selected"; ?>>
                                <?php echo htmlspecialchars($js['status_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Strategy Set</label>
                        <input type="datetime-local" name="strategy_set"
                            value="<?php echo htmlspecialchars(date('Y-m-d\TH:i', strtotime($strategy_set))); ?>">
                    </div>
                    <div class="field">
                        <label>Strategy Due</label>
                        <input type="datetime-local" name="strategy_due"
                            value="<?php echo $strategy_due ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($strategy_due))) : ''; ?>">
                    </div>
                    <div class="field">
                        <label>Strategy Complete</label>
                        <input type="datetime-local" name="strategy_complete"
                            value="<?php echo $strategy_complete ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($strategy_complete))) : ''; ?>">
                    </div>
                </div>
            </div>
            <div class="button-group">
                <button type="submit" class="action-btn">Update Case</button>
                <a href="view_case_history.php?job_id=<?php echo $job_id; ?>" class="action-btn">View History</a>
                <button type="button" class="action-btn"
                    onclick="window.location.href='job.php?job_id=<?php echo $job_id; ?>'">Cancel</button>
            </div>
        </form>
    </div>
</body>

</html>