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
    die('<h1 style="font-family:sans-serif">403 — Invalid CSRF Token</h1>');
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
    if (empty($_SESSION['user_id']) || empty($_SESSION['user_role'])) {
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
    if (!defined('SMTP_ENABLED') || !SMTP_ENABLED) {
        error_log('[VendoLink Email] SMTP disabled. Skipping delivery to: ' . $to);
        return false;
    }

    $from     = $from     ?? (defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'vendolink.support@gmail.com');
    $fromName = $fromName ?? (defined('SMTP_FROM_NAME')  ? SMTP_FROM_NAME  : 'VendoLink Support');

    $host     = defined('SMTP_HOST') ? SMTP_HOST : 'ssl://smtp.gmail.com';
    $port     = defined('SMTP_PORT') ? SMTP_PORT : 465;
    $username = defined('SMTP_USER') ? SMTP_USER : '';
    $password = defined('SMTP_PASS') ? SMTP_PASS : '';

    if (empty($host) || empty($username) || empty($password)) {
        error_log('[VendoLink Email] SMTP configuration incomplete. Missing host, username, or password.');
        return false;
    }

    $socket = @fsockopen($host, $port, $errno, $errstr, 10);
    if (!$socket) {
        error_log("[VendoLink SMTP Error] Connection failed: {$errstr} ({$errno})");
        return false;
    }

    fgets($socket, 512);

    $cmds = [
        "EHLO localhost\r\n" => 250,
        "AUTH LOGIN\r\n" => 334,
        base64_encode($username) . "\r\n" => 334,
        base64_encode($password) . "\r\n" => 235,
        "MAIL FROM: <{$from}>\r\n" => 250,
        "RCPT TO: <{$to}>\r\n" => 250,
        "DATA\r\n" => 354,
    ];

    foreach ($cmds as $cmd => $expectedCode) {
        fputs($socket, $cmd);
        $res = '';
        while ($str = fgets($socket, 512)) {
            $res .= $str;
            if (substr($str, 3, 1) === ' ') break;
        }
        $code = (int)substr($res, 0, 3);
        if ($code !== $expectedCode) {
            error_log("[VendoLink SMTP Error] Handshake failed: expected {$expectedCode}, got {$code}");
            fclose($socket);
            return false;
        }
    }

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$fromName} <{$from}>\r\n";
    $headers .= "To: {$to}\r\n";
    $headers .= "Subject: {$subject}\r\n";
    $headers .= "Date: " . date('r') . "\r\n";

    fputs($socket, $headers . "\r\n" . $htmlBody . "\r\n.\r\n");
    $res = fgets($socket, 512);
    $code = (int)substr($res, 0, 3);

    fputs($socket, "QUIT\r\n");
    fclose($socket);

    return $code === 250;
}

