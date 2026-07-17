<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once('../header.php');
?>
<style>
    /* header.php's own body{} already sets margin/background/color/font-family
       page-wide. This page used to redeclare a second <!DOCTYPE html><html>
       <head> here with its own body{} - browsers tolerate the invalid
       nesting, but that stray rule won the cascade and bled its font into
       the real header/nav above it. The 120px clearance moves onto
       .container's top margin since body no longer carries it. */

    .container {
        max-width: 900px;
        margin: 120px auto 0 auto;
        padding: 20px;
    }

    h1 {
        text-align: center;
        font-size: 32px;
        margin-bottom: 30px;
    }

    p {
        text-align: center;
        color: var(--polaris-text-secondary);
        font-size: 18px;
    }

    </style>

    <div class="container">
        <h1>Captain's Log</h1>
        <p>Welcome to the Case Examination Portal.<br>
        Please access exhibits through the Cargo Hold to begin your examinations.</p>
    </div>
