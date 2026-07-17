<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../header.php';

if (!isset($_GET['job_id'])) {
    echo "Job ID not specified.";
    exit();
}
$job_id = intval($_GET['job_id']);
$message = "";

// Fetch logged-in user's ID and name
$user_id = intval($_SESSION['user_id']);
$stmt = $conn->prepare("SELECT id, CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($extracted_by_id, $extracted_by_name);
$stmt->fetch();
$stmt->close();

if (!$extracted_by_name) {
    $extracted_by_name = "Unknown User";
    $extracted_by_id = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $exhibit_ref = strtoupper(trim($_POST['exhibit_ref']));
    $description = trim($_POST['description']);
    $produced_date = $_POST['produced_date'] ?: date('Y-m-d');

    if ($exhibit_ref === '') {
        $message = "Exhibit reference is required.";
    } else {
        $dupCheck = $conn->prepare("SELECT COUNT(*) FROM produced_exhibits WHERE UPPER(exhibit_ref) = ? AND job_id = ?");
        $dupCheck->bind_param("si", $exhibit_ref, $job_id);
        $dupCheck->execute();
        $dupCheck->bind_result($count);
        $dupCheck->fetch();
        $dupCheck->close();

        if ($count > 0) {
            $message = "Exhibit reference already exists for this job.";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO produced_exhibits (job_id, exhibit_ref, description, produced_date, extracted_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isssi", $job_id, $exhibit_ref, $description, $produced_date, $extracted_by_id);
            if ($stmt->execute()) {
                $message = "Produced exhibit added successfully.";
            } else {
                $message = "Error adding produced exhibit.";
            }
            $stmt->close();
        }
    }
}
?>

<style>
    /* header.php's own body{} already sets margin/background/color/font-family
       page-wide. This page used to redeclare a second <!DOCTYPE html><html>
       <head> here with its own body{} - browsers tolerate the invalid
       nesting, but that stray rule won the cascade and bled its font into
       the real header/nav above it. Scoped to .container instead, which
       already clears the fixed header via its margin-top. */

    .container {
        max-width: 800px;
        margin: 120px auto 20px;
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
        box-sizing: border-box;
    }

    h2 {
        font-size: 24px;
        margin-bottom: 20px;
        text-align: center;
    }

    .back-btn {
        display: inline-block;
        padding: 5px 10px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border: none;
        border-radius: 3px;
        text-decoration: none;
        text-align: center;
        font-size: 14px;
        cursor: pointer;
        transition: background 0.3s ease;
        margin-bottom: 20px;
    }

    .back-btn:hover {
        background: var(--polaris-accent-hover);
    }

    form {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    label {
        font-size: 14px;
        color: var(--polaris-text-dim);
    }

    input[type="text"],
    input[type="date"] {
        width: 100%;
        padding: 8px;
        background: var(--polaris-bg);
        border: 1px solid var(--polaris-border);
        color: var(--polaris-text);
        border-radius: 4px;
        font-size: 14px;
    }

    input[readonly] {
        background: var(--polaris-divider);
        cursor: not-allowed;
    }

    button {
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border: none;
        padding: 5px 10px;
        border-radius: 3px;
        font-size: 14px;
        cursor: pointer;
        transition: background 0.3s ease;
        align-self: flex-start;
    }

    button:hover {
        background: var(--polaris-accent-hover);
    }

    .message {
        margin-bottom: 15px;
        padding: 10px;
        background: var(--polaris-divider);
        border-left: 4px solid var(--polaris-accent);
        font-size: 14px;
    }

    a {
        color: var(--polaris-accent);
        text-decoration: none;
    }

    a:hover {
        text-decoration: underline;
    }
    </style>

    <div class="container">
        <h2>Add Produced Exhibit</h2>

        <?php if (!empty($message)): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="post">
            <label for="exhibit_ref">Exhibit Reference</label>
            <input type="text" name="exhibit_ref" id="exhibit_ref" oninput="this.value = this.value.toUpperCase();"
                required>
            <label for="description">Description</label>
            <input type="text" name="description" id="description">
            <label for="produced_date">Produced Date</label>
            <input type="date" name="produced_date" id="produced_date" value="<?php echo date('Y-m-d'); ?>" readonly>
            <label for="extracted_by">Extracted By</label>
            <input type="text" id="extracted_by" value="<?php echo htmlspecialchars($extracted_by_name); ?>" readonly>
            <input type="hidden" name="extracted_by" value="<?php echo htmlspecialchars($extracted_by_id); ?>">
            <button type="submit">Save</button>
        </form>
        <br>
        <a href="job.php?job_id=<?php echo htmlspecialchars($job_id); ?>" class="back-btn">Go Back</a>

    </div>
</body>

</html>