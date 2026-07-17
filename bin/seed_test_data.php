<?php
// bin/seed_test_data.php
//
// CLI-only test data generator - populates a realistic spread of cases,
// exhibits, process templates, tasks, assets, and history/audit trail
// entries. Run from inside the app container:
//
//   docker exec -it polaris_app php bin/seed_test_data.php
//
// Deliberately not reachable over HTTP - see docker/apache-hardening.conf,
// which denies all requests under /bin/, plus the PHP_SAPI check below.
//
// Uses the app's own insert_history_row()/log_audit_event() helpers (not
// raw INSERTs) so the tamper-evident hash/HMAC chains on case_history,
// exhibit_history, exhibit_process_history, and audit_log stay valid -
// Check Database Integrity will pass over this data exactly like real
// data entered through the UI.
//
// Case refs follow the app's real YY + 3-digit-sequence format (see
// cargo_hold/create_case.php) - this script reads the current max per
// year and continues from there, so re-running it is safe (just adds
// more cases) rather than colliding with existing refs.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("This script is CLI-only.\n");
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/integrity.php';
require_once __DIR__ . '/../includes/audit.php';

function out(string $msg): void
{
    fwrite(STDOUT, $msg . "\n");
}

// ---------------------------------------------------------------------
// Lookup data - top up with a few more so dropdowns look realistic
// ---------------------------------------------------------------------

function ensure_lookup(mysqli $conn, string $table, string $col, array $values, ?string $extraCol = null, $extraVal = null): array
{
    $ids = [];
    foreach ($values as $v) {
        $stmt = $conn->prepare("SELECT " . $conn->real_escape_string($table === 'case_types' ? 'case_type_id' : ($table === 'exhibit_types' ? 'exhibit_type_id' : ($table === 'exhibit_locations' ? 'location_id' : ($table === 'customers' ? 'customer_id' : ($table === 'operations' ? 'operation_id' : ($table === 'job_status' ? 'status_id' : 'id')))))) . " AS id FROM `$table` WHERE `$col` = ? LIMIT 1");
        $stmt->bind_param("s", $v);
        $stmt->execute();
        $stmt->bind_result($id);
        if ($stmt->fetch()) {
            $ids[$v] = $id;
            $stmt->close();
            continue;
        }
        $stmt->close();

        if ($extraCol !== null) {
            $ins = $conn->prepare("INSERT INTO `$table` (`$col`, `$extraCol`) VALUES (?, ?)");
            $ins->bind_param("si", $v, $extraVal);
        } else {
            $ins = $conn->prepare("INSERT INTO `$table` (`$col`) VALUES (?)");
            $ins->bind_param("s", $v);
        }
        $ins->execute();
        $ids[$v] = $conn->insert_id;
        $ins->close();
    }
    return $ids;
}

out("Seeding lookup data...");
$caseTypeIds = ensure_lookup($conn, 'case_types', 'type_name', [
    'MOBILE PHONE EXAMINATION', 'COMPUTER EXAMINATION', 'CCTV RECOVERY', 'CLOUD DATA EXTRACTION',
    'RANSOMWARE INVESTIGATION', 'SOCIAL MEDIA EXTRACTION',
]);
$exhibitTypeIds = ensure_lookup($conn, 'exhibit_types', 'type_name', [
    'Mobile Phone', 'Laptop', 'Desktop PC', 'USB Storage', 'SD Card', 'Hard Drive', 'Tablet', 'Smart Watch',
]);
$locationIds = ensure_lookup($conn, 'exhibit_locations', 'location_name', [
    'Evidence Locker A', 'Evidence Locker B', 'Lab Bench 1', 'Lab Bench 2', 'Cold Storage', 'Overflow Store',
]);
$forceIds = ensure_lookup($conn, 'forces', 'force_name', [
    'Riverside Police', 'Northgate Constabulary', 'Eastdale Constabulary',
]);
$operationIds = ensure_lookup($conn, 'operations', 'operation_name', [
    'Operation Riverbank', 'Operation Foxglove', 'Operation Harbour', 'Operation Lighthouse', 'Operation Cascade',
]);
$customerIds = ensure_lookup($conn, 'customers', 'name', [
    'Alex Turner', 'Sam Patel', 'Morgan Reid', 'Jamie Osei',
]);
$statusIds = ensure_lookup($conn, 'job_status', 'status_name', [
    'Awaiting Triage', 'In Progress', 'Awaiting Review', 'Complete',
]);
$assetTypeIds = ensure_lookup($conn, 'asset_types', 'type_name', [
    'Write Blocker', 'Imaging Rig', 'Forensic Laptop', 'Camera', 'Storage Drive',
]);
$assetLocationIds = ensure_lookup($conn, 'asset_locations', 'location_name', [
    'Lab Cabinet 1', 'Lab Cabinet 2', 'Store Room',
]);

