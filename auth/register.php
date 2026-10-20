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
$productKey = e($_POST['product_key'] ?? $savedForm['product_key'] ?? $_GET['key'] ?? '');
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
                    <label for="product_key" class="auth-label d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-key-fill me-1 text-warning"></i>Activation Key <span class="text-danger">*</span></span>
                    </label>
                    <div class="input-group">
                        <input type="text" id="product_key" name="product_key" class="form-control auth-input font-monospace text-uppercase"
                               value="<?= $productKey ?>" placeholder="e.g. VL-2026-XXXX-XXXX-XXXX" required autocomplete="off"
                               style="letter-spacing: 0.05em; text-transform: uppercase;">
                        <button type="button" class="btn btn-outline-info d-flex align-items-center gap-1.5 px-3" id="btnOpenScanner" title="Scan QR code from Machine Packaging Sticker" style="border-color: #0ea5e9; color: #38bdf8; background: rgba(14, 165, 233, 0.1);">
                            <i class="bi bi-qr-code-scan"></i>
                            <span class="d-none d-sm-inline">Scan QR Code</span>
                            <span class="d-inline d-sm-none">Scan</span>
                        </button>
                    </div>

                    <!-- Success Alert (Shown upon successful QR scan) -->
                    <div id="qrScanSuccessAlert" class="alert alert-success py-2 px-3 mt-2 d-none align-items-center justify-content-between" role="alert" style="background: rgba(34, 197, 94, 0.15); border: 1px solid #22c55e; color: #86efac; font-size: 0.85rem;">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-check-circle-fill text-success fs-6"></i>
                            <div>
                                <strong>QR code scanned.</strong> Activation key filled in: <span class="font-monospace fw-bold text-white ms-1" id="qrScanKeyText"></span>
                            </div>
                        </div>
                        <button type="button" class="btn-close btn-close-white ms-2" style="font-size: 0.7rem;" onclick="document.getElementById('qrScanSuccessAlert').classList.add('d-none')" aria-label="Close"></button>
                    </div>

                    <div class="form-text text-muted small mt-1">
                        <i class="bi bi-shield-check me-1 text-info"></i>Enter the Product Key from your controller kit or click <strong>Scan QR Code</strong> to scan the Machine Packaging Sticker.
                    </div>
                </div>

                <!-- Password -->
                <div class="form-group-auth mb-3">
                    <label for="password" class="auth-label">
                        <i class="bi bi-lock me-1"></i>Password <span class="text-danger">*</span>
                    </label>
                    <div class="input-password-wrapper">
                        <input type="password" id="password" name="password" class="form-control auth-input"
                               placeholder="Min. 8 alphanumeric" required minlength="8" autocomplete="new-password">
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

