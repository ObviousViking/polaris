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

$avatar_filename = null;
if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] == UPLOAD_ERR_OK) {
    // Allow only specific image types.
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    if (in_array($_FILES['avatar']['type'], $allowed_types)) {
        // Determine the extension.
        $ext = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
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
}


if ($avatar_filename) {
    $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, avatar = ?, theme = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $first_name, $last_name, $avatar_filename, $theme, $user_id);
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