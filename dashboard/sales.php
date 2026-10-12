<?php
/**
 * ============================================================
 * VendoLink — Sales Overview & Transactions
 * ============================================================
 *
 * Provides real-time transaction reporting, financial revenue verification,
 * service filtering, date ranges, pagination, and CSV export.
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Machine.php';
require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../controllers/SalesController.php';

// ---- Role Guard ----
require_role([ROLE_ADMIN, ROLE_OWNER]);

$ownerId = current_owner_id();
$machineModel = new Machine();
$primaryMachine = $machineModel->primaryWithServices($ownerId);
$activeMachineId = (int)($primaryMachine['id'] ?? 0);

// ---- Handle POST Actions ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'record_cash_collection') {
        $collectedAmount = (float)($_POST['collected_amount'] ?? 0);
        $notes           = trim($_POST['notes'] ?? '');
        $userId          = (int)(current_user()['id'] ?? 0);
        $machineId       = $activeMachineId;

        if ($machineId <= 0) {
            flash('danger', "No active machine assigned to your account. Please activate a hardware kit first.");
            redirect('dashboard/sales.php');
        } elseif ($collectedAmount > 0) {
            $txModel = new Transaction();
            $txModel->recordCashCollection($userId, $machineId, $collectedAmount, $notes);
            flash('success', "Physical cash collection of ₱" . number_format($collectedAmount, 2) . " recorded successfully!");
            log_activity('CASH_COLLECTED', "Collected ₱" . number_format($collectedAmount, 2) . " from physical coin box.");
            redirect('dashboard/sales.php');
        } else {
            flash('danger', "Please enter a valid cash collection amount.");
            redirect('dashboard/sales.php');
        }
    } else {
        (new SalesController())->handle();
    }
}

$transactionModel = new Transaction();

// ---- Filter Parameters ----
$search    = trim($_GET['search'] ?? '');
$service   = trim($_GET['service'] ?? 'all');
$status    = trim($_GET['status'] ?? 'all');
$dateRange = trim($_GET['date_range'] ?? 'all');
$dateFrom  = trim($_GET['date_from'] ?? '');
$dateTo    = trim($_GET['date_to'] ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = max(1, min(100, (int)($_GET['per_page'] ?? 5)));

// ---- Resolve Date Range Presets ----
if ($dateRange === 'today') {
    $dateFrom = date('Y-m-d');
    $dateTo   = date('Y-m-d');
} elseif ($dateRange === 'week') {
    $dateFrom = date('Y-m-d', strtotime('monday this week'));
    $dateTo   = date('Y-m-d');
} elseif ($dateRange === 'month') {
    $dateFrom = date('Y-m-01');
    $dateTo   = date('Y-m-d');
} elseif (!empty($dateFrom) || !empty($dateTo)) {
    $dateRange = 'custom';
}

$filters = [
    'search'    => $search,
    'service'   => $service,
    'status'    => $status,
    'date_from' => $dateFrom,
    'date_to'   => $dateTo,
];

// ---- Handle CSV / Excel Export Actions ----
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    if ($exportType === 'sales_report') {
        $transactionModel->exportSalesReport($filters, $ownerId);
        exit;
    } elseif ($exportType === 'transactions' || $exportType === 'csv' || $exportType === 'excel') {
        $transactionModel->exportTransactionsCsv($filters, $ownerId);
        exit;
    }
}

// ---- Data Queries ----
$servicesList    = $transactionModel->getServices($ownerId);
$auditMetrics    = $transactionModel->getAuditMetrics($filters, $ownerId);
$cashCollections = $transactionModel->getCashCollections($activeMachineId, $ownerId);

// ---- Cash Collections Pagination (5 records per page) ----
$totalCollections = count($cashCollections);
$cPerPage         = 5;
$cTotalPages      = max(1, (int)ceil($totalCollections / $cPerPage));
$cCurrentPage     = max(1, min($cTotalPages, (int)($_GET['cpage'] ?? 1)));
$cOffset          = ($cCurrentPage - 1) * $cPerPage;
$pagedCollections = array_slice($cashCollections, 0, 5);

$result          = $transactionModel->getPaginated($filters, $page, $perPage, $ownerId);
$reportModel     = new Report();
$salesAnalytics  = $reportModel->getSalesAnalytics('today', $ownerId);

$transactions = array_slice($result['items'] ?? [], 0, 5);
$totalItems   = (int) $result['total_items'];
$totalPages   = (int) $result['total_pages'];
$pageNumber   = (int) $result['page'];

$pageTitle   = 'Sales Overview';
$currentPage = 'sales';
$user        = current_user();

/**
 * Build URL preserving active filters with custom query parameters.
 */