// ---------------------------------------------------------------------
// Users
// ---------------------------------------------------------------------

out("Seeding users...");
$newUsers = [
    ['Sarah', 'Mitchell', 'sarah.mitchell@example.test', 'user'],
    ['Tom', 'Reilly', 'tom.reilly@example.test', 'user'],
    ['Priya', 'Nair', 'priya.nair@example.test', 'admin'],
    ['Mike', 'Chen', 'mike.chen@example.test', 'user'],
];
$userIds = [];
$existingUsersRes = $conn->query("SELECT id, email FROM users");
while ($row = $existingUsersRes->fetch_assoc()) {
    $userIds[$row['email']] = (int) $row['id'];
}
$defaultHash = password_hash('Password1!', PASSWORD_DEFAULT);
foreach ($newUsers as [$first, $last, $email, $role]) {
    if (isset($userIds[$email])) {
        continue;
    }
    $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, password, role, active) VALUES (?, ?, ?, ?, ?, 1)");
    $stmt->bind_param("sssss", $first, $last, $email, $defaultHash, $role);
    $stmt->execute();
    $userIds[$email] = $conn->insert_id;
    $stmt->close();
}
$allUserIds = array_values($userIds);
$adminUserId = $userIds['admin@example.test'] ?? $allUserIds[0];

// ---------------------------------------------------------------------
// Process Builder templates ("processing templates")
// ---------------------------------------------------------------------

out("Seeding process templates...");

function ensure_process_type(mysqli $conn, string $name, string $description, int $createdBy): int
{
    $stmt = $conn->prepare("SELECT id FROM process_types WHERE name = ? LIMIT 1");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $stmt->bind_result($id);
    if ($stmt->fetch()) {
        $stmt->close();
        return $id;
    }
    $stmt->close();

    $ins = $conn->prepare("INSERT INTO process_types (name, description, is_active, created_by) VALUES (?, ?, 1, ?)");
    $ins->bind_param("ssi", $name, $description, $createdBy);
    $ins->execute();
    $id = $conn->insert_id;
    $ins->close();
    return $id;
}

