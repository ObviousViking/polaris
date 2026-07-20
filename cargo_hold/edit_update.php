<?php
// edit_update.php - edits an existing case update. Any logged-in user may
// edit (only deletion is admin/super-restricted). Logs an old/new diff into
// case_history as a CASE_UPDATE_EDITED action.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/integrity.php';
require_once '../includes/permissions.php';
require_permission($conn, 'case_update_edit');

$update_id = isset($_GET['update_id']) ? intval($_GET['update_id']) : 0;
if ($update_id <= 0) {
    echo "Update not specified.";
    exit();
}

$stmt = $conn->prepare("SELECT * FROM case_updates WHERE update_id = ?");
$stmt->bind_param("i", $update_id);
$stmt->execute();
$update = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$update) {
    echo "Update not found.";
    exit();
}

$job_id = (int) $update['job_id'];
$validTypes = ['Case Update', 'Communication'];
$validCommTypes = ['Email', 'Phone', 'In Person', 'Other'];
$message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $update_text = trim($_POST['update_text']);
    $update_type = in_array($_POST['update_type'] ?? '', $validTypes, true) ? $_POST['update_type'] : 'Case Update';
    $comm_type = null;
    $comm_person = null;
    if ($update_type === 'Communication') {
        $comm_type = in_array($_POST['comm_type'] ?? '', $validCommTypes, true) ? $_POST['comm_type'] : null;
        $comm_person = trim($_POST['comm_person'] ?? '');
    }
    if (empty($update_text)) {
        $message = "Please enter update text.";
    } elseif ($update_type === 'Communication' && ($comm_type === null || $comm_person === '')) {
        $message = "Please select a communication type and enter who it was with.";
    } else {
        if ($comm_person === '') {
            $comm_person = null;
        }
        $user_id = (int) $_SESSION['user_id'];
        $now = date('Y-m-d H:i:s');

        // Old/new diff, same shape edit_job.php uses for field edits.
        $changes = json_encode([
            'Update ID' => $update_id,
            'Type' => ['old' => $update['update_type'], 'new' => $update_type],
            'Communication Type' => ['old' => $update['comm_type'], 'new' => $comm_type],
            'Communication With' => ['old' => $update['comm_person'], 'new' => $comm_person],
            'Text' => ['old' => $update['update_text'], 'new' => $update_text],
        ]);

        $stmt = $conn->prepare("UPDATE case_updates SET update_type = ?, comm_type = ?, comm_person = ?, update_text = ?, updated_at = ?, updated_by = ? WHERE update_id = ?");
        $stmt->bind_param("sssssii", $update_type, $comm_type, $comm_person, $update_text, $now, $user_id, $update_id);
        if ($stmt->execute()) {
            insert_history_row($conn, 'case_history', $job_id, 'CASE_UPDATE_EDITED', $user_id, $changes);

            echo "<script>
                    window.opener.location.reload();
                    window.close();
                  </script>";
            exit();
        } else {
            $message = "Error updating: " . $stmt->error;
        }
        $stmt->close();
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
    <title>Edit Case Update</title>
    <link rel="stylesheet" href="/assets/theme.css">
    <link href="../js/quill/quill.snow.css" rel="stylesheet">
    <script src="../js/quill/quill.js"></script>
    <style>
    body {
        font-family: Arial, sans-serif;
        background: var(--polaris-surface-deep);
        color: var(--polaris-text);
        padding: 20px;
        margin: 0;
    }

    .container {
        max-width: 600px;
        margin: 40px auto;
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 5px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
    }

    label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
        color: var(--polaris-text-dim);
    }

    select,
    input[type="text"] {
        width: 100%;
        padding: 8px;
        margin-bottom: 15px;
        background: var(--polaris-bg);
        color: var(--polaris-text);
        border: 1px solid var(--polaris-border);
        border-radius: 3px;
        box-sizing: border-box;
    }

    #editor-container {
        height: 200px;
        /* Always white/black - this is the Quill editing canvas, not part
           of the app chrome, so it should not follow the theme. */
        background: #fff;
        color: #000;
    }

    input[type="submit"] {
        background: var(--polaris-accent);
        color: var(--polaris-text);
        padding: 5px 10px;
        border: none;
        border-radius: 3px;
        cursor: pointer;
        font-size: 14px;
        margin-top: 10px;
        width: 100%;
    }

    input[type="submit"]:hover {
        background: var(--polaris-accent-hover);
    }

    .message {
        margin-bottom: 10px;
        padding: 8px;
        background: var(--polaris-error-bg);
        color: var(--polaris-error-text);
        border-radius: 3px;
        text-align: center;
    }
    </style>
</head>

<body>
    <div class="container">
        <h2>Edit Case Update</h2>
        <?php if (!empty($message)): ?>
        <p class="message"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>
        <form id="updateForm" method="post" action="edit_update.php?update_id=<?php echo $update_id; ?>">
            <label for="update_type">Type</label>
            <select id="update_type" name="update_type">
                <?php foreach ($validTypes as $type): ?>
                <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $update['update_type'] === $type ? 'selected' : ''; ?>><?php echo htmlspecialchars($type); ?></option>
                <?php endforeach; ?>
            </select>
            <div id="comm_fields" style="display:none;">
                <label for="comm_type">Communication Type</label>
                <select id="comm_type" name="comm_type">
                    <?php foreach ($validCommTypes as $ctype): ?>
                    <option value="<?php echo htmlspecialchars($ctype); ?>" <?php echo $update['comm_type'] === $ctype ? 'selected' : ''; ?>><?php echo htmlspecialchars($ctype); ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="comm_person">Person</label>
                <input type="text" id="comm_person" name="comm_person" placeholder="Who was this communication with?" value="<?php echo htmlspecialchars($update['comm_person'] ?? ''); ?>">
            </div>
            <textarea id="update_text" name="update_text" style="display:none;"><?php echo htmlspecialchars($update['update_text']); ?></textarea>
            <div id="editor-container"></div>
            <input type="submit" value="Save Changes">
        </form>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var hidden = document.getElementById('update_text');
        var quill = new Quill('#editor-container', {
            theme: 'snow',
            modules: {
                toolbar: [
                    ['bold', 'italic', 'underline'],
                    [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                    ['link', 'image'],
                    [{ 'color': [] }, { 'background': [] }]
                ]
            }
        });
        quill.root.innerHTML = hidden.value;

        document.getElementById('updateForm').onsubmit = function() {
            hidden.value = quill.root.innerHTML;
            return true;
        };

        toggleCommFields();
        document.getElementById('update_type').addEventListener('change', toggleCommFields);
    });

    function toggleCommFields() {
        var isComm = document.getElementById('update_type').value === 'Communication';
        document.getElementById('comm_fields').style.display = isComm ? '' : 'none';
    }
    </script>
</body>

</html>
