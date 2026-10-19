<?php
/**
 * ============================================================
 * VendoLink — Login Page
 * ============================================================
 *
 * Scope: Single business, single car-wash machine (Phase 1).
 *
 * Handles GET (display form) and POST (dispatch to controller).
 * Password-toggle behaviour is handled by the global
 * initPasswordToggle() function in assets/js/app.js.
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

// Redirect to appropriate dashboard if already logged in (skip if explicit logout requested)
if (is_logged_in() && !isset($_GET['logged_out'])) {
    if (has_role([ROLE_ADMIN, ROLE_SUPPORT])) {
        redirect('admin/dashboard/index.php');
    } else {
        redirect('dashboard/index.php');
    }
}

// Handle POST — dispatch to AuthController
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../controllers/AuthController.php';
    (new AuthController())->login();
    exit;
}

// Pre-fill identifier only when returning from a POST (e.g. after a failed login
// that was redirected back here with flash message). On a plain GET request,
// $_POST is empty so this safely evaluates to ''.
$identifier = ($_SERVER['REQUEST_METHOD'] === 'POST') ? e($_POST['identifier'] ?? '') : '';
$pageTitle  = 'Sign In';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-wrapper">

    <!-- Left branding panel -->
    <div class="auth-brand-panel">
        <div class="auth-brand-content">
            <div class="brand-logo-large">
                <i class="bi bi-cpu-fill"></i>
            </div>
            <h1 class="brand-name">Vendo<span>Link</span></h1>
            <p class="brand-tagline">IoT Monitoring &amp; Sales Audit System</p>

            <div class="brand-features mt-4">
                <div class="feature-item">
                    <i class="bi bi-activity"></i>
                    <span>Real-time Machine Monitoring</span>
                </div>
                <div class="feature-item">
                    <i class="bi bi-graph-up-arrow"></i>
                    <span>Sales Analytics &amp; Reporting</span>
                </div>
                <div class="feature-item">
                    <i class="bi bi-shield-check"></i>
                    <span>Secure Role-Based Access</span>
                </div>
                <div class="feature-item">
                    <i class="bi bi-bell"></i>
                    <span>Instant Alerts &amp; Notifications</span>
                </div>
            </div>

            <div class="brand-version">v<?= APP_VERSION ?></div>
        </div>
    </div>

    <!-- Right form panel -->
    <div class="auth-form-panel">
        <div class="auth-form-container">

            <!-- Mobile logo -->
            <div class="auth-mobile-logo d-lg-none mb-4">
                <i class="bi bi-cpu-fill me-2"></i>
                <span>Vendo<strong>Link</strong></span>
            </div>

            <h2 class="auth-form-title">Welcome back</h2>
            <p class="auth-form-subtitle">Sign in to your VendoLink account</p>

            <!-- Flash Messages -->
            <?php if (isset($_GET['logged_out'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>You have been logged out successfully.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?= render_flash() ?>

            <?php
            $isLocalHost = (
                in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost'], true) ||
                strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false ||
                strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false ||
                (defined('APP_ENV') && APP_ENV === 'development')
            ) && (!defined('APP_ENV') || APP_ENV !== 'production');
            ?>
            <?php if ($isLocalHost): ?>
            <div class="alert alert-info border border-info border-opacity-25 rounded-3 p-3 mb-3" style="background: rgba(14, 165, 233, 0.08);">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="small fw-bold text-info"><i class="bi bi-lightning-charge-fill me-1"></i>Localhost Test Bypass</span>
                    <span class="badge bg-info-subtle text-info border border-info border-opacity-25" style="font-size: 0.68rem;">Local Dev Only</span>
                </div>
                <div class="d-flex gap-2 flex-wrap mb-2">
                    <a href="<?= BASE_URL ?>/auth/dev_login.php?as=owner" class="btn btn-sm btn-info fw-bold text-dark px-3 shadow-sm">
                        <i class="bi bi-box-arrow-in-right me-1"></i>1-Click Owner Login
                    </a>
                    <a href="<?= BASE_URL ?>/auth/dev_login.php?as=admin" class="btn btn-sm btn-outline-info px-3">
                        <i class="bi bi-shield-lock me-1"></i>1-Click Admin Login
                    </a>
                </div>
                <div class="text-secondary small" style="font-size: 0.75rem;">
                    Manual login: <code>test_owner</code> or <code>admin</code> &bull; Password: <code>Password123!</code>
                </div>
            </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form id="loginForm" action="" method="POST" novalidate data-loading="true">
                <?= csrf_field() ?>

                <!-- Email / Username -->
                <div class="form-group-auth mb-3">
                    <label for="identifier" class="auth-label">
                        <i class="bi bi-person me-1"></i>Email or Username
                    </label>
                    <input
                        type="text"
                        id="identifier"
                        name="identifier"
                        class="form-control auth-input"
                        value="<?= $identifier ?>"
                        placeholder="admin@vendolink.local"
                        autocomplete="username"
                        required
                        autofocus
                    >
                </div>

                <!-- Password -->
                <div class="form-group-auth mb-3">
                    <label for="password" class="auth-label">
                        <i class="bi bi-lock me-1"></i>Password
                    </label>
                    <div class="input-password-wrapper">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control auth-input"
                            placeholder="••••••••"
                            autocomplete="current-password"
                            required
                        >
                        <button type="button" class="password-toggle" id="togglePassword"
                                aria-label="Toggle password visibility">
                            <i class="bi bi-eye" id="togglePasswordIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Remember Me + Forgot Password -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="form-check auth-check">
                        <input class="form-check-input" type="checkbox"
                               id="remember_me" name="remember_me" value="1">
                        <label class="form-check-label" for="remember_me">
                            Remember me for 30 days
                        </label>
                    </div>
                    <a href="<?= BASE_URL ?>/auth/forgot_password.php" class="auth-link">
                        Forgot password?
                    </a>
                </div>

                <!-- Submit -->
                <button type="submit" class="btn-auth-submit" id="loginBtn">
                    <span class="btn-text">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                    </span>
                    <span class="btn-loading d-none">
                        <span class="spinner-border spinner-border-sm me-2" role="status"></span>
                        Signing in...
                    </span>
                </button>
            </form>

            <div class="auth-footer-text mt-4 text-center">
                <div class="mb-2 text-slate-300">
                    Don't have an account? <a href="<?= BASE_URL ?>/auth/register.php" class="auth-link fw-bold ms-1">Create Owner Account</a>
                </div>
                <div>
                    <i class="bi bi-shield-lock me-1"></i>
                    Secured with bcrypt encryption &amp; CSRF protection
                </div>
            </div>

        </div>
    </div>

</div>

<!--
    NOTE: Password-toggle and form loading-state are handled globally
    by initPasswordToggle() and initFormLoadingState() in assets/js/app.js.
    No inline JS is needed here — removing duplicate handlers prevents
    the double-binding bug where classList.replace() could silently fail
    on the second toggle if the icon class was already swapped by the
    other listener. The form has data-loading="true" so
    initFormLoadingState() picks it up automatically.
-->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
