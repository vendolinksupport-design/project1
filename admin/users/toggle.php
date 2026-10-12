<?php
/**
 * ============================================================
 * VendoLink — Toggle Support User Status
 * ============================================================
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../models/User.php';

require_auth();
require_role(ROLE_ADMIN);

// CSRF Protection
$token = $_POST[CSRF_TOKEN_NAME] ?? ($_GET[CSRF_TOKEN_NAME] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
$current = $_SESSION[CSRF_TOKEN_NAME] ?? '';
if (empty($token) || empty($current) || !hash_equals($current, $token)) {
    http_response_code(403);
    die('<h1 style="font-family:sans-serif">403 — Invalid CSRF Token</h1>');
}

$id     = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
$action = strtolower($_POST['action'] ?? ($_GET['action'] ?? ''));
$userModel = new User();

$targetUser = $userModel->findById($id);

if (!$targetUser) {
    flash('danger', 'User not found.');
    redirect('admin/users/index.php');
}

// Ensure Admin can only toggle Support users via this interface
if ($targetUser['role'] !== ROLE_SUPPORT) {
    flash('danger', 'You can only toggle Support accounts from this page.');
    redirect('admin/users/index.php');
}

if ($action === 'activate') {
    $userModel->setStatus($id, 'active');
    log_activity('SUPPORT_ACTIVATED', "Activated Support account: {$targetUser['username']}", current_user()['id']);
    flash('success', 'Support account activated.');
} elseif ($action === 'deactivate') {
    $userModel->setStatus($id, 'inactive');
    log_activity('SUPPORT_DEACTIVATED', "Deactivated Support account: {$targetUser['username']}", current_user()['id']);
    flash('success', 'Support account deactivated.');
} else {
    flash('warning', 'Invalid action.');
}

redirect('admin/users/index.php');