function ensure_process_field(mysqli $conn, int $typeId, string $label, string $key, string $fieldType, ?string $lookupSource, bool $required, int $sortOrder): int
{
    $stmt = $conn->prepare("SELECT id FROM process_fields WHERE process_type_id = ? AND field_key = ? LIMIT 1");
    $stmt->bind_param("is", $typeId, $key);
    $stmt->execute();
    $stmt->bind_result($id);
    if ($stmt->fetch()) {
        $stmt->close();
        return $id;
    }
    $stmt->close();

    $req = $required ? 1 : 0;
    $ins = $conn->prepare("INSERT INTO process_fields (process_type_id, field_label, field_key, field_type, lookup_source, is_required, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $ins->bind_param("isssssi", $typeId, $label, $key, $fieldType, $lookupSource, $req, $sortOrder);
    $ins->execute();
    $id = $conn->insert_id;
    $ins->close();
    return $id;
}

$mobileTypeId = ensure_process_type($conn, 'Mobile Phone Triage', 'Initial triage and extraction details for mobile devices.', $adminUserId);
$mobileFields = [
    'device_make_model' => ensure_process_field($conn, $mobileTypeId, 'Device Make / Model', 'device_make_model', 'text', null, true, 1),
    'imei' => ensure_process_field($conn, $mobileTypeId, 'IMEI', 'imei', 'text', null, true, 2),
    'pin_passcode' => ensure_process_field($conn, $mobileTypeId, 'PIN / Passcode', 'pin_passcode', 'text', null, false, 3),
    'storage_capacity_gb' => ensure_process_field($conn, $mobileTypeId, 'Storage Capacity (GB)', 'storage_capacity_gb', 'number', null, false, 4),
    'extraction_tool' => ensure_process_field($conn, $mobileTypeId, 'Extraction Tool', 'extraction_tool', 'lookup', 'assets', false, 5),
    'extraction_date' => ensure_process_field($conn, $mobileTypeId, 'Extraction Date', 'extraction_date', 'date', null, false, 6),
];

$computerTypeId = ensure_process_type($conn, 'Computer Forensic Examination', 'Disk imaging and analysis details for computers.', $adminUserId);
$computerFields = [
    'operating_system' => ensure_process_field($conn, $computerTypeId, 'Operating System', 'operating_system', 'text', null, true, 1),
    'hard_drive_size' => ensure_process_field($conn, $computerTypeId, 'Hard Drive Size', 'hard_drive_size', 'text', null, false, 2),
    'encryption_present' => ensure_process_field($conn, $computerTypeId, 'Encryption Present', 'encryption_present', 'text', null, false, 3),
    'imaging_tool' => ensure_process_field($conn, $computerTypeId, 'Imaging Tool', 'imaging_tool', 'lookup', 'assets', false, 4),
    'analysis_notes' => ensure_process_field($conn, $computerTypeId, 'Analysis Notes', 'analysis_notes', 'textarea', null, false, 5),
];

$cctvTypeId = ensure_process_type($conn, 'CCTV Recovery', 'Recovery details for CCTV/DVR footage.', $adminUserId);
$cctvFields = [
    'dvr_model' => ensure_process_field($conn, $cctvTypeId, 'Camera System / DVR Model', 'dvr_model', 'text', null, true, 1),
    'footage_date_range' => ensure_process_field($conn, $cctvTypeId, 'Footage Date Range', 'footage_date_range', 'text', null, true, 2),
    'recovery_method' => ensure_process_field($conn, $cctvTypeId, 'Recovery Method', 'recovery_method', 'textarea', null, false, 3),
    'recovered_by' => ensure_process_field($conn, $cctvTypeId, 'Recovered By', 'recovered_by', 'lookup', 'users', false, 4),
];

// ---------------------------------------------------------------------
// Assets (Logistics Hub)
// ---------------------------------------------------------------------

out("Seeding assets...");
$assetTypeNames = array_keys($assetTypeIds);
$assetLocationNames = array_keys($assetLocationIds);
$newAssets = [
    ['Tableau T356789', 'Write Blocker', 'Available'],
    ['UFED Touch2', 'Imaging Rig', 'Available'],
    ['Cellebrite Premium Laptop', 'Forensic Laptop', 'Available'],
    ['Axiom Workstation 1', 'Forensic Laptop', 'Deployed'],
    ['DVR Recovery Kit', 'Camera', 'Available'],
    ['4TB Evidence Drive #12', 'Storage Drive', 'Available'],
];
foreach ($newAssets as [$friendly, $typeName, $availability]) {
    $exists = $conn->query("SELECT id FROM assets WHERE friendly_name = " . "'" . $conn->real_escape_string($friendly) . "'");
    if ($exists && $exists->num_rows > 0) {
        continue;
    }
    $res = $conn->query("SELECT MAX(id) AS max_id FROM assets");
    $row = $res->fetch_assoc();
    $nextId = $row ? ((int) $row['max_id'] + 1) : 1;
    $assetNumber = 'AS-' . str_pad((string) $nextId, 5, '0', STR_PAD_LEFT);
    $locationName = $assetLocationNames[array_rand($assetLocationNames)];

    $stmt = $conn->prepare("INSERT INTO assets (asset_number, friendly_name, asset_type, location, availability) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $assetNumber, $friendly, $typeName, $locationName, $availability);
    $stmt->execute();
    $newAssetId = $conn->insert_id;
    $stmt->close();
    log_audit_event($conn, 'asset', $newAssetId, 'CREATE', $adminUserId, json_encode(['asset_number' => $assetNumber, 'friendly_name' => $friendly]));
}

// ---------------------------------------------------------------------
// Cases + exhibits
// ---------------------------------------------------------------------

out("Seeding cases and exhibits...");

function next_custom_ref(mysqli $conn, string $yearPrefix): string
{
    $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING(custom_ref, 3) AS UNSIGNED)) AS last_ref FROM jobs WHERE custom_ref LIKE ?");
    $like = $yearPrefix . '%';
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $stmt->bind_result($lastRef);
    $stmt->fetch();
    $stmt->close();
    return is_null($lastRef)
        ? $yearPrefix . '000'
        : $yearPrefix . str_pad((string) ($lastRef + 1), 3, '0', STR_PAD_LEFT);
}

