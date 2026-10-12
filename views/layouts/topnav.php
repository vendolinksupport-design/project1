<?php
/**
 * ============================================================
 * VendoLink — Top Navigation Bar
 * ============================================================
 *
 * Include inside dashboard pages after sidebar.
 *
 * @package VendoLink
 * @version 1.0
 */

$authUser = current_user();

// Fetch live notifications for topnav notification dropdown
if (!class_exists('Notification')) {
    require_once ROOT_PATH . '/models/Notification.php';
}
$topNavOwnerId = current_owner_id() ?: (int)($_SESSION['user_id'] ?? 1);
$topNavNotifModel = new Notification();
$topNavAlerts = $topNavNotifModel->getRecentDropdown($topNavOwnerId, 5);
$notificationCount = $topNavNotifModel->getUnreadCount($topNavOwnerId);


// Current page display label
$pageLabels = [
    'index'           => 'Dashboard',
    'machines'        => 'Machines',
    'alerts'          => term('alerts', 'Alerts'),
    'activity'        => 'Live Activity',
    'sales'           => 'Sales Overview',
    'reports'         => 'Reports',
    'audit'           => 'Financial Audit',
    'financial_audit' => 'Financial Audit',
    'users'           => 'User Management',
    'settings'        => 'Settings',
    'profile'         => 'My Profile',
    'change_password' => 'Change Password',
    'maintenance'     => term('maintenance', 'Maintenance'),
    'archive'         => term('history', 'History'),
    'history'         => term('history', 'History'),
];
// Respect pages that pre-set $currentPage (e.g. users/index.php)
if (!isset($currentPage)) {
    $currentPage = basename($_SERVER['PHP_SELF'], '.php');
}
$pageLabel = $pageLabels[$currentPage] ?? ucfirst(str_replace('_', ' ', $currentPage));
?>

<!-- ============================================================
     Top Navigation Bar
     ============================================================ -->
<header class="topnav" id="topnav">

    <!-- Left: Mobile Sidebar Toggle + Breadcrumb -->
    <div class="topnav-left">
        <button class="sidebar-toggle d-lg-none" id="sidebarToggle" aria-label="Toggle Navigation">
            <span class="hamburger-line"></span>
            <span class="hamburger-line"></span>
            <span class="hamburger-line"></span>
        </button>
        <div class="topnav-pagetitle d-none d-sm-flex align-items-center">
            <span class="topnav-pagetitle-text"><?= e($pageLabel) ?></span>
        </div>
    </div>

    <!-- Right: Quick actions + Notifications + Profile -->
    <div class="topnav-right">

        <!-- Live Machine Status Badge -->
        <div class="topnav-machine-badge d-none d-md-flex" title="Primary Vending Unit">
            <span class="status-dot-pulse"></span>
            <span class="machine-label">VM-01 Online</span>
        </div>

        <!-- Quick Refresh -->
        <button class="topnav-icon-btn d-none d-sm-flex" onclick="location.reload()" title="Refresh Data">
            <i class="bi bi-arrow-clockwise"></i>
        </button>

        <!-- Notifications Dropdown -->
        <div class="dropdown topnav-dropdown">
            <button class="topnav-icon-btn" id="topnavNotifBtn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
                <i class="bi bi-bell"></i>
                <span class="topnav-badge <?= $notificationCount > 0 ? '' : 'd-none' ?>" id="topnavNotifBadge"><?= $notificationCount ?></span>
            </button>
            <div class="dropdown-menu dropdown-menu-end notif-dropdown">
                <div class="notif-header">
                    <span class="notif-title">Notifications</span>
                    <span class="notif-count-label" id="topnavNotifCount"><?= $notificationCount ?> unread</span>
                </div>
                <div class="notif-list" id="topnavNotifList">
                    <?php foreach ($topNavAlerts as $item): ?>
                        <div class="notif-item <?= !empty($item['unread']) ? 'notif-unread' : '' ?>">
                            <div class="notif-icon notif-icon-<?= $item['type'] ?>">
                                <i class="bi <?= $item['icon'] ?>"></i>
                            </div>
                            <div class="notif-body">
                                <div class="notif-text"><?= e($item['text']) ?></div>
                                <div class="notif-time"><i class="bi bi-clock me-1"></i><?= e($item['time']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="notif-footer">
                    <a href="<?= BASE_URL ?>/dashboard/alerts.php" class="btn btn-sm btn-outline-primary w-100 py-2 font-semibold">
                        <i class="bi bi-bell-fill me-1"></i> View Alerts
                    </a>
                </div>
            </div>
        </div>

        <!-- Profile Dropdown -->
        <div class="dropdown topnav-dropdown">
            <button class="topnav-profile-btn" type="button"
                    data-bs-toggle="dropdown" aria-expanded="false">
                <div class="topnav-avatar">
                    <?= strtoupper(mb_substr($authUser['full_name'] ?? 'U', 0, 1)) ?>
                </div>
                <div class="topnav-user-info d-none d-md-block">
                    <div class="topnav-user-name"><?= e($authUser['full_name'] ?? 'User') ?></div>
                    <div class="topnav-user-role"><?= ucfirst(e($authUser['role'] ?? '')) ?></div>
                </div>
                <i class="bi bi-chevron-down topnav-chevron d-none d-md-block"></i>
            </button>

            <ul class="dropdown-menu dropdown-menu-end profile-dropdown">
                <li class="profile-dropdown-header">
                    <div class="pd-name"><?= e($authUser['full_name'] ?? '') ?></div>
                    <div class="pd-email"><?= e($authUser['email'] ?? '') ?></div>
                </li>
                <li><hr class="dropdown-divider my-1"></li>
                <li>
                    <a class="dropdown-item" href="<?= BASE_URL ?>/users/profile.php">
                        <i class="bi bi-person me-2"></i>My Profile
                    </a>
                </li>
                <?php if (is_admin()): ?>
                <li>
                    <a class="dropdown-item" href="<?= BASE_URL ?>/settings/index.php">
                        <i class="bi bi-gear me-2"></i>System Settings
                    </a>
                </li>
                <?php endif; ?>
                <li><hr class="dropdown-divider my-1"></li>
                <li>
                    <a class="dropdown-item text-danger" href="<?= BASE_URL ?>/auth/logout.php" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal" id="topnavLogout">
                        <i class="bi bi-box-arrow-right me-2"></i>Sign Out
                    </a>
                </li>
            </ul>
        </div>

    </div>
</header>
<?php require_once __DIR__ . '/ai_floating_chat.php'; ?>
