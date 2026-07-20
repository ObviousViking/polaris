<?php
// cargo_hold/case_report.php
//
// Case Report Builder - printable case summary. Checkboxes toggle visibility
// client-side (updateReport()); appendix images load lazily on demand.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/permissions.php';
require_permission($conn, 'case_report');
require_once '../includes/document_preview.php';
require_once '../includes/settings.php';

if (!isset($_GET['job_id'])) {
    echo "Job ID not specified.";
    exit();
}
$job_id = intval($_GET['job_id']);

$reportLogoUrl = null;
$reportLogoFilename = get_report_logo_filename($conn);
if ($reportLogoFilename !== null) {
    $storageConfig = get_storage_settings($conn);
    $reportLogoUrl = $storageConfig['paths']['report_logo_dir_url'] . $reportLogoFilename;
}

// Case details.
$stmt = $conn->prepare("
    SELECT j.custom_ref, j.date_time, j.initial_summary, j.oic, j.suspect,
           j.fingerprints, j.dna, j.malware, j.strategy_set, j.strategy_due,
           ct.type_name AS case_type, op.operation_name, st.status_name
    FROM jobs j
    LEFT JOIN case_types ct ON j.case_type_id = ct.case_type_id
    LEFT JOIN operations op ON j.operation = op.operation_id
    LEFT JOIN job_status st ON j.status_id = st.status_id
    WHERE j.job_id = ?
");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    echo "Case not found.";
    exit();
}

// All exhibits, grouped into a parent_id => [children] map.
$exhibits = [];
$exhibitsByParent = [];
$stmt = $conn->prepare("
    SELECT e.exhibit_id, e.exhibit_ref, e.item_description, e.status, e.urgency, e.parent_id,
           et.type_name AS exhibit_type_name, el.location_name,
           COALESCE(CONCAT(u.first_name, ' ', u.last_name), '') AS allocated_to_name
    FROM exhibits e
    LEFT JOIN exhibit_types et ON e.exhibit_type_id = et.exhibit_type_id
    LEFT JOIN exhibit_locations el ON e.location_id = el.location_id
    LEFT JOIN users u ON e.allocated_to = u.id
    WHERE e.job_id = ? AND e.deleted_at IS NULL
    ORDER BY e.exhibit_ref ASC
");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $exhibits[$row['exhibit_id']] = $row;
    $exhibitsByParent[$row['parent_id'] ?? 0][] = $row['exhibit_id'];
}
$stmt->close();

$mainExhibitIds = $exhibitsByParent[0] ?? [];
$allExhibitIds = array_keys($exhibits);

// Examination data (Process Builder field values + free-text notes) per exhibit.
$processesByExhibit = [];
if (!empty($allExhibitIds)) {
    $placeholders = implode(',', array_fill(0, count($allExhibitIds), '?'));
    $stmt = $conn->prepare("
        SELECT ep.id, ep.exhibit_id, ep.free_text, ep.created_at, pt.name AS process_type_name,
               COALESCE(CONCAT(u.first_name, ' ', u.last_name), '') AS examiner_name
        FROM exhibit_processes ep
        JOIN process_types pt ON ep.process_type_id = pt.id
        LEFT JOIN users u ON ep.updated_by = u.id
        WHERE ep.exhibit_id IN ($placeholders)
        ORDER BY ep.created_at ASC
    ");
    $stmt->bind_param(str_repeat('i', count($allExhibitIds)), ...$allExhibitIds);
    $stmt->execute();
    $res = $stmt->get_result();
    $processIds = [];
    while ($row = $res->fetch_assoc()) {
        $row['fields'] = [];
        $processesByExhibit[$row['exhibit_id']][$row['id']] = $row;
        $processIds[] = $row['id'];
    }
    $stmt->close();

    if (!empty($processIds)) {
        $pPlaceholders = implode(',', array_fill(0, count($processIds), '?'));
        $stmt = $conn->prepare("
            SELECT epv.exhibit_process_id, pf.field_label, epv.value
            FROM exhibit_process_values epv
            JOIN process_fields pf ON pf.id = epv.process_field_id
            WHERE epv.exhibit_process_id IN ($pPlaceholders) AND epv.value IS NOT NULL AND epv.value != ''
            ORDER BY pf.sort_order ASC
        ");
        $stmt->bind_param(str_repeat('i', count($processIds)), ...$processIds);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            foreach ($processesByExhibit as $exId => $procs) {
                if (isset($procs[$row['exhibit_process_id']])) {
                    $processesByExhibit[$exId][$row['exhibit_process_id']]['fields'][] = $row;
                    break;
                }
            }
        }
        $stmt->close();
    }
}

// Photos per exhibit.
$photosByExhibit = [];
$config = get_storage_settings($conn);
if (!empty($allExhibitIds)) {
    $placeholders = implode(',', array_fill(0, count($allExhibitIds), '?'));
    $stmt = $conn->prepare("SELECT exhibit_id, file_name, file_path FROM exhibit_photos WHERE exhibit_id IN ($placeholders) ORDER BY uploaded_at ASC");
    $stmt->bind_param(str_repeat('i', count($allExhibitIds)), ...$allExhibitIds);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $row['file_url'] = str_replace($config['paths']['photo_dir_fs'], $config['paths']['photo_dir_url'], $row['file_path']);
        $photosByExhibit[$row['exhibit_id']][] = $row;
    }
    $stmt->close();
}

