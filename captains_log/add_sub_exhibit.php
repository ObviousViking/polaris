<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../db.php';

$parent_id = isset($_GET['parent_id']) ? intval($_GET['parent_id']) : 0;
if ($parent_id <= 0) {
    die("Invalid parent exhibit ID.");
}

// Get parent exhibit info
$parent_stmt = $conn->prepare("SELECT exhibit_ref, job_id, location_id, delivered_by FROM exhibits WHERE exhibit_id = ?");
$parent_stmt->bind_param("i", $parent_id);
$parent_stmt->execute();
$parent_result = $parent_stmt->get_result();
$parent = $parent_result->fetch_assoc();

if (!$parent) {
    die("Parent exhibit not found.");
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $suffix = trim($_POST['suffix']);
    $type_id = intval($_POST['exhibit_type_id']);
    $user_id = $_SESSION['user_id'];

    if ($suffix === '') {
        $errors[] = 'Sub-exhibit suffix is required.';
    }

    if ($type_id <= 0) {
        $errors[] = 'Please select an exhibit type.';
    }

    // Generate full exhibit_ref in UPPERCASE
    $sub_ref = strtoupper($parent['exhibit_ref'] . '_' . $suffix);

    // Check for duplicates
    $check_stmt = $conn->prepare("SELECT exhibit_id FROM exhibits WHERE exhibit_ref = ?");
    $check_stmt->bind_param("s", $sub_ref);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $errors[] = 'An exhibit with that reference already exists.';
    }

    if (empty($errors)) {
        $insert_stmt = $conn->prepare("
            INSERT INTO exhibits (exhibit_ref, exhibit_type_id, parent_id, job_id, created_by, location_id, delivered_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $insert_stmt->bind_param("siiiiis", $sub_ref, $type_id, $parent_id, $parent['job_id'], $user_id, $parent['location_id'], $parent['delivered_by']);

        if ($insert_stmt->execute()) {
            header("Location: examination.php?exhibit_id=" . $parent_id);
            exit();
        } else {
            $errors[] = 'Failed to create sub-exhibit: ' . $conn->error;
        }
    }
}

// Fetch exhibit types
$types_result = $conn->query("SELECT exhibit_type_id, type_name FROM exhibit_types ORDER BY type_name ASC");
$exhibit_types = $types_result->fetch_all(MYSQLI_ASSOC);

// header.php must come after the POST-handling block above - it's an
// unconditional include that starts writing HTML output the moment it
// runs, and once any output has been sent, header("Location: ...") fails
// silently (this app runs with display_errors=Off, so nothing even shows
// the warning - the page just fails to redirect and shows a blank shell).
include '../header.php';
?>

<link rel="stylesheet" href="css/examination.css">
<style>
    /* header.php's own body{} already sets margin/background/color/font-family
       page-wide. This page used to redeclare a second <!DOCTYPE html><html>
       <head> here with its own body{} - browsers tolerate the invalid
       nesting, but that stray rule won the cascade and bled its font into
       the real header/nav above it. The 120px clearance moves onto
       .form-container's top margin since body no longer carries it. */

    .form-container {
        max-width: 600px;
        margin: 120px auto 0 auto;
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 5px;
        box-shadow: 0 2px 8px rgba(255, 255, 255, 0.1);
    }

    h2 {
        margin-top: 0;
    }

    label {
        display: block;
        margin-top: 10px;
    }

    input[type="text"],
    select {
        width: 100%;
        padding: 8px;
        margin-top: 5px;
        background: var(--polaris-bg);
        color: var(--polaris-text);
        border: 1px solid var(--polaris-border);
        border-radius: 4px;
    }

    .btn {
        margin-top: 20px;
        padding: 5px 10px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border: none;
        border-radius: 3px;
        cursor: pointer;
        font-size: 14px;
        text-decoration: none;
    }

    .btn:hover {
        background: var(--polaris-accent-hover);
    }

    .btn-add {
        background: var(--polaris-success-strong);
    }

    .btn-add:hover {
        background: var(--polaris-success-strong-hover);
    }

    .error-box {
        background: var(--polaris-error-bg);
        color: var(--polaris-error-text);
        padding: 10px;
        margin-bottom: 15px;
        border-radius: 4px;
    }

    .error-box ul {
        margin: 0;
        padding-left: 20px;
    }
    </style>


    <div class="form-container">
        <h2>Add Sub-Exhibit to <?= htmlspecialchars($parent['exhibit_ref']) ?></h2>

        <?php if (!empty($errors)): ?>
        <div class="error-box">
            <ul>
                <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="post">
            <label for="suffix">Sub-Exhibit Suffix (e.g., HDD0):</label>
            <input type="text" id="suffix" name="suffix" placeholder="HDD0" required>

            <label for="exhibit_type_id">Exhibit Type:</label>
            <select id="exhibit_type_id" name="exhibit_type_id" required>
                <option value="">-- Select Type --</option>
                <?php foreach ($exhibit_types as $type): ?>
                <option value="<?= $type['exhibit_type_id'] ?>">
                    <?= htmlspecialchars($type['type_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-add">➕ Add Sub-Exhibit</button>
            <a href="examination.php?exhibit_id=<?= $parent_id ?>" class="btn">Cancel</a>
        </form>
    </div>

</body>

</html>