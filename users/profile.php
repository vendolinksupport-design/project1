<?php
/**
 * ============================================================
 * VendoLink - My Profile
 * ============================================================
 *
 * Displays the logged-in user account information and
 * provides an inline self-service Change Password form.
 *
 * @package VendoLink
 * @version 1.1
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/User.php';

require_auth();

$userModel   = new User();
$profileUser = $userModel->findById((int)$_SESSION['user_id']);

if (!$profileUser) {
    flash('danger', 'User profile not found.');
    redirect('dashboard/index.php');
}

// ---- Handle POST actions (Change Password & Email Verification / Change) ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? 'change_password';

    // 1. Resend verification link for current unverified email
    if ($action === 'resend_verification') {
        require_once __DIR__ . '/../controllers/AuthController.php';
        $authCtrl   = new AuthController();

        $rawToken   = $userModel->createEmailVerifyToken((int)$profileUser['id']);
        $verifyLink = BASE_URL . '/auth/verify_email.php?token=' . urlencode($rawToken);

        $authCtrl->sendVerificationEmail($profileUser['email'], $profileUser['full_name'], $verifyLink);

        log_activity('VERIFICATION_EMAIL_SENT', 'Sent email verification link.', (int)$profileUser['id']);
        flash('success', 'Verification link sent to ' . e($profileUser['email']) . '! Please check your inbox.');
        redirect('users/profile.php');
    }

    // 2. Request Email Change (Step 1)
    if ($action === 'request_email_change') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newEmail        = trim($_POST['new_email'] ?? '');

        if (empty($currentPassword) || empty($newEmail)) {
            $_SESSION['open_email_modal'] = true;
            flash('danger', 'Please enter your new email and current password.');
            redirect('users/profile.php#security');
        }

        if (!$userModel->verifyPassword($currentPassword, $profileUser['password'])) {
            $_SESSION['open_email_modal'] = true;
            flash('danger', 'Your current password was incorrect. Please try again.');
            log_activity('EMAIL_CHANGE_FAILED', 'Incorrect current password provided for email change.', (int)$profileUser['id']);
            redirect('users/profile.php#security');
        }

        if (!validate_email($newEmail)) {
            $_SESSION['open_email_modal'] = true;
            flash('danger', 'Please enter a valid email address.');
            redirect('users/profile.php#security');
        }

        if (strtolower($newEmail) === strtolower($profileUser['email'])) {
            $_SESSION['open_email_modal'] = true;
            flash('danger', 'New email cannot be the same as your current email.');
            redirect('users/profile.php#security');
        }

        $existingUser = $userModel->findByEmail($newEmail);
        if ($existingUser && (int)$existingUser['id'] !== (int)$profileUser['id']) {
            $_SESSION['open_email_modal'] = true;
            flash('danger', 'That email address is already in use by another account.');
            redirect('users/profile.php#security');
        }

        // Generate 6-digit OTP and store pending request in session
        $otp = sprintf('%06d', random_int(0, 999999));
        $_SESSION['pending_email_change'] = [
            'user_id'    => (int)$profileUser['id'],
            'new_email'  => strtolower($newEmail),
            'otp_hash'   => hash('sha256', $otp),
            'expires_at' => time() + 300, // 5 minutes
            'sent_at'    => time(),
            'attempts'   => 0,
        ];

        // Send OTP email using existing AuthController email service
        require_once __DIR__ . '/../controllers/AuthController.php';
        $authCtrl = new AuthController();
        $authCtrl->sendEmailChangeOtp(strtolower($newEmail), $profileUser['full_name'], $otp);

        log_activity('EMAIL_CHANGE_REQUESTED', 'Requested email change to ' . mask_email($newEmail), (int)$profileUser['id']);
        flash('info', 'Verification code sent to ' . e(mask_email($newEmail)) . '. Please enter the 6-digit code below to confirm.');
        $_SESSION['open_email_modal'] = true;
        redirect('users/profile.php#security');
    }

    // 3. Verify Email Change with 6-digit OTP (Step 2)
    if ($action === 'verify_email_change') {
        if (empty($_SESSION['pending_email_change']) || ($_SESSION['pending_email_change']['user_id'] !== (int)$profileUser['id'])) {
            // If email change was already completed by an immediate prior request (double-submit / rapid retry)
            if (!empty($_SESSION['email_change_completed'])) {
                unset($_SESSION['email_change_completed']);
                redirect('users/profile.php');
            }
            flash('danger', 'No pending email change request found or session expired.');
            redirect('users/profile.php#security');
        }

        $pending = &$_SESSION['pending_email_change'];
        $attempts = ($pending['attempts'] ?? 0) + 1;
        $pending['attempts'] = $attempts;

        if ($attempts > 5) {
            unset($_SESSION['pending_email_change']);
            flash('danger', 'Maximum verification attempts exceeded. Please submit a new email change request.');
            redirect('users/profile.php#security');
        }

        if (time() > $pending['expires_at']) {
            $_SESSION['open_email_modal'] = true;
            flash('danger', 'The verification code has expired. Please click "Resend Code".');
            redirect('users/profile.php#security');
        }

        $otp = trim($_POST['otp'] ?? '');
        $hashedInput = hash('sha256', $otp);

        if (!hash_equals($pending['otp_hash'], $hashedInput)) {
            $_SESSION['open_email_modal'] = true;
            log_activity('EMAIL_CHANGE_FAILED', 'Incorrect OTP entered for email change.', (int)$profileUser['id']);
            $remaining = 5 - $attempts;
            flash('danger', "Invalid verification code. {$remaining} attempt(s) remaining.");
            redirect('users/profile.php#security');
        }

        // Verification successful — apply update to database & active session!
        $newEmail = $pending['new_email'];
        $oldEmail = $profileUser['email'];

        $userModel->updateEmail((int)$profileUser['id'], $newEmail, true);
        $_SESSION['email'] = $newEmail;
        $_SESSION['email_change_completed'] = true;
        unset($_SESSION['pending_email_change']);
        unset($_SESSION['open_email_modal']);

        log_activity('EMAIL_CHANGED', "User changed email address from {$oldEmail} to {$newEmail}.", (int)$profileUser['id']);
        flash('success', 'Your email address has been successfully updated to ' . e($newEmail) . '!');
        redirect('users/profile.php');
    }

    // 4. Resend Email Change OTP
    if ($action === 'resend_email_change_otp') {
        if (empty($_SESSION['pending_email_change']) || ($_SESSION['pending_email_change']['user_id'] !== (int)$profileUser['id'])) {
            flash('danger', 'No pending email change request found.');
            redirect('users/profile.php#security');
        }

        $elapsed = time() - ($_SESSION['pending_email_change']['sent_at'] ?? 0);
        if ($elapsed < 60) {
            $wait = 60 - $elapsed;
            $_SESSION['open_email_modal'] = true;
            flash('warning', "Please wait {$wait} second(s) before requesting a new code.");
            redirect('users/profile.php#security');
        }

        $otp = sprintf('%06d', random_int(0, 999999));
        $_SESSION['pending_email_change']['otp_hash']   = hash('sha256', $otp);
        $_SESSION['pending_email_change']['expires_at'] = time() + 300;
        $_SESSION['pending_email_change']['sent_at']    = time();
        $_SESSION['pending_email_change']['attempts']   = 0;

        require_once __DIR__ . '/../controllers/AuthController.php';
        $authCtrl = new AuthController();
        $authCtrl->sendEmailChangeOtp($_SESSION['pending_email_change']['new_email'], $profileUser['full_name'], $otp);

        log_activity('EMAIL_CHANGE_OTP_RESENT', 'Resent email change OTP code.', (int)$profileUser['id']);
        flash('success', 'A new 6-digit code has been sent to ' . e(mask_email($_SESSION['pending_email_change']['new_email'])));
        $_SESSION['open_email_modal'] = true;
        redirect('users/profile.php#security');
    }

    // 5. Cancel Email Change Request
    if ($action === 'cancel_email_change') {
        unset($_SESSION['pending_email_change']);
        unset($_SESSION['open_email_modal']);
        flash('info', 'Email change request cancelled.');
        redirect('users/profile.php#security');
    }

    // 6. Change Password Action
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword     = $_POST['new_password']     ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $_SESSION['open_password_modal'] = true;
        flash('danger', 'Please fill in all password fields.');
        redirect('users/profile.php#security');
    }

    if (!$userModel->verifyPassword($currentPassword, $profileUser['password'])) {
        $_SESSION['open_password_modal'] = true;
        flash('danger', 'Your current password is incorrect.');
        log_activity('PASSWORD_CHANGE_FAILED', 'Incorrect current password on profile page.', (int)$profileUser['id']);
        redirect('users/profile.php#security');
    }

    if ($userModel->verifyPassword($newPassword, $profileUser['password'])) {
        $_SESSION['open_password_modal'] = true;
        flash('danger', 'New password cannot be the same as your current password.');
        redirect('users/profile.php#security');
    }

    if (strlen($newPassword) < 8) {
        $_SESSION['open_password_modal'] = true;
        flash('danger', 'New password must be at least 8 characters.');
        redirect('users/profile.php#security');
    }

    if ($newPassword !== $confirmPassword) {
        $_SESSION['open_password_modal'] = true;
        flash('danger', 'New password and confirmation do not match.');
        redirect('users/profile.php#security');
    }

    $userModel->updatePassword((int)$profileUser['id'], $newPassword);
    csrf_regenerate();
    log_activity('PASSWORD_CHANGED', 'User changed their own password from My Profile.', (int)$profileUser['id']);

    flash('success', 'Password updated successfully!');
    redirect('users/profile.php');
}

// ---- Formatted display values ----
$createdAt   = !empty($profileUser['created_at'])  ? date('F j, Y', strtotime($profileUser['created_at']))              : 'N/A';
$createdAtShort = !empty($profileUser['created_at']) ? date('M j, Y', strtotime($profileUser['created_at']))            : 'N/A';
$lastLogin   = !empty($profileUser['last_login'])   ? date('M j, Y \a\t g:i A', strtotime($profileUser['last_login']))  : 'Never';
$lastLoginShort = !empty($profileUser['last_login']) ? date('M j, g:i A', strtotime($profileUser['last_login']))        : 'Never';
$lastUpdated = !empty($profileUser['updated_at'])   ? date('M j, Y', strtotime($profileUser['updated_at']))             : 'N/A';
$emailVerified = (bool)($profileUser['email_verified'] ?? false);

$avatarInitial = strtoupper(mb_substr(
    $profileUser['full_name'] ?: $profileUser['username'] ?: 'U', 0, 1
));

$statusMap  = [
    'active'   => ['label' => 'Active',   'cls' => 'profile-status-active'],
    'inactive' => ['label' => 'Inactive', 'cls' => 'profile-status-inactive'],
    'banned'   => ['label' => 'Banned',   'cls' => 'profile-status-banned'],
];
$statusInfo = $statusMap[$profileUser['status']] ?? ['label' => ucfirst($profileUser['status']), 'cls' => 'profile-status-inactive'];

$roleColorMap = [
    'administrator' => '#f87171',
    'owner'         => '#fbbf24',
    'staff'         => '#a5b4fc',
    'technician'    => '#38bdf8',
];
$roleColor = $roleColorMap[$profileUser['role']] ?? '#e2e8f0';

// ---- Modal auto-open state ----
$openPasswordModal     = !empty($_SESSION['open_password_modal']);
unset($_SESSION['open_password_modal']);

// Auto-clean expired pending email changes (older than 5 minutes)
if (!empty($_SESSION['pending_email_change'])) {
    if (time() > ($_SESSION['pending_email_change']['expires_at'] ?? 0)) {
        unset($_SESSION['pending_email_change']);
    }
}

$hasPendingEmailChange = !empty($_SESSION['pending_email_change']) && ((int)$_SESSION['pending_email_change']['user_id'] === (int)$profileUser['id']);
$pendingNewEmail       = $hasPendingEmailChange ? $_SESSION['pending_email_change']['new_email'] : '';

// Only auto-open modal if explicitly requested by a fresh form redirect, NEVER merely because a pending change exists!
$openEmailModal        = !empty($_SESSION['open_email_modal']);
unset($_SESSION['open_email_modal']);

$pageTitle   = 'My Profile';
$currentPage = 'profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= APP_NAME ?> My Profile">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> &mdash; <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/app.css?v=<?= e((string) filemtime(ROOT_PATH . '/assets/css/app.css')) ?>">
</head>
<body class="dashboard-body">
<div class="app-shell">

    <?php require_once __DIR__ . '/../views/layouts/sidebar.php'; ?>

    <div class="main-content" id="mainContent">
        <?php require_once __DIR__ . '/../views/layouts/topnav.php'; ?>

        <main class="page-content" id="pageContent">

            <?php if ($flashHtml = render_flash()): ?>
            <div class="flash-container mb-3"><?= $flashHtml ?></div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="page-header mb-4">
                <div>
                    <h1 class="page-title"><i class="bi bi-person-circle me-2"></i>My Profile</h1>
                    <p class="page-subtitle">Account details and security settings.</p>
                </div>
                <div class="page-header-actions">
                    <a href="<?= BASE_URL ?>/dashboard/index.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-speedometer2 me-1"></i>Dashboard
                    </a>
                </div>
            </div>

            <div class="profile-container">
                <!-- ===== IDENTITY STRIP — full width ===== -->
                <div class="panel vl-profile-panel identity-strip mb-4">
                    <div class="identity-left">
                        <div class="avatar-wrap">
                            <div class="avatar-lg profile-avatar-large" aria-label="Profile initial">
                                <?= $avatarInitial ?>
                            </div>
                            <span class="status-ring" title="<?= $statusInfo['label'] ?>"></span>
                        </div>
                        <div>
                            <div class="identity-name profile-full-name"><?= e($profileUser['full_name']) ?></div>
                            <div class="identity-handle profile-username">@<?= e($profileUser['username']) ?></div>
                            <div class="identity-badges profile-badges">
                                <span class="badge owner profile-role-badge">
                                    <i class="bi bi-shield-check me-1"></i><?= ucfirst(e($profileUser['role'])) ?>
                                </span>
                                <span class="badge active <?= $statusInfo['cls'] ?>">
                                    <i class="bi bi-circle-fill me-1" style="font-size:.45rem;vertical-align:middle;"></i><?= $statusInfo['label'] ?>
                                </span>
                                <?php if ($emailVerified): ?>
                                    <span class="badge verified"><i class="bi bi-patch-check-fill me-1"></i>Email verified</span>
                                <?php else: ?>
                                    <span class="badge warn"><i class="bi bi-exclamation-triangle-fill me-1"></i>Email unverified</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="identity-meta">
                        <div class="meta-chip">
                            <div class="m-label">Member since</div>
                            <div class="m-val"><?= e($createdAtShort) ?></div>
                        </div>
                        <div class="meta-chip">
                            <div class="m-label">Last login</div>
                            <div class="m-val"><?= e($lastLoginShort) ?></div>
                        </div>
                    </div>
                </div>

                <!-- ===== MAIN GRID: two equal wide columns ===== -->
                <div class="layout profile-page-grid">
                    <!-- LEFT: Account information -->
                    <div class="panel vl-profile-panel">
                        <div class="panel-head vl-profile-panel-heading">
                            <h2><i class="bi bi-person-lines-fill me-2"></i>Account information</h2>
                        </div>
                        <div class="field-grid profile-detail-grid">
                            <div class="field-cell profile-detail-row">
                                <div class="field-label profile-detail-label"><i class="bi bi-person me-2"></i>Full name</div>
                                <div class="field-value profile-detail-value"><?= e($profileUser['full_name']) ?></div>
                            </div>
                            <div class="field-cell profile-detail-row">
                                <div class="field-label profile-detail-label"><i class="bi bi-at me-2"></i>Username</div>
                                <div class="field-value mono profile-detail-value profile-mono">@<?= e($profileUser['username']) ?></div>
                            </div>
                            <div class="field-cell profile-detail-row">
                                <div class="field-label profile-detail-label"><i class="bi bi-envelope me-2"></i>Email</div>
                                <div class="field-value mono profile-detail-value profile-mono" style="word-break:break-all;"><?= e($profileUser['email']) ?></div>
                            </div>
                            <div class="field-cell profile-detail-row">
                                <div class="field-label profile-detail-label"><i class="bi bi-shield me-2"></i>Role</div>
                                <div class="field-value accent profile-detail-value profile-role-text"><?= ucfirst(e($profileUser['role'])) ?></div>
                            </div>
                            <div class="field-cell profile-detail-row">
                                <div class="field-label profile-detail-label"><i class="bi bi-toggle-on me-2"></i>Status</div>
                                <div class="field-value profile-detail-value">
                                    <span class="badge active <?= $statusInfo['cls'] ?>">
                                        <i class="bi bi-circle-fill me-1" style="font-size:.45rem;vertical-align:middle;"></i><?= $statusInfo['label'] ?>
                                    </span>
                                </div>
                            </div>
                            <div class="field-cell profile-detail-row">
                                <div class="field-label profile-detail-label"><i class="bi bi-patch-check me-2"></i>Email verified</div>
                                <div class="field-value profile-detail-value">
                                    <?php if ($emailVerified): ?>
                                        <span class="badge verified"><i class="bi bi-patch-check-fill me-1"></i>Verified</span>
                                    <?php else: ?>
                                        <span class="badge warn"><i class="bi bi-exclamation-triangle-fill me-1"></i>Not Verified</span>
                                        <form method="POST" action="profile.php" class="d-inline-block ms-2">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="resend_verification">
                                            <button type="submit" class="btn btn-sm btn-outline-warning py-0 px-2" style="font-size:.72rem;">
                                                <i class="bi bi-send me-1"></i>Verify Now
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="field-cell profile-detail-row">
                                <div class="field-label profile-detail-label"><i class="bi bi-calendar-plus me-2"></i>Member since</div>
                                <div class="field-value profile-detail-value"><?= e($createdAt) ?></div>
                            </div>
                            <div class="field-cell profile-detail-row profile-detail-row--last">
                                <div class="field-label profile-detail-label"><i class="bi bi-clock-history me-2"></i>Last login</div>
                                <div class="field-value profile-detail-value"><?= e($lastLogin) ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT: Account security -->
                    <div class="panel vl-profile-panel" id="security">
                        <div class="panel-head vl-profile-panel-heading d-flex align-items-center justify-content-between">
                            <h2><i class="bi bi-shield-lock-fill me-2"></i>Account security</h2>
                            <span class="badge protected"><i class="bi bi-shield-check me-1"></i>Protected</span>
                        </div>
                        <div class="sec-body">
                            <div class="security-cards-stack">
                                <!-- 1. Password Security Card -->
                                <div class="sec-item security-card">
                                    <div class="sec-top security-card-main">
                                        <div class="security-card-icon security-card-icon--key">
                                            <i class="bi bi-key-fill"></i>
                                        </div>
                                        <div class="security-card-info">
                                            <div class="sec-title-row security-card-title">
                                                <span>Password</span>
                                                <span class="badge set security-badge security-badge--ok"><i class="bi bi-check-circle-fill me-1"></i>Set</span>
                                            </div>
                                            <div class="sec-detail security-card-meta">
                                                <span>Last changed: <?= e($lastUpdated) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="security-card-action">
                                        <button type="button" class="btn btn-action" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                                             <i class="bi bi-pencil-square me-1"></i>Change password
                                        </button>
                                    </div>
                                </div>

                                <!-- 2. Email Security Card -->
                                <div class="sec-item security-card">
                                    <div class="sec-top security-card-main">
                                        <div class="security-card-icon security-card-icon--mail">
                                            <i class="bi bi-envelope-fill"></i>
                                        </div>
                                        <div class="security-card-info">
                                            <div class="sec-title-row security-card-title">
                                                <span>Email</span>
                                                <?php if ($emailVerified): ?>
                                                    <span class="badge verified security-badge security-badge--ok"><i class="bi bi-patch-check-fill me-1"></i>Verified</span>
                                                <?php else: ?>
                                                    <span class="badge warn security-badge security-badge--warn"><i class="bi bi-exclamation-triangle-fill me-1"></i>Not Verified</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="sec-detail mono security-card-meta">
                                                <span class="font-monospace" style="word-break:break-all;"><?= e($profileUser['email']) ?></span>
                                                <?php if (!$emailVerified): ?>
                                                    <form method="POST" action="profile.php" class="d-inline-block ms-1">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="resend_verification">
                                                        <button type="submit" class="btn btn-sm btn-outline-warning py-0 px-2" style="font-size:.7rem;">
                                                            <i class="bi bi-send me-1"></i>Verify Now
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($hasPendingEmailChange): ?>
                                                    <div class="mt-1 small" style="color:var(--vl-info, #38bdf8); font-size:0.75rem;">
                                                        <i class="bi bi-clock-history me-1"></i>Verification pending for <span class="font-monospace text-info"><?= e($pendingNewEmail) ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="security-card-action">
                                        <button type="button" class="btn btn-action" data-bs-toggle="modal" data-bs-target="#changeEmailModal">
                                            <i class="bi bi-pencil-square me-1"></i>Change email
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== RECOMMENDATIONS — full width beneath both ===== -->
                <div class="panel reco vl-profile-panel mt-4">
                    <div class="security-overview-box">
                        <div class="reco-head security-overview-title">
                            <i class="bi bi-shield-check me-2"></i>Security recommendations
                        </div>
                        <div class="reco-grid security-overview-list">
                            <div class="reco-item security-overview-item">
                                <i class="bi bi-check2-circle"></i>
                                <p>Use a strong, unique password of at least 8 characters with letters, numbers, and symbols.</p>
                            </div>
                            <div class="reco-item security-overview-item">
                                <i class="bi bi-shield-lock"></i>
                                <p>Changing your email requires your current password and a 6-digit one-time confirmation code.</p>
                            </div>
                            <div class="reco-item security-overview-item">
                                <i class="bi bi-activity"></i>
                                <p>All password and email changes are recorded in the system audit logs for maximum security.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div><!-- /.profile-container -->

        </main>
        
        <!-- Page Footer -->
        <footer class="app-footer">
            <span><?= APP_NAME ?> v<?= APP_VERSION ?> &copy; <?= date('Y') ?></span>
            <span>Powered by VendoCore IoT Controller</span>
        </footer>
    </div>
</div>

<!-- ============================================================
     MODAL 1: CHANGE PASSWORD
     ============================================================ -->
<div class="modal fade modal-security-dialog" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header modal-security-header">
                <h5 class="modal-title fs-6 fw-bold text-white d-flex align-items-center" id="changePasswordModalLabel">
                    <i class="bi bi-shield-lock-fill me-2" style="color:var(--vl-primary);"></i>Change Password
                </h5>
                <button type="button" class="btn-close btn-close-white" id="btnCloseChangePasswordX" aria-label="Close"></button>
            </div>
            <form method="POST" action="profile.php#security" novalidate id="changePasswordModalForm" data-loading="true">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_password">
                
                <div class="modal-body modal-security-body">
                    <!-- Inline Alert for frontend/backend validation messages -->
                    <div id="modalPasswordAlert" class="alert alert-danger d-none py-2 px-3 small mb-3" role="alert"></div>

                    <p class="text-secondary small mb-3">
                        Ensure your account stays secure by using a strong password that you do not use on any other service.
                    </p>

                    <!-- Current Password -->
                    <div class="profile-form-group">
                        <label for="modal_current_password" class="profile-form-label">
                            <i class="bi bi-lock me-1"></i>Current Password <span class="text-danger">*</span>
                        </label>
                        <div class="input-password-wrapper">
                            <input type="password" class="form-control dark-input"
                                   id="modal_current_password" name="current_password"
                                   required autocomplete="current-password"
                                   placeholder="Enter your current password">
                            <button type="button" class="password-toggle" aria-label="Toggle password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- New Password -->
                    <div class="profile-form-group">
                        <label for="modal_new_password" class="profile-form-label">
                            <i class="bi bi-key me-1"></i>New Password <span class="text-danger">*</span>
                        </label>
                        <div class="input-password-wrapper">
                            <input type="password" class="form-control dark-input"
                                   id="modal_new_password" name="new_password"
                                   required minlength="8"
                                   autocomplete="new-password"
                                   placeholder="Min. 8 characters">
                            <button type="button" class="password-toggle" aria-label="Toggle password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength-bar" id="modalPwStrengthBar">
                            <div class="password-strength-fill" id="modalPwStrengthFill"></div>
                        </div>
                        <div class="password-strength-text" id="modalPwStrengthText"></div>
                    </div>

                    <!-- Confirm New Password -->
                    <div class="profile-form-group">
                        <label for="modal_confirm_password" class="profile-form-label">
                            <i class="bi bi-key-fill me-1"></i>Confirm New Password <span class="text-danger">*</span>
                        </label>
                        <div class="input-password-wrapper">
                            <input type="password" class="form-control dark-input"
                                   id="modal_confirm_password" name="confirm_password"
                                   required minlength="8"
                                   autocomplete="new-password"
                                   placeholder="Re-enter new password">
                            <button type="button" class="password-toggle" aria-label="Toggle password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="password-match-hint" id="modalPwMatchHint"></div>
                    </div>

                    <!-- Live Validation Checklist -->
                    <div class="password-requirements mb-1">
                        <div class="pw-req-item" id="modal-req-length">
                            <i class="bi bi-circle me-1"></i>At least 8 characters
                        </div>
                        <div class="pw-req-item" id="modal-req-different">
                            <i class="bi bi-circle me-1"></i>Different from current password
                        </div>
                        <div class="pw-req-item" id="modal-req-match">
                            <i class="bi bi-circle me-1"></i>Passwords match
                        </div>
                    </div>
                </div>

                <div class="modal-footer modal-security-footer d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary px-3" id="btnCancelChangePassword">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold" id="btnSubmitPassword">
                        <span class="btn-text"><i class="bi bi-check2-circle me-1"></i>Update Password</span>
                        <span class="btn-loading d-none"><span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Updating...</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================
     CONFIRM DISCARD MODAL (CHANGE PASSWORD)
     ============================================================ -->
<div class="modal fade modal-security-dialog" id="discardPasswordModal" tabindex="-1" aria-labelledby="discardPasswordModalLabel" aria-hidden="true" data-bs-backdrop="static" style="z-index: 1065;">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header modal-security-header py-2 px-3">
                <h5 class="modal-title fs-6 fw-bold text-white d-flex align-items-center" id="discardPasswordModalLabel">
                    <i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>Discard changes?
                </h5>
                <button type="button" class="btn-close btn-close-white" id="btnDiscardModalX" aria-label="Close"></button>
            </div>
            <div class="modal-body modal-security-body py-3 px-3">
                <p class="text-secondary small mb-0">
                    You have unsaved changes. Are you sure you want to close without updating your password?
                </p>
            </div>
            <div class="modal-footer modal-security-footer d-flex justify-content-end gap-2 py-2 px-3">
                <button type="button" class="btn btn-sm btn-outline-secondary px-3" id="btnKeepEditing">
                    Keep Editing
                </button>
                <button type="button" class="btn btn-sm btn-danger px-3 fw-semibold" id="btnConfirmDiscard">
                    Discard
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     MODAL 2: CHANGE EMAIL (2-STEP WITH 6-DIGIT OTP)
     ============================================================ -->
<div class="modal fade modal-security-dialog" id="changeEmailModal" tabindex="-1" aria-labelledby="changeEmailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header modal-security-header">
                <h5 class="modal-title fs-6 fw-bold text-white d-flex align-items-center" id="changeEmailModalLabel">
                    <i class="bi bi-envelope-gear-fill me-2" style="color:var(--vl-primary);"></i>Change Email Address
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <?php if (!$hasPendingEmailChange): ?>
            <!-- STEP 1: Request Email Change -->
            <form method="POST" action="profile.php#security" id="requestEmailChangeForm" data-loading="true">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="request_email_change">

                <div class="modal-body modal-security-body">
                    <p class="text-secondary small mb-3">
                        To update your email, enter your new email address and confirm your current account password. A 6-digit verification code will be sent to the new email.
                    </p>

                    <!-- Current Email -->
                    <div class="profile-form-group">
                        <label class="profile-form-label">Current Email</label>
                        <div>
                            <span class="current-email-badge">
                                <i class="bi bi-envelope me-1 text-info"></i><?= e($profileUser['email']) ?>
                            </span>
                        </div>
                    </div>

                    <!-- New Email -->
                    <div class="profile-form-group">
                        <label for="new_email" class="profile-form-label">
                            <i class="bi bi-at me-1"></i>New Email <span class="text-danger">*</span>
                        </label>
                        <input type="email" class="form-control dark-input"
                               id="new_email" name="new_email"
                               required autocomplete="email"
                               placeholder="e.g. user@example.com">
                    </div>

                    <!-- Current Password for authorization -->
                    <div class="profile-form-group">
                        <label for="email_current_password" class="profile-form-label">
                            <i class="bi bi-lock me-1"></i>Current Password <span class="text-danger">*</span>
                        </label>
                        <div class="input-password-wrapper">
                            <input type="password" class="form-control dark-input"
                                   id="email_current_password" name="current_password"
                                   required autocomplete="current-password"
                                   placeholder="Confirm your current password">
                            <button type="button" class="password-toggle" aria-label="Toggle password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="form-text text-muted small mt-1">
                            Required to prevent unauthorized account changes.
                        </div>
                    </div>
                </div>

                <div class="modal-footer modal-security-footer d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold" id="btnSendVerification">
                        <span class="btn-text"><i class="bi bi-send me-1"></i>Send Verification</span>
                        <span class="btn-loading d-none"><span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Sending...</span>
                    </button>
                </div>
            </form>

            <?php else: ?>
            <!-- STEP 2: Verify New Email with 6-Digit OTP -->
            <form method="POST" action="profile.php#security" id="verifyEmailChangeForm" data-loading="true">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="verify_email_change">

                <div class="modal-body modal-security-body text-center">
                    <div class="mb-3">
                        <div class="security-card-icon security-card-icon--mail mx-auto mb-2" style="width:52px; height:52px; font-size:1.5rem;">
                            <i class="bi bi-envelope-check"></i>
                        </div>
                        <h6 class="text-white fw-bold mb-1">Verify New Email</h6>
                        <div class="text-secondary small">
                            Code sent to:
                            <div class="mt-1">
                                <span class="otp-target-email"><?= e($pendingNewEmail) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- 6-digit OTP Input -->
                    <div class="my-4">
                        <label for="email_otp" class="profile-form-label mb-2">Enter 6-Digit Verification Code</label>
                        <input type="text" class="form-control dark-input otp-code-input"
                               id="email_otp" name="otp"
                               maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                               placeholder="• • • • • •"
                               required autofocus autocomplete="one-time-code">
                        <div class="text-muted small mt-2">
                            <i class="bi bi-clock me-1"></i>Expires in 5 minutes.
                        </div>
                    </div>
                </div>

                <div class="modal-footer modal-security-footer d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="d-flex gap-2">
                        <!-- Cancel pending change -->
                        <button type="button" class="btn btn-outline-secondary btn-sm px-3" onclick="document.getElementById('cancelEmailChangeForm').submit()">
                            Cancel
                        </button>
                        <!-- Resend OTP code -->
                        <button type="button" class="btn btn-outline-info btn-sm px-3" onclick="document.getElementById('resendEmailChangeForm').submit()">
                            <i class="bi bi-arrow-clockwise me-1"></i>Resend Code
                        </button>
                    </div>
                    <!-- Submit OTP -->
                    <button type="submit" class="btn btn-primary px-4 fw-semibold" id="btnVerifyEmail">
                        <span class="btn-text"><i class="bi bi-patch-check-fill me-1"></i>Verify Email</span>
                        <span class="btn-loading d-none"><span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Verifying...</span>
                    </button>
                </div>
            </form>

            <!-- Hidden separate forms for Cancel and Resend to prevent nested form issues -->
            <form method="POST" action="profile.php#security" id="cancelEmailChangeForm" class="d-none">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel_email_change">
            </form>
            <form method="POST" action="profile.php#security" id="resendEmailChangeForm" class="d-none">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="resend_email_change_otp">
            </form>
            <?php endif; ?>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= ASSETS_URL ?>/js/app.js?v=<?= e((string) filemtime(ROOT_PATH . '/assets/js/app.js')) ?>"></script>
<script>
(function(){
    'use strict';

    // Password show/hide toggle logic is handled globally and idempotently via app.js
    // ------------------------------------------------------------

    // ------------------------------------------------------------
    // Change Password Modal Elements
    // ------------------------------------------------------------
    var currentPw  = document.getElementById('modal_current_password');
    var newPw      = document.getElementById('modal_new_password');
    var confirmPw  = document.getElementById('modal_confirm_password');
    var fill       = document.getElementById('modalPwStrengthFill');
    var text       = document.getElementById('modalPwStrengthText');
    var matchHint  = document.getElementById('modalPwMatchHint');
    var reqLength  = document.getElementById('modal-req-length');
    var reqDiff    = document.getElementById('modal-req-different');
    var reqMatch   = document.getElementById('modal-req-match');
    var pwAlert    = document.getElementById('modalPasswordAlert');
    var pwForm     = document.getElementById('changePasswordModalForm');
    var pwModalEl  = document.getElementById('changePasswordModal');
    var discardEl  = document.getElementById('discardPasswordModal');

    var btnCloseX  = document.getElementById('btnCloseChangePasswordX');
    var btnCancel  = document.getElementById('btnCancelChangePassword');
    var btnKeep    = document.getElementById('btnKeepEditing');
    var btnKeepX   = document.getElementById('btnDiscardModalX');
    var btnDiscard = document.getElementById('btnConfirmDiscard');

    var pwModalInstance      = (pwModalEl && typeof bootstrap !== 'undefined') ? bootstrap.Modal.getOrCreateInstance(pwModalEl) : null;
    var discardModalInstance = (discardEl && typeof bootstrap !== 'undefined') ? new bootstrap.Modal(discardEl, { backdrop: 'static', keyboard: true }) : null;

    var isCurrentPwTouched = false;
    var isNewPwTouched     = false;
    var isConfirmPwTouched = false;
    var isClosingViaDiscard = false;

    // ------------------------------------------------------------
    // 1. Dirty Form State Tracker
    // ------------------------------------------------------------
    function isChangePasswordDirty() {
        var cp = currentPw ? currentPw.value : '';
        var np = newPw     ? newPw.value     : '';
        var cf = confirmPw ? confirmPw.value : '';
        return (cp.trim() !== '' || np.trim() !== '' || cf.trim() !== '');
    }

    // ------------------------------------------------------------
    // 2. Alert & Requirement Helpers (Inline Only, No Popups)
    // ------------------------------------------------------------
    function showPasswordAlert(msg) {
        if (!pwAlert) return;
        pwAlert.innerHTML = '<i class="bi bi-exclamation-circle-fill me-2"></i>' + msg;
        pwAlert.classList.remove('d-none');
    }

    function hidePasswordAlert() {
        if (!pwAlert) return;
        pwAlert.innerHTML = '';
        pwAlert.classList.add('d-none');
    }

    function strength(pw) {
        if (!pw) return { pct: 0, label: '', color: 'transparent' };
        var s = 0;
        if (pw.length >= 8)  s++;
        if (pw.length >= 12) s++;
        if (/[A-Z]/.test(pw)) s++;
        if (/[0-9]/.test(pw)) s++;
        if (/[^A-Za-z0-9]/.test(pw)) s++;
        var m = [
            { label: '',            color: 'transparent', pct: 0 },
            { label: 'Weak',        color: '#ef4444',     pct: 20 },
            { label: 'Fair',        color: '#f97316',     pct: 40 },
            { label: 'Moderate',    color: '#f59e0b',     pct: 60 },
            { label: 'Strong',      color: '#22c55e',     pct: 80 },
            { label: 'Very Strong', color: '#0ea5e9',     pct: 100 }
        ];
        return m[Math.min(s, 5)];
    }

    function setReq(el, state) {
        if (!el) return;
        var ic = el.querySelector('i');
        if (state === true) {
            el.classList.remove('pw-req-fail');
            el.classList.add('pw-req-pass');
            if (ic) ic.className = 'bi bi-check-circle-fill text-success me-1';
        } else if (state === false) {
            el.classList.remove('pw-req-pass');
            el.classList.add('pw-req-fail');
            if (ic) ic.className = 'bi bi-x-circle-fill text-danger me-1';
        } else {
            // Neutral / inactive
            el.classList.remove('pw-req-pass', 'pw-req-fail');
            if (ic) ic.className = 'bi bi-circle me-1';
        }
    }

    // ------------------------------------------------------------
    // 3. Complete Form Reset
    // ------------------------------------------------------------
    function resetChangePasswordForm() {
        // Reset input values
        if (currentPw) currentPw.value = '';
        if (newPw)     newPw.value     = '';
        if (confirmPw) confirmPw.value = '';

        // Reset inputs back to type="password" and clear validation markers
        [currentPw, newPw, confirmPw].forEach(function(input) {
            if (!input) return;
            input.type = 'password';
            input.classList.remove('is-invalid', 'is-valid');
            var wrapper = input.closest('.input-password-wrapper');
            if (wrapper) {
                var icon = wrapper.querySelector('.password-toggle i');
                if (icon) icon.className = 'bi bi-eye';
                var btn = wrapper.querySelector('.password-toggle');
                if (btn) btn.setAttribute('aria-label', 'Show password');
            }
        });

        // Reset password strength bar
        if (fill) {
            fill.style.width = '0%';
            fill.style.backgroundColor = 'transparent';
        }
        if (text) {
            text.textContent = '';
            text.style.color = '';
        }

        // Reset live requirements checklist back to neutral
        setReq(reqLength, null);
        setReq(reqDiff, null);
        setReq(reqMatch, null);

        // Reset match hint
        if (matchHint) {
            matchHint.textContent = '';
            matchHint.className = 'password-match-hint';
        }

        // Hide inline alert message
        hidePasswordAlert();

        // Reset submission lock and button state
        if (pwForm) {
            pwForm.dataset.submitting = 'false';
            var submitBtn = pwForm.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = false;
                var txt = submitBtn.querySelector('.btn-text');
                var lod = submitBtn.querySelector('.btn-loading');
                if (txt) txt.classList.remove('d-none');
                if (lod) lod.classList.add('d-none');
            }
        }

        // Reset interaction tracking flags
        isCurrentPwTouched = false;
        isNewPwTouched     = false;
        isConfirmPwTouched = false;
        isClosingViaDiscard = false;
    }

    // ------------------------------------------------------------
    // 4. Password Validation UX
    // ------------------------------------------------------------
    function validatePasswordFields() {
        var cp = currentPw ? currentPw.value : '';
        var np = newPw     ? newPw.value     : '';
        var cf = confirmPw ? confirmPw.value : '';

        // Hide generic top alert while actively typing
        hidePasswordAlert();

        // Remove field invalid highlight once user starts typing in that field
        if (cp.length > 0 && currentPw) currentPw.classList.remove('is-invalid');
        if (np.length > 0 && newPw)     newPw.classList.remove('is-invalid');
        if (cf.length > 0 && confirmPw) confirmPw.classList.remove('is-invalid');

        // A. Password Strength Meter
        if (np.length === 0) {
            if (fill) { fill.style.width = '0%'; fill.style.backgroundColor = 'transparent'; }
            if (text) { text.textContent = ''; text.style.color = ''; }
        } else {
            var s = strength(np);
            if (fill) { fill.style.width = s.pct + '%'; fill.style.backgroundColor = s.color; }
            if (text) { text.textContent = s.label; text.style.color = s.color; }
        }

        // B. Requirement 1: At least 8 characters
        if (np.length === 0) {
            setReq(reqLength, null);
        } else if (np.length >= 8) {
            setReq(reqLength, true);
        } else {
            // Keep neutral while actively typing; show failure only if interacted and blurred/submitted
            setReq(reqLength, isNewPwTouched ? false : null);
        }

        // C. Requirement 2: Different from current password
        if (np.length === 0 || cp.length === 0) {
            setReq(reqDiff, null);
        } else if (np !== cp) {
            setReq(reqDiff, true);
        } else {
            setReq(reqDiff, false);
        }

        // D. Requirement 3: Confirm Password Match
        // Only perform password-match validation once Confirm New Password contains input!
        if (cf.length === 0) {
            if (matchHint) {
                matchHint.textContent = '';
                matchHint.className = 'password-match-hint';
            }
            setReq(reqMatch, null); // Neutral inactive state
        } else {
            var isMatch = (np.length > 0 && np === cf);
            if (matchHint) {
                matchHint.textContent = isMatch ? '\u2713 Passwords match' : '\u2717 Passwords do not match';
                matchHint.className   = 'password-match-hint ' + (isMatch ? 'match-ok' : 'match-fail');
            }
            setReq(reqMatch, isMatch);
        }
    }

    // ------------------------------------------------------------
    // 5. Close / Cancel / Discard Handling
    // ------------------------------------------------------------
    function handleCloseChangePasswordModal() {
        if (!isChangePasswordDirty()) {
            resetChangePasswordForm();
            if (!pwModalInstance && pwModalEl && typeof bootstrap !== 'undefined') {
                pwModalInstance = bootstrap.Modal.getOrCreateInstance(pwModalEl);
            }
            if (pwModalInstance) {
                pwModalInstance.hide();
            }
        } else {
            if (!discardModalInstance && discardEl && typeof bootstrap !== 'undefined') {
                discardModalInstance = new bootstrap.Modal(discardEl, { backdrop: 'static', keyboard: true });
            }
            if (discardModalInstance) {
                discardModalInstance.show();
            }
        }
    }

    // Expose functions globally for programmatic access or testing
    window.resetChangePasswordForm = resetChangePasswordForm;
    window.handleCloseChangePasswordModal = handleCloseChangePasswordModal;
    window.isChangePasswordDirty = isChangePasswordDirty;

    // Field Event Listeners
    if (currentPw) {
        currentPw.addEventListener('input', validatePasswordFields);
        currentPw.addEventListener('blur', function() {
            isCurrentPwTouched = true;
            validatePasswordFields();
        });
    }

    if (newPw) {
        newPw.addEventListener('input', validatePasswordFields);
        newPw.addEventListener('blur', function() {
            isNewPwTouched = true;
            validatePasswordFields();
        });
    }

    if (confirmPw) {
        confirmPw.addEventListener('input', validatePasswordFields);
        confirmPw.addEventListener('blur', function() {
            isConfirmPwTouched = true;
            validatePasswordFields();
        });
    }

    // Both Close (X) and Cancel use the same close-handling logic
    if (btnCloseX) btnCloseX.addEventListener('click', handleCloseChangePasswordModal);
    if (btnCancel) btnCancel.addEventListener('click', handleCloseChangePasswordModal);

    // Keep Editing: close confirmation modal and keep Change Password modal open
    if (btnKeep) {
        btnKeep.addEventListener('click', function() {
            if (discardModalInstance) discardModalInstance.hide();
        });
    }
    if (btnKeepX) {
        btnKeepX.addEventListener('click', function() {
            if (discardModalInstance) discardModalInstance.hide();
        });
    }

    // Discard: reset form, close confirmation, and close Change Password modal
    if (btnDiscard) {
        btnDiscard.addEventListener('click', function() {
            isClosingViaDiscard = true;
            if (discardModalInstance) discardModalInstance.hide();
            resetChangePasswordForm();
            if (!pwModalInstance && pwModalEl && typeof bootstrap !== 'undefined') {
                pwModalInstance = bootstrap.Modal.getOrCreateInstance(pwModalEl);
            }
            if (pwModalInstance) {
                pwModalInstance.hide();
            }
        });
    }

    // Modal Stacking Management
    if (discardEl) {
        discardEl.addEventListener('show.bs.modal', function() {
            discardEl.style.zIndex = '1070';
            setTimeout(function() {
                var backdrops = document.querySelectorAll('.modal-backdrop');
                if (backdrops.length > 1) {
                    backdrops[backdrops.length - 1].style.zIndex = '1065';
                }
            }, 10);
        });

        discardEl.addEventListener('hidden.bs.modal', function() {
            if (pwModalEl && pwModalEl.classList.contains('show')) {
                document.body.classList.add('modal-open');
            }
        });
    }

    // Intercept Escape or backdrop click on Change Password modal
    if (pwModalEl) {
        pwModalEl.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                if (discardEl && discardEl.classList.contains('show')) return;
                e.preventDefault();
                e.stopPropagation();
                handleCloseChangePasswordModal();
            }
        });

        pwModalEl.addEventListener('click', function(e) {
            if (e.target === pwModalEl) {
                if (discardEl && discardEl.classList.contains('show')) return;
                handleCloseChangePasswordModal();
            }
        });

        // Always guarantee a clean, reset form when modal finishes hiding
        pwModalEl.addEventListener('hidden.bs.modal', function() {
            resetChangePasswordForm();
        });
    }

    // ------------------------------------------------------------
    // 6. Form Submission & Validation Enforcement
    // ------------------------------------------------------------
    if (pwForm) {
        pwForm.addEventListener('submit', function (e) {
            var cp = currentPw ? currentPw.value : '';
            var np = newPw     ? newPw.value     : '';
            var cf = confirmPw ? confirmPw.value : '';

            isCurrentPwTouched = true;
            isNewPwTouched     = true;
            isConfirmPwTouched = true;
            validatePasswordFields();

            // 1. Current password required
            if (!cp.trim()) {
                e.preventDefault();
                showPasswordAlert('Please enter your current password.');
                if (currentPw) {
                    currentPw.classList.add('is-invalid');
                    currentPw.focus();
                }
                return false;
            }

            // 2. New password required
            if (!np) {
                e.preventDefault();
                showPasswordAlert('Please enter a new password.');
                if (newPw) {
                    newPw.classList.add('is-invalid');
                    newPw.focus();
                }
                return false;
            }

            // 3. New password minimum 8 characters
            if (np.length < 8) {
                e.preventDefault();
                showPasswordAlert('New password must be at least 8 characters long.');
                setReq(reqLength, false);
                if (newPw) {
                    newPw.classList.add('is-invalid');
                    newPw.focus();
                }
                return false;
            }

            // 4. New password different from current password
            if (np === cp) {
                e.preventDefault();
                showPasswordAlert('New password cannot be the same as your current password.');
                setReq(reqDiff, false);
                if (newPw) {
                    newPw.classList.add('is-invalid');
                    newPw.focus();
                }
                return false;
            }

            // 5. Confirm password required
            if (!cf) {
                e.preventDefault();
                showPasswordAlert('Please confirm your new password.');
                if (confirmPw) {
                    confirmPw.classList.add('is-invalid');
                    confirmPw.focus();
                }
                return false;
            }

            // 6. Passwords must match
            if (np !== cf) {
                e.preventDefault();
                showPasswordAlert('New password and confirm password do not match.');
                setReq(reqMatch, false);
                if (confirmPw) {
                    confirmPw.classList.add('is-invalid');
                    confirmPw.focus();
                }
                return false;
            }

            // Valid! Hide any lingering error alert
            hidePasswordAlert();

            // Submit Lock Prevention
            if (pwForm.dataset.submitting === 'true') {
                e.preventDefault();
                return false;
            }
            pwForm.dataset.submitting = 'true';
            var submitBtn = pwForm.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                var txt = submitBtn.querySelector('.btn-text');
                var lod = submitBtn.querySelector('.btn-loading');
                if (txt) txt.classList.add('d-none');
                if (lod) lod.classList.remove('d-none');
            }
        });
    }

    function attachSubmitLock(formId) {
        var f = document.getElementById(formId);
        if (!f) return;
        f.addEventListener('submit', function (e) {
            if (f.dataset.submitting === 'true') {
                e.preventDefault();
                return false;
            }
            f.dataset.submitting = 'true';
            var btn = f.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                var txt = btn.querySelector('.btn-text');
                var lod = btn.querySelector('.btn-loading');
                if (txt) txt.classList.add('d-none');
                if (lod) lod.classList.remove('d-none');
            }
        });
    }

    attachSubmitLock('verifyEmailChangeForm');
    attachSubmitLock('requestEmailChangeForm');

    // Auto-open modals only when explicitly requested by a fresh server redirect
    <?php if ($openPasswordModal): ?>
    if (pwModalEl && typeof bootstrap !== 'undefined') {
        var pwAutoInst = bootstrap.Modal.getOrCreateInstance(pwModalEl);
        pwAutoInst.show();
        showPasswordAlert('Your current password was incorrect. Please try again.');
        if (currentPw) {
            currentPw.classList.add('is-invalid');
            currentPw.focus();
        }
    }
    <?php endif; ?>

    <?php if ($openEmailModal): ?>
    var emModalEl = document.getElementById('changeEmailModal');
    if (emModalEl && typeof bootstrap !== 'undefined') {
        var emModal = new bootstrap.Modal(emModalEl);
        emModal.show();
    }
    <?php endif; ?>

    // Prevent back-forward cache (bfcache) from restoring an open modal state
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            <?php if (!$openEmailModal): ?>
            var emModalEl = document.getElementById('changeEmailModal');
            if (emModalEl && typeof bootstrap !== 'undefined') {
                var inst = bootstrap.Modal.getInstance(emModalEl);
                if (inst) inst.hide();
            }
            <?php endif; ?>
            <?php if (!$openPasswordModal): ?>
            var pwModalEl = document.getElementById('changePasswordModal');
            if (pwModalEl && typeof bootstrap !== 'undefined') {
                var pwInst = bootstrap.Modal.getInstance(pwModalEl);
                if (pwInst) pwInst.hide();
            }
            <?php endif; ?>
        }
    });

})();
</script>
</body>
</html>
