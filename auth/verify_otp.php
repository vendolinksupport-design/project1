<?php
/**
 * ============================================================
 * VendoLink — Email OTP Verification Page
 * ============================================================
 *
 * Verifies the 6-digit OTP sent to the user's registered email
 * after self-registration.
 *
 * Expiration: 5 minutes (300 seconds)
 * Resend cooldown: 60 seconds
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

if (is_logged_in()) {
    redirect('dashboard/index.php');
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../controllers/AuthController.php';
    $authCtrl = new AuthController();
    $action   = $_POST['action'] ?? 'verify_otp';

    if ($action === 'resend_otp') {
        $authCtrl->resendOtp();
    } else {
        $authCtrl->verifyOtp();
    }
    exit;
}

// Check state: is this the success view?
$isCompleted = !empty($_SESSION['reg_completed']);
if ($isCompleted) {
    unset($_SESSION['reg_completed']);
}

$email  = $_SESSION['reg_email'] ?? '';
$sentAt = (int)($_SESSION['otp_sent_at'] ?? time());
$now    = time();

// Timers calculations
$expiresInSeconds = max(0, 300 - ($now - $sentAt)); // 5 minutes (300s)
$cooldownSeconds  = max(0, 60 - ($now - $sentAt));  // 60s cooldown

// If no session & not completed, redirect to register
if (!$isCompleted && empty($_SESSION['reg_user_id'])) {
    flash('info', 'Please complete the registration form to receive your verification code.');
    redirect('auth/register.php');
}

$maskedEmail = mask_email($email);
$pageTitle   = $isCompleted ? 'Account Created Successfully' : 'Verify Your Email';

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
                    <i class="bi bi-shield-check"></i>
                    <span>Secure 6-Digit Email Verification</span>
                </div>
                <div class="feature-item">
                    <i class="bi bi-envelope-open"></i>
                    <span>Instant SMTP Delivery</span>
                </div>
                <div class="feature-item">
                    <i class="bi bi-clock-history"></i>
                    <span>5-Minute Expiration Security</span>
                </div>
                <div class="feature-item">
                    <i class="bi bi-person-check-fill"></i>
                    <span>Verified Owner Access</span>
                </div>
            </div>

            <div class="brand-version">v<?= APP_VERSION ?></div>
        </div>
    </div>

    <!-- Right form panel -->
    <div class="auth-form-panel">
        <div class="auth-form-container">

            <div class="auth-mobile-logo d-lg-none mb-4">
                <i class="bi bi-cpu-fill me-2"></i>
                <span>Vendo<strong>Link</strong></span>
            </div>

            <?php if ($isCompleted): ?>

                <!-- ====================================================
                     SUCCESS STATE — Account Created Successfully
                     ==================================================== -->
                <div class="text-center py-4">
                    <div class="success-badge-large mb-3">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 3.8rem;"></i>
                    </div>
                    <h2 class="auth-form-title text-white mb-2">Account Created Successfully</h2>
                    <p class="auth-form-subtitle text-slate-300 mb-4" style="font-size: 1rem;">
                        Your VendoLink owner account has been verified.
                    </p>

                    <a href="<?= BASE_URL ?>/auth/login.php" class="btn-auth-submit text-decoration-none d-inline-flex align-items-center justify-content-center">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Go to Login
                    </a>
                </div>

            <?php else: ?>

                <!-- ====================================================
                     OTP VERIFICATION STATE — 6-Digit Code Input
                     ==================================================== -->
                <h2 class="auth-form-title">Verify your email</h2>
                <p class="auth-form-subtitle text-slate-300 mb-4">
                    We sent a verification code to<br>
                    <strong class="text-white font-monospace fs-6"><?= e($maskedEmail) ?></strong>
                </p>

                <?= render_flash() ?>

                <form id="otpForm" action="" method="POST" novalidate data-loading="true">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="verify_otp">

                    <!-- 6-digit OTP input -->
                    <div class="form-group-auth mb-3">
                        <label for="otpInput" class="auth-label text-center d-block mb-2">
                            <i class="bi bi-shield-lock me-1"></i>Enter 6-Digit Code
                        </label>
                        <input
                            type="text"
                            id="otpInput"
                            name="otp"
                            class="form-control auth-input otp-code-input text-center font-monospace"
                            maxlength="6"
                            placeholder="000000"
                            pattern="[0-9]{6}"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            required
                            autofocus
                        >
                    </div>

                    <!-- Expiration Countdown -->
                    <div class="otp-expiration-badge text-center mb-4" id="otpTimerBox">
                        <i class="bi bi-clock me-1"></i>Code expires in <span id="otpCountdown" class="fw-bold">05:00</span>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" class="btn-auth-submit mb-3" id="verifyBtn">
                        <span class="btn-text">
                            <i class="bi bi-patch-check me-2"></i>Verify Email
                        </span>
                        <span class="btn-loading d-none">
                            <span class="spinner-border spinner-border-sm me-2" role="status"></span>
                            Verifying...
                        </span>
                    </button>
                </form>

                <!-- Resend Code Form -->
                <form id="resendForm" action="" method="POST" class="text-center">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="resend_otp">
                    <button type="submit" class="btn btn-outline-secondary btn-sm px-4" id="resendBtn" <?= $cooldownSeconds > 0 ? 'disabled' : '' ?>>
                        <i class="bi bi-arrow-clockwise me-1"></i>
                        <span id="resendBtnText">Resend Code<?= $cooldownSeconds > 0 ? " ({$cooldownSeconds}s)" : '' ?></span>
                    </button>
                </form>

                <div class="auth-footer-text mt-4 text-center">
                    <i class="bi bi-info-circle me-1"></i>
                    Didn't receive the email? Check your spam folder or click Resend Code
                </div>

            <?php endif; ?>

        </div>
    </div>

</div>

<?php if (!$isCompleted): ?>
<script>
(function(){
    'use strict';
    var expiresInSec = <?= (int)$expiresInSeconds ?>;
    var cooldownSec  = <?= (int)$cooldownSeconds ?>;
    var timerEl      = document.getElementById('otpCountdown');
    var timerBox     = document.getElementById('otpTimerBox');
    var resendBtn    = document.getElementById('resendBtn');
    var resendText   = document.getElementById('resendBtnText');
    var otpInput     = document.getElementById('otpInput');

    // Auto-focus & digits-only restriction
    if (otpInput) {
        otpInput.addEventListener('input', function(){
            this.value = this.value.replace(/[^0-9]/g, '');
        });
    }

    // 1. Expiration Timer (5 min)
    function updateExpirationTimer() {
        if (expiresInSec <= 0) {
            if (timerEl) timerEl.textContent = '00:00';
            if (timerBox) {
                timerBox.innerHTML = '<i class="bi bi-exclamation-circle text-danger me-1"></i><span class="text-danger fw-bold">Code expired. Please click Resend Code.</span>';
            }
            return;
        }
        var m = Math.floor(expiresInSec / 60);
        var s = expiresInSec % 60;
        var formatted = (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s);
        if (timerEl) timerEl.textContent = formatted;
        expiresInSec--;
    }
    updateExpirationTimer();
    var expInterval = setInterval(function(){
        updateExpirationTimer();
        if (expiresInSec < 0) clearInterval(expInterval);
    }, 1000);

    // 2. Resend Cooldown Timer (60s)
    function updateCooldownTimer() {
        if (cooldownSec <= 0) {
            if (resendBtn) resendBtn.disabled = false;
            if (resendText) resendText.textContent = 'Resend Code';
            return;
        }
        if (resendBtn) resendBtn.disabled = true;
        if (resendText) resendText.textContent = 'Resend Code (' + cooldownSec + 's)';
        cooldownSec--;
    }
    updateCooldownTimer();
    var cdInterval = setInterval(function(){
        updateCooldownTimer();
        if (cooldownSec < 0) clearInterval(cdInterval);
    }, 1000);
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
