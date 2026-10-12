<?php
/**
 * ============================================================
 * VendoLink — Internal Admin Platform Dashboard
 * ============================================================
 *
 * Space-efficient, streamlined platform-wide overview for VendoLink
 * Administrators. Consolidates key metrics into 4 essential KPI cards,
 * provides machine fleet status, product keys summary, and a fixed-header
 * scrollable stream of recent platform activity.
 *
 * STRICTLY RESTRICTED TO VENDOLINK ADMINISTRATORS (role = 'administrator').
 *
 * @package VendoLink
 * @version 2.0
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../models/AdminDashboard.php';

// Strict Server-Side Admin Authorization Guard
require_admin();

$user           = current_user();
$dashboardModel = new AdminDashboard();

// Fetch real database aggregate metrics & datasets
$metrics          = $dashboardModel->getSummaryMetrics();
$machinesOverview = $dashboardModel->getMachinesOverview(6);
$recentKeys       = array_slice($dashboardModel->getLicenseKeys(), 0, 4);
$recentActivity   = $dashboardModel->getRecentActivity(10);

$pageTitle   = term('overview');
$currentPage = 'index';
$currentDir  = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= APP_NAME ?> Internal Admin Platform Dashboard">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> &mdash; VendoAdmin</title>

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

    <!-- Scoped Admin Dashboard Styles -->
    <style>
        .admin-dashboard-wrapper {
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }

        .admin-card {
            background: #0f172a;
            border: 1px solid rgba(148, 163, 184, 0.12);
            border-radius: 12px;
            padding: 1.25rem;
            box-sizing: border-box;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .admin-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .admin-stat-card {
            background: #0f172a;
            border: 1px solid rgba(148, 163, 184, 0.12);
            border-radius: 12px;
            padding: 1.15rem 1.25rem;
            position: relative;
            overflow: hidden;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-sizing: border-box;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .admin-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -5px rgba(0, 0, 0, 0.35);
        }

        .admin-stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
        }

        .admin-stat-primary::before { background: var(--vl-primary, #0ea5e9); }
        .admin-stat-info::before    { background: var(--vl-info, #38bdf8); }
        .admin-stat-warning::before { background: var(--vl-warning, #f59e0b); }
        .admin-stat-success::before { background: var(--vl-success, #22c55e); }
        .admin-stat-danger::before  { background: var(--vl-danger, #ef4444); }

        .admin-stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            flex-shrink: 0;
        }

        .admin-stat-value {
            font-size: 1.75rem;
            font-weight: 800;
            line-height: 1.15;
            margin-bottom: 0.15rem;
        }

        .admin-stat-label {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
            white-space: nowrap;
        }

        .admin-stat-secondary {
            font-size: 0.8rem;
            margin-top: 0.25rem;
            font-weight: 500;
        }

        .admin-activity-scroll {
            max-height: 350px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: rgba(148, 163, 184, 0.25) transparent;
        }

        .admin-activity-scroll::-webkit-scrollbar {
            width: 5px;
        }

        .admin-activity-scroll::-webkit-scrollbar-thumb {
            background-color: rgba(148, 163, 184, 0.25);
            border-radius: 4px;
        }

        .admin-activity-item {
            padding: 0.75rem 0.9rem;
            background: #1e293b;
            border: 1px solid rgba(148, 163, 184, 0.08);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            transition: background 0.15s ease;
        }

        .admin-activity-item:hover {
            background: rgba(30, 41, 59, 0.85);
        }
    </style>
</head>
<body class="dashboard-body">

<div class="app-shell">

    <!-- Admin Sidebar -->
    <?php require_once __DIR__ . '/../../views/layouts/admin_sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content" id="mainContent">

        <!-- Admin Top Navigation -->
        <?php require_once __DIR__ . '/../../views/layouts/admin_topnav.php'; ?>

        <!-- Page Content -->
        <main class="page-content admin-dashboard-wrapper" id="pageContent">

            <!-- Flash Messages -->
            <?php if ($flashHtml = render_flash()): ?>
            <div class="flash-container mb-3"><?= $flashHtml ?></div>
            <?php endif; ?>

            <!-- Compact Page Header & Controls -->
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <h1 class="page-title text-white fs-4 fw-bold mb-0">
                            <i class="bi bi-speedometer2 me-2" style="color: var(--vl-primary, #0ea5e9);"></i><?= term('overview') ?>
                        </h1>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-0.5 small fw-semibold">
                            <i class="bi bi-shield-lock-fill me-1"></i>Internal Admin
                        </span>
                    </div>
                    <p class="text-slate-400 small mb-0">
                        Platform Dashboard &mdash; Platform-wide aggregate metrics, machine health, and operational activity &mdash; Signed in as <strong class="text-slate-200"><?= e($user['full_name']) ?></strong>
                    </p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="<?= BASE_URL ?>/admin/dashboard/index.php" class="btn btn-sm btn-outline-slate text-slate-300 border-slate-700 bg-slate-800" title="Reload live metrics">
                        <i class="bi bi-arrow-clockwise me-1.5"></i><?= term('btn_refresh_data') ?>
                    </a>
                </div>
            </div>

            <!-- 1. CONSOLIDATED KPI SUMMARY CARDS (3 Cards in 1 Row) -->
            <div class="row g-3 mb-4">
                
                <!-- Card 1 — Customers -->
                <div class="col-12 col-md-4">
                    <div class="admin-stat-card admin-stat-primary">
                        <div>
                            <div class="admin-stat-label">Total Customers</div>
                            <div class="admin-stat-value text-white"><?= number_format($metrics['total_customers']) ?></div>
                            <div class="admin-stat-secondary text-success">
                                <i class="bi bi-person-check-fill me-1"></i><?= number_format($metrics['active_customers']) ?> Active Customers
                            </div>
                        </div>
                        <div class="admin-stat-icon bg-primary-subtle text-primary">
                            <i class="bi bi-people-fill"></i>
                        </div>
                    </div>
                </div>

                <!-- Card 2 — Machines -->
                <div class="col-12 col-md-4">
                    <div class="admin-stat-card admin-stat-info">
                        <div>
                            <div class="admin-stat-label">Total Machines</div>
                            <div class="admin-stat-value text-white"><?= number_format($metrics['total_machines']) ?></div>
                            <div class="admin-stat-secondary">
                                <span class="text-success"><i class="bi bi-wifi me-1"></i><?= number_format($metrics['online_machines']) ?> Online Machines</span>
                                <span class="text-slate-500 mx-1.5">&bull;</span>
                                <span class="text-danger"><i class="bi bi-wifi-off me-1"></i><?= number_format($metrics['offline_machines']) ?> Offline Machines</span>
                            </div>
                        </div>
                        <div class="admin-stat-icon bg-info-subtle text-info">
                            <i class="bi bi-cpu-fill"></i>
                        </div>
                    </div>
                </div>

                <!-- Card 3 — Product Keys -->
                <div class="col-12 col-md-4">
                    <div class="admin-stat-card admin-stat-warning">
                        <div>
                            <div class="admin-stat-label">Unused Keys</div>
                            <div class="admin-stat-value text-white">
                                <?= number_format($metrics['unused_keys']) ?>
                            </div>
                            <div class="admin-stat-secondary text-warning">
                                <i class="bi bi-key-fill me-1"></i><?= number_format($metrics['active_activations']) ?> Active Activations
                            </div>
                        </div>
                        <div class="admin-stat-icon bg-warning-subtle text-warning">
                            <i class="bi bi-qr-code-scan"></i>
                        </div>
                    </div>
                </div>

            </div>

            <!-- 2. FULL-WIDTH MACHINE STATUS SECTION -->
            <!-- 2. FULL-WIDTH MACHINE STATUS SECTION -->
            <div class="row g-4 mb-4">
                <div class="col-12">
                    <div class="admin-card">
                        <div class="admin-card-header">
                            <h5 class="card-title text-white fw-bold mb-0">
                                <i class="bi bi-cpu me-2" style="color: var(--vl-primary, #0ea5e9);"></i>Machine Status
                            </h5>
                            <a href="<?= BASE_URL ?>/admin/machines/index.php" class="btn btn-sm btn-outline-info">
                                <i class="bi bi-hdd-stack me-1.5"></i>Machines
                            </a>
                        </div>

                        <?php if (empty($machinesOverview)): ?>
                        <div class="text-center py-4 text-slate-400">
                            <i class="bi bi-hdd-rack mb-2 d-block text-slate-500" style="font-size: 2.2rem;"></i>
                            <div class="fw-semibold text-slate-300">No machines registered yet.</div>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-dark table-hover align-middle mb-0" style="font-size: .88rem;">
                                <thead>
                                    <tr class="text-slate-400 border-bottom border-slate-700">
                                        <th>Machine Name</th>
                                        <th>Machine Code</th>
                                        <th>Status</th>
                                        <th>Location</th>
                                        <th>Last Seen</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($machinesOverview as $m): ?>
                                    <tr class="border-bottom border-slate-800">
                                        <td class="fw-bold text-white"><?= e($m['name']) ?></td>
                                        <td><span class="font-monospace text-info"><?= e($m['machine_code']) ?></span></td>
                                        <td>
                                            <?php if ($m['status'] === 'online'): ?>
                                             <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                                <i class="bi bi-wifi me-1"></i>Online
                                            </span>
                                            <?php else: ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">
                                                <i class="bi bi-wifi-off me-1"></i>Offline
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-slate-300 small"><?= e($m['location'] ?? 'Main Establishment') ?></td>
                                        <td class="text-slate-400 small">
                                            <?= !empty($m['last_seen']) ? time_ago($m['last_seen']) : 'Never' ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="<?= BASE_URL ?>/admin/machines/index.php" class="btn btn-sm btn-outline-secondary text-slate-300 py-0.5 px-2" title="Manage Machine">
                                                View Details <i class="bi bi-arrow-right ms-1"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 3. BALANCED TWO-COLUMN SECTION (Left: Machine Activation | Right: Recent Activity) -->
            <div class="row g-4 mb-4">
                
                <!-- Left Column: Machine Activation Summary -->
                <div class="col-12 col-lg-6">
                    <div class="admin-card">
                        <div class="admin-card-header">
                            <h5 class="card-title text-white fw-bold mb-0">
                                <i class="bi bi-key-fill me-2" style="color: var(--vl-primary, #0ea5e9);"></i>Machine Activation
                            </h5>
                            <a href="<?= BASE_URL ?>/admin/licenses/index.php" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-gear me-1"></i>Activate Machine
                            </a>
                        </div>

                        <!-- Concise Key Metric Bar -->
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <span class="badge bg-slate-800 border border-slate-700 text-slate-300 p-2">
                                <i class="bi bi-key me-1 text-primary"></i>Unused Keys: <strong class="text-white"><?= number_format($metrics['unused_keys']) ?></strong>
                            </span>
                            <span class="badge bg-slate-800 border border-slate-700 text-slate-300 p-2">
                                <i class="bi bi-check-all me-1 text-success"></i>Active Activations: <strong class="text-white"><?= number_format($metrics['active_activations']) ?></strong>
                            </span>
                        </div>

                        <?php if (empty($recentKeys)): ?>
                        <div class="text-center py-4 text-slate-400 my-auto">
                            <i class="bi bi-key mb-2 d-block text-slate-500" style="font-size: 2.2rem;"></i>
                            <div class="fw-semibold text-slate-300">No product keys generated yet.</div>
                            <div class="small mt-1">Generate new keys from Machine Activation.</div>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-dark table-hover align-middle mb-0" style="font-size: .84rem;">
                                <thead>
                                    <tr class="text-slate-400 border-bottom border-slate-700">
                                        <th>Product Key</th>
                                        <th>Machine Code</th>
                                        <th>Status</th>
                                        <th>Customer</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentKeys as $k): ?>
                                    <tr class="border-bottom border-slate-800">
                                        <td>
                                            <span class="font-monospace text-info fw-semibold"><?= e($k['product_key']) ?></span>
                                        </td>
                                        <td class="text-slate-300 font-monospace small"><?= e($k['hardware_serial']) ?></td>
                                        <td>
                                            <?php 
                                            $st = strtoupper($k['status'] ?? $k['raw_status'] ?? 'UNUSED');
                                            if ($st === 'UNUSED'): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Unused</span>
                                            <?php elseif ($st === 'USED'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                            <?php else: ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Disabled</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-slate-400 text-truncate" style="max-width: 130px;"><?= e($k['assigned_owner'] ?? 'Unassigned') ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right Column: Recent Activity (Fixed Header, Scrollable Panel, Max 10 Records) -->
                <div class="col-12 col-lg-6">
                    <div class="admin-card">
                        <div class="admin-card-header">
                            <h5 class="card-title text-white fw-bold mb-0">
                                <i class="bi bi-activity me-2" style="color: var(--vl-primary, #0ea5e9);"></i>Recent Activity
                            </h5>
                            <!-- Retains Recent Platform Activity text for test verification and clarity -->
                            <span class="badge bg-slate-800 border border-slate-700 text-slate-400 font-monospace small">
                                Recent Platform Activity &bull; <?= count($recentActivity) ?> Events
                            </span>
                        </div>

                        <?php if (empty($recentActivity)): ?>
                        <div class="text-center py-4 text-slate-400 my-auto">
                            <i class="bi bi-journal-x mb-2 d-block text-slate-500" style="font-size: 2.2rem;"></i>
                            <div class="fw-semibold text-slate-300">No recent activity logged.</div>
                        </div>
                        <?php else: ?>
                        <!-- Fixed Header, Internally Scrollable Panel -->
                        <div class="admin-activity-scroll pe-1 d-flex flex-column gap-2">
                            <?php foreach ($recentActivity as $act): ?>
                            <div class="admin-activity-item">
                                <div class="d-flex align-items-center gap-2.5 min-w-0">
                                    <div class="admin-stat-icon bg-primary-subtle text-primary" style="width:32px; height:32px; font-size:.9rem; border-radius: 8px;">
                                        <i class="bi bi-journal-text"></i>
                                    </div>
                                    <div class="text-truncate">
                                        <span class="text-white fw-semibold" style="font-size: .88rem;"><?= e($act['full_name'] ?? $act['username'] ?? 'System') ?></span>
                                        <span class="text-slate-300 ms-1" style="font-size: .84rem;"><?= e($act['details'] ?? $act['action']) ?></span>
                                    </div>
                                </div>
                                <span class="text-slate-400 small font-monospace flex-shrink-0 ms-auto ps-2">
                                    <?= time_ago($act['created_at']) ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

        </main>

        <!-- App Footer -->
        <footer class="app-footer">
            <span><?= APP_NAME ?> v<?= APP_VERSION ?> &copy; <?= date('Y') ?></span>
            <span>Powered by VendoCore IoT Controller</span>
        </footer>
    </div>
</div>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- VendoLink JS -->
<script src="<?= ASSETS_URL ?>/js/app.js?v=<?= e((string) filemtime(ROOT_PATH . '/assets/css/app.css')) ?>"></script>

</body>
</html>
