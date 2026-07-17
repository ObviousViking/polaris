<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../db.php';
include '../header.php';

// Validate exhibit_id
$exhibit_id = isset($_GET['exhibit_id']) ? intval($_GET['exhibit_id']) : 0;
if ($exhibit_id <= 0) {
    die("Invalid exhibit ID.");
}

// Get exhibit details
$exhibit_stmt = $conn->prepare("
    SELECT e.exhibit_ref, e.bag_number, e.status,
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
    SELECT file_name, file_path
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

// Available processes (for the "Add Process" picker) - active only, an
// inactive one is still shown on already-filled-in records but shouldn't
// be offered for new ones (same convention as exhibit_locations).
$processTypes = [];
$ptResult = $conn->query("SELECT id, name FROM process_types WHERE is_active = 1 ORDER BY name");
while ($row = $ptResult->fetch_assoc()) {
    $processTypes[] = $row;
}

// Processes recorded against this exhibit AND its sub-exhibits, so they all
// show up in one tight list here rather than needing to visit each
// sub-exhibit's own examination page - the Exhibit Ref column says which
// one each row belongs to. Field values aren't needed for this list view
// (kept to Edit, to keep the table tight); see manage_exhibit_process.php.
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

    .doc-list {
        max-height: 260px;
        overflow-y: auto;
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .doc-list li {
        margin-bottom: 10px;
        background: var(--polaris-bg);
        padding: 10px;
        border-radius: 5px;
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
</style>

<div class="main-container">

    <!-- Exhibit Info - spreadsheet style: one header row, one data row -->
    <div class="panel">
        <div class="info-header">
            <h2>Exhibit <?= htmlspecialchars($exhibit['exhibit_ref']) ?></h2>
            <a href="/cargo_hold/job.php?job_id=<?= $job_id ?>" class="btn btn-small btn-outline">&larr; Back to
                Case</a>
        </div>
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
                        <td><?= htmlspecialchars(trim($exhibit['first_name'] . ' ' . $exhibit['last_name'])) ?></td>
                        <td><?= htmlspecialchars($exhibit['urgency']) ?></td>
                        <td><?= htmlspecialchars($exhibit['location_name']) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Processes -->
    <div class="panel">
        <div class="info-header">
            <h2>Processes</h2>
            <?php if (!empty($processTypes)): ?>
            <form class="add-process-form" method="get" action="manage_exhibit_process.php">
                <select name="exhibit_id">
                    <option value="<?= $exhibit_id ?>"><?= htmlspecialchars($exhibit['exhibit_ref']) ?> (this
                        exhibit)</option>
                    <?php foreach ($sub_exhibits as $sub): ?>
                    <option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['exhibit_ref']) ?> (sub-exhibit)
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
                        <td><?= htmlspecialchars($ep['exhibit_ref']) ?></td>
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

    <!-- Sub-Exhibits -->
    <div class="panel">
        <div class="info-header">
            <h2>Sub-Exhibits</h2>
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

    <!-- Photos & Documents -->
    <div class="bottom-grid">
        <div class="panel">
            <h2 class="section-title">Uploaded Photos</h2>
            <?php if (empty($photos)): ?>
            <p class="empty-note">No photos uploaded yet.</p>
            <?php else: ?>
            <div class="photo-grid">
                <?php foreach ($photos as $photo): ?>
                <img src="<?= htmlspecialchars($photo['file_url']) ?>" alt="Exhibit Photo">
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div style="text-align:center; margin-top:12px; display:flex; justify-content:center; gap:10px;">
                <button class="btn btn-small" onclick="openUploadWindow()">&#128228; Upload</button>
                <a href="view_images.php?exhibit_id=<?= $exhibit_id ?>" class="btn btn-small" target="_blank">&#128444;&#65039;
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
                <li><a href="download_document.php?doc_id=<?= htmlspecialchars($doc['id']) ?>">
                        <?= htmlspecialchars($doc['original_filename']) ?> &#128229;</a></li>
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
