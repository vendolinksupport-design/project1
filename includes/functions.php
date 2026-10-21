<?php
/**
 * ============================================================
 * VendoLink — Global Helper Functions
 * ============================================================
 *
 * Provides: CSRF protection, flash messages, redirect,
 * input sanitization, output escaping, activity logging,
 * role checks, date formatting, and pagination utilities.
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/terminology.php';
require_once __DIR__ . '/Mailer.php';

// ============================================================
// CSRF Protection
// ============================================================

/**
 * Return current CSRF token from session, or generate one if missing.
 *
 * @return string
 */
function csrf_token(): string
{
    return csrf_generate();
}

/**
 * Generate (or reuse) a CSRF token and store it in the session.
 *
 * @return string The CSRF token
 */
function csrf_generate(): string
{
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Regenerate the CSRF token (e.g. after login, logout, or credential change).
 * Keeps the previous token valid for a 60-second grace window to prevent race
 * conditions with in-flight or rapid concurrent requests.
 *
 * @return string The new CSRF token
 */
function csrf_regenerate(): string
{
    if (!empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME . '_prev'] = [
            'token'      => $_SESSION[CSRF_TOKEN_NAME],
            'expires_at' => time() + 60, // 60-second grace window
        ];
    }
    $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Render a hidden CSRF input field — include inside every form.
 *
 * @return string HTML hidden input
 */
function csrf_field(): string
{
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . htmlspecialchars(csrf_generate()) . '">';
}

/**
 * Validate the CSRF token from POST data or HTTP_X_CSRF_TOKEN header.
 * Checks active session token first, and if not matched, checks previous token within grace window.
 * Terminates with 403 if invalid or missing.
 */
function csrf_verify(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        if (!headers_sent() && defined('SESSION_NAME')) {
            session_name(SESSION_NAME);
        }
        @session_start();
    }

    $token = $_POST[CSRF_TOKEN_NAME] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    $current = $_SESSION[CSRF_TOKEN_NAME] ?? '';

    // 1. Check against active session token
    if (!empty($current) && !empty($token) && hash_equals($current, $token)) {
        return; // Valid token
    }

    // 2. Check against previous token within grace window (handles in-flight race conditions)
    if (!empty($_SESSION[CSRF_TOKEN_NAME . '_prev'])) {
        $prev = $_SESSION[CSRF_TOKEN_NAME . '_prev'];
        if (
            !empty($prev['token']) &&
            ($prev['expires_at'] ?? 0) >= time() &&
            !empty($token) &&
            hash_equals($prev['token'], $token)
        ) {
            return; // Valid previous token within grace window
        }
    }

    // 3. Reject invalid or missing token
    http_response_code(403);

    // Auto-generate fresh token so subsequent attempts succeed
    csrf_regenerate();

    // AJAX / API call -> return structured JSON
    if (function_exists('is_api_request') && is_api_request()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }
        echo json_encode([
            'success'      => false,
            'error'        => 'Session security token expired or invalid. Please refresh the page and try again.',
            'code'         => 403,
            'csrf_expired' => true,
        ]);
        exit;
    }

    // Browser POST from internal page -> redirect seamlessly with fresh token and friendly alert
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!empty($referer) && !empty($host) && parse_url($referer, PHP_URL_HOST) === $host && !headers_sent()) {
        if (function_exists('flash')) {
            flash('warning', 'Your security session was refreshed. Please submit your form again.');
        }
        header('Location: ' . $referer);
        exit;
    }

    // Direct POST without referer or CLI runner -> render styled VendoLink 403 card with retry action
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 — Invalid CSRF Token</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background-color: #0b1329;
            color: #f1f5f9;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 1.5rem;
        }
        .error-card {
            background: #111e38;
            border: 1px solid #1e3a5f;
            border-radius: 1rem;
            padding: 2.5rem;
            max-width: 480px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
        }
        .error-icon {
            font-size: 3rem;
            color: #f59e0b;
            margin-bottom: 1rem;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #0ea5e9;
            color: #ffffff;
            font-weight: 600;
            padding: 0.65rem 1.4rem;
            border-radius: 0.5rem;
            text-decoration: none;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
        }
        .btn-action:hover {
            background: #38bdf8;
            color: #ffffff;
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-icon">
            <i class="bi bi-shield-lock"></i>
        </div>
        <h1 style="font-family:sans-serif" class="h4 fw-bold mb-2">403 — Invalid CSRF Token</h1>
        <p class="text-secondary mb-4" style="font-size: 0.95rem; color: #94a3b8 !important;">
            Your security token expired due to inactivity or the form submission could not be verified. A new security token has been generated.
        </p>
        <div>
            <button onclick="window.history.length > 1 ? window.history.back() : window.location.reload();" class="btn-action">
                <i class="bi bi-arrow-clockwise"></i> Return &amp; Try Again
            </button>
        </div>
    </div>
</body>
</html>
    <?php
    exit;
}