function build_filter_url(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    $clean = [];
    foreach ($params as $k => $v) {
        if ($v !== '' && $v !== null && $v !== 'all') {
            $clean[$k] = $v;
        }
    }
    return 'sales.php' . (!empty($clean) ? '?' . http_build_query($clean) : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= APP_NAME ?> Sales Overview &amp; Verification — <?= APP_TAGLINE ?>">
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
                    <h1 class="page-title text-white">
                        <i class="bi bi-cash-coin me-2 text-primary-vl"></i>Sales Overview
                    </h1>
                    <p class="page-subtitle text-light">
                        Review, verify, and trace VendoLink coin collections and transaction records.
                    </p>
                </div>
                <div class="page-header-actions">
                    <div class="page-date text-light">
                        <i class="bi bi-calendar3 me-1"></i><?= date('l, F j, Y') ?>
                    </div>
                </div>
            </div>

            <?php /* Hidden Development Simulation Panel
            <div class="collapse mb-4" id="devSimulationPanel">
                <div class="tx-filter-card" style="border: 1px dashed var(--vl-primary); background: rgba(14, 165, 233, 0.05);">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h6 class="text-primary-vl mb-0 fw-bold">
                            <i class="bi bi-cpu me-1"></i>Development Test Tools (No Hardware Attached)
                        </h6>
                        <span class="badge bg-secondary text-light">Development Only</span>
                    </div>
                    <p class="text-light small mb-3">
                        Physical coin-slot and ESP32 hardware are not connected yet. Use this safe utility to simulate coin insertions with current configuration snapshots to test filters, pagination, and sales totals.
                    </p>
                    <div class="row g-3">
                        <div class="col-12 col-md-8">
                            <form method="post" class="row g-2 align-items-center">
                                <input type="hidden" name="action" value="dev_record">
                                <?= csrf_field() ?>
                                <div class="col-12 col-sm-4">
                                    <select class="form-select form-select-sm dark-input text-white" name="service_id" required>
                                        <?php foreach ($servicesList as $svc): ?>
                                            <option value="<?= (int) $svc['id'] ?>">
                                                <?= e($svc['name']) ?> (₱<?= number_format((float) $svc['coin_value'], 2) ?> = <?= Transaction::formatDuration((int) $svc['service_seconds']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-sm-3">
                                    <input type="number" step="0.01" min="1" class="form-control form-control-sm dark-input text-white" name="amount" value="5.00" placeholder="Amount ₱" required>
                                </div>
                                <div class="col-6 col-sm-3">
                                    <select class="form-select form-select-sm dark-input text-white" name="status">
                                        <option value="completed">Completed</option>
                                        <option value="active">Active</option>
                                        <option value="pending">Pending</option>
                                        <option value="cancelled">Cancelled</option>
                                        <option value="failed">Failed</option>
                                    </select>
                                </div>
                                <div class="col-12 col-sm-2">
                                    <button class="btn btn-sm btn-primary w-100" type="submit">
                                        <i class="bi bi-plus-circle me-1"></i>Insert
                                    </button>
                                </div>
                            </form>
                        </div>
                        <div class="col-12 col-md-4 text-md-end">
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="dev_seed">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-info" type="submit">
                                    <i class="bi bi-shuffle me-1"></i>Seed Sample Transactions
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            */ ?>

            <!-- Summary Cards Row: Total Revenue | Transaction Count | Water Sales | Soap Sales -->
            <div class="row g-4 mb-4">

                <!-- 1. Total Revenue -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card stat-card-sales">
                        <div class="stat-card-body">
                            <div class="stat-card-info">
                                <p class="stat-card-label text-white">Total Revenue</p>
                                <h3 class="stat-card-value text-success"><?= format_currency($auditMetrics['total_revenue']) ?></h3>
                                <p class="stat-card-trend text-light">
                                    <span class="trend-badge trend-up">
                                        <i class="bi bi-check-circle-fill"></i> Verified
                                    </span>
                                    <?= $auditMetrics['completed_count'] ?> completed tx
                                </p>
                            </div>
                            <div class="stat-card-icon">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                        </div>
                        <div class="stat-card-footer">
                            <span class="stat-card-link text-light">
                                Verified completed revenue total
                            </span>
                        </div>
                    </div>
                </div>

                <!-- 2. Transaction Count -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card stat-card-online">
                        <div class="stat-card-body">
                            <div class="stat-card-info">
                                <p class="stat-card-label text-white">Transaction Count</p>
                                <h3 class="stat-card-value text-white"><?= number_format($auditMetrics['transaction_count']) ?></h3>
                                <p class="stat-card-trend text-light">
                                    <span class="trend-badge trend-neutral">
                                        <i class="bi bi-receipt"></i> Total Logged
                                    </span>
                                    all statuses
                                </p>
                            </div>
                            <div class="stat-card-icon">
                                <i class="bi bi-receipt-cutoff"></i>
                            </div>
                        </div>
                        <div class="stat-card-footer">
                            <span class="stat-card-link text-light">
                                Total sales records in view
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
                                <h3 class="stat-card-value" style="color: #38bdf8;"><?= format_currency($auditMetrics['water_revenue']) ?></h3>
                                <p class="stat-card-trend text-light">
                                    <span class="trend-badge trend-neutral">
                                        <i class="bi bi-droplet-fill text-info"></i> <?= $auditMetrics['water_count'] ?> sales
                                    </span>
                                    water service
                                </p>
                            </div>
                            <div class="stat-card-icon" style="background: rgba(56,189,248,.1); color: #38bdf8;">
                                <i class="bi bi-droplet-fill"></i>
                            </div>
                        </div>
                        <div class="stat-card-footer">
                            <a href="<?= build_filter_url(['service' => 'water', 'page' => 1]) ?>" class="stat-card-link text-light">
                                Filter water sales <i class="bi bi-arrow-right ms-1"></i>
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
                                <h3 class="stat-card-value" style="color: #c084fc;"><?= format_currency($auditMetrics['soap_revenue']) ?></h3>
                                <p class="stat-card-trend text-light">
                                    <span class="trend-badge trend-neutral">
                                        <i class="bi bi-stars text-warning"></i> <?= $auditMetrics['soap_count'] ?> sales
                                    </span>
                                    soap service
                                </p>
                            </div>
                            <div class="stat-card-icon" style="background: rgba(192,132,252,.1); color: #c084fc;">
                                <i class="bi bi-stars"></i>
                            </div>
                        </div>
                        <div class="stat-card-footer">
                            <a href="<?= build_filter_url(['service' => 'soap', 'page' => 1]) ?>" class="stat-card-link text-light">
                                Filter soap sales <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ============================================================
                 SALES PERFORMANCE ANALYTICS GRAPH CARD
                 ============================================================ -->
            <div class="chart-card mb-4" id="salesAnalyticsCard">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
                    <div>
                        <h5 class="panel-title mb-1 text-white">
                            <i class="bi bi-graph-up-arrow me-2 text-primary-vl"></i>Sales Performance Analytics
                        </h5>
                        <p class="text-muted small mb-0" id="salesAnalyticsSubtitle">
                            <?= e($salesAnalytics['period_label']) ?> &mdash; Visual revenue and transaction volume trends
                        </p>
                    </div>

                    <!-- Filter Buttons: [Today] [This Week] [This Month] [This Year] [All Time] -->
                    <div class="btn-group btn-group-sm sales-period-filter-group" role="group" aria-label="Sales Period Filters">
                        <button type="button" class="btn btn-outline-secondary sales-filter-btn active" data-period="today">
                            Today
                        </button>
                        <button type="button" class="btn btn-outline-secondary sales-filter-btn" data-period="week">
                            This Week
                        </button>
                        <button type="button" class="btn btn-outline-secondary sales-filter-btn" data-period="month">
                            This Month
                        </button>
                        <button type="button" class="btn btn-outline-secondary sales-filter-btn" data-period="year">
                            This Year
                        </button>
                        <button type="button" class="btn btn-outline-secondary sales-filter-btn" data-period="all">
                            All Time
                        </button>
                    </div>
                </div>

                <!-- 4 Period KPI Summary Tiles -->
                <div class="row g-2 mb-3">
                    <!-- 1. Total Revenue -->
                    <div class="col-6 col-lg-3">
                        <div class="sales-kpi-pill">
                            <div class="kpi-label">Total Revenue</div>
                            <div class="kpi-value text-success" id="kpiTotalRevenue"><?= $salesAnalytics['metrics']['total_revenue_formatted'] ?></div>
                            <div class="kpi-sub" id="kpiPctChange">
                                <span class="badge bg-<?= ($salesAnalytics['metrics']['pct_direction'] === 'up') ? 'success' : (($salesAnalytics['metrics']['pct_direction'] === 'down') ? 'danger' : 'secondary') ?> bg-opacity-25 text-<?= ($salesAnalytics['metrics']['pct_direction'] === 'up') ? 'success' : (($salesAnalytics['metrics']['pct_direction'] === 'down') ? 'danger' : 'muted') ?>">
                                    <i class="bi bi-arrow-<?= ($salesAnalytics['metrics']['pct_direction'] === 'up') ? 'up' : (($salesAnalytics['metrics']['pct_direction'] === 'down') ? 'down' : 'right') ?> me-1"></i><?= $salesAnalytics['metrics']['pct_change_formatted'] ?>
                                </span>
                                <span class="text-muted ms-1 small" id="kpiPrevLabel"><?= e($salesAnalytics['prev_period_label']) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Total Transactions -->
                    <div class="col-6 col-lg-3">
                        <div class="sales-kpi-pill">
                            <div class="kpi-label">Total Transactions</div>
                            <div class="kpi-value text-white" id="kpiTotalTx"><?= number_format($salesAnalytics['metrics']['total_transactions']) ?></div>
                            <div class="kpi-sub text-muted small">Completed customer vends</div>
                        </div>
                    </div>

                    <!-- 3. Average Transaction -->
                    <div class="col-6 col-lg-3">
                        <div class="sales-kpi-pill">
                            <div class="kpi-label">Average Ticket</div>
                            <div class="kpi-value text-info" id="kpiAvgTx"><?= $salesAnalytics['metrics']['avg_transaction_formatted'] ?></div>
                            <div class="kpi-sub text-muted small">Per successful vend</div>
                        </div>
                    </div>

                    <!-- 4. Grouping & View -->
                    <div class="col-6 col-lg-3">
                        <div class="sales-kpi-pill">
                            <div class="kpi-label">Grouping / View</div>
                            <div class="kpi-value text-warning text-capitalize" id="kpiGrouping"><?= e($salesAnalytics['time_grouping']) ?></div>
                            <div class="kpi-sub text-muted small" id="kpiActiveFilterBadge">Interactive Real-Time Bar Graph</div>
                        </div>
                    </div>
                </div>

                <!-- Responsive Bar Chart Canvas -->
                <div class="chart-canvas-wrapper" style="position: relative; height: 260px; width: 100%;">
                    <canvas id="salesAnalyticsChart"></canvas>
                </div>

                <!-- Empty State Alert (shown when period has 0 data) -->
                <div id="salesChartEmptyState" class="text-center py-4 text-slate-400 <?= $salesAnalytics['chart']['has_data'] ? 'd-none' : '' ?>">
                    <i class="bi bi-bar-chart text-slate-600 mb-2 d-block" style="font-size: 2.2rem;"></i>
                    <div class="fw-semibold text-slate-300">No completed sales recorded for this period.</div>
                    <p class="text-slate-500 small mb-0">Sales will populate automatically upon coin insertion.</p>
                </div>
            </div>

            <!-- Filters & Search Panel -->
            <div class="tx-filter-card mb-4">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="fw-bold text-white"><i class="bi bi-funnel me-1"></i>Filters:</span>
                        <!-- Date Preset Pills -->
                        <div class="d-flex align-items-center gap-1 flex-wrap">
                            <a href="<?= build_filter_url(['date_range' => 'all', 'date_from' => '', 'date_to' => '', 'page' => 1]) ?>"
                               class="tx-preset-pill <?= $dateRange === 'all' && empty($dateFrom) && empty($dateTo) ? 'active' : '' ?>">
                               All Time
                            </a>
                            <a href="<?= build_filter_url(['date_range' => 'today', 'date_from' => '', 'date_to' => '', 'page' => 1]) ?>"
                               class="tx-preset-pill <?= $dateRange === 'today' ? 'active' : '' ?>">
                               Today
                            </a>
                            <a href="<?= build_filter_url(['date_range' => 'week', 'date_from' => '', 'date_to' => '', 'page' => 1]) ?>"
                               class="tx-preset-pill <?= $dateRange === 'week' ? 'active' : '' ?>">
                               This Week
                            </a>
                            <a href="<?= build_filter_url(['date_range' => 'month', 'date_from' => '', 'date_to' => '', 'page' => 1]) ?>"
                               class="tx-preset-pill <?= $dateRange === 'month' ? 'active' : '' ?>">
                               This Month
                            </a>
                        </div>
                    </div>

                    <?php if (!empty($search) || $service !== 'all' || $status !== 'all' || !empty($dateFrom) || !empty($dateTo) || $dateRange !== 'all'): ?>
                    <a href="sales.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle me-1"></i>Clear Filters
                    </a>
                    <?php endif; ?>
                </div>

                <form method="get" action="sales.php" class="row g-3 align-items-end">
                    <input type="hidden" name="date_range" value="custom">

                    <!-- Date Range: From -->
                    <div class="col-12 col-sm-6 col-md-3 col-xl-2">
                        <label class="form-label text-light small mb-1">From Date</label>
                        <input type="date" class="form-control form-control-sm dark-input text-white" name="date_from" value="<?= e($dateFrom) ?>">
                    </div>

                    <!-- Date Range: To -->
                    <div class="col-12 col-sm-6 col-md-3 col-xl-2">
                        <label class="form-label text-light small mb-1">To Date</label>
                        <input type="date" class="form-control form-control-sm dark-input text-white" name="date_to" value="<?= e($dateTo) ?>">
                    </div>

                    <!-- Service Filter -->
                    <div class="col-12 col-sm-6 col-md-3 col-xl-2">
                        <label class="form-label text-light small mb-1">Service</label>
                        <select class="form-select form-select-sm dark-input text-white" name="service">
                            <option value="all" <?= $service === 'all' ? 'selected' : '' ?>>All Services</option>
                            <?php foreach ($servicesList as $svc): ?>
                                <option value="<?= e($svc['service_key']) ?>" <?= $service === $svc['service_key'] ? 'selected' : '' ?>>
                                    <?= e($svc['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Status Filter -->
                    <div class="col-12 col-sm-6 col-md-3 col-xl-2">
                        <label class="form-label text-light small mb-1">Status</label>
                        <select class="form-select form-select-sm dark-input text-white" name="status">
                            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="incomplete" <?= $status === 'incomplete' ? 'selected' : '' ?>>Incomplete</option>
                            <option value="interrupted" <?= $status === 'interrupted' ? 'selected' : '' ?>>Interrupted</option>
                            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>Failed</option>
                        </select>
                    </div>

                    <!-- Search Input -->
                    <div class="col-12 col-md-6 col-xl-2">
                        <label class="form-label text-light small mb-1">Search</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text dark-input" style="border-right: 0;">
                                <i class="bi bi-search text-muted"></i>
                            </span>
                            <input type="text" class="form-control dark-input text-white" name="search"
                                   placeholder="Search ID (#00001) or name..."
                                   value="<?= e($search) ?>" style="border-left: 0;">
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="col-12 col-md-6 col-xl-2">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm flex-fill">
                                <i class="bi bi-filter me-1"></i>Apply
                            </button>
                            <a href="<?= build_filter_url(['export' => 'excel']) ?>" class="btn btn-outline-success btn-sm flex-fill text-nowrap" title="Export currently filtered records to Excel">
                                <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Transaction Table Card -->
            <div class="tx-table-card collection-table-card sales-tx-table-card mb-4" id="salesTransactionRecords">
                <div class="panel-header d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3 py-3 px-3">
                    <h5 class="panel-title text-white d-flex align-items-center mb-0">
                        <i class="bi bi-receipt me-2 text-primary-vl"></i><?= term('transaction_records', 'Transaction Records') ?>
                    </h5>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-3 py-1.5 fw-semibold">
                            Showing <?= number_format(count($transactions)) ?> of <?= number_format($totalItems) ?> transactions
                        </span>
                        <a href="<?= BASE_URL ?>/dashboard/archive.php?tab=transactions" class="btn btn-sm btn-outline-primary text-nowrap d-inline-flex align-items-center">
                            View All Transactions <i class="bi bi-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>

                <?php if (empty($transactions)): ?>
                <div class="activity-empty py-5 text-center">
                    <i class="bi bi-receipt-cutoff" style="font-size: 3rem; color: var(--vl-text-muted);"></i>
                    <p class="text-light mt-2 mb-0">No transaction records found matching your filters.</p>
                    <?php if (!empty($search) || $service !== 'all' || $status !== 'all' || !empty($dateFrom) || !empty($dateTo)): ?>
                    <a href="sales.php" class="btn btn-sm btn-outline-secondary mt-3">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Reset All Filters
                    </a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle mb-0" style="--bs-table-bg: transparent;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--vl-border); color: #94a3b8; font-size: .78rem; text-transform: uppercase; letter-spacing: .06em;">
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
                            <?php foreach ($transactions as $tx):
                                $serviceIcon = match ($tx['service_key'] ?? '') {
                                    'water' => 'bi-droplet-fill text-info',
                                    'soap'  => 'bi-stars text-warning',
                                    default => 'bi-gear-fill text-primary',
                                };

                                $statusBadgeClass = match ($tx['status']) {
                                    'completed'   => 'tx-badge-completed',
                                    'active'      => 'tx-badge-active',
                                    'pending'     => 'tx-badge-pending',
                                    'cancelled'   => 'tx-badge-cancelled',
                                    'failed'      => 'tx-badge-failed',
                                    'incomplete'  => 'tx-badge-incomplete',
                                    'interrupted' => 'tx-badge-interrupted',
                                    default       => 'tx-badge-pending',
                                };
                            ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,.05); font-size: .88rem;">
                                <!-- Transaction ID -->
                                <td class="ps-4">
                                    <span class="tx-id text-white fw-bold"><?= e($tx['formatted_id']) ?></span>
                                </td>

                                <!-- Date & Time -->
                                <td>
                                    <div class="text-white fw-medium">
                                        <?= format_date($tx['created_at'], 'M j, Y g:i A') ?>
                                    </div>
                                    <div class="tx-snapshot-tag text-light">
                                        <?= time_ago($tx['created_at']) ?>
                                    </div>
                                </td>

                                <!-- Machine -->
                                <td>
                                    <div class="text-white fw-medium">
                                        <?= e($tx['machine_name'] ?: 'VendoLink Machine 01') ?>
                                    </div>
                                    <div class="tx-snapshot-tag text-light">
                                        <?= e($tx['machine_location'] ?: 'Main Establishment') ?>
                                    </div>
                                </td>

                                <!-- Service -->
                                <td>
                                    <span class="d-inline-flex align-items-center gap-1">
                                        <i class="bi <?= $serviceIcon ?>"></i>
                                        <span class="text-white fw-medium"><?= e($tx['service_name'] ?: 'Service') ?></span>
                                    </span>
                                </td>

                                <!-- Amount -->
                                <td>
                                    <span class="fw-bold <?= $tx['status'] === 'incomplete' ? 'text-warning' : 'text-success' ?>" style="font-size: .95rem;">
                                        <?= e($tx['amount_formatted']) ?>
                                    </span>
                                </td>

                                <!-- Purchased Time & Rate Snapshot -->
                                <td>
                                    <?php if ($tx['status'] === 'incomplete'): ?>
                                        <div class="text-white fw-medium">
                                            <i class="bi bi-clock-history me-1 text-warning"></i>
                                            <?= e($tx['actual_time_label'] ?? '0s') ?> <span class="text-muted small">/ <?= e($tx['purchased_time_label']) ?></span>
                                        </div>
                                        <div class="tx-snapshot-tag text-warning">
                                            <i class="bi bi-lightning-charge-fill me-1"></i>Ran <?= e($tx['actual_time_label'] ?? '0s') ?> before interruption
                                        </div>
                                    <?php else: ?>
                                        <div class="text-white fw-medium">
                                            <i class="bi bi-clock-history me-1 text-primary-vl"></i>
                                            <?= e($tx['purchased_time_label']) ?>
                                        </div>
                                        <div class="tx-snapshot-tag text-light">
                                            Snapshot: <?= e($tx['rate_snapshot_label']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- Status -->
                                <td>
                                    <span class="tx-badge <?= $statusBadgeClass ?>">
                                        <?= ucfirst(e($tx['status'])) ?>
                                    </span>
                                    <?php if (!empty($tx['status_reason'])): ?>
                                        <div class="tx-snapshot-tag text-muted mt-1" style="font-size: 0.7rem; max-width: 190px; line-height: 1.2;">
                                            <i class="bi bi-info-circle me-1"></i><?= e($tx['status_reason']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php
                            $emptyRowsCount = max(0, 5 - count($transactions));
                            for ($i = 0; $i < $emptyRowsCount; $i++):
                            ?>
                            <tr class="tx-empty-row" aria-hidden="true" style="border-bottom: 1px solid rgba(255,255,255,.04); height: 58px;">
                                <td class="ps-4 text-muted opacity-25">&mdash;</td>
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

                <?php endif; ?>

            </div>

            <!-- PHYSICAL CASH COLLECTION LOG TABLE CARD -->
            <div class="tx-table-card collection-table-card mt-4" id="cashCollectionsTracker">
                <div class="panel-header d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3 py-3 px-3">
                    <h5 class="panel-title text-white d-flex align-items-center mb-0">
                        <i class="bi bi-wallet2 text-success me-2"></i><?= term('cash_collections', 'Cash Collections') ?>
                    </h5>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-3 py-1.5 fw-semibold">
                            Showing <?= number_format(count($pagedCollections)) ?> of <?= number_format($totalCollections) ?> collections
                        </span>
                        <a href="<?= BASE_URL ?>/dashboard/archive.php?tab=transactions#cashCollectionsArchive" class="btn btn-sm btn-outline-primary text-nowrap d-inline-flex align-items-center">
                            View All Collections <i class="bi bi-arrow-right ms-1"></i>
                        </a>
                        <button type="button" class="btn btn-sm btn-success fw-bold d-inline-flex align-items-center" data-bs-toggle="modal" data-bs-target="#recordCashModal">
                            <i class="bi bi-wallet2 me-1.5"></i>Record Cash Collection
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0 align-middle" style="--bs-table-bg: transparent;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--vl-border); color: #94a3b8; font-size: .78rem; text-transform: uppercase; letter-spacing: .06em;">
                                <th class="ps-3">Collection Date</th>
                                <th>Recorded By</th>
                                <th>Physical Cash Collected</th>
                                <th>System Sales Target</th>
                                <th>Variance</th>
                                <th class="pe-3">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pagedCollections)): ?>
                            <tr class="collection-empty-row">
                                <td colspan="6" class="p-0 border-0">
                                    <div class="collection-empty-container text-center py-5">
                                        <i class="bi bi-wallet2 fs-1 text-muted mb-3 opacity-50"></i>
                                        <div class="text-light fw-medium">
                                            No physical cash collections recorded yet. Click "Record Cash Collection" above when clearing the coin box.
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($pagedCollections as $cc): 
                                    $var = (float)$cc['variance_amount'];
                                ?>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,.05); font-size: .88rem;">
                                    <td class="ps-3 fw-bold text-light">
                                        <?= format_date($cc['collected_at'], 'M d, Y, g:i A') ?>
                                    </td>
                                    <td class="small text-secondary">
                                        <?= e($cc['full_name']) ?>
                                    </td>
                                    <td class="fw-bold text-success font-monospace">
                                        ₱<?= number_format((float)$cc['collected_amount'], 2) ?>
                                    </td>
                                    <td class="text-slate-300 font-monospace">
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
                                    <td class="pe-3 small text-secondary">
                                        <?= e($cc['notes'] ?? 'N/A') ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>

        </main>

        <!-- RECORD CASH COLLECTION MODAL -->
        <div class="modal fade" id="recordCashModal" tabindex="-1" aria-labelledby="recordCashModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content bg-dark text-light border-secondary border-opacity-25" style="background:#0f172a; border-color:#1e293b;">
                    <div class="modal-header border-secondary border-opacity-25">
                        <h5 class="modal-title fw-bold" id="recordCashModalLabel">
                            <i class="bi bi-wallet2 text-success me-2"></i>Record Physical Cash Collection
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST">
                        <div class="modal-body p-4">
                            <input type="hidden" name="action" value="record_cash_collection">

                            <div class="alert alert-info bg-info bg-opacity-10 border-info border-opacity-25 text-info small mb-3">
                                <i class="bi bi-info-circle-fill me-1.5"></i>
                                Enter the total physical cash removed from the coin box. VendoLink will automatically compare it against registered system sales to calculate variance.
                            </div>

                            <div class="mb-3">
                                <label class="form-label text-secondary small fw-bold">COLLECTED CASH AMOUNT (₱)</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-secondary bg-opacity-20 text-light border-secondary border-opacity-25 fw-bold">₱</span>
                                    <input type="number" step="0.50" min="0.50" name="collected_amount" class="form-control bg-dark text-light border-secondary border-opacity-25 fw-bold fs-5" placeholder="e.g. 1400.00" required autofocus autocomplete="off">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label text-secondary small fw-bold">NOTES / OBSERVATIONS (OPTIONAL)</label>
                                <textarea name="notes" class="form-control bg-dark text-light border-secondary border-opacity-25" rows="3" placeholder="e.g. Physical cash collection matching system sales. Coin box cleared."></textarea>
                            </div>
                        </div>
                        <div class="modal-footer border-secondary border-opacity-25">
                            <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success fw-bold px-4">
                                <i class="bi bi-check-circle me-1"></i>Save Collection Record
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Page Footer -->
        <footer class="app-footer">
            <span><?= APP_NAME ?> v<?= APP_VERSION ?> &copy; <?= date('Y') ?></span>
            <span>Powered by VendoCore IoT Controller</span>
        </footer>

    </div><!-- /.main-content -->

