<?php
/**
 * ============================================================
 * VendoLink — Global Application Configuration
 * ============================================================
 *
 * Adjust BASE_URL, DB_*, and timezone to match your environment
 * before deploying.
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
 * FUTURE EXPANSION (not implemented in v1):
 *   - Multiple businesses
 *   - Multiple branches / establishments
 *   - Multiple machines per branch
 *   - Multiple ESP32 controllers
 *   - Multi-tenant management
 *
 * ============================================================
 *
 * @package VendoLink
 * @version 1.0
 */

// ============================================================
// Environment: 'development' | 'production'
// ============================================================
define('APP_ENV', 'development');

// ============================================================
// Application Identity
// ============================================================
define('APP_NAME',    'VendoLink');
define('APP_VERSION', '1.0');
define('APP_TAGLINE', 'IoT Monitoring &amp; Sales Audit System');

// ============================================================
// Dynamic Base URL — supports localhost, local IP (192.168.x.x), and LAN hosts
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('BASE_URL', $protocol . '://' . $host . '/vendolink_claude_v1');

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
define('DB_HOST',    'localhost');
define('DB_PORT',    '3306');
define('DB_NAME',    'vendolink_db');
define('DB_USER',    'root');
define('DB_PASS',    '');           // Set a password in production
define('DB_CHARSET', 'utf8mb4');

// ============================================================
// SMTP / Online Email Configuration
// ============================================================
define('SMTP_ENABLED',    true);
define('SMTP_HOST',       'ssl://smtp.gmail.com');
define('SMTP_PORT',       465);
define('SMTP_USER',       'vendolink.support@gmail.com');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
define('SMTP_SECURE',     'ssl');
define('SMTP_FROM_EMAIL', 'vendolink.support@gmail.com');
define('SMTP_FROM_NAME',  'VendoLink Support');

// ============================================================
// Google Gemini AI Configuration
// Paste your free API key from https://aistudio.google.com/app/apikey below
// ============================================================
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
// ============================================================
// Session Configuration
// ============================================================
define('SESSION_NAME',               'vendolink_sess');
define('SESSION_LIFETIME',           3600);    // 1 hour in seconds (cookie lifetime)
define('SESSION_INACTIVITY_TIMEOUT', 1800);    // 30 minutes of inactivity before auto-logout
define('SESSION_ABSOLUTE_TIMEOUT',   43200);   // 12 hours absolute maximum session lifetime
define('SESSION_REGEN_INTERVAL',     900);     // 15 minutes between periodic session ID regenerations
define('REMEMBER_ME_DAYS',           30);      // Remember-me cookie lifetime

// ============================================================
// Security Settings
// ============================================================
define('CSRF_TOKEN_NAME',   'csrf_token');
define('BCRYPT_COST',        12);           // bcrypt work factor
define('MAX_LOGIN_ATTEMPTS', 5);            // max failures before lockout
define('LOCKOUT_DURATION',   900);          // lockout time (seconds = 15 min)

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