// Documents per exhibit.
$documentsByExhibit = [];
if (!empty($allExhibitIds)) {
    $placeholders = implode(',', array_fill(0, count($allExhibitIds), '?'));
    $stmt = $conn->prepare("SELECT exhibit_id, original_filename, file_path FROM exhibit_documents WHERE exhibit_id IN ($placeholders) ORDER BY uploaded_at ASC");
    $stmt->bind_param(str_repeat('i', count($allExhibitIds)), ...$allExhibitIds);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $row['file_url'] = str_replace($config['paths']['document_dir_fs'], $config['paths']['document_dir_url'], $row['file_path']);
        $row['embeddable'] = is_embeddable_extension($row['original_filename']);
        $documentsByExhibit[$row['exhibit_id']][] = $row;
    }
    $stmt->close();
}

// Case updates (both types).
$updates = [];
$stmt = $conn->prepare("
    SELECT cu.update_type, cu.update_text, cu.update_date, CONCAT(u.first_name, ' ', u.last_name) AS author_name
    FROM case_updates cu
    JOIN users u ON cu.user_id = u.id
    WHERE cu.job_id = ?
    ORDER BY cu.update_date ASC
");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $updates[] = $row;
}
$stmt->close();

// Case-level documents, tracked separately from $documentsByExhibit.
$caseDocuments = [];
$stmt = $conn->prepare("SELECT id, original_filename, file_path FROM case_documents WHERE job_id = ? ORDER BY uploaded_at ASC");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $row['embeddable'] = is_embeddable_extension($row['original_filename']);
    $caseDocuments[] = $row;
}
$stmt->close();

// Just lists which documents are embeddable - case_report_previews.php
// renders and caches the actual page images on demand (see loadAppendixPreviews()).
$appendixManifest = [];
foreach ($documentsByExhibit as $exId => $docs) {
    foreach ($docs as $doc) {
        if ($doc['embeddable']) {
            $appendixManifest[] = [
                'key' => $doc['file_path'],
                'label' => $exhibits[$exId]['exhibit_ref'],
                'filename' => $doc['original_filename'],
                'scope' => 'exhibit',
                'exhibitId' => (int) $exId,
            ];
        }
    }
}
foreach ($caseDocuments as $doc) {
    if ($doc['embeddable']) {
        $appendixManifest[] = [
            'key' => $doc['file_path'],
            'label' => 'Case Document',
            'filename' => $doc['original_filename'],
            'scope' => 'case',
            'exhibitId' => null,
        ];
    }
}

