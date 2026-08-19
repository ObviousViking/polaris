<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'db.php';
require_once 'includes/settings.php';

// Load configuration for file paths.
$config = get_storage_settings($conn);
$avatar_dir_fs = $config['paths']['avatar_dir_fs']; // File system path

// Ensure the avatar directory exists.
if (!is_dir($avatar_dir_fs)) {
    mkdir($avatar_dir_fs, 0755, true);
}

$user_id = $_SESSION['user_id'];
$first_name = trim($_POST['first_name']);
$last_name = trim($_POST['last_name']);
$theme = ($_POST['theme'] ?? '') === 'light' ? 'light' : 'dark';
$remove_avatar = isset($_POST['remove_avatar']);

// Real image formats and their safe extensions, keyed by getimagesize()'s
// detected type - not the client-supplied Content-Type or filename
// extension, both of which are attacker/user controlled and were
// previously trusted outright (a mismatched or unrecognized type used to
// fail silently here with no error shown, and the previous "Profile
// updated successfully" message made that look like the avatar had saved
// when it hadn't).
const AVATAR_ALLOWED_TYPES = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG => 'png',
    IMAGETYPE_GIF => 'gif',
    IMAGETYPE_WEBP => 'webp',
];

$avatar_filename = null;
if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['profile_message'] = "Avatar upload failed (error code {$_FILES['avatar']['error']}). Please try a smaller file.";
        header("Location: user_profile.php");
        exit();
    }

    $imageInfo = @getimagesize($_FILES['avatar']['tmp_name']);
    if ($imageInfo === false || !isset(AVATAR_ALLOWED_TYPES[$imageInfo[2]])) {
        $_SESSION['profile_message'] = "That file doesn't look like a supported image (JPEG, PNG, GIF, or WEBP).";
        header("Location: user_profile.php");
        exit();
    }

    $ext = AVATAR_ALLOWED_TYPES[$imageInfo[2]];
    // Create a static filename based on user id.
    $avatar_filename = 'avatar_' . $user_id . '.' . $ext;

    // Delete any previous avatar file for this user.
    foreach (glob($avatar_dir_fs . 'avatar_' . $user_id . '.*') as $existingFile) {
        // Avoid deleting the default avatar if it exists in the same folder
        if (basename($existingFile) !== 'default_avatar.png') {
            unlink($existingFile);
        }
    }

    // Save the new avatar using the file system path.
    $destination = $avatar_dir_fs . $avatar_filename;
    if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $destination)) {
        $_SESSION['profile_message'] = "Error uploading file.";
        header("Location: user_profile.php");
        exit();
    }
}

// A new upload always wins over a "remove" checkbox left checked by mistake.
$avatar_removed = false;
if (!$avatar_filename && $remove_avatar) {
    foreach (glob($avatar_dir_fs . 'avatar_' . $user_id . '.*') as $existingFile) {
        // Avoid deleting the default avatar if it exists in the same folder
        if (basename($existingFile) !== 'default_avatar.png') {
            unlink($existingFile);
        }
    }
    $avatar_removed = true;
}

if ($avatar_filename) {
    $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, avatar = ?, theme = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $first_name, $last_name, $avatar_filename, $theme, $user_id);
} elseif ($avatar_removed) {
    $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, avatar = NULL, theme = ? WHERE id = ?");
    $stmt->bind_param("sssi", $first_name, $last_name, $theme, $user_id);
} else {
    $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, theme = ? WHERE id = ?");
    $stmt->bind_param("sssi", $first_name, $last_name, $theme, $user_id);
}
$stmt->execute();
$stmt->close();

$_SESSION['profile_message'] = "Profile updated successfully.";
header("Location: user_profile.php");
exit();
?>