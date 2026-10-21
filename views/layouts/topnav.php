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

// Resolve owner machines and active selection for topnav machine switcher
if (!class_exists('Machine')) {
    require_once ROOT_PATH . '/models/Machine.php';
}
$topNavMachineModel = new Machine();
$topNavMachines = $topNavMachineModel->getAllActive(current_owner_id());
$selectedMachineId = current_selected_machine_id(current_owner_id());
$selectedMachine = null;

if (!empty($topNavMachines)) {
    foreach ($topNavMachines as $m) {
        if ((int)$m['id'] === $selectedMachineId) {
            $selectedMachine = $m;
            break;
        }
    }
    if (!$selectedMachine) {
        $selectedMachine = $topNavMachines[0];
        $selectedMachineId = (int)$selectedMachine['id'];
        $_SESSION['selected_machine_id'] = $selectedMachineId;
    }
}


// Current page display label
$pageLabels = [
    'index'           => 'Dashboard',
    'machines'        => 'Machines',
    'alerts'          => term('alerts', 'Notifications'),
    'activity'        => 'Live Activity',
    'sales'           => 'Sales Overview',
    'reports'         => 'Reports',
    'audit'           => 'Financial Audit',
    'financial_audit' => 'Financial Audit',
    'users'           => 'User Management',
    'settings'        => 'Settings',
    'profile'         => 'My Profile',
    'change_password' => 'Change Password',
    'maintenance'     => term('service_reminders', 'Service Reminders'),
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

    <!-- Left: Mobile Sidebar Toggle + Balanced Utility Unit Switcher + Desktop Page Title -->
    <div class="topnav-left">
        <button class="sidebar-toggle d-lg-none" id="sidebarToggle" aria-label="Toggle Navigation">
            <span class="hamburger-line"></span>
            <span class="hamburger-line"></span>
            <span class="hamburger-line"></span>
        </button>

        <!-- Balanced Utility Header: Unit Selector (Design 6) -->
        <div class="dropdown topnav-dropdown topnav-machine-switcher">
            <?php if (!empty($selectedMachine)): 
                $selStatus = $selectedMachine['calculated_status'] ?? 'offline';
                $statusDotClass = ($selStatus === 'online') ? 'status-online' : (($selStatus === 'stale') ? 'status-warning' : 'status-offline');
                $machineTitle = !empty($selectedMachine['name']) ? $selectedMachine['name'] : ($selectedMachine['machine_code'] ?? 'Machine Unit');
                $machineShort = !empty($selectedMachine['machine_code']) 
                    ? $selectedMachine['machine_code'] 
                    : (preg_match('/([A-Za-z0-9_-]+)$/', $selectedMachine['name'] ?? '', $mMatches) ? $mMatches[1] : $machineTitle);
            ?>
                <button class="topnav-machine-btn balanced-utility-btn" type="button" id="topnavMachineSwitcherBtn" data-bs-toggle="dropdown" aria-expanded="false" title="Current Machine: <?= e($machineTitle) ?>">
                    <span class="unit-caption">SELECTED UNIT</span>
                    <span class="unit-value-row">
                        <span class="status-dot <?= $statusDotClass ?>"></span>
                        <span class="machine-label-short d-inline d-md-none"><?= e($machineShort) ?></span>
                        <span class="machine-label-full d-none d-md-inline text-truncate"><?= e($machineTitle) ?></span>
                        <i class="bi bi-chevron-down ms-0.5 unit-chevron"></i>
                    </span>
                </button>
            <?php else: ?>
                <button class="topnav-machine-btn balanced-utility-btn" type="button" id="topnavMachineSwitcherBtn" data-bs-toggle="dropdown" aria-expanded="false" title="No Machines Assigned">
                    <span class="unit-caption">SELECTED UNIT</span>
                    <span class="unit-value-row">
                        <span class="status-dot status-offline"></span>
                        <span class="machine-label text-muted fst-italic">No Machine</span>
                        <i class="bi bi-chevron-down ms-0.5 unit-chevron"></i>
                    </span>
                </button>
            <?php endif; ?>

            <div class="dropdown-menu dropdown-menu-start machine-switcher-menu shadow-lg" aria-labelledby="topnavMachineSwitcherBtn">
                <div class="machine-switcher-header">
                    <div>
                        <span class="machine-switcher-title fw-bold">My Machines</span>
                        <div class="machine-switcher-sub small text-muted">
                            <?= !empty($selectedMachine) ? 'Current: <strong class="text-light">' . e($machineTitle) . '</strong>' : 'No hardware active' ?>
                        </div>
                    </div>
                    <?php if (!empty($topNavMachines)): ?>
                        <span class="badge bg-primary bg-opacity-25 text-info font-monospace rounded-pill"><?= count($topNavMachines) ?> Unit<?= count($topNavMachines) === 1 ? '' : 's' ?></span>
                    <?php endif; ?>
                </div>

                <div class="machine-switcher-list">
                    <?php if (empty($topNavMachines)): ?>
                        <div class="p-3 text-center text-secondary small">
                            <i class="bi bi-cpu fs-4 d-block mb-1 text-muted"></i>
                            No machines connected yet.
                        </div>
                    <?php else: ?>
                        <?php foreach ($topNavMachines as $m): 
                            $mStat = $m['calculated_status'] ?? 'offline';
                            $isSelected = (!empty($selectedMachine) && (int)$m['id'] === (int)$selectedMachine['id']);
                            $mDot = ($mStat === 'online') ? 'status-online' : (($mStat === 'stale') ? 'status-warning' : 'status-offline');
                            $mBadgeClass = ($mStat === 'online') ? 'bg-success text-success' : (($mStat === 'stale') ? 'bg-warning text-warning' : 'bg-secondary text-secondary');
                            $mLabel = !empty($m['name']) ? $m['name'] : $m['machine_code'];
                            $mSub = (!empty($m['name']) && !empty($m['machine_code']) && $m['name'] !== $m['machine_code']) ? $m['machine_code'] : ($m['device_mac'] ?? 'Hardware Unit');
                        ?>
                            <a class="machine-switcher-item <?= $isSelected ? 'active' : '' ?>"
                               href="<?= BASE_URL ?>/dashboard/switch_machine.php?id=<?= (int)$m['id'] ?>&return_to=<?= urlencode($_SERVER['REQUEST_URI'] ?? (BASE_URL . '/dashboard/machines.php')) ?>">
                                <div class="d-flex align-items-center justify-content-between w-100 gap-2">
                                    <div class="d-flex align-items-center gap-2 min-w-0">
                                        <span class="status-dot <?= $mDot ?>"></span>
                                        <div class="min-w-0 text-truncate">
                                            <div class="machine-item-name text-truncate <?= $isSelected ? 'fw-bold text-info' : 'text-light' ?>">
                                                <?= e($mLabel) ?>
                                            </div>
                                            <div class="machine-item-sub font-monospace text-muted small">
                                                <?= e($mSub) ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                        <span class="badge <?= $mBadgeClass ?> bg-opacity-10 border border-opacity-25 rounded-pill px-2 py-0.5" style="font-size: 0.7rem;">
                                            <?= ucfirst($mStat) ?>
                                        </span>
                                        <?php if ($isSelected): ?>
                                            <i class="bi bi-check2 text-info fs-5"></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="topnav-pagetitle d-none d-lg-flex align-items-center ms-3 ps-3 border-start border-secondary border-opacity-25">
            <span class="topnav-pagetitle-text"><?= e($pageLabel) ?></span>
        </div>
    </div>

    <!-- Right: Quick actions + Notifications + Profile -->
    <div class="topnav-right">

        <!-- Quick Refresh -->
        <button class="topnav-icon-btn d-none d-sm-flex" onclick="location.reload()" title="Refresh Data">
            <?= lucide_icon('rotate-cw') ?>
        </button>

        <!-- Notifications Dropdown -->
        <div class="dropdown topnav-dropdown">
            <button class="topnav-icon-btn" id="topnavNotifBtn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
                <?= lucide_icon('bell') ?>
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
                                <?= lucide_icon($item['icon']) ?>
                            </div>
                            <div class="notif-body">
                                <div class="notif-text"><?= e($item['text']) ?></div>
                                <div class="notif-time"><?= lucide_icon('clock', 'me-1') ?><?= e($item['time']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="notif-footer">
                    <a href="<?= BASE_URL ?>/dashboard/alerts.php" class="btn btn-sm btn-outline-primary w-100 py-2 font-semibold">
                        <?= lucide_icon('bell', 'me-1') ?> View Notifications
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
                <?= lucide_icon('chevron-down', 'topnav-chevron d-none d-md-block') ?>
            </button>

            <ul class="dropdown-menu dropdown-menu-end profile-dropdown">
                <li class="profile-dropdown-header">
                    <div class="pd-name"><?= e($authUser['full_name'] ?? '') ?></div>
                    <div class="pd-email"><?= e($authUser['email'] ?? '') ?></div>
                </li>
                <li><hr class="dropdown-divider my-1"></li>
                <li>
                    <a class="dropdown-item" href="<?= BASE_URL ?>/users/profile.php">
                        <?= lucide_icon('user', 'me-2') ?>My Profile
                    </a>
                </li>
                <?php if (is_admin()): ?>
                <li>
                    <a class="dropdown-item" href="<?= BASE_URL ?>/settings/index.php">
                        <?= lucide_icon('settings', 'me-2') ?>System Settings
                    </a>
                </li>
                <?php endif; ?>
                <li><hr class="dropdown-divider my-1"></li>
                <li>
                    <a class="dropdown-item text-danger" href="<?= BASE_URL ?>/auth/logout.php" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal" id="topnavLogout">
                        <?= lucide_icon('log-out', 'me-2') ?>Sign Out
                    </a>
                </li>
            </ul>
        </div>

    </div>
</header>
<?php require_once __DIR__ . '/ai_floating_chat.php'; ?>
<?php require_once __DIR__ . '/../partials/modal_add_machine.php'; ?>
