<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../db.php';
require_once '../includes/permissions.php';
require_once '../includes/integrity.php';
require_once '../includes/achievements.php';
require_once '../includes/audit.php';
require_once '../includes/deletion_reason.php';
require_permission($conn, 'examination_view');

// Validate exhibit_id
$exhibit_id = isset($_GET['exhibit_id']) ? intval($_GET['exhibit_id']) : 0;
if ($exhibit_id <= 0) {
    die("Invalid exhibit ID.");
}

// Get exhibit details
$exhibit_stmt = $conn->prepare("
    SELECT e.exhibit_ref, e.bag_number, e.status, e.parent_id,
           e.allocated_to, u.first_name, u.last_name,
           e.urgency, e.location_id, l.location_name,
           e.exhibit_type_id, t.type_name
    FROM exhibits e
    LEFT JOIN users u ON e.allocated_to = u.id
    LEFT JOIN exhibit_locations l ON e.location_id = l.location_id
    LEFT JOIN exhibit_types t ON e.exhibit_type_id = t.exhibit_type_id
    WHERE e.exhibit_id = ?
");
$exhibit_stmt->bind_param("i", $exhibit_id);
$exhibit_stmt->execute();
$exhibit_result = $exhibit_stmt->get_result();
$exhibit = $exhibit_result->fetch_assoc();

if (!$exhibit) {
    die("Exhibit not found.");
}

// Status stepper write path - a fast, in-page alternative to Edit Exhibit's
// status dropdown. Must run (and redirect) before header.php's HTML output
// below, same reason edit_exhibit.php does all its POST handling up front.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_exhibit_status'])) {
    if (user_can($conn, (int) $_SESSION['user_id'], 'exhibit_edit')) {
        $validStatuses = ['Not Yet Started', 'Imaging', 'Imaged', 'Being Analysed', 'On Hold', 'Complete'];
        $newStatus = trim($_POST['new_status'] ?? '');
        if (in_array($newStatus, $validStatuses, true) && $newStatus !== $exhibit['status']) {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("UPDATE exhibits SET status = ? WHERE exhibit_id = ?");
                $stmt->bind_param("si", $newStatus, $exhibit_id);
                if (!$stmt->execute()) {
                    throw new Exception($stmt->error);
                }
                $stmt->close();

                $changes = json_encode(['Status' => ['old' => $exhibit['status'], 'new' => $newStatus]]);
                if (!insert_history_row($conn, 'exhibit_history', $exhibit_id, 'UPDATE', (int) $_SESSION['user_id'], $changes)) {
                    throw new Exception('History insert failed');
                }
                $conn->commit();
                check_and_unlock_achievements($conn, (int) $_SESSION['user_id'], 'exhibits_completed');
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Status update failed for exhibit $exhibit_id: " . $e->getMessage());
            }
        }
    }
    header("Location: examination.php?exhibit_id=" . $exhibit_id);
    exit();
}

// Delete an uploaded photo or document - same permission that gates
// uploading them (document_manage), same "require a reason" setting as
// every other delete in the app, logged to the tamper-evident audit_log
// (not exhibit_history - this isn't a change to the exhibit record itself).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['delete_photo']) || isset($_POST['delete_document']))) {
    if (user_can($conn, (int) $_SESSION['user_id'], 'document_manage')) {
        $deleteReason = require_deletion_reason_or_fail($conn);
        if ($deleteReason !== false) {
            if (isset($_POST['delete_photo'])) {
                $photoId = (int) $_POST['delete_photo'];
                $stmt = $conn->prepare("SELECT file_name, file_path FROM exhibit_photos WHERE id = ? AND exhibit_id = ?");
                $stmt->bind_param("ii", $photoId, $exhibit_id);
                $stmt->execute();
                $photo = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($photo) {
                    $del = $conn->prepare("DELETE FROM exhibit_photos WHERE id = ?");
                    $del->bind_param("i", $photoId);
                    $del->execute();
                    $del->close();
                    if (is_file($photo['file_path'])) {
                        @unlink($photo['file_path']);
                    }
                    log_audit_event($conn, 'exhibit_photo', $photoId, 'DELETE', (int) $_SESSION['user_id'], json_encode(['file_name' => $photo['file_name'], 'exhibit_id' => $exhibit_id, 'reason' => $deleteReason]));
                }
            } else {
                $docId = (int) $_POST['delete_document'];
                $stmt = $conn->prepare("SELECT original_filename, file_path FROM exhibit_documents WHERE id = ? AND exhibit_id = ?");
                $stmt->bind_param("ii", $docId, $exhibit_id);
                $stmt->execute();
                $doc = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($doc) {
                    $del = $conn->prepare("DELETE FROM exhibit_documents WHERE id = ?");
                    $del->bind_param("i", $docId);
                    $del->execute();
                    $del->close();
                    if (is_file($doc['file_path'])) {
                        @unlink($doc['file_path']);
                    }
                    log_audit_event($conn, 'exhibit_document', $docId, 'DELETE', (int) $_SESSION['user_id'], json_encode(['file_name' => $doc['original_filename'], 'exhibit_id' => $exhibit_id, 'reason' => $deleteReason]));
                }
            }
        }
    }
    header("Location: examination.php?exhibit_id=" . $exhibit_id);
    exit();
}

