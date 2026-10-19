<?php
/**
 * ============================================================
 * VendoLink — Localhost Developer Instant Login Bypass
 * ============================================================
 *
 * STRICTLY restricted to local development / localhost.
 * Allows 1-click testing bypass into Owner or Admin accounts
 * without manual password entry during local development.
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

// Strict security check: Localhost ONLY
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
$httpHost   = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = (
    in_array($remoteAddr, ['127.0.0.1', '::1', 'localhost'], true) ||
    strpos($httpHost, 'localhost') !== false ||
    strpos($httpHost, '127.0.0.1') !== false ||
    (defined('APP_ENV') && APP_ENV === 'development')
);

// Block if on production
if (!$isLocal || (defined('APP_ENV') && APP_ENV === 'production')) {
    http_response_code(403);
    die('Forbidden: Dev login is strictly restricted to local development environments.');
}

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

$role = strtolower(trim($_GET['as'] ?? 'owner'));
$db = Database::getInstance()->getConnection();

if ($role === 'admin' || $role === 'administrator') {
    $stmt = $db->prepare("SELECT * FROM users WHERE role = 'administrator' AND status = 'active' ORDER BY id ASC LIMIT 1");
} else {
    $stmt = $db->prepare("SELECT * FROM users WHERE role = 'owner' AND status = 'active' ORDER BY id ASC LIMIT 1");
}

$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("No active {$role} user found in database. Run database seed first.");
}

// Log in via session
populate_session($user);
log_activity('DEV_AUTO_LOGIN', "Local developer instant login as {$user['username']} ({$user['role']}).", (int)$user['id']);

flash('success', "Logged in automatically as <strong>" . htmlspecialchars($user['username']) . "</strong> (" . ucfirst($user['role']) . ").");

if ($user['role'] === 'administrator') {
    redirect('admin/dashboard/index.php');
} else {
    redirect('dashboard/index.php');
}
