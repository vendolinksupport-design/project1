<?php
/**
 * ============================================================
 * VendoLink — Create Support User
 * ============================================================
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../models/User.php';

require_auth();
require_role(ROLE_ADMIN);

$pageTitle   = 'Add Support User';
$currentPage = 'create';
$currentDir  = 'users';
$userModel   = new User();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? $_POST['confirm_password'] ?? '';

    if (empty($fullName)) {
        $errors[] = 'Full Name is required.';
    }
    if (empty($username)) {
        $errors[] = 'Username is required.';
    } elseif ($userModel->findByUsername($username)) {
        $errors[] = 'Username is already taken.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid email is required.';
    } elseif ($userModel->findByEmail($email)) {
        $errors[] = 'Email is already taken.';
    }
    if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must be at least 8 characters long and contain both letters and numbers.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        $newId = $userModel->createSupportAccount([
            'full_name' => $fullName,
            'username'  => $username,
            'email'     => $email,
            'password'  => $password,
        ]);
        
        log_activity('SUPPORT_CREATED', "Created Support account: $username", current_user()['id']);
        flash('success', 'Admin account created successfully.');
        redirect('admin/users/index.php');
    }
}

$pageTitle   = term('add_admin_account', 'Add Admin Account');
$currentPage = 'create';
$currentDir  = 'users';
$userModel   = new User();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — VendoAdmin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/app.css">
</head>
<body class="dashboard-body">
<div class="app-shell">
    <?php require_once __DIR__ . '/../../views/layouts/admin_sidebar.php'; ?>
    <div class="main-content" id="mainContent">
        <?php require_once __DIR__ . '/../../views/layouts/admin_topnav.php'; ?>
        <main class="page-content" id="pageContent">
            
            <div class="page-header mb-4">
                <a href="index.php" class="btn btn-sm btn-outline-secondary mb-3 fw-semibold"><i class="bi bi-arrow-left me-1"></i>Back to <?= term('admin_accounts') ?></a>
                <h1 class="page-title text-white"><i class="bi bi-person-plus-fill me-2" style="color: var(--vl-primary);"></i><?= term('add_admin_account') ?></h1>
                <p class="page-subtitle text-slate-300">Create a new internal VendoLink administrative staff account.</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger mb-4">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $e): ?>
                            <li><?= e($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="vl-profile-panel" style="max-width: 680px;">
                <div class="vl-profile-panel-heading text-white fw-bold mb-4">
                    <i class="bi bi-shield-lock-fill me-2" style="color: var(--vl-primary);"></i>Admin Account Information
                </div>

                <form method="POST" action="create.php" id="createSupportForm">
                    <?= csrf_field() ?>
                    
                    <div class="mb-3">
                        <label class="form-label text-white fw-semibold small mb-1.5" style="user-select: none;">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control dark-input" placeholder="e.g. Alex Rivera" required value="<?= e($_POST['full_name'] ?? '') ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label text-white fw-semibold small mb-1.5" style="user-select: none;">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control dark-input" placeholder="e.g. support_alex" required value="<?= e($_POST['username'] ?? '') ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label text-white fw-semibold small mb-1.5" style="user-select: none;">Email Address <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control dark-input" placeholder="e.g. alex.support@vendolink.local" required value="<?= e($_POST['email'] ?? '') ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label text-white fw-semibold small mb-1.5" style="user-select: none;">Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="password" class="form-control dark-input" placeholder="Min. 8 alphanumeric" minlength="8" required
                                   data-target-rules="#create_admin_rules"
                                   data-target-match="#create_admin_match">
                            <button type="button" class="btn btn-outline-secondary border-secondary border-opacity-25 toggle-password-btn text-light" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div id="create_admin_rules" class="text-slate-400 small mt-1" data-default-text="Must be at least 8 characters with letters and numbers.">Must be at least 8 characters with letters and numbers.</div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label text-white fw-semibold small mb-1.5" style="user-select: none;">Confirm Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="confirm_password" class="form-control dark-input" placeholder="Re-enter password" minlength="8" required
                                   data-target-match="#create_admin_match">
                            <button type="button" class="btn btn-outline-secondary border-secondary border-opacity-25 toggle-password-btn text-light" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div id="create_admin_match" class="mt-1"></div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary fw-semibold w-100 py-2.5">
                        <i class="bi bi-check-lg me-1"></i><?= term('add_admin_account') ?>
                    </button>
                </form>
            </div>

        </main>
        
        <!-- App Footer -->
        <footer class="app-footer">
            <span><?= APP_NAME ?> v<?= APP_VERSION ?> &copy; <?= date('Y') ?></span>
            <span>Powered by VendoCore IoT Controller</span>
        </footer>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= ASSETS_URL ?>/js/app.js?v=<?= e((string) filemtime(ROOT_PATH . '/assets/css/app.css')) ?>"></script>
</body>
</html>
