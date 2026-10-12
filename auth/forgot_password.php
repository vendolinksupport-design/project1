<?php
/**
 * ============================================================
 * VendoLink — Forgot Password Page
 * ============================================================
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

// Redirect if already logged in
if (is_logged_in()) {
    redirect('dashboard/index.php');
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../models/User.php';
    require_once __DIR__ . '/../controllers/AuthController.php';
    (new AuthController())->forgotPassword();
    exit;
}

$pageTitle = 'Forgot Password';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-wrapper auth-wrapper-centered">
    <div class="auth-form-panel auth-panel-full">
        <div class="auth-form-container auth-compact">

            <!-- Back link -->
            <a href="<?= BASE_URL ?>/auth/login.php" class="auth-back-link mb-4 d-inline-flex">
                <i class="bi bi-arrow-left me-2"></i>Back to Sign In
            </a>

            <!-- Icon header -->
            <div class="auth-icon-header">
                <div class="auth-icon-circle">
                    <i class="bi bi-envelope-at"></i>
                </div>
            </div>

            <h2 class="auth-form-title text-center mt-3">Forgot Password?</h2>
            <p class="auth-form-subtitle text-center">
                Enter your registered email address and we'll send you a password reset link.
            </p>

            <!-- Flash Messages -->
            <?= render_flash() ?>

            <!-- Form -->
            <form action="" method="POST" id="forgotForm" novalidate>
                <?= csrf_field() ?>

                <div class="form-group-auth mb-4">
                    <label for="email" class="auth-label">
                        <i class="bi bi-envelope me-1"></i>Email Address
                    </label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="form-control auth-input"
                        placeholder="admin@vendolink.local"
                        autocomplete="email"
                        required
                        autofocus
                    >
                </div>

                <button type="submit" class="btn-auth-submit" id="forgotBtn">
                    <span class="btn-text">
                        <i class="bi bi-send me-2"></i>Send Reset Link
                    </span>
                    <span class="btn-loading d-none">
                        <span class="spinner-border spinner-border-sm me-2" role="status"></span>
                        Sending...
                    </span>
                </button>
            </form>

            <div class="auth-footer-text mt-4 text-center">
                <i class="bi bi-info-circle me-1"></i>
                Reset links expire after <strong>1 hour</strong> for security.
            </div>

        </div>
    </div>
</div>

<script>
    document.getElementById('forgotForm').addEventListener('submit', function () {
        document.querySelector('.btn-text').classList.add('d-none');
        document.querySelector('.btn-loading').classList.remove('d-none');
        document.getElementById('forgotBtn').disabled = true;
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
