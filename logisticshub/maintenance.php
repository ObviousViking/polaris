<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once '../includes/permissions.php';
require_permission($conn, 'asset_manage');
if (isset($_GET['embedded'])) {
    require_once '../includes/embedded_header.php';
} else {
    require_once('../header.php');
}
require_once('../includes/coming_soon.php');

render_coming_soon(
    'Maintenance & Calibration',
    "Tracking scheduled maintenance/calibration dates and history for lab equipment isn't built "
    . "yet - the 'availability' field on an asset (e.g. 'In Maintenance') has to be set manually "
    . "for now.",
    'lh_dashboard.php',
    'Back to Asset Management'
);