$oics = ['DC Harris', 'DC Whitfield', 'DS Ahmed', 'DC Bello', 'DS Marchetti'];
$suspects = ['John Doe', 'Unknown male, 30s', 'Sarah Blackwood', '', 'Unidentified', 'Kevin Lang'];
$summaries = [
    'Device seized during warrant execution, requires full extraction.',
    'Suspected malware infection reported by IT department, drive imaged for analysis.',
    'CCTV footage requested covering incident on site premises.',
    'Cloud account data requested under production order.',
    'Device recovered from suspect during arrest, triage requested.',
    'Ransomware incident - client systems encrypted, investigating entry vector.',
    'Social media account activity relevant to ongoing harassment investigation.',
    'Device submitted by victim for evidence of threatening messages.',
];

$caseTypeNames = array_keys($caseTypeIds);
$customerNames = array_keys($customerIds);
$forceNames = array_keys($forceIds);
$operationNames = array_keys($operationIds);
$statusNames = array_keys($statusIds);
$exhibitTypeNames = array_keys($exhibitTypeIds);
$locationNames = array_keys($locationIds);

$slaDays = 90;
$exhibitRefCounterRes = $conn->query("SELECT COUNT(*) AS c FROM exhibits");
$exhibitCounter = (int) $exhibitRefCounterRes->fetch_assoc()['c'];

$yearBatches = [
    ['prefix' => date('y', strtotime('-1 year')), 'count' => 8, 'daysAgoRange' => [200, 400]],
    ['prefix' => date('y'), 'count' => 16, 'daysAgoRange' => [1, 180]],
];

$createdCaseIds = [];
$createdExhibitIds = [];