// ============================================================
// Flash Messages
// ============================================================

/**
 * Store a flash message in the session (shown once on next page).
 *
 * @param string $type    Bootstrap alert type: success, danger, warning, info
 * @param string $message Message text (will be HTML-escaped on output)
 */
function flash(string $type, string $message, bool $raw = false): void
{
    $_SESSION['flash_messages'][] = [
        'type'    => $type,
        'message' => $message,
        'raw'     => $raw,
    ];
}

/**
 * Retrieve and clear all flash messages.
 *
 * @return array List of ['type' => string, 'message' => string]
 */
function get_flash_messages(): array
{
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return $messages;
}

/**
 * Render all queued flash messages as Bootstrap alerts.
 *
 * @return string HTML string of alerts
 */
function render_flash(): string
{
    $messages = get_flash_messages();
    if (empty($messages)) {
        return '';
    }

    $html = '';
    foreach ($messages as $msg) {
        $type    = htmlspecialchars($msg['type']);
        $message = !empty($msg['raw']) ? $msg['message'] : htmlspecialchars($msg['message']);
        $html   .= <<<HTML
<div class="alert alert-{$type} alert-dismissible fade show" role="alert">
    {$message}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
HTML;
    }
    return $html;
}

// ============================================================
// Redirect
// ============================================================

/**
 * Redirect to an internal URL and exit.
 *
 * @param string $path  Path relative to BASE_URL (e.g. '/auth/login.php')
 */
function redirect(string $path): void
{
    $url = BASE_URL . '/' . ltrim($path, '/');
    header('Location: ' . $url);
    exit;
}

/**
 * Redirect to an absolute URL and exit.
 *
 * @param string $url Absolute URL
 */
function redirect_url(string $url): void
{
    header('Location: ' . $url);
    exit;
}

// ============================================================
// Input Sanitization & Output Escaping
// ============================================================

/**
 * Sanitize a string input (trim + strip tags).
 *
 * @param string $input Raw user input
 * @return string Sanitized string
 */
function sanitize(string $input): string
{
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

/**
 * Escape a value for safe HTML output.
 *
 * @param mixed $value Value to escape
 * @return string HTML-safe string
 */
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Validate and return a sanitized email, or null if invalid.
 *
 * @param string $email Raw email input
 * @return string|null
 */
function validate_email(string $email): ?string
{
    $email = trim($email);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

// ============================================================
// Activity Logging
// ============================================================

/**
 * Write an entry to the activity_logs table.
 *
 * @param string      $action      Short action code (e.g. 'LOGIN', 'LOGOUT')
 * @param string|null $description Optional longer description
 * @param int|null    $userId      User ID (null for unauthenticated actions)
 */
function log_activity(string $action, ?string $description = null, ?int $userId = null): void
{
    try {
        $db = Database::getInstance();
        $db->query(
            'INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent)
             VALUES (:uid, :action, :desc, :ip, :ua)',
            [
                ':uid'    => $userId ?? ($_SESSION['user_id'] ?? null),
                ':action' => strtoupper($action),
                ':desc'   => $description,
                ':ip'     => $_SERVER['REMOTE_ADDR']     ?? null,
                ':ua'     => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]
        );
    } catch (PDOException $e) {
        // Non-fatal — log to file and continue
        error_log('[VendoLink][ActivityLog] ' . $e->getMessage());
    }
}

// ============================================================
// Session & Authentication Helpers
// ============================================================

/**
 * Detect whether the current request is an API, AJAX, or JSON request.
 *
 * @return bool
 */
function is_api_request(): bool
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (str_contains($uri, '/api/')) {
        return true;
    }

    $xRequestedWith = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    if ($xRequestedWith === 'xmlhttprequest') {
        return true;
    }

    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    if (str_contains($accept, 'application/json')) {
        return true;
    }

    $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_contains($contentType, 'application/json')) {
        return true;
    }

    return false;
}

