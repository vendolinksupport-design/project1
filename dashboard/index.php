<?php
/**
 * ============================================================
 * VendoLink — Main Dashboard
 * ============================================================
 *
 * Protected route — requires authentication.
 * Renders the top KPI metrics, sales chart, service performance,
 * machine status, recent transactions, maintenance, and system activity.
 *
 * @package VendoLink
 * @version 1.0
 */

// ---- Bootstrap application ----
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Machine.php';
require_once __DIR__ . '/../models/Maintenance.php';
require_once __DIR__ . '/../models/AiAssistant.php';

// ---- Guard: must be logged in ----
require_auth();

// ---- Page metadata ----
$pageTitle    = 'Dashboard';
$currentPage  = 'index';
$user         = current_user();

// ---- Real Database Models & Data ----
$ownerId          = current_owner_id();
$reportModel      = new Report();
$txModel          = new Transaction();
$machineModel     = new Machine();
$maintenanceModel = new Maintenance();
$aiAssistant      = new AiAssistant();

$today = date('Y-m-d');
$todayStats    = $reportModel->getSummaryMetrics($today, $today, 'all', $ownerId);
$todayChart    = $reportModel->getTimeSeries($today, $today, 'today', 'all', $ownerId);
$allMachines   = $machineModel->getAllActive($ownerId);
$fleetMetrics  = (count($allMachines) > 1 && $ownerId > 0) ? $reportModel->getFleetMachineMetrics($ownerId, $today, $today) : [];
$machineInfo   = current_selected_machine($ownerId) ?: $machineModel->primaryWithServices($ownerId);
$recentTxs     = $txModel->getPaginated([], 1, 5, $ownerId)['items'];
$maintItems    = $maintenanceModel->items($ownerId);
$maintSummary  = $reportModel->getMaintenanceSummary($today, $today, $ownerId);
$aiInsights    = $aiAssistant->generateInsights($ownerId);

// Get services indexed by key
$servicesByKey = [];
if (!empty($machineInfo['services'])) {
    foreach ($machineInfo['services'] as $svc) {
        $servicesByKey[$svc['service_key']] = $svc;
    }
}
$waterService = $servicesByKey['water'] ?? ['name' => 'Water Service', 'coin_value' => 5.00, 'service_seconds' => 120];
$soapService  = $servicesByKey['soap']  ?? ['name' => 'Soap Service',  'coin_value' => 5.00, 'service_seconds' => 60];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= APP_NAME ?> Dashboard — <?= APP_TAGLINE ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>

    <!-- Bootstrap 5 -->
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
          rel="stylesheet">

    <!-- VendoLink CSS -->
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/app.css?v=<?= e((string) filemtime(ROOT_PATH . '/assets/css/app.css')) ?>">

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml"
          href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚡</text></svg>">
</head>
<body class="dashboard-body">

<!-- ============================================================
     App Shell
     ============================================================ -->
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
                        <i class="bi bi-speedometer2 me-2"></i>Dashboard
                    </h1>
                    <p class="page-subtitle">
                        Welcome back, <strong><?= e($user['full_name'] ?? 'System Administrator') ?></strong>!
                        Here's what's happening with your machines today.
                    </p>
                </div>
                <div class="page-header-actions d-flex align-items-center flex-shrink-0">
                    <div class="page-date text-slate-300 d-inline-flex align-items-center text-nowrap" style="gap: 10px;">
                        <!-- Live Status Indicator -->
                        <div class="d-inline-flex align-items-center">
                            <span class="live-dot me-1.5" style="width: 8px; height: 8px; background: #22c55e; border-radius: 50%; display: inline-block; box-shadow: 0 0 8px rgba(34, 197, 94, 0.7);"></span>
                            <span class="text-success fw-bold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Live</span>
                        </div>

                        <span class="text-slate-600">&bull;</span>

                        <!-- Calendar Date -->
                        <span class="fw-medium text-slate-300 small"><?= date('l, F j, Y') ?></span>

                        <span class="text-slate-600">&bull;</span>

                        <!-- Live Digital Clock Display -->
                        <span id="clockDisplay" class="clock-display font-monospace text-white fw-bold fs-6" style="letter-spacing: 0.5px; line-height: 1;"><?= date('h:i A') ?></span>
                    </div>
                </div>
            </div>

            <!-- Dashboard Cards & Panels -->
            <?php require_once __DIR__ . '/../views/dashboard/cards.php'; ?>

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

<!-- Today's Sales Chart Initialization -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('dashboardTodaySalesChart');
    if (!ctx) return;

    const chartLabels = <?= json_encode($todayChart['labels']) ?>;
    const waterData   = <?= json_encode($todayChart['water_data']) ?>;
    const soapData    = <?= json_encode($todayChart['soap_data']) ?>;

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
});
</script>

</body>
</html>
