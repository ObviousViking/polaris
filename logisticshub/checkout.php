<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
if (isset($_GET['embedded'])) {
    require_once '../includes/embedded_header.php';
} else {
    require_once('../header.php');
}
require_once('../includes/coming_soon.php');

render_coming_soon(
    'Asset Checkout',
    "Checking an asset out to a user (and back in again) isn't tracked yet - the 'availability' "
    . "field can be edited manually from Manage Assets, but there's no record of who currently "
    . "has an item or when it's due back.",
    'lh_dashboard.php',
    'Back to Asset Management'
);