/**
 * Check if a user is currently logged in and session is valid.
 *
 * @return bool
 */
function is_logged_in(): bool
{
    if (empty($_SESSION['user_id']) || (empty($_SESSION['user_role']) && empty($_SESSION['role']))) {
        return false;
    }

    // Check inactivity timeout (30 min default)
    if (defined('SESSION_INACTIVITY_TIMEOUT') && !empty($_SESSION['last_activity'])) {
        if ((time() - (int)$_SESSION['last_activity']) > SESSION_INACTIVITY_TIMEOUT) {
            return false;
        }
    }

    // Check absolute session lifetime (12h default)
    if (defined('SESSION_ABSOLUTE_TIMEOUT') && !empty($_SESSION['created_at'])) {
        if ((time() - (int)$_SESSION['created_at']) > SESSION_ABSOLUTE_TIMEOUT) {
            return false;
        }
    }

    return true;
}

/**
 * Return the currently logged-in user's data from session.
 *
 * @return array|null
 */
function current_user(): ?array
{
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id'        => $_SESSION['user_id'],
        'username'  => $_SESSION['username']  ?? '',
        'email'     => $_SESSION['email']     ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'role'      => $_SESSION['user_role'] ?? $_SESSION['role'] ?? '',
    ];
}

/**
 * Check if the current user has a specific role.
 *
 * @param string|array $roles A single role string or array of roles
 * @return bool
 */
function has_role(string|array $roles): bool
{
    if (!is_logged_in()) {
        return false;
    }
    $roles = (array)$roles;
    $role  = $_SESSION['user_role'] ?? $_SESSION['role'] ?? '';
    return in_array($role, $roles, true);
}

/**
 * Return true only if the current user is an administrator.
 *
 * @return bool
 */
function is_admin(): bool
{
    return has_role([ROLE_ADMIN, 'admin']);
}

/**
 * Return true if the current user is a VendoLink Support account.
 */
function is_support(): bool
{
    return has_role([ROLE_SUPPORT, 'support']);
}

/**
 * Return true if the current user is an Internal VendoLink user (Admin or Support).
 */
function is_internal_user(): bool
{
    return has_role(array_merge(INTERNAL_ROLES, ['admin', 'support']));
}

/**
 * Return true if the current user is a Customer user (Owner or Staff).
 */
function is_customer_user(): bool
{
    return has_role(array_merge(CUSTOMER_ROLES, ['owner', 'staff']));
}

/**
 * Return true if the current user is a Customer Owner.
 */
function is_owner(): bool
{
    return has_role([ROLE_OWNER, 'owner']);
}

/**
 * Return true if the current user is a Customer Staff.
 */
function is_staff(): bool
{
    return has_role([ROLE_STAFF, 'staff']);
}

/**
 * Return the authenticated owner ID for multi-tenant data scoping.
 * Returns null for administrators and internal accounts (unrestricted).
 *
 * @return int|null
 */
function current_owner_id(): ?int
{
    if (!is_logged_in() || is_internal_user()) {
        return null;
    }
    return !empty($_SESSION['owner_id']) ? (int)$_SESSION['owner_id'] : (int)($_SESSION['user_id'] ?? 0);
}

/**
 * Return the currently selected machine ID for the active owner context.
 * Validates ownership against the database to prevent cross-tenant exposure.
 *
 * @param int|null $ownerId Optional owner ID (defaults to current_owner_id())
 * @return int|null
 */
