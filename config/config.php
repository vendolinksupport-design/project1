<?php
/**
 * ============================================================
 * VendoLink — Global Application Configuration
 * ============================================================
 *
 * Supports both local development (XAMPP localhost / LAN)
 * and production deployment (InfinityFree / vendolink.great-site.net).
 *
 * ============================================================
 * PHASE 1 SCOPE — IMPORTANT
 * ============================================================
 *
 * VendoLink Version 1 is designed for a single car-wash business
 * operating one vending machine controlled by one ESP32.
 *
 *   - ONE business
 *   - ONE car-wash establishment
 *   - ONE physical machine (Machine 01)
 *   - ONE ESP32 controller
 *
 * ============================================================
 *
 * @package VendoLink
 * @version 1.0
 */

// ============================================================
// Environment Detection (Production vs Development)
// ============================================================
$httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$isProductionHost = (
    strpos($httpHost, 'vendolink.online') !== false ||
    strpos($httpHost, 'great-site.net') !== false ||
    strpos($httpHost, 'infinityfree.com') !== false ||
    (getenv('APP_ENV') === 'production')
);

if ($isProductionHost) {
    define('APP_ENV', 'production');
} else {
    define('APP_ENV', 'development');
}

// ============================================================
// Application Identity
// ============================================================
define('APP_NAME',    'VendoLink');
define('APP_VERSION', '1.0');
define('APP_TAGLINE', 'IoT Monitoring & Sales Audit System');

// ============================================================
// Base URL & Routing Configuration
// ============================================================
if (APP_ENV === 'production') {
    // Production deployment directly in web root
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    define('BASE_URL', $protocol . '://' . $httpHost);
} else {
    // Dynamic Base URL for local XAMPP / LAN hosts
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    define('BASE_URL', $protocol . '://' . $httpHost . '/vendolink_claude_v1');
}

// ============================================================
// Absolute Filesystem Paths
// ============================================================
define('ROOT_PATH',        dirname(__DIR__));
define('CONFIG_PATH',      ROOT_PATH . '/config');
define('VIEWS_PATH',       ROOT_PATH . '/views');
define('CONTROLLERS_PATH', ROOT_PATH . '/controllers');
define('MODELS_PATH',      ROOT_PATH . '/models');
define('INCLUDES_PATH',    ROOT_PATH . '/includes');
define('UPLOADS_PATH',     ROOT_PATH . '/uploads');
define('LOGS_PATH',        ROOT_PATH . '/logs');

// ============================================================
// Asset URL (CSS, JS, images)
// ============================================================
define('ASSETS_URL', BASE_URL . '/assets');

// ============================================================
// Database Credentials
// ============================================================
if (APP_ENV === 'production') {
    // Production cPanel MySQL Database
    if (file_exists(__DIR__ . '/db.production.php')) {
        require_once __DIR__ . '/db.production.php';
    } else {
        define('DB_HOST',    'localhost');
        define('DB_PORT',    '3306');
        define('DB_NAME',    'vendvqqp_vendolink_db');
        define('DB_USER',    'vendvqqp_admin');
        define('DB_PASS',    'YOUR_PASSWORD_HERE'); // Replace with your vendvqqp_admin password
        define('DB_CHARSET', 'utf8mb4');
    }
} else {
    // Localhost Development MySQL Database
    define('DB_HOST',    'localhost');
    define('DB_PORT',    '3306');
    define('DB_NAME',    'vendolink_db');
    define('DB_USER',    'root');
    define('DB_PASS',    '');           // Set a password in production
    define('DB_CHARSET', 'utf8mb4');
}

// ============================================================
// SMTP / Online Email Configuration
// ============================================================
// Load optional local unversioned mail overrides (never committed to Git)
if (file_exists(__DIR__ . '/mail.local.php')) {
    require_once __DIR__ . '/mail.local.php';
}

