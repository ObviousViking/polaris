<?php
// login.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}


// If the user is already logged in, redirect them to the dashboard.
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

?>


<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <script>
    // Pages inside the System Management iframes (Manage Users, Backup/Restore,
    // etc.) redirect here whenever the session is invalid - e.g. a DB restore
    // destroys the session. Left alone, that navigation happens inside the
    // small iframe, showing the full login screen shrunk into that panel
    // while the surrounding nav stays put. Break out to the top window instead.
    if (window.top !== window.self) {
        window.top.location.href = window.location.href;
    }
    </script>
    <title>Polaris Login</title>
    <link rel="stylesheet" href="/assets/theme.css">
    <style>
    * {
        box-sizing: border-box;
    }

    html,
    body {
        height: 100%;
        margin: 0;
        font-family: Arial, sans-serif;
        background-color: var(--polaris-bg);
        color: var(--polaris-text);
    }

    .login-container {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 100vh;
    }

    .login-form {
        background-color: var(--polaris-surface);
        padding: 30px;
        border-radius: 5px;
        width: 320px;
        box-shadow: 0 0 15px rgba(0, 0, 0, 0.5);
    }

    .login-form .logo {
        text-align: center;
        margin-bottom: 20px;
    }

    .login-form .logo img {
        height: 50px;
        margin: 0 auto;
        display: block;
    }

    .login-form .logo h2 {
        margin-top: 10px;
        font-size: 24px;
    }

    .login-form label {
        display: block;
        margin-bottom: 8px;
    }

    .login-form input[type="email"],
    .login-form input[type="password"] {
        width: 100%;
        padding: 10px;
        margin-bottom: 20px;
        border: none;
        border-radius: 3px;
    }

    .login-form input[type="submit"] {
        width: 100%;
        padding: 12px;
        background-color: var(--polaris-border);
        border: none;
        border-radius: 3px;
        cursor: pointer;
        font-size: 16px;
        color: var(--polaris-text);
    }

    .error-message {
        color: var(--polaris-danger);
        text-align: center;
        margin-bottom: 15px;
    }
    </style>
</head>

<body>
    <div class="login-container">
        <form class="login-form" method="post" action="login_process.php">
            <div class="logo">
                <img src="logo.png" alt="Polaris Logo">
                <h2>Polaris Login</h2>
            </div>
            <?php 
        // Display error message if available.
        if (isset($error)) {
          echo "<div class='error-message'>$error</div>";
        }
      ?>
            <label for="email">Email:</label>
            <input type="email" name="email" required>

            <label for="password">Password:</label>
            <input type="password" name="password" required>

            <input type="submit" value="Log In">
        </form>
    </div>
</body>

</html>