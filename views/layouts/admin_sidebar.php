<?php
/**
 * ============================================================
 * VendoLink — Admin Dashboard Sidebar
 * ============================================================
 *
 * Role-aware navigation sidebar strictly for internal roles.
 * Include inside admin dashboard pages after requiring auth.
 */

if (!isset($currentPage)) {
    $currentPage = basename($_SERVER['PHP_SELF'], '.php');
}
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

if (!function_exists('nav_item')) {
    function nav_item(
        string $href,
        string $icon,
        string $label,
        string $matchPage = '',
        string $roles = 'all'
    ): void {
        global $currentPage, $currentDir;

        if ($roles !== 'all') {
            $roleArr = array_map('trim', explode(',', $roles));
            if (!has_role($roleArr)) {
                return;
            }
        }

        $isActive = '';
        if ($matchPage) {
            if ($currentPage === $matchPage) {
                $isActive = 'active';
            } elseif ($currentPage === 'index' && $currentDir === $matchPage) {
                $isActive = 'active';
            }
        }

        echo <<<HTML
        <li class="nav-item-sidebar">
            <a href="{$href}" class="nav-link-sidebar {$isActive}">
                <span class="nav-icon"><i class="bi {$icon}"></i></span>
                <span class="nav-label">{$label}</span>
            </a>
        </li>
        HTML;
    }
}

$authUser = current_user();
?>

<aside class="sidebar" id="sidebar">

    <!-- Sidebar Header / Logo -->
    <div class="sidebar-header">
        <a href="<?= BASE_URL ?>/admin/dashboard/index.php" class="sidebar-logo">
            <div class="sidebar-logo-icon">
                <i class="bi bi-shield-lock-fill text-danger"></i>
            </div>
            <div class="sidebar-logo-text">
                <span class="sidebar-logo-brand">Vendo<strong>Admin</strong></span>
                <span class="sidebar-logo-version">Internal</span>
            </div>
        </a>
        <!-- Mobile close button -->
        <button class="sidebar-close d-lg-none" id="sidebarClose" aria-label="Close sidebar">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>


    <!-- Navigation -->
    <nav class="sidebar-nav">

        <div class="nav-section-label">Main</div>
        <ul class="nav-list">
            <?php nav_item(
                BASE_URL . '/admin/dashboard/index.php',
                'bi-speedometer2',
                term('overview'),
                'dashboard'
            ); ?>
        </ul>

        <div class="nav-section-label">Platform Management</div>
        <ul class="nav-list">
            <?php nav_item(
                BASE_URL . '/admin/customers/index.php',
                'bi-person-badge-fill',
                term('customers'),
                'customers'
            ); ?>
            <?php /* Hidden for presentation simplicity
            nav_item(
                BASE_URL . '/admin/machines/index.php',
                'bi-cpu-fill',
                term('machines'),
                'machines'
            );
            */ ?>
            <?php /* Hidden - Not needed
            nav_item(
                BASE_URL . '/admin/ota/index.php',
                'bi-cloud-arrow-up-fill',
                'OTA Firmware',
                'ota'
            );
            */ ?>
            <?php nav_item(
                BASE_URL . '/admin/licenses/index.php',
                'bi-key-fill',
                term('machine_activation'),
                'licenses'
            ); ?>
        </ul>

        <?php if (has_role(ROLE_ADMIN)): ?>
        <div class="nav-section-label">System</div>
        <ul class="nav-list">
            <?php nav_item(
                BASE_URL . '/admin/users/index.php',
                'bi-people-fill',
                term('admin_accounts'),
                'users'
            ); ?>
        </ul>
        <?php endif; ?>

    </nav>

</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
