<?php
/**
 * ============================================================
 * VendoLink — History Archive
 * ============================================================
 *
 * Read-only historical record repository containing:
 * 1. Transaction History (Service transactions & rate snapshots)
 * 2. Maintenance History (Completed maintenance records & runtimes)
 * 3. Configuration History (Service pricing & time change audits)
 *
 * Strictly read-only to preserve a reliable audit trail.
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Archive.php';
require_once __DIR__ . '/../models/Service.php';
require_once __DIR__ . '/../models/Transaction.php';

// ---- Role Guard ----
require_role([ROLE_ADMIN, ROLE_OWNER]);

$pageTitle   = term('history', 'History');
$currentPage = 'archive';
$user        = current_user();
$ownerId     = current_owner_id();

$archiveModel = new Archive();
$serviceModel = new Service();
$servicesList = (new Transaction())->getServices($ownerId);

// ---- Active Tab ----
$activeTab = $_GET['tab'] ?? 'transactions';
if (!in_array($activeTab, ['transactions', 'maintenance', 'configuration'], true)) {
    $activeTab = 'transactions';
}

// ---- Common Filters ----
$search   = trim($_GET['search'] ?? '');
$service  = trim($_GET['service'] ?? 'all');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = max(1, min(100, (int)($_GET['per_page'] ?? 15)));

$filters = [
    'search'    => $search,
    'service'   => $service,
    'date_from' => $dateFrom,
    'date_to'   => $dateTo,
];

// ---- Handle CSV / Excel Export Actions ----
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    $txModel = new Transaction();
    if ($exportType === 'sales_report') {
        $txModel->exportSalesReport($filters, $ownerId);
        exit;
    } elseif ($exportType === 'transactions' || $exportType === 'csv' || $exportType === 'excel') {
        $txModel->exportTransactionsCsv($filters, $ownerId);
        exit;
    }
}

// ---- Fetch Data for Current Tab ----
$cashCollections      = [];
$totalCashCollections = 0;
$ccPerPage            = 10;
$ccTotalPages         = 1;
$ccCurrentPage        = 1;
$pagedCashCollections = [];
$historySummary       = [
    'total_revenue'     => 0.00,
    'transaction_count' => 0,
    'completed_count'   => 0,
    'water_revenue'     => 0.00,
    'water_count'       => 0,
    'soap_revenue'      => 0.00,
    'soap_count'        => 0,
];

if ($activeTab === 'transactions') {
    $result               = $archiveModel->getTransactionHistory($filters, $page, $perPage, $ownerId);
    $transactionModel     = new Transaction();
    $historySummary       = $transactionModel->getAuditMetrics($filters, $ownerId);
    $cashCollections      = $transactionModel->getCashCollections(0, $ownerId, $filters);
    $totalCashCollections = count($cashCollections);
    $ccTotalPages         = max(1, (int)ceil($totalCashCollections / $ccPerPage));
    $ccCurrentPage        = max(1, min($ccTotalPages, (int)($_GET['ccpage'] ?? 1)));
    $ccOffset             = ($ccCurrentPage - 1) * $ccPerPage;
    $pagedCashCollections = array_slice($cashCollections, $ccOffset, $ccPerPage);
} elseif ($activeTab === 'maintenance') {
    $result = $archiveModel->getMaintenanceHistory($filters, $page, $perPage, $ownerId);
} else {
    $result = $archiveModel->getConfigurationHistory($filters, $page, $perPage, $ownerId);
}

$records    = $result['items'];
$totalItems = $result['total_items'];
$totalPages = $result['total_pages'];
$page       = $result['page'];

/**
 * Helper to build URL preserving filters and active tab.
 */
