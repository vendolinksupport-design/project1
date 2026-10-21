<?php
/**
 * ============================================================
 * VendoLink — Admin Top Navigation Bar
 * ============================================================
 */

$authUser = current_user();

$pageLabels = [
    'index'     => term('overview'),
    'dashboard' => term('overview'),
    'customers' => term('customers'),
    'machines'  => term('machines'),
    'licenses'  => term('machine_activation'),
    'users'     => term('admin_accounts'),
    'create'    => 'Add Admin Account',
];
if (!isset($currentPage)) {
    $currentPage = basename($_SERVER['PHP_SELF'], '.php');
}
if (!isset($currentDir)) {
    $currentDir = basename(dirname($_SERVER['PHP_SELF']));
}

$pageLabel = $pageLabels[$currentPage] ?? ucfirst($currentPage);
if ($currentPage === 'index' && isset($pageLabels[$currentDir])) {
    $pageLabel = $pageLabels[$currentDir];
}
?>

<header class="topnav" id="topnav">
    <div class="topnav-left">
        <button class="sidebar-toggle d-lg-none" id="sidebarToggle" aria-label="Toggle Navigation">
            <span class="hamburger-line"></span>
            <span class="hamburger-line"></span>
            <span class="hamburger-line"></span>
        </button>
    </div>

    <div class="topnav-right">
        <!-- Quick Refresh -->
        <button class="topnav-icon-btn d-none d-sm-flex" onclick="location.reload()" title="Refresh Data">
            <?= lucide_icon('rotate-cw') ?>
        </button>

        <!-- Profile Dropdown -->
        <div class="dropdown topnav-dropdown">
            <button class="topnav-profile-btn" type="button"
                    data-bs-toggle="dropdown" aria-expanded="false">
                <div class="topnav-avatar bg-danger text-white border-0">
                    <?= strtoupper(mb_substr($authUser['full_name'] ?? 'U', 0, 1)) ?>
                </div>
                <div class="topnav-user-info d-none d-md-block">
                    <div class="topnav-user-name"><?= e($authUser['full_name'] ?? 'User') ?></div>
                    <div class="topnav-user-role"><?= ($authUser['role'] === ROLE_SUPPORT) ? 'Support' : 'Admin' ?></div>
                </div>
                <?= lucide_icon('chevron-down', 'topnav-chevron d-none d-md-block') ?>
            </button>

            <ul class="dropdown-menu dropdown-menu-end profile-dropdown">
                <li class="profile-dropdown-header">
                    <div class="pd-name"><?= e($authUser['full_name'] ?? '') ?></div>
                    <div class="pd-email"><?= e($authUser['email'] ?? '') ?></div>
                </li>
                <li><hr class="dropdown-divider my-1"></li>
                <li>
                    <a class="dropdown-item text-danger" href="<?= BASE_URL ?>/auth/logout.php" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal" id="adminTopnavLogout">
                        <?= lucide_icon('log-out', 'me-2') ?>Sign Out
                    </a>
                </li>
            </ul>
        </div>
    </div>
</header>

<!-- Logout Confirmation Modal (Admin) -->
<div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-labelledby="adminLogoutModalLabel" aria-hidden="true" style="z-index: 10040;">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 380px; width: 90%; margin: 1.75rem auto;">
        <div class="modal-content text-light text-center p-3" style="background: #0f172a; border: 1px solid var(--vl-border) !important; border-radius: 16px; box-shadow: 0 16px 40px rgba(0,0,0,0.8) !important;">
            <div class="modal-body py-3">
                <div class="p-3 bg-danger bg-opacity-10 text-danger rounded-circle d-inline-flex mb-3">
                    <i class="bi bi-box-arrow-right fs-1"></i>
                </div>
                <h5 class="modal-title font-bold text-light mb-2" id="adminLogoutModalLabel">Sign Out Confirmation</h5>
                <p class="text-secondary small mb-4">
                    Are you sure you want to sign out of your VendoLink session?
                </p>
                <div class="d-grid gap-2">
                    <a id="adminLogoutConfirmActionBtn" href="<?= BASE_URL ?>/auth/logout.php" onclick="window.location.href='<?= BASE_URL ?>/auth/logout.php';" class="btn btn-danger font-bold py-2 shadow logout-action-btn">
                        <i class="bi bi-box-arrow-right me-1"></i> Yes, Sign Out
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-2" data-bs-dismiss="modal">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
