<?php
// setup.php
session_start();
require_once 'db.php';
require_once 'includes/settings.php';

// This page only ever creates the FIRST user. If any user already exists, the
// application is already installed — refuse to run again (this used to be a
// permanently-open, unauthenticated "create admin" endpoint).
$existing = $conn->query("SELECT COUNT(*) AS total FROM users");
if ($existing && ($row = $existing->fetch_assoc()) && (int)$row['total'] > 0) {
    header("Location: login.php");
    exit();
}

// If db.php didn't die() before reaching this line, the connection above
// already succeeded - this is just for display.
$db_status_ok = true;
$db_host_display = getenv('DB_HOST') . ':' . (getenv('DB_PORT') ?: '3306');
$db_name_display = getenv('DB_NAME');
$db_user_display = getenv('DB_USER');

// Pre-filled with the Docker image's mounted data volume - just accept it
// as-is unless you've changed the volume mount in docker-compose.yml.
// Editable later from Case Management -> Manage System Details -> Manage
// Storage Settings regardless.
$data_root = get_data_root($conn);
$data_host_display = get_data_host_path_display();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';
    $data_root  = trim($_POST['data_root'] ?? '') ?: $data_root;
}

// Checked on every load (including after a POST, since data_root may have
// just changed) so the status shown always matches what's about to be saved.
$data_root_exists = is_dir($data_root);
if (!$data_root_exists) {
    @mkdir($data_root, 0775, true);
    $data_root_exists = is_dir($data_root);
}
$data_root_writable = $data_root_exists && is_writable($data_root);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($first_name === '' || $last_name === '' || $email === '' || $password === '') {
        $error = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } elseif (!$data_root_writable) {
        $error = "The data storage path isn't writable - fix the path or its permissions before continuing.";
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, password, role) VALUES (?, ?, ?, ?, 'super')");
        $stmt->bind_param("ssss", $first_name, $last_name, $email, $hashed_password);

        if ($stmt->execute()) {
            // Log the new super user straight in and drop into the app.
            $new_user_id = $stmt->insert_id;
            $stmt->close();

            save_data_root($conn, $data_root);

            session_regenerate_id(true);
            $_SESSION['user_id'] = $new_user_id;
            header("Location: dashboard.php");
            exit();
        } else {
            $error = "Error: " . $conn->error;
            $stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Polaris Setup</title>
    <link rel="stylesheet" href="/assets/theme.css">
    <style>
    body {
        margin: 0;
        font-family: Arial, sans-serif;
        background: var(--polaris-bg);
        color: var(--polaris-text);
        display: flex;
        justify-content: center;
        padding: 40px 20px;
        box-sizing: border-box;
    }

    .container {
        max-width: 560px;
        width: 100%;
        padding: 20px;
        background: var(--polaris-surface);
        border-radius: 8px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
        box-sizing: border-box;
    }

    .branding {
        display: flex;
        justify-content: center;
        margin-bottom: 10px;
    }

    .branding img {
        height: 64px;
    }

    h1 {
        text-align: center;
        font-size: 24px;
        margin-bottom: 20px;
    }

    .step {
        border: 1px solid var(--polaris-border);
        border-radius: 6px;
        padding: 15px;
        margin-bottom: 20px;
    }

    .step h2 {
        margin: 0 0 10px;
        font-size: 16px;
        color: var(--polaris-text-dim);
    }

    .status-line {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
        margin-bottom: 4px;
    }

    .status-ok {
        color: var(--polaris-success-strong);
    }

    .status-bad {
        color: var(--polaris-danger-alt);
    }

    .status-detail {
        font-size: 13px;
        color: var(--polaris-text-muted);
        margin: 4px 0 0 22px;
    }

    .form-group {
        margin-bottom: 15px;
    }

    label {
        display: block;
        margin-bottom: 5px;
        color: var(--polaris-text-secondary);
    }

    input[type="text"],
    input[type="email"],
    input[type="password"] {
        width: 100%;
        max-width: 100%;
        padding: 8px;
        border: 1px solid var(--polaris-border);
        border-radius: 4px;
        background: var(--polaris-divider);
        color: var(--polaris-text);
        box-sizing: border-box;
    }

    input[type="submit"] {
        width: 100%;
        padding: 10px;
        background: var(--polaris-success-strong);
        border: none;
        border-radius: 4px;
        color: var(--polaris-text);
        font-size: 16px;
        cursor: pointer;
    }

    input[type="submit"]:hover {
        background: var(--polaris-success-strong-hover);
    }

    .error {
        color: var(--polaris-danger-alt);
        margin-bottom: 15px;
        text-align: center;
    }

    .info {
        color: var(--polaris-text-secondary);
        margin-bottom: 15px;
        text-align: center;
        font-size: 14px;
    }
    </style>
</head>

<body>
    <div class="container">
        <div class="branding">
            <img src="/logo.png" alt="Polaris logo">
        </div>
        <h1>Polaris Setup</h1>

        <?php if (isset($error)): ?>
        <div class="error">
            <p><?php echo htmlspecialchars($error); ?></p>
        </div>
        <?php endif; ?>

        <div class="step">
            <h2>1. Database Connection</h2>
            <div class="status-line status-ok">&#10003; Connected</div>
            <div class="status-detail">
                <?php echo htmlspecialchars($db_user_display); ?>@<?php echo htmlspecialchars($db_host_display); ?>
                / <?php echo htmlspecialchars($db_name_display); ?>
            </div>
        </div>

        <div class="step">
            <h2>2. Data Storage</h2>
            <div class="status-line <?php echo $data_root_writable ? 'status-ok' : 'status-bad'; ?>">
                <?php echo $data_root_writable ? '&#10003; Writable' : '&#10007; Not writable'; ?>
            </div>
            <div class="status-detail">Host path: <?php echo htmlspecialchars($data_host_display); ?></div>
            <div class="status-detail">Avatars, exhibit photos, and exhibit documents are stored here
                (in fixed subfolders). Editable later from Case Management -> Manage System Details.</div>
        </div>

        <form method="POST" action="">
            <div class="form-group">
                <label for="data_root">Data storage root (container path - only change this if you
                    know what you're doing)</label>
                <input type="text" id="data_root" name="data_root"
                    value="<?php echo htmlspecialchars($data_root); ?>">
            </div>

            <div class="step" style="margin-top: 25px;">
                <h2>3. Create Super User</h2>
                <p class="info" style="text-align:left;">This account is purely for administrative
                    purposes and shouldn't be used as your personal account.</p>
                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name" required>
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name" required>
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <input type="submit" value="Create Super User &amp; Continue">
            </div>
        </form>
    </div>
</body>

</html>