include '../header.php';
?>
<style>
    .builder {
        display: flex;
        max-width: 1700px;
        width: 95%;
        margin: 120px auto 20px auto;
        gap: 20px;
    }

    .controls {
        flex: 0 0 300px;
        background: var(--polaris-surface);
        padding: 15px;
        border-radius: 5px;
        height: fit-content;
        position: sticky;
        top: 120px;
    }

    .controls h2 {
        font-size: 16px;
        margin: 0 0 10px 0;
    }

    .controls h3 {
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--polaris-text-muted);
        margin: 15px 0 6px 0;
        border-top: 1px solid var(--polaris-border);
        padding-top: 12px;
    }

    .controls h3:first-of-type {
        border-top: none;
        padding-top: 0;
        margin-top: 0;
    }

    .exhibit-picker {
        max-height: 220px;
        overflow-y: auto;
        border: 1px solid var(--polaris-border);
        border-radius: 4px;
        padding: 6px;
        background: var(--polaris-surface-deep);
    }

    .exhibit-picker label {
        display: block;
        font-size: 13px;
        padding: 3px 0;
    }

    .exhibit-picker label small {
        color: var(--polaris-text-muted);
    }

    .picker-buttons {
        display: flex;
        gap: 6px;
        margin-top: 6px;
    }

    .picker-buttons button {
        flex: 1;
        padding: 4px 8px;
        font-size: 12px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border: none;
        border-radius: 3px;
        cursor: pointer;
    }

    .picker-buttons button:hover {
        background: var(--polaris-accent-hover);
    }

    .controls .option-row {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 13px;
        margin-bottom: 6px;
    }

    .print-btn {
        display: block;
        width: 100%;
        margin-top: 15px;
        padding: 8px;
        background: var(--polaris-success-strong);
        color: var(--polaris-text);
        border: none;
        border-radius: 3px;
        font-size: 14px;
        cursor: pointer;
    }

    .print-btn:hover {
        background: var(--polaris-success-strong-hover);
    }

    .zip-btn {
        background: var(--polaris-accent);
        margin-top: 8px;
    }

    .zip-btn:hover {
        background: var(--polaris-accent-hover);
    }

    .preview {
        flex: 1;
        min-width: 0;
        background: var(--polaris-surface);
        padding: 30px;
        border-radius: 5px;
    }

    .report-header {
        display: flex;
        align-items: center;
        gap: 20px;
        text-align: center;
        border-bottom: 2px solid var(--polaris-border);
        padding-bottom: 18px;
        margin-bottom: 20px;
    }

    .report-header.has-logo {
        text-align: left;
    }

    .report-logo {
        max-height: 70px;
        max-width: 200px;
        flex: 0 0 auto;
        display: block;
    }

    .report-header-titles {
        flex: 1;
        min-width: 0;
    }

    .report-header h1 {
        margin: 0 0 4px 0;
        font-size: 22px;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        color: var(--polaris-text-muted);
    }

    .report-header-ref {
        margin: 0 0 4px 0;
        font-size: 26px;
        font-weight: 600;
    }

    .report-header-generated {
        margin: 0;
        font-size: 12px;
        color: var(--polaris-text-muted);
    }

    .case-summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 10px 20px;
        background: var(--polaris-surface-deep);
        border: 1px solid var(--polaris-border);
        border-radius: 5px;
        padding: 14px 16px;
    }

    .cs-field {
        display: flex;
        flex-direction: column;
        gap: 2px;
        font-size: 13px;
    }

    .cs-label {
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-size: 11px;
        color: var(--polaris-text-muted);
    }

    .cs-value {
        font-size: 14px;
    }

    .report-summary-text {
        margin-top: 14px;
    }

    .badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        white-space: nowrap;
    }

    .badge-success {
        background: var(--polaris-success-bg);
        color: var(--polaris-success-text);
    }

    .badge-danger {
        background: var(--polaris-error-bg);
        color: var(--polaris-error-text);
    }

    .report-section-title {
        font-size: 18px;
        border-bottom: 1px solid var(--polaris-border);
        padding-bottom: 5px;
        margin: 25px 0 12px 0;
    }

    .report-exhibit {
        border: 1px solid var(--polaris-border);
        border-radius: 5px;
        padding: 15px;
        margin-bottom: 15px;
    }

    .report-exhibit.is-sub {
        margin-left: 25px;
        margin-top: 10px;
        background: var(--polaris-surface-deep);
    }

    .report-exhibit h4 {
        margin: 0 0 8px 0;
    }

    .report-exhibit .meta-line {
        font-size: 13px;
        color: var(--polaris-text-muted);
        margin-bottom: 10px;
    }

    .report-subsection {
        margin-top: 12px;
    }

    .report-subsection h5 {
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--polaris-text-placeholder);
        margin: 0 0 6px 0;
    }

    .process-block {
        background: var(--polaris-surface);
        border-left: 3px solid var(--polaris-accent);
        border-radius: 4px;
        padding: 10px;
        margin-bottom: 8px;
        font-size: 13px;
    }

    .process-block .field-row {
        margin: 3px 0;
    }

    .process-block .field-row strong {
        display: inline-block;
        min-width: 140px;
        color: var(--polaris-text-placeholder);
    }

    .photo-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .photo-grid img {
        width: 120px;
        height: 120px;
        object-fit: cover;
        border-radius: 4px;
        border: 1px solid var(--polaris-border);
    }

    .doc-list {
        list-style: none;
        padding: 0;
        margin: 0;
        font-size: 13px;
    }

    .doc-list li {
        padding: 2px 0;
    }

    .report-update {
        border-left: 3px solid var(--polaris-accent);
        background: var(--polaris-surface-deep);
        border-radius: 4px;
        padding: 10px;
        margin-bottom: 8px;
        font-size: 13px;
    }

    .report-update .update-meta {
        color: var(--polaris-text-muted);
        font-size: 12px;
        margin-bottom: 4px;
    }

    .appendix-item {
        margin-bottom: 20px;
    }

    .appendix-item h5 {
        font-size: 14px;
        margin-bottom: 6px;
    }

    .appendix-page {
        margin-bottom: 10px;
    }

    .appendix-page img {
        max-width: 100%;
        border: 1px solid var(--polaris-border);
        border-radius: 4px;
        display: block;
    }

    .appendix-page-num {
        font-size: 11px;
        color: var(--polaris-text-muted);
        margin-top: 3px;
    }

    #appendix-loading {
        text-align: center;
        padding: 30px 0;
        color: var(--polaris-text-muted);
        font-size: 13px;
    }

    .appendix-spinner {
        width: 26px;
        height: 26px;
        margin: 0 auto 12px auto;
        border: 3px solid var(--polaris-border);
        border-top-color: var(--polaris-accent);
        border-radius: 50%;
        animation: appendix-spin 0.8s linear infinite;
    }

    @keyframes appendix-spin {
        to { transform: rotate(360deg); }
    }

    .empty-note {
        color: var(--polaris-text-faint);
        font-style: italic;
        font-size: 13px;
    }

    @media print {
        body * {
            visibility: hidden;
        }

        .preview, .preview * {
            visibility: visible;
        }

        .preview {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            background: #fff !important;
            color: #000 !important;
            padding: 0;
        }

        .report-exhibit {
            border-color: #999 !important;
            page-break-inside: avoid;
        }

        .report-exhibit.is-sub {
            background: #f5f5f5 !important;
        }

        .process-block, .report-update {
            background: #f5f5f5 !important;
            border-left-color: #666 !important;
            page-break-inside: avoid;
        }

        .report-section-title {
            /* Keeps a title glued to whatever follows it - avoids orphaned headings. */
            page-break-after: avoid;
        }

        .report-header {
            page-break-before: avoid;
        }

        /* Appendix is its own chapter - always starts on a fresh page. */
        #report-appendix-section {
            page-break-before: always;
        }

        .appendix-item {
            page-break-before: always;
        }

        .appendix-page {
            page-break-inside: avoid;
        }

        #appendix-loading {
            display: none !important;
        }
    }
