<?php
// bin/seed_demo_data.php
//
// CLI-only. Populates lookup data (if empty) plus a handful of realistic
// cases, exhibits, examinations, produced items, case updates, and
// Spaceport submissions so the app can be clicked through end-to-end on a
// fresh install, instead of starting from an empty shell.
//
// Run from inside the app container:
//   docker exec -it polaris_app php bin/seed_demo_data.php
//
// Safe to re-run - lookup tables are only seeded if empty, and demo
// cases/exhibits are only created if no case with the demo custom_ref
// prefix already exists.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("This script is CLI-only.\n");
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/integrity.php';
require_once __DIR__ . '/../includes/spaceport.php';

// Case-insensitive find-or-create for a single named lookup row - robust
// against a test DB that already has some lookup data under different
// casing (e.g. from manual testing), unlike a plain array-key lookup.
function get_or_create(mysqli $conn, string $table, string $idCol, string $nameCol, string $name): int
{
    $stmt = $conn->prepare("SELECT $idCol FROM $table WHERE UPPER($nameCol) = UPPER(?) LIMIT 1");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        return (int) $row[$idCol];
    }
    $stmt = $conn->prepare("INSERT INTO $table ($nameCol) VALUES (?)");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();
    return $id;
}

function seed_if_empty(mysqli $conn, string $table, string $insertSql): void
{
    $result = $conn->query("SELECT COUNT(*) AS c FROM `$table`");
    $row = $result->fetch_assoc();
    if ((int) $row['c'] > 0) {
        echo "  - $table already has data, skipping.\n";
        return;
    }
    $conn->query($insertSql);
    echo "  - seeded $table\n";
}

