<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once '../includes/permissions.php';
require_permission($conn, 'asset_view');
if (isset($_GET['embedded'])) {
    require_once '../includes/embedded_header.php';
} else {
    require_once('../header.php');
}
require_once('../includes/coming_soon.php');

render_coming_soon(
    'Audit Log',
    "A history of changes to assets (added, edited, checked out, retired) isn't tracked yet - "
    . "asset records can currently be edited with no record of who changed what or when.",
    'lh_dashboard.php',
    'Back to Asset Management'
);