</style>

<div class="builder">
    <!-- Left: Controls -->
    <div class="controls">
        <h2>Case Report Builder</h2>

        <h3>Exhibits</h3>
        <div class="picker-buttons">
            <button type="button" onclick="setAllExhibits(true)">Select All</button>
            <button type="button" onclick="setAllExhibits(false)">Deselect All</button>
        </div>
        <div class="exhibit-picker">
            <?php if (empty($mainExhibitIds)): ?>
            <p class="empty-note">No exhibits on this case.</p>
            <?php else: ?>
            <?php foreach ($mainExhibitIds as $exId): ?>
            <?php $ex = $exhibits[$exId]; ?>
            <label>
                <input type="checkbox" class="exhibit-checkbox" id="exhibit_<?php echo $exId; ?>" checked
                    onchange="updateReport()">
                <?php echo htmlspecialchars($ex['exhibit_ref']); ?>
                <small>(<?php echo htmlspecialchars($ex['exhibit_type_name'] ?? ''); ?>)</small>
            </label>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="option-row" style="margin-top:8px;">
            <input type="checkbox" id="opt_sub_exhibits" checked onchange="updateReport()">
            <label for="opt_sub_exhibits">Include sub-exhibits of selected exhibits</label>
        </div>

        <h3>Exhibit Detail</h3>
        <div class="option-row">
            <input type="checkbox" id="opt_examination" checked onchange="updateReport()">
            <label for="opt_examination">Examination process data</label>
        </div>
        <div class="option-row">
            <input type="checkbox" id="opt_photos" checked onchange="updateReport()">
            <label for="opt_photos">Exhibit photos</label>
        </div>
        <div class="option-row">
            <input type="checkbox" id="opt_documents" checked onchange="updateReport()">
            <label for="opt_documents">Attached documents (list)</label>
        </div>

        <h3>Case Updates</h3>
        <div class="option-row">
            <input type="checkbox" id="opt_case_updates" checked onchange="updateReport()">
            <label for="opt_case_updates">Case Updates</label>
        </div>
        <div class="option-row">
            <input type="checkbox" id="opt_communications" checked onchange="updateReport()">
            <label for="opt_communications">Communications</label>
        </div>

        <h3>Case-Level</h3>
        <div class="option-row">
            <input type="checkbox" id="opt_case_documents" checked onchange="updateReport()">
            <label for="opt_case_documents">Case documents (list)</label>
        </div>

        <h3>Documents Appendix</h3>
        <div class="option-row">
            <input type="checkbox" id="opt_appendix" onchange="onAppendixToggle()">
            <label for="opt_appendix">Embed TXT / PDF / DOCX documents as an appendix (page images)</label>
        </div>

        <button type="button" class="print-btn" onclick="window.print()">Print / Save as PDF</button>
        <button type="button" class="print-btn zip-btn" onclick="downloadAttachmentsZip()">Download Attachments (ZIP)</button>
    </div>

    <!-- Right: Live Preview -->
    <div class="preview">
        <div class="report-header<?php echo $reportLogoUrl ? ' has-logo' : ''; ?>">
            <?php if ($reportLogoUrl): ?>
            <img src="<?php echo htmlspecialchars($reportLogoUrl); ?>" alt="Logo" class="report-logo">
            <?php endif; ?>
            <div class="report-header-titles">
                <h1>Case Report</h1>
                <p class="report-header-ref"><?php echo htmlspecialchars($job['custom_ref']); ?></p>
                <p class="report-header-generated">Generated <?php echo date('d/m/Y H:i'); ?></p>
            </div>
        </div>

        <div class="report-section-title" style="margin-top:0;">Case Summary</div>
        <div class="case-summary-grid">
            <div class="cs-field"><span class="cs-label">Date/Time</span><span class="cs-value"><?php echo htmlspecialchars($job['date_time'] ?: '—'); ?></span></div>
            <div class="cs-field"><span class="cs-label">Case Type</span><span class="cs-value"><?php echo htmlspecialchars($job['case_type'] ?: '—'); ?></span></div>
            <div class="cs-field"><span class="cs-label">Operation</span><span class="cs-value"><?php echo htmlspecialchars($job['operation_name'] ?: 'None'); ?></span></div>
            <div class="cs-field"><span class="cs-label">OIC</span><span class="cs-value"><?php echo htmlspecialchars($job['oic'] ?: '—'); ?></span></div>
            <div class="cs-field"><span class="cs-label">Status</span><span class="cs-value"><?php echo htmlspecialchars($job['status_name'] ?: '—'); ?></span></div>
            <div class="cs-field"><span class="cs-label">Strategy Set</span><span class="cs-value"><?php echo htmlspecialchars($job['strategy_set'] ?: '—'); ?></span></div>
            <div class="cs-field"><span class="cs-label">Strategy Due</span><span class="cs-value"><?php echo htmlspecialchars($job['strategy_due'] ?: '—'); ?></span></div>
            <div class="cs-field">
                <span class="cs-label">Fingerprints</span>
                <span class="cs-value"><span class="badge <?php echo $job['fingerprints'] ? 'badge-danger' : 'badge-success'; ?>"><?php echo $job['fingerprints'] ? 'Concern' : 'None'; ?></span></span>
            </div>
            <div class="cs-field">
                <span class="cs-label">DNA</span>
                <span class="cs-value"><span class="badge <?php echo $job['dna'] ? 'badge-danger' : 'badge-success'; ?>"><?php echo $job['dna'] ? 'Concern' : 'None'; ?></span></span>
            </div>
            <div class="cs-field">
                <span class="cs-label">Malware</span>
                <span class="cs-value"><span class="badge <?php echo $job['malware'] ? 'badge-danger' : 'badge-success'; ?>"><?php echo $job['malware'] ? 'Concern' : 'None'; ?></span></span>
            </div>
        </div>
        <?php if (!empty($job['initial_summary'])): ?>
        <p class="report-summary-text"><?php echo nl2br(htmlspecialchars($job['initial_summary'])); ?></p>
        <?php endif; ?>

        <div class="report-section-title">Exhibits</div>
        <div id="report-exhibits">
            <?php
            function render_exhibit_report_block($exId, $exhibits, $exhibitsByParent, $processesByExhibit, $photosByExhibit, $documentsByExhibit, $isSub = false)
            {
                $ex = $exhibits[$exId];
                ?>
                <div class="report-exhibit <?php echo $isSub ? 'is-sub' : ''; ?>" data-exhibit-id="<?php echo $exId; ?>"
                    data-is-sub="<?php echo $isSub ? '1' : '0'; ?>" data-parent-id="<?php echo (int) ($ex['parent_id'] ?? 0); ?>">
                    <h4><?php echo htmlspecialchars($ex['exhibit_ref']); ?></h4>
                    <div class="meta-line">
                        <?php echo htmlspecialchars($ex['exhibit_type_name'] ?? ''); ?> &middot;
                        <?php echo htmlspecialchars($ex['item_description'] ?? ''); ?> &middot;
                        Status: <?php echo htmlspecialchars($ex['status'] ?? ''); ?> &middot;
                        Priority: <?php echo htmlspecialchars($ex['urgency'] ?? ''); ?><br>
                        Location: <?php echo htmlspecialchars($ex['location_name'] ?? ''); ?> &middot;
                        Allocated To: <?php echo htmlspecialchars($ex['allocated_to_name'] ?: 'Unassigned'); ?>
                    </div>

                    <div class="report-subsection report-section-examination" id="report-exam-<?php echo $exId; ?>">
                        <h5>Examination</h5>
                        <?php $procs = $processesByExhibit[$exId] ?? []; ?>
                        <?php if (empty($procs)): ?>
                        <p class="empty-note">No examination processes recorded.</p>
                        <?php else: ?>
                        <?php foreach ($procs as $proc): ?>
                        <div class="process-block">
                            <div class="field-row"><strong>Process:</strong> <?php echo htmlspecialchars($proc['process_type_name']); ?></div>
                            <div class="field-row"><strong>Examiner:</strong> <?php echo htmlspecialchars($proc['examiner_name'] ?: 'Unknown'); ?></div>
                            <?php foreach ($proc['fields'] as $field): ?>
                            <div class="field-row"><strong><?php echo htmlspecialchars($field['field_label']); ?>:</strong>
                                <?php echo htmlspecialchars($field['value']); ?></div>
                            <?php endforeach; ?>
                            <?php if (!empty($proc['free_text'])): ?>
                            <div class="field-row"><strong>Notes:</strong> <?php echo $proc['free_text']; ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="report-subsection report-section-photos" id="report-photos-<?php echo $exId; ?>">
                        <h5>Photos</h5>
                        <?php $photos = $photosByExhibit[$exId] ?? []; ?>
                        <?php if (empty($photos)): ?>
                        <p class="empty-note">No photos attached.</p>
                        <?php else: ?>
                        <div class="photo-grid">
                            <?php foreach ($photos as $photo): ?>
                            <img src="<?php echo htmlspecialchars($photo['file_url']); ?>"
                                alt="<?php echo htmlspecialchars($photo['file_name']); ?>">
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="report-subsection report-section-documents" id="report-docs-<?php echo $exId; ?>">
                        <h5>Documents</h5>
                        <?php $docs = $documentsByExhibit[$exId] ?? []; ?>
                        <?php if (empty($docs)): ?>
                        <p class="empty-note">No documents attached.</p>
                        <?php else: ?>
                        <ul class="doc-list">
                            <?php foreach ($docs as $doc): ?>
                            <li><?php echo htmlspecialchars($doc['original_filename']); ?>
                                <?php echo $doc['embeddable'] ? ' <small>(embeddable)</small>' : ''; ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </div>

                    <?php foreach ($exhibitsByParent[$exId] ?? [] as $subId): ?>
                    <?php render_exhibit_report_block($subId, $exhibits, $exhibitsByParent, $processesByExhibit, $photosByExhibit, $documentsByExhibit, true); ?>
                    <?php endforeach; ?>
                </div>
                <?php
            }

            foreach ($mainExhibitIds as $exId) {
                render_exhibit_report_block($exId, $exhibits, $exhibitsByParent, $processesByExhibit, $photosByExhibit, $documentsByExhibit, false);
            }
            ?>
        </div>

        <div class="report-section-title" id="report-updates-section">Case Updates</div>
        <div id="report-updates">
            <?php if (empty($updates)): ?>
            <p class="empty-note">No case updates recorded.</p>
            <?php else: ?>
            <?php foreach ($updates as $update): ?>
            <div class="report-update" data-type="<?php echo htmlspecialchars($update['update_type']); ?>">
                <div class="update-meta">
                    <?php echo htmlspecialchars($update['update_type']); ?> &middot;
                    <?php echo htmlspecialchars($update['author_name']); ?> &middot;
                    <?php echo htmlspecialchars($update['update_date']); ?>
                </div>
                <?php echo $update['update_text']; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="report-section-title" id="report-case-documents-section">Case Documents</div>
        <div id="report-case-documents">
            <?php if (empty($caseDocuments)): ?>
            <p class="empty-note">No case documents attached.</p>
            <?php else: ?>
            <ul class="doc-list">
                <?php foreach ($caseDocuments as $doc): ?>
                <li><?php echo htmlspecialchars($doc['original_filename']); ?>
                    <?php echo $doc['embeddable'] ? ' <small>(embeddable)</small>' : ''; ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>

        <div class="report-section-title" id="report-appendix-section" style="display:none;">Document Appendix</div>
        <div id="report-appendix">
            <?php if (empty($appendixManifest)): ?>
            <p class="empty-note">No embeddable documents attached.</p>
            <?php else: ?>
            <div id="appendix-loading" style="display:none;">
                <div class="appendix-spinner"></div>
                <p id="appendix-loading-text">Gathering case info...</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
