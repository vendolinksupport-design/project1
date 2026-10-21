<?php
/**
 * ============================================================
 * VendoLink — System Alerts & Notifications Center
 * ============================================================
 *
 * Real-time monitoring hub for hardware telemetry, low tank level floaters,
 * and connection logs.
 *
 * @package VendoLink
 * @version 2.5
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Maintenance.php';
require_once __DIR__ . '/../models/Notification.php';

// Requires authentication
require_auth();

$transactionModel  = new Transaction();
$maintenanceModel  = new Maintenance();
$notificationModel = new Notification();

$ownerId       = current_owner_id() ?: (int)($_SESSION['user_id'] ?? 1);
$rawFilter     = strtolower(trim((string)($_GET['filter'] ?? 'all')));
if (in_array($rawFilter, ['logs', 'system_logs', 'system'], true)) {
    $activeFilter = 'hardware';
} elseif (in_array($rawFilter, ['critical', 'hardware', 'maintenance'], true)) {
    $activeFilter = $rawFilter;
} else {
    $activeFilter = 'all';
}

// Handle "Mark All as Read" action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
    $notificationModel->markAllAsRead($ownerId);
    $_SESSION['notifications_read_at'] = time();
    flash('success', 'All system notifications marked as read.');
    redirect('dashboard/alerts.php' . ($activeFilter !== 'all' ? '?filter=' . urlencode($activeFilter) : ''));
}

// Counts for Summary Metrics directly from Notification model
$summaryMetrics = $notificationModel->getSummaryCounts($ownerId);
$totalAlerts     = $summaryMetrics['total'];
$criticalCount   = $summaryMetrics['critical'];
$hardwareCount   = $summaryMetrics['hardware'];

// Pagination parameters (15 alerts per page maximum)
$perPage            = 15;
$totalFilteredItems = $notificationModel->getTotalCount($ownerId, $activeFilter);
$totalPages         = max(1, (int) ceil($totalFilteredItems / $perPage));
$page               = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$offset             = ($page - 1) * $perPage;

$pagedNotifications = $notificationModel->getForOwner($ownerId, $activeFilter, $perPage, $offset);


if (!function_exists('build_alerts_url')) {
    function build_alerts_url(array $overrides = []): string
    {
        global $activeFilter;
        $params = array_merge(['filter' => $activeFilter], $_GET, $overrides);
        if (isset($params['filter']) && $params['filter'] === 'all') {
            unset($params['filter']);
        }
        return 'alerts.php' . (!empty($params) ? '?' . http_build_query($params) : '');
    }
}

$pageTitle   = term('alerts', 'Notifications');
$currentPage = 'alerts';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= APP_NAME ?> — Notifications">
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
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2338bdf8' stroke-width='2'><path d='M13 2L3 14h9l-1 8 10-12h-9l1-8z'/></svg>">
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
                    <h1 class="page-title">
                        <i class="bi bi-bell-fill me-2" style="color: var(--vl-orange);"></i><?= term('alerts', 'Notifications') ?>
                    </h1>
                    <p class="page-subtitle">
                        Stay informed about tank levels, hardware status, and important system events.
                    </p>
                </div>
                <div class="page-header-actions d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-sm btn-info text-dark font-semibold d-none" style="display: none !important;" onclick="testLiveNotification()">
                        <i class="bi bi-bell-ring-fill me-1"></i> Test Live Notification
                    </button>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="mark_all_read">
                        <button type="submit" class="btn btn-sm btn-outline-secondary font-semibold">
                            <i class="bi bi-check2-all me-1"></i> Mark All as Read
                        </button>
                    </form>
                    <a href="<?= BASE_URL ?>/dashboard/alerts.php" class="btn btn-sm btn-primary font-semibold" id="btnRefreshAlerts" onclick="if(window.VendoLink && window.VendoLink.refreshAlertsFeed){event.preventDefault();window.VendoLink.refreshAlertsFeed();}">
                        <i class="bi bi-arrow-clockwise me-1"></i> Refresh Feed
                    </a>
                </div>
            </div>

            <!-- Top KPI Summary Cards (3 Equal Columns - Click to Filter) -->
            <div class="row g-3 mb-4">

                <!-- Total Alerts -->
                <div class="col-12 col-md-4">
                    <a href="<?= build_alerts_url(['filter' => 'all', 'page' => 1]) ?>" class="text-decoration-none d-block h-100" title="Filter by All Alerts">
                        <div class="stat-card stat-card-accent-info <?= ($activeFilter === 'all') ? 'border-info' : '' ?>" style="<?= ($activeFilter === 'all') ? 'box-shadow: 0 0 15px rgba(56, 189, 248, 0.25); border: 1px solid rgba(56, 189, 248, 0.5) !important;' : '' ?>">
                            <div class="stat-card-body">
                                <div class="stat-card-info">
                                    <p class="stat-card-label" data-original="TOTAL NOTIFICATIONS">TOTAL ALERTS</p>
                                    <h3 class="stat-card-value text-info" id="kpiTotalAlerts"><?= $totalAlerts ?></h3>
                                    <p class="stat-card-trend">
                                        <span class="text-muted small">Monitored system events</span>
                                    </p>
                                </div>
                                <div class="stat-card-icon" style="background: rgba(56, 189, 248, 0.15); color: var(--vl-cyan);">
                                    <i class="bi bi-bell"></i>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>

                <!-- Critical Alerts -->
                <div class="col-12 col-md-4">
                    <a href="<?= build_alerts_url(['filter' => 'critical', 'page' => 1]) ?>" class="text-decoration-none d-block h-100" title="Filter by Critical Alerts">
                        <div class="stat-card stat-card-accent-danger <?= ($activeFilter === 'critical') ? 'border-danger' : '' ?>" style="<?= ($activeFilter === 'critical') ? 'box-shadow: 0 0 15px rgba(239, 68, 68, 0.25); border: 1px solid rgba(239, 68, 68, 0.5) !important;' : '' ?>">
                            <div class="stat-card-body">
                                <div class="stat-card-info">
                                    <p class="stat-card-label" data-original="CRITICAL NOTIFICATIONS">CRITICAL ALERTS</p>
                                    <h3 class="stat-card-value text-danger" id="kpiCriticalCount"><?= $criticalCount ?></h3>
                                    <p class="stat-card-trend">
                                        <span class="text-muted small">Low reservoir level warnings</span>
                                    </p>
                                </div>
                                <div class="stat-card-icon" style="background: rgba(239, 68, 68, 0.15); color: #f87171;">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>

                <!-- Hardware Notifications / System Logs -->
                <div class="col-12 col-md-4">
                    <a href="<?= build_alerts_url(['filter' => 'hardware', 'page' => 1]) ?>" class="text-decoration-none d-block h-100" title="Filter by System Logs">
                        <div class="stat-card stat-card-accent-warning <?= ($activeFilter === 'hardware') ? 'border-warning' : '' ?>" style="<?= ($activeFilter === 'hardware') ? 'box-shadow: 0 0 15px rgba(245, 158, 11, 0.25); border: 1px solid rgba(245, 158, 11, 0.5) !important;' : '' ?>">
                            <div class="stat-card-body">
                                <div class="stat-card-info">
                                    <p class="stat-card-label" data-original="HARDWARE NOTIFICATIONS">SYSTEM LOGS</p>
                                    <h3 class="stat-card-value text-warning" id="kpiHardwareCount"><?= $hardwareCount ?></h3>
                                    <p class="stat-card-trend">
                                        <span class="text-muted small">Restorations, connectivity &amp; activity logs</span>
                                    </p>
                                </div>
                                <div class="stat-card-icon" style="background: rgba(245, 158, 11, 0.15); color: #fbbf24;">
                                    <i class="bi bi-journal-text"></i>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>

            </div>

            <!-- Filter Tabs & Main Panel -->
            <div class="report-section-card alert-stream-card mb-4">
                <div class="breakdown-header border-bottom border-secondary border-opacity-25 pb-3 mb-3">
                    <div>
                        <h5 class="panel-title mb-1">
                            <i class="bi bi-list-stars me-2" style="color: var(--vl-cyan);"></i>Notification Feed
                        </h5>
                        <p class="text-muted small mb-0">
                            A chronological list of system notifications and important events.
                        </p>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <a class="btn btn-sm <?= ($activeFilter === 'all') ? 'btn-primary' : 'btn-outline-secondary' ?> font-semibold"
                           href="<?= build_alerts_url(['filter' => 'all', 'page' => 1]) ?>">
                            All (<span id="filterAllCount"><?= $totalAlerts ?></span>)
                        </a>
                        <a class="btn btn-sm <?= ($activeFilter === 'critical') ? 'btn-danger' : 'btn-outline-danger' ?> font-semibold"
                           href="<?= build_alerts_url(['filter' => 'critical', 'page' => 1]) ?>">
                            Critical Alerts (<span id="filterCriticalCount"><?= $criticalCount ?></span>)
                        </a>
                        <a class="btn btn-sm <?= ($activeFilter === 'hardware') ? 'btn-warning text-dark' : 'btn-outline-warning' ?> font-semibold"
                           href="<?= build_alerts_url(['filter' => 'hardware', 'page' => 1]) ?>">
                            System Logs (<span id="filterHardwareCount"><?= $hardwareCount ?></span>)
                        </a>
                    </div>
                </div>

                <!-- Notifications Stream List -->
                <div class="alert-stream-container">
                    <?php if (empty($pagedNotifications)): ?>
                        <div class="alert-stream-empty" id="alertStreamEmpty">
                            <i class="bi bi-bell-slash text-muted display-4 d-block mb-3"></i>
                            <h5 class="text-light font-semibold mb-1">No Notifications Found</h5>
                            <p class="text-muted small mb-0">There are no notifications at this time.</p>
                        </div>
                    <?php else: ?>
                        <div class="alert-stream-list" id="alertStreamList" data-last-id="<?= !empty($pagedNotifications) ? (int)$pagedNotifications[0]['id'] : 0 ?>">
                            <?php foreach ($pagedNotifications as $n): 
                                $timeVal = !empty($n['created_at']) ? $n['created_at'] : (!empty($n['timestamp']) ? $n['timestamp'] : date('Y-m-d H:i:s'));
                                $isUnread = empty($n['is_read']);
                            ?>
                                <div class="alert-card-item p-3 rounded-3 d-flex align-items-start gap-3 <?= $isUnread ? 'border border-info border-opacity-50' : '' ?>" id="alert-card-<?= (int)$n['id'] ?>">
                                    
                                    <!-- Alert Type Icon -->
                                    <div class="notif-icon notif-icon-<?= $n['type'] ?> p-2 rounded-3 flex-shrink-0">
                                        <i class="bi <?= $n['icon'] ?> fs-5"></i>
                                    </div>

                                    <!-- Alert Content -->
                                    <div class="flex-grow-1 min-w-0">
                                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
                                            <h6 class="font-bold mb-0 text-light me-2">
                                                <?= e($n['title']) ?>
                                                <?php if ($isUnread): ?>
                                                    <span class="badge bg-info bg-opacity-25 text-info border border-info border-opacity-25 ms-1" style="font-size: 0.7rem;">NEW</span>
                                                <?php endif; ?>
                                            </h6>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25">
                                                    <?= e($n['badge']) ?>
                                                </span>
                                                <span class="text-muted small">
                                                    <i class="bi bi-clock me-1"></i><?= date('M j, Y g:i A', strtotime($timeVal)) ?>
                                                </span>
                                            </div>
                                        </div>

                                        <p class="text-muted mb-0 small line-height-md">
                                            <?= e($n['message']) ?>
                                        </p>
                                    </div>

                                </div>
                            <?php endforeach; ?>

                            <?php
                            $emptySlotsCount = max(0, $perPage - count($pagedNotifications));
                            for ($si = 0; $si < $emptySlotsCount; $si++):
                            ?>
                            <div class="alert-card-spacer" aria-hidden="true"></div>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Pagination Controls -->
                <?php if ($totalPages > 1): ?>
                <div class="tx-pagination mt-4 pt-3 border-top border-secondary border-opacity-25">
                    <div class="text-muted small">
                        Showing <strong><?= number_format(count($pagedNotifications)) ?></strong> of <strong><?= number_format($totalFilteredItems) ?></strong> notifications (Page <?= $page ?> of <?= $totalPages ?>)
                    </div>
                    <nav aria-label="Notifications pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <!-- Previous -->
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= build_alerts_url(['page' => $page - 1]) ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;&nbsp;Prev</span>
                                </a>
                            </li>

                            <!-- Page Numbers -->
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage   = min($totalPages, $page + 2);

                            if ($startPage > 1) {
                                echo '<li class="page-item"><a class="page-link" href="' . build_alerts_url(['page' => 1]) . '">1</a></li>';
                                if ($startPage > 2) {
                                    echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                }
                            }

                            for ($p = $startPage; $p <= $endPage; $p++): ?>
                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= build_alerts_url(['page' => $p]) ?>"><?= $p ?></a>
                            </li>
                            <?php endfor;

                            if ($endPage < $totalPages) {
                                if ($endPage < $totalPages - 1) {
                                    echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                }
                                echo '<li class="page-item"><a class="page-link" href="' . build_alerts_url(['page' => $totalPages]) . '">' . $totalPages . '</a></li>';
                            }
                            ?>

                            <!-- Next -->
                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= build_alerts_url(['page' => $page + 1]) ?>" aria-label="Next">
                                    <span aria-hidden="true">Next&nbsp;&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>

        </main>

        <!-- Page Footer -->
        <footer class="app-footer">
            <span><?= APP_NAME ?> v<?= APP_VERSION ?> &copy; <?= date('Y') ?></span>
            <span>Powered by VendoCore IoT Controller</span>
        </footer>

    </div><!-- /.main-content -->

</div><!-- /.app-shell -->

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- VendoLink JS -->
<script src="<?= ASSETS_URL ?>/js/app.js?v=<?= e((string) filemtime(ROOT_PATH . '/assets/js/app.js')) ?>"></script>
<script>
function testLiveNotification() {
    // 1. Web Dashboard Toast Alert
    if (window.VendoLink && typeof window.VendoLink.toast === 'function') {
        window.VendoLink.toast('[Alert] PWA Alert: Low Water Reservoir Floater Test Triggered!', 'warning', 6000);
    }

    // 2. Native System Background & Lockscreen Push Notification (Service Worker)
    if (window.VendoLink && typeof window.VendoLink.sendBackgroundNotification === 'function') {
        window.VendoLink.sendBackgroundNotification(
            '[CRITICAL] Low Water Reservoir Level',
            'Water tank floater (GPIO 13) triggered LOW on VendoLink Machine 01. Refill immediately.',
            '<?= BASE_URL ?>/dashboard/alerts.php'
        );
    }
}
</script>
</body>
</html>
