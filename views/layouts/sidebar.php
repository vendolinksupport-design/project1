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

        $iconHtml = lucide_icon($icon, 'sidebar-lucide-icon');

        echo <<<HTML
        <li class="nav-item-sidebar">
            <a href="{$href}" class="nav-link-sidebar {$isActive}">
                <span class="nav-icon">{$iconHtml}</span>
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
                <?= lucide_icon('cpu', 'sidebar-logo-lucide-icon') ?>
            </div>
            <div class="sidebar-logo-text">
                <span class="sidebar-logo-brand">Vendo<strong>Link</strong></span>
                <span class="sidebar-logo-version">v<?= APP_VERSION ?></span>
            </div>
        </a>
        <!-- Mobile close button -->
        <button class="sidebar-close d-lg-none" id="sidebarClose" aria-label="Close sidebar">
            <?= lucide_icon('x') ?>
        </button>
    </div>


    <!-- Navigation -->
    <nav class="sidebar-nav">

        <!-- Main -->
        <div class="nav-section-label">Main</div>
        <ul class="nav-list">
            <?php nav_item(
                BASE_URL . '/dashboard/index.php',
                'gauge',
                'Dashboard',
                'index'
            ); ?>
        </ul>

        <!-- Monitoring -->
        <div class="nav-section-label">Monitoring</div>
        <ul class="nav-list">
            <?php nav_item(
                BASE_URL . '/dashboard/machines.php',
                'server',
                'Machines',
                'machines'
            ); ?>
            <?php nav_item(
                BASE_URL . '/dashboard/alerts.php',
                'bell',
                term('alerts', 'Alerts'),
                'alerts'
            ); ?>
            <?php nav_item(
                BASE_URL . '/dashboard/maintenance.php',
                'wrench',
                term('service_reminders', 'Service Reminders'),
                'maintenance',
                ROLE_ADMIN . ',' . ROLE_OWNER
            ); ?>
        </ul>

        <!-- Sales & Analytics -->
        <div class="nav-section-label">Sales &amp; Analytics</div>
        <ul class="nav-list">
            <?php if (has_role([ROLE_ADMIN, ROLE_OWNER])): ?>
                <?php
                $isSalesChildActive       = ($currentPage === 'sales');
                $isCollectionsChildActive = ($currentPage === 'cash_collections');
                $isSalesParentExpanded    = $isSalesChildActive || $isCollectionsChildActive;
                ?>
                <li class="nav-item-sidebar nav-item-has-submenu <?= $isSalesParentExpanded ? 'open' : '' ?>">
                    <a href="#salesOverviewSubmenu"
                       class="nav-link-sidebar nav-link-parent <?= $isSalesParentExpanded ? 'parent-active' : '' ?>"
                       data-bs-toggle="collapse"
                       role="button"
                       aria-expanded="<?= $isSalesParentExpanded ? 'true' : 'false' ?>"
                       aria-controls="salesOverviewSubmenu">
                        <span class="nav-icon"><?= lucide_icon('trending-up', 'sidebar-lucide-icon') ?></span>
                        <span class="nav-label">Sales Overview</span>
                        <span class="nav-arrow ms-auto">
                            <?= lucide_icon('chevron-down', 'sidebar-arrow-icon') ?>
                        </span>
                    </a>
                    <div class="collapse <?= $isSalesParentExpanded ? 'show' : '' ?>" id="salesOverviewSubmenu">
                        <ul class="nav-submenu-list">
                            <li class="nav-submenu-item">
                                <a href="<?= BASE_URL ?>/dashboard/sales.php"
                                   class="nav-submenu-link <?= $isSalesChildActive ? 'active' : '' ?>">
                                    <span class="nav-icon"><?= lucide_icon('dollar-sign', 'sidebar-lucide-icon') ?></span>
                                    <span class="nav-label">Sales</span>
                                </a>
                            </li>
                            <li class="nav-submenu-item">
                                <a href="<?= BASE_URL ?>/dashboard/cash_collections.php"
                                   class="nav-submenu-link <?= $isCollectionsChildActive ? 'active' : '' ?>">
                                    <span class="nav-icon"><?= lucide_icon('wallet', 'sidebar-lucide-icon') ?></span>
                                    <span class="nav-label">Cash Collections</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>
                <?php
                $isHistoryActive = in_array($currentPage, ['archive', 'history'], true);
                ?>
                <li class="nav-item-sidebar">
                    <a href="<?= BASE_URL . '/dashboard/archive.php' ?>"
                       class="nav-link-sidebar <?= $isHistoryActive ? 'active' : '' ?>">
                        <span class="nav-icon"><?= lucide_icon('history', 'sidebar-lucide-icon') ?></span>
                        <span class="nav-label"><?= term('history', 'History') ?></span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>

        <!-- System Settings -->
        <div class="nav-section-label">System Settings</div>
        <ul class="nav-list">
            <?php nav_item(
                BASE_URL . '/users/index.php',
                'users',
                'Users',
                'users',
                ROLE_ADMIN
            ); ?>
            <?php nav_item(
                BASE_URL . '/settings/index.php',
                'settings',
                'Settings',
                'settings',
                ROLE_ADMIN . ',' . ROLE_OWNER
            ); ?>
        </ul>

    </nav>

</aside>

<!-- Sidebar Overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script>if (typeof window.BASE_URL === 'undefined') { window.BASE_URL = <?= json_encode(BASE_URL) ?>; }</script>
