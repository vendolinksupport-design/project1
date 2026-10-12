<?php
/**
 * ============================================================
 * VendoLink — Logout Handler
 * ============================================================
 *
 * Scope: Fully destroys the active session, deletes all remember-me
 * tokens in DB, expires all cookies across all paths, and
 * immediately redirects to the login page.
 *
 * @package VendoLink
 * @version 2.1
 */

// Buffer output so no accidental whitespaces prevent header redirection
ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/User.php';

// 1. Ensure the session is started using VendoLink's session name
if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        session_name(SESSION_NAME);
    }
    @session_start();
}

// 2. Invalidate remember-me token in DB for the active user
$userId = $_SESSION['user_id'] ?? null;
if (!empty($userId)) {
    try {
        $userModel = new User();
        $userModel->clearRememberMeToken((int)$userId);
        log_activity('LOGOUT', 'User logged out.', (int)$userId);
    } catch (Exception $e) {
        // Non-fatal
    }
}

// Also invalidate any DB user whose remember_token matches current cookie
if (!empty($_COOKIE['remember_token'])) {
    try {
        $db = Database::getInstance();
        $hashedToken = hash('sha256', $_COOKIE['remember_token']);
        $db->query('UPDATE users SET remember_token = NULL WHERE remember_token = ?', [$hashedToken]);
    } catch (Exception $e) {
        // Non-fatal
    }
}

// 3. Clear all session variables
$_SESSION = [];

// 4. Destroy PHP session on server
if (session_status() === PHP_SESSION_ACTIVE) {
    @session_destroy();
}

// 5. Expire all cookies across all possible paths
$cookieNames = [SESSION_NAME, 'remember_token', 'PHPSESSID', session_name()];
$paths = ['/', '/vendolink_claude_v1', '/vendolink_claude_v1/', ''];
foreach ($cookieNames as $name) {
    foreach ($paths as $path) {
        if (!headers_sent()) {
            setcookie($name, '', time() - 86400, $path);
            setcookie($name, '', time() - 86400, $path, '', false, true);
            setcookie($name, '', time() - 86400, $path, '', true, true);
        }
    }
    unset($_COOKIE[$name]);
}

// 6. Send strict no-cache headers to prevent Back-button access
ob_end_clean();
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
    header('Location: ' . BASE_URL . '/auth/login.php?logged_out=1');
} else {
    echo '<script>window.location.href = "' . BASE_URL . '/auth/login.php?logged_out=1";</script>';
}
exit;
