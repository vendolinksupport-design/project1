<?php
/**
 * ============================================================
 * VendoLink — Dashboard Sidebar
 * ============================================================
 *
 * Role-aware navigation sidebar.
 * Include inside dashboard pages after requiring auth middleware.
 *
 * @package VendoLink
 * @version 1.0
 */

// Determine the current page for active link highlighting.
// Pages may pre-set $currentPage before including the sidebar; respect that.
if (!isset($currentPage)) {
    $currentPage = basename($_SERVER['PHP_SELF'], '.php');
}
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

/**
 * Helper — render a nav item with active state detection.
 *
 * @param string $href      URL for the link
 * @param string $icon      Bootstrap icon class (e.g. 'bi-speedometer2')
 * @param string $label     Display label
 * @param string $matchPage Basename to match for active state
 * @param string $roles     Comma-separated roles that can see this item ('all' for all)
 */
if (!function_exists('nav_item')) {
    function nav_item(
        string $href,
        string $icon,
        string $label,
        string $matchPage = '',
        string $roles = 'all'
    ): void {
        global $currentPage, $currentDir;

        // Role check
        if ($roles !== 'all') {
            $roleArr = array_map('trim', explode(',', $roles));
            if (!has_role($roleArr)) {
                return;
            }
        }

        // Match by explicit $currentPage value first, then fall back to directory.
        // This ensures pages like users/index.php that set $currentPage = 'users'
        // correctly activate the Users nav item rather than the Dashboard item.
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

<!-- ============================================================
     Sidebar
     ============================================================ -->
<aside class="sidebar" id="sidebar">

    <!-- Sidebar Header / Logo -->
    <div class="sidebar-header">
        <a href="<?= BASE_URL ?>/dashboard/index.php" class="sidebar-logo">
            <div class="sidebar-logo-icon">
                <i class="bi bi-cpu-fill"></i>
            </div>
            <div class="sidebar-logo-text">
                <span class="sidebar-logo-brand">Vendo<strong>Link</strong></span>
                <span class="sidebar-logo-version">v<?= APP_VERSION ?></span>
            </div>
        </a>
        <!-- Mobile close button -->
        <button class="sidebar-close d-lg-none" id="sidebarClose" aria-label="Close sidebar">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>


    <!-- Navigation -->
    <nav class="sidebar-nav">

        <!-- Main -->
        <div class="nav-section-label">Main</div>
        <ul class="nav-list">
            <?php nav_item(
                BASE_URL . '/dashboard/index.php',
                'bi-speedometer2',
                'Dashboard',
                'index'
            ); ?>
        </ul>

        <!-- Monitoring -->
        <div class="nav-section-label">Monitoring</div>
        <ul class="nav-list">
            <?php nav_item(
                BASE_URL . '/dashboard/machines.php',
                'bi-hdd-stack',
                'Machines',
                'machines'
            ); ?>
            <?php nav_item(
                BASE_URL . '/dashboard/alerts.php',
                'bi-bell-fill',
                term('alerts', 'Alerts'),
                'alerts'
            ); ?>
            <?php nav_item(
                BASE_URL . '/dashboard/maintenance.php',
                'bi-wrench-adjustable-circle',
                term('maintenance', 'Maintenance'),
                'maintenance',
                ROLE_ADMIN . ',' . ROLE_OWNER
            ); ?>
        </ul>

        <!-- Sales & Analytics -->
        <div class="nav-section-label">Sales &amp; Analytics</div>
        <ul class="nav-list">
            <?php nav_item(
                BASE_URL . '/dashboard/sales.php',
                'bi-cash-coin',
                term('sales_overview', 'Sales Overview'),
                'sales',
                ROLE_ADMIN . ',' . ROLE_OWNER
            ); ?>
            <?php nav_item(
                BASE_URL . '/dashboard/archive.php',
                'bi-archive',
                term('history', 'History'),
                'archive',
                ROLE_ADMIN . ',' . ROLE_OWNER
            ); ?>
            <?php
            /*
             * Reports module is hidden from the sidebar navigation per requirements.
             * The backend code, export functions, and /dashboard/reports.php route remain 100% active and accessible.
             *
             * nav_item(
             *     BASE_URL . '/dashboard/reports.php',
             *     'bi-graph-up',
             *     'Reports',
             *     'reports',
             *     ROLE_ADMIN . ',' . ROLE_OWNER
             * );
             */
            ?>
        </ul>

        <!-- System Settings -->
        <div class="nav-section-label">System Settings</div>
        <ul class="nav-list">
            <?php nav_item(
                BASE_URL . '/users/index.php',
                'bi-people',
                'Users',
                'users',
                ROLE_ADMIN
            ); ?>
            <?php nav_item(
                BASE_URL . '/settings/index.php',
                'bi-gear',
                'Settings',
                'settings',
                ROLE_ADMIN . ',' . ROLE_OWNER
            ); ?>
        </ul>

    </nav>

</aside>

<!-- Sidebar Overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>