function current_selected_machine_id(?int $ownerId = null): ?int
{
    $effectiveOwnerId = $ownerId !== null ? $ownerId : current_owner_id();
    if ($effectiveOwnerId === null) {
        return null;
    }

    // 1. Check for manual switch request via query parameter or post
    $requestedId = null;
    if (isset($_GET['switch_machine']) && is_numeric($_GET['switch_machine'])) {
        $requestedId = (int)$_GET['switch_machine'];
    } elseif (isset($_POST['switch_machine']) && is_numeric($_POST['switch_machine'])) {
        $requestedId = (int)$_POST['switch_machine'];
    }

    require_once ROOT_PATH . '/models/Machine.php';
    $machineModel = new Machine();
    $allowedIds   = $machineModel->getMachineIdsForOwner($effectiveOwnerId);

    if (empty($allowedIds)) {
        unset($_SESSION['selected_machine_id']);
        return null;
    }

    // If an explicit switch was requested and is authorized:
    if ($requestedId !== null && in_array($requestedId, $allowedIds, true)) {
        $_SESSION['selected_machine_id'] = $requestedId;
        return $requestedId;
    }

    // Check stored session selection
    $sessionMachineId = (int)($_SESSION['selected_machine_id'] ?? 0);
    if ($sessionMachineId > 0 && in_array($sessionMachineId, $allowedIds, true)) {
        return $sessionMachineId;
    }

    // Fall back to primary or first available machine
    $primary = $machineModel->primaryWithServices($effectiveOwnerId);
    $defaultId = !empty($primary['id']) && in_array((int)$primary['id'], $allowedIds, true)
        ? (int)$primary['id']
        : $allowedIds[0];

    $_SESSION['selected_machine_id'] = $defaultId;
    return $defaultId;
}

/**
 * Return the full record (with services & calculated status) of the currently selected machine.
 *
 * @param int|null $ownerId Optional owner ID
 * @return array|null
 */
function current_selected_machine(?int $ownerId = null): ?array
{
    $effectiveOwnerId = $ownerId !== null ? $ownerId : current_owner_id();
    $selectedId = current_selected_machine_id($effectiveOwnerId);

    if (!$selectedId) {
        return null;
    }

    require_once ROOT_PATH . '/models/Machine.php';
    $machineModel = new Machine();
    $machine = $machineModel->getByIdWithServices($selectedId, $effectiveOwnerId);

    if (!$machine) {
        // Fallback to primary if invalid
        $machine = $machineModel->primaryWithServices($effectiveOwnerId);
        if ($machine) {
            $_SESSION['selected_machine_id'] = (int)$machine['id'];
        }
    }

    return $machine;
}

// ============================================================
// Date & Time Formatting
// ============================================================

/**
 * Format a datetime string or timestamp for display.
 *
 * @param string|int|null $datetime  Raw datetime string, ISO 8601 string, or Unix timestamp from DB
 * @param string          $format    PHP date format string (default: 'M d, Y, g:i A')
 * @return string
 */
function format_date(string|int|null $datetime, string $format = 'M d, Y, g:i A'): string
{
    if ($datetime === null || $datetime === '' || $datetime === '0000-00-00 00:00:00' || $datetime === '0000-00-00') {
        return '—';
    }
    try {
        if (is_numeric($datetime)) {
            $dt = (new DateTime())->setTimestamp((int)$datetime);
        } else {
            $dt = new DateTime((string)$datetime);
        }
        return $dt->format($format);
    } catch (Throwable) {
        return (string)$datetime;
    }
}

/**
 * Return a human-readable "time ago" string.
 *
 * @param string $datetime DB datetime string
 * @return string e.g. "2 hours ago"
 */
function time_ago(string $datetime): string
{
    try {
        $now  = new DateTime();
        $past = new DateTime($datetime);
        $diff = $now->diff($past);

        if ($diff->y > 0)  return $diff->y . ' year'  . ($diff->y > 1 ? 's' : '') . ' ago';
        if ($diff->m > 0)  return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
        if ($diff->d > 0)  return $diff->d . ' day'   . ($diff->d > 1 ? 's' : '') . ' ago';
        if ($diff->h > 0)  return $diff->h . ' hour'  . ($diff->h > 1 ? 's' : '') . ' ago';
        if ($diff->i > 0)  return $diff->i . ' min'   . ($diff->i > 1 ? 's' : '') . ' ago';
        return 'Just now';
    } catch (Exception) {
        return $datetime;
    }
}

// ============================================================
// String Utilities
// ============================================================

/**
 * Truncate a string to a maximum length with an ellipsis.
 *
 * @param string $text  Input string
 * @param int    $limit Max character count
 * @param string $end   Appended suffix
 * @return string
 */
function truncate(string $text, int $limit = 80, string $end = '...'): string
{
    if (mb_strlen($text) <= $limit) {
        return $text;
    }
    return mb_substr($text, 0, $limit) . $end;
}

/**
 * Format a number as Philippine Peso currency.
 *
 * @param float $amount
 * @return string e.g. "₱ 1,250.00"
 */
function format_currency(float $amount): string
{
    return '₱ ' . number_format($amount, 2);
}

