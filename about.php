<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once __DIR__ . '/includes/version.php';
include 'header.php';
?>

<div class="content-wrapper">
    <div class="about-card">
        <div class="about-header">
            <img src="/logo.png" alt="Polaris logo" class="about-logo">
            <div>
                <h2>Polaris</h2>
                <p class="about-version"><?php echo htmlspecialchars(APP_VERSION); ?></p>
            </div>
        </div>

        <p>Polaris is a self-hosted case and evidence management system for digital forensics
            labs - cases, exhibits, examination records, task tracking, asset logistics, and a
            tamper-evident audit trail, all running entirely on your own infrastructure with no
            external services required.</p>

        <a href="https://github.com/ObviousViking/polaris" target="_blank" rel="noopener" class="about-github-link">
            View on GitHub &rarr;
        </a>

        <h3>Built With</h3>
        <ul class="about-credits">
            <li>Logo: <a href="https://www.flaticon.com/free-icons/polaris" target="_blank" rel="noopener">Polaris icons created by Artifex - Flaticon</a></li>
            <li><a href="https://quilljs.com/" target="_blank" rel="noopener">Quill</a> - rich text editing</li>
            <li><a href="https://www.dropzonejs.com/" target="_blank" rel="noopener">Dropzone.js</a> - drag-and-drop file uploads</li>
            <li><a href="https://jquery.com/" target="_blank" rel="noopener">jQuery</a></li>
        </ul>
    </div>
</div>

<style>
.content-wrapper {
    max-width: 700px;
    margin: 140px auto 40px auto;
    padding: 0 20px;
}

.about-card {
    background: var(--polaris-surface);
    border: 1px solid var(--polaris-border);
    border-radius: 8px;
    padding: 25px 30px;
}

.about-header {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 15px;
}

.about-logo {
    height: 56px;
    width: 56px;
}

.about-header h2 {
    margin: 0;
}

.about-version {
    margin: 2px 0 0 0;
    color: var(--polaris-text-muted);
    font-size: 13px;
}

.about-github-link {
    display: inline-block;
    margin: 10px 0 5px 0;
    color: var(--polaris-accent);
    text-decoration: none;
    font-size: 14px;
}

.about-github-link:hover {
    text-decoration: underline;
}

.about-card h3 {
    margin-top: 25px;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--polaris-text-muted);
    border-top: 1px solid var(--polaris-border);
    padding-top: 20px;
}

.about-credits {
    list-style: none;
    padding: 0;
    margin: 10px 0 0 0;
    font-size: 14px;
    color: var(--polaris-text-secondary);
}

.about-credits li {
    padding: 4px 0;
}

.about-credits a {
    color: var(--polaris-accent);
    text-decoration: none;
}

.about-credits a:hover {
    text-decoration: underline;
}
</style>

</body>

</html>
