<?php
/**
 * ============================================================
 * VendoLink — Business Performance Reports
 * ============================================================
 *
 * Dedicated analytics dashboard summarizing transaction performance,
 * service revenue breakdowns, sales over time charts, runtime summaries,
 * and maintenance events for selected reporting periods.
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../models/Service.php';
require_once __DIR__ . '/../models/Transaction.php';

// ---- Role Guard ----
require_role([ROLE_ADMIN, ROLE_OWNER]);

$pageTitle   = 'Business Reports';
$currentPage = 'reports';
$user        = current_user();
$ownerId     = current_owner_id();

$reportModel      = new Report();
$transactionModel = new Transaction();
$servicesList     = $transactionModel->getServices($ownerId);

// ---- Filter Parameters ----
$periodPreset = trim($_GET['period'] ?? 'today');
$serviceFilter = trim($_GET['service'] ?? 'all');
$customFrom   = trim($_GET['date_from'] ?? '');
$customTo     = trim($_GET['date_to'] ?? '');

if (!in_array($periodPreset, ['today', 'week', 'month', 'custom'], true)) {
    $periodPreset = 'today';
}

// If custom dates are explicitly provided in GET, activate custom mode
if (!empty($customFrom) || !empty($customTo)) {
    $periodPreset = 'custom';
}

// ---- Resolve Period Range ----
$period = $reportModel->resolvePeriod($periodPreset, $customFrom, $customTo);
$dateFrom    = $period['date_from'];
$dateTo      = $period['date_to'];
$periodLabel = $period['label'];
$periodType  = $period['type'];

// ---- Handle CSV Export ----
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $reportModel->exportCsv($dateFrom, $dateTo, $serviceFilter, $ownerId);
    exit;
}

// ---- Fetch Analytics Data ----
$summary     = $reportModel->getSummaryMetrics($dateFrom, $dateTo, $serviceFilter, $ownerId);
$timeSeries  = $reportModel->getTimeSeries($dateFrom, $dateTo, $periodType, $serviceFilter, $ownerId);
$maintenance = $reportModel->getMaintenanceSummary($dateFrom, $dateTo, $ownerId);

/**
 * Helper to build report filter URL.
 */