echo "Seeding lookup data...\n";
seed_if_empty($conn, 'case_types', "INSERT INTO case_types (type_name) VALUES ('DIGITAL FORENSICS'), ('MOBILE PHONE EXAMINATION'), ('CYBER CRIME')");
seed_if_empty($conn, 'job_status', "INSERT INTO job_status (status_name) VALUES ('Awaiting Triage'), ('In Progress'), ('Completed'), ('On Hold')");
seed_if_empty($conn, 'operations', "INSERT INTO operations (operation_name) VALUES ('Operation Riverbank'), ('Operation Compass')");
seed_if_empty($conn, 'forces', "INSERT INTO forces (force_name) VALUES ('Anytown Police'), ('Riverside Constabulary')");
seed_if_empty($conn, 'customers', "INSERT INTO customers (name, email, organisation) VALUES ('DI Sarah Hughes', 'shughes@anytownpolice.example', 'Anytown Police')");
seed_if_empty($conn, 'exhibit_types', "INSERT INTO exhibit_types (type_name) VALUES ('Mobile Phone'), ('Laptop'), ('USB Drive'), ('SIM Card')");
seed_if_empty($conn, 'exhibit_locations', "INSERT INTO exhibit_locations (location_name, is_active) VALUES ('Evidence Safe 1', 1), ('Forensic Lab Bench 3', 1), ('Secure Storage Room', 1)");
seed_if_empty($conn, 'process_types', "INSERT INTO process_types (name, description) VALUES ('Pre-Imaging Triage', 'Initial assessment before imaging'), ('Full Forensic Imaging', 'Bit-for-bit forensic image acquisition'), ('Data Extraction Review', 'Review of extracted data against strategy')");
seed_if_empty($conn, 'external_contacts', "INSERT INTO external_contacts (name, role_title, phone, email) VALUES ('DI Sarah Hughes', 'Investigating Officer', '01234 567890', 'shughes@anytownpolice.example'), ('DC Mark Ellis', 'Deputy SIO', '01234 567891', 'mellis@anytownpolice.example')");
seed_if_empty($conn, 'submission_question_fields', "INSERT INTO submission_question_fields (field_label, field_key, field_type, is_required, sort_order) VALUES
    ('Court Deadline Confirmed?', 'court_deadline_confirmed', 'checkbox', 0, 10),
    ('Legal Aid / Case Reference', 'legal_aid_reference', 'text', 0, 20),
    ('Additional Notes for the Lab', 'additional_notes_for_lab', 'textarea', 0, 30)");

$superId = null;
$res = $conn->query("SELECT id FROM users WHERE role = 'super' ORDER BY id LIMIT 1");
if ($row = $res->fetch_assoc()) {
    $superId = (int) $row['id'];
}
if (!$superId) {
    die("No super user found - run setup.php first.\n");
}

$officerId = null;
$res = $conn->query("SELECT id FROM users WHERE role = 'submitting_officer' ORDER BY id LIMIT 1");
if ($row = $res->fetch_assoc()) {
    $officerId = (int) $row['id'];
}

// Bail out early if demo data already exists (idempotency).
$existing = $conn->query("SELECT COUNT(*) AS c FROM jobs WHERE custom_ref LIKE 'DM%'")->fetch_assoc();
if ((int) $existing['c'] > 0) {
    echo "Demo cases already exist (custom_ref DM%) - skipping case/exhibit/examination seed.\n";
    exit(0);
}

echo "Fetching lookup ids...\n";
$caseTypeId = $conn->query("SELECT case_type_id FROM case_types ORDER BY case_type_id LIMIT 1")->fetch_assoc()['case_type_id'];
$statusIds = [];
$res = $conn->query("SELECT status_id, status_name FROM job_status ORDER BY status_id");
while ($row = $res->fetch_assoc()) { $statusIds[$row['status_name']] = $row['status_id']; }
$operationId = $conn->query("SELECT operation_id FROM operations ORDER BY operation_id LIMIT 1")->fetch_assoc()['operation_id'];
$customerId = $conn->query("SELECT customer_id FROM customers ORDER BY customer_id LIMIT 1")->fetch_assoc()['customer_id'];
$forceId = $conn->query("SELECT id FROM forces ORDER BY id LIMIT 1")->fetch_assoc()['id'];
$mobileTypeId = get_or_create($conn, 'exhibit_types', 'exhibit_type_id', 'type_name', 'Mobile Phone');
$laptopTypeId = get_or_create($conn, 'exhibit_types', 'exhibit_type_id', 'type_name', 'Laptop');
$usbTypeId = get_or_create($conn, 'exhibit_types', 'exhibit_type_id', 'type_name', 'USB Drive');
$locationId = $conn->query("SELECT location_id FROM exhibit_locations ORDER BY location_id LIMIT 1")->fetch_assoc()['location_id'];
$preImagingId = get_or_create($conn, 'process_types', 'id', 'name', 'Pre-Imaging Triage');
$fullImagingId = get_or_create($conn, 'process_types', 'id', 'name', 'Full Forensic Imaging');
$caseItemTypeId = $conn->query("SELECT type_id FROM case_item_types ORDER BY type_id LIMIT 1")->fetch_assoc()['type_id'];
$questionFieldIds = [];
$res = $conn->query("SELECT field_id, field_key FROM submission_question_fields");
while ($row = $res->fetch_assoc()) { $questionFieldIds[$row['field_key']] = $row['field_id']; }

echo "Creating demo cases...\n";

// --- Case 1: in-progress case with two booked-in exhibits, examinations,
// a produced item, and case updates (including a communication).
$ref1 = 'DM' . date('y') . '01';
$stmt = $conn->prepare("INSERT INTO jobs (custom_ref, created_by, initial_summary, oic, operation, customer_id, lead_force_id, suspect, fingerprints, dna, status_id, malware, strategy_set, strategy_due, case_type_id, incident_number, external_reference) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, 0, NOW(), DATE_ADD(NOW(), INTERVAL 90 DAY), ?, ?, ?)");
$summary1 = 'Suspect device seized during warranted search. Digital forensic examination requested to establish evidence of communication with co-accused.';
$oic1 = 'DI Sarah Hughes';
$suspect1 = 'John Smith';
$statusInProgress = $statusIds['In Progress'] ?? array_values($statusIds)[0];
$incident1 = 'INC-2026-1042';
$extRef1 = '';
$stmt->bind_param("sissiiisiiss", $ref1, $superId, $summary1, $oic1, $operationId, $customerId, $forceId, $suspect1, $statusInProgress, $caseTypeId, $incident1, $extRef1);
$stmt->execute();
$job1 = $conn->insert_id;
$stmt->close();
echo "  - case $ref1 (job_id $job1)\n";

$exhibits1 = [
    ['ref' => 'DM1-001', 'desc' => 'iPhone 13, black, cracked screen', 'type_id' => $mobileTypeId, 'bag' => 'BAG-1001'],
    ['ref' => 'DM1-002', 'desc' => 'Dell Latitude laptop', 'type_id' => $laptopTypeId, 'bag' => 'BAG-1002'],
];
$exhibitIds1 = [];
foreach ($exhibits1 as $ex) {
    $stmt = $conn->prepare("INSERT INTO exhibits (job_id, barcode, time_in, time_out, exhibit_type_id, bag_number, exhibit_ref, urgency, location_id, delivered_by, item_description, status, created_by) VALUES (?, '', NOW(), NULL, ?, ?, ?, 'Medium', ?, 'PC Davies', ?, 'Being Analysed', ?)");
    $stmt->bind_param("iissisi", $job1, $ex['type_id'], $ex['bag'], $ex['ref'], $locationId, $ex['desc'], $superId);
    if (!$stmt->execute()) {
        echo "  ! exhibit insert failed for {$ex['ref']}: {$stmt->error}\n";
        $stmt->close();
        continue;
    }
    $exId = $conn->insert_id;
    $exhibitIds1[] = $exId;
    $stmt->close();
    insert_history_row($conn, 'exhibit_history', $exId, 'BOOK_IN', $superId, json_encode(['exhibit_ref' => $ex['ref'], 'item_description' => $ex['desc']]));
}
echo "  - " . count($exhibitIds1) . " exhibits booked in\n";

// Examinations on the phone (skipped if the exhibit insert above failed).
if (!empty($exhibitIds1)) {
    $examStmt = $conn->prepare("INSERT INTO exhibit_processes (exhibit_id, process_type_id, free_text, created_by) VALUES (?, ?, ?, ?)");
    $notes1 = 'Device powered on, no PIN lock. Battery at 60%. Proceeding to full forensic imaging.';
    $examStmt->bind_param("iisi", $exhibitIds1[0], $preImagingId, $notes1, $superId);
    $examStmt->execute();
    $proc1 = $conn->insert_id;
    insert_history_row($conn, 'exhibit_process_history', $proc1, 'CREATE', $superId, json_encode(['free_text' => $notes1]));

    $notes2 = 'Full logical image acquired via Cellebrite UFED. Hash verified, matches source.';
    $examStmt->bind_param("iisi", $exhibitIds1[0], $fullImagingId, $notes2, $superId);
    $examStmt->execute();
    $proc2 = $conn->insert_id;
    insert_history_row($conn, 'exhibit_process_history', $proc2, 'CREATE', $superId, json_encode(['free_text' => $notes2]));
    $examStmt->close();
    echo "  - 2 examination records added\n";

    // A produced item derived from that exhibit.
    $stmt = $conn->prepare("INSERT INTO case_items (job_id, type_id, source_exhibit_id, item_ref, description, status, notes, file_count, created_on, created_by, assigned_to) VALUES (?, ?, ?, ?, ?, 'Reviewed', ?, ?, CURDATE(), ?, ?)");
    $itemRef1 = 'DM1-EXP01';
    $itemDesc1 = 'Extracted call log and messages (PDF export)';
    $itemNotes1 = 'Reviewed against strategy - relevant messages flagged for statement.';
    $fileCount1 = 1;
    $stmt->bind_param("iiisssiii", $job1, $caseItemTypeId, $exhibitIds1[0], $itemRef1, $itemDesc1, $itemNotes1, $fileCount1, $superId, $superId);
    $stmt->execute();
    $item1 = $conn->insert_id;
    $stmt->close();
    insert_history_row($conn, 'case_item_history', $item1, 'CREATE', $superId, json_encode(['item_ref' => $itemRef1, 'type' => 'Produced Item']));
    echo "  - produced item $itemRef1\n";
}

// Case updates: one plain, one Communication.
$stmt = $conn->prepare("INSERT INTO case_updates (job_id, user_id, update_type, update_text, update_date) VALUES (?, ?, 'Case Update', ?, NOW())");
$update1 = 'Imaging complete on iPhone 13. Beginning analysis against strategy - looking for communications with co-accused.';
$stmt->bind_param("iis", $job1, $superId, $update1);
$stmt->execute();
insert_history_row($conn, 'case_history', $job1, 'CASE_UPDATE_ADDED', $superId, json_encode(['Text' => $update1]));
$stmt->close();

$stmt = $conn->prepare("INSERT INTO case_updates (job_id, user_id, update_type, comm_type, comm_person, update_text, update_date) VALUES (?, ?, 'Communication', 'Phone', 'DI Sarah Hughes', ?, NOW())");
$update2 = 'Called OIC to confirm strategy still stands re: message threads after 1 March. Confirmed - proceed as planned.';
$stmt->bind_param("iis", $job1, $superId, $update2);
$stmt->execute();
insert_history_row($conn, 'case_history', $job1, 'CASE_UPDATE_ADDED', $superId, json_encode(['Type' => 'Communication', 'Communication Type' => 'Phone', 'Communication With' => 'DI Sarah Hughes', 'Text' => $update2]));
$stmt->close();
echo "  - 2 case updates (1 communication)\n";

// --- Case 2: newly booked-in, awaiting triage - simpler, for variety.
$ref2 = 'DM' . date('y') . '02';
$statusTriage = $statusIds['Awaiting Triage'] ?? array_values($statusIds)[0];
$stmt = $conn->prepare("INSERT INTO jobs (custom_ref, created_by, initial_summary, oic, operation, customer_id, lead_force_id, suspect, fingerprints, dna, status_id, malware, strategy_set, strategy_due, case_type_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, 0, NOW(), DATE_ADD(NOW(), INTERVAL 90 DAY), ?)");
$summary2 = 'USB drive recovered from suspect vehicle. Awaiting triage.';
$oic2 = 'DC Mark Ellis';
$suspect2 = 'Unknown';
$stmt->bind_param("sissiiisii", $ref2, $superId, $summary2, $oic2, $operationId, $customerId, $forceId, $suspect2, $statusTriage, $caseTypeId);
$stmt->execute();
$job2 = $conn->insert_id;
$stmt->close();

$stmt = $conn->prepare("INSERT INTO exhibits (job_id, barcode, time_in, time_out, exhibit_type_id, bag_number, exhibit_ref, urgency, location_id, delivered_by, item_description, status, created_by) VALUES (?, '', NOW(), NULL, ?, 'BAG-2001', 'DM2-001', 'High', ?, 'PC Davies', 'SanDisk 32GB USB drive', 'Not Yet Started', ?)");
$stmt->bind_param("iiii", $job2, $usbTypeId, $locationId, $superId);
if ($stmt->execute()) {
    $exId2 = $conn->insert_id;
    insert_history_row($conn, 'exhibit_history', $exId2, 'BOOK_IN', $superId, json_encode(['exhibit_ref' => 'DM2-001']));
    echo "  - case $ref2 (job_id $job2), 1 exhibit\n";
} else {
    echo "  ! exhibit insert failed for DM2-001: {$stmt->error}\n";
}
$stmt->close();

// --- Spaceport submissions: one pending, one approved+linked, one rejected.
if ($officerId) {
    echo "Creating demo Spaceport submissions...\n";

    $stmt = $conn->prepare("INSERT INTO submissions (case_type_id, initial_summary, oic, suspect, incident_number, submitted_by) VALUES (?, ?, ?, ?, ?, ?)");
    $s1summary = 'Suspect laptop and phone seized following arrest for fraud offences. Requesting full examination.';
    $s1oic = 'DC Mark Ellis';
    $s1suspect = 'Alan Turing';
    $s1incident = 'INC-2026-1099';
    $stmt->bind_param("issssi", $caseTypeId, $s1summary, $s1oic, $s1suspect, $s1incident, $officerId);
    $stmt->execute();
    $sub1 = $conn->insert_id;
    $stmt->close();
    insert_history_row($conn, 'submission_history', $sub1, 'CREATE', $officerId, json_encode(['case_type_id' => $caseTypeId, 'oic' => $s1oic, 'suspect' => $s1suspect]));
    echo "  - submission " . submission_ref($sub1) . " (Pending)\n";

    $stmt = $conn->prepare("INSERT INTO submissions (case_type_id, initial_summary, oic, suspect, submitted_by, status, reviewed_by, reviewed_at, decision_reason) VALUES (?, ?, ?, ?, ?, 'Rejected', ?, NOW(), ?)");
    $s2summary = 'Witness phone for context only, no offences alleged.';
    $s2oic = 'DC Mark Ellis';
    $s2suspect = 'N/A - witness device';
    $s2reason = 'Not a suspect device - refer to witness statement process instead, no forensic examination required.';
    $stmt->bind_param("isssiis", $caseTypeId, $s2summary, $s2oic, $s2suspect, $officerId, $superId, $s2reason);
    $stmt->execute();
    $sub2 = $conn->insert_id;
    $stmt->close();
    insert_history_row($conn, 'submission_history', $sub2, 'CREATE', $officerId, json_encode(['case_type_id' => $caseTypeId, 'oic' => $s2oic]));
    insert_history_row($conn, 'submission_history', $sub2, 'REJECTED', $superId, json_encode(['reason' => $s2reason]));
    echo "  - submission " . submission_ref($sub2) . " (Rejected)\n";

    // --- Submissions #3 and #4: approved (a real job exists) but their
    // declared exhibits are still 'Declared' - they haven't physically
    // arrived at the lab yet. This is exactly what the "Declared via
    // Submission" checklist on add_exhibit.php is for.
    $stmt = $conn->prepare("INSERT INTO submissions (case_type_id, initial_summary, oic, suspect, incident_number, submitted_by) VALUES (?, ?, ?, ?, ?, ?)");
    $s3summary = 'Suspect arrested for drug supply offences. Two phones and a laptop seized under warrant, being couriered to the lab.';
    $s3oic = 'DI Sarah Hughes';
    $s3suspect = 'Robert Cole';
    $s3incident = 'INC-2026-1150';
    $stmt->bind_param("issssi", $caseTypeId, $s3summary, $s3oic, $s3suspect, $s3incident, $officerId);
    $stmt->execute();
    $sub3 = $conn->insert_id;
    $stmt->close();
    insert_history_row($conn, 'submission_history', $sub3, 'CREATE', $officerId, json_encode(['case_type_id' => $caseTypeId, 'oic' => $s3oic, 'suspect' => $s3suspect]));

    $insEx = $conn->prepare("INSERT INTO submitted_exhibits (submission_id, exhibit_type_id, exhibit_ref, description, bag_number, seizing_officer, seizing_location) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $declared3 = [
        [$mobileTypeId, 'DM3-001', 'Samsung Galaxy S22, blue', 'BAG-3001', 'PC Nadia Farooq', '14 Elm Street, Anytown'],
        [$mobileTypeId, 'DM3-002', 'iPhone SE, white, locked', 'BAG-3002', 'PC Nadia Farooq', '14 Elm Street, Anytown'],
        [$laptopTypeId, 'DM3-003', 'HP Pavilion laptop', 'BAG-3003', 'PC Nadia Farooq', '14 Elm Street, Anytown'],
    ];
    foreach ($declared3 as $d) {
        $insEx->bind_param("iisssss", $sub3, $d[0], $d[1], $d[2], $d[3], $d[4], $d[5]);
        $insEx->execute();
    }
    $insEx->close();

    if (!empty($questionFieldIds)) {
        $insQ = $conn->prepare("INSERT INTO submission_question_values (submission_id, field_id, value) VALUES (?, ?, ?)");
        $answers3 = [
            'court_deadline_confirmed' => '1',
            'legal_aid_reference' => 'LA-2026-88213',
            'additional_notes_for_lab' => 'Suspect has history of encrypted messaging app use - please prioritise app data extraction.',
        ];
        foreach ($answers3 as $key => $val) {
            if (!isset($questionFieldIds[$key])) { continue; }
            $insQ->bind_param("iis", $sub3, $questionFieldIds[$key], $val);
            $insQ->execute();
        }
        $insQ->close();
    }

    // A tiny (1x1) but genuinely valid PNG, so getimagesize()/the upload
    // path both see a real image - not a placeholder that would break the
    // subject-photo preview.
    $tinyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $storage = get_storage_settings($conn);
    $photoDir = $storage['paths']['subject_photo_dir_fs'] . $sub3 . '/';
    if (!is_dir($photoDir)) {
        mkdir($photoDir, 0755, true);
    }
    // This script runs via CLI (docker exec), typically as root - unlike
    // every other writer of this tree, which is Apache/www-data. Left at
    // the mkdir() default, root ends up owning this directory and any
    // later officer-uploaded photo silently fails to move_uploaded_file()
    // into it (no write permission, no error surfaced - see
    // cargo_hold/edit_job.php's subject photo handler). Force it open.
    @chmod($photoDir, 0777);
    $photoFile = $photoDir . 'v1_demo.png';
    file_put_contents($photoFile, $tinyPng);
    @chmod($photoFile, 0666);
    $insPhoto = $conn->prepare("INSERT INTO subject_photos (submission_id, version, original_filename, stored_filename, file_path, uploaded_by) VALUES (?, 1, 'subject.png', 'v1_demo.png', ?, ?)");
    $insPhoto->bind_param("isi", $sub3, $photoFile, $officerId);
    $insPhoto->execute();
    $insPhoto->close();

    $job3 = approve_submission($conn, $sub3, $superId);
    echo "  - submission " . submission_ref($sub3) . " (Approved, job_id $job3) - 3 exhibits declared, none arrived yet\n";

    $stmt = $conn->prepare("INSERT INTO submissions (case_type_id, initial_summary, oic, suspect, submitted_by) VALUES (?, ?, ?, ?, ?)");
    $s4summary = 'Single USB drive seized from workplace locker, awaiting courier delivery.';
    $s4oic = 'DC Mark Ellis';
    $s4suspect = 'Priya Nair';
    $stmt->bind_param("isssi", $caseTypeId, $s4summary, $s4oic, $s4suspect, $officerId);
    $stmt->execute();
    $sub4 = $conn->insert_id;
    $stmt->close();
    insert_history_row($conn, 'submission_history', $sub4, 'CREATE', $officerId, json_encode(['case_type_id' => $caseTypeId, 'oic' => $s4oic, 'suspect' => $s4suspect]));

    $insEx = $conn->prepare("INSERT INTO submitted_exhibits (submission_id, exhibit_type_id, exhibit_ref, description, bag_number, seizing_officer, seizing_location) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $ref4 = 'DM4-001';
    $bag = 'BAG-4001';
    $officer4 = 'PC Davies';
    $loc4 = 'Unit 7 Business Park, Anytown';
    $desc4 = 'SanDisk 64GB USB drive';
    $insEx->bind_param("iisssss", $sub4, $usbTypeId, $ref4, $desc4, $bag, $officer4, $loc4);
    $insEx->execute();
    $insEx->close();

    $job4 = approve_submission($conn, $sub4, $superId);
    echo "  - submission " . submission_ref($sub4) . " (Approved, job_id $job4) - 1 exhibit declared, not arrived yet\n";

    // --- Submission #5: approved AND fully resolved - every declared
    // exhibit has already been booked in and reconciled, so the "Declared
    // via Submission" checklist on add_exhibit.php is empty for this job.
    // No photo, no submission-question answers either - covers the "plain"
    // end of the variety spectrum, same as a case with nothing extra filled in.
    $stmt = $conn->prepare("INSERT INTO submissions (case_type_id, initial_summary, oic, suspect, submitted_by) VALUES (?, ?, ?, ?, ?)");
    $s5summary = 'Suspect tablet seized at custody suite following unrelated stop. Straightforward device, no complicating factors.';
    $s5oic = 'DI Sarah Hughes';
    $s5suspect = 'Grace Okafor';
    $stmt->bind_param("isssi", $caseTypeId, $s5summary, $s5oic, $s5suspect, $officerId);
    $stmt->execute();
    $sub5 = $conn->insert_id;
    $stmt->close();
    insert_history_row($conn, 'submission_history', $sub5, 'CREATE', $officerId, json_encode(['case_type_id' => $caseTypeId, 'oic' => $s5oic, 'suspect' => $s5suspect]));

    $insEx = $conn->prepare("INSERT INTO submitted_exhibits (submission_id, exhibit_type_id, exhibit_ref, description, bag_number, seizing_officer, seizing_location) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $exhibitRef5 = 'DM5-001';
    $bag5 = 'BAG-5001';
    $officer5 = 'PC Davies';
    $loc5 = 'Anytown Custody Suite';
    $desc5 = 'Samsung Galaxy Tab A8';
    $insEx->bind_param("iisssss", $sub5, $mobileTypeId, $exhibitRef5, $desc5, $bag5, $officer5, $loc5);
    $insEx->execute();
    $declaredExhibitId5 = $conn->insert_id;
    $insEx->close();

    $job5 = approve_submission($conn, $sub5, $superId);

    // Actually book the declared exhibit in and reconcile it, same
    // sequence add_exhibit.php's fulfillment path runs - this is what
    // leaves nothing outstanding on the checklist.
    $exStmt = $conn->prepare("INSERT INTO exhibits (job_id, barcode, time_in, time_out, exhibit_type_id, bag_number, exhibit_ref, urgency, location_id, delivered_by, item_description, status, created_by) VALUES (?, '', NOW(), NULL, ?, ?, ?, 'Low', ?, ?, ?, 'Not Yet Started', ?)");
    $exStmt->bind_param("iississi", $job5, $mobileTypeId, $bag5, $exhibitRef5, $locationId, $officer5, $desc5, $superId);
    $exStmt->execute();
    $matchedExhibitId5 = $conn->insert_id;
    $exStmt->close();
    insert_history_row($conn, 'exhibit_history', $matchedExhibitId5, 'BOOK_IN', $superId, json_encode(['exhibit_ref' => $exhibitRef5, 'item_description' => $desc5]));

    $reconcileStmt = $conn->prepare("UPDATE submitted_exhibits SET reconcile_status = 'Received', matched_exhibit_id = ? WHERE submitted_exhibit_id = ?");
    $reconcileStmt->bind_param("ii", $matchedExhibitId5, $declaredExhibitId5);
    $reconcileStmt->execute();
    $reconcileStmt->close();
    insert_history_row($conn, 'submission_history', $sub5, 'UPDATE', $superId, json_encode([
        'Declared Exhibit' => $exhibitRef5,
        'Reconcile Status' => ['old' => 'Declared', 'new' => 'Received'],
        'Booked In As' => $exhibitRef5,
    ]));
    echo "  - submission " . submission_ref($sub5) . " (Approved, job_id $job5) - fully resolved, nothing pending\n";

    // --- Submission #6: More Info Requested - the one status the other
    // demo submissions don't cover, sitting in the loop waiting on the
    // officer to amend and resubmit.
    $stmt = $conn->prepare("INSERT INTO submissions (case_type_id, initial_summary, oic, suspect, submitted_by, status, reviewed_by, reviewed_at, info_requested_note) VALUES (?, ?, ?, ?, ?, 'More Info Requested', ?, NOW(), ?)");
    $s6summary = 'Suspect phone seized, exact offence unclear from initial report.';
    $s6oic = 'DC Mark Ellis';
    $s6suspect = 'Unnamed at this stage';
    $s6note = "Please confirm the specific offence(s) under investigation and the strategy - can't assess turnaround priority without this.";
    $stmt->bind_param("isssiis", $caseTypeId, $s6summary, $s6oic, $s6suspect, $officerId, $superId, $s6note);
    $stmt->execute();
    $sub6 = $conn->insert_id;
    $stmt->close();
    insert_history_row($conn, 'submission_history', $sub6, 'CREATE', $officerId, json_encode(['case_type_id' => $caseTypeId, 'oic' => $s6oic, 'suspect' => $s6suspect]));
    insert_history_row($conn, 'submission_history', $sub6, 'MORE_INFO_REQUESTED', $superId, json_encode(['note' => $s6note]));
    echo "  - submission " . submission_ref($sub6) . " (More Info Requested)\n";
}

echo "\nDone. Log in and browse:\n";
echo "  - Case Management -> $ref1 / $ref2\n";
if ($officerId) {
    echo "  - Spaceport -> Review Queue (as an admin/super user)\n";
    echo "  - Spaceport -> My Submissions (as the submitting_officer user)\n";
    echo "  - SUB-000003/000004 are approved with exhibits still 'Declared' - open Book\n";
    echo "    Exhibit(s) In on their case to try the reconciliation checklist.\n";
    echo "  - SUB-000005 is approved and fully resolved - nothing pending on that checklist.\n";
    echo "  - SUB-000006 is sitting at More Info Requested.\n";
}
