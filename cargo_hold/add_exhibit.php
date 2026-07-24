<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/integrity.php';
require_once '../includes/permissions.php';
require_once '../includes/exhibit_receipts.php';
require_permission($conn, 'exhibit_create');

// Ensure a job_id is provided
if (!isset($_GET['job_id'])) {
    echo "Job ID not specified.";
    exit();
}
$job_id = intval($_GET['job_id']);

// Retrieve the job's custom_ref (optional)
$jobStmt = $conn->prepare("SELECT custom_ref FROM jobs WHERE job_id = ?");
$jobStmt->bind_param("i", $job_id);
$jobStmt->execute();
$jobStmt->bind_result($job_custom_ref);
$jobStmt->fetch();
$jobStmt->close();

// Fetch lookup data for exhibit types
$exhibitTypes = [];
$result = $conn->query("SELECT exhibit_type_id, type_name FROM exhibit_types ORDER BY type_name");
while ($row = $result->fetch_assoc()) {
    $exhibitTypes[] = $row;
}
$result->free();

// Active locations only - edit_exhibit.php still shows inactive ones too.
$locations = [];
$result = $conn->query("SELECT location_id, location_name FROM exhibit_locations WHERE is_active = 1 ORDER BY location_name");
while ($row = $result->fetch_assoc()) {
    $locations[] = $row;
}
$result->free();

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve arrays of inputs
    $exhibit_refs = $_POST['exhibit_ref'];
    $item_descriptions = $_POST['item_description'];
    $exhibit_types = $_POST['exhibit_type'];
    $urgencies = $_POST['urgency'];
    $bag_numbers = $_POST['bag_number'];
    $locations_arr = $_POST['location'];
    
    // Retrieve the common Delivered By value
    $delivered_by = trim($_POST['delivered_by']);
    
    // Checkboxes for receipt and label printing
    $generate_receipt = isset($_POST['generate_receipt']);
    $print_label = isset($_POST['print_label']);
    
    $inserted_ids = [];
    $allSuccess = true;
    
    // Validate delivered_by length
    if (strlen($delivered_by) > 255) {
        $allSuccess = false;
        $message = "Delivered By value exceeds 255 characters.";
    } else {
        // Prepare the insert statement for exhibits, matching table column order
        $stmt = $conn->prepare("
            INSERT INTO exhibits 
            (job_id, barcode, time_in, time_out, exhibit_type_id, bag_number, exhibit_ref, urgency, location_id, delivered_by, item_description, status, created_by)
            VALUES (?, ?, CURRENT_TIMESTAMP, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            $message = "Error preparing exhibit insert: " . $conn->error;
            $allSuccess = false;
        } else {
            // Bind parameters: job_id (i), barcode (s), exhibit_type_id (i), bag_number (s), exhibit_ref (s), urgency (s), location_id (i), delivered_by (s), item_description (s), status (s), created_by (i)
            $typeString = "isisssisssi";
            $barcode = ''; // Default empty barcode
            $status = 'Not Yet Started'; // Default status
            
            for ($i = 0; $i < count($exhibit_refs); $i++) {
                // Force uppercase for exhibit_ref and bag_number
                $exhibit_ref_val = strtoupper(trim($exhibit_refs[$i]));
                $bag_number_val = strtoupper(trim($bag_numbers[$i]));
                $item_desc_val = trim($item_descriptions[$i]);
                $exhibit_type_val = intval($exhibit_types[$i]);
                // Validate urgency
                $urgency_val = in_array($urgencies[$i], ['Low', 'Medium', 'High']) ? $urgencies[$i] : 'Low';
                $location_val = intval($locations_arr[$i]);
                $created_by = intval($_SESSION['user_id']);
                
                // Check required fields
                if (empty($exhibit_type_val) || empty($exhibit_ref_val) || empty($location_val) || empty($delivered_by)) {
                    $allSuccess = false;
                    $message = "Please fill in the required fields (Exhibit Type, Exhibit Ref, Location, Delivered By) for exhibit " . ($i + 1) . ".";
                    break;
                }
                
                // Validate lengths
                if (strlen($exhibit_ref_val) > 50 || strlen($bag_number_val) > 50 || strlen($item_desc_val) > 255) {
                    $allSuccess = false;
                    $message = "Exhibit Ref or Bag Number exceeds 50 characters, or Description exceeds 255 characters for exhibit " . ($i + 1) . ".";
                    break;
                }
                
                // Check for duplicate exhibit_ref within job_id
                $dupCheck = $conn->prepare("SELECT COUNT(*) FROM exhibits WHERE UPPER(exhibit_ref) = ? AND job_id = ?");
                $dupCheck->bind_param("si", $exhibit_ref_val, $job_id);
                $dupCheck->execute();
                $dupCheck->bind_result($count);
                $dupCheck->fetch();
                $dupCheck->close();
                
                if ($count > 0) {
                    $allSuccess = false;
                    $message = "Exhibit reference '$exhibit_ref_val' already exists for this job.";
                    break;
                }
                
                // Bind parameters in exact column order
                $stmt->bind_param($typeString, 
                    $job_id, 
                    $barcode, 
                    $exhibit_type_val, 
                    $bag_number_val, 
                    $exhibit_ref_val, 
                    $urgency_val, 
                    $location_val, 
                    $delivered_by, 
                    $item_desc_val, 
                    $status, 
                    $created_by
                );
                if ($stmt->execute()) {
                    $inserted_id = $conn->insert_id;
                    $inserted_ids[] = $inserted_id;
                    
                    // Look up descriptive values for exhibit type
                    $exhibitTypeName = "";
                    foreach ($exhibitTypes as $et) {
                        if ($et['exhibit_type_id'] == $exhibit_type_val) {
                            $exhibitTypeName = $et['type_name'];
                            break;
                        }
                    }
                    // Look up descriptive value for location
                    $locationName = "";
                    foreach ($locations as $loc) {
                        if ($loc['location_id'] == $location_val) {
                            $locationName = $loc['location_name'];
                            break;
                        }
                    }
                    
                    // Build changes array with descriptive values
                    $changesArray = [
                        "exhibit_ref" => $exhibit_ref_val,
                        "bag_number" => $bag_number_val,
                        "item_description" => $item_desc_val,
                        "exhibit_type" => $exhibitTypeName,
                        "urgency" => $urgency_val,
                        "location" => $locationName,
                        "delivered_by" => $delivered_by
                    ];
                    $changesJSON = json_encode($changesArray);
                    
                    // Insert audit (history) record
                    $historyAction = "BOOK_IN";
                    $changedBy = $_SESSION['user_id'];
                    if (!insert_history_row($conn, 'exhibit_history', $inserted_id, $historyAction, $changedBy, $changesJSON)) {
                        $allSuccess = false;
                        $message = "Error adding history for exhibit ID {$inserted_id}: " . $conn->error;
                        error_log("History insert failed for exhibit ID {$inserted_id}: " . $conn->error);
                        break;
                    }
                } else {
                    $allSuccess = false;
                    $message = "Error adding exhibit '$exhibit_ref_val': " . $stmt->error;
                    error_log("Exhibit insert failed for exhibit_ref '$exhibit_ref_val': " . $stmt->error);
                    break;
                }
            }
            $stmt->close();
        }
    }
    
    if ($allSuccess && !empty($inserted_ids)) {
        require_once '../includes/achievements.php';
        check_and_unlock_achievements($conn, (int) $_SESSION['user_id'], 'exhibits_booked_in');

        if ($generate_receipt) {
            $receiptId = save_exhibit_receipt($conn, $job_id, 'in', $inserted_ids, (int) $_SESSION['user_id']);
            $receiptURL = $receiptId
                ? "view_receipt.php?receipt_id=" . urlencode($receiptId)
                : "exhibit_receipt.php?ids=" . urlencode(implode(',', $inserted_ids)) .
                  "&job_id=" . urlencode($job_id) . "&type=in";
            // A real link avoids popup-blocker issues that window.open() would hit here.
            include '../header.php';
            ?>
            <div class="content-wrapper" style="max-width:500px; margin:150px auto 20px; text-align:center;">
                <h2>Exhibit(s) Booked In</h2>
                <p>The exhibit(s) were added successfully.</p>
                <p>
                    <a href="<?php echo htmlspecialchars($receiptURL); ?>" target="_blank"
                        style="display:inline-block; padding:5px 10px; background:var(--polaris-accent); color:var(--polaris-text); border-radius:3px; font-size:14px; text-decoration:none; margin-bottom:10px;">
                        View / Print Receipt
                    </a>
                </p>
                <p>
                    <a href="job.php?job_id=<?php echo (int) $job_id; ?>"
                        style="display:inline-block; padding:5px 10px; background:var(--polaris-accent); color:var(--polaris-text); border-radius:3px; font-size:14px; text-decoration:none;">
                        Continue to Case
                    </a>
                </p>
            </div>
            </body>
            </html>
            <?php
            exit();
        } else {
            header("Location: job.php?job_id=" . $job_id);
            exit();
        }
    }
}

