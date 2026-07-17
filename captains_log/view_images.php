<?php
// view_images.php
//
// Opened in a new tab for browsing an exhibit's photos. Deliberately
// doesn't include header.php - not needed in a popup.
session_start();
if (!isset($_SESSION['user_id'])) {
    die("Not authenticated");
}

require_once '../db.php';
require_once '../includes/settings.php';

$config = get_storage_settings($conn);
$exhibit_id = isset($_GET['exhibit_id']) ? intval($_GET['exhibit_id']) : 0;

if ($exhibit_id <= 0) die("Invalid exhibit ID");

$photo_stmt = $conn->prepare("SELECT file_name, file_path FROM exhibit_photos WHERE exhibit_id = ? ORDER BY uploaded_at ASC");
$photo_stmt->bind_param("i", $exhibit_id);
$photo_stmt->execute();
$photo_result = $photo_stmt->get_result();

$photos = [];
while ($row = $photo_result->fetch_assoc()) {
    $photos[] = [
        'file_name' => $row['file_name'],
        'file_url' => str_replace($config['paths']['photo_dir_fs'], $config['paths']['photo_dir_url'], $row['file_path'])
    ];
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
    <title>Exhibit Images</title>
    <link rel="stylesheet" href="/assets/theme.css">
    <style>
    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        display: flex;
        flex-direction: column;
        height: 100vh;
        background: var(--polaris-black-alt);
        color: var(--polaris-text);
        font-family: Arial, sans-serif;
    }

    .main-content {
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: flex-start;
        align-items: center;
        overflow: hidden;
        padding: 20px;
    }

    .carousel {
        display: flex;
        overflow-x: auto;
        overflow-y: hidden;
        gap: 10px;
        padding: 10px 0;
        /* remove side padding to stop offset clipping */
        margin-bottom: 20px;
        border-bottom: 1px solid var(--polaris-border);
        width: 100%;
        justify-content: flex-start;
        height: 100px;
        flex-shrink: 0;
        scroll-padding-left: 10px;
        /* optional: helps when snapping or auto-scrolling */
    }

    .carousel img {
        height: 100%;
        object-fit: cover;
        border-radius: 5px;
        cursor: pointer;
        transition: transform 0.2s;
        flex-shrink: 0;
        /* prevents weird squashing on small screens */
    }


    .carousel img:hover {
        transform: scale(1.1);
    }


    .main-image {
        max-width: 100%;
        max-height: calc(100vh - 200px);
        /* responsive to screen size */
        border-radius: 5px;
        object-fit: contain;
    }

    .download-bar {
        padding: 15px;
        text-align: center;
        background: var(--polaris-black-alt);
    }

    .btn-download {
        padding: 5px 10px;
        background: var(--polaris-accent);
        border: none;
        color: var(--polaris-text);
        border-radius: 3px;
        text-decoration: none;
        font-size: 14px;
    }

    .btn-download:hover {
        background: var(--polaris-accent-hover);
    }
    </style>
</head>

<body>
    <?php if (empty($photos)): ?>
    <div style="padding: 40px; text-align: center;">
        <h2>No photos found for this exhibit.</h2>
    </div>
    <?php else: ?>
    <div class="main-content">
        <h2>Exhibit Images</h2>

        <div class="carousel" id="thumbnailBar">
            <?php foreach ($photos as $index => $photo): ?>
            <img src="<?= htmlspecialchars($photo['file_url']) ?>" alt="Thumbnail" onclick="selectImage(<?= $index ?>)">
            <?php endforeach; ?>
        </div>

        <img src="<?= htmlspecialchars($photos[0]['file_url']) ?>" id="mainImage" class="main-image"
            alt="Selected Image">
    </div>

    <div class="download-bar">
        <a href="<?= htmlspecialchars($photos[0]['file_url']) ?>" id="downloadBtn" class="btn-download" download>⬇
            Download</a>
    </div>

    <script>
    const photos = <?= json_encode($photos) ?>;
    let current = 0;

    function selectImage(index) {
        current = index;
        const mainImage = document.getElementById('mainImage');
        const downloadBtn = document.getElementById('downloadBtn');
        mainImage.src = photos[index].file_url;
        downloadBtn.href = photos[index].file_url;
    }
    </script>
    <?php endif; ?>
</body>

</html>
