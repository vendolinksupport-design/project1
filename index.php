<?php
/**
 * ============================================================
 * VendoLink — Root Entry Point (index.php)
 * ============================================================
 *
 * The root index redirects to the appropriate page:
 *   - Logged-in users  → Dashboard
 *   - Guests           → Login page
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/middleware/auth.php';

// Redirect based on auth state
if (is_logged_in()) {
    if (has_role([ROLE_ADMIN, ROLE_SUPPORT])) {
        redirect('admin/dashboard/index.php');
    } else {
        redirect('dashboard/index.php');
    }
} else {
    redirect('auth/login.php');
}
