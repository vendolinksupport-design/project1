<?php
/**
 * ============================================================
 * VendoLink — Dedicated Cash Collections Page
 * ============================================================
 *
 * Dedicated collection and reconciliation interface under Sales Overview:
 * - Physical coin collection recording
 * - Automated variance calculation against system sales
 * - Standardized reconciliation states (Matched, Above, Below)
 * - Machine & reconciliation result filtering
 * - Strict 15-record pagination with bottom-anchored controls
 * - Full-dataset Excel export
 *
 * @package VendoLink
 * @version 2.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Machine.php';

// ---- Role Guard ----
require_role([ROLE_ADMIN, ROLE_OWNER]);

$ownerId        = current_owner_id();
$machineModel   = new Machine();
$primaryMachine = $machineModel->primaryWithServices($ownerId);
$activeMachineId= current_selected_machine_id($ownerId) ?: (int)($primaryMachine['id'] ?? 0);
$allMachines    = $machineModel->getAll($ownerId);

// ---- Handle POST Actions: Record Cash Collection ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'record_cash_collection') {
        $collectedAmount = (float)($_POST['collected_amount'] ?? 0);
        $notes           = trim($_POST['notes'] ?? '');
        $userId          = (int)(current_user()['id'] ?? 0);
        $machineId       = (int)($_POST['machine_id'] ?? $activeMachineId);

        if ($machineId <= 0 && !empty($allMachines)) {
            $machineId = (int)$allMachines[0]['id'];
        }

        if ($machineId <= 0) {
            flash('danger', "No active machine found. Please ensure hardware is assigned before recording collections.");
            redirect('dashboard/cash_collections.php');
        } elseif ($collectedAmount > 0) {
            $txModel = new Transaction();
            $txModel->recordCashCollection($userId, $machineId, $collectedAmount, $notes);
            flash('success', "Physical cash collection of ₱" . number_format($collectedAmount, 2) . " recorded successfully!");
            log_activity('CASH_COLLECTED', "Collected ₱" . number_format($collectedAmount, 2) . " from physical coin box.");
            redirect('dashboard/cash_collections.php');
        } else {
            flash('danger', "Please enter a valid cash collection amount.");
            redirect('dashboard/cash_collections.php');
        }
    }
}

$transactionModel = new Transaction();

// ---- Filters ----
$search        = trim($_GET['search'] ?? '');
$machineFilter = trim((string)($_GET['machine'] ?? 'all'));
$reconFilter   = strtolower(trim((string)($_GET['reconciliation'] ?? 'all')));
if (!in_array($reconFilter, ['all', 'matched', 'above', 'below'], true)) {
    $reconFilter = 'all';
}
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 15; // Strictly 15 records per page

$ccFilters = [
    'search'         => $search,
    'machine'        => $machineFilter,
    'reconciliation' => $reconFilter,
    'date_from'      => $dateFrom,
    'date_to'        => $dateTo,
];

// ---- Cash Collections Excel Export ----
if (isset($_GET['export']) && in_array(strtolower(trim($_GET['export'])), ['excel', 'csv', 'cash_collections'], true)) {
    $transactionModel->exportCashCollectionsCsv($ccFilters, $ownerId);
    exit;
}

// ---- Query Collections Dataset ----
$targetMachineId = ($machineFilter !== 'all' && $machineFilter !== '') ? (int)$machineFilter : 0;
$allCollections  = $transactionModel->getCashCollections($targetMachineId, $ownerId, $ccFilters);
$totalRecords    = count($allCollections);
$totalPages      = max(1, (int)ceil($totalRecords / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}
$offset          = ($page - 1) * $perPage;
$pagedRecords    = array_slice($allCollections, $offset, $perPage);
$currentPageCount= count($pagedRecords);

// Check if any filter is active
$hasActiveFilters = !empty($search) || ($machineFilter !== 'all' && $machineFilter !== '') || ($reconFilter !== 'all') || !empty($dateFrom) || !empty($dateTo);

// Total records in DB without filters
$dbTotalCollections = count($transactionModel->getCashCollections(0, $ownerId, []));

$pageTitle   = 'Cash Collections';
$currentPage = 'cash_collections';
$user        = current_user();

// Helper to build URL preserving filters
if (!function_exists('cc_url')) {
    function cc_url(array $overrides = []): string {
        global $search, $machineFilter, $reconFilter, $dateFrom, $dateTo, $page;
        $params = [
            'search'         => $search,
            'machine'        => $machineFilter,
            'reconciliation' => $reconFilter,
            'date_from'      => $dateFrom,
            'date_to'        => $dateTo,
            'page'           => $page,
        ];
        $merged = array_merge($params, $overrides);
        return 'cash_collections.php?' . http_build_query($merged);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= APP_NAME ?> Cash Collections &amp; Reconciliation — <?= APP_TAGLINE ?>">
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
                    <h1 class="page-title text-white d-flex align-items-center">
                        <?= lucide_icon('wallet', 'me-2 text-primary-vl') ?>Cash Collections
                    </h1>
                    <p class="page-subtitle text-light">
                        Record physical coin collections, reconcile counted cash with expected system sales, and track variances.
                    </p>
                </div>
                <div class="page-header-actions d-flex align-items-center gap-2 flex-wrap">
                    <button type="button" class="btn btn-sm btn-primary fw-semibold d-inline-flex align-items-center" data-bs-toggle="modal" data-bs-target="#recordCashModal">
                        <?= lucide_icon('plus-circle', 'me-1.5') ?>Record Collection
                    </button>
                    <a href="<?= cc_url(['export' => 'excel']) ?>" class="btn btn-sm btn-outline-success fw-semibold d-inline-flex align-items-center">
                        <?= lucide_icon('download', 'me-1.5') ?>Export Excel
                    </a>
                </div>
            </div>

            <!-- Search & Filters Card -->
            <div class="tx-filter-card mb-4">
                <form method="get" action="cash_collections.php" class="row g-3 align-items-end" id="cashCollectionsFilterForm">
                    <input type="hidden" name="page" value="1">

                    <!-- Search Input -->
                    <div class="col-12 col-md-4 col-xl-4">
                        <label class="form-label text-muted small mb-1 fw-medium">Search</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text dark-input" style="border-right: 0;">
                                <?= lucide_icon('search', 'text-muted') ?>
                            </span>
                            <input type="text" class="form-control dark-input" name="search"
                                   placeholder="Search machine, notes, or recorded by..."
                                   value="<?= e($search) ?>" style="border-left: 0;">
                        </div>
                    </div>

                    <!-- Machine Filter -->
                    <div class="col-6 col-sm-4 col-md-3 col-xl-2">
                        <label class="form-label text-muted small mb-1 fw-medium">Machine</label>
                        <select class="form-select form-select-sm dark-input text-white" name="machine">
                            <option value="all" <?= $machineFilter === 'all' ? 'selected' : '' ?>>All Machines</option>
                            <?php foreach ($allMachines as $m): ?>
                                <option value="<?= (int)$m['id'] ?>" <?= $machineFilter === (string)$m['id'] ? 'selected' : '' ?>>
                                    <?= e($m['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Reconciliation Result Filter -->
                    <div class="col-6 col-sm-4 col-md-3 col-xl-2">
                        <label class="form-label text-muted small mb-1 fw-medium">Reconciliation Result</label>
                        <select class="form-select form-select-sm dark-input text-white" name="reconciliation">
                            <option value="all" <?= $reconFilter === 'all' ? 'selected' : '' ?>>All Results</option>
                            <option value="matched" <?= $reconFilter === 'matched' ? 'selected' : '' ?>>Matched</option>
                            <option value="above" <?= $reconFilter === 'above' ? 'selected' : '' ?>>Above</option>
                            <option value="below" <?= $reconFilter === 'below' ? 'selected' : '' ?>>Below</option>
                        </select>
                    </div>

                    <!-- From Date -->
                    <div class="col-6 col-sm-4 col-md-3 col-xl-2">
                        <label class="form-label text-muted small mb-1 fw-medium">From Date</label>
                        <input type="date" class="form-control form-control-sm dark-input text-white" name="date_from" value="<?= e($dateFrom) ?>">
                    </div>

                    <!-- To Date -->
                    <div class="col-6 col-sm-4 col-md-3 col-xl-2">
                        <label class="form-label text-muted small mb-1 fw-medium">To Date</label>
                        <input type="date" class="form-control form-control-sm dark-input text-white" name="date_to" value="<?= e($dateTo) ?>">
                    </div>

                    <!-- Filter Actions -->
                    <div class="col-12 col-md-auto ms-auto d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary d-inline-flex align-items-center">
                            <?= lucide_icon('filter', 'me-1') ?>Apply Filters
                        </button>
                        <?php if ($hasActiveFilters): ?>
                            <a href="cash_collections.php" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center">
                                <?= lucide_icon('rotate-ccw', 'me-1') ?>Reset
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Active Filters Badges -->
            <?php if ($hasActiveFilters): ?>
            <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                <span class="text-muted small fw-medium">Active Filters:</span>
                <?php if (!empty($search)): ?>
                    <span class="badge bg-secondary bg-opacity-25 text-light border border-secondary border-opacity-25 d-inline-flex align-items-center py-1 px-2.5">
                        <?= lucide_icon('search', 'me-1') ?>"<?= e($search) ?>"
                        <a href="<?= cc_url(['search' => '', 'page' => 1]) ?>" class="ms-1.5 text-muted hover-text-white text-decoration-none">&times;</a>
                    </span>
                <?php endif; ?>
                <?php if ($machineFilter !== 'all' && $machineFilter !== ''): 
                    $mName = 'Machine #' . $machineFilter;
                    foreach ($allMachines as $am) {
                        if ((string)$am['id'] === $machineFilter) {
                            $mName = $am['name'];
                            break;
                        }
                    }
                ?>
                    <span class="badge bg-secondary bg-opacity-25 text-info border border-info border-opacity-25 d-inline-flex align-items-center py-1 px-2.5">
                        <?= lucide_icon('cpu', 'me-1') ?><?= e($mName) ?>
                        <a href="<?= cc_url(['machine' => 'all', 'page' => 1]) ?>" class="ms-1.5 text-muted hover-text-white text-decoration-none">&times;</a>
                    </span>
                <?php endif; ?>
                <?php if ($reconFilter !== 'all'): ?>
                    <span class="badge bg-secondary bg-opacity-25 text-warning border border-warning border-opacity-25 d-inline-flex align-items-center py-1 px-2.5">
                        <?= lucide_icon('check-circle', 'me-1') ?><?= ucfirst($reconFilter) ?>
                        <a href="<?= cc_url(['reconciliation' => 'all', 'page' => 1]) ?>" class="ms-1.5 text-muted hover-text-white text-decoration-none">&times;</a>
                    </span>
                <?php endif; ?>
                <?php if (!empty($dateFrom) || !empty($dateTo)): ?>
                    <span class="badge bg-secondary bg-opacity-25 text-light border border-secondary border-opacity-25 d-inline-flex align-items-center py-1 px-2.5">
                        <?= lucide_icon('calendar', 'me-1') ?><?= e($dateFrom ?: 'Beginning') ?> &rarr; <?= e($dateTo ?: 'Now') ?>
                        <a href="<?= cc_url(['date_from' => '', 'date_to' => '', 'page' => 1]) ?>" class="ms-1.5 text-muted hover-text-white text-decoration-none">&times;</a>
                    </span>
                <?php endif; ?>
                <a href="cash_collections.php" class="text-muted small ms-1 text-decoration-underline">Clear all</a>
            </div>
            <?php endif; ?>

            <!-- ============================================================
                 COLLECTIONS TABLE PANEL (Fixed Height & Anchored Pagination)
                 ============================================================ -->
            <div class="tx-table-card archive-table-card mb-4" id="cashCollectionsPanel" style="display: flex; flex-direction: column; min-height: 944px;">
                <div class="panel-header d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3 py-3 px-3">
                    <h5 class="panel-title text-white d-flex align-items-center mb-0">
                        <?= lucide_icon('wallet', 'me-2 text-primary-vl') ?>Collection Records
                    </h5>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-3 py-1.5 fw-semibold">
                            Showing <?= number_format($currentPageCount) ?> of <?= number_format($totalRecords) ?> records
                        </span>
                    </div>
                </div>

                <div class="table-responsive" style="flex: 1 0 auto; display: flex; flex-direction: column;">
                    <?php if (empty($allCollections)): ?>
                        <?php if ($hasActiveFilters): ?>
                            <!-- Search/filter returned no matches -->
                            <div class="activity-empty py-5 text-center d-flex flex-column align-items-center justify-content-center" style="flex: 1 0 auto; min-height: 400px;">
                                <?= lucide_icon('search', 'mb-3 opacity-50', ['style' => 'color: #64748B; width: 44px; height: 44px;']) ?>
                                <h6 class="text-white fw-bold mb-1">No records match your filters</h6>
                                <p class="text-muted small mb-3">Try adjusting your search criteria or date ranges.</p>
                                <a href="cash_collections.php" class="btn btn-sm btn-outline-secondary">
                                    <?= lucide_icon('rotate-ccw', 'me-1') ?>Reset Filters
                                </a>
                            </div>
                        <?php else: ?>
                            <!-- Genuinely empty dataset -->
                            <div class="activity-empty py-5 text-center d-flex flex-column align-items-center justify-content-center" style="flex: 1 0 auto; min-height: 400px;">
                                <?= lucide_icon('wallet', 'mb-3 opacity-50', ['style' => 'color: #64748B; width: 44px; height: 44px;']) ?>
                                <h6 class="text-white fw-bold mb-1">No physical cash collections recorded yet</h6>
                                <p class="text-muted small mb-3">Click &ldquo;Record Collection&rdquo; above when clearing the physical coin box.</p>
                                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#recordCashModal">
                                    <?= lucide_icon('plus-circle', 'me-1') ?>Record Collection
                                </button>
                            </div>
                        <?php endif; ?>
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
                                <?php foreach ($pagedRecords as $cc):
                                    $var = (float)($cc['variance_amount'] ?? 0);
                                    $machineDisplay = preg_replace('/^VendoLink\s+/i', '', $cc['machine_name'] ?: 'Machine 01');

                                    if ($var == 0) {
                                        $reconBadgeClass = 'badge-matched';
                                        $reconLabel      = 'Matched';
                                        $reconIcon       = 'check-circle';
                                    } elseif ($var > 0) {
                                        $reconBadgeClass = 'badge-above';
                                        $reconLabel      = 'Above';
                                        $reconIcon       = 'arrow-up-right';
                                    } else {
                                        $reconBadgeClass = 'badge-below';
                                        $reconLabel      = 'Below';
                                        $reconIcon       = 'arrow-down-right';
                                    }
                                ?>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,.05); font-size: .88rem;">
                                    <!-- Collection Date -->
                                    <td class="ps-4">
                                        <div class="text-white fw-medium">
                                            <?= format_date($cc['collected_at'], 'M d, Y, g:i A') ?>
                                        </div>
                                        <div class="text-muted small">
                                            <?= time_ago($cc['collected_at']) ?>
                                        </div>
                                    </td>

                                    <!-- Machine -->
                                    <td>
                                        <div class="text-white fw-medium">
                                            <?= e($machineDisplay) ?>
                                        </div>
                                        <div class="text-muted small">
                                            <?= e(($cc['machine_location'] ?? '') ?: 'Main Unit') ?>
                                        </div>
                                    </td>

                                    <!-- Recorded By (Admin only) -->
                                    <?php if (is_admin()): ?>
                                    <td>
                                        <div class="text-white small fw-medium">
                                            <?= e($cc['full_name'] ?: 'User') ?>
                                        </div>
                                        <div class="text-muted small">
                                            <?= e($cc['username'] ?? '') ?>
                                        </div>
                                    </td>
                                    <?php endif; ?>

                                    <!-- Cash Collected -->
                                    <td>
                                        <span class="fw-bold text-success font-monospace" style="font-size: .95rem;">
                                            ₱<?= number_format((float)$cc['collected_amount'], 2) ?>
                                        </span>
                                    </td>

                                    <!-- Expected Sales -->
                                    <td>
                                        <span class="text-slate-300 font-monospace">
                                            ₱<?= number_format((float)$cc['system_sales_amount'], 2) ?>
                                        </span>
                                    </td>

                                    <!-- Difference (Signed amount) -->
                                    <td>
                                        <span class="font-monospace fw-semibold <?= $var == 0 ? 'text-success' : ($var > 0 ? 'text-warning' : 'text-danger') ?>">
                                            <?= $var > 0 ? '+₱' : ($var < 0 ? '-₱' : '₱') ?><?= number_format(abs($var), 2) ?>
                                        </span>
                                    </td>

                                    <!-- Result (Reconciliation Badge) -->
                                    <td>
                                        <span class="badge badge-reconciliation <?= $reconBadgeClass ?>">
                                            <?= lucide_icon($reconIcon, 'me-1') ?><?= $reconLabel ?>
                                        </span>
                                    </td>

                                    <!-- Notes -->
                                    <td class="pe-4 text-secondary small" style="max-width: 220px;">
                                        <?= e($cc['notes'] ?? 'N/A') ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <!-- Reserved Space Indicator (Shown when current page has fewer than 15 records) -->
                        <?php if ($currentPageCount < 15): ?>
                        <div class="table-reserved-space text-center py-4 d-flex flex-column align-items-center justify-content-center" style="min-height: <?= max(100, (15 - $currentPageCount) * 44) ?>px;">
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
                        <?php if ($totalRecords > 0): ?>
                            Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong> (Total <?= number_format($totalRecords) ?> records)
                        <?php else: ?>
                            0 records found
                        <?php endif; ?>
                    </div>

                    <nav aria-label="Cash collections pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <!-- Previous -->
                            <li class="page-item <?= ($page <= 1 || $totalRecords === 0) ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= cc_url(['page' => $page - 1]) ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;&nbsp;Prev</span>
                                </a>
                            </li>

                            <!-- Page Numbers -->
                            <?php if ($totalRecords > 0):
                                $startPage = max(1, $page - 2);
                                $endPage   = min($totalPages, $page + 2);

                                if ($startPage > 1) {
                                    echo '<li class="page-item"><a class="page-link" href="' . cc_url(['page' => 1]) . '">1</a></li>';
                                    if ($startPage > 2) {
                                        echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
                                    }
                                }

                                for ($p = $startPage; $p <= $endPage; $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= cc_url(['page' => $p]) ?>"><?= $p ?></a>
                                </li>
                                <?php endfor;

                                if ($endPage < $totalPages) {
                                    if ($endPage < $totalPages - 1) {
                                        echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
                                    }
                                    echo '<li class="page-item"><a class="page-link" href="' . cc_url(['page' => $totalPages]) . '">' . $totalPages . '</a></li>';
                                }
                            endif; ?>

                            <!-- Next -->
                            <li class="page-item <?= ($page >= $totalPages || $totalRecords === 0) ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= cc_url(['page' => $page + 1]) ?>" aria-label="Next">
                                    <span aria-hidden="true">Next&nbsp;&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>

        </main>

        <!-- RECORD CASH COLLECTION MODAL -->
        <div class="modal fade" id="recordCashModal" tabindex="-1" aria-labelledby="recordCashModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content bg-dark text-light border-secondary border-opacity-25" style="background:#0f172a; border-color:#1e293b;">
                    <div class="modal-header border-secondary border-opacity-25">
                        <h5 class="modal-title fw-bold d-flex align-items-center" id="recordCashModalLabel">
                            <?= lucide_icon('wallet', 'text-success me-2') ?>Record Physical Cash Collection
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST" action="cash_collections.php">
                        <div class="modal-body p-4">
                            <input type="hidden" name="action" value="record_cash_collection">
                            <?= csrf_field() ?>

                            <div class="alert alert-info bg-info bg-opacity-10 border-info border-opacity-25 text-info small mb-3">
                                <?= lucide_icon('info', 'me-1.5') ?>
                                Enter the total physical cash removed from the coin box. VendoLink will automatically compare it against registered system sales to calculate variance.
                            </div>

                            <!-- Machine selection if multiple machines available -->
                            <?php if (count($allMachines) > 1): ?>
                            <div class="mb-3">
                                <label class="form-label text-secondary small fw-bold">SELECT MACHINE</label>
                                <select name="machine_id" class="form-select dark-input text-white" required>
                                    <?php foreach ($allMachines as $m): ?>
                                        <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === $activeMachineId ? 'selected' : '' ?>>
                                            <?= e($m['name']) ?> (<?= e($m['machine_code'] ?: 'Unit') ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php else: ?>
                                <input type="hidden" name="machine_id" value="<?= $activeMachineId ?>">
                            <?php endif; ?>

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
                            <button type="submit" class="btn btn-success fw-bold px-4 d-inline-flex align-items-center">
                                <?= lucide_icon('check-circle', 'me-1') ?>Save Collection Record
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
<!-- VendoLink JS -->
<script src="<?= ASSETS_URL ?>/js/app.js"></script>

</body>
</html>
