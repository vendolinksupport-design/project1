<?php
/**
 * ============================================================
 * VendoLink — Session Keep-Alive & CSRF Synchronizer Endpoint
 * ============================================================
 *
 * Lightweight endpoint invoked silently in the background by app.js:
 * 1. Keeps active user sessions alive so they don't expire during presentations/work.
 * 2. Returns the current valid CSRF token so forms in background/sleeping tabs
 *    are automatically synchronized when the user returns.
 * 3. Zero database queries for optimal performance (< 2ms response).
 *
 * @package VendoLink
 * @version 1.0.0
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Boot session idempotently
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (defined('SESSION_NAME')) {
        session_name(SESSION_NAME);
    }
    @session_start();
}

// Touch session activity if authenticated
$isAuth = !empty($_SESSION['user_id']);
if ($isAuth) {
    $_SESSION['last_activity'] = time();
}

// Ensure valid CSRF token exists
$token = csrf_token();

echo json_encode([
    'success'       => true,
    'csrf_token'    => $token,
    'authenticated' => $isAuth,
    'timestamp'     => time(),
]);
exit;
