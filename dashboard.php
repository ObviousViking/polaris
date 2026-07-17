<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
include 'header.php';

// Read messages from text file.
$messagesFile = 'random_messages.txt';
if (file_exists($messagesFile)) {
    $messages = file($messagesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!empty($messages)) {
        // Changes every hour rather than once a day.
        $index = (date("z") * 24 + (int) date("G")) % count($messages);
        $messageOfTheDay = $messages[$index];
    } else {
        $messageOfTheDay = "No messages available at the moment.";
    }
} else {
    $messageOfTheDay = "Messages file not found.";
}

// Generate randomized sparkles.
$numSparkles = 10;
$sparklesHTML = "";
for ($i = 0; $i < $numSparkles; $i++) {
    $top = rand(0, 100);
    $left = rand(0, 100);
    $delay = rand(0, 6500) / 1000; // delay between 0 and 6.5 seconds.
    $sparklesHTML .= "<div class='sparkle' style='top: {$top}%; left: {$left}%; animation-delay: {$delay}s;'></div>";
}
?>

<!-- Inline styling for the full-screen sparkle container -->
<style>
html,
body {
    margin: 0;
    padding: 0;
    overflow: hidden;
}

/* Deliberately fixed colours, not theme variables - this is a night-sky
   backdrop (Polaris = the north star), not page chrome, so it stays dark
   with white sparkles regardless of the light/dark theme setting. */
.sparkle-container {
    position: relative;
    display: flex;
    justify-content: center;
    align-items: center;
    height: 100vh;
    /* Full viewport height */
    background-color: #000;
}

.message-of-day {
    position: relative;
    font-size: 24px;
    color: #fff;
    padding: 20px;
    text-align: center;
    z-index: 2;
}

.sparkle {
    position: absolute;
    width: 3px;
    height: 3px;
    background: #fff;
    border-radius: 50%;
    opacity: 0;
    animation: sparkleAnim 6.5s infinite;
}

@keyframes sparkleAnim {
    0% {
        opacity: 0;
        transform: scale(0.6);
    }

    50% {
        opacity: 0.45;
        transform: scale(1);
    }

    100% {
        opacity: 0;
        transform: scale(0.6);
    }
}

@media (prefers-reduced-motion: reduce) {
    .sparkle {
        animation: none;
        opacity: 0.25;
    }
}
</style>

<div class="sparkle-container">
    <div class="message-of-day">
        <?php echo htmlspecialchars($messageOfTheDay); ?>
    </div>
    <?php echo $sparklesHTML; ?>
</div>
</body>

</html>