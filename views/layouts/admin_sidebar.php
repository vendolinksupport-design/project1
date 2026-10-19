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

<aside class="sidebar" id="sidebar">

    <!-- Sidebar Header / Logo -->
    <div class="sidebar-header">
        <a href="<?= BASE_URL ?>/admin/dashboard/index.php" class="sidebar-logo">
            <div class="sidebar-logo-icon">
                <?= lucide_icon('shield-check', 'text-danger') ?>
            </div>
            <div class="sidebar-logo-text">
                <span class="sidebar-logo-brand">Vendo<strong>Admin</strong></span>
                <span class="sidebar-logo-version" id="adminSecretVersion" style="cursor:default;" title="VendoLink Admin">Internal</span>
            </div>
        </a>
        <!-- Mobile close button -->
        <button class="sidebar-close d-lg-none" id="sidebarClose" aria-label="Close sidebar">
            <?= lucide_icon('x') ?>
        </button>
    </div>


    <!-- Navigation -->
    <nav class="sidebar-nav">

        <div class="nav-section-label">Main</div>
        <ul class="nav-list">
            <?php nav_item(
                BASE_URL . '/admin/dashboard/index.php',
                'gauge',
                term('overview'),
                'dashboard'
            ); ?>
        </ul>

        <div class="nav-section-label">Hardware &amp; Machines</div>
        <ul class="nav-list">
            <?php nav_item(
                BASE_URL . '/admin/machines/index.php',
                'cpu',
                term('machines'),
                'machines'
            ); ?>
            <?php nav_item(
                BASE_URL . '/admin/ota/index.php',
                'radio',
                'Wireless Updates (OTA)',
                'ota'
            ); ?>
        </ul>

        <div class="nav-section-label">Customers &amp; Users</div>
        <ul class="nav-list">
            <?php nav_item(
                BASE_URL . '/admin/customers/index.php',
                'users',
                term('customers'),
                'customers'
            ); ?>
            <?php if (has_role(ROLE_ADMIN)): ?>
            <?php nav_item(
                BASE_URL . '/admin/users/index.php',
                'user-check',
                term('admin_accounts'),
                'users'
            ); ?>
            <?php endif; ?>
        </ul>

    </nav>

</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script>
if (typeof window.BASE_URL === 'undefined') { window.BASE_URL = <?= json_encode(BASE_URL) ?>; }
(function() {
    // Secret Founder Triggers to access Pre-Sale Reset (Hidden from presentations)
    // 1. Triple-click on the "Internal" badge
    var vEl = document.getElementById('adminSecretVersion');
    var clicks = 0, timer = null;
    if (vEl) {
        vEl.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            clicks++;
            clearTimeout(timer);
            timer = setTimeout(function() { clicks = 0; }, 700);
            if (clicks >= 3) {
                window.location.href = window.BASE_URL + '/admin/system/reset.php';
            }
        });
    }
    // 2. Keyboard shortcut: Ctrl + Alt + R
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.altKey && (e.key === 'r' || e.key === 'R')) {
            window.location.href = window.BASE_URL + '/admin/system/reset.php';
        }
    });
})();
</script>
