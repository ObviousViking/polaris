<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/spaceport.php';

$submission_id = isset($_GET['submission_id']) ? intval($_GET['submission_id']) : 0;
include '../header.php';
?>
<div class="content-wrapper" style="max-width:500px; margin:150px auto 20px; text-align:center;">
    <h2>Submission Received</h2>
    <p>Your case has been filed for review. It doesn't have a case number yet - you'll be notified once it's been looked at.</p>
    <p style="font-size:20px; font-family:monospace; margin:20px 0;"><?php echo htmlspecialchars(submission_ref($submission_id)); ?></p>
    <p>
        <a href="view_submission.php?submission_id=<?php echo $submission_id; ?>"
            style="display:inline-block; padding:5px 10px; background:var(--polaris-accent); color:var(--polaris-text); border-radius:3px; font-size:14px; text-decoration:none; margin-bottom:10px;">
            View Submission
        </a>
    </p>
    <p>
        <a href="my_submissions.php"
            style="display:inline-block; padding:5px 10px; background:var(--polaris-accent); color:var(--polaris-text); border-radius:3px; font-size:14px; text-decoration:none;">
            My Submissions
        </a>
    </p>
</div>
