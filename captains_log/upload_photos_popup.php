<?php
// upload_photos_popup.php
//
// Small popup for dragging exhibit photos onto Dropzone.js (self-hosted,
// no CDN). Deliberately doesn't include header.php - its HTML output would
// pollute the JSON response this file writes for the AJAX upload.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../db.php';
require_once '../includes/settings.php';
require_once '../includes/permissions.php';

$config = get_storage_settings($conn);
$man_no = preg_replace('/[^\w\-]/', '_', $_SESSION['user_id'] ?? null);
$exhibit_id = isset($_GET['exhibit_id']) ? intval($_GET['exhibit_id']) : 0;

if (!$man_no || $exhibit_id <= 0) {
    die("Not authenticated or invalid exhibit.");
}
if (!user_can($conn, (int) $_SESSION['user_id'], 'document_manage')) {
    die("You do not have permission to upload photos.");
}

// Handle the AJAX upload request first, before any HTML is output.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    header('Content-Type: application/json');

    // Get exhibit info
    $stmt = $conn->prepare("
        SELECT e.exhibit_ref, j.custom_ref
        FROM exhibits e
        JOIN jobs j ON e.job_id = j.job_id
        WHERE e.exhibit_id = ?
    ");
    $stmt->bind_param("i", $exhibit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $exhibit = $result->fetch_assoc();

    if (!$exhibit) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Exhibit not found']);
        exit;
    }

    $exhibit_ref = preg_replace('/[^\w\-]/', '_', $exhibit['exhibit_ref']);
    $custom_ref = preg_replace('/[^\w\-]/', '_', $exhibit['custom_ref']);
    $year = date('Y');

    $base_dir = rtrim(str_replace('\\', '/', $config['paths']['photo_dir_fs']), '/') . "/$year/$custom_ref/$exhibit_ref/";
    $base_url = rtrim($config['paths']['photo_dir_url'], '/') . "/$year/$custom_ref/$exhibit_ref/";

    if (!is_dir($base_dir)) {
        mkdir($base_dir, 0775, true);
    }

    // Prepare response
    $response = ['status' => 'success', 'files' => []];
    $errors = [];

    // Handle multiple files
    $files = $_FILES['file'];
    $file_count = is_array($files['name']) ? count($files['name']) : 1;

    // Normalize single file case to array for consistent processing
    if (!is_array($files['name'])) {
        $files = [
            'name' => [$files['name']],
            'tmp_name' => [$files['tmp_name']],
            'size' => [$files['size']],
            'type' => [$files['type']],
            'error' => [$files['error']]
        ];
    }

    for ($i = 0; $i < $file_count; $i++) {
        $original_name = $files['name'][$i];
        $tmp_name = $files['tmp_name'][$i];
        $error = $files['error'][$i];

        if ($error !== UPLOAD_ERR_OK) {
            $errors[] = "File $original_name failed to upload (error code: $error)";
            continue;
        }

        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        if (!in_array($extension, $allowed_extensions)) {
            $errors[] = "File $original_name has invalid type. Only JPG, PNG, and GIF are allowed.";
            continue;
        }

        $timestamp = date('Ymd_His');
        $hash = md5_file($tmp_name);
        $new_filename = "{$custom_ref}_{$exhibit_ref}_{$timestamp}_{$hash}_{$man_no}." . $extension;
        $target_path = $base_dir . $new_filename;

        if (!move_uploaded_file($tmp_name, $target_path)) {
            $errors[] = "Failed to move file $original_name";
            continue;
        }

        $stmt = $conn->prepare("
            INSERT INTO exhibit_photos (exhibit_id, file_name, file_path, uploaded_by, uploaded_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("isss", $exhibit_id, $new_filename, $target_path, $man_no);
        if (!$stmt->execute()) {
            $errors[] = "Failed to save file $original_name to database";
            continue;
        }

        $response['files'][] = $new_filename;
    }

    if (!empty($errors)) {
        $response['status'] = 'error';
        $response['message'] = implode(', ', $errors);
        http_response_code(400);
    } else {
        http_response_code(200);
    }

    if (!empty($response['files'])) {
        require_once '../includes/achievements.php';
        check_and_unlock_achievements($conn, (int) $_SESSION['user_id'], 'uploads_count');
    }

    echo json_encode($response);
    exit;
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
    <title>Upload Exhibit Photos</title>
    <link rel="stylesheet" href="/assets/theme.css">
    <link rel="stylesheet" href="/assets/dropzone/dropzone.min.css">
    <script src="/assets/dropzone/dropzone.min.js"></script>
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

    .dz {
        background: var(--polaris-surface);
        border: 2px dashed var(--polaris-accent);
        padding: 30px;
        border-radius: 10px;
    }

    .btn-close {
        background: var(--polaris-accent);
        border: none;
        color: var(--polaris-text);
        padding: 5px 10px;
        cursor: pointer;
        border-radius: 3px;
        font-size: 14px;
        margin-top: 20px;
    }

    .btn-close:hover {
        background: var(--polaris-accent-hover);
    }

    .btn-close:hover {
        background: #cc4444;
    }

    .log {
        margin-top: 15px;
        background: var(--polaris-black-alt);
        padding: 10px;
        border-radius: 5px;
        font-size: 12px;
        white-space: pre-wrap;
    }
    </style>
</head>

<body>
    <h2>Upload Exhibit Photos</h2>
    <form action="upload_photos_popup.php?exhibit_id=<?= $exhibit_id ?>" class="dropzone dz" id="photo-dropzone"
        method="post" enctype="multipart/form-data"></form>
    <div class="log" id="uploadLog">Ready.</div>
    <button class="btn-close" onclick="window.close()">Close Window</button>

    <script>
    Dropzone.options.photoDropzone = {
        paramName: "file",
        maxFilesize: 50,
        timeout: 300000,
        addRemoveLinks: true,
        acceptedFiles: "image/*",
        uploadMultiple: true,
        parallelUploads: 10,
        maxFiles: 500,
        init: function() {
            this.on("sending", function(file, xhr, formData) {
                console.log("Sending file:", file.name);
            });
            this.on("success", function(file, response) {
                const log = document.getElementById("uploadLog");
                console.log("Success response for:", file.name, response);
                file.uploadSuccess = true;
                if (response.status === "success") {
                    log.textContent += `✅ ${file.name} uploaded\n`;
                } else {
                    log.textContent +=
                        `❌ ${file.name} failed: ${response.message || 'Unknown error'}\n`;
                }
            });
            this.on("error", function(file, errorMessage, xhr) {
                const log = document.getElementById("uploadLog");
                if (file.uploadSuccess) return;
                console.log("Error for:", file.name, errorMessage, xhr);
                log.textContent += `❌ ${file.name} failed: ${errorMessage || 'Unknown error'}\n`;
            });
            this.on("queuecomplete", function() {
                const log = document.getElementById("uploadLog");
                log.textContent += "All uploads completed.\n";
                console.log("Queue complete");
                if (window.opener) window.opener.location.reload();
            });
        }
    };
    </script>
</body>

</html>
