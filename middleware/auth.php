<?php
/**
 * ============================================================
 * VendoLink — Authentication & Authorization Middleware
 * ============================================================
 *
 * Centralized server-side authentication, session security,
 * and role-based access control.
 *
 * Enforces:
 *   - Session authentication & expiration (inactivity + absolute)
 *   - Account active status verification against database
 *   - Role-based authorization (Admin, Internal, Customer, Owner)
 *   - HTTP 401 JSON for unauthenticated API requests
 *   - HTTP 403 JSON or styled page for forbidden requests
 *   - Cache-Control headers to prevent back-button caching
 *   - Periodic session ID regeneration (session fixation defense)
 *
 * @package VendoLink
 * @version 2.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

// ============================================================
// Boot Session (idempotent & hardened)
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,                                                         // expire on browser close
        'path'     => '/',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), // HTTPS only in production
        'httponly' => true,                                                      // no JS access
        'samesite' => 'Lax',
    ]);

    session_name(SESSION_NAME);
    session_start();
}

// ============================================================
// Remember-Me — auto-login from cookie
// ============================================================
if (!is_logged_in() && isset($_COOKIE['remember_token'])) {
    if (!isset($_GET['logged_out']) && basename($_SERVER['PHP_SELF'] ?? '') !== 'logout.php') {
        _try_remember_me_login($_COOKIE['remember_token']);
    }
}

// ============================================================
// Access Control Guards
// ============================================================

/**
 * Terminate or redirect an unauthorized request with appropriate HTTP status code.
 *
 * @param string      $message    User-facing message
 * @param int         $statusCode HTTP status code (401 or 403)
 * @param string|null $redirect   Optional redirection path
 */
function deny_access(string $message = 'Forbidden: Access denied.', int $statusCode = 403, ?string $redirect = null): void
{
    http_response_code($statusCode);

    // API / AJAX Requests: return structured JSON
    if (is_api_request()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }
        echo json_encode([
            'success' => false,
            'error'   => $message,
            'code'    => $statusCode,
        ]);
        exit;
    }

    // Browser Request with explicit redirect
    if ($redirect !== null) {
        flash('danger', $message);
        redirect($redirect);
    }

    // Browser Request without redirect: render dedicated 403/401 page
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }

    $homeUrl = is_admin() ? BASE_URL . '/admin/dashboard/index.php' : (is_logged_in() ? BASE_URL . '/dashboard/index.php' : BASE_URL . '/auth/login.php');
    $homeLabel = is_logged_in() ? 'Back to Dashboard' : 'Go to Login';

    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $statusCode === 401 ? '401 Unauthorized' : '403 Forbidden' ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background: #0b1329;
            color: #f1f5f9;
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 1.5rem;
        }
        .error-card {
            background: #111e38;
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 16px;
            padding: 2.5rem 2rem;
            max-width: 480px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
        }
        .error-icon {
            width: 72px;
            height: 72px;
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2.2rem;
            margin-bottom: 1.5rem;
        }
        .btn-action {
            background: #06b6d4;
            color: #0b1329;
            font-weight: 600;
            border: none;
            padding: 0.65rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
        }
        .btn-action:hover {
            background: #22d3ee;
            color: #0b1329;
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-icon">
            <i class="bi bi-shield-x"></i>
        </div>
        <h2 class="h4 fw-bold mb-2"><?= $statusCode === 401 ? '401 — Unauthorized' : '403 — Access Forbidden' ?></h2>
        <p class="text-secondary mb-4" style="font-size: 0.95rem;">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </p>
        <div>
            <a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-action">
                <i class="bi bi-arrow-left"></i> <?= htmlspecialchars($homeLabel, ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>
    </div>
</body>
</html>
    <?php
    exit;
}

/**
 * Require the user to be authenticated with a valid active session.
 *
 * @param string|null $redirectTo Path to redirect after login (optional)
 */
function require_auth(?string $redirectTo = null): void
{
    // Prevent browser back-button caching on all authenticated pages
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
        header('Pragma: no-cache');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
    }

    // 1. Session check (existence and inactivity / absolute timeouts)
    if (!is_logged_in()) {
        destroy_session();

        if (is_api_request()) {
            deny_access('Unauthorized: Please log in to access this resource.', 401);
        }

        if ($redirectTo) {
            $_SESSION['intended_url'] = $redirectTo;
        } else {
            $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'] ?? '';
        }

        flash('warning', 'Please log in to access that page.');
        redirect('auth/login.php');
    }

    // 2. Active Account Database Verification on Every Request
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $user = _verify_user_account($userId);

    if (!$user || $user['status'] !== 'active') {
        destroy_session();

        if (is_api_request()) {
            deny_access('Unauthorized: Your account is inactive or disabled.', 401);
        }

        flash('danger', 'Your account has been deactivated or disabled. Please contact support.');
        redirect('auth/login.php');
    }

    // 3. Keep session in sync with database row
    $_SESSION['user_role']     = $user['role'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['email']         = $user['email'];
    $_SESSION['full_name']     = $user['full_name'];
    $_SESSION['last_activity'] = time();

    // 4. Regenerate session ID periodically to prevent fixation
    _maybe_regenerate_session();
}

/**
 * Require the user to have a specific role (or one of several roles).
 *
 * @param string|array $roles    Required role(s)
 * @param string|null  $redirect Optional path to redirect if role check fails
 */
function require_role(string|array $roles, ?string $redirect = null): void
{
    require_auth();

    if (!has_role($roles)) {
        deny_access('Access Denied: You do not have permission to access that resource.', 403, $redirect);
    }
}

/**
 * Require VendoLink Admin privileges.
 *
 * @param string|null $redirect Optional path to redirect
 */
function require_admin(?string $redirect = null): void
{
    require_auth();

    if (!is_admin()) {
        deny_access('Access Denied: VendoLink Admin privileges required.', 403, $redirect);
    }
}

/**
 * Require VendoLink Internal user privileges (Admin or Support).
 *
 * @param string|null $redirect Optional path to redirect
 */
function require_internal(?string $redirect = null): void
{
    require_auth();

    if (!is_internal_user()) {
        deny_access('Access Denied: Internal VendoLink credentials required.', 403, $redirect);
    }
}

/**
 * Require Customer privileges (Owner or Staff).
 *
 * @param string|null $redirect Optional path to redirect
 */
function require_customer(?string $redirect = null): void
{
    require_auth();

    if (!is_customer_user()) {
        deny_access('Access Denied: Customer credentials required.', 403, $redirect);
    }
}

/**
 * Populate session variables from a user DB row.
 * Called after successful login.
 *
 * @param array $user Row from users table
 */
function populate_session(array $user): void
{
    // Regenerate session ID on login to prevent session fixation
    if (!headers_sent()) {
        session_regenerate_id(true);
    }

    $now = time();
    $_SESSION['user_id']          = (int)$user['id'];
    $_SESSION['username']         = $user['username'];
    $_SESSION['email']            = $user['email'];
    $_SESSION['full_name']        = $user['full_name'];
    $_SESSION['user_role']        = $user['role'];
    $_SESSION['role']             = $user['role'];
    $_SESSION['created_at']       = $now;
    $_SESSION['last_activity']    = $now;
    $_SESSION['last_regenerated'] = $now;

    // Store authenticated owner_id in session for multi-tenant data isolation
    if (in_array($user['role'], [ROLE_OWNER, 'owner'], true)) {
        $_SESSION['owner_id'] = (int)$user['id'];
    } elseif (in_array($user['role'], [ROLE_STAFF, 'staff'], true)) {
        $_SESSION['owner_id'] = (int)($user['created_by'] ?? $user['owner_id'] ?? $user['id']);
    } else {
        // Internal Admin / Support accounts have null owner_id (unrestricted access across all machines)
        $_SESSION['owner_id'] = null;
    }
}

/**
 * Destroy the session and all related cookies cleanly.
 */
function destroy_session(): void
{
    // Ensure session is active before destroying
    if (session_status() === PHP_SESSION_NONE) {
        if (!headers_sent()) {
            session_name(SESSION_NAME);
        }
        @session_start();
    }

    // 1. Wipe all session data
    $_SESSION = [];

    // 2. Delete session cookie
    if (!headers_sent() && ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            $params['secure'] ?? false,
            $params['httponly'] ?? true
        );
    }

    // 3. Clear remember-me cookie
    if (isset($_COOKIE['remember_token'])) {
        if (!headers_sent()) {
            setcookie('remember_token', '', time() - 42000, '/', '', isset($_SERVER['HTTPS']), true);
        }
        unset($_COOKIE['remember_token']);
    }

    // 4. Destroy the session storage
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_destroy();
    }
}