var appendixManifest = <?php echo json_encode($appendixManifest); ?>;
var appendixJobId = <?php echo json_encode($job_id); ?>;
var appendixLoaded = false;
var appendixLoading = false;
var appendixMessages = ['Gathering case info...', 'Converting documents...', 'Collating notes...', 'Almost done...'];
var appendixMessageTimer = null;

function downloadAttachmentsZip() {
    var exhibitIds = [];
    document.querySelectorAll('.exhibit-checkbox').forEach(function(cb) {
        if (cb.checked) {
            exhibitIds.push(cb.id.replace('exhibit_', ''));
        }
    });
    if (exhibitIds.length === 0) {
        alert('Select at least one exhibit first.');
        return;
    }
    var subOn = document.getElementById('opt_sub_exhibits').checked ? '1' : '0';
    var caseDocsOn = document.getElementById('opt_case_documents').checked ? '1' : '0';
    var url = 'case_report_zip.php?job_id=' + appendixJobId
        + '&exhibit_ids=' + exhibitIds.join(',')
        + '&include_sub=' + subOn
        + '&include_case_documents=' + caseDocsOn;
    window.location.href = url;
}

function setAllExhibits(checked) {
    document.querySelectorAll('.exhibit-checkbox').forEach(function(cb) {
        cb.checked = checked;
    });
    updateReport();
}

