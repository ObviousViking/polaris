<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';  // This file should define $conn
require_once '../includes/integrity.php';

if (!isset($_GET['job_id'])) {
    echo "Job ID not specified.";
    exit();
}
$job_id = intval($_GET['job_id']);

$validTypes = ['Case Update', 'Communication'];
$message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $update_text = trim($_POST['update_text']);
    $update_type = in_array($_POST['update_type'] ?? '', $validTypes, true) ? $_POST['update_type'] : 'Case Update';
    if (empty($update_text)) {
        $message = "Please enter update text.";
    } else {
        $user_id = $_SESSION['user_id'];
        $update_date = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("INSERT INTO case_updates (job_id, user_id, update_type, update_text, update_date) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iisss", $job_id, $user_id, $update_type, $update_text, $update_date);
        if ($stmt->execute()) {
            $updateId = $conn->insert_id;
            $changes = json_encode(['Update ID' => $updateId, 'Type' => $update_type, 'Text' => $update_text]);
            insert_history_row($conn, 'case_history', $job_id, 'CASE_UPDATE_ADDED', (int) $user_id, $changes);

            echo "<script>
                    alert('Update added successfully.');
                    window.opener.location.reload(); // Refresh parent window
                    window.close(); // Close pop-up window
                  </script>";
            exit();
        } else {
            $message = "Error adding update: " . $stmt->error;
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
    <title>Add Case Update</title>
    <link rel="stylesheet" href="/assets/theme.css">
    <!-- Include Quill CSS and JS locally -->
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

    #editor-container {
        height: 200px;
        /* Always white/black - this is the Quill editing canvas, not part
           of the app chrome, so it should not follow the theme. */
        background: #fff;
        color: #000;
    }

    label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
        color: var(--polaris-text-dim);
    }

    select {
        width: 100%;
        padding: 8px;
        margin-bottom: 15px;
        background: var(--polaris-bg);
        color: var(--polaris-text);
        border: 1px solid var(--polaris-border);
        border-radius: 3px;
    }

    input[type="submit"] {
        background: var(--polaris-success-strong);
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
        background: var(--polaris-success-strong-hover);
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
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var quill = new Quill('#editor-container', {
            theme: 'snow',
            modules: {
                toolbar: [
                    ['bold', 'italic', 'underline'],
                    [{
                        'list': 'ordered'
                    }, {
                        'list': 'bullet'
                    }],
                    ['link', 'image'],
                    [{
                        'color': []
                    }, {
                        'background': []
                    }]
                ]
            }
        });

        document.getElementById('updateForm').onsubmit = function() {
            var html = quill.root.innerHTML;
            document.getElementById('update_text').value = html;
            return true;
        };
    });
    </script>
</head>

<body>
    <div class="container">
        <h2>Add Case Update</h2>
        <?php if (!empty($message)): ?>
        <p class="message"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>
        <form id="updateForm" method="post" action="add_update.php?job_id=<?php echo $job_id; ?>">
            <label for="update_type">Type</label>
            <select id="update_type" name="update_type">
                <?php foreach ($validTypes as $type): ?>
                <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                <?php endforeach; ?>
            </select>
            <!-- Hidden textarea to hold the editor's content -->
            <textarea id="update_text" name="update_text" style="display:none;"></textarea>
            <!-- Div that becomes the Quill editor -->
            <div id="editor-container"></div>
            <input type="submit" value="Add Update">
        </form>
    </div>
</body>

</html>