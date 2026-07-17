<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/settings.php';

$embedded = isset($_GET['embedded']) || isset($_POST['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    include '../header.php';
}

// Generate the next custom reference using a two-digit year prefix and a 3-digit sequence.
$year_prefix = date('y');
$query = "SELECT MAX(CAST(SUBSTRING(custom_ref, 3) AS UNSIGNED)) AS last_ref 
          FROM jobs 
          WHERE custom_ref LIKE '$year_prefix%'";
$result = $conn->query($query);
$row = $result->fetch_assoc();
$next_ref = is_null($row['last_ref']) 
            ? $year_prefix . '000' 
            : $year_prefix . str_pad(($row['last_ref'] + 1), 3, '0', STR_PAD_LEFT);
$result->free();

// Fetch available case types.
$caseTypes = [];
$result = $conn->query("SELECT case_type_id, type_name FROM case_types ORDER BY type_name");
while ($row = $result->fetch_assoc()) {
    $caseTypes[] = $row;
}
$result->free();

// Fetch available job statuses.
$jobStatuses = [];
$result = $conn->query("SELECT status_id, status_name FROM job_status ORDER BY status_name");
while ($row = $result->fetch_assoc()) {
    $jobStatuses[] = $row;
}
$result->free();

// Fetch available operations.
$operations = [];
$result = $conn->query("SELECT operation_id, operation_name FROM operations ORDER BY operation_name");
while ($row = $result->fetch_assoc()) {
    $operations[] = $row;
}
$result->free();

// Fetch available lead forces.
$forces = [];
$result = $conn->query("SELECT id, force_name FROM forces ORDER BY force_name");
while ($row = $result->fetch_assoc()) {
    $forces[] = $row;
}
$result->free();

// Fetch available customers.
$customers = [];
$result = $conn->query("SELECT customer_id, name FROM customers ORDER BY name");
while ($row = $result->fetch_assoc()) {
    $customers[] = $row;
}
$result->free();

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve and sanitize form inputs.
    $description  = trim($_POST['description']);  // This will be used as "Case Background"
    $case_type_id = intval($_POST['case_type']);
    $oic          = trim($_POST['oic']);
    // If operation is not provided, default to 1.
    $operation_id = !empty($_POST['operation']) ? intval($_POST['operation']) : 1;
    // If status is not provided, default to 1.
    $status_id    = !empty($_POST['status']) ? intval($_POST['status']) : 1;
    // For customer, if not provided, set to NULL.
    $customer_id  = !empty($_POST['customer']) ? intval($_POST['customer']) : NULL;
    // Lead force is optional.
    $lead_force_id = !empty($_POST['lead_force']) ? intval($_POST['lead_force']) : NULL;
    $suspect       = trim($_POST['suspect']);
    $fingerprints  = isset($_POST['fingerprints']) ? 1 : 0;
    $dna           = isset($_POST['dna']) ? 1 : 0;
    $malware       = isset($_POST['malware']) ? 1 : 0;
    
    // Basic validation: Require Case Type.
    if (empty($case_type_id)) {
        $message = "Please select a Case Type.";
    } else {
        // Set created_by as the current user ID.
        $created_by = $_SESSION['user_id'];
        // Set strategy_set as the current date/time.
        $strategy_set = date('Y-m-d H:i:s');
        // Strategy due is set from the configured SLA (Captain's Quarters -> Configure SLA).
        $slaDays = get_strategy_due_sla_days($conn);
        $strategy_due = date('Y-m-d H:i:s', strtotime("+{$slaDays} days"));
        
        // Insert the new case. (15 parameters total; strategy_complete is removed.)
        $stmt = $conn->prepare("INSERT INTO jobs 
            (custom_ref, created_by, initial_summary, oic, operation, customer_id, lead_force_id, suspect, fingerprints, dna, status_id, malware, strategy_set, strategy_due, case_type_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        // Types: custom_ref, created_by, initial_summary, oic, operation, customer_id,
        // lead_force_id, suspect, fingerprints, dna, status_id, malware, strategy_set,
        // strategy_due, case_type_id
        $typeString = "sissiiisiiiissi";
        $stmt->bind_param($typeString, 
            $next_ref,
            $created_by,
            $description,
            $oic,
            $operation_id,
            $customer_id,
            $lead_force_id,
            $suspect,
            $fingerprints,
            $dna,
            $status_id,
            $malware,
            $strategy_set,
            $strategy_due,
            $case_type_id
        );
        
        if ($stmt->execute()) {
            $newJobId = $conn->insert_id;

            require_once '../includes/achievements.php';
            check_and_unlock_achievements($conn, (int) $created_by, 'cases_created');

            echo "<script>
                    alert('Case created successfully. Custom Ref: " . htmlspecialchars($next_ref) . "');
                    " . ($embedded ? 'window.top' : 'window') . ".location.href = 'job.php?job_id=" . $newJobId . "';
                  </script>";
            exit();
        } else {
            $message = "Error creating case: " . $stmt->error;
        }
        $stmt->close();
    }
}
?>

<style>
    .content-wrapper {
        max-width: 800px;
        margin: <?php echo $embedded ? '0' : '120px'; ?> auto 20px auto;
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

    input[disabled] {
        background-color: rgba(255, 255, 255, 0.05);
    }

    input[type="submit"] {
        background: var(--polaris-success-strong);
        color: var(--polaris-text);
        padding: 5px 10px;
        border: none;
        border-radius: 3px;
        cursor: pointer;
        font-size: 14px;
        width: 48%;
    }

    input[type="submit"]:hover {
        background: var(--polaris-success-strong-hover);
    }

    .button-group {
        display: flex;
        justify-content: space-between;
        width: 100%;
        margin-top: 20px;
    }

    .cancel-button {
        background: var(--polaris-accent);
        color: var(--polaris-text);
        padding: 5px 10px;
        border: none;
        border-radius: 3px;
        cursor: pointer;
        font-size: 14px;
        text-decoration: none;
        text-align: center;
        width: 48%;
    }

    .cancel-button:hover {
        background: var(--polaris-accent-hover);
    }

    .checkbox-group {
        display: flex;
        gap: 10px;
    }

    .checkbox-group label {
        display: inline-block;
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
        <h2>Create New Case</h2>
        <?php if (!empty($message)): ?>
        <div class="message <?php echo (strpos($message, 'Error') !== false) ? 'error' : 'success'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        <form method="post" action="create_case.php<?php echo $embedded ? '?embedded=1' : ''; ?>">
            <div class="form-columns">
                <div class="column">
                    <div class="field">
                        <label>Custom Ref</label>
                        <input type="text" value="<?php echo htmlspecialchars($next_ref); ?>" disabled>
                    </div>
                    <div class="field">
                        <label>Date/Time</label>
                        <input type="text" value="<?php echo date('Y-m-d H:i:s'); ?>" disabled>
                    </div>
                    <div class="field">
                        <label>Case Type (required)</label>
                        <select name="case_type" required>
                            <option value="">Select Case Type</option>
                            <?php foreach ($caseTypes as $ct): ?>
                            <option value="<?php echo $ct['case_type_id']; ?>">
                                <?php echo htmlspecialchars($ct['type_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Operation (required, default is Operation 1)</label>
                        <select name="operation" required>
                            <?php 
                        // Default to operation id 1 if none selected.
                        ?>
                            <?php foreach ($operations as $op): ?>
                            <option value="<?php echo $op['operation_id']; ?>"
                                <?php if ($op['operation_id'] == 1) echo "selected"; ?>>
                                <?php echo htmlspecialchars($op['operation_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>OIC</label>
                        <input type="text" name="oic" placeholder="Enter Officer In Charge">
                    </div>
                    <div class="field">
                        <label>Case Background</label>
                        <textarea name="description" rows="5" placeholder="Enter case background"></textarea>
                    </div>
                </div>
                <div class="column">
                    <div class="field">
                        <label>Customer</label>
                        <select name="customer">
                            <option value="">Select Customer</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?php echo $c['customer_id']; ?>"><?php echo htmlspecialchars($c['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Lead Force (optional)</label>
                        <select name="lead_force">
                            <option value="">Select Lead Force</option>
                            <?php foreach ($forces as $f): ?>
                            <option value="<?php echo $f['id']; ?>"><?php echo htmlspecialchars($f['force_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Suspect</label>
                        <input type="text" name="suspect" placeholder="Enter suspect name">
                    </div>
                    <div class="field">
                        <label>Status (required, default is Status 1)</label>
                        <select name="status" required>
                            <?php 
                        // Mark status with id 1 as selected by default.
                        ?>
                            <?php foreach ($jobStatuses as $js): ?>
                            <option value="<?php echo $js['status_id']; ?>"
                                <?php if ($js['status_id'] == 1) echo "selected"; ?>>
                                <?php echo htmlspecialchars($js['status_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field checkbox-group">
                        <label><input type="checkbox" name="fingerprints" value="1"> Fingerprints</label>
                        <label><input type="checkbox" name="dna" value="1"> DNA</label>
                        <label><input type="checkbox" name="malware" value="1"> Malware</label>
                    </div>
                </div>
            </div>
            <div class="button-group">
                <input type="submit" value="Create Case">
                <a href="ch_dashboard.php" class="cancel-button" target="_top">Cancel</a>
            </div>
        </form>
    </div>
</body>

</html>