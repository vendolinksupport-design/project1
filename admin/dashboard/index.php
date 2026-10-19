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
$recentActivity   = $dashboardModel->getRecentActivity(10);
$latestRelease    = $dashboardModel->getLatestOtaRelease();
$latestFwVersion  = !empty($latestRelease['version']) ? $latestRelease['version'] : '3.0.0';

$pageTitle   = term('overview');
$currentPage = 'index';
$currentDir  = 'dashboard';

/**
 * Format activity action into clean, human-readable title.
 */
function format_admin_activity_title(array $act): string {
    $action = $act['action'] ?? '';
    switch ($action) {
        case 'SERVICE_CONFIG_UPDATE':
            return 'Service pricing & config updated';
        case 'PASSWORD_RESET_REQUEST':
            return 'Password reset requested';
        case 'LOGIN_FAILED':
            return 'Failed login attempt detected';
        case 'USER_LOGIN':
            return 'User signed in';
        case 'LOGOUT':
            return 'User signed out';
        case 'MACHINE_HEARTBEAT':
            return 'Heartbeat synced from machine';
        case 'LICENSE_KEY_GENERATED':
            return 'Product license key generated';
        case 'VERIFICATION_SUCCESS':
        case 'OTP_VERIFIED':
            return 'Customer verified OTP';
        case 'CUSTOMER_REGISTERED':
            return 'New customer registered';
        default:
            return ucwords(strtolower(str_replace(['_', '-'], ' ', $action)));
    }
}

/**
 * Format activity details / user subtitle.
 */
function format_admin_activity_subtitle(array $act): string {
    $details = trim($act['details'] ?? '');
    $user = trim($act['full_name'] ?? $act['username'] ?? '');
    if (!empty($details) && $details !== ($act['action'] ?? '')) {
        return $details;
    }
    if (!empty($user)) {
        return 'Initiated by ' . $user;
    }
    return 'System operational event';
}

/**
 * Partially mask a product key for secure display (e.g., VL-2026-••••-••••-E37C).
 */