function onAppendixToggle() {
    if (document.getElementById('opt_appendix').checked) {
        loadAppendixPreviews();
    }
    updateReport();
}

function buildAppendixShell() {
    var container = document.getElementById('report-appendix');
    appendixManifest.forEach(function(item) {
        var div = document.createElement('div');
        div.className = 'appendix-item';
        div.setAttribute('data-doc-key', item.key);
        if (item.scope === 'case') {
            div.setAttribute('data-scope', 'case');
        } else {
            div.setAttribute('data-exhibit-id', item.exhibitId);
        }
        div.style.display = 'none';

        var h5 = document.createElement('h5');
        h5.textContent = item.label + ' - ' + item.filename;
        div.appendChild(h5);

        var body = document.createElement('div');
        body.className = 'appendix-item-body';
        div.appendChild(body);

        container.appendChild(div);
    });
}

function startAppendixMessageRotation() {
    var idx = 0;
    var el = document.getElementById('appendix-loading-text');
    el.textContent = appendixMessages[0];
    appendixMessageTimer = setInterval(function() {
        idx = (idx + 1) % appendixMessages.length;
        el.textContent = appendixMessages[idx];
    }, 1800);
}

function stopAppendixMessageRotation() {
    if (appendixMessageTimer) {
        clearInterval(appendixMessageTimer);
        appendixMessageTimer = null;
    }
}

