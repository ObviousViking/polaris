<?php
// includes/coming_soon.php
//
// Shared placeholder for dashboard links that point at a real, planned
// feature that just isn't built yet - so visiting one shows a clear "not
// built yet" page instead of a raw 404 or a blank file.

function render_coming_soon(string $title, string $description, string $backHref, string $backLabel): void
{
    // When loaded inside the System Management nav+iframe shell, the nav on
    // the left already covers "go back" - a back link here would just
    // reload the whole shell inside itself.
    $embedded = isset($_GET['embedded']);
    ?>
<div class="content-wrapper">
    <div class="coming-soon">
        <h2><?php echo htmlspecialchars($title); ?></h2>
        <p class="badge">Not built yet</p>
        <p><?php echo htmlspecialchars($description); ?></p>
        <?php if (!$embedded): ?>
        <a href="<?php echo htmlspecialchars($backHref); ?>" class="back-btn">&larr; <?php echo htmlspecialchars($backLabel); ?></a>
        <?php endif; ?>
    </div>
</div>

<style>
.coming-soon {
    max-width: 600px;
    margin: 40px auto;
    padding: 25px;
    background: var(--polaris-surface);
    border: 1px solid var(--polaris-border);
    border-radius: 8px;
    text-align: center;
}

.coming-soon h2 {
    margin-top: 0;
}

.coming-soon .badge {
    display: inline-block;
    background: var(--polaris-border);
    color: var(--polaris-text-secondary);
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.coming-soon p {
    color: var(--polaris-text-dim);
}

.back-btn {
    display: inline-block;
    margin-top: 15px;
    padding: 5px 10px;
    background: var(--polaris-accent);
    color: var(--polaris-text);
    border-radius: 3px;
    text-decoration: none;
    font-size: 14px;
}

.back-btn:hover {
    background: var(--polaris-accent-hover);
}
</style>

</body>

</html>
<?php
}