function mask_product_key(string $key): string {
    $parts = explode('-', trim($key));
    if (count($parts) >= 4) {
        $masked = array_fill(2, count($parts) - 3, '••••');
        return $parts[0] . '-' . $parts[1] . '-' . implode('-', $masked) . '-' . end($parts);
    } elseif (strlen($key) > 8) {
        return substr($key, 0, 7) . '-••••-' . substr($key, -4);
    }
    return $key;
}
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

    <!-- Scoped Admin Dashboard Styles (Matching dashboard_preview.html) -->
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

        .preview-header-link {
            color: #38bdf8;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            transition: color 0.15s ease;
        }

        .preview-header-link:hover {
            color: #7dd3fc;
            text-decoration: none;
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
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
            cursor: pointer;
            text-decoration: none !important;
        }

        .admin-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -5px rgba(0, 0, 0, 0.35);
            text-decoration: none !important;
        }

        .admin-stat-primary:hover { border-color: rgba(14, 165, 233, 0.45); }
        .admin-stat-info:hover    { border-color: rgba(16, 185, 129, 0.45); }
        .admin-stat-warning:hover { border-color: rgba(245, 158, 11, 0.45); }
        .admin-stat-indigo:hover  { border-color: rgba(99, 102, 241, 0.45); }

        .admin-stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
        }

        .admin-stat-primary::before { background: #0ea5e9; }
        .admin-stat-info::before    { background: #10b981; }
        .admin-stat-warning::before { background: #f59e0b; }
        .admin-stat-indigo::before  { background: #6366f1; }

        .admin-stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
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

        /* Machine item cards (matching dashboard_preview.html) */
        .preview-machine-card {
            background: rgba(15, 23, 42, 0.75);
            border: 1px solid rgba(148, 163, 184, 0.14);
            border-radius: 10px;
            padding: 1rem;
            transition: border-color 0.15s ease, background 0.15s ease;
        }

        .preview-machine-card:hover {
            border-color: rgba(56, 189, 248, 0.35);
            background: rgba(15, 23, 42, 0.95);
        }

        /* Status Pills: Explicit high-contrast colors matching preview */
        .preview-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.22rem 0.65rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            line-height: 1.2;
            white-space: nowrap;
            flex-shrink: 0;
            text-decoration: none;
        }

        .preview-status-pill.status-online {
            background: rgba(34, 197, 94, 0.15) !important;
            color: #4ade80 !important;
            border: 1px solid rgba(34, 197, 94, 0.3) !important;
        }

        .preview-status-pill.status-stale {
            background: rgba(245, 158, 11, 0.15) !important;
            color: #fbbf24 !important;
            border: 1px solid rgba(245, 158, 11, 0.3) !important;
        }

        .preview-status-pill.status-offline {
            background: rgba(239, 68, 68, 0.15) !important;
            color: #f87171 !important;
            border: 1px solid rgba(239, 68, 68, 0.3) !important;
        }

        .preview-status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            display: inline-block;
        }

        .preview-status-pill.status-online .preview-status-dot {
            background: #4ade80;
            box-shadow: 0 0 6px rgba(74, 222, 128, 0.6);
        }

        .preview-status-pill.status-stale .preview-status-dot {
            background: #fbbf24;
        }

        .preview-status-pill.status-offline .preview-status-dot {
            background: #f87171;
        }

        .preview-badge-code {
            background: rgba(14, 165, 233, 0.12) !important;
            color: #38bdf8 !important;
            border: 1px solid rgba(56, 189, 248, 0.25) !important;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace !important;
            font-size: 0.75rem !important;
            font-weight: 600 !important;
            padding: 0.2rem 0.55rem !important;
            border-radius: 6px !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.35rem !important;
            white-space: nowrap !important;
            text-decoration: none !important;
        }

        .preview-badge-key {
            background: rgba(245, 158, 11, 0.12) !important;
            color: #fde047 !important;
            border: 1px solid rgba(245, 158, 11, 0.25) !important;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace !important;
            font-size: 0.75rem !important;
            font-weight: 600 !important;
            padding: 0.2rem 0.55rem !important;
            border-radius: 6px !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.35rem !important;
            max-width: 220px !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            text-decoration: none !important;
        }

        .preview-badge-count {
            background: #1e293b !important;
            color: #cbd5e1 !important;
            border: 1px solid #334155 !important;
            font-size: 0.72rem !important;
            font-weight: 500 !important;
            padding: 0.2rem 0.55rem !important;
            border-radius: 9999px !important;
            line-height: 1.2;
            display: inline-block;
        }

        .preview-link-arrow {
            color: #cbd5e1;
            font-size: 0.75rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            transition: color 0.15s ease;
        }

        .preview-link-arrow:hover {
            color: #ffffff;
            text-decoration: underline;
        }

        /* Banner styles */
        .preview-banner-success {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            background: rgba(16, 185, 129, 0.08);
            border: 1px solid rgba(16, 185, 129, 0.2);
            margin-bottom: 1rem;
        }

        .preview-banner-warning {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.25);
            margin-bottom: 1rem;
        }

        /* Recent Activity Scroll & Items */
        .preview-activity-scroll {
            max-height: 320px;
            overflow-y: auto;
            padding-right: 4px;
            scrollbar-width: thin;
            scrollbar-color: rgba(148, 163, 184, 0.2) transparent;
        }

        .preview-activity-scroll::-webkit-scrollbar {
            width: 4px;
        }

        .preview-activity-scroll::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.2);
            border-radius: 4px;
        }

        .preview-activity-item {
            padding: 0.65rem 0.85rem;
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid rgba(148, 163, 184, 0.1);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            transition: background 0.15s ease, border-color 0.15s ease;
        }

        .preview-activity-item:hover {
            background: rgba(30, 41, 59, 0.75);
            border-color: rgba(148, 163, 184, 0.2);
        }

        .preview-activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            flex-shrink: 0;
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

            <!-- Compact Page Header -->
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
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
                <div class="page-header-actions d-flex align-items-center flex-shrink-0">
                    <div class="page-date text-slate-300 bg-slate-900 border border-slate-800 rounded-pill px-3 py-1.5 d-inline-flex align-items-center text-nowrap shadow-sm" style="gap: 10px;">
                        <!-- Live Status Indicator -->
                        <div class="d-inline-flex align-items-center">
                            <span class="live-dot me-1.5" style="width: 8px; height: 8px; background: #22c55e; border-radius: 50%; display: inline-block; box-shadow: 0 0 8px rgba(34, 197, 94, 0.7);"></span>
                            <span class="text-success fw-bold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Live</span>
                        </div>

                        <span class="text-slate-600">&bull;</span>

                        <!-- Calendar Date -->
                        <div class="d-inline-flex align-items-center text-slate-300 small">
                            <i class="bi bi-calendar3 me-1.5 text-info"></i>
                            <span class="fw-medium"><?= date('l, F j, Y') ?></span>
                        </div>

                        <span class="text-slate-600">&bull;</span>

                        <!-- Sizable Digital Clock Badge -->
                        <div class="d-inline-flex align-items-center bg-slate-950 border border-slate-700 border-opacity-60 rounded-pill px-2.5 py-1 text-warning shadow-sm">
                            <i class="bi bi-clock-fill me-1.5 text-warning" style="font-size: 0.95rem;"></i>
                            <span id="adminClockDisplay" class="clock-display font-monospace text-white fw-bold fs-6" style="letter-spacing: 0.5px; line-height: 1;"><?= date('h:i A') ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 1. CONSOLIDATED KPI SUMMARY CARDS (4 Interactive Cards Directing to Specific Panels) -->
            <div class="row g-3 mb-4">
                
                <!-- Card 1 — Customers -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <a href="<?= BASE_URL ?>/admin/customers/index.php" class="admin-stat-card admin-stat-primary text-decoration-none" title="View All Customers">
                        <div>
                            <div class="admin-stat-label">Total Customers</div>
                            <div class="admin-stat-value text-white"><?= number_format($metrics['total_customers']) ?></div>
                            <div class="admin-stat-secondary text-success">
                                <i class="bi bi-person-check-fill me-1"></i><?= number_format($metrics['active_customers']) ?> Active Customers
                            </div>
                        </div>
                        <div class="admin-stat-icon" style="background: rgba(14, 165, 233, 0.12); border: 1px solid rgba(14, 165, 233, 0.25); color: #38bdf8;">
                            <i class="bi bi-people-fill"></i>
                        </div>
                    </a>
                </div>

                <!-- Card 2 — Machines -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <a href="<?= BASE_URL ?>/admin/machines/index.php" class="admin-stat-card admin-stat-info text-decoration-none" title="View All Machines">
                        <div>
                            <div class="admin-stat-label">Total Machines</div>
                            <div class="admin-stat-value text-white"><?= number_format($metrics['total_machines']) ?></div>
                            <div class="admin-stat-secondary">
                                <span class="text-success"><i class="bi bi-broadcast me-1"></i><?= number_format($metrics['online_machines']) ?> Online Machines</span>
                                <span class="text-slate-500 mx-1">&bull;</span>
                                <span class="text-slate-400"><?= number_format($metrics['offline_machines']) ?> Offline</span>
                            </div>
                        </div>
                        <div class="admin-stat-icon" style="background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.25); color: #34d399;">
                            <i class="bi bi-cpu-fill"></i>
                        </div>
                    </a>
                </div>

                <!-- Card 3 — Product Keys -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <a href="<?= BASE_URL ?>/admin/licenses/index.php" class="admin-stat-card admin-stat-warning text-decoration-none" title="Manage & Generate Product Keys">
                        <div>
                            <div class="admin-stat-label">Product Keys</div>
                            <div class="admin-stat-value text-white">
                                <?= number_format($metrics['unused_keys']) ?> <span class="fs-6 fw-normal text-slate-400">Unused</span>
                            </div>
                            <div class="admin-stat-secondary text-warning">
                                <i class="bi bi-key-fill me-1"></i><?= number_format($metrics['active_activations']) ?> Active Activations
                            </div>
                        </div>
                        <div class="admin-stat-icon" style="background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.25); color: #fbbf24;">
                            <i class="bi bi-qr-code-scan"></i>
                        </div>
                    </a>
                </div>

                <!-- Card 4 — Firmware & OTA -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <a href="<?= BASE_URL ?>/admin/ota/index.php" class="admin-stat-card admin-stat-indigo text-decoration-none" title="View OTA Firmware Releases">
                        <div>
                            <div class="admin-stat-label">Latest Firmware</div>
                            <div class="admin-stat-value text-white">v<?= e(ltrim($latestFwVersion, 'vV')) ?></div>
                            <div class="admin-stat-secondary" style="color: #a5b4fc;">
                                <i class="bi bi-check-circle-fill me-1"></i>OTA Sync Operational
                            </div>
                        </div>
                        <div class="admin-stat-icon" style="background: rgba(99, 102, 241, 0.12); border: 1px solid rgba(99, 102, 241, 0.25); color: #818cf8;">
                            <i class="bi bi-hdd-network-fill"></i>
                        </div>
                    </a>
                </div>

            </div>

            <!-- 2. MAIN OPERATIONAL PANELS (Left: Machine Status | Right: Recent Activity) -->
            <div class="row g-4 mb-4">
                
                <!-- Left Column: Machine Status (col-12 col-lg-7) -->
                <div class="col-12 col-lg-7">
                    <div class="admin-card">
                        <div class="admin-card-header">
                            <div class="d-flex align-items-center gap-2">
                                <span class="preview-status-dot" style="background: #22c55e; box-shadow: 0 0 6px rgba(34, 197, 94, 0.6); width: 8px; height: 8px;"></span>
                                <h5 class="card-title text-white fw-bold mb-0 fs-6">Machine Status</h5>
                                <span class="preview-badge-count font-monospace">
                                    <?= count($machinesOverview) ?> <?= count($machinesOverview) === 1 ? 'Unit' : 'Units' ?>
                                </span>
                            </div>
                            <a href="<?= BASE_URL ?>/admin/machines/index.php" class="preview-header-link">
                                View All Machines <i class="bi bi-chevron-right ms-0.5"></i>
                            </a>
                        </div>

                        <!-- Operational Health Status Banner -->
                        <?php
                            $hasOfflineInOverview = false;
                            foreach ($machinesOverview as $chk) {
                                $chkSt = $chk['calculated_status'] ?? $chk['status'] ?? 'offline';
                                if ($chkSt === 'offline') {
                                    $hasOfflineInOverview = true;
                                    break;
                                }
                            }
                            $allOperational = !empty($machinesOverview) && !$hasOfflineInOverview && (int)$metrics['offline_machines'] === 0;
                        ?>
                        <?php if ($allOperational): ?>
                            <div class="preview-banner-success">
                                <div class="d-flex align-items-center gap-2 small fw-medium" style="color: #6ee7b7 !important;">
                                    <i class="bi bi-check-circle-fill flex-shrink-0" style="color: #34d399;"></i>
                                    <span>All machines are online and responding normally. No hardware faults detected.</span>
                                </div>
                            </div>
                        <?php elseif (!empty($machinesOverview)): ?>
                            <div class="preview-banner-warning d-flex align-items-center justify-content-between gap-2">
                                <div class="d-flex align-items-center gap-2 small fw-medium" style="color: #fca5a5 !important;">
                                    <i class="bi bi-exclamation-triangle-fill flex-shrink-0" style="color: #f87171;"></i>
                                    <span>Attention: <?= max(1, (int)$metrics['offline_machines']) ?> machine(s) currently offline.</span>
                                </div>
                                <a href="<?= BASE_URL ?>/admin/machines/index.php" class="btn btn-sm btn-danger py-0.5 px-2" style="font-size: 0.75rem;">Inspect</a>
                            </div>
                        <?php endif; ?>

                        <?php if (empty($machinesOverview)): ?>
                            <div class="text-center py-5 text-slate-400 my-auto">
                                <i class="bi bi-hdd-rack mb-2 d-block text-slate-500 fs-1"></i>
                                <div class="fw-semibold text-slate-300">No machines registered yet.</div>
                                <a href="<?= BASE_URL ?>/admin/machines/index.php?tab=provisioning" class="btn btn-sm btn-primary mt-2">
                                    <i class="bi bi-plus-circle me-1"></i>Add Machine
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-2.5">
                                <?php foreach ($machinesOverview as $m): ?>
                                    <?php
                                        $mStatus = $m['calculated_status'] ?? $m['status'] ?? 'offline';
                                        $displayKey = !empty($m['product_key']) ? $m['product_key'] : ($m['machine_key'] ?? '');
                                    ?>
                                    <div class="preview-machine-card">
                                        <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                                            <div>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="fw-bold text-white fs-6"><?= e($m['name'] ?: 'Machine #' . $m['id']) ?></span>
                                                    <span class="text-slate-400 small">#<?= (int)$m['id'] ?></span>
                                                </div>
                                                <div class="text-slate-400 small mt-0.5 d-flex align-items-center gap-1">
                                                    <i class="bi bi-geo-alt text-slate-500"></i>
                                                    <?= e($m['location'] ?: 'Main Station') ?>
                                                </div>
                                            </div>
                                            <span class="preview-status-pill status-<?= $mStatus ?>">
                                                <span class="preview-status-dot"></span>
                                                <?= ucfirst($mStatus) ?>
                                            </span>
                                        </div>

                                        <div class="d-flex flex-wrap align-items-center gap-2 pt-2 border-top border-slate-700 border-opacity-40">
                                            <span class="preview-badge-code">
                                                <i class="bi bi-tag-fill"></i>
                                                <?= e($m['machine_code'] ?: 'No Code') ?>
                                            </span>

                                            <?php if (!empty($displayKey)): ?>
                                                <span class="preview-badge-key" title="Product Key: <?= e($displayKey) ?>">
                                                    <i class="bi bi-key-fill"></i>
                                                    <?= e(mask_product_key($displayKey)) ?>
                                                </span>
                                            <?php endif; ?>

                                            <span class="small text-slate-400 ms-auto">
                                                Owner: <strong class="text-slate-200"><?= e($m['owner_name'] ?: 'Unassigned') ?></strong>
                                            </span>
                                        </div>

                                        <div class="d-flex align-items-center justify-content-between text-slate-400 small pt-2 mt-1 border-top border-slate-700 border-opacity-30">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="font-monospace text-slate-300"><i class="bi bi-globe me-1"></i><?= e($m['ip_address'] ?: '—') ?></span>
                                                <span>&bull;</span>
                                                <span><i class="bi bi-activity me-1"></i><?= !empty($m['last_seen']) ? time_ago($m['last_seen']) : 'No Heartbeat' ?></span>
                                            </div>
                                            <a href="<?= BASE_URL ?>/admin/machines/index.php" class="preview-link-arrow">
                                                Configure Unit <i class="bi bi-arrow-right ms-0.5"></i>
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right Column: Recent Activity (col-12 col-lg-5) -->
                <div class="col-12 col-lg-5">
                    <div class="admin-card">
                        <div class="admin-card-header mb-1">
                            <h5 class="card-title text-white fw-bold mb-0 d-flex align-items-center gap-2 fs-6">
                                <i class="bi bi-lightning-charge-fill text-sky-400"></i>Recent Platform Activity
                            </h5>
                            <!-- Retains Recent Platform Activity text for test verification and clarity -->
                            <span class="preview-badge-count font-monospace">
                                Clean Stream &bull; <?= count($recentActivity) ?> Events
                            </span>
                        </div>
                        <p class="text-slate-400 mb-3" style="font-size: 0.78rem;">
                            Filtered for meaningful operational, customer, and hardware events.
                        </p>

                        <?php if (empty($recentActivity)): ?>
                            <div class="text-center py-5 text-slate-400 my-auto">
                                <i class="bi bi-journal-x mb-2 d-block text-slate-500 fs-1"></i>
                                <div class="fw-semibold text-slate-300">No recent activity logged.</div>
                            </div>
                        <?php else: ?>
                            <!-- Fixed Header, Internally Scrollable Panel -->
                            <div class="preview-activity-scroll pe-1 d-flex flex-column gap-2">
                                <?php foreach ($recentActivity as $act): ?>
                                    <?php
                                        $actName = $act['action'] ?? '';
                                        $actTitle = format_admin_activity_title($act);
                                        $actSubtitle = format_admin_activity_subtitle($act);

                                        $actIcon = 'bi-journal-text';
                                        $iconBg = 'rgba(14, 165, 233, 0.12)';
                                        $iconColor = '#38bdf8';
                                        $iconBorder = 'rgba(56, 189, 248, 0.25)';

                                        if (strpos($actName, 'MACHINE') !== false || strpos($actName, 'HEARTBEAT') !== false) {
                                            $actIcon = 'bi-broadcast';
                                            $iconBg = 'rgba(34, 197, 94, 0.12)';
                                            $iconColor = '#4ade80';
                                            $iconBorder = 'rgba(34, 197, 94, 0.25)';
                                        } elseif (strpos($actName, 'KEY') !== false || strpos($actName, 'LICENSE') !== false) {
                                            $actIcon = 'bi-key-fill';
                                            $iconBg = 'rgba(245, 158, 11, 0.12)';
                                            $iconColor = '#fbbf24';
                                            $iconBorder = 'rgba(245, 158, 11, 0.25)';
                                        } elseif (strpos($actName, 'VERIF') !== false || strpos($actName, 'AUTH') !== false || strpos($actName, 'OTP') !== false) {
                                            $actIcon = 'bi-shield-check';
                                            $iconBg = 'rgba(34, 197, 94, 0.12)';
                                            $iconColor = '#4ade80';
                                            $iconBorder = 'rgba(34, 197, 94, 0.25)';
                                        } elseif (strpos($actName, 'FAIL') !== false || strpos($actName, 'RESET') !== false || strpos($actName, 'ERROR') !== false) {
                                            $actIcon = 'bi-exclamation-triangle-fill';
                                            $iconBg = 'rgba(239, 68, 68, 0.12)';
                                            $iconColor = '#f87171';
                                            $iconBorder = 'rgba(239, 68, 68, 0.25)';
                                        } elseif (strpos($actName, 'SERVICE') !== false || strpos($actName, 'CONFIG') !== false) {
                                            $actIcon = 'bi-gear-wide-connected';
                                            $iconBg = 'rgba(245, 158, 11, 0.12)';
                                            $iconColor = '#fbbf24';
                                            $iconBorder = 'rgba(245, 158, 11, 0.25)';
                                        } elseif (strpos($actName, 'CUSTOMER') !== false || strpos($actName, 'USER') !== false) {
                                            $actIcon = 'bi-person-fill';
                                            $iconBg = 'rgba(14, 165, 233, 0.12)';
                                            $iconColor = '#38bdf8';
                                            $iconBorder = 'rgba(56, 189, 248, 0.25)';
                                        }
                                    ?>
                                    <div class="preview-activity-item">
                                        <div class="d-flex align-items-center gap-2.5 min-w-0">
                                            <div class="preview-activity-icon" style="background: <?= $iconBg ?>; color: <?= $iconColor ?>; border: 1px solid <?= $iconBorder ?>;">
                                                <i class="bi <?= $actIcon ?>"></i>
                                            </div>
                                            <div class="text-truncate">
                                                <div class="text-slate-200 fw-medium text-truncate" style="font-size: 0.8125rem; line-height: 1.3;">
                                                    <?= e($actTitle) ?>
                                                </div>
                                                <div class="text-slate-400 text-truncate" style="font-size: 0.72rem; line-height: 1.2;">
                                                    <?= e($actSubtitle) ?>
                                                </div>
                                            </div>
                                        </div>
                                        <span class="text-slate-400 font-monospace flex-shrink-0 ms-auto ps-2 text-nowrap" style="font-size: 0.72rem;">
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