/**
 * Return a Bootstrap badge class for a user role.
 *
 * @param string $role
 * @return string
 */
function role_badge(string $role): string
{
    return match ($role) {
        ROLE_ADMIN      => 'danger',
        ROLE_OWNER      => 'warning',
        ROLE_TECHNICIAN => 'info',
        default         => 'secondary',
    };
}

/**
 * Return a Bootstrap badge class for a user status.
 *
 * @param string $status
 * @return string
 */
function status_badge(string $status): string
{
    return match ($status) {
        'active'   => 'success',
        'inactive' => 'secondary',
        'banned'   => 'danger',
        default    => 'secondary',
    };
}

// ============================================================
// Token Generation
// ============================================================

/**
 * Generate a cryptographically secure random token.
 *
 * @param int $length Number of bytes (hex output is 2x this length)
 * @return string Hex token string
 */
function generate_token(int $length = 32): string
{
    return bin2hex(random_bytes($length));
}

/**
 * Mask an email address for privacy display (e.g. p***@gmail.com).
 *
 * @param string $email
 * @return string Masked email
 */
function mask_email(string $email): string
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $email;
    }
    $parts  = explode('@', $email, 2);
    $user   = $parts[0];
    $domain = $parts[1];

    $len = strlen($user);
    if ($len <= 2) {
        $maskedUser = substr($user, 0, 1) . '*';
    } else {
        $maskedUser = substr($user, 0, 1) . str_repeat('*', min(3, $len - 2)) . substr($user, -1);
    }

    return $maskedUser . '@' . $domain;
}

// ============================================================
// System Email / SMTP Notification Delivery
// ============================================================

/**
 * Send an email notification using configured SMTP credentials.
 *
 * @param string      $to       Recipient email address
 * @param string      $subject  Email subject line
 * @param string      $htmlBody HTML body content
 * @param string|null $from     Sender email (optional, defaults to SMTP_FROM_EMAIL)
 * @param string|null $fromName Sender display name (optional, defaults to SMTP_FROM_NAME)
 * @return bool True if delivered, false on failure
 */
function send_system_email(string $to, string $subject, string $htmlBody, ?string $from = null, ?string $fromName = null): bool
{
    return Mailer::send($to, $subject, $htmlBody, null, $from, $fromName);
}

// ============================================================
// Lucide Icons & Safe Excel Export Helpers
// ============================================================

/**
 * Return Lucide SVG icon markup.
 *
 * @param string $name  Icon name (e.g. 'layers', 'search', 'filter', 'trending-up', 'wallet', 'dollar-sign')
 * @param string $class Additional CSS classes
 * @param array  $attrs Additional HTML attributes (width, height, style, etc.)
 * @return string Safe SVG HTML
 */