// Fetched only the first time the appendix is turned on; result is kept so
// toggling it off and back on doesn't refetch.
function loadAppendixPreviews() {
    if (appendixLoaded || appendixLoading) {
        return;
    }
    if (appendixManifest.length === 0) {
        appendixLoaded = true;
        return;
    }

    appendixLoading = true;
    buildAppendixShell();
    var loadingEl = document.getElementById('appendix-loading');
    loadingEl.style.display = '';
    startAppendixMessageRotation();

    fetch('case_report_previews.php?job_id=' + appendixJobId)
        .then(function(response) { return response.json(); })
        .then(function(data) {
            document.querySelectorAll('.appendix-item').forEach(function(div) {
                var key = div.getAttribute('data-doc-key');
                var body = div.querySelector('.appendix-item-body');
                var preview = data[key];
                if (!body) {
                    return;
                }
                if (!preview) {
                    body.innerHTML = '<p class="empty-note">Could not render this document.</p>';
                    return;
                }
                var html = '';
                preview.images.forEach(function(imageDataUri, i) {
                    html += '<div class="appendix-page"><img src="' + imageDataUri + '" alt="Page ' + (i + 1) + '">'
                        + '<div class="appendix-page-num">Page ' + (i + 1) + ' of ' + preview.total_pages + '</div></div>';
                });
                if (preview.truncated) {
                    html += '<p class="empty-note">Showing the first ' + preview.images.length + ' of '
                        + preview.total_pages + ' pages.</p>';
                }
                body.innerHTML = html;
            });
        })
        .catch(function() {
            document.getElementById('appendix-loading-text').textContent = 'Failed to load document previews.';
        })
        .finally(function() {
            appendixLoading = false;
            appendixLoaded = true;
            stopAppendixMessageRotation();
            loadingEl.style.display = 'none';
            updateReport();
        });
}

