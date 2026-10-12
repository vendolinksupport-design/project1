<?php
/**
 * ============================================================
 * VendoLink — Change Password (Self-Service)
 * ============================================================
 *
 * Allows logged-in users (Owner, Staff, Admin) to update
 * their own password securely.
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/User.php';

require_auth();

$userModel = new User();
$currentUser = current_user();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validate inputs
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        flash('danger', 'Please fill in all password fields.');
        redirect('users/change_password.php');
    }

    // Verify current password
    $userData = $userModel->findById((int)$currentUser['id']);
    if (!$userData || !$userModel->verifyPassword($currentPassword, $userData['password'])) {
        flash('danger', 'Your current password was incorrect. Please try again.');
        log_activity('PASSWORD_CHANGE_FAILED', 'Incorrect current password provided.', (int)$currentUser['id']);
        redirect('users/change_password.php');
    }

    if ($userModel->verifyPassword($newPassword, $userData['password'])) {
        flash('danger', 'New password cannot be the same as your current password.');
        redirect('users/change_password.php');
    }

    // Validate new password strength
    if (strlen($newPassword) < 8) {
        flash('danger', 'New password must be at least 8 characters long.');
        redirect('users/change_password.php');
    }

    if ($newPassword !== $confirmPassword) {
        flash('danger', 'New password and confirm password do not match.');
        redirect('users/change_password.php');
    }

    // Update password
    $userModel->updatePassword((int)$currentUser['id'], $newPassword);
    log_activity('PASSWORD_CHANGED', 'User updated their own password successfully.', (int)$currentUser['id']);

    flash('success', 'Your password has been changed successfully!');
    redirect('users/change_password.php');
}

$pageTitle   = 'Change Password';
$currentPage = 'change_password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= APP_NAME ?> Change Password — <?= APP_TAGLINE ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>

    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- VendoLink CSS -->
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/app.css?v=<?= e((string) filemtime(ROOT_PATH . '/assets/css/app.css')) ?>">
</head>
<body class="dashboard-body">

<div class="app-shell">

    <!-- Sidebar -->
    <?php require_once __DIR__ . '/../views/layouts/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content" id="mainContent">

        <!-- Top Navigation -->
        <?php require_once __DIR__ . '/../views/layouts/topnav.php'; ?>

        <!-- Page Content -->
        <main class="page-content" id="pageContent">

            <!-- Flash Messages -->
            <?php if ($flashHtml = render_flash()): ?>
            <div class="flash-container mb-3">
                <?= $flashHtml ?>
            </div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="page-header mb-4">
                <div>
                    <h1 class="page-title text-white">
                        <i class="bi bi-key me-2 text-primary-vl"></i>Change Password
                    </h1>
                    <p class="page-subtitle text-light">
                        Update your account password to maintain system security.
                    </p>
                </div>
            </div>

            <div class="row justify-content-center">
                <div class="col-12 col-md-8 col-lg-6">
                    <div class="card bg-slate-900 border-slate-700 text-white shadow-lg p-4">
                        <form method="POST" action="change_password.php" novalidate data-loading="true">
                            <?= csrf_field() ?>

                            <!-- Current Password -->
                            <div class="mb-3">
                                <label for="current_password" class="form-label text-light small">
                                    <i class="bi bi-lock me-1"></i>Current Password <span class="text-danger">*</span>
                                </label>
                                <div class="input-password-wrapper">
                                    <input type="password" class="form-control dark-input text-white"
                                           id="current_password" name="current_password" required autofocus>
                                    <button type="button" class="password-toggle" aria-label="Toggle password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- New Password -->
                            <div class="mb-3">
                                <label for="new_password" class="form-label text-light small">
                                    <i class="bi bi-key me-1"></i>New Password <span class="text-danger">*</span>
                                </label>
                                <div class="input-password-wrapper">
                                    <input type="password" class="form-control dark-input text-white"
                                           id="new_password" name="new_password" required minlength="8" placeholder="Min. 8 characters">
                                    <button type="button" class="password-toggle" aria-label="Toggle password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Confirm New Password -->
                            <div class="mb-4">
                                <label for="confirm_password" class="form-label text-light small">
                                    <i class="bi bi-key-fill me-1"></i>Confirm New Password <span class="text-danger">*</span>
                                </label>
                                <div class="input-password-wrapper">
                                    <input type="password" class="form-control dark-input text-white"
                                           id="confirm_password" name="confirm_password" required minlength="8" placeholder="Re-enter new password">
                                    <button type="button" class="password-toggle" aria-label="Toggle password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Buttons -->
                            <div class="d-flex justify-content-between align-items-center">
                                <a href="<?= BASE_URL ?>/dashboard/index.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
                                </a>
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <span class="btn-text">
                                        <i class="bi bi-check2-circle me-1"></i>Update Password
                                    </span>
                                    <span class="btn-loading d-none">
                                        <span class="spinner-border spinner-border-sm me-1"></span>
                                        Updating...
                                    </span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </main>
        
        <!-- Page Footer -->
        <footer class="app-footer">
            <span><?= APP_NAME ?> v<?= APP_VERSION ?> &copy; <?= date('Y') ?></span>
            <span>Powered by VendoCore IoT Controller</span>
        </footer>
    </div>
</div>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- VendoLink JS -->
<script src="<?= ASSETS_URL ?>/js/app.js?v=<?= e((string) filemtime(ROOT_PATH . '/assets/js/app.js')) ?>"></script>
</body>
</html>
