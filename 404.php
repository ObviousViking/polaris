<?php
session_start();
require_once('header.php');
?>
<style>
    /* header.php's own body{} already sets margin/background/color/font-family
       and already loads /assets/theme.css page-wide. This page used to
       redeclare a second <!DOCTYPE html><html><head> here (with a duplicate
       theme.css link and its own body{} rule) - browsers tolerate the
       invalid nesting, but that stray rule won the cascade and bled its
       font into the real header/nav above it. The 200px clearance moves
       onto .error-container's top margin since body no longer carries it. */

    .error-container {
        max-width: 600px;
        margin: 200px auto 0 auto;
        text-align: center;
    }

    h1 {
        font-size: 60px;
        margin-bottom: 20px;
        color: var(--polaris-danger);
    }

    p {
        font-size: 20px;
        margin-bottom: 30px;
    }

    a {
        color: var(--polaris-accent);
        text-decoration: none;
        font-size: 18px;
    }

    a:hover {
        text-decoration: underline;
    }
    </style>

    <div class="error-container">
        <h1>404</h1>
        <p>Sorry, the page you are looking for does not exist.</p>
        <a href="/dashboard.php">Return to Home</a>
    </div>
