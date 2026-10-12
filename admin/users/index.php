<?php
/**
 * ============================================================
 * VendoLink — Internal Users List
 * ============================================================
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../models/User.php';

require_auth();
require_role(ROLE_ADMIN);

$pageTitle   = term('admin_accounts', 'Admin Accounts');
$currentPage = 'users';
$currentDir  = 'users';
$userModel   = new User();

$users = $userModel->getInternalUsers();
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
            <?php if ($flashHtml = render_flash()): ?>
            <div class="flash-container mb-3"><?= $flashHtml ?></div>
            <?php endif; ?>

            <div class="page-header mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h1 class="page-title text-white"><i class="bi bi-people-fill me-2" style="color: var(--vl-primary);"></i><?= term('admin_accounts') ?></h1>
                    <p class="page-subtitle text-slate-300">Manage VendoLink administrative staff accounts.</p>
                </div>
                <div>
                    <a href="create.php" class="btn btn-primary btn-sm fw-semibold px-3"><i class="bi bi-plus-lg me-1"></i> <?= term('add_admin_account') ?></a>
                </div>
            </div>

            <div class="vl-profile-panel mb-4 p-0 overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-hover table-dark align-middle mb-0" style="font-size: .88rem;">
                        <thead>
                            <tr class="text-slate-400 border-bottom border-slate-700">
                                <th>Name</th>
                                <th>Email</th>
                                <th style="width: 160px;">Role</th>
                                <th style="width: 120px;">Status</th>
                                <th style="width: 160px;">Last Login</th>
                                <th style="width: 140px;">Created Date</th>
                                <th style="width: 130px;" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-slate-400">No admin accounts found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($users as $u): ?>
                                <tr class="border-bottom border-slate-800">
                                    <td>
                                        <div class="fw-bold text-white"><?= e($u['full_name']) ?></div>
                                        <div class="text-slate-400 small font-monospace">@<?= e($u['username']) ?></div>
                                    </td>
                                    <td><span class="text-info font-monospace"><?= e($u['email']) ?></span></td>
                                    <td>
                                        <?php if ($u['role'] === ROLE_ADMIN): ?>
                                        <span class="badge bg-danger">
                                            <i class="bi bi-shield-fill-check me-1"></i>Admin
                                        </span>
                                        <?php else: ?>
                                        <span class="badge bg-info text-dark">
                                            <i class="bi bi-headset me-1"></i>Support
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($u['status'] === 'active'): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i>Active</span>
                                        <?php else: ?>
                                        <span class="badge bg-danger"><i class="bi bi-x-circle-fill me-1"></i>Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="text-slate-300 small font-monospace"><?= $u['last_login'] ? format_date($u['last_login']) : 'Never' ?></span>
                                    </td>
                                    <td>
                                        <span class="text-slate-300 small"><?= format_date($u['created_at'], 'M d, Y') ?></span>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($u['role'] === ROLE_SUPPORT): ?>
                                            <?php if ($u['status'] === 'active'): ?>
                                                <a href="toggle.php?id=<?= $u['id'] ?>&action=deactivate&<?= CSRF_TOKEN_NAME ?>=<?= e(csrf_token()) ?>" class="btn btn-sm btn-outline-danger py-1 px-2 fw-semibold" style="font-size: .78rem;" onclick="return confirm('Deactivate this support user?');">Deactivate</a>
                                            <?php else: ?>
                                                <a href="toggle.php?id=<?= $u['id'] ?>&action=activate&<?= CSRF_TOKEN_NAME ?>=<?= e(csrf_token()) ?>" class="btn btn-sm btn-outline-success py-1 px-2 fw-semibold" style="font-size: .78rem;" onclick="return confirm('Activate this support user?');">Activate</a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
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