// The examination page always shows the parent exhibit - a sub-exhibit's
// own processes/photos/documents/sub-exhibits would otherwise look like
// they'd disappeared behind a separate page whenever a link (e.g. after
// saving a process recorded against a sub-exhibit) lands here with a
// sub-exhibit's id. Processes recorded against a sub-exhibit still show up
// in the parent's Processes table, flagged as such - see below.
if ($exhibit['parent_id'] !== null) {
    header("Location: examination.php?exhibit_id=" . (int) $exhibit['parent_id']);
    exit();
}

include '../header.php';

// Get sub exhibits
$sub_stmt = $conn->prepare("
    SELECT e.exhibit_id, e.exhibit_ref, e.exhibit_type_id, t.type_name,
           u.first_name, u.last_name
    FROM exhibits e
    LEFT JOIN exhibit_types t ON e.exhibit_type_id = t.exhibit_type_id
    LEFT JOIN users u ON e.created_by = u.id
    WHERE e.parent_id = ?
    ORDER BY e.exhibit_ref ASC
");
$sub_stmt->bind_param("i", $exhibit_id);
$sub_stmt->execute();
$sub_result = $sub_stmt->get_result();

$sub_exhibits = [];
while ($row = $sub_result->fetch_assoc()) {
    $sub_exhibits[] = [
        'id' => $row['exhibit_id'],
        'exhibit_ref' => $row['exhibit_ref'],
        'type_name' => $row['type_name'],
        'created_by_name' => trim($row['first_name'] . ' ' . $row['last_name']),
    ];
}


// Load uploaded photos
$photo_stmt = $conn->prepare("
    SELECT id, file_name, file_path
    FROM exhibit_photos
    WHERE exhibit_id = ?
    ORDER BY uploaded_at DESC
");
$photo_stmt->bind_param("i", $exhibit_id);
$photo_stmt->execute();
$photo_result = $photo_stmt->get_result();

$photos = [];
while ($row = $photo_result->fetch_assoc()) {
    $photos[] = [
        'id' => $row['id'],
        'file_url' => str_replace($config['paths']['photo_dir_fs'], $config['paths']['photo_dir_url'], $row['file_path']),
        'file_name' => $row['file_name']
    ];
}



// Load documents
$doc_stmt = $conn->prepare("
    SELECT id, original_filename, file_path
    FROM exhibit_documents
    WHERE exhibit_id = ?
    ORDER BY uploaded_at DESC
");
$doc_stmt->bind_param("i", $exhibit_id);
$doc_stmt->execute();
$doc_result = $doc_stmt->get_result();

$documents = [];
while ($row = $doc_result->fetch_assoc()) {
    $url = str_replace($config['paths']['document_dir_fs'], $config['paths']['document_dir_url'], $row['file_path']);
    $documents[] = [
        'id' => $row['id'],
        'file_url' => $url,
        'original_filename' => $row['original_filename']
    ];
}



$job_stmt = $conn->prepare("SELECT job_id FROM exhibits WHERE exhibit_id = ?");
$job_stmt->bind_param("i", $exhibit_id);
$job_stmt->execute();
$job_result = $job_stmt->get_result();
$job_row = $job_result->fetch_assoc();

$job_id = $job_row ? $job_row['job_id'] : 0;

// Active processes for the "Add Process" picker, scoped to this exhibit's
// type - a process with no process_type_exhibit_types rows at all is
// unscoped (offered everywhere); otherwise it needs an explicit row for
// this exhibit_type_id (assigned from the process's own edit form, see
// captains_quarters/manage_processes.php).
$processTypes = [];
$ptStmt = $conn->prepare("
    SELECT pt.id, pt.name
    FROM process_types pt
    WHERE pt.is_active = 1
      AND (
        NOT EXISTS (SELECT 1 FROM process_type_exhibit_types x WHERE x.process_type_id = pt.id)
        OR EXISTS (SELECT 1 FROM process_type_exhibit_types x WHERE x.process_type_id = pt.id AND x.exhibit_type_id = ?)
      )
    ORDER BY pt.sort_order ASC
");
$ptStmt->bind_param("i", $exhibit['exhibit_type_id']);
$ptStmt->execute();
$ptResult = $ptStmt->get_result();
while ($row = $ptResult->fetch_assoc()) {
    $processTypes[] = $row;
}
$ptStmt->close();

// All processes recorded against this exhibit or its sub-exhibits - one
// flat list, no pipeline/case-event split.
$exhibitProcesses = [];
$epStmt = $conn->prepare("
    SELECT ep.id, ep.exhibit_id, pt.name AS process_name, ep.updated_at,
           e.exhibit_ref,
           COALESCE(CONCAT(uu.first_name, ' ', uu.last_name), CONCAT(cu.first_name, ' ', cu.last_name)) AS entered_by_name
    FROM exhibit_processes ep
    JOIN process_types pt ON ep.process_type_id = pt.id
    JOIN exhibits e ON ep.exhibit_id = e.exhibit_id
    LEFT JOIN users cu ON ep.created_by = cu.id
    LEFT JOIN users uu ON ep.updated_by = uu.id
    WHERE ep.exhibit_id = ? OR ep.exhibit_id IN (SELECT exhibit_id FROM exhibits WHERE parent_id = ?)
    ORDER BY ep.updated_at DESC
");
$epStmt->bind_param("ii", $exhibit_id, $exhibit_id);
$epStmt->execute();
$epResult = $epStmt->get_result();
while ($epRow = $epResult->fetch_assoc()) {
    $exhibitProcesses[] = $epRow;
}
$epStmt->close();

// Status stepper - mirrors exhibits.status (6 real values) bucketed into 3
// stages. Stage 2 covers everything that isn't the exact start/end value.
$stage2Statuses = ['Imaging', 'Imaged', 'Being Analysed', 'On Hold'];
$statusStage = 2;
if ($exhibit['status'] === 'Not Yet Started') {
    $statusStage = 1;
} elseif ($exhibit['status'] === 'Complete') {
    $statusStage = 3;
}
$canEditStatus = user_can($conn, (int) $_SESSION['user_id'], 'exhibit_edit');
$canManageDocuments = user_can($conn, (int) $_SESSION['user_id'], 'document_manage');

// Shared metadata pool values recorded for this exhibit (see
// captains_quarters/manage_metadata_fields.php / manage_exhibit_process.php).
// Only fields with a value are shown - most exhibits only fill in a few.
$exhibitMetadata = [];
$mdStmt = $conn->prepare("
    SELECT mf.field_label, mf.field_type, mf.hash_algorithm, mv.value, mv.updated_at,
           CONCAT(u.first_name, ' ', u.last_name) AS updated_by_name
    FROM exhibit_metadata_values mv
    JOIN exhibit_metadata_fields mf ON mf.id = mv.metadata_field_id
    LEFT JOIN users u ON u.id = mv.updated_by
    WHERE mv.exhibit_id = ? AND mv.value IS NOT NULL AND mv.value != ''
    ORDER BY mf.sort_order
");
$mdStmt->bind_param("i", $exhibit_id);
$mdStmt->execute();
$mdResult = $mdStmt->get_result();
while ($mdRow = $mdResult->fetch_assoc()) {
    $exhibitMetadata[] = $mdRow;
}
$mdStmt->close();
?>

<link rel="stylesheet" href="/assets/dropzone/dropzone.min.css">
<script src="/assets/dropzone/dropzone.min.js"></script>

<style>
    /* header.php's own body{} already sets margin/background/color/font-family
       page-wide - the 120px clearance for the fixed header/nav lives on
       .main-container's top margin. Widened from the old 1400px cap (and a
       fixed 300px sidebar) to scale better on wide screens now that exhibit
       info is a compact spreadsheet row instead of a stacked sidebar. */

    .main-container {
        max-width: 1700px;
        width: 95%;
        margin: 120px auto 40px auto;
    }

    .panel {
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 5px;
        box-shadow: 0 2px 8px rgba(255, 255, 255, 0.1);
        margin-bottom: 25px;
    }

    .section-title {
        margin-top: 0;
        font-size: 20px;
        border-bottom: 1px solid var(--polaris-border);
        padding-bottom: 10px;
        margin-bottom: 15px;
    }

    .info-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 15px;
    }

    .info-header h2 {
        border: none;
        margin: 0;
        padding: 0;
    }

    /* Spreadsheet-style tables used throughout this page - a header row of
       labels, then one (or more) data rows underneath. Tighter and more
       scannable than the old stacked <p><strong>Label:</strong> value</p> list. */
    .sheet-table-wrapper {
        overflow-x: auto;
    }

    .sheet-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    .sheet-table th {
        background: var(--polaris-divider);
        color: var(--polaris-text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-size: 11px;
        text-align: left;
        padding: 8px 10px;
        border: 1px solid var(--polaris-border);
        white-space: nowrap;
    }

    .sheet-table td {
        background: var(--polaris-surface-deep);
        color: var(--polaris-gray-light);
        padding: 8px 10px;
        border: 1px solid var(--polaris-border);
        vertical-align: top;
    }

    .btn,
    .btn-small,
    .btn-download {
        padding: 5px 10px;
        background: var(--polaris-accent);
        border: none;
        color: var(--polaris-text);
        border-radius: 3px;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
        font-size: 14px;
        line-height: 1.2;
    }

    .btn-small {
        padding: 3px 8px;
        font-size: 12px;
    }

    .btn:hover,
    .btn-small:hover,
    .btn-download:hover {
        background: var(--polaris-accent-hover);
    }

    .btn-outline {
        background: transparent;
        border: 1px solid var(--polaris-border-hover);
    }

    .btn-outline:hover {
        background: var(--polaris-divider);
    }

    .btn-add {
        background: var(--polaris-success-strong);
    }

    .btn-add:hover {
        background: var(--polaris-success-strong-hover);
    }

    .add-process-form {
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;
    }

    .add-process-form select {
        padding: 7px;
        background: var(--polaris-bg);
        color: var(--polaris-text);
        border: 1px solid var(--polaris-border);
        border-radius: 4px;
    }

    .empty-note {
        color: var(--polaris-text-faint);
        font-size: 14px;
    }

    .badge-sub {
        display: inline-block;
        padding: 2px 8px;
        margin-left: 6px;
        border-radius: 10px;
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        white-space: nowrap;
        background: var(--polaris-accent);
        color: var(--polaris-text);
    }

    .bottom-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }

    @media (max-width: 900px) {
        .bottom-grid {
            grid-template-columns: 1fr;
        }
    }

    .photo-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
        gap: 10px;
        max-height: 260px;
        overflow-y: auto;
        padding-right: 5px;
    }

    .photo-grid img {
        width: 100%;
        height: 100px;
        object-fit: cover;
        border-radius: 5px;
        background: var(--polaris-surface-deep);
    }

    .photo-item {
        position: relative;
    }

    .photo-delete-form {
        position: absolute;
        top: 4px;
        right: 4px;
    }

    .photo-delete-btn {
        width: 20px;
        height: 20px;
        line-height: 18px;
        padding: 0;
        border: none;
        border-radius: 50%;
        background: rgba(0, 0, 0, 0.6);
        color: #fff;
        cursor: pointer;
        font-size: 14px;
    }

    .photo-delete-btn:hover {
        background: var(--polaris-danger);
    }

    .doc-list {
        max-height: 260px;
        overflow-y: auto;
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .doc-list li {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 10px;
        background: var(--polaris-bg);
        padding: 10px;
        border-radius: 5px;
    }

    .doc-delete-btn {
        background: var(--polaris-error-bg);
        flex-shrink: 0;
    }

    .doc-delete-btn:hover {
        background: var(--polaris-danger);
    }

    .doc-list a {
        color: #66b3ff;
        text-decoration: none;
    }

    .doc-list a:hover {
        text-decoration: underline;
    }

    .sub-exhibit-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    .sub-exhibit-table th {
        background: var(--polaris-divider);
        color: var(--polaris-text);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 8px 10px;
        text-align: left;
        border: 1px solid var(--polaris-border);
        font-size: 11px;
    }

    .sub-exhibit-table td {
        border: 1px solid var(--polaris-border);
        padding: 8px 10px;
        background: var(--polaris-bg);
        color: var(--polaris-gray-light);
    }

    .sub-exhibit-table tr:hover td {
        background: var(--polaris-bg-alt);
    }

    /* Tabbed layout - only one section visible at a time so the page reads
       as a single working area instead of a long stack of panels. */
    .tab-nav {
        display: flex;
        gap: 4px;
        flex-wrap: wrap;
        border-bottom: 1px solid var(--polaris-border);
        margin-bottom: 18px;
    }

    .tab-btn {
        background: transparent;
        border: none;
        border-bottom: 3px solid transparent;
        color: var(--polaris-text-secondary);
        padding: 10px 16px;
        font-size: 15px;
        cursor: pointer;
    }

    .tab-btn:hover {
        color: var(--polaris-text);
    }

    .tab-btn.active {
        color: var(--polaris-text);
        border-bottom-color: var(--polaris-accent);
        font-weight: 600;
    }

    .tab-panel {
        display: none;
    }

    .tab-panel.active {
        display: block;
    }

    /* Status stepper - mirrors exhibits.status bucketed into 3 stages. */
    .status-stepper {
        display: flex;
        align-items: flex-start;
    }

    .stepper-stage {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
        background: transparent;
        border: none;
        padding: 4px 8px;
        color: var(--polaris-text-secondary);
        font-family: inherit;
        font-size: 13px;
    }

    button.stepper-stage {
        cursor: pointer;
    }

    .stepper-dot {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--polaris-divider);
        border: 2px solid var(--polaris-border);
        font-weight: 600;
        color: var(--polaris-text-secondary);
    }

    .stepper-stage.active .stepper-dot {
        background: var(--polaris-accent);
        border-color: var(--polaris-accent);
        color: var(--polaris-text);
    }

    .stepper-stage.complete .stepper-dot {
        background: var(--polaris-success-strong);
        border-color: var(--polaris-success-strong);
        color: var(--polaris-text);
    }

    .stepper-label {
        font-weight: 600;
        color: var(--polaris-text);
    }

    .stepper-connector {
        flex: 0 0 60px;
        height: 3px;
        background: var(--polaris-border);
        margin-top: 15px;
    }

    .stepper-connector.complete {
        background: var(--polaris-success-strong);
    }

    .stage2-picker {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 14px;
    }

    .stage2-picker select {
        padding: 6px;
        background: var(--polaris-bg);
        color: var(--polaris-text);
        border: 1px solid var(--polaris-border);
        border-radius: 4px;
    }
</style>

<div class="main-container">

    <!-- Persistent identity strip - always shows which exhibit you're on,
         regardless of which tab is active. -->
    <div class="panel" style="margin-bottom: 15px;">
        <div class="info-header">
            <h2>Exhibit <?= htmlspecialchars($exhibit['exhibit_ref']) ?>
                <span style="font-size: 14px; font-weight: normal; color: var(--polaris-text-secondary);">
                    &mdash; <?= htmlspecialchars($exhibit['type_name']) ?>
                    &middot; <?= htmlspecialchars($exhibit['status']) ?>
                    <?php if (!empty($exhibit['location_name'])): ?>
                    &middot; <?= htmlspecialchars($exhibit['location_name']) ?>
                    <?php endif; ?>
                </span>
            </h2>
            <a href="/cargo_hold/job.php?job_id=<?= $job_id ?>" class="btn btn-small btn-outline">&larr; Back to
                Case</a>
        </div>
    </div>

    <!-- Status - a fast, always-visible control for exhibits.status, not
         specific to any one tab. -->
    <div class="panel">
        <h2 class="section-title">Status</h2>
        <div class="status-stepper">
            <?php if ($canEditStatus): ?>
            <button type="button" class="stepper-stage<?= $statusStage === 1 ? ' active' : ($statusStage > 1 ? ' complete' : '') ?>"
                data-stage="1">
                <span class="stepper-dot"><?= $statusStage > 1 ? '&#10003;' : '1' ?></span>
                <span class="stepper-label">Not Yet Started</span>
            </button>
            <?php else: ?>
            <span class="stepper-stage<?= $statusStage === 1 ? ' active' : ($statusStage > 1 ? ' complete' : '') ?>">
                <span class="stepper-dot"><?= $statusStage > 1 ? '&#10003;' : '1' ?></span>
                <span class="stepper-label">Not Yet Started</span>
            </span>
            <?php endif; ?>

            <div class="stepper-connector<?= $statusStage > 1 ? ' complete' : '' ?>"></div>

            <?php if ($canEditStatus): ?>
            <button type="button" class="stepper-stage<?= $statusStage === 2 ? ' active' : ($statusStage > 2 ? ' complete' : '') ?>"
                data-stage="2">
                <span class="stepper-dot"><?= $statusStage > 2 ? '&#10003;' : '2' ?></span>
                <span class="stepper-label"><?= $statusStage === 2 ? htmlspecialchars($exhibit['status']) : 'In Progress' ?></span>
            </button>
            <?php else: ?>
            <span class="stepper-stage<?= $statusStage === 2 ? ' active' : ($statusStage > 2 ? ' complete' : '') ?>">
                <span class="stepper-dot"><?= $statusStage > 2 ? '&#10003;' : '2' ?></span>
                <span class="stepper-label"><?= $statusStage === 2 ? htmlspecialchars($exhibit['status']) : 'In Progress' ?></span>
            </span>
            <?php endif; ?>

            <div class="stepper-connector<?= $statusStage > 2 ? ' complete' : '' ?>"></div>

            <?php if ($canEditStatus): ?>
            <button type="button" class="stepper-stage<?= $statusStage === 3 ? ' active' : '' ?>" data-stage="3">
                <span class="stepper-dot">3</span>
                <span class="stepper-label">Completed</span>
            </button>
            <?php else: ?>
            <span class="stepper-stage<?= $statusStage === 3 ? ' active' : '' ?>">
                <span class="stepper-dot">3</span>
                <span class="stepper-label">Completed</span>
            </span>
            <?php endif; ?>
        </div>

        <?php if ($canEditStatus): ?>
        <div class="stage2-picker" id="stage2-picker" style="display:none;">
            <label for="stage2-select" style="margin:0; color: var(--polaris-text-secondary);">Set to:</label>
            <select id="stage2-select">
                <?php foreach ($stage2Statuses as $s): ?>
                <option value="<?= htmlspecialchars($s) ?>" <?= $exhibit['status'] === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-small btn-add" onclick="submitStage2()">Set</button>
            <button type="button" class="btn btn-small btn-outline"
                onclick="document.getElementById('stage2-picker').style.display='none';">Cancel</button>
        </div>
        <form method="post" action="examination.php?exhibit_id=<?= $exhibit_id ?>" id="status-form" style="display:none;">
            <input type="hidden" name="update_exhibit_status" value="1">
            <input type="hidden" name="new_status" id="status-form-value">
        </form>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="tab-nav">
            <button type="button" class="tab-btn active" data-tab="details">Details</button>
            <button type="button" class="tab-btn" data-tab="subs">Sub-Exhibits<?php if (!empty($sub_exhibits)): ?>
                    (<?= count($sub_exhibits) ?>)<?php endif; ?></button>
            <button type="button" class="tab-btn" data-tab="processes">Processes<?php if (!empty($exhibitProcesses)): ?>
                    (<?= count($exhibitProcesses) ?>)<?php endif; ?></button>
        </div>

        <!-- Details - exhibit info plus the shared metadata pool for this exhibit -->
        <div class="tab-panel active" data-tab-panel="details">
            <div class="sheet-table-wrapper">
                <table class="sheet-table">
                    <thead>
                        <tr>
                            <th>Exhibit Ref</th>
                            <th>Type</th>
                            <th>Bag Number</th>
                            <th>Status</th>
                            <th>Allocated To</th>
                            <th>Urgency</th>
                            <th>Location</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= htmlspecialchars($exhibit['exhibit_ref']) ?></td>
                            <td><?= htmlspecialchars($exhibit['type_name']) ?></td>
                            <td><?= htmlspecialchars($exhibit['bag_number']) ?></td>
                            <td><?= htmlspecialchars($exhibit['status']) ?></td>
                            <td><?= htmlspecialchars(trim($exhibit['first_name'] . ' ' . $exhibit['last_name'])) ?>
                            </td>
                            <td><?= htmlspecialchars($exhibit['urgency']) ?></td>
                            <td><?= htmlspecialchars($exhibit['location_name']) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <?php if (!empty($exhibitMetadata)): ?>
            <div class="info-header" style="margin-top: 20px;">
                <h2 class="section-title" style="border: none; margin: 0; padding: 0;">Metadata</h2>
                <a href="view_metadata_history.php?exhibit_id=<?= $exhibit_id ?>"
                    class="btn btn-small btn-outline">History</a>
            </div>
            <div class="sheet-table-wrapper">
                <table class="sheet-table">
                    <thead>
                        <tr>
                            <?php foreach ($exhibitMetadata as $md): ?>
                            <th><?= htmlspecialchars($md['field_label']) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <?php foreach ($exhibitMetadata as $md): ?>
                            <td>
                                <?php if ($md['field_type'] === 'checkbox'): ?>
                                <?= $md['value'] === '1' ? 'Yes' : 'No' ?>
                                <?php else: ?>
                                <?= htmlspecialchars($md['value']) ?>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p class="empty-note" style="margin-top: 20px;">No metadata pool values recorded for this exhibit yet.
            </p>
            <?php endif; ?>
        </div>

        <!-- Sub-Exhibits -->
        <div class="tab-panel" data-tab-panel="subs">
            <div class="info-header">
                <h2 class="section-title" style="border: none; margin: 0; padding: 0;">Sub-Exhibits</h2>
                <button class="btn btn-small btn-add"
                    onclick="location.href='add_sub_exhibit.php?parent_id=<?= $exhibit_id ?>'">&#10133; Add
                    Sub-Exhibit</button>
            </div>

            <?php if (empty($sub_exhibits)): ?>
            <p class="empty-note">No sub-exhibits found.</p>
            <?php else: ?>
            <div class="sheet-table-wrapper">
                <table class="sub-exhibit-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Type</th>
                            <th>Created By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sub_exhibits as $sub): ?>
                        <tr>
                            <td><?= htmlspecialchars($sub['exhibit_ref']) ?></td>
                            <td><?= htmlspecialchars($sub['type_name']) ?></td>
                            <td><?= htmlspecialchars($sub['created_by_name']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Processes - every process instance recorded against this exhibit
             or its sub-exhibits, plus the Add Process picker. -->
        <div class="tab-panel" data-tab-panel="processes">
            <div class="info-header">
                <h2 class="section-title" style="border: none; margin: 0; padding: 0;">Processes</h2>
                <?php if (!empty($processTypes)): ?>
                <form class="add-process-form" method="get" action="manage_exhibit_process.php">
                    <select name="exhibit_id">
                        <option value="<?= $exhibit_id ?>"><?= htmlspecialchars($exhibit['exhibit_ref']) ?> (this
                            exhibit)</option>
                        <?php foreach ($sub_exhibits as $sub): ?>
                        <option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['exhibit_ref']) ?>
                            (sub-exhibit)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="process_type_id" required onchange="this.form.submit()">
                        <option value="">Select a process&hellip;</option>
                        <?php foreach ($processTypes as $pt): ?>
                        <option value="<?= $pt['id'] ?>"><?= htmlspecialchars($pt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="btn btn-small btn-add">Add Process</button></noscript>
                </form>
                <?php else: ?>
                <span class="empty-note">No processes defined yet - set them up in System Management &rarr; Process
                    Builder.</span>
                <?php endif; ?>
            </div>

            <?php if (empty($exhibitProcesses)): ?>
            <p class="empty-note">No processes recorded against this exhibit or its sub-exhibits yet.</p>
            <?php else: ?>
            <div class="sheet-table-wrapper">
                <table class="sheet-table">
                    <thead>
                        <tr>
                            <th>Process</th>
                            <th>Exhibit Ref</th>
                            <th>Entered By</th>
                            <th>Date/Time</th>
                            <th>Edit</th>
                            <th>History</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($exhibitProcesses as $ep): ?>
                        <tr>
                            <td><?= htmlspecialchars($ep['process_name']) ?></td>
                            <td><?= htmlspecialchars($ep['exhibit_ref']) ?><?php if ((int) $ep['exhibit_id'] !== $exhibit_id): ?><span
                                    class="badge-sub">Sub</span><?php endif; ?></td>
                            <td><?= htmlspecialchars($ep['entered_by_name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($ep['updated_at']) ?></td>
                            <td><a href="manage_exhibit_process.php?exhibit_process_id=<?= $ep['id'] ?>"
                                    class="btn-small btn">Edit</a></td>
                            <td><a href="view_process_history.php?exhibit_process_id=<?= $ep['id'] ?>"
                                    class="btn-small btn btn-outline">History</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Photos & Documents - always visible, not tucked behind a tab. -->
    <div class="bottom-grid">
        <div class="panel">
            <h2 class="section-title">Uploaded Photos</h2>
            <?php if (empty($photos)): ?>
            <p class="empty-note">No photos uploaded yet.</p>
            <?php else: ?>
            <div class="photo-grid">
                <?php foreach ($photos as $photo): ?>
                <div class="photo-item">
                    <img src="<?= htmlspecialchars($photo['file_url']) ?>" alt="Exhibit Photo">
                    <?php if ($canManageDocuments): ?>
                    <form method="post" action="examination.php?exhibit_id=<?= $exhibit_id ?>" class="photo-delete-form">
                        <input type="hidden" name="delete_photo" value="<?= $photo['id'] ?>">
                        <button type="submit" class="photo-delete-btn" title="Delete photo"
                            onclick="return confirmDeleteWithReason(this.form, 'Delete this photo?');">&times;</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div style="text-align:center; margin-top:12px; display:flex; justify-content:center; gap:10px;">
                <button class="btn btn-small" onclick="openUploadWindow()">&#128228; Upload</button>
                <a href="view_images.php?exhibit_id=<?= $exhibit_id ?>" class="btn btn-small"
                    target="_blank">&#128444;&#65039;
                    View Images</a>
                <a href="download_all_photos.php?exhibit_id=<?= $exhibit_id ?>" class="btn btn-small">&#128230;
                    Download All</a>
            </div>
        </div>

        <div class="panel">
            <h2 class="section-title">Uploaded Documents</h2>
            <?php if (empty($documents)): ?>
            <p class="empty-note">No documents uploaded yet.</p>
            <?php else: ?>
            <ul class="doc-list">
                <?php foreach ($documents as $doc): ?>
                <li>
                    <a href="download_document.php?doc_id=<?= htmlspecialchars($doc['id']) ?>">
                        <?= htmlspecialchars($doc['original_filename']) ?> &#128229;</a>
                    <?php if ($canManageDocuments): ?>
                    <form method="post" action="examination.php?exhibit_id=<?= $exhibit_id ?>" style="display:inline;">
                        <input type="hidden" name="delete_document" value="<?= $doc['id'] ?>">
                        <button type="submit" class="btn-small btn doc-delete-btn" title="Delete document"
                            onclick="return confirmDeleteWithReason(this.form, 'Delete this document?');">Delete</button>
                    </form>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <div style="text-align:center; margin-top:12px;">
                <button class="btn btn-small" onclick="openDocUploadWindow()">&#128228; Upload</button>
            </div>
        </div>
    </div>

</div>

<script>
const exhibitId = <?= json_encode($exhibit_id) ?>;

document.querySelectorAll('.tab-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const tab = btn.dataset.tab;
        document.querySelectorAll('.tab-btn').forEach(function (b) {
            b.classList.toggle('active', b.dataset.tab === tab);
        });
        document.querySelectorAll('.tab-panel').forEach(function (p) {
            p.classList.toggle('active', p.dataset.tabPanel === tab);
        });
    });
});

document.querySelectorAll('.stepper-stage[data-stage]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const stage = btn.dataset.stage;
        if (stage === '2') {
            const picker = document.getElementById('stage2-picker');
            picker.style.display = picker.style.display === 'none' ? 'flex' : 'none';
            return;
        }
        const value = stage === '1' ? 'Not Yet Started' : 'Complete';
        document.getElementById('status-form-value').value = value;
        document.getElementById('status-form').submit();
    });
});

function submitStage2() {
    const select = document.getElementById('stage2-select');
    document.getElementById('status-form-value').value = select.value;
    document.getElementById('status-form').submit();
}

function openUploadWindow() {
    const width = 800;
    const height = 500;
    const left = (screen.width - width) / 2;
    const top = (screen.height - height) / 2;
    window.open(
        `upload_photos_popup.php?exhibit_id=${exhibitId}`,
        'UploadPhotos',
        `width=${width},height=${height},left=${left},top=${top},resizable=no,scrollbars=no`
    );
}

function openDocUploadWindow() {
    const width = 700;
    const height = 600;
    const left = (screen.width - width) / 2;
    const top = (screen.height - height) / 2;
    window.open(
        `upload_documents.php?exhibit_id=${exhibitId}`,
        'UploadDocuments',
        `width=${width},height=${height},left=${left},top=${top}`
    );
}
</script>

</body>

</html>