<!-- ============================================================ -->
<!-- QR Code Scanner Modal (Packaging Sticker Scanner)            -->
<!-- ============================================================ -->
<div class="modal fade" id="qrScannerModal" tabindex="-1" aria-labelledby="qrScannerModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background-color: #0f172a; border: 1px solid #1e293b; color: #f8fafc; border-radius: 14px; box-shadow: 0 25px 60px rgba(0, 0, 0, 0.7);">
            <div class="modal-header border-secondary border-opacity-25 pb-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="p-2 rounded-3 bg-info bg-opacity-10 text-info">
                        <i class="bi bi-qr-code-scan fs-5"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-white mb-0" id="qrScannerModalLabel">Scan Packaging QR Code</h5>
                        <p class="text-secondary small mb-0" style="font-size: 0.78rem;">Point your camera at the QR code on the Machine Packaging Sticker</p>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" id="btnCloseScanner"></button>
            </div>
            <div class="modal-body p-3 p-sm-4 text-center">
                <!-- Camera Select Dropdown (shown when multiple cameras exist) -->
                <div id="cameraSelectContainer" class="mb-3 d-none text-start">
                    <label for="cameraSelect" class="form-label small text-secondary mb-1">
                        <i class="bi bi-camera-video me-1"></i>Select Camera:
                    </label>
                    <select id="cameraSelect" class="form-select form-select-sm" style="background-color: #1e293b; color: #f8fafc; border-color: #334155; font-size: 0.82rem;">
                    </select>
                </div>

                <!-- Scanner Viewfinder Box -->
                <div class="scanner-viewport-wrapper position-relative rounded-3 overflow-hidden" id="scannerViewport">
                    <!-- Html5Qrcode video destination -->
                    <div id="qrReader" style="width: 100%;"></div>

                    <!-- Scanning Reticle / Visual Crosshairs & Laser -->
                    <div id="scannerReticle" class="scanner-reticle position-absolute top-50 start-50 translate-middle d-none" style="pointer-events: none; width: 220px; height: 220px;">
                        <div class="reticle-corner reticle-tl"></div>
                        <div class="reticle-corner reticle-tr"></div>
                        <div class="reticle-corner reticle-bl"></div>
                        <div class="reticle-corner reticle-br"></div>
                        <div class="scanner-laser-line"></div>
                    </div>

                    <!-- Loading / Starting Camera Overlay -->
                    <div id="scannerLoadingOverlay" class="position-absolute top-50 start-50 translate-middle text-center w-100 px-3">
                        <div class="spinner-border text-info mb-3" style="width: 2.5rem; height: 2.5rem;" role="status"></div>
                        <div class="text-white fw-semibold small">Initializing camera preview...</div>
                        <div class="text-secondary small mt-1" style="font-size: 0.78rem;">Please allow camera permissions if prompted</div>
                    </div>

                    <!-- Error Overlay -->
                    <div id="scannerErrorOverlay" class="position-absolute top-0 start-0 w-100 h-100 p-3 text-center d-none" style="overflow-y: auto; background: rgba(15, 23, 42, 0.98); z-index: 20;">
                        <i class="bi bi-camera-video-off text-danger fs-2 mb-1"></i>
                        <h6 id="scannerErrorTitle" class="text-white fw-bold mb-1">Camera Access Issue</h6>
                        <p id="scannerErrorMessage" class="text-secondary small mb-2" style="font-size: 0.82rem; line-height: 1.4;">Unable to access the camera.</p>

                        <!-- Settings Guide (shown when camera permission is blocked or denied repeatedly) -->
                        <div id="scannerSettingsGuide" class="p-2 mb-2 rounded-2 text-start small d-none" style="background: rgba(15, 23, 42, 0.95); border: 1px solid #334155; font-size: 0.76rem;">
                            <div class="fw-bold text-info mb-1 d-flex align-items-center gap-1">
                                <i class="bi bi-sliders"></i> How to enable camera in browser settings:
                            </div>
                            <ol class="ps-3 mb-1 text-slate-300" style="padding-left: 1.1rem; line-height: 1.45;">
                                <li>Tap the <i class="bi bi-sliders text-info"></i> or <i class="bi bi-lock-fill text-warning"></i> lock or site info icon beside the address bar.</li>
                                <li>Tap <strong>Permissions</strong> or <strong>Site settings</strong>.</li>
                                <li>Change <strong>Camera</strong> access to <strong>Allow</strong>.</li>
                                <li>Return here and tap <strong>Try Again</strong>, or tap <strong>Take Photo / Upload</strong> below to scan without changing settings.</li>
                            </ol>
                            <div class="mt-2 pt-1 border-top border-secondary border-opacity-25" style="font-size: 0.73rem;">
                                <span class="text-warning fw-semibold"><i class="bi bi-phone me-1"></i>Still blocked?</span> In Android Phone Settings:
                                <div class="text-slate-300 ms-3 mt-0.5">Settings &rarr; Apps &rarr; Brave (or Chrome) &rarr; Permissions &rarr; Camera &rarr; <strong>Allow</strong></div>
                            </div>
                        </div>

                        <!-- Direct Actions: Take Photo (native OS camera), Try Again, Enter Manually -->
                        <div class="d-flex flex-wrap justify-content-center gap-2 mt-2">
                            <button type="button" class="btn btn-sm btn-success px-3" id="btnUploadPhotoFromError">
                                <i class="bi bi-camera-fill me-1"></i>Take Photo / Upload
                            </button>
                            <button type="button" class="btn btn-sm btn-info px-3" id="btnRetryCamera">
                                <i class="bi bi-arrow-clockwise me-1"></i>Try Again
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary px-3" id="btnEnterManualFromError" data-bs-dismiss="modal">
                                <i class="bi bi-keyboard me-1"></i>Enter Manually
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Hidden file input for native OS photo capture / upload fallback -->
                <input type="file" id="scannerFileInput" accept="image/*" capture="environment" class="d-none">

                <!-- Live Status & Instructions -->
                <div id="scannerStatusText" class="mt-3 text-secondary small" style="font-size: 0.8rem;">
                    <i class="bi bi-info-circle me-1 text-info"></i>Align the QR code from the ESP32 sticker inside the frame to scan automatically.
                </div>

                <!-- Temporary Inline Warning (e.g. Scanned invalid QR code) -->
                <div id="scannerFeedbackMsg" class="alert alert-warning py-2 px-3 mt-2 small d-none" style="background: rgba(245, 158, 11, 0.15); border: 1px solid #f59e0b; color: #fde68a; font-size: 0.82rem;" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i><span id="scannerFeedbackText">Invalid QR code.</span>
                </div>
            </div>
            <div class="modal-footer border-secondary border-opacity-25 justify-content-between">
                <button type="button" class="btn btn-outline-info btn-sm px-3" id="btnUploadPhotoFromFooter">
                    <i class="bi bi-camera me-1"></i>Take Photo / Upload
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-bs-dismiss="modal">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* QR Scanner Viewfinder and Reticle Styling */
.scanner-viewport-wrapper {
    position: relative;
    background: #020617;
    border: 2px solid #334155;
    border-radius: 10px;
    min-height: 300px;
    max-height: 420px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
}
#qrReader {
    width: 100% !important;
    border: none !important;
}
#qrReader video {
    width: 100% !important;
    height: 100% !important;
    object-fit: cover !important;
    border-radius: 8px;
    display: block;
}
#qrReader__scan_region {
    background: transparent !important;
}
#qrReader__header_message, #qrReader img[alt="Info icon"] {
    display: none !important;
}
.scanner-reticle {
    position: absolute;
    width: 220px;
    height: 220px;
    pointer-events: none;
    z-index: 10;
}
.reticle-corner {
    position: absolute;
    width: 26px;
    height: 26px;
    border-color: #0ea5e9;
    border-style: solid;
}
.reticle-tl { top: 0; left: 0; border-width: 4px 0 0 4px; border-top-left-radius: 6px; }
.reticle-tr { top: 0; right: 0; border-width: 4px 4px 0 0; border-top-right-radius: 6px; }
.reticle-bl { bottom: 0; left: 0; border-width: 0 0 4px 4px; border-bottom-left-radius: 6px; }
.reticle-br { bottom: 0; right: 0; border-width: 0 4px 4px 0; border-bottom-right-radius: 6px; }
.scanner-laser-line {
    position: absolute;
    left: 4px;
    right: 4px;
    height: 2px;
    background: linear-gradient(90deg, rgba(14,165,233,0), #38bdf8 50%, rgba(14,165,233,0));
    box-shadow: 0 0 8px #0ea5e9;
    animation: scannerLaser 2s infinite ease-in-out;
}
@keyframes scannerLaser {
    0% { top: 6px; opacity: 0.2; }
    50% { top: calc(100% - 8px); opacity: 1; }
    100% { top: 6px; opacity: 0.2; }
}
</style>

<!-- html5-qrcode Library -->
<script src="<?= ASSETS_URL ?>/js/html5-qrcode.min.js"></script>
<script>
if (typeof Html5Qrcode === 'undefined') {
    document.write('<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"><\/script>');
}
</script>

<script>
/**
 * ============================================================
 * VendoLink — Registration QR Scanner & Form Controller
 * ============================================================
 */

/**
 * Robustly extract and validate an activation key from a QR payload.
 * Supports:
 * - Packaging sticker register URL: http(s)://.../auth/register.php?key=VL-2026-XXXX
 * - Generic URLs with ?key=, ?product_key=, ?activation_key=, ?code=
 * - JSON payload: {"key":"VL-..."} or {"product_key":"VL-..."}
 * - Direct activation key string: VL-2026-XXXX-XXXX-XXXX or custom keys
 *
 * @param {string} rawPayload Scanned QR code text
 * @return {string|null} Normalized key string or null if invalid
 */
function extractActivationKey(rawPayload) {
    if (!rawPayload || typeof rawPayload !== 'string') return null;
    var trimmed = rawPayload.trim();
    if (!trimmed) return null;

    // Reject payloads containing HTML/XML tags, script injections, or unprintable control characters
    if (/[<>]|[\x00-\x1F\x7F]/.test(trimmed)) return null;

    function isValidKeyFormat(k) {
        if (!k || typeof k !== 'string') return false;
        var clean = k.trim();
        if (clean.length < 5 || clean.length > 64) return false;
        if (!/^[A-Za-z0-9_-]+$/.test(clean)) return false;
        var lower = clean.toLowerCase();
        if (/^(https?|ftp|true|false|null|undefined|wifi|mailto|tel)$/.test(lower)) return false;
        return true;
    }

    function normalizeKey(k) {
        return k.trim().toUpperCase();
    }

    // 1. Try URL parsing if it looks like a URL or has query string
    try {
        var urlObj = null;
        if (/^https?:\/\//i.test(trimmed)) {
            urlObj = new URL(trimmed);
        } else if (trimmed.indexOf('?') !== -1) {
            urlObj = new URL('http://dummy.local/' + (trimmed.startsWith('/') ? trimmed.slice(1) : trimmed));
        }

        if (urlObj && urlObj.searchParams) {
            var candidate = urlObj.searchParams.get('key') ||
                            urlObj.searchParams.get('product_key') ||
                            urlObj.searchParams.get('activation_key') ||
                            urlObj.searchParams.get('code');
            if (candidate && isValidKeyFormat(candidate)) {
                return normalizeKey(candidate);
            }
        }
    } catch (e) {}

    // 2. Query param regex fallback for malformed URLs
    var matchQuery = trimmed.match(/[?&](?:key|product_key|activation_key|code)=([A-Za-z0-9_\-]+)/i);
    if (matchQuery && matchQuery[1] && isValidKeyFormat(matchQuery[1])) {
        return normalizeKey(matchQuery[1]);
    }

    // 3. JSON payload parsing
    if (trimmed.startsWith('{') && trimmed.endsWith('}')) {
        try {
            var parsed = JSON.parse(trimmed);
            var jsonKey = parsed.key || parsed.product_key || parsed.activation_key || parsed.code;
            if (jsonKey && typeof jsonKey === 'string' && isValidKeyFormat(jsonKey)) {
                return normalizeKey(jsonKey);
            }
        } catch (e) {}
    }

    // 4. Direct key format: e.g. VL-2026-XXXX-XXXX-XXXX
    if (isValidKeyFormat(trimmed)) {
        return normalizeKey(trimmed);
    }

    // 5. Embedded VL-XXXX pattern match in descriptive label text
    // Only search embedded if text does not contain code/syntax characters
    if (!/[{}[\];'"\\$%^&*!~`+=]/.test(trimmed)) {
        var embedded = trimmed.match(/\b(VL-[A-Za-z0-9\-]+)\b/i);
        if (embedded && embedded[1] && isValidKeyFormat(embedded[1])) {
            return normalizeKey(embedded[1]);
        }
    }

    return null;
}

// Expose parser globally for automated testing
window.extractActivationKey = extractActivationKey;

(function(){
    'use strict';

    // Password strength & match controls
    var pw        = document.getElementById('password');
    var confirmPw = document.getElementById('confirm_password');
    var fill      = document.getElementById('pwStrengthFill');
    var text      = document.getElementById('pwStrengthText');
    var matchHint = document.getElementById('pwMatchHint');

    function strength(p){
        if(!p) return {pct:0,label:'',color:'transparent'};
        var isLen = p.length >= 8;
        var hasLetter = /[A-Za-z]/.test(p);
        var hasNumber = /[0-9]/.test(p);

        if (!isLen) {
            return {pct:25, label:'<span class="text-danger small fw-medium"><i class="bi bi-x-circle me-1"></i>Must be at least 8 characters long</span>', color:'#ef4444'};
        }
        if (!hasLetter || !hasNumber) {
            return {pct:50, label:'<span class="text-danger small fw-medium"><i class="bi bi-x-circle me-1"></i>Must contain both letters and numbers</span>', color:'#f59e0b'};
        }

        var s = 2;
        if (p.length >= 10) s++;
        if (/[A-Z]/.test(p) && /[a-z]/.test(p)) s++;
        if (/[^A-Za-z0-9]/.test(p)) s++;

        var m=[
            {label:'',color:'transparent',pct:0},
            {label:'Weak',color:'#ef4444',pct:20},
            {label:'Fair',color:'#f97316',pct:40},
            {label:'<span class="text-success small fw-medium"><i class="bi bi-check-circle-fill me-1"></i>Good (Alphanumeric)</span>',color:'#22c55e',pct:75},
            {label:'<span class="text-success small fw-medium"><i class="bi bi-check-circle-fill me-1"></i>Strong (Alphanumeric)</span>',color:'#10b981',pct:90},
            {label:'<span class="text-info small fw-medium"><i class="bi bi-shield-check me-1"></i>Very Strong</span>',color:'#0ea5e9',pct:100}
        ];
        return m[Math.min(s,5)];
    }

    function updateMatch() {
        if (!pw || !confirmPw) return;
        var p  = pw.value;
        var cp = confirmPw.value;
        if (!cp) {
            if (matchHint) { matchHint.innerHTML = ''; matchHint.className = 'password-match-hint'; }
            return;
        }
        var match = p.length > 0 && p === cp;
        if (matchHint) {
            if (match) {
                matchHint.innerHTML = '<span class="text-success small fw-medium"><i class="bi bi-check-circle-fill me-1"></i>Passwords match</span>';
                matchHint.className   = 'password-match-hint match-ok';
            } else {
                matchHint.innerHTML = '<span class="text-danger small fw-medium"><i class="bi bi-x-circle-fill me-1"></i>Passwords do not match</span>';
                matchHint.className   = 'password-match-hint match-fail';
            }
        }
    }

    if (pw) {
        pw.addEventListener('input', function(){
            var s = strength(this.value);
            if(fill){ fill.style.width=s.pct+'%'; fill.style.backgroundColor=s.color; }
            if(text){ text.innerHTML=s.label; text.style.color=s.color; }
            updateMatch();
        });
    }
    if (confirmPw) {
        confirmPw.addEventListener('input', updateMatch);
    }

    // ============================================================
    // QR Scanner Lifecycle & One-Tap Camera Permission Management
    // ============================================================
    var html5QrCode            = null;
    var isScanning             = false;
    var isRequestingPermission = false;
    var scanCooldown           = false;
    var permissionDenialCount  = 0;
    var isPermanentlyBlocked   = false;
    var useSimpleConstraints   = false;

    var modalEl                = document.getElementById('qrScannerModal');
    var btnOpenScanner         = document.getElementById('btnOpenScanner');
    var cameraSelect           = document.getElementById('cameraSelect');
    var cameraSelectBox        = document.getElementById('cameraSelectContainer');
    var loadingOverlay         = document.getElementById('scannerLoadingOverlay');
    var errorOverlay           = document.getElementById('scannerErrorOverlay');
    var settingsGuide          = document.getElementById('scannerSettingsGuide');
    var reticle                = document.getElementById('scannerReticle');
    var statusText             = document.getElementById('scannerStatusText');
    var feedbackBox            = document.getElementById('scannerFeedbackMsg');
    var feedbackText           = document.getElementById('scannerFeedbackText');
    var btnRetryCamera         = document.getElementById('btnRetryCamera');
    var btnEnterManualFromError= document.getElementById('btnEnterManualFromError');
    var productKeyInput        = document.getElementById('product_key');
    var successAlert           = document.getElementById('qrScanSuccessAlert');
    var successKeyText         = document.getElementById('qrScanKeyText');
    var scannerFileInput       = document.getElementById('scannerFileInput');
    var btnUploadPhotoError    = document.getElementById('btnUploadPhotoFromError');
    var btnUploadPhotoFooter   = document.getElementById('btnUploadPhotoFromFooter');

    function resetScannerUI() {
        if (loadingOverlay) loadingOverlay.classList.remove('d-none');
        if (errorOverlay)   errorOverlay.classList.add('d-none');
        if (settingsGuide)  settingsGuide.classList.add('d-none');
        if (reticle)        reticle.classList.add('d-none');
        if (feedbackBox)    feedbackBox.classList.add('d-none');
        if (btnRetryCamera) btnRetryCamera.classList.remove('d-none');
        if (statusText)     statusText.innerHTML = '<i class="bi bi-info-circle me-1 text-info"></i>Align the QR code from the ESP32 sticker inside the frame.';
    }

    /**
     * Categorizes camera errors with specialized titles, messages, and recovery actions.
     * Handled types:
     * - NOT_ALLOWED: NotAllowedError / PermissionDeniedError (permission denied or blocked)
     * - NOT_FOUND: NotFoundError / DevicesNotFoundError (no camera hardware)
     * - NOT_READABLE: NotReadableError / TrackStartError (camera in use by another app)
     * - OVERCONSTRAINED: OverconstrainedError (rear-facing mode unsupported)
     * - INSECURE_OR_UNSUPPORTED: mediaDevices missing, HTTP non-localhost origin
     *
     * @param {Error|object|string} err
     * @return {object} Error details object
     */
    function categorizeCameraError(err) {
        var errName = (err && err.name) ? String(err.name) : '';
        var errStr  = ((err && err.message) ? err.message : String(err || '')).toLowerCase();

        // 1. Insecure context or mediaDevices unsupported
        if (errName === 'InsecureContextError' ||
            errName === 'NotSupportedError' ||
            !navigator.mediaDevices ||
            !navigator.mediaDevices.getUserMedia ||
            /insecure context|secure context|not supported|mediadevices/i.test(errStr)) {
            return {
                type: 'INSECURE_OR_UNSUPPORTED',
                title: 'HTTPS Required or Browser Unsupported',
                message: 'Camera access requires a secure connection (HTTPS or localhost) and a supported modern browser. Please enter your Activation Key manually.',
                canRetry: false,
                showSettingsGuide: false
            };
        }

        // 2. Permission denied or blocked (NotAllowedError)
        if (errName === 'NotAllowedError' || errName === 'PermissionDeniedError' || /notallowederror|permission denied|denied/i.test(errStr)) {
            var isBlocked = (permissionDenialCount >= 1 || isPermanentlyBlocked);
            return {
                type: 'NOT_ALLOWED',
                title: 'Camera Permission Denied',
                message: isBlocked
                    ? 'Camera access is blocked by your browser settings. Follow the instructions below to enable camera access, or type your Activation Key manually.'
                    : 'Camera access was denied. Tap "Try Again" to grant camera permission, or enter your Activation Key manually.',
                canRetry: true,
                showSettingsGuide: isBlocked
            };
        }

        // 3. No camera detected (NotFoundError)
        if (errName === 'NotFoundError' || errName === 'DevicesNotFoundError' || /notfounderror|no camera|devices not found/i.test(errStr)) {
            return {
                type: 'NOT_FOUND',
                title: 'No Camera Detected',
                message: 'No video camera was found on your device. Please enter your Activation Key manually.',
                canRetry: false,
                showSettingsGuide: false
            };
        }

        // 4. Camera busy or already in use (NotReadableError)
        if (errName === 'NotReadableError' || errName === 'TrackStartError' || /notreadableerror|trackstart|in use|could not start/i.test(errStr)) {
            return {
                type: 'NOT_READABLE',
                title: 'Camera In Use',
                message: 'The camera is currently in use by another application or tab. Please close other camera apps and tap "Try Again".',
                canRetry: true,
                showSettingsGuide: false
            };
        }

        // 5. Overconstrained / requested configuration unavailable (OverconstrainedError)
        if (errName === 'OverconstrainedError' || errName === 'ConstraintNotSatisfiedError' || /overconstrainederror|constraint/i.test(errStr)) {
            return {
                type: 'OVERCONSTRAINED',
                title: 'Camera Mode Unavailable',
                message: 'The requested camera configuration is unavailable. Tap "Try Again" to use the default camera.',
                canRetry: true,
                showSettingsGuide: false
            };
        }

        // Generic fallback
        return {
            type: 'UNKNOWN',
            title: 'Camera Access Error',
            message: (err && err.message) ? err.message : 'Unable to access the camera preview. Please enter the key manually.',
            canRetry: true,
            showSettingsGuide: false
        };
    }

    function handleCameraError(err) {
        var cat = categorizeCameraError(err);

        if (cat.type === 'NOT_ALLOWED') {
            permissionDenialCount++;
        }

        if (loadingOverlay) loadingOverlay.classList.add('d-none');
        if (reticle)        reticle.classList.add('d-none');
        if (errorOverlay)   errorOverlay.classList.remove('d-none');

        var t = document.getElementById('scannerErrorTitle');
        var m = document.getElementById('scannerErrorMessage');
        if (t) t.textContent = cat.title;
        if (m) m.textContent = cat.message;

        if (settingsGuide) {
            if (cat.showSettingsGuide || permissionDenialCount >= 2 || isPermanentlyBlocked) {
                settingsGuide.classList.remove('d-none');
            } else {
                settingsGuide.classList.add('d-none');
            }
        }

        if (btnRetryCamera) {
            if (cat.canRetry) {
                btnRetryCamera.classList.remove('d-none');
            } else {
                btnRetryCamera.classList.add('d-none');
            }
        }

        if (statusText) {
            if (cat.type === 'NOT_ALLOWED') {
                statusText.innerHTML = '<i class="bi bi-shield-x text-warning me-1"></i>Camera permission blocked. Check browser settings.';
            } else {
                statusText.innerHTML = '<i class="bi bi-exclamation-circle text-danger me-1"></i>Scanner unavailable.';
            }
        }
    }

    function populateCameraSelect(cameras, selectedId) {
        if (!cameraSelect || !cameraSelectBox) return;
        cameraSelect.innerHTML = '';

        if (!cameras || cameras.length <= 1) {
            cameraSelectBox.classList.add('d-none');
            return;
        }

        cameras.forEach(function(cam, idx) {
            var opt = document.createElement('option');
            opt.value = cam.id;
            opt.textContent = cam.label || ('Camera ' + (idx + 1));
            if (selectedId && cam.id === selectedId) {
                opt.selected = true;
            } else if (!selectedId && /back|rear|environment/i.test(cam.label)) {
                opt.selected = true;
            }
            cameraSelect.appendChild(opt);
        });

        cameraSelectBox.classList.remove('d-none');
    }

    /**
     * Initializes Html5Qrcode and begins video decoding.
     * Called automatically once camera permission is granted.
     */
    function initAndStartHtml5Scanner(preferredCameraId) {
        if (isScanning) return;

        if (typeof Html5Qrcode === 'undefined') {
            handleCameraError({ name: 'ScannerLibraryUnavailable', message: 'The QR scanning module could not be loaded.' });
            return;
        }

        if (!html5QrCode) {
            try {
                html5QrCode = new Html5Qrcode("qrReader");
            } catch (e) {
                handleCameraError(e);
                return;
            }
        }

        var config = {
            fps: 10,
            qrbox: function(viewfinderWidth, viewfinderHeight) {
                var minEdge = Math.min(viewfinderWidth, viewfinderHeight);
                var edge = Math.floor(minEdge * 0.72);
                return { width: Math.max(edge, 180), height: Math.max(edge, 180) };
            },
            aspectRatio: 1.0,
            experimentalFeatures: {
                useBarCodeDetectorIfSupported: true
            }
        };

        function startWithCamera(cameraConfig) {
            return html5QrCode.start(
                cameraConfig,
                config,
                onScanSuccess,
                onScanFailure
            ).then(function() {
                isScanning = true;
                if (loadingOverlay) loadingOverlay.classList.add('d-none');
                if (reticle)        reticle.classList.remove('d-none');
                if (statusText)     statusText.innerHTML = '<i class="bi bi-camera me-1 text-success"></i>Camera active. Position the packaging sticker QR code in the box.';
            }).catch(function(err) {
                // If environment failed, try user/front facing camera fallback
                if (cameraConfig && (cameraConfig === "environment" || (typeof cameraConfig === 'object' && cameraConfig.facingMode === "environment"))) {
                    return html5QrCode.start(
                        { facingMode: "user" },
                        config,
                        onScanSuccess,
                        onScanFailure
                    ).then(function() {
                        isScanning = true;
                        if (loadingOverlay) loadingOverlay.classList.add('d-none');
                        if (reticle)        reticle.classList.remove('d-none');
                        if (statusText)     statusText.innerHTML = '<i class="bi bi-camera me-1 text-success"></i>Camera active. Position the packaging sticker QR code in the box.';
                    }).catch(function(err2) {
                        handleCameraError(err2);
                    });
                }
                handleCameraError(err);
            });
        }

        // Enumerate cameras (labels will be populated because permission is already granted)
        Html5Qrcode.getCameras().then(function(cameras) {
            if (cameras && cameras.length > 0) {
                populateCameraSelect(cameras, preferredCameraId);
                var camConfig = preferredCameraId ? preferredCameraId : { facingMode: "environment" };
                startWithCamera(camConfig);
            } else {
                startWithCamera({ facingMode: "environment" });
            }
        }).catch(function() {
            startWithCamera({ facingMode: "environment" });
        });
    }

    /**
     * Request camera permission immediately in response to user click, then start scanner automatically.
     * Prefers rear-facing camera on mobile ({ facingMode: { ideal: "environment" } }).
     *
     * @param {boolean} isRetry True if invoked from Try Again button
     */
    function requestCameraAndStartScanner(isRetry) {
        if (isScanning) return;
        if (isRequestingPermission && !isRetry) return;

        isRequestingPermission = true;
        resetScannerUI();

        // 1. Verify mediaDevices support
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            isRequestingPermission = false;
            handleCameraError({ name: 'InsecureContextError' });
            return;
        }

        // 2. Query Permissions API if supported
        if (navigator.permissions && navigator.permissions.query) {
            try {
                navigator.permissions.query({ name: 'camera' }).then(function(pStatus) {
                    if (pStatus.state === 'denied') {
                        isPermanentlyBlocked = true;
                    }
                    pStatus.onchange = function() {
                        if (pStatus.state === 'granted') {
                            isPermanentlyBlocked = false;
                            if (settingsGuide) settingsGuide.classList.add('d-none');
                            if (modalEl && modalEl.classList.contains('show') && !isScanning) {
                                requestCameraAndStartScanner(true);
                            }
                        }
                    };
                }).catch(function() {});
            } catch (e) {}
        }

        // 3. Request camera access immediately with user gesture token
        var constraints = useSimpleConstraints
            ? { video: true }
            : { video: { facingMode: { ideal: "environment" } } };

        navigator.mediaDevices.getUserMedia(constraints).then(function(stream) {
            isRequestingPermission = false;
            permissionDenialCount  = 0;
            isPermanentlyBlocked   = false;

            // Stop probe stream tracks so Html5Qrcode can bind to the camera cleanly
            if (stream && stream.getTracks) {
                stream.getTracks().forEach(function(track) {
                    track.stop();
                });
            }

            // Allow Android Camera2 service 200ms to cleanly release sensor before Html5Qrcode binds
            setTimeout(function() {
                initAndStartHtml5Scanner();
            }, 200);
        }).catch(function(err) {
            isRequestingPermission = false;

            // Handle overconstrained fallback: retry with simple { video: true }
            if ((err && (err.name === 'OverconstrainedError' || err.name === 'ConstraintNotSatisfiedError')) && !useSimpleConstraints) {
                useSimpleConstraints = true;
                requestCameraAndStartScanner(true);
                return;
            }

            handleCameraError(err);
        });
    }

    function stopScanner() {
        isRequestingPermission = false;
        var p = Promise.resolve();
        if (html5QrCode && isScanning) {
            isScanning = false;
            p = html5QrCode.stop().then(function() {
                return html5QrCode.clear();
            }).catch(function(err) {
                console.warn("QR scanner stop error:", err);
            });
        }
        // Force stop any lingering MediaStream tracks directly on video elements
        try {
            var videoEls = document.querySelectorAll('#qrReader video');
            videoEls.forEach(function(v) {
                if (v && v.srcObject && v.srcObject.getTracks) {
                    v.srcObject.getTracks().forEach(function(track) { track.stop(); });
                }
            });
        } catch (e) {}
        return p;
    }

    function showTemporaryFeedback(msg) {
        if (feedbackBox && feedbackText) {
            feedbackText.textContent = msg;
            feedbackBox.classList.remove('d-none');
            setTimeout(function() {
                feedbackBox.classList.add('d-none');
            }, 3000);
        }
    }

    function onScanSuccess(decodedText) {
        if (scanCooldown) return;
        scanCooldown = true;

        var key = extractActivationKey(decodedText);
        if (!key) {
            showTemporaryFeedback("Scanned QR code does not contain a valid VendoLink activation key. Please scan the packaging sticker.");
            setTimeout(function() { scanCooldown = false; }, 2000);
            return;
        }

        // Successfully decoded a valid activation key
        if (productKeyInput) {
            productKeyInput.value = key;
            productKeyInput.dispatchEvent(new Event('input', { bubbles: true }));
            productKeyInput.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // Stop camera and dismiss modal
        stopScanner().then(function() {
            var modalInstance = window.bootstrap ? bootstrap.Modal.getInstance(modalEl) : null;
            if (modalInstance) {
                modalInstance.hide();
            }

            // Display prominent success alert on the registration form
            if (successAlert) {
                if (successKeyText) {
                    successKeyText.textContent = key;
                }
                successAlert.classList.remove('d-none');
                successAlert.classList.add('d-flex');
                successAlert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            // Optional mobile haptic vibration
            if (navigator.vibrate) {
                try { navigator.vibrate(80); } catch (e) {}
            }
        }).finally(function() {
            scanCooldown = false;
        });
    }

    function onScanFailure() {
        // Ignored during normal frame search
    }

    // ============================================================
    // Event Listeners & Lifecycle Controls
    // ============================================================

    // 1. Scan QR Code button: Immediately requests camera permission and shows modal
    if (btnOpenScanner && modalEl) {
        btnOpenScanner.addEventListener('click', function() {
            var bsModal = window.bootstrap ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;
            if (bsModal) {
                bsModal.show();
            }
            requestCameraAndStartScanner(false);
        });
    }

    // 2. Modal shown/hidden lifecycle
    if (modalEl) {
        modalEl.addEventListener('shown.bs.modal', function() {
            if (!isScanning && !isRequestingPermission) {
                requestCameraAndStartScanner(false);
            }
        });

        modalEl.addEventListener('hidden.bs.modal', function() {
            stopScanner();
            resetScannerUI();
        });
    }

    // 3. Camera Switch Listener
    if (cameraSelect) {
        cameraSelect.addEventListener('change', function() {
            var newId = this.value;
            stopScanner().then(function() {
                initAndStartHtml5Scanner(newId);
            });
        });
    }

    // 4. Try Again Button Listener: retries permission request immediately
    if (btnRetryCamera) {
        btnRetryCamera.addEventListener('click', function() {
            requestCameraAndStartScanner(true);
        });
    }

    // 5. Enter Key Manually from error overlay: closes modal and focuses input
    if (btnEnterManualFromError) {
        btnEnterManualFromError.addEventListener('click', function() {
            stopScanner().then(function() {
                var modalInstance = window.bootstrap ? bootstrap.Modal.getInstance(modalEl) : null;
                if (modalInstance) modalInstance.hide();
                if (productKeyInput) {
                    productKeyInput.focus();
                }
            });
        });
    }

    // Native Camera / Photo Upload Fallback (Works even when live camera stream is blocked by browser)
    function triggerPhotoScan() {
        if (scannerFileInput) {
            scannerFileInput.click();
        }
    }

    if (btnUploadPhotoError) {
        btnUploadPhotoError.addEventListener('click', triggerPhotoScan);
    }
    if (btnUploadPhotoFooter) {
        btnUploadPhotoFooter.addEventListener('click', triggerPhotoScan);
    }

    if (scannerFileInput) {
        scannerFileInput.addEventListener('change', function(e) {
            var file = e.target.files && e.target.files[0];
            if (!file) return;

            if (!html5QrCode) {
                try {
                    html5QrCode = new Html5Qrcode("qrReader");
                } catch (err) {
                    handleCameraError(err);
                    return;
                }
            }

            if (statusText) {
                statusText.innerHTML = '<span class="spinner-border spinner-border-sm me-1 text-info"></span>Scanning image for QR code...';
            }

            html5QrCode.scanFile(file, false)
                .then(function(decodedText) {
                    onScanSuccess(decodedText);
                })
                .catch(function(err) {
                    showTemporaryFeedback("Could not find a valid QR code in that photo. Please take a clear, close-up photo of the sticker.");
                    if (statusText) {
                        statusText.innerHTML = '<i class="bi bi-exclamation-triangle text-warning me-1"></i>No QR code detected in photo. Try another photo.';
                    }
                })
                .finally(function() {
                    scannerFileInput.value = '';
                });
        });
    }

    // 6. Guaranteed camera shutdown on tab close or navigation
    window.addEventListener('beforeunload', function() {
        stopScanner();
    });
    window.addEventListener('pagehide', function() {
        stopScanner();
    });

    // Expose helpers globally for testing & diagnostics
    window.categorizeCameraError = categorizeCameraError;
    window.requestCameraAndStartScanner = requestCameraAndStartScanner;

})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>