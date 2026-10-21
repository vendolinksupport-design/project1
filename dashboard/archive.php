<?php
/**
 * ============================================================
 * VendoLink — History Archive
 * ============================================================
 *
 * Read-only historical record repository containing:
 * 1. Transaction History (Service transactions & rate snapshots)
 *    - Secondary selection: Transaction Records
 *    - Secondary selection: Cash Collections
 * 2. Maintenance History (Completed maintenance records & runtimes)
 * 3. Configuration History (Service pricing & time change audits)
 *
 * Strictly read-only to preserve a reliable audit trail.
 *
 * @package VendoLink
 * @version 1.1
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Archive.php';
require_once __DIR__ . '/../models/Machine.php';
require_once __DIR__ . '/../models/Service.php';
require_once __DIR__ . '/../models/Transaction.php';

// ---- Role Guard ----
require_role([ROLE_ADMIN, ROLE_OWNER]);

$pageTitle   = term('history', 'History');
$currentPage = 'archive';
$user        = current_user();
$ownerId     = current_owner_id();

// ---- Handle POST Actions (e.g. Record Cash Collection) ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'record_cash_collection') {
        $collectedAmount = (float)($_POST['collected_amount'] ?? 0);
        $notes           = trim($_POST['notes'] ?? '');
        $userId          = (int)(current_user()['id'] ?? 0);
        $machineModel    = new Machine();
        $primaryMachine  = $machineModel->primaryWithServices($ownerId);
        $machineId       = (int)($_POST['machine_id'] ?? (current_selected_machine_id($ownerId) ?: (int)($primaryMachine['id'] ?? 0)));
        $isOverride      = !empty($_POST['is_override']);
        $overrideReason  = trim($_POST['override_reason'] ?? '');
        $userRole        = current_user()['role'] ?? '';

        if ($machineId <= 0) {
            flash('danger', "No active machine assigned to your account.");
            redirect('dashboard/archive.php?tab=transactions&section=collections');
        } elseif ($collectedAmount > 0) {
            try {
                $txModel = new Transaction();
                $collId = $txModel->recordCashCollection(
                    $userId,
                    $machineId,
                    $collectedAmount,
                    $notes,
                    $isOverride,
                    $overrideReason,
                    $userId,
                    $userRole
                );
                flash('success', "Cash collection of ₱" . number_format($collectedAmount, 2) . " saved successfully!");
                log_activity('CASH_COLLECTED', "Collected ₱" . number_format($collectedAmount, 2) . " from machine #{$machineId} (Record #{$collId}).");
                redirect('dashboard/archive.php?tab=transactions&section=collections');
            } catch (\InvalidArgumentException $e) {
                flash('danger', $e->getMessage());
                redirect('dashboard/archive.php?tab=transactions&section=collections');
            } catch (\Throwable $e) {
                flash('danger', "Failed to record cash collection: " . $e->getMessage());
                redirect('dashboard/archive.php?tab=transactions&section=collections');
            }
        } else {
            flash('danger', "Please enter a valid cash collection amount.");
            redirect('dashboard/archive.php?tab=transactions&section=collections');
        }
    }
}

$archiveModel = new Archive();
$serviceModel = new Service();
$machineModel = new Machine();
$primaryMachine = $machineModel->primaryWithServices($ownerId);
$activeMachineId= current_selected_machine_id($ownerId) ?: (int)($primaryMachine['id'] ?? 0);
$allMachines    = $machineModel->getAll($ownerId);
$servicesList = (new Transaction())->getServices($ownerId);

// ---- Active Top-Level Tab ----
$activeTab = $_GET['tab'] ?? 'transactions';
if (!in_array($activeTab, ['transactions', 'maintenance', 'configuration'], true)) {
    $activeTab = 'transactions';
}

// ---- Secondary Section (inside Transaction History tab) ----
$activeSection = 'records';
if ($activeTab === 'transactions') {
    $activeSection = ($_GET['section'] ?? 'records') === 'collections' ? 'collections' : 'records';
}

// ---- Filters for Transaction Records, Maintenance & Configuration ----
$search   = trim($_GET['search'] ?? '');
$service  = trim($_GET['service'] ?? 'all');
$status   = trim($_GET['status'] ?? 'all');
if (in_array($status, ['interrupted'], true)) {
    $status = 'incomplete';
} elseif (in_array($status, ['cancelled', 'failed'], true)) {
    $status = 'unsuccessful';
}
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? $_GET['ccpage'] ?? 1));
$perPage  = max(1, min(100, (int)($_GET['per_page'] ?? 15)));

$filters = [
    'search'    => $search,
    'service'   => $service,
    'status'    => $status,
    'date_from' => $dateFrom,
    'date_to'   => $dateTo,
];

// ---- Separate Filters for Cash Collections ----
$ccSearch   = trim($_GET['search'] ?? '');
$ccDateFrom = trim($_GET['date_from'] ?? '');
$ccDateTo   = trim($_GET['date_to'] ?? '');

$ccFilters = [
    'search'    => $ccSearch,
    'date_from' => $ccDateFrom,
    'date_to'   => $ccDateTo,
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
    } elseif ($exportType === 'cash_collections') {
        $txModel->exportCashCollectionsCsv($ccFilters, $ownerId);
        exit;
    }
}

// ---- Fetch Data for Current Tab & Section ----
$cashCollections      = [];
$totalCashCollections = 0;
$ccPerPage            = $perPage;
$ccTotalPages         = 1;
$ccCurrentPage        = 1;
$pagedCashCollections = [];
$ccSummary            = [
    'total_collected'    => 0.00,
    'total_system_sales' => 0.00,
    'net_variance'       => 0.00,
    'total_count'        => 0,
    'match_count'        => 0,
    'surplus_count'      => 0,
    'shortage_count'     => 0,
];

$historySummary = [
    'total_revenue'     => 0.00,
    'transaction_count' => 0,
    'completed_count'   => 0,
    'water_revenue'     => 0.00,
    'water_count'       => 0,
    'soap_revenue'      => 0.00,
    'soap_count'        => 0,
];

if ($activeTab === 'transactions') {
    $transactionModel = new Transaction();

    if ($activeSection === 'records') {
        $result               = $archiveModel->getTransactionHistory($filters, $page, $perPage, $ownerId);
        $historySummary       = $transactionModel->getAuditMetrics($filters, $ownerId);
        $records              = $result['items'];
        $totalItems           = $result['total_items'];
        $totalPages           = $result['total_pages'];
        $page                 = $result['page'];
    } else {
        $allCollections       = $transactionModel->getCashCollections(0, $ownerId, $ccFilters);
        $totalCashCollections = count($allCollections);

        $totCol = 0.0;
        $totSys = 0.0;
        $totVar = 0.0;
        $mCount = 0;
        $sCount = 0;
        $shCount = 0;

        foreach ($allCollections as $ccItem) {
            $cCol = (float)($ccItem['collected_amount'] ?? 0);
            $cSys = (float)($ccItem['system_sales_amount'] ?? 0);
            $cVar = (float)($ccItem['variance_amount'] ?? 0);
            $totCol += $cCol;
            $totSys += $cSys;
            $totVar += $cVar;
            if ($cVar == 0) {
                $mCount++;
            } elseif ($cVar > 0) {
                $sCount++;
            } else {
                $shCount++;
            }
        }

        $ccSummary = [
            'total_collected'    => $totCol,
            'total_system_sales' => $totSys,
            'net_variance'       => $totVar,
            'total_count'        => $totalCashCollections,
            'match_count'        => $mCount,
            'surplus_count'      => $sCount,
            'shortage_count'     => $shCount,
        ];

        $ccTotalPages         = max(1, (int)ceil($totalCashCollections / $ccPerPage));
        $ccCurrentPage        = max(1, min($ccTotalPages, $page));
        $ccOffset             = ($ccCurrentPage - 1) * $ccPerPage;
        $pagedCashCollections = array_slice($allCollections, $ccOffset, $ccPerPage);

        $records              = $pagedCashCollections;
        $totalItems           = $totalCashCollections;
        $totalPages           = $ccTotalPages;
        $page                 = $ccCurrentPage;
    }
} elseif ($activeTab === 'maintenance') {
    $result     = $archiveModel->getMaintenanceHistory($filters, $page, $perPage, $ownerId);
    $records    = $result['items'];
    $totalItems = $result['total_items'];
    $totalPages = $result['total_pages'];
    $page       = $result['page'];
} else {
    $result     = $archiveModel->getConfigurationHistory($filters, $page, $perPage, $ownerId, $user['role'] ?? null);
    $records    = $result['items'];
    $totalItems = $result['total_items'];
    $totalPages = $result['total_pages'];
    $page       = $result['page'];
}

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
                        Searchable records of completed transactions, cash collections, maintenance events, and service configuration changes.
                    </p>
                </div>
                <div class="page-header-actions">
                    <span class="readonly-indicator">
                        <i class="bi bi-shield-lock me-1"></i>Audit Records
                    </span>
                    <div class="page-date">
                        <?= date('l, F j, Y') ?>
                    </div>
                </div>
            </div>

            <!-- Top-Level Tab Navigation -->
            <div class="archive-tab-nav">
                <a href="<?= build_archive_url(['tab' => 'transactions', 'section' => 'records', 'page' => 1]) ?>"
                   class="archive-tab-btn <?= $activeTab === 'transactions' ? 'active' : '' ?>">
                    <i class="bi bi-cash-coin"></i>
                    <span>Transaction History</span>
                </a>
                <a href="<?= build_archive_url(['tab' => 'maintenance', 'section' => null, 'page' => 1, 'service' => null, 'status' => null, 'search' => null, 'date_from' => null, 'date_to' => null]) ?>"
                   class="archive-tab-btn <?= $activeTab === 'maintenance' ? 'active' : '' ?>">
                    <i class="bi bi-wrench-adjustable-circle"></i>
                    <span>Maintenance History</span>
                </a>
                <a href="<?= build_archive_url(['tab' => 'configuration', 'section' => null, 'page' => 1, 'service' => null, 'status' => null, 'search' => null, 'date_from' => null, 'date_to' => null]) ?>"
                   class="archive-tab-btn <?= $activeTab === 'configuration' ? 'active' : '' ?>">
                    <i class="bi bi-sliders"></i>
                    <span>Configuration History</span>
                </a>
            </div>

            <?php if ($activeTab === 'transactions'): ?>
            <!-- Secondary Sub-Tab Navigation inside Transaction History -->
            <div class="archive-subtab-nav mb-4" role="tablist" aria-label="Transaction History Sub-sections">
                <a href="<?= build_archive_url(['tab' => 'transactions', 'section' => 'records', 'page' => 1]) ?>"
                   class="archive-subtab-btn <?= $activeSection === 'records' ? 'active' : '' ?>"
                   role="tab"
                   aria-selected="<?= $activeSection === 'records' ? 'true' : 'false' ?>">
                    <?= lucide_icon('receipt', 'me-1.5') ?>
                    <span>Transaction History</span><!-- Transaction Records -->
                </a>
                <a href="<?= build_archive_url(['tab' => 'transactions', 'section' => 'collections', 'page' => 1, 'status' => null, 'service' => null, 'search' => null, 'date_from' => null, 'date_to' => null]) ?>#cashCollectionsArchive"
                   class="archive-subtab-btn <?= $activeSection === 'collections' ? 'active' : '' ?>"
                   role="tab"
                   aria-selected="<?= $activeSection === 'collections' ? 'true' : 'false' ?>">
                    <?= lucide_icon('wallet', 'me-1.5') ?>
                    <span>Cash Collections</span>
                </a>
            </div>
            <?php endif; ?>

            <!-- ======================================================== -->
            <!-- SECTION 1A: TRANSACTION RECORDS                          -->
            <!-- ======================================================== -->
            <?php if ($activeTab === 'transactions' && $activeSection === 'records'): ?>

            <!-- Transaction Filter Card -->
            <div class="tx-filter-card mb-4">
                <form method="get" action="archive.php" class="row g-3 align-items-end">
                    <input type="hidden" name="tab" value="transactions">
                    <input type="hidden" name="section" value="records">

                    <!-- Search Input -->
                    <div class="col-12 col-md-3 col-xl-2">
                        <label class="form-label text-muted small mb-1">Search</label>
                        <div class="input-group input-group-sm history-search-group">
                            <span class="history-search-icon" aria-hidden="true">
                                <?= lucide_icon('search', 'text-muted') ?>
                            </span>
                            <input type="text" class="form-control form-control-sm dark-input" name="search"
                                   placeholder="Search ID (#00001) or service..."
                                   value="<?= e($search) ?>">
                        </div>
                    </div>

                    <!-- Service Filter -->
                    <div class="col-12 col-sm-6 col-md-2 col-xl-2">
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

                    <!-- Status Filter -->
                    <div class="col-12 col-sm-6 col-md-2 col-xl-2">
                        <label class="form-label text-muted small mb-1">Status</label>
                        <select class="form-select form-select-sm dark-input" name="status">
                            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="incomplete" <?= in_array($status, ['incomplete', 'interrupted'], true) ? 'selected' : '' ?>>Incomplete</option>
                            <option value="unsuccessful" <?= in_array($status, ['unsuccessful', 'cancelled', 'failed'], true) ? 'selected' : '' ?>>Unsuccessful</option>
                        </select>
                    </div>

                    <!-- Date From -->
                    <div class="col-12 col-sm-6 col-md-2 col-xl-2">
                        <label class="form-label text-muted small mb-1">From Date</label>
                        <input type="date" class="form-control form-control-sm dark-input" name="date_from" value="<?= e($dateFrom) ?>">
                    </div>

                    <!-- Date To -->
                    <div class="col-12 col-sm-6 col-md-2 col-xl-2">
                        <label class="form-label text-muted small mb-1">To Date</label>
                        <input type="date" class="form-control form-control-sm dark-input" name="date_to" value="<?= e($dateTo) ?>">
                    </div>

                    <!-- Action Buttons -->
                    <div class="col-12 col-sm-6 col-md-1 col-xl-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill">
                            <i class="bi bi-funnel me-1"></i>Apply
                        </button>
                        <?php if (!empty($search) || $service !== 'all' || $status !== 'all' || !empty($dateFrom) || !empty($dateTo)): ?>
                        <a href="archive.php?tab=transactions&section=records" class="btn btn-outline-secondary btn-sm" title="Clear Filters">
                            <i class="bi bi-x-circle"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Transaction Records Table Panel -->
            <div class="tx-table-card archive-table-card mb-4" style="display: flex; flex-direction: column; min-height: 944px;">
                <div class="panel-header d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                    <div>
                        <h5 class="panel-title mb-1 d-flex align-items-center">
                            <?= lucide_icon('receipt', 'me-2 text-primary-vl') ?>Transaction Records
                        </h5>
                        <span class="text-muted small">
                            Showing <?= number_format(count($records)) ?> of <?= number_format($totalItems) ?> records
                        </span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <a href="<?= build_archive_url(['export' => 'excel']) ?>" class="btn btn-sm btn-outline-success text-nowrap d-inline-flex align-items-center" title="Export filtered records to Excel">
                            <?= lucide_icon('download', 'me-1.5') ?>Export Excel
                        </a>
                    </div>
                </div>

                <div class="table-responsive" style="flex: 1 0 auto; display: flex; flex-direction: column;">
                    <?php if (empty($records)): ?>
                    <div class="activity-empty py-5 text-center d-flex flex-column align-items-center justify-content-center" style="flex: 1 0 auto; min-height: 400px;">
                        <?= lucide_icon('search', 'mb-3 opacity-50', ['style' => 'color: #64748B; width: 44px; height: 44px;']) ?>
                        <h6 class="text-white fw-bold mb-1">No records match your filters</h6>
                        <p class="text-muted small mb-3">Try adjusting your search criteria or date ranges.</p>
                        <?php if (!empty($search) || $service !== 'all' || $status !== 'all' || !empty($dateFrom) || !empty($dateTo)): ?>
                        <a href="archive.php?tab=transactions&section=records" class="btn btn-sm btn-outline-secondary">
                            <?= lucide_icon('rotate-ccw', 'me-1') ?>Reset Filters
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
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
                                    'water' => 'droplets',
                                    'soap'  => 'sparkles',
                                    default => 'cog',
                                };
                                $serviceColor = match ($tx['service_key'] ?? '') {
                                    'water' => 'text-info',
                                    'soap'  => 'text-warning',
                                    default => 'text-primary',
                                };
                                $serviceDisplayName = match (strtolower($tx['service_key'] ?? '')) {
                                    'water' => 'Water',
                                    'soap'  => 'Soap',
                                    default => preg_replace('/\s+Service$/i', '', $tx['service_name'] ?: 'Service'),
                                };
                                $statusBadge = match ($tx['status']) {
                                    'completed'   => 'tx-badge-completed',
                                    'active'      => 'tx-badge-active',
                                    'pending'     => 'tx-badge-pending',
                                    'cancelled'   => 'tx-badge-cancelled',
                                    'failed'      => 'tx-badge-failed',
                                    'incomplete'  => 'tx-badge-incomplete',
                                    'interrupted' => 'tx-badge-interrupted',
                                    default       => 'tx-badge-pending',
                                };
                                $statusLabel = match (strtolower($tx['status'] ?? '')) {
                                    'completed'   => 'Completed',
                                    'incomplete'  => 'Incomplete',
                                    'interrupted' => 'Interrupted',
                                    'failed'      => 'Unsuccessful',
                                    'active'      => 'Active',
                                    'pending'     => 'Pending',
                                    'cancelled'   => 'Cancelled',
                                    default       => ucfirst(strtolower($tx['status'] ?? '')),
                                };
                                $machineDisplayName = preg_replace('/^VendoLink\s+/i', '', $tx['machine_name'] ?: 'Machine 01');
                            ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,.04); font-size: .88rem;">
                                <!-- Transaction ID -->
                                <td class="ps-4">
                                    <span class="tx-id text-white fw-bold"><?= e($tx['formatted_id']) ?></span>
                                </td>

                                <!-- Date & Time -->
                                <td>
                                    <div class="text-light fw-medium">
                                        <?= format_date($tx['created_at'], 'M j, Y, g:i A') ?>
                                    </div>
                                    <div class="text-muted small">
                                        <?= time_ago($tx['created_at']) ?>
                                    </div>
                                </td>

                                <!-- Machine -->
                                <td>
                                    <div class="text-light fw-medium">
                                        <?= e($machineDisplayName) ?>
                                    </div>
                                    <div class="text-muted small">
                                        <?= e($tx['machine_location'] ?: 'Main Establishment') ?>
                                    </div>
                                </td>

                                <!-- Service -->
                                <td>
                                    <span class="d-inline-flex align-items-center gap-1.5">
                                        <?= lucide_icon($serviceIcon, $serviceColor) ?>
                                        <span class="text-light fw-medium"><?= e($serviceDisplayName) ?></span>
                                    </span>
                                </td>

                                <!-- Amount -->
                                <td>
                                    <span class="fw-bold text-success" style="font-size: .95rem;">
                                        <?= e($tx['amount_formatted']) ?>
                                    </span>
                                </td>

                                <!-- Purchased Time -->
                                <td>
                                    <?php if ($tx['status'] === 'incomplete'): ?>
                                        <div class="text-light fw-medium d-inline-flex align-items-center">
                                            <?= lucide_icon('clock', 'me-1 text-warning') ?>
                                            <?= e($tx['actual_time_label'] ?? '0s') ?> <span class="text-muted small ms-1">/ <?= e($tx['purchased_time_label']) ?></span>
                                        </div>
                                        <div class="text-warning small" style="font-size: 0.75rem;">
                                            Ran <?= e($tx['actual_time_label'] ?? '0s') ?> before interruption
                                        </div>
                                    <?php else: ?>
                                        <div class="text-light fw-medium d-inline-flex align-items-center">
                                            <?= lucide_icon('clock', 'me-1 text-primary-vl') ?>
                                            <?= e($tx['purchased_time_label']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- Status -->
                                <td>
                                    <span class="tx-badge <?= $statusBadge ?>"><?= e($statusLabel) ?></span>
                                    <?php if (!empty($tx['status_reason'])): ?>
                                        <div class="text-muted mt-1" style="font-size: 0.72rem; max-width: 190px; line-height: 1.2;">
                                            <?= e($tx['status_reason']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Reserved Space Indicator (Shown when current page has fewer than 15 records) -->
                    <?php if (count($records) < 15): ?>
                    <div class="table-reserved-space text-center py-4 d-flex flex-column align-items-center justify-content-center" style="min-height: <?= max(100, (15 - count($records)) * 44) ?>px;">
                        <?= lucide_icon('layers', 'layers-icon mb-2') ?>
                        <div class="text-slate-400 fw-medium small">Reserved space for the remaining rows</div>
                        <div class="small" style="color: #64748B;">No placeholder rows are displayed.</div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Anchored Pagination Controls at Bottom -->
                <div class="tx-pagination" style="margin-top: auto;">
                    <div class="text-muted small">
                        <?php if ($totalItems > 0): ?>
                            Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong> (Total <?= number_format($totalItems) ?> records)
                        <?php else: ?>
                            0 records found
                        <?php endif; ?>
                    </div>
                    <nav aria-label="Transaction pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <!-- Previous -->
                            <li class="page-item <?= ($page <= 1 || $totalItems === 0) ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= build_archive_url(['page' => $page - 1]) ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;&nbsp;Prev</span>
                                </a>
                            </li>

                            <!-- Page Numbers -->
                            <?php
                            if ($totalItems > 0):
                                $startPage = max(1, $page - 2);
                                $endPage   = min($totalPages, $page + 2);

                                if ($startPage > 1) {
                                    echo '<li class="page-item"><a class="page-link" href="' . build_archive_url(['page' => 1]) . '">1</a></li>';
                                    if ($startPage > 2) {
                                        echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
                                    }
                                }

                                for ($p = $startPage; $p <= $endPage; $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= build_archive_url(['page' => $p]) ?>"><?= $p ?></a>
                                </li>
                                <?php endfor;

                                if ($endPage < $totalPages) {
                                    if ($endPage < $totalPages - 1) {
                                        echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
                                    }
                                    echo '<li class="page-item"><a class="page-link" href="' . build_archive_url(['page' => $totalPages]) . '">' . $totalPages . '</a></li>';
                                }
                            endif;
                            ?>

                            <!-- Next -->
                            <li class="page-item <?= ($page >= $totalPages || $totalItems === 0) ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= build_archive_url(['page' => $page + 1]) ?>" aria-label="Next">
                                    <span aria-hidden="true">Next&nbsp;&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>

            </div>

            <!-- ======================================================== -->
            <!-- SECTION 1B: CASH COLLECTIONS                             -->
            <!-- ======================================================== -->
            <?php elseif ($activeTab === 'transactions' && $activeSection === 'collections'): ?>

            <!-- Cash Collections Filter Card -->
            <div class="tx-filter-card mb-4">
                <form method="get" action="archive.php" class="row g-3 align-items-end">
                    <input type="hidden" name="tab" value="transactions">
                    <input type="hidden" name="section" value="collections">

                    <!-- Search Input -->
                    <div class="col-12 col-md-4 col-xl-4">
                        <label class="form-label text-muted small mb-1 fw-medium">Search</label>
                        <div class="input-group input-group-sm history-search-group">
                            <span class="history-search-icon" aria-hidden="true">
                                <?= lucide_icon('search', 'text-muted') ?>
                            </span>
                            <input type="text" class="form-control form-control-sm dark-input" name="search"
                                   placeholder="<?= is_admin() ? 'Search notes, machine, or recorded by...' : 'Search notes or machine...' ?>"
                                   value="<?= e($ccSearch) ?>">
                        </div>
                    </div>

                    <!-- Date From -->
                    <div class="col-12 col-sm-6 col-md-3 col-xl-3">
                        <label class="form-label text-muted small mb-1 fw-medium">From Date</label>
                        <input type="date" class="form-control form-control-sm dark-input text-white" name="date_from" value="<?= e($ccDateFrom) ?>">
                    </div>

                    <!-- Date To -->
                    <div class="col-12 col-sm-6 col-md-3 col-xl-3">
                        <label class="form-label text-muted small mb-1 fw-medium">To Date</label>
                        <input type="date" class="form-control form-control-sm dark-input text-white" name="date_to" value="<?= e($ccDateTo) ?>">
                    </div>

                    <!-- Action Buttons -->
                    <div class="col-12 col-sm-6 col-md-2 col-xl-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill d-inline-flex align-items-center justify-content-center">
                            <?= lucide_icon('filter', 'me-1') ?>Apply
                        </button>
                        <?php if (!empty($ccSearch) || !empty($ccDateFrom) || !empty($ccDateTo)): ?>
                        <a href="archive.php?tab=transactions&section=collections" class="btn btn-outline-secondary btn-sm" title="Clear Filters">
                            <?= lucide_icon('rotate-ccw') ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Cash Collections Records Panel -->
            <div class="tx-table-card archive-table-card mb-4" id="cashCollectionsArchive" style="display: flex; flex-direction: column; min-height: 944px;">
                <div class="panel-header d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                    <div>
                        <h5 class="panel-title mb-1 d-flex align-items-center">
                            <?= lucide_icon('wallet', 'text-success me-2') ?>Cash Collections
                        </h5>
                        <span class="text-muted small">
                            Showing <?= number_format(count($pagedCashCollections)) ?> of <?= number_format($totalCashCollections) ?> collection record<?= $totalCashCollections === 1 ? '' : 's' ?>
                        </span>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <a href="<?= build_archive_url(['export' => 'cash_collections']) ?>" class="btn btn-sm btn-outline-success text-nowrap d-inline-flex align-items-center" title="Export cash collection records to Excel">
                            <?= lucide_icon('download', 'me-1.5') ?>Export Excel
                        </a>
                        <button type="button" class="btn btn-sm btn-success fw-semibold text-nowrap d-inline-flex align-items-center" data-bs-toggle="modal" data-bs-target="#recordCashModal">
                            <?= lucide_icon('plus-circle', 'me-1.5') ?>Record Cash Collection
                        </button>
                    </div>
                </div>

                <div class="table-responsive" style="flex: 1 0 auto; display: flex; flex-direction: column;">
                    <?php if (empty($pagedCashCollections)): ?>
                    <div class="activity-empty py-5 text-center d-flex flex-column align-items-center justify-content-center" style="flex: 1 0 auto; min-height: 400px;">
                        <?= lucide_icon('search', 'mb-3 opacity-50', ['style' => 'color: #64748B; width: 44px; height: 44px;']) ?>
                        <h6 class="text-white fw-bold mb-1">No records match your filters</h6>
                        <p class="text-muted small mb-3">Try adjusting your search criteria or date ranges.</p>
                        <?php if (!empty($ccSearch) || !empty($ccDateFrom) || !empty($ccDateTo)): ?>
                        <a href="archive.php?tab=transactions&section=collections" class="btn btn-sm btn-outline-secondary">
                            <?= lucide_icon('rotate-ccw', 'me-1') ?>Reset Filters
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <table class="table table-dark table-hover align-middle mb-0" style="--bs-table-bg: transparent;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--vl-border); color: #94a3b8; font-size: .78rem; text-transform: uppercase; letter-spacing: .06em;">
                                <th class="ps-4">Collection Date</th>
                                <th>Machine</th>
                                <?php if (is_admin()): ?>
                                <th>Recorded By</th>
                                <?php endif; ?>
                                <th>Cash Collected</th>
                                <th>Expected Sales</th>
                                <th>Difference</th>
                                <th>Result</th>
                                <th class="pe-4">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pagedCashCollections as $cc): 
                                $var = (float)($cc['variance_amount'] ?? 0);
                                if (!empty($cc['is_override'])) {
                                    $reconBadgeClass = 'badge-above';
                                    $reconLabel      = 'Override';
                                    $reconIcon       = 'shield-alert';
                                } elseif ($var == 0) {
                                    $reconBadgeClass = 'badge-matched';
                                    $reconLabel      = 'Matched';
                                    $reconIcon       = 'check-circle';
                                } elseif ($var > 0) {
                                    $reconBadgeClass = 'badge-above';
                                    $reconLabel      = 'Excess';
                                    $reconIcon       = 'arrow-up-right';
                                } else {
                                    $reconBadgeClass = 'badge-below';
                                    $reconLabel      = 'Shortage';
                                    $reconIcon       = 'arrow-down-right';
                                }
                            ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,.04); font-size: .88rem;">
                                <td class="ps-4 fw-bold text-light">
                                    <?= format_date($cc['collected_at'], 'M d, Y, g:i A') ?>
                                </td>
                                <td>
                                    <div class="text-light fw-medium">
                                        <?= e(preg_replace('/^VendoLink\s+/i', '', $cc['machine_name'] ?: 'Machine 01')) ?>
                                    </div>
                                </td>
                                <?php if (is_admin()): ?>
                                <td class="small text-secondary">
                                    <?= e($cc['full_name'] ?? '—') ?>
                                </td>
                                <?php endif; ?>
                                <td class="fw-bold text-success font-monospace">
                                    ₱<?= number_format((float)$cc['collected_amount'], 2) ?>
                                </td>
                                <td class="text-light font-monospace">
                                    ₱<?= number_format((float)($cc['expected_amount'] > 0 ? $cc['expected_amount'] : $cc['system_sales_amount']), 2) ?>
                                </td>
                                <td>
                                    <span class="font-monospace fw-semibold <?= $var == 0 ? 'text-success' : ($var > 0 ? 'text-warning' : 'text-danger') ?>">
                                        <?= $var > 0 ? '+₱' : ($var < 0 ? '-₱' : '₱') ?><?= number_format(abs($var), 2) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-reconciliation <?= $reconBadgeClass ?>">
                                        <?= lucide_icon($reconIcon, 'me-1') ?><?= $reconLabel ?>
                                    </span>
                                </td>
                                <td class="pe-4 small text-secondary" style="max-width: 250px;">
                                    <div><?= e($cc['notes'] ?: '—') ?></div>
                                    <?php if (!empty($cc['override_reason'])): ?>
                                        <div class="text-warning small mt-1 d-flex align-items-center gap-1 font-monospace" style="font-size: .75rem;">
                                            <i class="bi bi-shield-check"></i> Override: <?= e($cc['override_reason']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Reserved Space Indicator (Shown when current page has fewer than 15 records) -->
                    <?php if (count($pagedCashCollections) < 15): ?>
                    <div class="table-reserved-space text-center py-4 d-flex flex-column align-items-center justify-content-center" style="min-height: <?= max(100, (15 - count($pagedCashCollections)) * 44) ?>px;">
                        <?= lucide_icon('layers', 'layers-icon mb-2') ?>
                        <div class="text-slate-400 fw-medium small">Reserved space for the remaining rows</div>
                        <div class="small" style="color: #64748B;">No placeholder rows are displayed.</div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Cash Collections Pagination Controls -->
                <div class="tx-pagination" style="margin-top: auto;">
                    <div class="text-muted small">
                        <?php if ($totalCashCollections > 0): ?>
                            Page <strong><?= $page ?></strong> of <strong><?= $ccTotalPages ?></strong> (Total <?= number_format($totalCashCollections) ?> record<?= $totalCashCollections === 1 ? '' : 's' ?>)
                        <?php else: ?>
                            0 records found
                        <?php endif; ?>
                    </div>
                    <nav aria-label="Cash collections pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <!-- Previous -->
                            <li class="page-item <?= ($page <= 1 || $totalCashCollections === 0) ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= build_archive_url(['page' => $page - 1]) ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;&nbsp;Prev</span>
                                </a>
                            </li>

                            <?php
                            if ($totalCashCollections > 0):
                                for ($cp = 1; $cp <= $ccTotalPages; $cp++): ?>
                                <li class="page-item <?= $cp === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= build_archive_url(['page' => $cp]) ?>"><?= $cp ?></a>
                                </li>
                                <?php endfor;
                            endif;
                            ?>

                            <!-- Next -->
                            <li class="page-item <?= ($page >= $ccTotalPages || $totalCashCollections === 0) ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= build_archive_url(['page' => $page + 1]) ?>" aria-label="Next">
                                    <span aria-hidden="true">Next&nbsp;&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>

            </div>

            <!-- ======================================================== -->
            <!-- SECTION 2 & 3: MAINTENANCE & CONFIGURATION HISTORY        -->
            <!-- ======================================================== -->
            <?php else: ?>

            <!-- Maintenance / Configuration Filter Card -->
            <div class="tx-filter-card mb-4">
                <form method="get" action="archive.php" class="row g-3 align-items-end">
                    <input type="hidden" name="tab" value="<?= e($activeTab) ?>">

                    <!-- Search Input -->
                    <div class="col-12 <?= $activeTab === 'configuration' ? 'col-md-4 col-xl-4' : 'col-md-5 col-xl-5' ?>">
                        <label class="form-label text-muted small mb-1">Search</label>
                        <div class="input-group input-group-sm history-search-group">
                            <span class="history-search-icon" aria-hidden="true">
                                <?= lucide_icon('search', 'text-muted') ?>
                            </span>
                            <input type="text" class="form-control form-control-sm dark-input" name="search"
                                   placeholder="<?= $activeTab === 'maintenance' ? 'Search maintenance name or notes...' : ($activeTab === 'configuration' && !is_admin() ? 'Search service or machine...' : 'Search service, user, or machine...') ?>"
                                   value="<?= e($search) ?>">
                        </div>
                    </div>

                    <!-- Service Filter (Configuration tab) -->
                    <?php if ($activeTab === 'configuration'): ?>
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
                    <div class="col-12 col-sm-6 <?= $activeTab === 'configuration' ? 'col-md-2 col-xl-2' : 'col-md-3 col-xl-3' ?>">
                        <label class="form-label text-muted small mb-1">From Date</label>
                        <input type="date" class="form-control form-control-sm dark-input" name="date_from" value="<?= e($dateFrom) ?>">
                    </div>

                    <!-- Date To -->
                    <div class="col-12 col-sm-6 <?= $activeTab === 'configuration' ? 'col-md-2 col-xl-2' : 'col-md-3 col-xl-3' ?>">
                        <label class="form-label text-muted small mb-1">To Date</label>
                        <input type="date" class="form-control form-control-sm dark-input" name="date_to" value="<?= e($dateTo) ?>">
                    </div>

                    <!-- Action Buttons -->
                    <div class="col-12 col-sm-6 <?= $activeTab === 'configuration' ? 'col-md-1 col-xl-2' : 'col-md-1 col-xl-1' ?> d-flex gap-2">
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

            <!-- Maintenance / Configuration Records Panel -->
            <div class="tx-table-card archive-table-card mb-4" style="display: flex; flex-direction: column; min-height: 944px;">
                <div class="panel-header d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                    <div>
                        <h5 class="panel-title mb-1 d-flex align-items-center">
                            <?php if ($activeTab === 'maintenance'): ?>
                                <?= lucide_icon('wrench', 'me-2 text-primary-vl') ?>Maintenance History
                            <?php else: ?>
                                <?= lucide_icon('sliders', 'me-2 text-primary-vl') ?>Configuration History
                            <?php endif; ?>
                        </h5>
                        <span class="text-muted small">
                            Showing <?= number_format(count($records)) ?> of <?= number_format($totalItems) ?> records
                        </span>
                    </div>
                </div>

                <div class="table-responsive" style="flex: 1 0 auto; display: flex; flex-direction: column;">
                    <?php if (empty($records)): ?>
                    <div class="activity-empty py-5 text-center d-flex flex-column align-items-center justify-content-center" style="flex: 1 0 auto; min-height: 400px;">
                        <?= lucide_icon('search', 'mb-3 opacity-50', ['style' => 'color: #64748B; width: 44px; height: 44px;']) ?>
                        <h6 class="text-white fw-bold mb-1">No records match your filters</h6>
                        <p class="text-muted small mb-3">Try adjusting your search criteria or date ranges.</p>
                        <?php if (!empty($search) || $service !== 'all' || !empty($dateFrom) || !empty($dateTo)): ?>
                        <a href="archive.php?tab=<?= e($activeTab) ?>" class="btn btn-sm btn-outline-secondary">
                            <?= lucide_icon('rotate-ccw', 'me-1') ?>Reset Filters
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>

                    <!-- SECTION 2: MAINTENANCE HISTORY -->
                    <?php if ($activeTab === 'maintenance'): ?>
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
                                    <div class="text-light fw-bold d-inline-flex align-items-center">
                                        <?= lucide_icon('wrench', 'me-1 text-primary-vl') ?>
                                        <?= e($mh['maintenance_name']) ?>
                                    </div>
                                    <div class="tx-snapshot-tag">
                                        Type: <?= ucfirst(e($mh['reminder_type'])) ?> Reminder
                                    </div>
                                </td>
                                <td>
                                    <div class="text-light fw-medium">
                                        <?= e(preg_replace('/^VendoLink\s+/i', '', $mh['machine_name'] ?: 'Machine 01')) ?>
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
                                    <span class="badge bg-secondary-subtle text-light border border-secondary font-monospace d-inline-flex align-items-center">
                                        <?= lucide_icon('clock', 'me-1') ?><?= e($mh['runtime_label']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="text-muted">
                                        <?= e($mh['notes'] ?: '—') ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="text-light fw-medium d-inline-flex align-items-center">
                                        <?= lucide_icon('user', 'me-1 text-muted') ?>
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
                        </tbody>
                    </table>

                    <!-- SECTION 3: CONFIGURATION HISTORY -->
                    <?php else: ?>
                    <table class="table table-dark table-hover align-middle mb-0" style="--bs-table-bg: transparent;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--vl-border); color: var(--vl-text-muted); font-size: .78rem; text-transform: uppercase; letter-spacing: .06em;">
                                <th class="ps-4">Service</th>
                                <th>Previous Configuration</th>
                                <th>New Configuration</th>
                                <?php if (is_admin()): ?>
                                <th>Changed By</th>
                                <?php endif; ?>
                                <th>Date &amp; Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $ch):
                                $serviceIcon = match ($ch['service_key'] ?? '') {
                                    'water' => 'droplets',
                                    'soap'  => 'sparkles',
                                    default => 'cog',
                                };
                                $serviceColor = match ($ch['service_key'] ?? '') {
                                    'water' => 'text-info',
                                    'soap'  => 'text-warning',
                                    default => 'text-primary',
                                };
                            ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,.04); font-size: .88rem;">
                                <td class="ps-4">
                                    <span class="d-inline-flex align-items-center gap-1">
                                        <?= lucide_icon($serviceIcon, $serviceColor) ?>
                                        <strong class="text-light"><?= e($ch['service_name']) ?></strong>
                                    </span>
                                    <div class="tx-snapshot-tag">
                                        <?= e(preg_replace('/^VendoLink\s+/i', '', $ch['machine_name'] ?: 'Machine 01')) ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-danger-emphasis fw-medium d-inline-flex align-items-center">
                                        <?= lucide_icon('minus-circle', 'me-1') ?><?= e($ch['previous_label']) ?>
                                    </div>
                                    <div class="tx-snapshot-tag">
                                        State: <?= $ch['previous_is_enabled'] ? 'Enabled' : 'Disabled' ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-success fw-medium d-inline-flex align-items-center">
                                        <?= lucide_icon('arrow-right-circle', 'me-1') ?><?= e($ch['new_label']) ?>
                                    </div>
                                    <div class="tx-snapshot-tag">
                                        State: <?= $ch['new_is_enabled'] ? 'Enabled' : 'Disabled' ?>
                                    </div>
                                </td>
                                <?php if (is_admin()): ?>
                                <td>
                                    <div class="text-light fw-medium d-inline-flex align-items-center">
                                        <?= lucide_icon('user', 'me-1 text-muted') ?>
                                        <?= e($ch['changed_by_name'] ?: 'Administrator') ?>
                                    </div>
                                    <?php if (!empty($ch['changed_by_role'])): ?>
                                    <div class="tx-snapshot-tag">
                                        Role: <?= ucfirst(e($ch['changed_by_role'])) ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
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
                        </tbody>
                    </table>
                    <?php endif; ?>

                    <!-- Reserved Space Indicator (Shown when current page has fewer than 15 records) -->
                    <?php if (count($records) < 15): ?>
                    <div class="table-reserved-space text-center py-4 d-flex flex-column align-items-center justify-content-center" style="min-height: <?= max(100, (15 - count($records)) * 44) ?>px;">
                        <?= lucide_icon('layers', 'layers-icon mb-2') ?>
                        <div class="text-slate-400 fw-medium small">Reserved space for the remaining rows</div>
                        <div class="small" style="color: #64748B;">No placeholder rows are displayed.</div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Pagination Controls -->
                <div class="tx-pagination" style="margin-top: auto;">
                    <div class="text-muted small">
                        <?php if ($totalItems > 0): ?>
                            Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong> (Total <?= number_format($totalItems) ?> records)
                        <?php else: ?>
                            0 records found
                        <?php endif; ?>
                    </div>
                    <nav aria-label="Archive pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <!-- Previous -->
                            <li class="page-item <?= ($page <= 1 || $totalItems === 0) ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= build_archive_url(['page' => $page - 1]) ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;&nbsp;Prev</span>
                                </a>
                            </li>

                            <!-- Page Numbers -->
                            <?php
                            if ($totalItems > 0):
                                $startPage = max(1, $page - 2);
                                $endPage   = min($totalPages, $page + 2);

                                if ($startPage > 1) {
                                    echo '<li class="page-item"><a class="page-link" href="' . build_archive_url(['page' => 1]) . '">1</a></li>';
                                    if ($startPage > 2) {
                                        echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
                                    }
                                }

                                for ($p = $startPage; $p <= $endPage; $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= build_archive_url(['page' => $p]) ?>"><?= $p ?></a>
                                </li>
                                <?php endfor;

                                if ($endPage < $totalPages) {
                                    if ($endPage < $totalPages - 1) {
                                        echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
                                    }
                                    echo '<li class="page-item"><a class="page-link" href="' . build_archive_url(['page' => $totalPages]) . '">' . $totalPages . '</a></li>';
                                }
                            endif;
                            ?>

                            <!-- Next -->
                            <li class="page-item <?= ($page >= $totalPages || $totalItems === 0) ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= build_archive_url(['page' => $page + 1]) ?>" aria-label="Next">
                                    <span aria-hidden="true">Next&nbsp;&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>

            </div>

            <?php endif; ?>

        </main>

        <!-- RECORD CASH COLLECTION MODAL -->
        <div class="modal fade" id="recordCashModal" tabindex="-1" aria-labelledby="recordCashModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content text-light border-secondary border-opacity-25 shadow-2xl" style="background:#0f172a; border-color:#1e293b; border-radius: 1rem;">
                    <div class="modal-header border-secondary border-opacity-25 pb-3">
                        <div class="d-flex align-items-center gap-2">
                            <div class="rounded-2 p-1.5 d-flex align-items-center justify-content-center text-success" style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3);">
                                <i class="bi bi-wallet2 fs-5"></i>
                            </div>
                            <div>
                                <h5 class="modal-title fw-bold text-white fs-6 mb-0" id="recordCashModalLabel">Record Cash Collection</h5>
                                <div class="text-secondary small" style="font-size: .75rem;">Save the cash you took out of the machine coin box</div>
                            </div>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST" action="archive.php?tab=transactions&section=collections" id="recordCashCollectionForm">
                        <div class="modal-body p-3 p-sm-4">
                            <input type="hidden" name="action" value="record_cash_collection">
                            <?= csrf_field() ?>

                            <!-- Real-Time Comparison Cards -->
                            <div class="row g-2 p-2 mb-3 rounded-3" style="background: rgba(15, 23, 42, 0.85); border: 1px solid rgba(255, 255, 255, 0.08);">
                                <div class="col-4 text-center border-end border-secondary border-opacity-25 pe-2">
                                    <span class="text-secondary small text-uppercase fw-semibold d-block" style="font-size: .68rem; letter-spacing: .05em;">Money in Machine</span>
                                    <span id="modalStatExpected" class="font-monospace fw-bold fs-6 text-info">₱0.00</span>
                                    <span class="text-muted d-block" style="font-size: .65rem;">from sales</span>
                                </div>
                                <div class="col-4 text-center border-end border-secondary border-opacity-25 pe-2">
                                    <span class="text-secondary small text-uppercase fw-semibold d-block" style="font-size: .68rem; letter-spacing: .05em;">Cash Collected</span>
                                    <span id="modalStatEntered" class="font-monospace fw-bold fs-6 text-white">₱0.00</span>
                                    <span class="text-muted d-block" style="font-size: .65rem;">your count</span>
                                </div>
                                <div class="col-4 text-center ps-2">
                                    <span class="text-secondary small text-uppercase fw-semibold d-block" style="font-size: .68rem; letter-spacing: .05em;">Difference</span>
                                    <span id="modalStatDiff" class="font-monospace fw-bold fs-6 text-success">₱0.00</span>
                                    <span id="modalStatDiffSub" class="small text-success d-block" style="font-size: .65rem;">waiting for count</span>
                                </div>
                            </div>

                            <!-- Dynamic Live Message Banner -->
                            <div id="modalValidationBanner" class="alert alert-info py-2 px-3 small mb-3 d-flex align-items-center gap-2 border-0" style="background: rgba(6, 182, 212, 0.12); color: #22d3ee; border-radius: .6rem;">
                                <i id="modalBannerIcon" class="bi bi-info-circle-fill text-info flex-shrink-0"></i>
                                <span id="modalBannerText">Enter the cash you collected to compare against the machine.</span>
                            </div>

                            <!-- Machine selection if multiple machines available -->
                            <?php if (count($allMachines) > 1): ?>
                            <div class="mb-3">
                                <label class="form-label text-secondary small fw-bold text-uppercase mb-1" style="font-size: .75rem;">Machine</label>
                                <select name="machine_id" id="modalMachineSelect" class="form-select dark-input text-white" required>
                                    <?php foreach ($allMachines as $m): ?>
                                        <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === $activeMachineId ? 'selected' : '' ?>>
                                            <?= e($m['name']) ?> (<?= e($m['machine_code'] ?: 'Unit') ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php else: ?>
                                <input type="hidden" name="machine_id" id="modalMachineHidden" value="<?= $activeMachineId ?>">
                            <?php endif; ?>

                            <!-- Cash Amount Field -->
                            <div class="mb-3">
                                <label class="form-label text-secondary small fw-bold text-uppercase mb-1" style="font-size: .75rem;">Cash You Collected (₱)</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-secondary bg-opacity-20 text-light border-secondary border-opacity-25 fw-bold">₱</span>
                                    <input type="number" step="0.50" min="0.50" name="collected_amount" id="modalCashInput" class="form-control bg-dark text-light border-secondary border-opacity-25 fw-bold fs-5" placeholder="0.00" required autofocus autocomplete="off">
                                </div>
                            </div>

                            <!-- Notes Field -->
                            <div class="mb-3">
                                <label class="form-label text-secondary small fw-bold text-uppercase mb-1" style="font-size: .75rem;">
                                    Notes / Reason <span id="modalNotesRequired" class="text-warning text-lowercase fw-normal d-none">(required because amount is short)</span>
                                </label>
                                <textarea name="notes" id="modalNotesInput" class="form-control bg-dark text-light border-secondary border-opacity-25 small" rows="2" placeholder="e.g. Left ₱100 in coins inside the machine for change."></textarea>
                            </div>

                            <!-- Authorized Override Container (shown when excess occurs) -->
                            <div id="modalOverridePanel" class="d-none p-3 mb-3 rounded-3 border border-warning border-opacity-40" style="background: rgba(245, 158, 11, 0.08);">
                                <div class="form-check d-flex align-items-center gap-2 mb-0">
                                    <input class="form-check-input" type="checkbox" name="is_override" value="1" id="modalOverrideCheckbox" style="margin-top: 0; cursor: pointer;">
                                    <label class="form-check-label fw-bold text-warning small cursor-pointer" for="modalOverrideCheckbox">
                                        Allow Extra Cash (Override)
                                    </label>
                                    <span class="badge bg-warning bg-opacity-20 text-warning ms-auto small border border-warning border-opacity-30">
                                        <i class="bi bi-shield-check me-1"></i>Authorized
                                    </span>
                                </div>
                                <div id="modalOverrideDetails" class="d-none mt-2 pt-2 border-top border-warning border-opacity-25">
                                    <label class="form-label text-warning small fw-bold mb-1" style="font-size: .75rem;">WHY IS THERE EXTRA CASH?</label>
                                    <textarea name="override_reason" id="modalOverrideReason" class="form-control bg-dark text-light border-warning border-opacity-50 small" rows="2" placeholder="e.g. Added coins manually for testing or customer coin adjustment..."></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer border-secondary border-opacity-25 pt-2 pb-3">
                            <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" id="modalSaveBtn" class="btn btn-success fw-bold px-4 d-inline-flex align-items-center gap-1.5 shadow-sm" disabled>
                                <i class="bi bi-check-circle-fill"></i>
                                <span>Save Collection</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Cash Collection Reconciliation Logic -->
        <script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('recordCashModal');
    if (!modal) return;

    const machineSelect = modal.querySelector('select[name="machine_id"]');
    const machineHidden = modal.querySelector('input[type="hidden"][name="machine_id"]');
    const cashInput = document.getElementById('modalCashInput');
    const notesInput = document.getElementById('modalNotesInput');
    const notesRequired = document.getElementById('modalNotesRequired');
    const saveBtn = document.getElementById('modalSaveBtn');
    const banner = document.getElementById('modalValidationBanner');
    const bannerIcon = document.getElementById('modalBannerIcon');
    const bannerText = document.getElementById('modalBannerText');

    const statExpected = document.getElementById('modalStatExpected');
    const statEntered = document.getElementById('modalStatEntered');
    const statDiff = document.getElementById('modalStatDiff');
    const statDiffSub = document.getElementById('modalStatDiffSub');

    const overridePanel = document.getElementById('modalOverridePanel');
    const overrideCheckbox = document.getElementById('modalOverrideCheckbox');
    const overrideDetails = document.getElementById('modalOverrideDetails');
    const overrideReason = document.getElementById('modalOverrideReason');
    const form = document.getElementById('recordCashCollectionForm');

    let currentExpected = 0.00;
    let canOverride = false;
    let isFetchingBalance = false;

    function getSelectedMachineId() {
        if (machineSelect) return parseInt(machineSelect.value, 10) || 0;
        if (machineHidden) return parseInt(machineHidden.value, 10) || 0;
        return 0;
    }

    function fetchBalance() {
        const mid = getSelectedMachineId();
        if (mid <= 0) return;
        isFetchingBalance = true;
        statExpected.textContent = '...';

        fetch('<?= BASE_URL ?>/api/cash_collection_balance.php?machine_id=' + mid)
            .then(res => res.json())
            .then(data => {
                isFetchingBalance = false;
                if (data.success) {
                    currentExpected = parseFloat(data.expected_balance) || 0;
                    statExpected.textContent = '₱' + currentExpected.toFixed(2);
                    canOverride = !!data.can_override;
                } else {
                    currentExpected = 0;
                    statExpected.textContent = '₱0.00';
                }
                evaluate();
            })
            .catch(() => {
                isFetchingBalance = false;
                statExpected.textContent = '₱' + currentExpected.toFixed(2);
                evaluate();
            });
    }

    function evaluate() {
        const enteredVal = parseFloat(cashInput.value);
        if (isNaN(enteredVal) || enteredVal <= 0) {
            statEntered.textContent = '₱0.00';
            statDiff.textContent = '₱0.00';
            statDiff.className = 'font-monospace fw-bold fs-6 text-slate-400';
            statDiffSub.textContent = 'waiting for count';
            statDiffSub.className = 'small text-muted d-block';
            banner.className = 'alert alert-info py-2 px-3 small mb-3 d-flex align-items-center gap-2 border-0';
            banner.style.background = 'rgba(6, 182, 212, 0.12)';
            banner.style.color = '#22d3ee';
            bannerIcon.className = 'bi bi-info-circle-fill text-info flex-shrink-0';
            bannerText.textContent = 'Enter the cash you collected to compare against the machine.';
            overridePanel.classList.add('d-none');
            notesRequired.classList.add('d-none');
            saveBtn.disabled = true;
            return;
        }

        statEntered.textContent = '₱' + enteredVal.toFixed(2);
        const diff = enteredVal - currentExpected;
        const absDiff = Math.abs(diff).toFixed(2);

        // Case D: Zero balance
        if (currentExpected <= 0) {
            statDiff.textContent = (diff > 0 ? '+₱' : '₱') + diff.toFixed(2);
            statDiff.className = 'font-monospace fw-bold fs-6 text-danger';
            statDiffSub.textContent = 'no cash in machine';
            statDiffSub.className = 'small text-danger d-block';
            banner.className = 'alert alert-danger py-2 px-3 small mb-3 d-flex align-items-center gap-2 border-0';
            banner.style.background = 'rgba(239, 68, 68, 0.15)';
            banner.style.color = '#f87171';
            bannerIcon.className = 'bi bi-slash-circle-fill text-danger flex-shrink-0';
            bannerText.textContent = 'This machine currently has ₱0.00 uncollected cash.';
            notesRequired.classList.add('d-none');

            if (canOverride) {
                overridePanel.classList.remove('d-none');
            } else {
                overridePanel.classList.add('d-none');
            }
        }
        // Case A: Exact match
        else if (Math.abs(diff) < 0.001) {
            statDiff.textContent = '₱0.00';
            statDiff.className = 'font-monospace fw-bold fs-6 text-success';
            statDiffSub.textContent = 'exact match';
            statDiffSub.className = 'small text-success d-block';
            banner.className = 'alert alert-success py-2 px-3 small mb-3 d-flex align-items-center gap-2 border-0';
            banner.style.background = 'rgba(16, 185, 129, 0.15)';
            banner.style.color = '#34d399';
            bannerIcon.className = 'bi bi-check-circle-fill text-success flex-shrink-0';
            bannerText.textContent = 'Exact match! The cash you entered matches the machine.';
            overridePanel.classList.add('d-none');
            notesRequired.classList.add('d-none');
        }
        // Case B: Shortage
        else if (diff < -0.001) {
            statDiff.textContent = '-₱' + absDiff;
            statDiff.className = 'font-monospace fw-bold fs-6 text-warning';
            statDiffSub.textContent = 'short by ₱' + absDiff;
            statDiffSub.className = 'small text-warning d-block';
            banner.className = 'alert alert-warning py-2 px-3 small mb-3 d-flex align-items-center gap-2 border-0';
            banner.style.background = 'rgba(245, 158, 11, 0.15)';
            banner.style.color = '#fbbf24';
            bannerIcon.className = 'bi bi-exclamation-triangle-fill text-warning flex-shrink-0';
            bannerText.textContent = 'Short by ₱' + absDiff + '. Please write a quick reason below before saving.';
            overridePanel.classList.add('d-none');
            notesRequired.classList.remove('d-none');
        }
        // Case C: Excess
        else if (diff > 0.001) {
            statDiff.textContent = '+₱' + absDiff;
            statDiff.className = 'font-monospace fw-bold fs-6 text-danger';
            statDiffSub.textContent = 'extra ₱' + absDiff;
            statDiffSub.className = 'small text-danger d-block';
            banner.className = 'alert alert-danger py-2 px-3 small mb-3 d-flex align-items-center gap-2 border-0';
            banner.style.background = 'rgba(239, 68, 68, 0.15)';
            banner.style.color = '#f87171';
            bannerIcon.className = 'bi bi-shield-x text-danger flex-shrink-0';
            bannerText.textContent = 'Too much entered! This is ₱' + absDiff + ' more than the machine made. Please double-check.';
            notesRequired.classList.add('d-none');

            if (canOverride) {
                overridePanel.classList.remove('d-none');
            } else {
                overridePanel.classList.add('d-none');
            }
        }

        checkValidity(diff);
    }

    function checkValidity(diff) {
        const val = parseFloat(cashInput.value);
        const notes = notesInput ? notesInput.value.trim() : '';
        const chk = overrideCheckbox ? overrideCheckbox.checked : false;
        const reason = overrideReason ? overrideReason.value.trim() : '';

        if (isNaN(val) || val <= 0) {
            saveBtn.disabled = true;
            return;
        }

        if (currentExpected <= 0) {
            saveBtn.disabled = !(canOverride && chk && reason.length >= 5);
            return;
        }

        if (Math.abs(diff) < 0.001) {
            saveBtn.disabled = false;
            return;
        }

        if (diff < -0.001) {
            saveBtn.disabled = (notes.length === 0);
            return;
        }

        if (diff > 0.001) {
            saveBtn.disabled = !(canOverride && chk && reason.length >= 5);
            return;
        }
    }

    if (machineSelect) {
        machineSelect.addEventListener('change', fetchBalance);
    }
    cashInput.addEventListener('input', evaluate);
    if (notesInput) {
        notesInput.addEventListener('input', () => {
            const val = parseFloat(cashInput.value);
            const diff = !isNaN(val) ? val - currentExpected : 0;
            checkValidity(diff);
        });
    }
    if (overrideCheckbox) {
        overrideCheckbox.addEventListener('change', function () {
            if (overrideCheckbox.checked) {
                overrideDetails.classList.remove('d-none');
            } else {
                overrideDetails.classList.add('d-none');
            }
            const val = parseFloat(cashInput.value);
            const diff = !isNaN(val) ? val - currentExpected : 0;
            checkValidity(diff);
        });
    }
    if (overrideReason) {
        overrideReason.addEventListener('input', () => {
            const val = parseFloat(cashInput.value);
            const diff = !isNaN(val) ? val - currentExpected : 0;
            checkValidity(diff);
        });
    }

    modal.addEventListener('shown.bs.modal', function () {
        fetchBalance();
        cashInput.focus();
    });

    if (form) {
        form.addEventListener('submit', function () {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Saving...';
        });
    }
});
</script>

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
