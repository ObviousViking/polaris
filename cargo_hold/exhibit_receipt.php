<?php
// Start the session only if not already active.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

require_once('../db.php');
require_once '../includes/permissions.php';
require_once '../includes/exhibit_receipts.php';
require_permission($conn, 'exhibit_view');

// Ensure job_id and exhibit ids are provided.
if (!isset($_GET['job_id'])) {
    die("Job ID not specified.");
}
$job_id = intval($_GET['job_id']);

if (!isset($_GET['ids'])) {
    die("No exhibit IDs specified.");
}
$ids = $_GET['ids'];
if (!preg_match('/^[0-9,]+$/', $ids)) {
    die("Invalid exhibit IDs.");
}
$exhibitIds = array_map('intval', explode(',', $ids));

// Determine the receipt type: default to "in", or if type=out then it's a book-out receipt.
$receiptType = (isset($_GET['type']) && $_GET['type'] === 'out') ? 'out' : 'in';

// For a book-out receipt, get the "Booked Out To" value.
$bookedOutTo = ($receiptType === 'out' && isset($_GET['booked_out_to'])) ? $_GET['booked_out_to'] : '';

$exhibits = fetch_exhibit_receipt_rows($conn, $exhibitIds, $receiptType);
if (empty($exhibits)) {
    die("No exhibits found.");
}

// Use the first exhibit's job info for the Job Number.
$jobCustomRef = $exhibits[0]['custom_ref'];

// Determine "Booked By" (for Book In) or "Booked Out By" (for Book Out) from the current session user.
$currentUserId = $_SESSION['user_id'];
$queryUser = "SELECT CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE id = " . intval($currentUserId);
$resUser = $conn->query($queryUser);
$userName = $currentUserId; // fallback
if ($resUser && $rowUser = $resUser->fetch_assoc()) {
    $userName = $rowUser['full_name'];
}

echo render_exhibit_receipt_html($exhibits, $receiptType, $jobCustomRef, $userName, $bookedOutTo);
