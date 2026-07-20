<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/settings.php';
require_once '../includes/integrity.php';
require_once '../includes/permissions.php';
require_permission($conn, 'document_manage');

$job_id = isset($_GET['job_id']) ? intval($_GET['job_id']) : 0;
if ($job_id <= 0) {
    die("Invalid job ID.");
}

$jobStmt = $conn->prepare("SELECT custom_ref FROM jobs WHERE job_id = ?");
$jobStmt->bind_param("i", $job_id);
$jobStmt->execute();
$jobStmt->bind_result($custom_ref_raw);
if (!$jobStmt->fetch()) {
    die("Case not found.");
}
$jobStmt->close();

$allowed_document_extensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'jpg', 'jpeg', 'png'];

$config = get_storage_settings($conn);
$year = date('Y');
$custom_ref = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $custom_ref_raw);
$upload_dir = $config['paths']['case_document_dir_fs'] . "$year/$custom_ref/";
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$upload_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['document'])) {
    $original_name = basename($_FILES['document']['name']);
    $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

    if (!in_array($extension, $allowed_document_extensions, true)) {
        $upload_message = "<p style='color: var(--polaris-danger);'>File type not allowed. Permitted types: " . implode(', ', $allowed_document_extensions) . ".</p>";
    } else {
        $hash = md5_file($_FILES['document']['tmp_name']);
        $timestamp = date('Ymd_His');
        $unique_name = "doc_{$timestamp}_{$hash}." . $extension;
        $target_path = $upload_dir . $unique_name;

        if (move_uploaded_file($_FILES['document']['tmp_name'], $target_path)) {
            $stmt = $conn->prepare("
                INSERT INTO case_documents (job_id, original_filename, stored_filename, file_path, uploaded_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isssi", $job_id, $original_name, $unique_name, $target_path, $_SESSION['user_id']);
            if ($stmt->execute()) {
                $changesJSON = json_encode(['filename' => $original_name]);
                insert_history_row($conn, 'case_history', $job_id, 'DOCUMENT_UPLOAD', $_SESSION['user_id'], $changesJSON);

                require_once '../includes/achievements.php';
                check_and_unlock_achievements($conn, (int) $_SESSION['user_id'], 'uploads_count');

                $upload_message = "<p style='color: var(--polaris-success-strong);'>Upload successful.</p>";
            } else {
                $upload_message = "<p style='color: var(--polaris-danger);'>Error saving document record: " . htmlspecialchars($conn->error) . "</p>";
            }
        } else {
            $upload_message = "<p style='color: var(--polaris-danger);'>Upload failed. Please check file permissions or try a smaller file.</p>";
        }
    }
}

// Fetch existing documents for this case.
$documents = [];
$docStmt = $conn->prepare("
    SELECT cd.id, cd.original_filename, cd.uploaded_at, CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by_name
    FROM case_documents cd
    LEFT JOIN users u ON cd.uploaded_by = u.id
    WHERE cd.job_id = ?
    ORDER BY cd.uploaded_at DESC
");
$docStmt->bind_param("i", $job_id);
$docStmt->execute();
$docResult = $docStmt->get_result();
while ($row = $docResult->fetch_assoc()) {
    $documents[] = $row;
}
$docStmt->close();

include '../header.php';
?>

<style>
    /* header.php's own body{} already sets margin/background/color/font-family
       page-wide. This page used to redeclare a second <!DOCTYPE html><html>
       <head> here with its own body{} - browsers tolerate the invalid
       nesting, but that stray rule won the cascade and bled its font into
       the real header/nav above it. The 120px clearance moves onto
       .content-wrapper's top margin since body no longer carries it. */

    .content-wrapper {
        max-width: 700px;
        margin: 140px auto 20px auto;
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 5px;
        box-shadow: 0 2px 10px rgba(255, 255, 255, 0.1);
    }

    h2 {
        text-align: center;
        margin-bottom: 20px;
    }

    input[type=file] {
        display: block;
        margin: 10px 0;
        color: var(--polaris-text);
    }

    input[type=submit] {
        padding: 8px 16px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border: none;
        border-radius: 4px;
        cursor: pointer;
    }

    input[type=submit]:hover {
        background: var(--polaris-accent-hover);
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }

    th,
    td {
        border: 1px solid var(--polaris-border);
        padding: 8px;
        text-align: left;
        font-size: 14px;
    }

    th {
        background: var(--polaris-divider);
    }

    a.back-btn {
        display: inline-block;
        margin-top: 20px;
        padding: 5px 10px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border-radius: 3px;
        text-decoration: none;
        font-size: 14px;
    }

    a.back-btn:hover {
        background: var(--polaris-accent-hover);
    }
    </style>

    <div class="content-wrapper">
        <h2>Case Documents - <?php echo htmlspecialchars($custom_ref_raw); ?></h2>

        <?php if (!empty($upload_message)) echo $upload_message; ?>

        <form method="post" enctype="multipart/form-data">
            <label for="document">Select file:</label>
            <input type="file" name="document" required>
            <input type="submit" value="Upload">
        </form>

        <table>
            <tr>
                <th>Filename</th>
                <th>Uploaded By</th>
                <th>Uploaded At</th>
                <th></th>
            </tr>
            <?php if (empty($documents)): ?>
            <tr>
                <td colspan="4">No documents uploaded yet.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($documents as $doc): ?>
            <tr>
                <td><?php echo htmlspecialchars($doc['original_filename']); ?></td>
                <td><?php echo htmlspecialchars($doc['uploaded_by_name'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($doc['uploaded_at']); ?></td>
                <td><a href="download_case_document.php?doc_id=<?php echo (int) $doc['id']; ?>">Download</a></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </table>

        <a href="job.php?job_id=<?php echo $job_id; ?>" class="back-btn">Back to Case</a>
    </div>
</body>

</html>
