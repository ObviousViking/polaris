<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';

// Ensure a job_id is provided.
if (!isset($_GET['job_id'])) {
    echo "Job ID not specified.";
    exit();
}
$job_id = intval($_GET['job_id']);

// Surfaces delete_exhibit.php/delete_update.php's ?error=reason_required redirect.
$pageError = "";
if (($_GET['error'] ?? '') === 'reason_required') {
    $pageError = "That deletion was not completed - a reason is required (System Management > System Settings > Require a reason for every deletion).";
}

// Only admins/super may delete or restore exhibits.
$roleStmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$roleStmt->bind_param("i", $_SESSION['user_id']);
$roleStmt->execute();
$roleStmt->bind_result($currentUserRole);
$roleStmt->fetch();
$roleStmt->close();
$canDeleteExhibits = ($currentUserRole === 'admin' || $currentUserRole === 'super');

// Retrieve job details along with lookup data.
$stmt = $conn->prepare("
    SELECT 
        j.job_id, 
        j.custom_ref, 
        j.date_time, 
        j.initial_summary, 
        j.oic, 
        j.customer_id, 
        j.lead_force_id, 
        j.suspect, 
        j.fingerprints, 
        j.dna, 
        j.malware, 
        j.status_id, 
        j.strategy_set, 
        j.strategy_due, 
        j.case_type_id,
        ct.type_name AS case_type,
        op.operation_name AS operation_name,
        st.status_name AS status_name
    FROM jobs j
    LEFT JOIN case_types ct ON j.case_type_id = ct.case_type_id
    LEFT JOIN operations op ON j.operation = op.operation_id
    LEFT JOIN job_status st ON j.status_id = st.status_id
    WHERE j.job_id = ?
");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$stmt->bind_result(
    $job_id, 
    $custom_ref, 
    $date_time, 
    $initial_summary, 
    $oic, 
    $customer_id, 
    $lead_force_id, 
    $suspect, 
    $fingerprints, 
    $dna, 
    $malware, 
    $status_id, 
    $strategy_set, 
    $strategy_due, 
    $case_type_id, 
    $case_type, 
    $operation_name, 
    $status_name
);

if (!$stmt->fetch()) {
    echo "Job not found.";
    exit();
}
$stmt->close();