if (!function_exists('build_archive_url')) {
    function build_archive_url(array $overrides = []): string
    {
        $params = array_merge($_GET, $overrides);
        $clean = [];
        foreach ($params as $k => $v) {
            if ($v !== '' && $v !== null && $v !== 'all') {
                $clean[$k] = $v;
            }
        }
        return 'archive.php' . (!empty($clean) ? '?' . http_build_query($clean) : '');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= APP_NAME ?> — <?= term('history', 'History') ?>">
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
                        <i class="bi bi-clock-history me-2"></i><?= term('history', 'History') ?>
                    </h1>
                    <p class="page-subtitle">
                        Searchable records of completed transactions, maintenance events, and service configuration changes.
                    </p>
                </div>
                <div class="page-header-actions">
                    <span class="readonly-indicator">
                        <i class="bi bi-shield-lock me-1"></i>Audit Records
                    </span>
                    <div class="page-date">
                        <i class="bi bi-calendar3 me-1"></i><?= date('l, F j, Y') ?>
                    </div>
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="archive-tab-nav">
                <a href="<?= build_archive_url(['tab' => 'transactions', 'page' => 1]) ?>"
                   class="archive-tab-btn <?= $activeTab === 'transactions' ? 'active' : '' ?>">
                    <i class="bi bi-cash-coin"></i>
                    <span>Transaction History</span>
                </a>
                <a href="<?= build_archive_url(['tab' => 'maintenance', 'page' => 1]) ?>"
                   class="archive-tab-btn <?= $activeTab === 'maintenance' ? 'active' : '' ?>">
                    <i class="bi bi-wrench-adjustable-circle"></i>
                    <span>Maintenance History</span>
                </a>
                <a href="<?= build_archive_url(['tab' => 'configuration', 'page' => 1]) ?>"
                   class="archive-tab-btn <?= $activeTab === 'configuration' ? 'active' : '' ?>">
                    <i class="bi bi-sliders"></i>
                    <span>Configuration History</span>
                </a>
            </div>

            <?php if ($activeTab === 'transactions'): ?>
            <!-- Summary Cards Row: Total Sales | Completed Transactions | Water Sales | Soap Sales -->
            <div class="row g-4 mb-4">

                <!-- 1. Total Sales Amount -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card stat-card-sales">
                        <div class="stat-card-body">
                            <div class="stat-card-info">
                                <p class="stat-card-label text-white">Total Sales</p>
                                <h3 class="stat-card-value text-success"><?= format_currency($historySummary['total_revenue']) ?></h3>
                                <p class="stat-card-trend text-light">
                                    <span class="trend-badge trend-up">
                                        <i class="bi bi-check-circle-fill"></i> Verified
                                    </span>
                                    <?= number_format($historySummary['completed_count']) ?> completed tx
                                </p>
                            </div>
                            <div class="stat-card-icon">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                        </div>
                        <div class="stat-card-footer">
                            <span class="stat-card-link text-light">
                                Verified completed revenue
                            </span>
                        </div>
                    </div>
                </div>

                <!-- 2. Completed Transactions -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card stat-card-online">
                        <div class="stat-card-body">
                            <div class="stat-card-info">
                                <p class="stat-card-label text-white">Completed Transactions</p>
                                <h3 class="stat-card-value text-white"><?= number_format($historySummary['completed_count']) ?></h3>
                                <p class="stat-card-trend text-light">
                                    <span class="trend-badge trend-neutral">
                                        <i class="bi bi-receipt"></i> Total Logged
                                    </span>
                                    <?= number_format($historySummary['transaction_count']) ?> all statuses
                                </p>
                            </div>
                            <div class="stat-card-icon">
                                <i class="bi bi-receipt-cutoff"></i>
                            </div>
                        </div>
                        <div class="stat-card-footer">
                            <span class="stat-card-link text-light">
                                <?= $historySummary['transaction_count'] > 0 ? round(($historySummary['completed_count'] / $historySummary['transaction_count']) * 100, 1) : 100 ?>% completion rate
                            </span>
                        </div>
                    </div>
                </div>

                <!-- 3. Water Service Sales -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card stat-card-notif" style="border-top-color: #38bdf8;">
                        <div class="stat-card-body">
                            <div class="stat-card-info">
                                <p class="stat-card-label text-white">Water Sales</p>
                                <h3 class="stat-card-value" style="color: #38bdf8;"><?= format_currency($historySummary['water_revenue']) ?></h3>
                                <p class="stat-card-trend text-light">
                                    <span class="trend-badge trend-neutral">
                                        <i class="bi bi-droplet-fill text-info"></i> <?= number_format($historySummary['water_count']) ?> sales
                                    </span>
                                    water service
                                </p>
                            </div>
                            <div class="stat-card-icon" style="background: rgba(56,189,248,.1); color: #38bdf8;">
                                <i class="bi bi-droplet-fill"></i>
                            </div>
                        </div>
                        <div class="stat-card-footer">
                            <a href="<?= build_archive_url(['service' => 'water', 'page' => 1]) ?>" class="stat-card-link text-light">
                                Filter water history <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- 4. Soap Service Sales -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card stat-card-notif" style="border-top-color: #c084fc;">
                        <div class="stat-card-body">
                            <div class="stat-card-info">
                                <p class="stat-card-label text-white">Soap Sales</p>
                                <h3 class="stat-card-value" style="color: #c084fc;"><?= format_currency($historySummary['soap_revenue']) ?></h3>
                                <p class="stat-card-trend text-light">
                                    <span class="trend-badge trend-neutral">
                                        <i class="bi bi-stars text-warning"></i> <?= number_format($historySummary['soap_count']) ?> sales
                                    </span>
                                    soap service
                                </p>
                            </div>
                            <div class="stat-card-icon" style="background: rgba(192,132,252,.1); color: #c084fc;">
                                <i class="bi bi-stars"></i>
                            </div>
                        </div>
                        <div class="stat-card-footer">
                            <a href="<?= build_archive_url(['service' => 'soap', 'page' => 1]) ?>" class="stat-card-link text-light">
                                Filter soap history <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>

            </div>
            <?php endif; ?>

            <!-- Simple Filter Card -->
            <div class="tx-filter-card mb-4">
                <form method="get" action="archive.php" class="row g-3 align-items-end">
                    <input type="hidden" name="tab" value="<?= e($activeTab) ?>">

                    <!-- Search Input -->
                    <div class="col-12 <?= in_array($activeTab, ['transactions', 'configuration'], true) ? 'col-md-4 col-xl-4' : 'col-md-5 col-xl-5' ?>">
                        <label class="form-label text-muted small mb-1">Search</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text dark-input" style="border-right: 0;">
                                <i class="bi bi-search text-muted"></i>
                            </span>
                            <input type="text" class="form-control dark-input" name="search"
                                   placeholder="<?= $activeTab === 'transactions' ? 'Search ID (#00001) or service...' : ($activeTab === 'maintenance' ? 'Search maintenance name or notes...' : 'Search service or user...') ?>"
                                   value="<?= e($search) ?>" style="border-left: 0;">
                        </div>
                    </div>

                    <!-- Service Filter (where applicable) -->
                    <?php if (in_array($activeTab, ['transactions', 'configuration'], true)): ?>
                    <div class="col-12 col-sm-6 col-md-3 col-xl-2">
                        <label class="form-label text-muted small mb-1">Service</label>
                        <select class="form-select form-select-sm dark-input" name="service">
                            <option value="all" <?= $service === 'all' ? 'selected' : '' ?>>All Services</option>
                            <?php foreach ($servicesList as $svc): ?>
                                <option value="<?= e($svc['service_key']) ?>" <?= $service === $svc['service_key'] ? 'selected' : '' ?>>
                                    <?= e($svc['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <!-- Date From -->
                    <div class="col-12 col-sm-6 <?= in_array($activeTab, ['transactions', 'configuration'], true) ? 'col-md-2 col-xl-2' : 'col-md-3 col-xl-3' ?>">
                        <label class="form-label text-muted small mb-1">From Date</label>
                        <input type="date" class="form-control form-control-sm dark-input" name="date_from" value="<?= e($dateFrom) ?>">
                    </div>

                    <!-- Date To -->
                    <div class="col-12 col-sm-6 <?= in_array($activeTab, ['transactions', 'configuration'], true) ? 'col-md-2 col-xl-2' : 'col-md-3 col-xl-3' ?>">
                        <label class="form-label text-muted small mb-1">To Date</label>
                        <input type="date" class="form-control form-control-sm dark-input" name="date_to" value="<?= e($dateTo) ?>">
                    </div>

                    <!-- Action Buttons -->
                    <div class="col-12 col-sm-6 col-md-1 col-xl-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill">
                            <i class="bi bi-funnel me-1"></i>Apply
                        </button>
                        <?php if (!empty($search) || $service !== 'all' || !empty($dateFrom) || !empty($dateTo)): ?>
                        <a href="archive.php?tab=<?= e($activeTab) ?>" class="btn btn-outline-secondary btn-sm" title="Clear Filters">
                            <i class="bi bi-x-circle"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Main Records Panel -->
            <div class="tx-table-card archive-table-card mb-4">
                <div class="panel-header d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                    <div>
                        <h5 class="panel-title mb-1">
                            <?php if ($activeTab === 'transactions'): ?>
                                <i class="bi bi-cash-coin me-2"></i>Transaction History
                            <?php elseif ($activeTab === 'maintenance'): ?>
                                <i class="bi bi-wrench-adjustable-circle me-2"></i>Maintenance History
                            <?php else: ?>
                                <i class="bi bi-sliders me-2"></i>Configuration History
                            <?php endif; ?>
                        </h5>
                        <span class="text-muted small">
                            Showing <?= number_format(count($records)) ?> of <?= number_format($totalItems) ?> records
                        </span>
                    </div>
                    <?php if ($activeTab === 'transactions'): ?>
                    <div class="d-flex align-items-center gap-2">
                        <a href="<?= build_archive_url(['export' => 'excel']) ?>" class="btn btn-sm btn-outline-success text-nowrap d-inline-flex align-items-center" title="Export filtered records to Excel">
                            <i class="bi bi-file-earmark-excel me-1.5"></i>Export Excel
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (empty($records)): ?>
                <div class="activity-empty py-5">
                    <i class="bi bi-archive" style="font-size: 3rem; color: var(--vl-text-muted);"></i>
                    <p class="text-muted mt-2">No historical archive records found matching your filters.</p>
                    <?php if (!empty($search) || $service !== 'all' || !empty($dateFrom) || !empty($dateTo)): ?>
                    <a href="archive.php?tab=<?= e($activeTab) ?>" class="btn btn-sm btn-outline-secondary mt-3">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Clear Filters
                    </a>
                    <?php endif; ?>
                </div>
                <?php else: ?>

                <!-- SECTION 1: TRANSACTION HISTORY -->
                <?php if ($activeTab === 'transactions'): ?>
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle mb-0" style="--bs-table-bg: transparent;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--vl-border); color: var(--vl-text-muted); font-size: .78rem; text-transform: uppercase; letter-spacing: .06em;">
                                <th class="ps-4">Transaction ID</th>
                                <th>Date &amp; Time</th>
                                <th>Machine</th>
                                <th>Service</th>
                                <th>Amount</th>
                                <th>Purchased Time</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $tx):
                                $serviceIcon = match ($tx['service_key'] ?? '') {
                                    'water' => 'bi-droplet-fill text-info',
                                    'soap'  => 'bi-stars text-warning',
                                    default => 'bi-gear-fill text-primary',
                                };
                                $statusBadge = match ($tx['status']) {
                                    'completed' => 'tx-badge-completed',
                                    'active'    => 'tx-badge-active',
                                    'pending'   => 'tx-badge-pending',
                                    'cancelled' => 'tx-badge-cancelled',
                                    'failed'    => 'tx-badge-failed',
                                    default     => 'tx-badge-pending',
                                };
                            ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,.04); font-size: .88rem;">
                                <td class="ps-4">
                                    <span class="tx-id"><?= e($tx['formatted_id']) ?></span>
                                </td>
                                <td>
                                    <div class="text-light fw-medium">
                                        <?= format_date($tx['created_at'], 'M j, Y g:i A') ?>
                                    </div>
                                    <div class="tx-snapshot-tag">
                                        <?= time_ago($tx['created_at']) ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-light fw-medium">
                                        <?= e($tx['machine_name'] ?: 'VendoLink Machine 01') ?>
                                    </div>
                                    <div class="tx-snapshot-tag">
                                        <?= e($tx['machine_location'] ?: 'Main Establishment') ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="d-inline-flex align-items-center gap-1">
                                        <i class="bi <?= $serviceIcon ?>"></i>
                                        <span class="text-light fw-medium"><?= e($tx['service_name'] ?: 'Service') ?></span>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-bold text-success">
                                        <?= e($tx['amount_formatted']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="text-light fw-medium">
                                        <i class="bi bi-clock-history me-1 text-primary-vl"></i>
                                        <?= e($tx['purchased_time_label']) ?>
                                    </div>
                                    <div class="tx-snapshot-tag">
                                        Snapshot: <?= e($tx['rate_snapshot_label']) ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="tx-badge <?= $statusBadge ?>">
                                        <?= ucfirst(e($tx['status'])) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php
                            $emptyRowsCount = max(0, $perPage - count($records));
                            for ($i = 0; $i < $emptyRowsCount; $i++):
                            ?>
                            <tr class="tx-empty-row" aria-hidden="true">
                                <td class="ps-4">&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>

                <!-- SECTION 2: MAINTENANCE HISTORY -->
                <?php elseif ($activeTab === 'maintenance'): ?>
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle mb-0" style="--bs-table-bg: transparent;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--vl-border); color: var(--vl-text-muted); font-size: .78rem; text-transform: uppercase; letter-spacing: .06em;">
                                <th class="ps-4">Maintenance Name</th>
                                <th>Machine / Service</th>
                                <th>Completion Date</th>
                                <th>Runtime at Completion</th>
                                <th>Notes</th>
                                <th>Completed By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $mh): ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,.04); font-size: .88rem;">
                                <td class="ps-4">
                                    <div class="text-light fw-bold">
                                        <i class="bi bi-wrench-adjustable-circle me-1 text-primary-vl"></i>
                                        <?= e($mh['maintenance_name']) ?>
                                    </div>
                                    <div class="tx-snapshot-tag">
                                        Type: <?= ucfirst(e($mh['reminder_type'])) ?> Reminder
                                    </div>
                                </td>
                                <td>
                                    <div class="text-light fw-medium">
                                        <?= e($mh['machine_name'] ?: 'VendoLink Machine 01') ?>
                                    </div>
                                    <div class="tx-snapshot-tag">
                                        Applies to: <?= e($mh['service_label']) ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-light fw-medium">
                                        <?= format_date($mh['completed_at'], 'M j, Y g:i A') ?>
                                    </div>
                                    <div class="tx-snapshot-tag">
                                        <?= time_ago($mh['completed_at']) ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-secondary-subtle text-light border border-secondary font-monospace">
                                        <i class="bi bi-stopwatch me-1"></i><?= e($mh['runtime_label']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="text-muted">
                                        <?= e($mh['notes'] ?: '—') ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="text-light fw-medium">
                                        <i class="bi bi-person-circle me-1 text-muted"></i>
                                        <?= e($mh['completed_by_name'] ?: 'System') ?>
                                    </div>
                                    <?php if (!empty($mh['completed_by_role'])): ?>
                                    <div class="tx-snapshot-tag">
                                        Role: <?= ucfirst(e($mh['completed_by_role'])) ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php
                            $emptyRowsCount = max(0, $perPage - count($records));
                            for ($i = 0; $i < $emptyRowsCount; $i++):
                            ?>
                            <tr class="tx-empty-row" aria-hidden="true">
                                <td class="ps-4">&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>

                <!-- SECTION 3: CONFIGURATION HISTORY -->
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle mb-0" style="--bs-table-bg: transparent;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--vl-border); color: var(--vl-text-muted); font-size: .78rem; text-transform: uppercase; letter-spacing: .06em;">
                                <th class="ps-4">Service</th>
                                <th>Previous Configuration</th>
                                <th>New Configuration</th>
                                <th>Changed By</th>
                                <th>Date &amp; Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $ch):
                                $serviceIcon = match ($ch['service_key'] ?? '') {
                                    'water' => 'bi-droplet-fill text-info',
                                    'soap'  => 'bi-stars text-warning',
                                    default => 'bi-gear-fill text-primary',
                                };
                            ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,.04); font-size: .88rem;">
                                <td class="ps-4">
                                    <span class="d-inline-flex align-items-center gap-1">
                                        <i class="bi <?= $serviceIcon ?>"></i>
                                        <strong class="text-light"><?= e($ch['service_name']) ?></strong>
                                    </span>
                                    <div class="tx-snapshot-tag">
                                        <?= e($ch['machine_name'] ?: 'VendoLink Machine 01') ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-danger-emphasis fw-medium">
                                        <i class="bi bi-dash-circle me-1"></i><?= e($ch['previous_label']) ?>
                                    </div>
                                    <div class="tx-snapshot-tag">
                                        State: <?= $ch['previous_is_enabled'] ? 'Enabled' : 'Disabled' ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-success fw-medium">
                                        <i class="bi bi-arrow-right-circle me-1"></i><?= e($ch['new_label']) ?>
                                    </div>
                                    <div class="tx-snapshot-tag">
                                        State: <?= $ch['new_is_enabled'] ? 'Enabled' : 'Disabled' ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-light fw-medium">
                                        <i class="bi bi-person-badge me-1 text-muted"></i>
                                        <?= e($ch['changed_by_name'] ?: 'Administrator') ?>
                                    </div>
                                    <?php if (!empty($ch['changed_by_role'])): ?>
                                    <div class="tx-snapshot-tag">
                                        Role: <?= ucfirst(e($ch['changed_by_role'])) ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="text-light fw-medium">
                                        <?= format_date($ch['created_at'], 'M j, Y g:i A') ?>
                                    </div>
                                    <div class="tx-snapshot-tag">
                                        <?= time_ago($ch['created_at']) ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php
                            $emptyRowsCount = max(0, $perPage - count($records));
                            for ($i = 0; $i < $emptyRowsCount; $i++):
                            ?>
                            <tr class="tx-empty-row" aria-hidden="true">
                                <td class="ps-4">&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Pagination Controls -->
                <?php if ($totalPages > 1): ?>
                <div class="tx-pagination">
                    <div class="text-muted small">
                        Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong> (Total <?= number_format($totalItems) ?> records)
                    </div>
                    <nav aria-label="Archive pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <!-- Previous -->
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= build_archive_url(['page' => $page - 1]) ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;&nbsp;Prev</span>
                                </a>
                            </li>

                            <!-- Page Numbers -->
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage   = min($totalPages, $page + 2);

                            if ($startPage > 1) {
                                echo '<li class="page-item"><a class="page-link" href="' . build_archive_url(['page' => 1]) . '">1</a></li>';
                                if ($startPage > 2) {
                                    echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                }
                            }

                            for ($p = $startPage; $p <= $endPage; $p++): ?>
                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= build_archive_url(['page' => $p]) ?>"><?= $p ?></a>
                            </li>
                            <?php endfor;

                            if ($endPage < $totalPages) {
                                if ($endPage < $totalPages - 1) {
                                    echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                }
                                echo '<li class="page-item"><a class="page-link" href="' . build_archive_url(['page' => $totalPages]) . '">' . $totalPages . '</a></li>';
                            }
                            ?>

                            <!-- Next -->
                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= build_archive_url(['page' => $page + 1]) ?>" aria-label="Next">
                                    <span aria-hidden="true">Next&nbsp;&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>

                <?php endif; ?>

            </div>

            <!-- CASH COLLECTIONS PANEL -->
            <?php if ($activeTab === 'transactions'): ?>
            <div class="tx-table-card collection-table-card mt-4" id="cashCollectionsArchive">
                <div class="panel-header d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3 py-3 px-3">
                    <h5 class="panel-title text-white d-flex align-items-center mb-0">
                        <i class="bi bi-wallet2 text-success me-2"></i><?= term('cash_collections', 'Cash Collections') ?>
                    </h5>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-3 py-1.5 fw-semibold">
                            <?= number_format($totalCashCollections) ?> Collection Record(s)
                        </span>
                        <a href="<?= BASE_URL ?>/dashboard/sales.php#cashCollectionsTracker" class="btn btn-sm btn-outline-success fw-semibold d-inline-flex align-items-center">
                            <i class="bi bi-wallet2 me-1.5"></i>Record Cash Collection
                        </a>
                    </div>
                </div>

                <?php if (empty($cashCollections)): ?>
                <div class="activity-empty py-5 text-center">
                    <i class="bi bi-wallet2 fs-1 text-muted mb-3 opacity-50"></i>
                    <p class="text-muted mt-2 mb-0">No cash collection records found matching your filters.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle mb-0" style="--bs-table-bg: transparent;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--vl-border); color: #94a3b8; font-size: .78rem; text-transform: uppercase; letter-spacing: .06em;">
                                <th class="ps-4">Collection Date</th>
                                <th>Machine</th>
                                <th>Recorded By</th>
                                <th>Physical Cash Collected</th>
                                <th>System Sales Target</th>
                                <th>Variance</th>
                                <th class="pe-4">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pagedCashCollections as $cc): 
                                $var = (float)($cc['variance_amount'] ?? 0);
                            ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,.04); font-size: .88rem;">
                                <td class="ps-4 fw-bold text-light">
                                    <?= format_date($cc['collected_at'], 'M d, Y, g:i A') ?>
                                </td>
                                <td>
                                    <div class="text-light fw-medium">
                                        <?= e($cc['machine_name'] ?: 'VendoLink Machine 01') ?>
                                    </div>
                                </td>
                                <td class="small text-secondary">
                                    <?= e($cc['full_name'] ?? '—') ?>
                                </td>
                                <td class="fw-bold text-success font-monospace">
                                    ₱<?= number_format((float)$cc['collected_amount'], 2) ?>
                                </td>
                                <td class="text-light font-monospace">
                                    ₱<?= number_format((float)$cc['system_sales_amount'], 2) ?>
                                </td>
                                <td>
                                    <?php if ($var == 0): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2.5 py-1">
                                            <i class="bi bi-check-circle me-1"></i>₱0.00 (Match)
                                        </span>
                                    <?php elseif ($var > 0): ?>
                                        <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 rounded-pill px-2.5 py-1">
                                            <i class="bi bi-arrow-up-right me-1"></i>+₱<?= number_format($var, 2) ?> (Surplus)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-2.5 py-1">
                                            <i class="bi bi-arrow-down-right me-1"></i>-₱<?= number_format(abs($var), 2) ?> (Shortage)
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="pe-4 small text-secondary">
                                    <?= e($cc['notes'] ?: '—') ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Cash Collections Pagination Controls -->
                <?php if ($ccTotalPages > 1): ?>
                <div class="tx-pagination">
                    <div class="text-muted small">
                        Page <strong><?= $ccCurrentPage ?></strong> of <strong><?= $ccTotalPages ?></strong> (Total <?= number_format($totalCashCollections) ?> record<?= $totalCashCollections === 1 ? '' : 's' ?>)
                    </div>
                    <nav aria-label="Cash collections pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <!-- Previous -->
                            <li class="page-item <?= $ccCurrentPage <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= build_archive_url(['ccpage' => $ccCurrentPage - 1]) ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;&nbsp;Prev</span>
                                </a>
                            </li>

                            <?php for ($cp = 1; $cp <= $ccTotalPages; $cp++): ?>
                            <li class="page-item <?= $cp === $ccCurrentPage ? 'active' : '' ?>">
                                <a class="page-link" href="<?= build_archive_url(['ccpage' => $cp]) ?>"><?= $cp ?></a>
                            </li>
                            <?php endfor; ?>

                            <!-- Next -->
                            <li class="page-item <?= $ccCurrentPage >= $ccTotalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= build_archive_url(['ccpage' => $ccCurrentPage + 1]) ?>" aria-label="Next">
                                    <span aria-hidden="true">Next&nbsp;&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>

                <?php endif; ?>
            </div>
            <?php endif; ?>

        </main>

        <!-- Page Footer -->
        <footer class="app-footer">
            <span><?= APP_NAME ?> v<?= APP_VERSION ?> &copy; <?= date('Y') ?></span>
            <span>Powered by VendoCore IoT Controller</span>
        </footer>

    </div><!-- /.main-content -->

</div><!-- /.app-shell -->

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- VendoLink JS -->
<script src="<?= ASSETS_URL ?>/js/app.js"></script>

</body>
</html>