if (!function_exists('build_report_url')) {
    function build_report_url(array $overrides = []): string
    {
        $params = $_GET;
        if (!isset($overrides['export'])) {
            unset($params['export']);
        }
        $params = array_merge($params, $overrides);
        $clean = [];
        foreach ($params as $k => $v) {
            if ($v !== '' && $v !== null && $v !== 'all') {
                $clean[$k] = $v;
            }
        }
        return 'reports.php' . (!empty($clean) ? '?' . http_build_query($clean) : '');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= APP_NAME ?> Business Performance Reports — <?= APP_TAGLINE ?>">
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
                        <i class="bi bi-graph-up me-2"></i>Performance Reports
                    </h1>
                    <p class="page-subtitle">
                        Financial performance analytics, service breakdowns, sales charts, and runtime summaries.
                    </p>
                </div>
                <div class="page-header-actions">
                    <div class="report-period-badge">
                        <i class="bi bi-calendar-event me-1"></i><?= e($periodLabel) ?>
                    </div>
                    <a href="<?= build_report_url(['export' => 'csv']) ?>" class="btn btn-outline-secondary btn-sm" title="Export reporting period as CSV">
                        <i class="bi bi-download me-1"></i>Export CSV
                    </a>
                </div>
            </div>

            <!-- Report Period & Filter Card -->
            <div class="tx-filter-card mb-4">
                <!-- Preset Pills -->
                <div class="d-flex align-items-center gap-2 flex-wrap pb-3 mb-3" style="border-bottom: 1px solid var(--vl-border);">
                    <span class="text-muted small fw-semibold me-2">Report Period:</span>
                    <a href="reports.php?period=today<?= $serviceFilter !== 'all' ? '&service=' . urlencode($serviceFilter) : '' ?>"
                       class="tx-preset-pill <?= $periodPreset === 'today' ? 'active' : '' ?>">
                        Today
                    </a>
                    <a href="reports.php?period=week<?= $serviceFilter !== 'all' ? '&service=' . urlencode($serviceFilter) : '' ?>"
                       class="tx-preset-pill <?= $periodPreset === 'week' ? 'active' : '' ?>">
                        This Week
                    </a>
                    <a href="reports.php?period=month<?= $serviceFilter !== 'all' ? '&service=' . urlencode($serviceFilter) : '' ?>"
                       class="tx-preset-pill <?= $periodPreset === 'month' ? 'active' : '' ?>">
                        This Month
                    </a>
                    <a href="reports.php?period=custom<?= !empty($customFrom) ? '&date_from=' . urlencode($customFrom) : '' ?><?= !empty($customTo) ? '&date_to=' . urlencode($customTo) : '' ?><?= $serviceFilter !== 'all' ? '&service=' . urlencode($serviceFilter) : '' ?>"
                       class="tx-preset-pill <?= $periodPreset === 'custom' ? 'active' : '' ?>">
                        Custom Range
                    </a>
                </div>

                <!-- Custom Range and Service Filter Form -->
                <form method="get" action="reports.php" class="row g-3 align-items-end">
                    <input type="hidden" name="period" value="custom">

                    <!-- Service Filter -->
                    <div class="col-12 col-sm-6 col-md-3">
                        <label class="form-label text-muted small mb-1">Filter Service</label>
                        <select class="form-select form-select-sm dark-input" name="service">
                            <option value="all" <?= $serviceFilter === 'all' ? 'selected' : '' ?>>All Services (Water &amp; Soap)</option>
                            <?php foreach ($servicesList as $svc): ?>
                                <option value="<?= e($svc['service_key']) ?>" <?= $serviceFilter === $svc['service_key'] ? 'selected' : '' ?>>
                                    <?= e($svc['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Date From -->
                    <div class="col-12 col-sm-6 col-md-3">
                        <label class="form-label text-muted small mb-1">From Date</label>
                        <input type="date" class="form-control form-control-sm dark-input" name="date_from" value="<?= e($dateFrom) ?>">
                    </div>

                    <!-- Date To -->
                    <div class="col-12 col-sm-6 col-md-3">
                        <label class="form-label text-muted small mb-1">To Date</label>
                        <input type="date" class="form-control form-control-sm dark-input" name="date_to" value="<?= e($dateTo) ?>">
                    </div>

                    <!-- Actions -->
                    <div class="col-12 col-sm-6 col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill">
                            <i class="bi bi-funnel me-1"></i>Apply Filter
                        </button>
                        <?php if ($periodPreset !== 'today' || $serviceFilter !== 'all'): ?>
                        <a href="reports.php" class="btn btn-outline-secondary btn-sm" title="Reset to Today">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- SECTION 1: PERIOD SUMMARY METRIC CARDS -->
            <div class="row g-3 mb-4">
                <!-- Total Revenue -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card stat-card-accent-success">
                        <div class="stat-card-body">
                            <div class="stat-card-info">
                                <p class="stat-card-label">Total Revenue</p>
                                <h3 class="stat-card-value text-success">₱<?= number_format($summary['total_revenue'], 2) ?></h3>
                                <p class="stat-card-trend">
                                    <span class="trend-badge trend-up">
                                        <i class="bi bi-clock-history"></i> Period Total
                                    </span>
                                </p>
                            </div>
                            <div class="stat-card-icon" style="background: rgba(34,197,94,.1); color: var(--vl-success);">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                        </div>
                        <div class="stat-card-footer">
                            <span class="stat-card-link text-muted">
                                <i class="bi bi-calendar-event me-1"></i><?= e($periodLabel) ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Total Transactions -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card stat-card-accent-primary">
                        <div class="stat-card-body">
                            <div class="stat-card-info">
                                <p class="stat-card-label">Transactions</p>
                                <h3 class="stat-card-value text-primary-vl"><?= number_format($summary['total_transactions']) ?></h3>
                                <p class="stat-card-trend">
                                    <span class="trend-badge trend-neutral">
                                        <i class="bi bi-receipt"></i> Completed
                                    </span>
                                </p>
                            </div>
                            <div class="stat-card-icon" style="background: rgba(14,165,233,.1); color: var(--vl-primary);">
                                <i class="bi bi-receipt-cutoff"></i>
                            </div>
                        </div>
                        <div class="stat-card-footer">
                            <span class="stat-card-link text-muted">
                                Avg. ₱<?= number_format($summary['avg_transaction_value'], 2) ?> per tx
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Water Revenue -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card stat-card-accent-info">
                        <div class="stat-card-body">
                            <div class="stat-card-info">
                                <p class="stat-card-label">Water Revenue</p>
                                <h3 class="stat-card-value text-info">₱<?= number_format($summary['water_revenue'], 2) ?></h3>
                                <p class="stat-card-trend">
                                    <span class="trend-badge" style="background: rgba(56,189,248,.15); color: #38bdf8;">
                                        <i class="bi bi-droplet-fill"></i> <?= $summary['water_share'] ?>% share
                                    </span>
                                </p>
                            </div>
                            <div class="stat-card-icon" style="background: rgba(56,189,248,.1); color: var(--vl-info);">
                                <i class="bi bi-droplet-fill"></i>
                            </div>
                        </div>
                        <div class="stat-card-footer">
                            <span class="stat-card-link text-muted">
                                <?= number_format($summary['water_count']) ?> transactions
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Soap Revenue -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card stat-card-accent-warning">
                        <div class="stat-card-body">
                            <div class="stat-card-info">
                                <p class="stat-card-label">Soap Revenue</p>
                                <h3 class="stat-card-value text-warning">₱<?= number_format($summary['soap_revenue'], 2) ?></h3>
                                <p class="stat-card-trend">
                                    <span class="trend-badge" style="background: rgba(234,179,8,.15); color: #fbbf24;">
                                        <i class="bi bi-stars"></i> <?= $summary['soap_share'] ?>% share
                                    </span>
                                </p>
                            </div>
                            <div class="stat-card-icon" style="background: rgba(245,158,11,.1); color: var(--vl-warning);">
                                <i class="bi bi-stars"></i>
                            </div>
                        </div>
                        <div class="stat-card-footer">
                            <span class="stat-card-link text-muted">
                                <?= number_format($summary['soap_count']) ?> transactions
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECTION 2: SALES OVER TIME CHART -->
            <div class="chart-card mb-4">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="panel-title mb-1">
                            <i class="bi bi-bar-chart-line me-2 text-primary-vl"></i>Sales Overview
                        </h5>
                        <p class="text-muted small mb-0">
                            Revenue generated over time (<?= $timeSeries['interval'] === 'hourly' ? 'Hourly distribution' : 'Daily distribution' ?>)
                        </p>
                    </div>
                    <div class="d-flex align-items-center gap-3 small">
                        <span class="d-inline-flex align-items-center gap-1">
                            <span style="width: 10px; height: 10px; border-radius: 50%; background: #38bdf8; display: inline-block;"></span>
                            <span class="text-muted">Water</span>
                        </span>
                        <span class="d-inline-flex align-items-center gap-1">
                            <span style="width: 10px; height: 10px; border-radius: 50%; background: #eab308; display: inline-block;"></span>
                            <span class="text-muted">Soap</span>
                        </span>
                    </div>
                </div>

                <div style="position: relative; height: 260px; width: 100%;">
                    <canvas id="salesChart"></canvas>
                </div>
            </div>

            <!-- SECTION 3: SERVICE BREAKDOWN (WATER VS SOAP) -->
            <div class="row g-3 mb-4">
                <!-- Water Service Card -->
                <div class="col-12 col-md-6">
                    <div class="report-breakdown-card h-100">
                        <div class="breakdown-header">
                            <div class="d-flex align-items-center gap-2">
                                <div class="stat-card-icon" style="background: rgba(56,189,248,.15); color: #38bdf8; width: 38px; height: 38px; font-size: 1.1rem;">
                                    <i class="bi bi-droplet-fill"></i>
                                </div>
                                <div>
                                    <h6 class="text-light fw-bold mb-0">Water Service</h6>
                                    <span class="text-muted small">Coin-operated dispense cycle</span>
                                </div>
                            </div>
                            <span class="badge bg-info-subtle text-info border border-info">
                                <?= $summary['water_share'] ?>% of Sales
                            </span>
                        </div>

                        <div class="breakdown-stat-grid">
                            <div class="breakdown-stat-box">
                                <span>Revenue</span>
                                <strong class="text-info">₱<?= number_format($summary['water_revenue'], 2) ?></strong>
                            </div>
                            <div class="breakdown-stat-box">
                                <span>Transactions</span>
                                <strong><?= number_format($summary['water_count']) ?></strong>
                            </div>
                            <div class="breakdown-stat-box">
                                <span>Purchased Time</span>
                                <strong class="text-light"><?= e($summary['water_purchased_label']) ?></strong>
                            </div>
                            <div class="breakdown-stat-box">
                                <span>Avg. per Tx</span>
                                <strong class="text-light">
                                    ₱<?= $summary['water_count'] > 0 ? number_format($summary['water_revenue'] / $summary['water_count'], 2) : '0.00' ?>
                                </strong>
                            </div>
                        </div>

                        <!-- Progress Share Bar -->
                        <div class="progress bg-dark" style="height: 6px;">
                            <div class="progress-bar bg-info" role="progressbar"
                                 style="width: <?= $summary['water_share'] ?>%;"
                                 aria-valuenow="<?= $summary['water_share'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>

                <!-- Soap Service Card -->
                <div class="col-12 col-md-6">
                    <div class="report-breakdown-card h-100">
                        <div class="breakdown-header">
                            <div class="d-flex align-items-center gap-2">
                                <div class="stat-card-icon" style="background: rgba(234,179,8,.15); color: #eab308; width: 38px; height: 38px; font-size: 1.1rem;">
                                    <i class="bi bi-stars"></i>
                                </div>
                                <div>
                                    <h6 class="text-light fw-bold mb-0">Soap Service</h6>
                                    <span class="text-muted small">Coin-operated dispense cycle</span>
                                </div>
                            </div>
                            <span class="badge bg-warning-subtle text-warning border border-warning">
                                <?= $summary['soap_share'] ?>% of Sales
                            </span>
                        </div>

                        <div class="breakdown-stat-grid">
                            <div class="breakdown-stat-box">
                                <span>Revenue</span>
                                <strong class="text-warning">₱<?= number_format($summary['soap_revenue'], 2) ?></strong>
                            </div>
                            <div class="breakdown-stat-box">
                                <span>Transactions</span>
                                <strong><?= number_format($summary['soap_count']) ?></strong>
                            </div>
                            <div class="breakdown-stat-box">
                                <span>Purchased Time</span>
                                <strong class="text-light"><?= e($summary['soap_purchased_label']) ?></strong>
                            </div>
                            <div class="breakdown-stat-box">
                                <span>Avg. per Tx</span>
                                <strong class="text-light">
                                    ₱<?= $summary['soap_count'] > 0 ? number_format($summary['soap_revenue'] / $summary['soap_count'], 2) : '0.00' ?>
                                </strong>
                            </div>
                        </div>

                        <!-- Progress Share Bar -->
                        <div class="progress bg-dark" style="height: 6px;">
                            <div class="progress-bar bg-warning" role="progressbar"
                                 style="width: <?= $summary['soap_share'] ?>%;"
                                 aria-valuenow="<?= $summary['soap_share'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECTION 4: RUNTIME SUMMARY -->
            <div class="report-section-card mb-4">
                <div class="breakdown-header">
                    <div>
                        <h5 class="panel-title mb-1">
                            <i class="bi bi-stopwatch me-2 text-primary-vl"></i>Runtime Summary
                        </h5>
                        <p class="text-muted small mb-0">
                            Comparison between purchased customer time and physical machine hardware runtime.
                        </p>
                    </div>
                    <span class="badge bg-secondary-subtle text-light border border-secondary">
                        <i class="bi bi-cpu me-1"></i>VendoCore Controller
                    </span>
                </div>

                <div class="row g-3 mb-2">
                    <!-- Water Runtime Box -->
                    <div class="col-12 col-md-4">
                        <div class="breakdown-stat-box p-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-muted fw-semibold">Water Service</span>
                                <i class="bi bi-droplet-fill text-info"></i>
                            </div>
                            <div class="d-flex justify-content-between text-light small mb-1">
                                <span>Purchased Time:</span>
                                <strong class="text-info"><?= e($summary['water_purchased_label']) ?></strong>
                            </div>
                            <div class="d-flex justify-content-between text-muted small">
                                <span>Hardware Runtime:</span>
                                <span class="badge bg-dark border border-secondary text-secondary font-monospace">Unavailable</span>
                            </div>
                        </div>
                    </div>

                    <!-- Soap Runtime Box -->
                    <div class="col-12 col-md-4">
                        <div class="breakdown-stat-box p-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-muted fw-semibold">Soap Service</span>
                                <i class="bi bi-stars text-warning"></i>
                            </div>
                            <div class="d-flex justify-content-between text-light small mb-1">
                                <span>Purchased Time:</span>
                                <strong class="text-warning"><?= e($summary['soap_purchased_label']) ?></strong>
                            </div>
                            <div class="d-flex justify-content-between text-muted small">
                                <span>Hardware Runtime:</span>
                                <span class="badge bg-dark border border-secondary text-secondary font-monospace">Unavailable</span>
                            </div>
                        </div>
                    </div>

                    <!-- Total Runtime Box -->
                    <div class="col-12 col-md-4">
                        <div class="breakdown-stat-box p-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-muted fw-semibold">Total Cumulative</span>
                                <i class="bi bi-clock text-primary-vl"></i>
                            </div>
                            <div class="d-flex justify-content-between text-light small mb-1">
                                <span>Purchased Time:</span>
                                <strong class="text-primary-vl"><?= e($summary['total_purchased_label']) ?></strong>
                            </div>
                            <div class="d-flex justify-content-between text-muted small">
                                <span>Hardware Runtime:</span>
                                <span class="badge bg-dark border border-secondary text-secondary font-monospace">Unavailable</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Hardware Unavailable Callout -->
                <div class="runtime-notice-box d-flex align-items-start gap-3">
                    <i class="bi bi-info-circle text-info" style="font-size: 1.3rem; margin-top: -2px;"></i>
                    <div>
                        <strong class="text-light d-block mb-1">Hardware runtime data unavailable</strong>
                        <p class="text-muted mb-0 small">
                            The ESP32 microcontroller and physical sensors are not yet connected to the local network.
                            Purchased service time reflects authorized user transactions and historical snapshots (<strong>Purchased Time &ne; Actual Hardware Runtime</strong>).
                        </p>
                    </div>
                </div>
            </div>

            <!-- SECTION 5: MAINTENANCE SUMMARY -->
            <div class="report-section-card mb-4">
                <div class="breakdown-header">
                    <div>
                        <h5 class="panel-title mb-1">
                            <i class="bi bi-wrench-adjustable-circle me-2 text-primary-vl"></i>Maintenance Summary
                        </h5>
                        <p class="text-muted small mb-0">
                            Service records and completed tasks for <?= e($periodLabel) ?>.
                        </p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-primary-subtle text-primary border border-primary">
                            <?= number_format($maintenance['active_reminders_count']) ?> Active Reminders
                        </span>
                        <span class="badge bg-success-subtle text-success border border-success">
                            <?= number_format($maintenance['period_completed_count']) ?> Completed in Period
                        </span>
                    </div>
                </div>

                <?php if (empty($maintenance['recent_completions'])): ?>
                <div class="activity-empty py-3">
                    <i class="bi bi-wrench" style="font-size: 1.8rem; color: var(--vl-text-muted);"></i>
                    <p class="text-muted mt-2 mb-0 small">No maintenance events recorded during this period.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle mb-0" style="--bs-table-bg: transparent;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--vl-border); color: var(--vl-text-muted); font-size: .78rem; text-transform: uppercase; letter-spacing: .06em;">
                                <th class="ps-3">Maintenance Task</th>
                                <th>Service</th>
                                <th>Completed Date</th>
                                <th>Logged Runtime</th>
                                <th>Completed By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($maintenance['recent_completions'] as $mc):
                                $sec = (int) $mc['runtime_at_completion_seconds'];
                                $runtimeFmt = intdiv($sec, 3600) . 'h ' . intdiv($sec % 3600, 60) . 'm';
                            ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,.04); font-size: .88rem;">
                                <td class="ps-3">
                                    <div class="text-light fw-medium">
                                        <i class="bi bi-check-circle-fill text-success me-1"></i>
                                        <?= e($mc['maintenance_name']) ?>
                                    </div>
                                    <?php if (!empty($mc['notes'])): ?>
                                    <div class="tx-snapshot-tag">
                                        Note: <?= e($mc['notes']) ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="text-light"><?= e($mc['service_name'] ?: 'Entire Machine') ?></span>
                                </td>
                                <td>
                                    <div class="text-light"><?= format_date($mc['completed_at'], 'M j, Y g:i A') ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-dark border border-secondary text-light font-monospace">
                                        <?= $runtimeFmt ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="text-light-subtle"><?= e($mc['full_name'] ?: 'System') ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<!-- VendoLink JS -->
<script src="<?= ASSETS_URL ?>/js/app.js"></script>

<!-- Sales Chart Initialization -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('salesChart');
    if (!ctx) return;

    const chartLabels = <?= json_encode($timeSeries['labels']) ?>;
    const waterData   = <?= json_encode($timeSeries['water_data']) ?>;
    const soapData    = <?= json_encode($timeSeries['soap_data']) ?>;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: chartLabels,
            datasets: [
                {
                    label: 'Water (₱)',
                    data: waterData,
                    backgroundColor: 'rgba(56, 189, 248, 0.75)',
                    borderColor: '#38bdf8',
                    borderWidth: 1,
                    borderRadius: 4,
                    stack: 'salesStack'
                },
                {
                    label: 'Soap (₱)',
                    data: soapData,
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
                    display: false
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
                        color: '#94a3b8',
                        font: { size: 11, family: 'Inter, sans-serif' },
                        maxRotation: 0,
                        autoSkip: true,
                        maxTicksLimit: 12
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
                        color: '#94a3b8',
                        font: { size: 11, family: 'Inter, sans-serif' },
                        callback: function(val) {
                            return '₱' + val;
                        }
                    }
                }
            }
        }
    });
});
</script>

</body>
</html>