// Fetch exhibits for this job with location name, exhibit type, and allocated user name.
$exhibits = [];
$stmtEx = $conn->prepare("
    SELECT 
        e.exhibit_id,
        e.exhibit_ref, 
        e.item_description, 
        et.type_name AS exhibit_type,
        e.urgency, 
        e.status, 
        e.time_in, 
        e.time_out, 
        e.allocated_to,
        COALESCE(CONCAT(u.first_name, ' ', u.last_name), '') AS allocated_to_name,
        el.location_name
    FROM exhibits e
    JOIN exhibit_types et ON e.exhibit_type_id = et.exhibit_type_id
    JOIN exhibit_locations el ON e.location_id = el.location_id
    LEFT JOIN users u ON e.allocated_to = u.id
    WHERE e.job_id = ? AND e.deleted_at IS NULL AND e.parent_id IS NULL
    ORDER BY e.time_in DESC
");
$stmtEx->bind_param("i", $job_id);
$stmtEx->execute();
$resultEx = $stmtEx->get_result();
while ($row = $resultEx->fetch_assoc()) {
    $exhibits[] = [
        'exhibit_id'      => $row['exhibit_id'],
        'exhibit_ref'     => $row['exhibit_ref'] ?? '',
        'item_description'=> $row['item_description'] ?? '',
        'exhibit_type'    => $row['exhibit_type'] ?? '',
        'urgency'         => $row['urgency'] ?? '',
        'status'          => $row['status'] ?? '',
        'time_in'         => $row['time_in'] ?? '',
        'time_out'        => $row['time_out'] ?? '',
        'allocated_to'    => $row['allocated_to'],  // integer value
        'allocated_to_name' => $row['allocated_to_name'],
        'location_name'   => $row['location_name'] ?? ''
    ];
}
$stmtEx->close();

// Deleted exhibits (admins only), shown separately from the working list.
$deletedExhibits = [];
if ($canDeleteExhibits) {
    $stmtDel = $conn->prepare("
        SELECT e.exhibit_id, e.exhibit_ref, e.item_description, e.deleted_at,
               CONCAT(u.first_name, ' ', u.last_name) AS deleted_by_name
        FROM exhibits e
        LEFT JOIN users u ON e.deleted_by = u.id
        WHERE e.job_id = ? AND e.deleted_at IS NOT NULL AND e.parent_id IS NULL
        ORDER BY e.deleted_at DESC
    ");
    $stmtDel->bind_param("i", $job_id);
    $stmtDel->execute();
    $resultDel = $stmtDel->get_result();
    while ($row = $resultDel->fetch_assoc()) {
        $deletedExhibits[] = $row;
    }
    $stmtDel->close();
}


// Fetch exported items
$exported_items = [];
$stmt = $conn->prepare("
    SELECT ei.*, 
           CONCAT(u1.first_name, ' ', u1.last_name) AS extracted_by_name,
           CONCAT(u2.first_name, ' ', u2.last_name) AS assigned_to_name
    FROM exported_items ei
    LEFT JOIN users u1 ON ei.extracted_by = u1.id
    LEFT JOIN users u2 ON ei.assigned_to = u2.id
    WHERE ei.job_id = ?
    ORDER BY ei.extraction_ref
");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $exported_items[] = $row;
}
$stmt->close();

// Fetch produced exhibits
$produced_exhibits = [];
$stmt = $conn->prepare("
    SELECT pe.*, CONCAT(u.first_name, ' ', u.last_name) AS extracted_by_name
    FROM produced_exhibits pe
    LEFT JOIN users u ON pe.extracted_by = u.id
    WHERE pe.job_id = ?
    ORDER BY pe.exhibit_ref
");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $produced_exhibits[] = $row;
}
$stmt->close();

// Fetch case documents
$case_documents = [];
$stmt = $conn->prepare("
    SELECT cd.id, cd.original_filename, cd.uploaded_at, CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by_name
    FROM case_documents cd
    LEFT JOIN users u ON cd.uploaded_by = u.id
    WHERE cd.job_id = ?
    ORDER BY cd.uploaded_at DESC
");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $case_documents[] = $row;
}
$stmt->close();

include '../header.php';
?>

<style>
    /* header.php's own body{} already sets margin/background/color/font-family
       page-wide. This page used to redeclare a second <!DOCTYPE html><html>
       <head> here with its own body{} - browsers tolerate the invalid
       nesting, but that stray rule won the cascade and bled its font into
       the real header/nav above it. Scoped to .page-container instead,
       which already clears the fixed header via its margin-top. */

    /* Container splits sidebar and main content. max-width/width matches
       the pattern used on examination.php so the page scales with the
       viewport instead of being capped at a fixed pixel width. */
    .page-container {
        display: flex;
        max-width: 1700px;
        width: 95%;
        margin: 120px auto 20px auto;
        gap: 20px;
        background: var(--polaris-surface-deep);
    }

    .page-error {
        max-width: 1700px;
        width: 95%;
        margin: 120px auto 0 auto;
        padding: 10px 15px;
        background: var(--polaris-error-bg);
        color: var(--polaris-error-text);
        border-radius: 4px;
        box-sizing: border-box;
    }

    .page-error + .page-container {
        margin-top: 15px;
    }

    .sheet-table-wrapper {
        overflow-x: auto;
        width: 100%;
        margin-bottom: 12px;
    }

    .btn,
    .btn-small {
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
    .btn-small:hover {
        background: var(--polaris-accent-hover);
    }

    /* Sidebar for case details */
    .sidebar-column {
        flex: 0 0 300px;
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .sidebar-box {
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
        box-sizing: border-box;
        height: fit-content;
    }

    .sidebar-box h3 {
        margin-top: 0;
        font-size: 1.2em;
        border-bottom: 1px solid var(--polaris-border);
        padding-bottom: 5px;
        margin-bottom: 15px;
    }

    .detail-item {
        margin-bottom: 15px;
        font-size: 14px;
    }

    .detail-item strong {
        color: var(--polaris-text-dim);
    }

    .edit-button {
        display: inline-block;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        padding: 5px 10px;
        border-radius: 3px;
        text-decoration: none;
        font-size: 14px;
        transition: background 0.3s ease;
        margin-top: 10px;
    }

    .edit-button:hover {
        background: var(--polaris-accent-hover);
    }

    /* Main content area */
    .main-content {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    /* Updates section */
    .updates {
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
        box-sizing: border-box;
    }

    .updates h3 {
        margin-top: 0;
        font-size: 1.2em;
        border-bottom: 1px solid var(--polaris-border);
        padding-bottom: 5px;
        margin-bottom: 10px;
    }

    #updates-content {
        width: 100%;
        box-sizing: border-box;
    }

    #updates-content p {
        margin: 0;
        padding: 10px;
        border: 1px solid var(--polaris-border);
        border-radius: 4px;
        background: var(--polaris-surface-deep);
    }

    .updates-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
        table-layout: fixed;
    }

    .updates-table th,
    .updates-table td {
        border: 1px solid var(--polaris-border);
        padding: 4px 8px;
        text-align: left;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .updates-table th {
        background: var(--polaris-divider);
    }

    .updates-table col.col-type {
        width: 100px;
    }

    .updates-table col.col-user {
        width: 120px;
    }

    .updates-table col.col-date {
        width: 150px;
    }

    .updates-table col.col-actions {
        width: 110px;
    }

    .update-type-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 11px;
        font-weight: bold;
    }

    .update-type-badge.type-case {
        background: #1a3d5c;
        color: #7ec4f2;
    }

    .update-type-badge.type-comm {
        background: #4d3d1b;
        color: #f2d67e;
    }

    .update-actions {
        display: flex;
        align-items: center;
        gap: 6px;
        white-space: nowrap;
    }

    .update-actions a,
    .update-actions button {
        font-size: 11px;
        padding: 2px 6px;
        border-radius: 3px;
        cursor: pointer;
    }

    .update-actions form {
        display: inline;
    }

    .updates-pager {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        margin-top: 8px;
        font-size: 12px;
        color: var(--polaris-text-muted);
    }

    .updates-pager button {
        font-size: 11px;
        padding: 3px 10px;
        border-radius: 3px;
        cursor: pointer;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border: none;
    }

    .updates-pager button:hover:not(:disabled) {
        background: var(--polaris-accent-hover);
    }

    .updates-pager button:disabled {
        opacity: 0.4;
        cursor: default;
    }

    .action-button {
        display: inline-block;
        background: var(--polaris-success-strong);
        color: var(--polaris-text);
        padding: 5px 10px;
        border-radius: 3px;
        text-decoration: none;
        font-size: 14px;
        transition: background 0.3s ease;
        margin-top: 10px;
    }

    .action-button:hover {
        background: var(--polaris-success-strong-hover);
    }

    /* Exhibits Section */
    .exhibits-section {
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
        box-sizing: border-box;
    }

    .exhibits-section h3 {
        margin-top: 0;
        font-size: 1.2em;
        border-bottom: 1px solid var(--polaris-border);
        padding-bottom: 5px;
        margin-bottom: 10px;
    }

    .exhibits-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }

    .exhibits-table th,
    .exhibits-table td {
        border: 1px solid var(--polaris-border);
        padding: 4px 8px;
        text-align: center;
    }

    .exhibits-table th {
        background: var(--polaris-divider);
    }

    /* Exported Items and Produced Exhibits Sections */
    .full-section {
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
        box-sizing: border-box;
        margin-bottom: 20px;
    }

    .full-section h3 {
        margin-top: 0;
        font-size: 1.2em;
        border-bottom: 1px solid var(--polaris-border);
        padding-bottom: 5px;
        margin-bottom: 10px;
    }

    .full-section table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
        margin-top: 10px;
    }

    .full-section th,
    .full-section td {
        border: 1px solid var(--polaris-border);
        padding: 4px 8px;
        text-align: center;
    }

    .full-section th {
        background: var(--polaris-divider);
    }

    .add-btn {
        display: inline-block;
        padding: 5px 10px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border: none;
        border-radius: 3px;
        text-decoration: none;
        text-align: center;
        font-size: 14px;
        cursor: pointer;
        transition: background 0.3s ease;
        margin-bottom: 15px;
    }

    .add-btn:hover {
        background: var(--polaris-accent-hover);
    }

    /* Modifier for buttons that create a brand-new record (vs. .add-btn's
       default, used for actions on existing exhibits like Book Out). */
    .btn-add {
        background: var(--polaris-success-strong);
    }

    .btn-add:hover {
        background: var(--polaris-success-strong-hover);
    }

    a {
        color: var(--polaris-accent);
        text-decoration: none;
    }

    a:hover {
        text-decoration: underline;
    }

    @media (max-width: 900px) {
        .page-container {
            flex-direction: column;
        }

        .sidebar-column {
            width: 100%;
        }
    }
    </style>
    <!-- Using jQuery from CDN for updates -->
    <script src="../js/jq/jquery-3.7.1.min.js"></script>
    <script>
    var canManageUpdates = <?php echo $canDeleteExhibits ? 'true' : 'false'; ?>;
    var allUpdates = [];
    var updatesPage = 1;
    var UPDATES_PAGE_SIZE = 5;

    function escapeHtml(str) {
        return $('<div>').text(str === null || str === undefined ? '' : str).html();
    }

    // Strips HTML tags and decodes entities from the rich-text update content.
    function stripHtml(html) {
        var tmp = document.createElement('div');
        tmp.innerHTML = html || '';
        return tmp.textContent || tmp.innerText || '';
    }

    function renderUpdatesPage() {
        if (allUpdates.length === 0) {
            $('#updates-content').html('<p>No updates available yet.</p>');
            $('.updates-pager').remove();
            return;
        }

        var totalPages = Math.ceil(allUpdates.length / UPDATES_PAGE_SIZE);
        if (updatesPage > totalPages) {
            updatesPage = totalPages;
        }
        if (updatesPage < 1) {
            updatesPage = 1;
        }

        var start = (updatesPage - 1) * UPDATES_PAGE_SIZE;
        var pageItems = allUpdates.slice(start, start + UPDATES_PAGE_SIZE);

        var html = "<table class='updates-table'>" +
            "<colgroup><col class='col-type'><col class='col-user'><col class='col-date'><col><col class='col-actions'></colgroup>" +
            "<thead><tr><th>Type</th><th>Entered By</th><th>Date/Time</th><th>Text</th><th>Actions</th></tr></thead><tbody>";

        pageItems.forEach(function(update) {
            var typeClass = update.update_type === 'Communication' ? 'type-comm' : 'type-case';
            var plainText = stripHtml(update.update_text);
            var dateCell = escapeHtml(update.update_date) + (update.updated_at ? ' (edited)' : '');

            var actions = "<a href='edit_update.php?update_id=" + update.update_id +
                "' target='_blank' onclick=\"window.open(this.href, 'editUpdate', 'width=700,height=550'); return false;\">Edit</a>";
            if (canManageUpdates) {
                actions += " <form method='post' action='delete_update.php' onsubmit=\"return confirmDeleteWithReason(this, 'Delete this update? The text will be removed, but the deletion and its content will be recorded in the case history.');\">" +
                    "<input type='hidden' name='update_id' value='" + update.update_id + "'>" +
                    "<button type='submit' style='background:var(--polaris-error-bg); color:var(--polaris-text); border:none;'>Delete</button></form>";
            }

            html += "<tr>" +
                "<td><span class='update-type-badge " + typeClass + "'>" + escapeHtml(update.update_type) + "</span></td>" +
                "<td>" + escapeHtml(update.first_name) + " " + escapeHtml(update.last_name) + "</td>" +
                "<td>" + dateCell + "</td>" +
                "<td title=\"" + escapeHtml(plainText) + "\">" + escapeHtml(plainText) + "</td>" +
                "<td><span class='update-actions'>" + actions + "</span></td>" +
                "</tr>";
        });

        html += "</tbody></table>";
        $('#updates-content').html(html);

        $('.updates-pager').remove();
        if (totalPages > 1) {
            var pager = $("<div class='updates-pager'>" +
                "<button id='updates-prev'>&laquo; Prev</button>" +
                "<span>Page " + updatesPage + " of " + totalPages + "</span>" +
                "<button id='updates-next'>Next &raquo;</button>" +
                "</div>");
            pager.find('#updates-prev').prop('disabled', updatesPage <= 1).on('click', function() {
                updatesPage--;
                renderUpdatesPage();
            });
            pager.find('#updates-next').prop('disabled', updatesPage >= totalPages).on('click', function() {
                updatesPage++;
                renderUpdatesPage();
            });
            $('#updates-content').after(pager);
        }
    }

    function fetchUpdates() {
        $.ajax({
            url: 'get_updates.php',
            data: {
                job_id: <?php echo $job_id; ?>
            },
            dataType: 'json',
            success: function(data) {
                allUpdates = data;
                renderUpdatesPage();
            },
            error: function(xhr, status, error) {
                console.error("Error fetching updates:", xhr.responseText);
                $('#updates-content').html("<p>Error loading updates.</p>");
                $('.updates-pager').remove();
            }
        });
    }
    $(document).ready(function() {
        fetchUpdates();
        setInterval(fetchUpdates, 10000); // refresh every 10 seconds
    });
    </script>

    <?php if ($pageError !== ""): ?>
    <div class="page-error"><?php echo htmlspecialchars($pageError); ?></div>
    <?php endif; ?>

    <div class="page-container">
        <!-- Sidebar: Case Details -->
        <div class="sidebar-column">
        <div class="sidebar-box">
            <h3>Case Details</h3>
            <div class="detail-item">
                <strong>Custom Ref:</strong><br>
                <?php echo htmlspecialchars($custom_ref ?? ''); ?>
            </div>
            <div class="detail-item">
                <strong>Date/Time:</strong><br>
                <?php echo htmlspecialchars($date_time ?? ''); ?>
            </div>
            <div class="detail-item">
                <strong>Case Type:</strong><br>
                <?php echo htmlspecialchars($case_type ?? ''); ?>
            </div>
            <div class="detail-item">
                <strong>Operation:</strong><br>
                <?php echo htmlspecialchars($operation_name ?? 'None'); ?>
            </div>
            <div class="detail-item">
                <strong>OIC:</strong><br>
                <?php echo htmlspecialchars($oic ?? ''); ?>
            </div>
            <div class="detail-item">
                <strong>Status:</strong><br>
                <?php echo htmlspecialchars($status_name ?? ''); ?>
            </div>
            <div class="detail-item">
                <strong>Strategy Set:</strong><br>
                <?php echo htmlspecialchars($strategy_set ?? ''); ?>
            </div>
            <div class="detail-item">
                <strong>Strategy Due:</strong><br>
                <?php echo htmlspecialchars($strategy_due ?? ''); ?>
            </div>
            <div class="detail-item">
                <strong>Fingerprints:</strong><br>
                <?php echo $fingerprints ? "<span style='font-weight:bold; color:var(--polaris-danger);'>Concern</span>" : "None"; ?>
            </div>
            <div class="detail-item">
                <strong>DNA:</strong><br>
                <?php echo $dna ? "<span style='font-weight:bold; color:var(--polaris-danger);'>Concern</span>" : "None"; ?>
            </div>
            <div class="detail-item">
                <strong>Malware:</strong><br>
                <?php echo $malware ? "<span style='font-weight:bold; color:var(--polaris-danger);'>Concern</span>" : "None"; ?>
            </div>
            <h3>Case Background</h3>
            <div class="detail-item">
                <?php echo nl2br(htmlspecialchars($initial_summary ?? '')); ?>
            </div>
            <a class="edit-button" href="edit_job.php?job_id=<?php echo $job_id; ?>">Edit Case</a>
        </div>

        <div class="sidebar-box">
            <h3>Case Documents</h3>
            <div class="detail-item">
                <?php if (empty($case_documents)): ?>
                <p style="margin:0 0 10px; color:var(--polaris-text-muted);">No documents uploaded yet.</p>
                <?php else: ?>
                <ul style="margin:0 0 10px; padding-left:18px;">
                    <?php foreach ($case_documents as $doc): ?>
                    <li style="margin-bottom:6px; font-size:13px;">
                        <a href="download_case_document.php?doc_id=<?php echo (int) $doc['id']; ?>" style="color:var(--polaris-accent);">
                            <?php echo htmlspecialchars($doc['original_filename']); ?>
                        </a>
                        <br>
                        <span style="color:var(--polaris-text-muted);">
                            <?php echo htmlspecialchars($doc['uploaded_by_name'] ?? ''); ?> -
                            <?php echo htmlspecialchars($doc['uploaded_at']); ?>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <a class="edit-button" href="upload_case_document.php?job_id=<?php echo $job_id; ?>">Manage Documents</a>
            </div>
        </div>
        </div>
        <!-- Main Content -->
        <div class="main-content">
            <!-- Updates Section -->
            <div class="updates">
                <h3>Case Updates</h3>
                <div id="updates-content">
                    <p>Loading updates...</p>
                </div>
                <a class="action-button" href="add_update.php?job_id=<?php echo $job_id; ?>" target="_blank"
                    onclick="window.open(this.href, 'addUpdate', 'width=700,height=500'); return false;">Add Update</a>
                <a class="edit-button" href="view_case_history.php?job_id=<?php echo $job_id; ?>"
                    style="margin-left:8px;">View Case History</a>
                <a class="edit-button" href="case_report.php?job_id=<?php echo $job_id; ?>" target="_top"
                    style="margin-left:8px;">Case Report</a>
            </div>
            <!-- Exhibits Section -->
            <div class="exhibits-section">
                <h3>Exhibits</h3>
                <?php if (!empty($exhibits)): ?>
                <div class="sheet-table-wrapper">
                <table class="exhibits-table">
                    <tr>
                        <th>Exhibit Ref</th>
                        <th>Description</th>
                        <th>Exhibit Type</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Time In</th>
                        <th>Time Out / Location</th>
                        <th>Allocated To</th>
                        <th>Examine</th>
                        <?php if ($canDeleteExhibits): ?>
                        <th>Delete</th>
                        <?php endif; ?>
                    </tr>
                    <?php foreach ($exhibits as $ex): ?>
                    <tr>
                        <td>
                            <a href="edit_exhibit.php?exhibit_id=<?= $ex['exhibit_id']; ?>">
                                <?= htmlspecialchars($ex['exhibit_ref']); ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars($ex['item_description']); ?></td>
                        <td><?= htmlspecialchars($ex['exhibit_type']); ?></td>
                        <td><?= htmlspecialchars($ex['urgency']); ?></td>
                        <td><?= htmlspecialchars($ex['status']); ?></td>
                        <td><?= htmlspecialchars($ex['time_in']); ?></td>
                        <td><?= htmlspecialchars($ex['time_out'] ?: $ex['location_name']); ?></td>
                        <td><?= htmlspecialchars($ex['allocated_to_name']); ?></td>
                        <td>
                            <a class="btn btn-small"
                                href="/captains_log/examination.php?exhibit_id=<?= $ex['exhibit_id']; ?>">Examine</a>
                        </td>
                        <?php if ($canDeleteExhibits): ?>
                        <td>
                            <form method="post" action="delete_exhibit.php" style="display:inline;"
                                onsubmit="return confirmDeleteWithReason(this, 'Delete exhibit <?= htmlspecialchars(addslashes($ex['exhibit_ref'])); ?>? It will be hidden from active views but kept in the audit trail, and can be restored later.');">
                                <input type="hidden" name="exhibit_id" value="<?= $ex['exhibit_id']; ?>">
                                <button type="submit" class="btn btn-small" style="background:var(--polaris-error-bg);">Delete</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </table>
                </div>
                <?php else: ?>
                <p>No exhibits added yet.</p>
                <?php endif; ?>
                <a class="add-btn btn-add" href="add_exhibit.php?job_id=<?php echo $job_id; ?>">Add Exhibit</a>
                <a class="add-btn" href="book_out_exhibits.php?job_id=<?php echo $job_id; ?>">Book Out Exhibit</a>

                <?php if ($canDeleteExhibits && !empty($deletedExhibits)): ?>
                <h4 style="margin-top:20px; color:var(--polaris-text-dim);">Deleted Exhibits</h4>
                <div class="sheet-table-wrapper">
                <table class="exhibits-table">
                    <tr>
                        <th>Exhibit Ref</th>
                        <th>Description</th>
                        <th>Deleted At</th>
                        <th>Deleted By</th>
                        <th>Restore</th>
                    </tr>
                    <?php foreach ($deletedExhibits as $ex): ?>
                    <tr>
                        <td><?= htmlspecialchars($ex['exhibit_ref']); ?></td>
                        <td><?= htmlspecialchars($ex['item_description'] ?? ''); ?></td>
                        <td><?= htmlspecialchars($ex['deleted_at']); ?></td>
                        <td><?= htmlspecialchars($ex['deleted_by_name'] ?? ''); ?></td>
                        <td>
                            <form method="post" action="restore_exhibit.php" style="display:inline;"
                                onsubmit="return confirm('Restore exhibit <?= htmlspecialchars(addslashes($ex['exhibit_ref'])); ?>?');">
                                <input type="hidden" name="exhibit_id" value="<?= $ex['exhibit_id']; ?>">
                                <button type="submit" class="btn btn-small">Restore</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                </div>
                <?php endif; ?>
            </div>
            <!-- Exported Items Section -->
            <div class="full-section">
                <h3>Exported Items</h3>
                <div class="sheet-table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Extraction Ref</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Extracted On</th>
                            <th>Extracted By</th>
                            <th>Assigned To</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($exported_items)): ?>
                        <tr>
                            <td colspan="6">No exported items added yet.</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($exported_items as $item): ?>
                        <tr>
                            <td><a
                                    href="edit_exported_item.php?item_id=<?php echo htmlspecialchars($item['item_id']); ?>&job_id=<?php echo htmlspecialchars($job_id); ?>">
                                    <?php echo htmlspecialchars($item['extraction_ref']); ?>
                                </a></td>
                            <td><?php echo htmlspecialchars($item['description'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($item['status'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($item['extracted_on'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($item['extracted_by_name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($item['assigned_to_name'] ?? ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
                <br>
                <a href="exported_items.php?job_id=<?php echo htmlspecialchars($job_id); ?>" class="add-btn btn-add">Add New
                    Item</a>
            </div>

            <!-- Produced Exhibits Section -->
            <div class="full-section">
                <h3>Produced Exhibits</h3>
                <div class="sheet-table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Exhibit Ref</th>
                            <th>Description</th>
                            <th>Produced</th>
                            <th>Extracted By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($produced_exhibits)): ?>
                        <tr>
                            <td colspan="4">No produced exhibits added yet.</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($produced_exhibits as $exhibit): ?>
                        <tr>
                            <td><a
                                    href="edit_produced_exhibit.php?exhibit_id=<?php echo htmlspecialchars($exhibit['exhibit_id']); ?>&job_id=<?php echo htmlspecialchars($job_id); ?>">
                                    <?php echo htmlspecialchars($exhibit['exhibit_ref']); ?>
                                </a></td>
                            <td><?php echo htmlspecialchars($exhibit['description'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($exhibit['produced_date'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($exhibit['extracted_by_name'] ?? ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
                <br>
                <a href="produced_exhibits.php?job_id=<?php echo htmlspecialchars($job_id); ?>" class="add-btn btn-add">Add New
                    Exhibit</a>
            </div>
        </div>

</body>

</html>