foreach ($yearBatches as $batch) {
    for ($i = 0; $i < $batch['count']; $i++) {
        $customRef = next_custom_ref($conn, $batch['prefix']);
        $caseTypeName = $caseTypeNames[array_rand($caseTypeNames)];
        $customerName = $customerNames[array_rand($customerNames)];
        $forceName = $forceNames[array_rand($forceNames)];
        $operationName = $operationNames[array_rand($operationNames)];
        $statusName = $statusNames[array_rand($statusNames)];
        $oic = $oics[array_rand($oics)];
        $suspect = $suspects[array_rand($suspects)];
        $summary = $summaries[array_rand($summaries)];
        $creatorId = $allUserIds[array_rand($allUserIds)];
        $fingerprints = random_int(0, 1);
        $dna = random_int(0, 1);
        $malware = random_int(0, 1);

        $daysAgo = random_int($batch['daysAgoRange'][0], $batch['daysAgoRange'][1]);
        $strategySet = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));
        $strategyDue = date('Y-m-d H:i:s', strtotime($strategySet . " +{$slaDays} days"));

        $stmt = $conn->prepare("INSERT INTO jobs
            (custom_ref, created_by, initial_summary, oic, operation, customer_id, lead_force_id, suspect, fingerprints, dna, status_id, malware, strategy_set, strategy_due, case_type_id, date_time)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $operationId = $operationIds[$operationName];
        $customerId = $customerIds[$customerName];
        $leadForceId = $forceIds[$forceName];
        $statusId = $statusIds[$statusName];
        $caseTypeId = $caseTypeIds[$caseTypeName];
        $typeString = "sissiiisiiiissi" . "s";
        $stmt->bind_param(
            "sissiiisiiiissis",
            $customRef, $creatorId, $summary, $oic, $operationId, $customerId, $leadForceId,
            $suspect, $fingerprints, $dna, $statusId, $malware, $strategySet, $strategyDue, $caseTypeId, $strategySet
        );
        $stmt->execute();
        $jobId = $conn->insert_id;
        $stmt->close();

        insert_history_row($conn, 'case_history', $jobId, 'CREATE', $creatorId, json_encode([
            'custom_ref' => $customRef, 'case_type' => $caseTypeName, 'oic' => $oic,
        ]));
        $createdCaseIds[] = ['job_id' => $jobId, 'custom_ref' => $customRef, 'case_type' => $caseTypeName];

        // A couple of case updates for realism.
        if (random_int(0, 2) === 0) {
            $updateText = "Strategy reviewed, awaiting further instructions from OIC.";
            $updStmt = $conn->prepare("INSERT INTO case_updates (job_id, user_id, update_text) VALUES (?, ?, ?)");
            $updStmt->bind_param("iis", $jobId, $creatorId, $updateText);
            $updStmt->execute();
            $updStmt->close();
        }

        // 1-4 exhibits per case.
        $exhibitCount = random_int(1, 4);
        for ($e = 0; $e < $exhibitCount; $e++) {
            $exhibitCounter++;
            $exhibitRef = 'EX' . str_pad((string) $exhibitCounter, 3, '0', STR_PAD_LEFT);
            $exhibitTypeName = $exhibitTypeNames[array_rand($exhibitTypeNames)];
            $locationName = $locationNames[array_rand($locationNames)];
            $urgency = ['Low', 'Medium', 'High'][array_rand(['Low', 'Medium', 'High'])];
            $status = ['Not Yet Started', 'Imaging', 'Imaged', 'Being Analysed', 'On Hold', 'Complete'][array_rand(['Not Yet Started', 'Imaging', 'Imaged', 'Being Analysed', 'On Hold', 'Complete'])];
            $itemDescriptions = [
                'Mobile Phone' => ['iPhone 13, blue, cracked screen', 'Samsung Galaxy S22, black', 'Nokia 3310, no case'],
                'Laptop' => ['Dell Latitude 5420', 'MacBook Pro 13"', 'HP EliteBook 840'],
                'Desktop PC' => ['Custom-built tower PC', 'Dell OptiPlex 7080'],
                'USB Storage' => ['SanDisk 32GB USB drive', 'Kingston 64GB USB drive'],
                'SD Card' => ['32GB SD card, unlabelled', 'SanDisk Extreme 128GB SD card'],
                'Hard Drive' => ['Seagate 1TB external HDD', 'WD My Passport 2TB'],
                'Tablet' => ['iPad Air, silver', 'Samsung Galaxy Tab S8'],
                'Smart Watch' => ['Apple Watch Series 8', 'Garmin Fenix 6'],
            ];
            $descPool = $itemDescriptions[$exhibitTypeName] ?? ['Item recovered from scene'];
            $itemDescription = $descPool[array_rand($descPool)];
            $deliveredBy = $oic;
            $allocatedTo = $allUserIds[array_rand($allUserIds)];
            $bagNumber = 'BAG' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);

            $exStmt = $conn->prepare("
                INSERT INTO exhibits
                (job_id, barcode, time_in, time_out, exhibit_type_id, bag_number, exhibit_ref, urgency, location_id, delivered_by, item_description, status, created_by, allocated_to)
                VALUES (?, '', ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $exhibitTypeId = $exhibitTypeIds[$exhibitTypeName];
            $exhibitLocationId = $locationIds[$locationName];
            $timeIn = date('Y-m-d H:i:s', strtotime($strategySet . ' +1 hour'));
            $exStmt->bind_param(
                "isisssisssii",
                $jobId, $timeIn, $exhibitTypeId, $bagNumber, $exhibitRef, $urgency, $exhibitLocationId,
                $deliveredBy, $itemDescription, $status, $creatorId, $allocatedTo
            );
            $exStmt->execute();
            $exhibitId = $conn->insert_id;
            $exStmt->close();

            insert_history_row($conn, 'exhibit_history', $exhibitId, 'BOOK_IN', $creatorId, json_encode([
                'exhibit_ref' => $exhibitRef, 'exhibit_type' => $exhibitTypeName, 'location' => $locationName,
            ]));
            $createdExhibitIds[] = ['exhibit_id' => $exhibitId, 'exhibit_type' => $exhibitTypeName, 'job_id' => $jobId];
        }
    }
}
out("Created " . count($createdCaseIds) . " cases and " . count($createdExhibitIds) . " exhibits.");

