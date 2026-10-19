<?php
/**
 * ============================================================
 * VendoLink — Auth Page Shared Header
 * ============================================================
 *
 * Used by auth pages: login, forgot-password, reset-password.
 * Includes Bootstrap 5, Bootstrap Icons, Google Fonts, and
 * VendoLink custom CSS.
 *
 * @package VendoLink
 * @version 1.0
 */

// Require session to be started
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

// Page title fallback
$pageTitle = $pageTitle ?? APP_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= APP_NAME ?> — <?= APP_TAGLINE ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>

    <!-- Bootstrap 5 -->
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
          rel="stylesheet">

    <!-- VendoLink Custom CSS -->
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/app.css">

    <!-- Favicon placeholder -->
    <link rel="icon" type="image/svg+xml"
          href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚡</text></svg>">
    <script>window.BASE_URL = <?= json_encode(BASE_URL) ?>;</script>
</head>
<body class="auth-body">
