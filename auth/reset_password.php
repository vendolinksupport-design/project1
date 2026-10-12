<?php
/**
 * ============================================================
 * VendoLink — Reset Password Page
 * ============================================================
 *
 * Accessed via a token link: /auth/reset_password.php?token=xxx
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/User.php';

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
    require_once __DIR__ . '/../controllers/AuthController.php';
    (new AuthController())->resetPassword();
    exit;
}

// Validate token on GET
$rawToken  = trim($_GET['token'] ?? '');
$tokenUser = null;
$tokenError = false;

if (empty($rawToken)) {
    flash('danger', 'No reset token provided.');
    redirect('auth/forgot_password.php');
}

$userModel = new User();
$tokenUser = $userModel->findByResetToken($rawToken);

if (!$tokenUser) {
    $tokenError = true;
}

$pageTitle = 'Reset Password';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-wrapper auth-wrapper-centered">
    <div class="auth-form-panel auth-panel-full">
        <div class="auth-form-container auth-compact">

            <!-- Back link -->
            <a href="<?= BASE_URL ?>/auth/login.php" class="auth-back-link mb-4 d-inline-flex">
                <i class="bi bi-arrow-left me-2"></i>Back to Sign In
            </a>

            <div class="auth-icon-header">
                <div class="auth-icon-circle <?= $tokenError ? 'auth-icon-danger' : '' ?>">
                    <i class="bi <?= $tokenError ? 'bi-x-circle' : 'bi-key' ?>"></i>
                </div>
            </div>

            <?php if ($tokenError): ?>

                <h2 class="auth-form-title text-center mt-3">Link Expired</h2>
                <p class="auth-form-subtitle text-center">
                    This reset link is invalid or has expired. Please request a new one.
                </p>
                <a href="<?= BASE_URL ?>/auth/forgot_password.php" class="btn-auth-submit text-center d-block mt-4">
                    <i class="bi bi-arrow-counterclockwise me-2"></i>Request New Link
                </a>

            <?php else: ?>

                <h2 class="auth-form-title text-center mt-3">Set New Password</h2>
                <p class="auth-form-subtitle text-center">
                    Enter a strong new password for
                    <strong><?= e($tokenUser['email']) ?></strong>.
                </p>

                <!-- Flash Messages -->
                <?= render_flash() ?>

                <form action="" method="POST" id="resetForm" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($rawToken) ?>">

                    <!-- New Password -->
                    <div class="form-group-auth mb-3">
                        <label for="new_password" class="auth-label">
                            <i class="bi bi-lock me-1"></i>New Password
                        </label>
                        <div class="input-password-wrapper">
                            <input
                                type="password"
                                id="new_password"
                                name="new_password"
                                class="form-control auth-input"
                                placeholder="Min. 8 characters"
                                autocomplete="new-password"
                                required
                                autofocus
                            >
                            <button type="button" class="password-toggle" aria-label="Toggle password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <!-- Strength meter -->
                        <div class="password-strength mt-2">
                            <div class="strength-bar">
                                <div class="strength-fill" id="strengthFill"></div>
                            </div>
                            <small class="strength-label" id="strengthLabel"></small>
                        </div>
                    </div>

                    <!-- Confirm Password -->
                    <div class="form-group-auth mb-4">
                        <label for="confirm_password" class="auth-label">
                            <i class="bi bi-lock-fill me-1"></i>Confirm Password
                        </label>
                        <div class="input-password-wrapper">
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                class="form-control auth-input"
                                placeholder="Re-enter password"
                                autocomplete="new-password"
                                required
                            >
                            <button type="button" class="password-toggle" aria-label="Toggle password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-auth-submit" id="resetBtn">
                        <span class="btn-text">
                            <i class="bi bi-check2-circle me-2"></i>Reset Password
                        </span>
                        <span class="btn-loading d-none">
                            <span class="spinner-border spinner-border-sm me-2"></span>
                            Resetting...
                        </span>
                    </button>
                </form>

            <?php endif; ?>

        </div>
    </div>
</div>

<script>
    function togglePwd(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon  = document.getElementById(iconId);
        input.type  = input.type === 'password' ? 'text' : 'password';
        icon.classList.toggle('bi-eye');
        icon.classList.toggle('bi-eye-slash');
    }

    // Password strength meter
    const pwdInput     = document.getElementById('new_password');
    const strengthFill = document.getElementById('strengthFill');
    const strengthLabel = document.getElementById('strengthLabel');

    if (pwdInput) {
        pwdInput.addEventListener('input', function () {
            const val = this.value;
            let score = 0;
            if (val.length >= 8)                    score++;
            if (/[A-Z]/.test(val))                  score++;
            if (/[0-9]/.test(val))                  score++;
            if (/[^A-Za-z0-9]/.test(val))           score++;

            const labels = ['', 'Weak', 'Fair', 'Good', 'Strong'];
            const colors = ['', '#ef4444', '#f97316', '#eab308', '#22c55e'];

            strengthFill.style.width  = (score * 25) + '%';
            strengthFill.style.background = colors[score] || '#334155';
            strengthLabel.textContent = labels[score] || '';
            strengthLabel.style.color = colors[score] || '#94a3b8';
        });
    }

    // Loading state
    const resetForm = document.getElementById('resetForm');
    if (resetForm) {
        resetForm.addEventListener('submit', function () {
            document.querySelector('.btn-text').classList.add('d-none');
            document.querySelector('.btn-loading').classList.remove('d-none');
            document.getElementById('resetBtn').disabled = true;
        });
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