include '../header.php';
?>

<style>
    /* header.php's own body{} already sets margin/background/color/font-family
       page-wide. This page used to redeclare a second <!DOCTYPE html><html>
       <head> here with its own body{} - browsers tolerate the invalid
       nesting, but that stray rule won the cascade and bled its font into
       the real header/nav above it. The 120px clearance moves onto
       .container's top margin since body no longer carries it. */

    .container {
        max-width: 800px;
        margin: 160px auto 40px auto;
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 5px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
    }

    h2 {
        text-align: center;
        margin-bottom: 20px;
    }

    table {
        width: auto;
        border-collapse: collapse;
        margin-bottom: 20px;
        table-layout: auto;
    }

    table th,
    table td {
        border: 1px solid var(--polaris-border);
        padding: 8px;
        text-align: left;
        font-size: 14px;
        word-break: break-word;
        white-space: nowrap;
    }

    table th {
        background: var(--polaris-divider);
    }

    .add-row {
        margin-bottom: 10px;
        cursor: pointer;
        color: var(--polaris-accent);
        text-decoration: underline;
        display: inline-block;
    }

    .field {
        margin-bottom: 15px;
    }

    label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
        color: var(--polaris-text-dim);
    }

    input[type="text"],
    input[type="number"],
    select {
        width: 100%;
        padding: 8px;
        border: 1px solid var(--polaris-border);
        border-radius: 4px;
        background: var(--polaris-surface-deep);
        color: var(--polaris-text);
    }

    .checkbox-group {
        margin-bottom: 15px;
    }

    .checkbox-group label {
        display: inline-block;
        margin-right: 20px;
    }

    .button-group {
        display: flex;
        gap: 10px;
        margin-top: 20px;
    }

    .button-group button {
        flex: 1;
        padding: 5px 10px;
        font-size: 14px;
        border: none;
        border-radius: 3px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        text-align: center;
        cursor: pointer;
        transition: background 0.3s ease;
    }

    .button-group button:hover {
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
    <script>
    function addExhibitRow() {
        var table = document.getElementById("exhibitsTable");
        var rowCount = table.rows.length;
        var row = table.insertRow(rowCount);

        // Exhibit Ref
        var cell1 = row.insertCell(0);
        var exhibitRef = document.createElement("input");
        exhibitRef.type = "text";
        exhibitRef.name = "exhibit_ref[]";
        exhibitRef.placeholder = "Exhibit Ref";
        exhibitRef.required = true;
        exhibitRef.oninput = function() {
            this.value = this.value.toUpperCase();
        };
        cell1.appendChild(exhibitRef);

        // Description
        var cell2 = row.insertCell(1);
        var desc = document.createElement("input");
        desc.type = "text";
        desc.name = "item_description[]";
        desc.placeholder = "Description";
        cell2.appendChild(desc);

        // Exhibit Type
        var cell3 = row.insertCell(2);
        var exhibitType = document.createElement("select");
        exhibitType.name = "exhibit_type[]";
        exhibitType.required = true;
        var defaultOption = document.createElement("option");
        defaultOption.value = "";
        defaultOption.text = "Select Type";
        exhibitType.appendChild(defaultOption);
        var types = <?php echo json_encode($exhibitTypes); ?>;
        types.forEach(function(type) {
            var opt = document.createElement("option");
            opt.value = type.exhibit_type_id;
            opt.text = type.type_name;
            exhibitType.appendChild(opt);
        });
        cell3.appendChild(exhibitType);

        // Urgency
        var cell4 = row.insertCell(3);
        var urgency = document.createElement("select");
        urgency.name = "urgency[]";
        urgency.required = true;
        var urgencies = ["Low", "Medium", "High"];
        urgencies.forEach(function(val) {
            var opt = document.createElement("option");
            opt.value = val;
            opt.text = val;
            if (val === "Low") opt.selected = true;
            urgency.appendChild(opt);
        });
        cell4.appendChild(urgency);

        // Bag Number
        var cell5 = row.insertCell(4);
        var bagNumber = document.createElement("input");
        bagNumber.type = "text";
        bagNumber.name = "bag_number[]";
        bagNumber.placeholder = "Bag Number";
        bagNumber.required = true;
        bagNumber.oninput = function() {
            this.value = this.value.toUpperCase();
        };
        cell5.appendChild(bagNumber);

        // Location
        var cell6 = row.insertCell(5);
        var locationSelect = document.createElement("select");
        locationSelect.name = "location[]";
        locationSelect.required = true;
        var locs = <?php echo json_encode($locations); ?>;
        if (locs.length > 0) {
            locs.forEach(function(loc, index) {
                var opt = document.createElement("option");
                opt.value = loc.location_id;
                opt.text = loc.location_name;
                if (index === 0) {
                    opt.selected = true;
                }
                locationSelect.appendChild(opt);
            });
        } else {
            var opt = document.createElement("option");
            opt.value = "";
            opt.text = "No locations available";
            locationSelect.appendChild(opt);
        }
        cell6.appendChild(locationSelect);

        // Remove button
        var cell7 = row.insertCell(6);
        var removeBtn = document.createElement("button");
        removeBtn.type = "button";
        removeBtn.textContent = "Remove";
        removeBtn.onclick = function() {
            if (document.getElementById("exhibitsTable").rows.length > 2) {
                this.parentElement.parentElement.remove();
            } else {
                alert("At least one exhibit entry must remain.");
            }
        };
        cell7.appendChild(removeBtn);
    }
    </script>

    <div class="container">
        <h2>Book Exhibit(s)</h2>
        <?php if (!empty($message)): ?>
        <p class="message <?php echo (strpos($message, 'Error') !== false) ? 'error' : 'success'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </p>
        <?php endif; ?>
        <form method="post" action="add_exhibit.php?job_id=<?php echo $job_id; ?>">
            <table id="exhibitsTable">
                <tr>
                    <th>Exhibit Ref</th>
                    <th>Description</th>
                    <th>Exhibit Type</th>
                    <th>Urgency</th>
                    <th>Bag Number</th>
                    <th>Location</th>
                    <th>Remove</th>
                </tr>
                <tr>
                    <td><input type="text" name="exhibit_ref[]" placeholder="Exhibit Ref" required
                            oninput="this.value=this.value.toUpperCase();"></td>
                    <td><input type="text" name="item_description[]" placeholder="Description"></td>
                    <td>
                        <select name="exhibit_type[]" required>
                            <option value="">Select Type</option>
                            <?php foreach ($exhibitTypes as $et): ?>
                            <option value="<?php echo $et['exhibit_type_id']; ?>">
                                <?php echo htmlspecialchars($et['type_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="urgency[]" required>
                            <option value="Low" selected>Low</option>
                            <option value="Medium">Medium</option>
                            <option value="High">High</option>
                        </select>
                    </td>
                    <td><input type="text" name="bag_number[]" placeholder="Bag Number" required
                            oninput="this.value=this.value.toUpperCase();"></td>
                    <td>
                        <select name="location[]" required>
                            <?php if (!empty($locations)): ?>
                            <?php foreach ($locations as $index => $loc): ?>
                            <option value="<?php echo $loc['location_id']; ?>"
                                <?php echo ($index === 0) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($loc['location_name']); ?>
                            </option>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <option value="">No locations available</option>
                            <?php endif; ?>
                        </select>
                    </td>
                    <td>
                        <!-- No remove button for initial row -->
                    </td>
                </tr>
            </table>
            <p class="add-row" onclick="addExhibitRow();">Add another exhibit</p>
            <div class="field">
                <label>Delivered By</label>
                <input type="text" name="delivered_by" placeholder="Enter delivered by" required>
            </div>
            <div class="checkbox-group">
                <label><input type="checkbox" name="generate_receipt" value="1"> Generate Receipt</label>
                <label><input type="checkbox" name="print_label" value="1"> Print Label</label>
            </div>
            <div class="button-group">
                <button type="submit">Book Exhibit(s) In</button>
                <button type="button"
                    onclick="window.location.href='job.php?job_id=<?php echo $job_id; ?>'">Cancel</button>
            </div>
        </form>
    </div>
</body>

</html>