function lucide_icon(string $name, string $class = '', array $attrs = []): string
{
    $rawName = strtolower(trim($name));
    // Strip leading bi- or bi prefixes if passed
    $cleanName = preg_replace('/^bi[-_ ]+/', '', $rawName);

    // Map common aliases to canonical Lucide names
    static $aliasMap = [
        'speedometer'                => 'gauge',
        'speedometer2'               => 'gauge',
        'dashboard'                  => 'gauge',
        'hdd-stack'                  => 'server',
        'hdd'                        => 'server',
        'hdd-network'                => 'server',
        'hdd-network-fill'           => 'server',
        'bell-fill'                  => 'bell',
        'bell-ring'                  => 'bell-ring',
        'bell-ring-fill'             => 'bell-ring',
        'wrench-adjustable'          => 'wrench',
        'wrench-adjustable-circle'   => 'wrench',
        'people'                     => 'users',
        'people-fill'                => 'users',
        'person-badge'               => 'users',
        'person-badge-fill'          => 'users',
        'gear'                       => 'settings',
        'gear-fill'                  => 'settings',
        'gear-wide'                  => 'settings',
        'gear-wide-connected'        => 'settings',
        'cog'                        => 'settings',
        'cogs'                       => 'settings',
        'box-arrow-right'            => 'log-out',
        'box-arrow-left'             => 'log-in',
        'sign-out'                   => 'log-out',
        'logout'                     => 'log-out',
        'arrow-clockwise'            => 'rotate-cw',
        'arrow-counterclockwise'     => 'rotate-ccw',
        'arrow-repeat'               => 'refresh-cw',
        'check2-all'                 => 'check-check',
        'check-all'                  => 'check-check',
        'check2-circle'              => 'check-circle',
        'check-circle-fill'          => 'check-circle',
        'circle-fill'                => 'circle',
        'droplet-fill'               => 'droplet',
        'droplets-fill'              => 'droplets',
        'stars'                      => 'sparkles',
        'sparkles-fill'              => 'sparkles',
        'clock-history'              => 'clock',
        'clock-fill'                 => 'clock',
        'cash-coin'                  => 'dollar-sign',
        'currency-dollar'            => 'dollar-sign',
        'peso'                       => 'peso',
        'peso-sign'                  => 'peso',
        'philippine-peso'            => 'peso',
        'currency-peso'              => 'peso',
        'shield-lock'                => 'shield-check',
        'shield-lock-fill'           => 'shield-check',
        'key-fill'                   => 'key',
        'person'                     => 'user',
        'person-fill'                => 'user',
        'person-circle'              => 'user',
        'graph-up'                   => 'trending-up',
        'graph-up-arrow'             => 'trending-up',
        'robot'                      => 'bot',
        'cpu-fill'                   => 'cpu',
        'x-lg'                       => 'x',
        'exclamation-triangle'       => 'alert-triangle',
        'exclamation-triangle-fill'  => 'alert-triangle',
        'info-circle'                => 'info',
        'info-circle-fill'           => 'info',
        'question-circle'            => 'help-circle',
        'send-check'                 => 'send',
        'send-check-fill'            => 'send',
        'sliders2'                   => 'sliders',
        'dash-lg'                    => 'minus',
        'plus-lg'                    => 'plus',
    ];

    $resolvedName = $aliasMap[$cleanName] ?? $aliasMap[$rawName] ?? $cleanName;

    $width = $attrs['width'] ?? '18';
    $height = $attrs['height'] ?? '18';
    $strokeWidth = $attrs['stroke-width'] ?? '2';

    $attrPairs = [];
    foreach ($attrs as $k => $v) {
        if (!in_array($k, ['width', 'height', 'stroke-width'], true)) {
            $attrPairs[] = htmlspecialchars($k) . '="' . htmlspecialchars((string)$v) . '"';
        }
    }
    $extraAttrs = !empty($attrPairs) ? ' ' . implode(' ', $attrPairs) : '';

    static $svgPaths = [
        'layers' => '<polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline>',
        'search' => '<circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>',
        'filter' => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>',
        'trending-up' => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline>',
        'trending-down' => '<polyline points="23 18 13.5 8.5 8.5 13.5 1 6"></polyline><polyline points="17 18 23 18 23 12"></polyline>',
        'dollar-sign' => '<line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>',
        'peso' => '<path d="M7 3v18"></path><path d="M7 3.5h5.5a4.5 4.5 0 0 1 0 9H7"></path><path d="M4 7h14"></path><path d="M4 10.5h14"></path>',
        'wallet' => '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"></path><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"></path><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"></path>',
        'history' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path><path d="M12 7v5l4 2"></path>',
        'gauge' => '<path d="m12 14 4-4"></path><path d="M3.34 19a10 10 0 1 1 17.32 0"></path>',
        'layout-dashboard' => '<rect width="7" height="9" x="3" y="3" rx="1"></rect><rect width="7" height="5" x="14" y="3" rx="1"></rect><rect width="7" height="9" x="14" y="12" rx="1"></rect><rect width="7" height="5" x="3" y="16" rx="1"></rect>',
        'server' => '<rect width="20" height="8" x="2" y="2" rx="2" ry="2"></rect><rect width="20" height="8" x="2" y="14" rx="2" ry="2"></rect><line x1="6" x2="6.01" y1="6" y2="6"></line><line x1="6" x2="6.01" y1="18" y2="18"></line>',
        'hard-drive' => '<line x1="22" x2="2" y1="12" y2="12"></line><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path><line x1="6" x2="6.01" y1="16" y2="16"></line><line x1="10" x2="10.01" y1="16" y2="16"></line>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"></path><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"></path>',
        'bell-ring' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"></path><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"></path><path d="M4 2C2.8 3.7 2 5.7 2 8"></path><path d="M22 8c0-2.3-.8-4.3-2-6"></path>',
        'bell-off' => '<path d="M8.7 3A6 6 0 0 1 18 8a21.3 21.3 0 0 0 .6 5"></path><path d="M17 17H3s3-2 3-9a4.67 4.67 0 0 1 .3-1.7"></path><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"></path><line x1="1" x2="23" y1="1" y2="23"></line>',
        'wrench' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
        'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>',
        'user-check' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><polyline points="16 11 18 13 22 9"></polyline>',
        'settings' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"></path><circle cx="12" cy="12" r="3"></circle>',
        'log-out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" x2="9" y1="12" y2="12"></line>',
        'log-in' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" x2="3" y1="12" y2="12"></line>',
        'rotate-cw' => '<path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"></path><path d="M21 3v5h-5"></path>',
        'rotate-ccw' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path>',
        'refresh-cw' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"></path><path d="M21 3v5h-5"></path><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"></path><path d="M8 16H3v5"></path>',
        'chevron-down' => '<polyline points="6 9 12 15 18 9"></polyline>',
        'chevron-up' => '<polyline points="18 15 12 9 6 15"></polyline>',
        'chevron-right' => '<polyline points="9 18 15 12 9 6"></polyline>',
        'chevron-left' => '<polyline points="15 18 9 12 15 6"></polyline>',
        'file-spreadsheet' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"></path><path d="M14 2v4a2 2 0 0 0 2 2h4"></path><path d="M8 13h2"></path><path d="M14 13h2"></path><path d="M8 17h2"></path><path d="M14 17h2"></path>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line>',
        'plus' => '<line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line>',
        'plus-circle' => '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line>',
        'minus' => '<line x1="5" y1="12" x2="19" y2="12"></line>',
        'minus-circle' => '<circle cx="12" cy="12" r="10"></circle><line x1="8" y1="12" x2="16" y2="12"></line>',
        'arrow-right' => '<line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline>',
        'arrow-right-circle' => '<circle cx="12" cy="12" r="10"></circle><polyline points="12 16 16 12 12 8"></polyline><line x1="8" y1="12" x2="16" y2="12"></line>',
        'arrow-up-right' => '<line x1="7" y1="17" x2="17" y2="7"></line><polyline points="7 7 17 7 17 17"></polyline>',
        'arrow-down-right' => '<line x1="7" y1="7" x2="17" y2="17"></line><polyline points="17 7 17 17 7 17"></polyline>',
        'check' => '<polyline points="20 6 9 17 4 12"></polyline>',
        'check-circle' => '<circle cx="12" cy="12" r="10"></circle><polyline points="9 12 12 15 16 10"></polyline>',
        'check-circle-2' => '<circle cx="12" cy="12" r="10"></circle><path d="m9 12 2 2 4-4"></path>',
        'check-check' => '<path d="M18 6 7 17l-5-5"></path><path d="m22 10-7.5 7.5L13 16"></path>',
        'circle' => '<circle cx="12" cy="12" r="10"></circle>',
        'calendar' => '<rect width="18" height="18" x="3" y="4" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y1="10"></line>',
        'droplet' => '<path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"></path>',
        'droplets' => '<path d="M7 16.3c2.2 0 4-1.83 4-4.05 0-1.16-.57-2.26-1.71-3.19S7.29 6.75 7 5.3c-.29 1.45-1.14 2.84-2.29 3.76S3 11.1 3 12.25c0 2.22 1.8 4.05 4 4.05z"></path><path d="M12.56 6.6A10.97 10.97 0 0 0 14 3.02c.5 2.5 2 4.9 4 6.5s3 3.5 3 5.5a6.98 6.98 0 0 1-11.91 4.97"></path>',
        'sparkles' => '<path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"></path>',
        'sliders' => '<line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line>',
        'receipt' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"></path><path d="M16 8H8"></path><path d="M16 12H8"></path><path d="M13 16H8"></path>',
        'clock' => '<circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline>',
        'x' => '<line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line>',
        'x-circle' => '<circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line>',
        'info' => '<circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4"></path><path d="M12 8h.01"></path>',
        'help-circle' => '<circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" x2="12.01" y1="17" y2="17"></line>',
        'alert-triangle' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line>',
        'shield-check' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><path d="m9 12 2 2 4-4"></path>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>',
        'shield-alert' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><line x1="12" x2="12" y1="8" y2="12"></line><line x1="12" x2="12.01" y1="16" y2="16"></line>',
        'cpu' => '<rect width="16" height="16" x="4" y="4" rx="2"></rect><rect width="6" height="6" x="9" y="9" rx="1"></rect><path d="M15 2v2"></path><path d="M15 20v2"></path><path d="M2 15h2"></path><path d="M2 9h2"></path><path d="M20 15h2"></path><path d="M20 9h2"></path><path d="M9 2v2"></path><path d="M9 20v2"></path>',
        'bar-chart-2' => '<line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line>',
        'pie-chart' => '<path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path><path d="M22 12A10 10 0 0 0 12 2v10z"></path>',
        'activity' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>',
        'key' => '<path d="m21 2-2 2m-1.5 1.5L14 9l-1.5-1.5L11 9l-1.5-1.5L8 9l-2-2a4 4 0 1 0-4 4l9.5 9.5a2.12 2.12 0 0 0 3 0l6.5-6.5a2.12 2.12 0 0 0 0-3L21 2Z"></path><circle cx="5.5" cy="5.5" r="1.5"></circle>',
        'lock' => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path>',
        'mail' => '<rect width="20" height="16" x="2" y="4" rx="2"></rect><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path>',
        'eye' => '<path d="2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"></path><circle cx="12" cy="12" r="3"></circle>',
        'eye-off' => '<path d="9.88 9.88a3 3 0 1 0 4.24 4.24"></path><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"></path><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"></path><line x1="2" x2="22" y1="2" y2="22"></line>',
        'edit' => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z"></path>',
        'trash-2' => '<path d="M3 6h18"></path><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path><line x1="10" x2="10" y1="11" y2="17"></line><line x1="14" x2="14" y1="11" y2="17"></line>',
        'wifi' => '<path d="M12 20h.01"></path><path d="M2 8.82a15 15 0 0 1 20 0"></path><path d="M5 12.86a10 10 0 0 1 14 0"></path><path d="M8.5 16.43a5 5 0 0 1 7 0"></path>',
        'radio' => '<path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9"></path><path d="M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.5"></path><circle cx="12" cy="12" r="2"></circle><path d="M16.2 7.8c2.3 2.3 2.3 6.1 0 8.5"></path><path d="M19.1 4.9C23 8.8 23 15.1 19.1 19"></path>',
        'display' => '<rect width="20" height="14" x="2" y="3" rx="2"></rect><line x1="8" x2="16" y1="21" y2="21"></line><line x1="12" x2="12" y1="17" y2="21"></line>',
        'bot' => '<path d="M12 8V4H8"></path><rect width="16" height="12" x="4" y="8" rx="2"></rect><path d="M2 14h2"></path><path d="M20 14h2"></path><path d="M15 13v2"></path><path d="M9 13v2"></path>',
        'power' => '<path d="M12 2v10"></path><path d="M18.4 6.6a9 9 0 1 1-12.77.04"></path>',
        'send' => '<line x1="22" x2="11" y1="2" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>',
    ];

    $classes = 'lucide lucide-' . htmlspecialchars($resolvedName);
    if (!empty($class)) {
        $classes .= ' ' . htmlspecialchars($class);
    }

    if (isset($svgPaths[$resolvedName])) {
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%s" height="%s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="%s" stroke-linecap="round" stroke-linejoin="round" class="%s" data-lucide="%s"%s>%s</svg>',
            htmlspecialchars((string)$width),
            htmlspecialchars((string)$height),
            htmlspecialchars((string)$strokeWidth),
            $classes,
            htmlspecialchars($resolvedName),
            $extraAttrs,
            $svgPaths[$resolvedName]
        );
    }

    return sprintf(
        '<i data-lucide="%s" class="%s"%s></i>',
        htmlspecialchars($resolvedName),
        $classes,
        $extraAttrs
    );
}

/**
 * Sanitize text to prevent CSV/Excel spreadsheet formula injection.
 * If user text begins with =, +, -, @, \t, or \r, prepends a single quote.
 *
 * @param mixed $value
 * @return string
 */
function sanitize_excel_cell(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    $str = (string)$value;
    if ($str === '') {
        return '';
    }
    $firstChar = $str[0];
    if (in_array($firstChar, ['=', '+', '-', '@', "\t", "\r"], true)) {
        // If it's a legitimate number (like -10.5 or +5), leave it unless followed by a formula
        if (is_numeric($str)) {
            return $str;
        }
        return "'" . $str;
    }
    return $str;
}

