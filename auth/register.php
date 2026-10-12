<?php
/**
 * ============================================================
 * VendoLink - Owner Account Self-Registration Page
 * ============================================================
 *
 * Allows new system operators to register an Owner account.
 * Requires a valid hardware Product Key.
 * Accounts are created unverified and inactive until 6-digit OTP
 * email verification is completed.
 *
 * @package VendoLink
 * @version 1.1
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../controllers/AuthController.php';
    (new AuthController())->register();
    exit;
}

$savedForm  = $_SESSION['reg_form_data'] ?? [];
unset($_SESSION['reg_form_data']);

$fullName   = e($_POST['full_name'] ?? $savedForm['full_name'] ?? '');
$username   = e($_POST['username']  ?? $savedForm['username'] ?? '');
$email      = e($_POST['email']     ?? $savedForm['email'] ?? '');
$productKey = e($_POST['product_key'] ?? $savedForm['product_key'] ?? '');
$pageTitle  = 'Create Owner Account';

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
                    <i class="bi bi-building"></i>
                    <span>Customer Account Registration</span>
                </div>
                <div class="feature-item">
                    <i class="bi bi-shield-check"></i>
                    <span><?= term('machine_activation') ?></span>
                </div>
                <div class="feature-item">
                    <i class="bi bi-envelope-check"></i>
                    <span>Instant Email OTP Verification</span>
                </div>
                <div class="feature-item">
                    <i class="bi bi-shield-lock"></i>
                    <span>Bcrypt Password Encryption</span>
                </div>
                <div class="feature-item">
                    <i class="bi bi-speedometer2"></i>
                    <span>Full Customer Dashboard Access</span>
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

            <h2 class="auth-form-title">Create Customer Account</h2>
            <p class="auth-form-subtitle">Register to manage your VendoLink machines</p>

            <?= render_flash() ?>

            <form id="registerForm" action="" method="POST" novalidate data-loading="true">
                <?= csrf_field() ?>

                <!-- Full Name -->
                <div class="form-group-auth mb-3">
                    <label for="full_name" class="auth-label">
                        <i class="bi bi-person me-1"></i>Full Name <span class="text-danger">*</span>
                    </label>
                    <input type="text" id="full_name" name="full_name" class="form-control auth-input"
                           value="<?= $fullName ?>" placeholder="e.g. Juan Dela Cruz" required autofocus>
                </div>

                <!-- Username -->
                <div class="form-group-auth mb-3">
                    <label for="username" class="auth-label">
                        <i class="bi bi-at me-1"></i>Username <span class="text-danger">*</span>
                    </label>
                    <input type="text" id="username" name="username" class="form-control auth-input"
                           value="<?= $username ?>" placeholder="e.g. owner_juan" required pattern="[a-zA-Z0-9_]{3,}">
                </div>

                <!-- Email Address -->
                <div class="form-group-auth mb-3">
                    <label for="email" class="auth-label">
                        <i class="bi bi-envelope me-1"></i>Email Address <span class="text-danger">*</span>
                    </label>
                    <input type="email" id="email" name="email" class="form-control auth-input"
                           value="<?= $email ?>" placeholder="owner@domain.com" required>
                </div>

                <!-- Product Key (Hardware Activation) -->
                <div class="form-group-auth mb-3">
                    <label for="product_key" class="auth-label">
                        <i class="bi bi-key-fill me-1 text-warning"></i>Activation Key <span class="text-danger">*</span>
                    </label>
                    <input type="text" id="product_key" name="product_key" class="form-control auth-input font-monospace text-uppercase"
                           value="<?= $productKey ?>" placeholder="e.g. VL-2026-XXXX-XXXX-XXXX" required autocomplete="off"
                           style="letter-spacing: 0.05em; text-transform: uppercase;">
                    <div class="form-text text-muted small mt-1">
                        <i class="bi bi-shield-check me-1 text-info"></i>Enter the machine activation key provided with your VendoLink controller kit.
                    </div>
                </div>

                <!-- Password -->
                <div class="form-group-auth mb-3">
                    <label for="password" class="auth-label">
                        <i class="bi bi-lock me-1"></i>Password <span class="text-danger">*</span>
                    </label>
                    <div class="input-password-wrapper">
                        <input type="password" id="password" name="password" class="form-control auth-input"
                               placeholder="Min. 8 characters" required minlength="8" autocomplete="new-password">
                        <button type="button" class="password-toggle" aria-label="Toggle password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="password-strength-bar mt-1" id="pwStrengthBar">
                        <div class="password-strength-fill" id="pwStrengthFill"></div>
                    </div>
                    <div class="password-strength-text" id="pwStrengthText"></div>
                </div>

                <!-- Confirm Password -->
                <div class="form-group-auth mb-4">
                    <label for="confirm_password" class="auth-label">
                        <i class="bi bi-lock-fill me-1"></i>Confirm Password <span class="text-danger">*</span>
                    </label>
                    <div class="input-password-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control auth-input"
                               placeholder="Re-enter password" required minlength="8" autocomplete="new-password">
                        <button type="button" class="password-toggle" aria-label="Toggle password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="password-match-hint" id="pwMatchHint"></div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-auth-submit" id="regBtn">
                    <span class="btn-text">
                        <i class="bi bi-person-plus me-2"></i>Create Owner Account
                    </span>
                    <span class="btn-loading d-none">
                        <span class="spinner-border spinner-border-sm me-2" role="status"></span>
                        Creating Account...
                    </span>
                </button>
            </form>

            <div class="auth-footer-text mt-4 text-center">
                <div class="mb-2 text-slate-300">
                    Already have an account? <a href="<?= BASE_URL ?>/auth/login.php" class="auth-link fw-bold ms-1">Sign In</a>
                </div>
                <div>
                    <i class="bi bi-shield-lock me-1"></i>
                    Self-registration is for VendoLink system owners only
                </div>
            </div>

        </div>
    </div>

</div>

<script>
(function(){
    'use strict';
    var pw        = document.getElementById('password');
    var confirmPw = document.getElementById('confirm_password');
    var fill      = document.getElementById('pwStrengthFill');
    var text      = document.getElementById('pwStrengthText');
    var matchHint = document.getElementById('pwMatchHint');

    function strength(p){
        if(!p) return {pct:0,label:'',color:'transparent'};
        var s=0;
        if(p.length>=8) s++;
        if(p.length>=12) s++;
        if(/[A-Z]/.test(p)) s++;
        if(/[0-9]/.test(p)) s++;
        if(/[^A-Za-z0-9]/.test(p)) s++;
        var m=[
            {label:'',color:'transparent',pct:0},
            {label:'Weak',color:'#ef4444',pct:20},
            {label:'Fair',color:'#f97316',pct:40},
            {label:'Moderate',color:'#f59e0b',pct:60},
            {label:'Strong',color:'#22c55e',pct:80},
            {label:'Very Strong',color:'#0ea5e9',pct:100}
        ];
        return m[Math.min(s,5)];
    }

    function updateMatch() {
        if (!pw || !confirmPw) return;
        var p  = pw.value;
        var cp = confirmPw.value;
        if (!cp) {
            if (matchHint) matchHint.textContent = '';
            return;
        }
        var match = p.length > 0 && p === cp;
        if (matchHint) {
            matchHint.textContent = match ? '\u2713 Passwords match' : '\u2717 Passwords do not match';
            matchHint.className   = 'password-match-hint ' + (match ? 'match-ok' : 'match-fail');
        }
    }

    if (pw) {
        pw.addEventListener('input', function(){
            var s = strength(this.value);
            if(fill){ fill.style.width=s.pct+'%'; fill.style.backgroundColor=s.color; }
            if(text){ text.textContent=s.label; text.style.color=s.color; }
            updateMatch();
        });
    }
    if (confirmPw) {
        confirmPw.addEventListener('input', updateMatch);
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>