<?php
// footer.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';

$userName = "Unknown User";
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $sql = "SELECT first_name, last_name FROM users WHERE id = $user_id";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $userName = $user['first_name'] . ' ' . $user['last_name'];
    }
}
?>
<footer style="
    position: fixed;
    bottom: 0;
    left: 0;
    width: 100%;
    height: 30px;
    background-color: var(--polaris-header-bg);
    color: var(--polaris-text);
    text-align: center;
    line-height: 30px;
    font-size: 14px;
    z-index: 1000;
">
    <span>Logged in as: <?php echo htmlspecialchars($userName); ?></span>
    <a href="logout.php" style="color: var(--polaris-text); text-decoration: none; margin-left: 20px;">Logout</a>
</footer>