function updateReport() {
    var subOn = document.getElementById('opt_sub_exhibits').checked;
    var examOn = document.getElementById('opt_examination').checked;
    var photosOn = document.getElementById('opt_photos').checked;
    var docsOn = document.getElementById('opt_documents').checked;
    var caseUpdatesOn = document.getElementById('opt_case_updates').checked;
    var commsOn = document.getElementById('opt_communications').checked;
    var caseDocsOn = document.getElementById('opt_case_documents').checked;
    var appendixOn = document.getElementById('opt_appendix').checked;

    var visibleExhibitIds = {};

    document.querySelectorAll('.report-exhibit[data-is-sub="0"]').forEach(function(block) {
        var id = block.getAttribute('data-exhibit-id');
        var cb = document.getElementById('exhibit_' + id);
        var checked = cb ? cb.checked : false;
        block.style.display = checked ? '' : 'none';
        if (checked) {
            visibleExhibitIds[id] = true;
        }
        toggleExhibitSubsections(block, examOn, photosOn, docsOn);
    });

    document.querySelectorAll('.report-exhibit[data-is-sub="1"]').forEach(function(block) {
        var id = block.getAttribute('data-exhibit-id');
        var parentId = block.getAttribute('data-parent-id');
        var visible = subOn && !!visibleExhibitIds[parentId];
        block.style.display = visible ? '' : 'none';
        if (visible) {
            visibleExhibitIds[id] = true;
        }
        toggleExhibitSubsections(block, examOn, photosOn, docsOn);
    });

    document.querySelectorAll('.report-update').forEach(function(block) {
        var type = block.getAttribute('data-type');
        var show = (type === 'Case Update' && caseUpdatesOn) || (type === 'Communication' && commsOn);
        block.style.display = show ? '' : 'none';
    });
    document.getElementById('report-updates-section').style.display = (caseUpdatesOn || commsOn) ? '' : 'none';

    document.getElementById('report-case-documents').style.display = caseDocsOn ? '' : 'none';
    document.getElementById('report-case-documents-section').style.display = caseDocsOn ? '' : 'none';

    document.querySelectorAll('.appendix-item').forEach(function(block) {
        var scope = block.getAttribute('data-scope');
        var visible;
        if (scope === 'case') {
            // Independent of the "Case documents (list)" checkbox.
            visible = appendixOn;
        } else {
            var exId = block.getAttribute('data-exhibit-id');
            visible = appendixOn && !!visibleExhibitIds[exId];
        }
        block.style.display = visible ? '' : 'none';
    });
    document.getElementById('report-appendix-section').style.display = appendixOn ? '' : 'none';
}

function toggleExhibitSubsections(block, examOn, photosOn, docsOn) {
    var id = block.getAttribute('data-exhibit-id');
    var examSection = document.getElementById('report-exam-' + id);
    var photosSection = document.getElementById('report-photos-' + id);
    var docsSection = document.getElementById('report-docs-' + id);
    if (examSection) {
        examSection.style.display = examOn ? '' : 'none';
    }
    if (photosSection) {
        photosSection.style.display = photosOn ? '' : 'none';
    }
    if (docsSection) {
        docsSection.style.display = docsOn ? '' : 'none';
    }
}

document.addEventListener('DOMContentLoaded', updateReport);
</script>