// ---------------------------------------------------------------------
// Exhibit processes - fill in some real examples against the templates
// ---------------------------------------------------------------------

out("Seeding exhibit process examples...");

function fill_exhibit_process(mysqli $conn, int $exhibitId, int $processTypeId, array $fieldIds, array $values, int $userId): void
{
    $stmt = $conn->prepare("INSERT INTO exhibit_processes (exhibit_id, process_type_id, created_by, updated_by) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiii", $exhibitId, $processTypeId, $userId, $userId);
    $stmt->execute();
    $epId = $conn->insert_id;
    $stmt->close();

    foreach ($values as $key => $value) {
        if (!isset($fieldIds[$key]) || $value === null) {
            continue;
        }
        $fieldId = $fieldIds[$key];
        $vStmt = $conn->prepare("INSERT INTO exhibit_process_values (exhibit_process_id, process_field_id, value) VALUES (?, ?, ?)");
        $vStmt->bind_param("iis", $epId, $fieldId, $value);
        $vStmt->execute();
        $vStmt->close();
    }

    insert_history_row($conn, 'exhibit_process_history', $epId, 'CREATE', $userId, json_encode($values));
}

$assetNamesRes = $conn->query("SELECT friendly_name FROM assets WHERE availability != 'Destroyed'");
$assetNames = [];
while ($row = $assetNamesRes->fetch_assoc()) {
    $assetNames[] = $row['friendly_name'];
}
$userNamesRes = $conn->query("SELECT CONCAT(first_name, ' ', last_name) AS n FROM users WHERE is_active = 1");
$userNames = [];
while ($row = $userNamesRes->fetch_assoc()) {
    $userNames[] = $row['n'];
}