// ============================================================
// Private Helpers
// ============================================================

/**
 * Verify account existence and active status in the database.
 *
 * @param int $userId
 * @return array|null
 */
function _verify_user_account(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    try {
        $db = Database::getInstance();
        return $db->query(
            'SELECT id, username, email, full_name, role, status FROM users WHERE id = ? LIMIT 1',
            [$userId]
        )->fetch() ?: null;
    } catch (Throwable $e) {
        error_log('[VendoLink][Middleware] User verification query error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Attempt to authenticate from the remember-me cookie token.
 *
 * @param string $cookieToken Raw token from cookie
 */
function _try_remember_me_login(string $cookieToken): void
{
    try {
        $db = Database::getInstance();
        $users = $db->query(
            'SELECT * FROM users WHERE status = ? AND remember_token IS NOT NULL',
            ['active']
        )->fetchAll();

        foreach ($users as $user) {
            if (hash_equals($user['remember_token'], hash('sha256', $cookieToken))) {
                populate_session($user);
                log_activity('AUTO_LOGIN', 'Logged in via remember-me cookie.', (int)$user['id']);
                break;
            }
        }
    } catch (PDOException $e) {
        error_log('[VendoLink][Middleware] Remember-me failed: ' . $e->getMessage());
    }
}

/**
 * Regenerate session ID periodically to mitigate session hijacking.
 */
function _maybe_regenerate_session(): void
{
    $interval = defined('SESSION_REGEN_INTERVAL') ? SESSION_REGEN_INTERVAL : 900;
    $lastRegen = $_SESSION['last_regenerated'] ?? $_SESSION['last_activity'] ?? 0;

    if ((time() - $lastRegen) > $interval) {
        if (!headers_sent()) {
            session_regenerate_id(true);
        }
        $_SESSION['last_regenerated'] = time();
    }
}