</div><!-- /.app-shell -->

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Chart.js 4.4.3 -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<!-- VendoLink JS -->
<script src="<?= ASSETS_URL ?>/js/app.js"></script>

<!-- Interactive Sales Analytics Graph Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('salesAnalyticsChart');
    if (!ctx) return;

    let chartInstance = null;
    let initialData = <?= json_encode($salesAnalytics) ?>;

    function initChart(data) {
        if (chartInstance) {
            chartInstance.destroy();
        }

        const labels     = data.chart.labels || [];
        const waterSales = data.chart.water_data || [];
        const soapSales  = data.chart.soap_data || [];

        chartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Water (₱)',
                        data: waterSales,
                        backgroundColor: 'rgba(56, 189, 248, 0.75)',
                        borderColor: '#38bdf8',
                        borderWidth: 1,
                        borderRadius: 4,
                        stack: 'salesStack'
                    },
                    {
                        label: 'Soap (₱)',
                        data: soapSales,
                        backgroundColor: 'rgba(234, 179, 8, 0.75)',
                        borderColor: '#eab308',
                        borderWidth: 1,
                        borderRadius: 4,
                        stack: 'salesStack'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        align: 'end',
                        labels: {
                            color: '#cbd5e1',
                            font: { size: 11, family: 'Inter, sans-serif' },
                            boxWidth: 12,
                            boxHeight: 12,
                            padding: 10
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        titleColor: '#f8fafc',
                        bodyColor: '#cbd5e1',
                        borderColor: 'rgba(148, 163, 184, 0.2)',
                        borderWidth: 1,
                        padding: 10,
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ₱' + parseFloat(context.raw).toFixed(2);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        stacked: true,
                        grid: {
                            color: 'rgba(255, 255, 255, 0.04)',
                            drawBorder: false,
                        },
                        ticks: {
                            color: '#cbd5e1',
                            font: { size: 10, family: 'Inter, sans-serif' },
                            maxRotation: 45,
                            autoSkip: true,
                            maxTicksLimit: 8
                        }
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(255, 255, 255, 0.05)',
                            drawBorder: false,
                        },
                        ticks: {
                            color: '#cbd5e1',
                            font: { size: 11, family: 'Inter, sans-serif' },
                            callback: function(val) {
                                return '₱' + val;
                            }
                        }
                    }
                }
            }
        });
    }

    // Initialize chart with initial server-rendered data
    initChart(initialData);

    // Filter Buttons Handler
    const filterButtons = document.querySelectorAll('.sales-filter-btn');
    const subtitleEl     = document.getElementById('salesAnalyticsSubtitle');
    const kpiRevenue     = document.getElementById('kpiTotalRevenue');
    const kpiTx          = document.getElementById('kpiTotalTx');
    const kpiAvg         = document.getElementById('kpiAvgTx');
    const kpiPctChange   = document.getElementById('kpiPctChange');
    const kpiGrouping    = document.getElementById('kpiGrouping');
    const emptyState     = document.getElementById('salesChartEmptyState');

    filterButtons.forEach(btn => {
        btn.addEventListener('click', async function() {
            const period = this.getAttribute('data-period');
            if (!period) return;

            filterButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            try {
                const response = await fetch(`<?= BASE_URL ?>/api/sales_analytics.php?period=${period}`);
                const result   = await response.json();

                if (result.success && result.data) {
                    const data = result.data;

                    // Update Subtitle & KPIs
                    if (subtitleEl) subtitleEl.innerHTML = `${data.period_label} &mdash; Visual revenue and transaction volume trends`;
                    if (kpiRevenue) kpiRevenue.textContent = data.metrics.total_revenue_formatted;
                    if (kpiTx)      kpiTx.textContent = Number(data.metrics.total_transactions).toLocaleString();
                    if (kpiAvg)     kpiAvg.textContent = data.metrics.avg_transaction_formatted;
                    if (kpiGrouping) kpiGrouping.textContent = data.time_grouping;

                    const dir = data.metrics.pct_direction;
                    const badgeClass = (dir === 'up') ? 'bg-success text-success' : ((dir === 'down') ? 'bg-danger text-danger' : 'bg-secondary text-muted');
                    const iconClass  = (dir === 'up') ? 'bi-arrow-up' : ((dir === 'down') ? 'bi-arrow-down' : 'bi-arrow-right');

                    if (kpiPctChange) {
                        kpiPctChange.innerHTML = `
                            <span class="badge ${badgeClass} bg-opacity-25">
                                <i class="bi ${iconClass} me-1"></i>${data.metrics.pct_change_formatted}
                            </span>
                            <span class="text-muted ms-1 small">${data.prev_period_label}</span>
                        `;
                    }

                    // Empty State Toggle
                    if (emptyState) {
                        if (data.chart.has_data) {
                            emptyState.classList.add('d-none');
                        } else {
                            emptyState.classList.remove('d-none');
                        }
                    }

                    // Update Chart
                    initChart(data);
                }
            } catch (err) {
                console.error('[VendoLink Sales Analytics] Failed to load period:', err);
            }
        });
    });
});
</script>

</body>
</html>
