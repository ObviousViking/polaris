<?php
// upload_documents.php
//
// Small popup window (opened via window.open() from examination.php) for uploading
// exhibit documents. Deliberately does NOT include header.php - a small
// popup doesn't need the full site header/nav/notification-polling script,
// and (see upload_photos_popup.php for the fuller version of this story)
// loading it unconditionally is actively risky for any page that also
// writes a raw response.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/settings.php';

$allowed_document_extensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'jpg', 'jpeg', 'png'];

$config = get_storage_settings($conn);

$exhibit_id = isset($_GET['exhibit_id']) ? intval($_GET['exhibit_id']) : 0;
if ($exhibit_id <= 0) {
    die("Invalid exhibit ID.");
}

// Get exhibit_ref and custom_ref for folder structure
$details_stmt = $conn->prepare("
    SELECT e.exhibit_ref, j.custom_ref
    FROM exhibits e
    JOIN jobs j ON e.job_id = j.job_id
    WHERE e.exhibit_id = ?
");
$details_stmt->bind_param("i", $exhibit_id);
$details_stmt->execute();
$details_result = $details_stmt->get_result();
$details = $details_result->fetch_assoc();

if (!$details) {
    die("Exhibit or job not found.");
}

// Sanitize values for filesystem
$year = date('Y');
$custom_ref = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $details['custom_ref']);
$exhibit_ref = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $details['exhibit_ref']);

$upload_dir = $config['paths']['document_dir_fs'] . "/$year/$custom_ref/$exhibit_ref/";
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
            INSERT INTO exhibit_documents (exhibit_id, original_filename, stored_filename, file_path, uploaded_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssi", $exhibit_id, $original_name, $unique_name, $target_path, $_SESSION['user_id']);
        $stmt->execute();

        require_once '../includes/achievements.php';
        check_and_unlock_achievements($conn, (int) $_SESSION['user_id'], 'uploads_count');

        $upload_message = "<p style='color: var(--polaris-success-strong);'>Upload successful. You can upload another file or close the window.</p>";
    } else {
        $upload_message = "<p style='color: var(--polaris-danger);'>Upload failed. Please check file permissions or try a smaller file.</p>";
    }
    }
}

$userTheme = "dark";
if ($stmt = $conn->prepare("SELECT theme FROM users WHERE id = ? LIMIT 1")) {
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $stmt->bind_result($userTheme);
    $stmt->fetch();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en"<?php echo $userTheme === 'light' ? ' data-theme="light"' : ''; ?>>

<head>
    <meta charset="UTF-8">
    <title>Upload Document</title>
    <link rel="stylesheet" href="/assets/theme.css">
    <style>
    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        padding: 20px;
        background: var(--polaris-bg);
        color: var(--polaris-text);
        font-family: Arial, sans-serif;
    }

    .upload-box {
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 10px;
    }

    input[type=file] {
        display: block;
        margin-top: 15px;
    }

    input[type=submit] {
        display: block;
        margin-top: 15px;
        padding: 5px 10px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border: none;
        border-radius: 3px;
        cursor: pointer;
        font-size: 14px;
    }

    input[type=submit]:hover {
        background: var(--polaris-accent-hover);
    }

    .close-btn {
        margin-top: 25px;
        padding: 5px 10px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border: none;
        border-radius: 3px;
        cursor: pointer;
        font-size: 14px;
    }

    .close-btn:hover {
        background: var(--polaris-accent-hover);
    }
    </style>
</head>

<body>
    <div class="upload-box">
        <h2>Upload Document</h2>
        <?php if (!empty($upload_message)) echo $upload_message; ?>

        <form method="post" enctype="multipart/form-data">
            <label for="document">Select file:</label>
            <input type="file" name="document" required>
            <input type="submit" value="Upload">
        </form>

        <button class="close-btn" onclick="window.close()">Close Window</button>
    </div>
</body>

</html>