if (!defined('SMTP_ENABLED'))    define('SMTP_ENABLED',    true);
if (!defined('SMTP_HOST'))       define('SMTP_HOST',       getenv('SMTP_HOST') ?: 'smtp.gmail.com');
if (!defined('SMTP_PORT'))       define('SMTP_PORT',       (int)(getenv('SMTP_PORT') ?: 587));
if (!defined('SMTP_USER'))       define('SMTP_USER',       getenv('SMTP_USER') ?: 'vendolink.support@gmail.com');
if (!defined('SMTP_PASS'))       define('SMTP_PASS',       getenv('SMTP_PASS') ?: '');
if (!defined('SMTP_SECURE'))     define('SMTP_SECURE',     getenv('SMTP_SECURE') ?: 'tls'); // 'tls' (587) or 'ssl' (465)
if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: 'vendolink.support@gmail.com');
if (!defined('SMTP_FROM_NAME'))  define('SMTP_FROM_NAME',  getenv('SMTP_FROM_NAME') ?: 'VendoLink Support');
if (!defined('SMTP_TIMEOUT'))    define('SMTP_TIMEOUT',    10);

// ============================================================
// Google Gemini AI Configuration
// Paste your free API key from https://aistudio.google.com/app/apikey below
// ============================================================
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');

// ============================================================
// Session Configuration
// ============================================================
define('SESSION_NAME',               'vendolink_sess');
define('SESSION_LIFETIME',           14400);   // 4 hours in seconds (cookie lifetime)
define('SESSION_INACTIVITY_TIMEOUT', 14400);   // 4 hours of inactivity before auto-logout
define('SESSION_ABSOLUTE_TIMEOUT',   86400);   // 24 hours absolute maximum session lifetime
define('SESSION_REGEN_INTERVAL',     1800);    // 30 minutes between periodic session ID regenerations
define('REMEMBER_ME_DAYS',           30);      // Remember-me cookie lifetime

// ============================================================
// Security Settings
// ============================================================
define('CSRF_TOKEN_NAME',    'csrf_token');
define('BCRYPT_COST',        12);           // bcrypt work factor
define('MAX_LOGIN_ATTEMPTS', 5);            // max failures before lockout
define('LOCKOUT_DURATION',   900);          // lockout time (seconds = 15 min)

// ============================================================
// Hardware Device Bootstrap Authentication Master Key
// ============================================================
// Cryptographic master salt used by the backend to derive and validate per-device
// bootstrap tokens: HMAC-SHA256(normalized_mac, DEVICE_BOOTSTRAP_MASTER_KEY).
// Stored strictly on the backend; never hardcoded into universal device firmware.
define('DEVICE_BOOTSTRAP_MASTER_KEY', getenv('DEVICE_BOOTSTRAP_MASTER_KEY') ?: 'vl_boot_master_8e3f1c7a9d2b5e0f4a6b8c1d3e5f7a9b');

// Legacy shared secret (retained for backward compatibility during staging)
define('DEVICE_ENROLLMENT_SECRET', getenv('DEVICE_ENROLLMENT_SECRET') ?: 'vl_enroll_sec_7f9c2a1e8d3b4f6a5c0e');

// ============================================================
// User Roles
// ============================================================
define('ROLE_ADMIN',      'administrator');
define('ROLE_SUPPORT',    'support');
define('ROLE_OWNER',      'owner');
define('ROLE_STAFF',      'staff');
define('ROLE_TECHNICIAN', 'technician');

define('ALL_ROLES',      [ROLE_ADMIN, ROLE_SUPPORT, ROLE_OWNER, ROLE_STAFF, ROLE_TECHNICIAN]);
define('INTERNAL_ROLES', [ROLE_ADMIN, ROLE_SUPPORT]);
define('CUSTOMER_ROLES', [ROLE_OWNER, ROLE_STAFF]);

// ============================================================
// Timezone & IoT Heartbeat Settings
// ============================================================
date_default_timezone_set('Asia/Manila');
define('HEARTBEAT_TIMEOUT_SECONDS', 60);

// ============================================================
// Error Reporting
// ============================================================
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', ROOT_PATH . '/logs/php_errors.log');
}

// Canonical Terminology Dictionary
require_once INCLUDES_PATH . '/terminology.php';
