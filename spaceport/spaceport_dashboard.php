<?php
// spaceport/spaceport_dashboard.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/permissions.php';

$userId = (int) $_SESSION['user_id'];
$canSubmit = user_can($conn, $userId, 'submission_create');
$canViewOwn = user_can($conn, $userId, 'submission_view_own');
$canReview = user_can($conn, $userId, 'submission_review');
$canManageQuestions = user_can($conn, $userId, 'manage_submission_questions');
$canManageLookups = user_can($conn, $userId, 'manage_lookups');

include '../header.php';
?>
<style>
    .content-wrapper { max-width: 800px; margin: 120px auto 40px auto; padding: 20px; }
    h2 { text-align: center; margin-bottom: 20px; }
    .card-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; }
    .card { display: block; padding: 18px; background: var(--polaris-surface); border-radius: 8px; box-shadow: 0 2px 10px rgba(255,255,255,0.05); text-decoration: none; color: var(--polaris-text); }
    .card:hover { background: var(--polaris-panel-alt); }
    .card .icon { font-size: 22px; }
    .card h3 { margin: 8px 0 4px; font-size: 16px; }
    .card p { margin: 0; font-size: 13px; color: var(--polaris-text-dim); }
</style>
<div class="content-wrapper">
    <h2>Case Submissions</h2>
    <div class="card-grid">
        <?php if ($canSubmit): ?>
        <a class="card" href="submit_case.php">
            <span class="icon">🚀</span>
            <h3>Submit a Case</h3>
            <p>File a new case for review.</p>
        </a>
        <?php endif; ?>
        <?php if ($canViewOwn): ?>
        <a class="card" href="my_submissions.php">
            <span class="icon">📋</span>
            <h3>My Submissions</h3>
            <p>Check status, respond to reviewer requests.</p>
        </a>
        <?php endif; ?>
        <?php if ($canReview): ?>
        <a class="card" href="review_queue.php">
            <span class="icon">🛰️</span>
            <h3>Review Queue</h3>
            <p>Approve, reject, or request more information.</p>
        </a>
        <a class="card" href="all_submissions.php">
            <span class="icon">📚</span>
            <h3>All Submissions</h3>
            <p>Browse and filter every submission, including approved and rejected ones.</p>
        </a>
        <?php endif; ?>
        <?php if ($canManageQuestions): ?>
        <a class="card" href="manage_submission_questions.php">
            <span class="icon">❓</span>
            <h3>Submission Questions</h3>
            <p>Define the extra questions officers see.</p>
        </a>
        <?php endif; ?>
        <?php if ($canManageLookups): ?>
        <a class="card" href="manage_contacts.php">
            <span class="icon">👤</span>
            <h3>External Contacts</h3>
            <p>Primary/secondary contacts officers can select.</p>
        </a>
        <?php endif; ?>
    </div>
</div>
