<?php
/**
 * ============================================================
 * VendoLink — Email Verification Handler
 * ============================================================
 *
 * Accessed via email link: /auth/verify_email.php?token=xxx
 *
 * Architecture stub — full email sending will be implemented
 * in a later module with PHPMailer/SMTP integration.
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../controllers/AuthController.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

// Get token from query string
$rawToken = trim($_GET['token'] ?? '');

if (empty($rawToken)) {
    flash('danger', 'Invalid verification link — no token provided.');
    redirect('auth/login.php');
}

// Dispatch to controller
(new AuthController())->verifyEmail($rawToken);