$phoneModels = ['Apple iPhone 13', 'Samsung Galaxy S22', 'Google Pixel 6'];
$osList = ['Windows 11 Pro', 'Windows 10 Home', 'macOS Ventura', 'Ubuntu 22.04'];
$processedCount = 0;
foreach ($createdExhibitIds as $ex) {
    $actor = $allUserIds[array_rand($allUserIds)];
    if ($ex['exhibit_type'] === 'Mobile Phone' && random_int(0, 1) === 0) {
        fill_exhibit_process($conn, $ex['exhibit_id'], $mobileTypeId, $mobileFields, [
            'device_make_model' => $phoneModels[array_rand($phoneModels)],
            'imei' => (string) random_int(100000000000000, 999999999999999),
            'pin_passcode' => (string) random_int(1000, 9999),
            'storage_capacity_gb' => (string) [64, 128, 256][array_rand([64, 128, 256])],
            'extraction_tool' => $assetNames ? $assetNames[array_rand($assetNames)] : null,
            'extraction_date' => date('Y-m-d', strtotime('-' . random_int(1, 60) . ' days')),
        ], $actor);
        $processedCount++;
    } elseif (in_array($ex['exhibit_type'], ['Laptop', 'Desktop PC'], true) && random_int(0, 1) === 0) {
        fill_exhibit_process($conn, $ex['exhibit_id'], $computerTypeId, $computerFields, [
            'operating_system' => $osList[array_rand($osList)],
            'hard_drive_size' => ['256GB SSD', '512GB SSD', '1TB HDD'][array_rand(['256GB SSD', '512GB SSD', '1TB HDD'])],
            'encryption_present' => ['None', 'BitLocker', 'FileVault'][array_rand(['None', 'BitLocker', 'FileVault'])],
            'imaging_tool' => $assetNames ? $assetNames[array_rand($assetNames)] : null,
            'analysis_notes' => 'Initial triage complete, no obvious signs of tampering. Full analysis pending.',
        ], $actor);
        $processedCount++;
    }
}
out("Filled in $processedCount exhibit process examples.");

// ---------------------------------------------------------------------
// Tasks + notifications
// ---------------------------------------------------------------------

out("Seeding tasks and notifications...");

$taskDescriptions = [
    'Complete initial triage and log findings.',
    'Cross-reference extracted data against known suspect numbers.',
    'Prepare exhibit for court disclosure.',
    'Chase up outstanding production order with provider.',
    'Review footage for relevant timestamps.',
    'Draft summary report for OIC.',
];
$existingTaskRes = $conn->query("SELECT MAX(id) AS max_id FROM tasks");
$taskRow = $existingTaskRes->fetch_assoc();
$nextTaskId = $taskRow['max_id'] ? ((int) $taskRow['max_id'] + 1) : 1;

$taskCount = 0;
foreach (array_slice($createdCaseIds, 0, 12) as $case) {
    $assignedTo = $allUserIds[array_rand($allUserIds)];
    $taskRef = 'T' . str_pad((string) $nextTaskId, 4, '0', STR_PAD_LEFT);
    $nextTaskId++;
    $description = $taskDescriptions[array_rand($taskDescriptions)];
    $status = ['not_started', 'in_progress', 'completed'][array_rand(['not_started', 'in_progress', 'completed'])];

    $stmt = $conn->prepare("INSERT INTO tasks (task_ref, custom_ref, job_id, assigned_to, description, status) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssiiss", $taskRef, $case['custom_ref'], $case['job_id'], $assignedTo, $description, $status);
    $stmt->execute();
    $taskId = $conn->insert_id;
    $stmt->close();
    $taskCount++;

    log_audit_event($conn, 'task', $taskId, 'CREATE', $adminUserId, json_encode(['task_ref' => $taskRef, 'custom_ref' => $case['custom_ref'], 'assigned_to' => $assignedTo]));

    $notifMessage = "New Task Assigned: " . $taskRef;
    $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'task_assigned', ?)");
    $notifStmt->bind_param("is", $assignedTo, $notifMessage);
    $notifStmt->execute();
    $notifStmt->close();
}
out("Created $taskCount tasks with notifications.");

out("");
out("Done. Summary:");
out("  Users:            " . count($allUserIds));
out("  Process templates: 3 (Mobile Phone Triage, Computer Forensic Examination, CCTV Recovery)");
out("  Cases created:     " . count($createdCaseIds));
out("  Exhibits created:  " . count($createdExhibitIds));
out("  Exhibit processes: $processedCount");
out("  Tasks created:      $taskCount");
out("  Assets:             " . count($newAssets) . " (skipped if already present)");
