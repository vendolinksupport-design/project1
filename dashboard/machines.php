<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Machine.php';
require_once __DIR__ . '/../models/Maintenance.php';
require_once __DIR__ . '/../controllers/MachineController.php';

require_role([ROLE_ADMIN, ROLE_OWNER]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_hardware_unified') {
        (new MachineController())->saveHardwareUnified();
    } elseif ($action === 'save_lcd_config') {
        (new MachineController())->saveLcdConfiguration();
    } elseif ($action === 'apply_machine_template') {
        (new MachineController())->applyMachineTemplate();
    } elseif ($action === 'claim_machine' || $action === 'add_machine') {
        (new MachineController())->claimMachine();
    } else {
        (new MachineController())->saveServiceConfiguration();
    }
}

$pageTitle = term('machines', 'Machines');
$currentPage = 'machines';
$user = current_user();

$ownerId = current_owner_id();
$machineModel = new Machine();
$allMachines = $machineModel->getAllActive($ownerId);

// Resolve currently selected machine context
$selectedMachineId = current_selected_machine_id($ownerId);
$machine = null;
if ($selectedMachineId > 0) {
    $machine = $machineModel->getByIdWithServices($selectedMachineId, $ownerId);
}
if (!$machine && !empty($allMachines)) {
    $machine = $machineModel->getByIdWithServices((int)$allMachines[0]['id'], $ownerId);
}
if (!$machine) {
    $machine = $machineModel->primaryWithServices($ownerId);
}

$maintenanceSummaries = (new Maintenance())->summariesByService($ownerId);

$machineAlerts = $machine ? $machineModel->getMachineAlerts($machine, $maintenanceSummaries) : [];
$recentActivities = $machine ? $machineModel->getOperationalActivity(5, null, $ownerId) : [];

function service_time_label(int $seconds): string {
    return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
}

function service_runtime_label(int $seconds): string {
    return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm';
}

function runtime_clock_label(int $seconds): string {
    return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/app.css?v=<?= e((string) filemtime(ROOT_PATH . '/assets/css/app.css')) ?>">

    <!-- Scoped LCD 2004A Simulator & Machine Profile Styles -->
    <style>
        .lcd-sim-card {
            background: #0f172a;
            border: 1px solid rgba(148, 163, 184, 0.12);
            border-radius: 14px;
            padding: 1.25rem;
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5);
        }
        .lcd-matrix-box {
            background: #020914;
            border: 2px solid #0e7490;
            border-radius: 10px;
            padding: 1rem;
            box-shadow: inset 0 0 20px rgba(6, 182, 212, 0.25), 0 0 25px rgba(6, 182, 212, 0.15);
            font-family: 'Consolas', 'Courier New', monospace;
            color: #67e8f9;
        }
        .lcd-direct-input {
            background: rgba(8, 47, 73, 0.45) !important;
            border: 1px solid rgba(6, 182, 212, 0.35) !important;
            color: #67e8f9 !important;
            font-family: 'Consolas', 'Courier New', monospace !important;
            text-shadow: 0 0 6px rgba(103, 232, 249, 0.6);
            letter-spacing: 0.12em;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }
        .lcd-direct-input:focus {
            background: rgba(8, 47, 73, 0.75) !important;
            border-color: #22d3ee !important;
            box-shadow: 0 0 10px rgba(34, 211, 238, 0.4) !important;
            outline: none !important;
        }
        .squircle-badge {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.25s ease;
            flex-shrink: 0;
            cursor: pointer;
        }
        .squircle-badge:hover {
            transform: scale(1.05);
            box-shadow: 0 0 14px rgba(6, 182, 212, 0.35);
        }
        .icon-opt-btn {
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .icon-opt-btn:hover {
            transform: translateY(-1px);
        }

        /* Compact Integrated Toggle Switch (Zero Text) */
        .integrated-switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
            cursor: pointer;
            user-select: none;
            margin-bottom: 0;
        }
        .integrated-switch input {
            opacity: 0;
            width: 0;
            height: 0;
            position: absolute;
        }
        .integrated-slider {
            position: absolute;
            inset: 0;
            border-radius: 9999px;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            background: #334155;
            border: 1px solid #475569;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.3);
        }
        .integrated-knob {
            position: absolute;
            top: 2px;
            left: 2px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #ffffff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.4);
            transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .integrated-switch input:checked + .integrated-slider {
            background: #0284c7;
            border-color: #38bdf8;
            box-shadow: 0 0 10px rgba(56, 189, 248, 0.35);
        }
        .integrated-switch input:checked + .integrated-slider .integrated-knob {
            transform: translateX(20px);
        }
        .integrated-switch input:not(:checked) + .integrated-slider {
            background: #334155;
            border-color: #475569;
        }
        .integrated-switch input:not(:checked) + .integrated-slider .integrated-knob {
            transform: translateX(0);
        }

        /* Floating Icon Selection Overlay Popover */
        .icon-overlay-popover {
            width: 240px !important;
            min-width: 240px !important;
            max-width: none !important;
            transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
            transform-origin: top left;
        }
        .icon-overlay-popover.hidden-popover {
            opacity: 0;
            pointer-events: none;
            transform: scale(0.95) translateY(-6px);
            display: none !important;
        }
        .icon-overlay-popover.show-popover {
            opacity: 1;
            pointer-events: auto;
            transform: scale(1) translateY(0);
            display: block !important;
        }
        @media (max-width: 576px) {
            .lcd-sim-card {
                padding: 1rem 0.85rem;
            }
            .lcd-matrix-box {
                padding: 0.75rem 0.6rem;
            }
            .lcd-direct-input {
                font-size: 0.8rem !important;
                letter-spacing: 0.05em !important;
            }
        }
    </style>
</head>
<body class="dashboard-body">
<div class="app-shell">
    <?php require_once __DIR__ . '/../views/layouts/sidebar.php'; ?>
    <div class="main-content" id="mainContent">
        <?php require_once __DIR__ . '/../views/layouts/topnav.php'; ?>
        <main class="page-content" id="pageContent">
            <?php if ($flashHtml = render_flash()): ?><div class="flash-container mb-3"><?= $flashHtml ?></div><?php endif; ?>
            <div class="page-header mb-4 d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
                <div>
                    <h1 class="page-title"><i class="bi bi-hdd-stack me-2"></i><?= term('machines') ?></h1>
                    <p class="page-subtitle mb-0">Configure services, LCD display templates, and telemetry for your connected machines.</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-warning text-dark fw-bold px-3 py-2 shadow-sm d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#addMachineModal">
                        <i class="bi bi-plus-circle-fill"></i> Add Another Machine
                    </button>
                </div>
            </div>

            <?php if (count($allMachines) > 1): ?>
            <!-- Machine Quick Switcher Bar -->
            <div class="p-3 mb-4 rounded-3 border border-secondary border-opacity-25" style="background: rgba(15, 23, 42, 0.65);">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="small text-muted text-uppercase fw-bold letter-spacing-1"><i class="bi bi-collection me-1 text-info"></i>Your Connected Machines (<?= count($allMachines) ?>)</span>
                    <span class="small text-muted d-none d-md-inline">Click a machine to manage its live configuration:</span>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($allMachines as $mItem):
                        $isCurrent = ($machine && (int)$mItem['id'] === (int)$machine['id']);
                        $mItemStat = $mItem['calculated_status'] ?? 'offline';
                        $mItemDot = ($mItemStat === 'online') ? 'status-online' : (($mItemStat === 'stale') ? 'status-warning' : 'status-offline');
                        $mItemBadge = ($mItemStat === 'online') ? 'bg-success text-success' : (($mItemStat === 'stale') ? 'bg-warning text-warning' : 'bg-secondary text-secondary');
                    ?>
                    <a href="<?= BASE_URL ?>/dashboard/switch_machine.php?id=<?= (int)$mItem['id'] ?>&return_to=<?= urlencode(BASE_URL . '/dashboard/machines.php') ?>" 
                       class="btn btn-sm d-flex align-items-center gap-2 px-3 py-2 text-start rounded-3 <?= $isCurrent ? 'btn-info text-dark fw-bold shadow-sm' : 'btn-dark border-secondary border-opacity-25 text-light' ?>"
                       style="min-width: 175px;">
                        <span class="status-dot <?= $mItemDot ?>"></span>
                        <div class="min-w-0 flex-grow-1 text-truncate">
                            <div class="text-truncate" style="font-size: 0.85rem;"><?= e($mItem['name']) ?></div>
                            <div class="font-monospace small <?= $isCurrent ? 'text-dark opacity-75' : 'text-muted' ?>" style="font-size: 0.72rem;"><?= e($mItem['machine_code']) ?></div>
                        </div>
                        <span class="badge <?= $isCurrent ? 'bg-dark text-info' : $mItemBadge ?> bg-opacity-25 rounded-pill px-2 py-0.5" style="font-size: 0.68rem;">
                            <?= ucfirst($mItemStat) ?>
                        </span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!$machine): ?>
                <div class="p-5 text-center rounded-3 border border-secondary border-opacity-25 my-4" style="background: rgba(15, 23, 42, 0.75);">
                    <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px; background: rgba(56, 189, 248, 0.15); color: #38bdf8;">
                        <i class="bi bi-hdd-network fs-2"></i>
                    </div>
                    <h4 class="text-light fw-bold mb-2">No Vending Machines Registered</h4>
                    <p class="text-secondary small mb-4" style="max-width: 480px; margin: 0 auto;">
                        Connect your first VendoLink machine using the Product Activation Key provided on the physical machine sticker.
                    </p>
                    <button type="button" class="btn btn-warning text-dark fw-bold px-4 py-2" data-bs-toggle="modal" data-bs-target="#addMachineModal">
                        <i class="bi bi-plus-circle-fill me-1"></i> Add Your First Machine
                    </button>
                </div>
            <?php else: ?>
                <?php
                $calcStatus = $machine['calculated_status'] ?? 'offline';
                $statusColor = ($calcStatus === 'online') ? 'success' : 'danger';
                $statusLabel = ($calcStatus === 'online') ? term('online') : term('offline');
                $machineType = $machine['machine_type'] ?? 'carwash';
                $typeBadge = Machine::getMachineTypeBadge($machineType);
                ?>

                <!-- 1. MACHINE STATUS / HEADER -->
                <div class="row g-4 mb-4">
                    <div class="col-12 col-lg-6">
                        <div class="mh-card">
                            <div class="mh-header">
                                <div class="mh-header-left">
                                    <div class="mh-icon-wrap">
                                        <i class="bi bi-hdd-network-fill mh-icon"></i>
                                    </div>
                                    <div class="mh-title-group">
                                        <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                            <h3 class="mh-name mb-0"><?= e($machine['name']) ?></h3>
                                            <span class="badge bg-<?= $typeBadge['color'] ?>-subtle text-<?= $typeBadge['color'] ?> border border-<?= $typeBadge['color'] ?> border-opacity-25 rounded-pill px-2.5 py-0.5" style="font-size: 0.72rem;">
                                                <i class="bi <?= $typeBadge['icon'] ?> me-1"></i><?= e($typeBadge['label']) ?>
                                            </span>
                                        </div>
                                        <span class="mh-code"><?= e($machine['machine_code']) ?> &bull; KEY: <?= e($machine['machine_key']) ?></span>
                                    </div>
                                </div>
                                <span class="badge bg-<?= $statusColor ?> rounded-pill mh-status-badge">
                                    <i class="bi bi-circle-fill me-1"></i><?= $statusLabel ?>
                                </span>
                            </div>

                            <div class="mh-grid">
                                <div class="mh-box">
                                    <span class="mh-box-label">ESP32 Controller</span>
                                    <span class="mh-box-status status-<?= $calcStatus === 'online' ? 'good' : ($calcStatus === 'stale' ? 'warning' : 'bad') ?>">
                                        <i class="bi bi-circle-fill fs-6"></i> <?= $calcStatus === 'online' ? 'Connected' : ($calcStatus === 'stale' ? 'Unstable' : 'Disconnected') ?>
                                    </span>
                                </div>
                                <div class="mh-box">
                                    <span class="mh-box-label">Wi-Fi</span>
                                    <span class="mh-box-status status-<?= $calcStatus === 'online' ? 'good' : ($calcStatus === 'stale' ? 'warning' : 'bad') ?>">
                                        <i class="bi bi-circle-fill fs-6"></i> <?= $calcStatus === 'online' ? 'Connected' : ($calcStatus === 'stale' ? 'Unstable' : 'Disconnected') ?>
                                    </span>
                                </div>
                                <div class="mh-box">
                                    <span class="mh-box-label">VendoLink Server</span>
                                    <span class="mh-box-status status-good">
                                        <i class="bi bi-circle-fill fs-6"></i> Connected
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <div class="mh-card">
                            <div class="mh-title"><i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>Alerts</div>
                            <div class="alert-box-container">
                                <?php if (empty($machineAlerts)): ?>
                                    <div class="alert-banner-ok">
                                        <i class="bi bi-check-circle-fill fs-5"></i>
                                        <span>No active issues</span>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($machineAlerts as $alert): ?>
                                        <div class="alert-banner-<?= $alert['type'] === 'danger' ? 'issue' : 'warning' ?>">
                                            <i class="bi <?= e($alert['icon']) ?> fs-5 mt-0.5"></i>
                                            <div>
                                                <strong class="d-block mb-0.5"><?= e($alert['title']) ?></strong>
                                                <span class="small opacity-90"><?= e($alert['message']) ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- UNIFIED MACHINE HARDWARE & SERVICE CONTROLLER PANEL -->
                <div class="report-section-card mb-4 text-light p-3 p-md-4 rounded-3" style="border: 1px solid rgba(56, 189, 248, 0.25); background: rgba(15, 23, 42, 0.75);">
                    <form id="unifiedHardwareForm" method="POST" action="">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_hardware_unified">
                        <input type="hidden" name="machine_id" value="<?= (int)$machine['id'] ?>">

                        <!-- Panel Header: Profile Switcher (Zero Emojis, Live Instant Change, No Apply Button) -->
                        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between pb-3 mb-3 border-bottom border-secondary border-opacity-25 gap-3">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                    <div class="p-2 rounded-3 text-info d-flex align-items-center justify-content-center" style="background: rgba(14,165,233,.12); border: 1px solid rgba(14,165,233,.25);">
                                        <i class="bi bi-hdd-rack fs-5"></i>
                                    </div>
                                    <h5 class="fw-bold mb-0 text-white">Machine Hardware &amp; Service Controller</h5>
                                    <span id="activeProfileBadge" class="badge bg-<?= $typeBadge['color'] ?>-subtle text-<?= $typeBadge['color'] ?> border border-<?= $typeBadge['color'] ?> border-opacity-25 rounded-pill px-2.5 py-0.5" style="font-size: 0.72rem;">
                                        Active: <?= e($typeBadge['label']) ?>
                                    </span>
                                </div>
                                <p class="text-slate-400 small mb-0 font-monospace" style="font-size: 0.78rem;">
                                    Single Physical Controller Enclosure &bull; Real-Time LCD &amp; Dual Outlets
                                </p>
                            </div>

                            <!-- Profile Switcher Dropdown -->
                            <div class="d-flex align-items-center">
                                <div class="input-group input-group-sm w-100 w-sm-auto">
                                    <span class="input-group-text bg-dark text-slate-300 border-secondary border-opacity-25 small fw-semibold">
                                        <i class="bi bi-sliders text-info me-1"></i> Profile
                                    </span>
                                    <select name="machine_template" id="machineTemplateSelect" class="form-select bg-dark text-light border-secondary border-opacity-25" style="min-width: 195px;">
                                        <option value="carwash" <?= $machineType === 'carwash' ? 'selected' : '' ?>>Carwash Vendo</option>
                                        <option value="air_blower" <?= $machineType === 'air_blower' ? 'selected' : '' ?>>Tire Air &amp; Blower</option>
                                        <option value="preset_1" <?= $machineType === 'preset_1' ? 'selected' : '' ?>>Preset 1 (Custom Dual)</option>
                                        <option value="preset_2" <?= $machineType === 'preset_2' ? 'selected' : '' ?>>Preset 2 (Custom Dual)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- 1. DIRECT-EDITABLE 2004A LCD DISPLAY (Single Display, Redundant Inputs Removed) -->
                        <div class="mb-4">
                            <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="bi bi-display text-info fs-6"></i>
                                    <span class="small fw-bold text-slate-200 text-uppercase letter-spacing-1">Physical 2004A LCD Display (Type Directly on Screen)</span>
                                </div>
                                <div class="d-flex align-items-center gap-1.5">
                                    <button type="button" id="btnResetToProfile" class="btn btn-outline-secondary btn-sm py-0.5 px-2 text-slate-300" style="font-size: 0.72rem;" title="Reset lines to selected profile defaults">
                                        <i class="bi bi-arrow-counterclockwise me-1 text-info"></i>Reset Defaults
                                    </button>
                                    <button type="button" id="btnClearLcd" class="btn btn-outline-secondary btn-sm py-0.5 px-2 text-slate-400" style="font-size: 0.72rem;" title="Clear all lines">
                                        <i class="bi bi-eraser me-1"></i>Clear
                                    </button>
                                </div>
                            </div>

                            <!-- Single Direct-Editable 2004A Bezel -->
                            <div class="lcd-matrix-box">
                                <div class="d-flex align-items-center justify-content-between pb-1.5 mb-2 border-bottom border-cyan-500 border-opacity-25 font-monospace text-info" style="font-size: 0.72rem;">
                                    <span class="d-flex align-items-center gap-1.5">
                                        <i class="bi bi-cpu"></i>HD44780 20x4 I2C MATRIX SCREEN (0x27)
                                    </span>
                                    <span class="badge bg-dark text-info border border-info border-opacity-50 font-monospace">DIRECT-EDIT LIVE</span>
                                </div>

                                <!-- 4 Directly Editable Lines (maxlength 20) -->
                                <div class="d-flex flex-column gap-2">
                                    <div class="position-relative d-flex align-items-center">
                                        <input type="text" maxlength="20" id="lcdLine1" name="lcd_line1" class="form-control form-control-sm lcd-direct-input text-uppercase px-3 py-2" value="<?= e($machine['lcd_line1'] ?? '== VendoLink v3.0 ==') ?>" autocomplete="off" spellcheck="false">
                                        <span id="cntLine1" class="position-absolute end-0 pe-3 font-monospace pointer-events-none" style="font-size: 0.7rem; color: rgba(103,232,249,0.5);">20/20</span>
                                    </div>

                                    <div class="position-relative d-flex align-items-center">
                                        <input type="text" maxlength="20" id="lcdLine2" name="lcd_line2" class="form-control form-control-sm lcd-direct-input text-uppercase px-3 py-2" value="<?= e($machine['lcd_line2'] ?? 'WELCOME CUSTOMER!') ?>" autocomplete="off" spellcheck="false">
                                        <span id="cntLine2" class="position-absolute end-0 pe-3 font-monospace pointer-events-none" style="font-size: 0.7rem; color: rgba(103,232,249,0.5);">20/20</span>
                                    </div>

                                    <div class="position-relative d-flex align-items-center">
                                        <input type="text" maxlength="20" id="lcdLine3" name="lcd_line3" class="form-control form-control-sm lcd-direct-input text-uppercase px-3 py-2" value="<?= e($machine['lcd_line3'] ?? 'INSERT COIN FIRST!') ?>" autocomplete="off" spellcheck="false">
                                        <span id="cntLine3" class="position-absolute end-0 pe-3 font-monospace pointer-events-none" style="font-size: 0.7rem; color: rgba(103,232,249,0.5);">20/20</span>
                                    </div>

                                    <div class="position-relative d-flex align-items-center">
                                        <input type="text" maxlength="20" id="lcdLine4" name="lcd_line4" class="form-control form-control-sm lcd-direct-input text-uppercase px-3 py-2" value="<?= e($machine['lcd_line4'] ?? 'WATER:P5 | SOAP:P5') ?>" autocomplete="off" spellcheck="false">
                                        <span id="cntLine4" class="position-absolute end-0 pe-3 font-monospace pointer-events-none" style="font-size: 0.7rem; color: rgba(103,232,249,0.5);">20/20</span>
                                    </div>
                                </div>

                                <div class="d-flex align-items-center justify-content-between text-muted small mt-2 pt-1 border-top border-cyan-500 border-opacity-25 font-monospace" style="font-size: 0.72rem; color: rgba(103,232,249,0.7) !important;">
                                    <span><i class="bi bi-cursor-text me-1 text-info"></i>Click any line on the physical screen above to edit text directly</span>
                                    <span><i class="bi bi-wifi text-info me-1"></i>Syncs with ESP32 screen over Wi-Fi</span>
                                </div>
                            </div>
                        </div>

                        <!-- Divider -->
                        <hr class="border-secondary border-opacity-25 my-4">

                        <!-- 2. DUAL SWITCHED OUTLETS WITH 4 SQUIRCLE ICONS -->
                        <div class="mb-4">
                            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="bi bi-plug-fill text-info fs-5"></i>
                                    <h5 class="fw-bold mb-0 text-white">Dual Switched Outlets &bull; Selectable Icons</h5>
                                </div>
                                <div class="text-success small font-monospace d-flex align-items-center gap-1">
                                    <i class="bi bi-shield-check"></i>Float Sensors: <span id="floaterStatusText"><?= $machineType === 'carwash' ? 'Active (Water & Soap Tank Protection)' : 'Disabled (Auto-Off • Unplugged Safe)' ?></span>
                                </div>
                            </div>

                            <div class="row g-4">
                            <?php foreach ($machine['services'] as $idx => $service):
                                $outletNum = $idx + 1;
                                $gpioPin = ($outletNum === 1) ? 25 : 33;
                                $serviceId = (int)$service['id'];
                                $serviceKey = e($service['service_key']);
                                $serviceTitle = !empty($service['name']) ? $service['name'] : ($outletNum === 1 ? 'Water Service' : 'Soap Service');
                                $serviceType = $service['type'] ?? ($outletNum === 1 ? 'water' : 'soap');
                                $serviceIcon = $service['icon'] ?? ($outletNum === 1 ? 'water' : 'soap');
                                $isRunning = (bool)($service['is_running'] ?? false);
                                $maintenanceSummary = $maintenanceSummaries[$service['service_key']] ?? null;
                            ?>
                                <div class="col-12 col-lg-6">
                                    <div class="service-section-box p-3 p-md-4 rounded-3 h-100" style="background: rgba(15,23,42,0.85); border: 1px solid rgba(148,163,184,0.15); overflow: visible !important;" data-service-section="<?= $serviceKey ?>">
                                        <input type="hidden" name="services[<?= $serviceId ?>][id]" value="<?= $serviceId ?>">
                                        <input type="hidden" name="services[<?= $serviceId ?>][key]" value="<?= $serviceKey ?>">
                                        <input type="hidden" name="services[<?= $serviceId ?>][coin_value]" value="<?= number_format((float)($service['coin_value'] ?? 5.0), 2, '.', '') ?>">
                                        <input type="hidden" name="services[<?= $serviceId ?>][icon]" id="out<?= $outletNum ?>InputIcon" value="<?= e($serviceIcon) ?>">
                                        <input type="hidden" name="services[<?= $serviceId ?>][type]" id="out<?= $outletNum ?>InputType" value="<?= e($serviceType) ?>">

                                        <!-- Outlet Card Header: Squircle Icon Badge + Name & GPIO -->
                                        <div class="d-flex align-items-start justify-content-between mb-3 gap-2">
                                            <div class="d-flex align-items-center" style="gap: 16px;">
                                                <div class="position-relative" style="z-index: 1020;">
                                                    <button type="button" onclick="toggleIconOverlay(<?= $outletNum ?>)" id="out<?= $outletNum ?>IconBadge" class="squircle-badge shadow-sm position-relative border-0" style="background: rgba(<?= $outletNum === 1 ? '6,182,212' : '245,158,11' ?>,.15); border: 1px solid rgba(<?= $outletNum === 1 ? '6,182,212' : '245,158,11' ?>,.35) !important; color: <?= $outletNum === 1 ? '#22d3ee' : '#fbbf24' ?>; cursor: pointer;" title="Click to choose a new icon">
                                                        <?php if ($serviceIcon === 'water'): ?>
                                                            <i class="bi bi-droplet-fill fs-5"></i>
                                                        <?php elseif ($serviceIcon === 'soap'): ?>
                                                            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                                <circle cx="9.5" cy="14.5" r="5" fill="rgba(245, 158, 11, 0.25)"/>
                                                                <path d="M8 12.5a2 2 0 0 1 2-1" stroke="#ffffff" stroke-width="1.2"/>
                                                                <circle cx="16" cy="8.5" r="3.5" fill="rgba(245, 158, 11, 0.25)"/>
                                                                <circle cx="6" cy="6" r="2" fill="rgba(245, 158, 11, 0.2)"/>
                                                            </svg>
                                                        <?php elseif ($serviceIcon === 'tire_air'): ?>
                                                            <i class="bi bi-speedometer2 fs-5"></i>
                                                        <?php elseif ($serviceIcon === 'blower'): ?>
                                                            <i class="bi bi-wind fs-5"></i>
                                                        <?php else: ?>
                                                            <i class="bi bi-droplet-fill fs-5"></i>
                                                        <?php endif; ?>
                                                        <span class="position-absolute rounded-circle d-flex align-items-center justify-content-center bg-dark border border-secondary text-info shadow" style="width: 16px; height: 16px; bottom: -3px; right: -3px; font-size: 8px;">
                                                            <i class="bi bi-pencil-fill"></i>
                                                        </span>
                                                    </button>

                                                    <!-- Floating Overlay: Select New Icon Popover -->
                                                    <div id="out<?= $outletNum ?>Overlay" onclick="if(window.event)window.event.stopPropagation();" class="icon-overlay-popover hidden-popover position-absolute p-2.5 rounded-3 border border-cyan-500 border-opacity-50 shadow-lg" style="top: 50px; left: 0; width: 240px !important; min-width: 240px !important; max-width: none !important; z-index: 1050; background: #0b1324 !important;">
                                                        <div class="d-flex align-items-center justify-content-between pb-1.5 mb-2 border-bottom border-secondary border-opacity-25" style="font-size: 0.72rem;">
                                                            <span class="fw-bold text-info d-flex align-items-center gap-1 font-monospace">
                                                                <i class="bi bi-palette"></i> Select New Icon
                                                            </span>
                                                            <button type="button" onclick="closeIconOverlay(<?= $outletNum ?>)" class="btn btn-sm btn-link text-muted p-0 text-decoration-none">
                                                                <i class="bi bi-x-lg" style="font-size: 0.7rem;"></i>
                                                            </button>
                                                        </div>
                                                        <div class="row g-1" style="margin: 0;">
                                                            <div class="col-6 p-1">
                                                                <button type="button" onclick="selectOutletIcon(<?= $outletNum ?>, 'water')" class="btn btn-sm w-100 d-flex align-items-center gap-1.5 p-1.5 rounded-2 text-start <?= $serviceIcon === 'water' ? 'btn-info text-dark fw-bold' : 'btn-dark text-slate-300 border border-secondary border-opacity-25' ?>" style="font-size: 0.75rem;">
                                                                    <i class="bi bi-droplet-fill fs-6"></i>
                                                                    <span>Water</span>
                                                                </button>
                                                            </div>
                                                            <div class="col-6 p-1">
                                                                <button type="button" onclick="selectOutletIcon(<?= $outletNum ?>, 'soap')" class="btn btn-sm w-100 d-flex align-items-center gap-1.5 p-1.5 rounded-2 text-start <?= $serviceIcon === 'soap' ? 'btn-warning text-dark fw-bold' : 'btn-dark text-slate-300 border border-secondary border-opacity-25' ?>" style="font-size: 0.75rem;">
                                                                    <i class="bi bi-stars fs-6"></i>
                                                                    <span>Soap</span>
                                                                </button>
                                                            </div>
                                                            <div class="col-6 p-1">
                                                                <button type="button" onclick="selectOutletIcon(<?= $outletNum ?>, 'tire_air')" class="btn btn-sm w-100 d-flex align-items-center gap-1.5 p-1.5 rounded-2 text-start <?= $serviceIcon === 'tire_air' ? 'btn-info text-dark fw-bold' : 'btn-dark text-slate-300 border border-secondary border-opacity-25' ?>" style="font-size: 0.75rem;">
                                                                    <i class="bi bi-speedometer2 fs-6"></i>
                                                                    <span>Tire Air</span>
                                                                </button>
                                                            </div>
                                                            <div class="col-6 p-1">
                                                                <button type="button" onclick="selectOutletIcon(<?= $outletNum ?>, 'blower')" class="btn btn-sm w-100 d-flex align-items-center gap-1.5 p-1.5 rounded-2 text-start <?= $serviceIcon === 'blower' ? 'btn-warning text-dark fw-bold' : 'btn-dark text-slate-300 border border-secondary border-opacity-25' ?>" style="font-size: 0.75rem;">
                                                                    <i class="bi bi-wind fs-6"></i>
                                                                    <span>Blower</span>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div>
                                                    <input type="text" name="services[<?= $serviceId ?>][name]" id="out<?= $outletNum ?>InputName" class="form-control form-control-sm bg-dark text-light border-secondary border-opacity-25 fw-bold px-3 py-1.5 mb-1.5" style="min-width: 180px; max-width: 220px; font-size: 0.95rem;" value="<?= e($serviceTitle) ?>" title="Edit service display name">
                                                    <span class="badge font-monospace" style="background: rgba(<?= $outletNum === 1 ? '6,182,212' : '245,158,11' ?>,.15); border: 1px solid rgba(<?= $outletNum === 1 ? '6,182,212' : '245,158,11' ?>,.35); color: <?= $outletNum === 1 ? '#22d3ee' : '#fbbf24' ?>; font-size: 0.72rem;">
                                                        <i class="bi bi-plug-fill me-0.5"></i>Outlet <?= $outletNum ?> (GPIO <?= $gpioPin ?>)
                                                    </span>
                                                </div>
                                            </div>

                                            <!-- Compact Integrated Toggle Switch (Zero Text) -->
                                            <label class="integrated-switch" title="<?= $service['is_enabled'] ? 'Outlet enabled' : 'Outlet disabled' ?>">
                                                <input type="checkbox" class="checkbox service-enable-toggle" id="enabled-<?= $serviceId ?>" name="services[<?= $serviceId ?>][is_enabled]" role="switch" aria-label="Enable <?= e($service['name']) ?>" <?= $service['is_enabled'] ? 'checked' : '' ?>>
                                                <span class="integrated-slider">
                                                    <span class="integrated-knob"></span>
                                                </span>
                                            </label>
                                        </div>

                                        <!-- Runtime & Session Grid -->
                                        <div class="service-runtime-grid runtime-summary mb-3">
                                            <div><span><?= term('current_session_time', 'Current Session Time') ?></span><strong><?= $isRunning ? runtime_clock_label((int) ($service['remaining_runtime_seconds'] ?? 0)) : '00:00' ?></strong></div>
                                            <div><span><?= term('total_runtime', 'Total Runtime') ?></span><strong><?= service_runtime_label((int) ($service['runtime_seconds'] ?? 0)) ?></strong></div>
                                            <div><span><?= term('machine_status', 'Machine Status') ?></span><strong class="runtime-status <?= $isRunning ? 'runtime-running' : '' ?>"><?= $isRunning ? 'Running' : 'Idle' ?></strong></div>
                                        </div>

                                        <!-- Maintenance Summary (if configured) -->
                                        <?php if ($maintenanceSummary): ?>
                                        <div class="service-maintenance-summary mb-3">
                                            <div class="maintenance-summary-heading"><i class="bi bi-wrench-adjustable text-info me-1"></i><?= term('maintenance', 'Maintenance') ?></div>
                                            <p class="mb-1"><strong class="maintenance-inline-status status-<?= e($maintenanceSummary['status']) ?>"><?= $maintenanceSummary['status'] === 'soon' ? 'Upcoming' : ($maintenanceSummary['status'] === 'due' || $maintenanceSummary['status'] === 'overdue' ? 'Due' : strtoupper(e($maintenanceSummary['status']))) ?></strong> <?= e($maintenanceSummary['name']) ?></p>
                                            <p class="mb-0">Next maintenance: <?= $maintenanceSummary['next_due_date'] ? e($maintenanceSummary['next_due_date']->format('M j, Y')) : 'Not date-based' ?></p>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Stepper Timer -->
                                        <div class="config-field border-bottom-0 pb-0">
                                            <div>
                                                <label><?= term('timer', 'Timer') ?></label>
                                                <p class="config-help">Machine runtime per ₱5 coin inserted.</p>
                                            </div>
                                            <div class="stepper time-stepper" data-step="10" data-min="10" data-max="600" data-service-name="<?= e($serviceTitle) ?>">
                                                <button type="button" class="stepper-btn" data-stepper="down" aria-label="Decrease service time for <?= e($serviceTitle) ?>"><i class="bi bi-dash-lg"></i></button>
                                                <input name="services[<?= $serviceId ?>][service_seconds]" id="out<?= $outletNum ?>InputSeconds" type="hidden" value="<?= (int) $service['service_seconds'] ?>">
                                                <input class="time-entry" type="text" inputmode="numeric" value="<?= service_time_label((int) $service['service_seconds']) ?>" aria-label="Service time in minutes and seconds for <?= e($serviceTitle) ?>" title="Enter time as MM:SS">
                                                <button type="button" class="stepper-btn" data-stepper="up" aria-label="Increase service time for <?= e($serviceTitle) ?>"><i class="bi bi-plus-lg"></i></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- 3. ACTION BAR: CANCEL + SINGLE SAVE BUTTON -->
                        <div class="pt-3 border-top border-secondary border-opacity-25 d-flex align-items-center justify-content-end flex-wrap gap-2">
                            <a href="<?= BASE_URL ?>/dashboard/machines.php" class="btn btn-outline-secondary px-3.5 py-2">
                                <i class="bi bi-x-circle me-1"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-info text-dark fw-bold px-4 py-2" id="unifiedMasterSaveBtn" title="<?= term('save_changes', 'Save Changes') ?>">
                                <i class="bi bi-save2-fill me-1"></i>Save Configuration as <span id="saveBtnPresetLabel"><?= e($typeBadge['label']) ?></span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- RECENT MACHINE CONFIGURATION ACTIVITY -->
                <section class="activity-card">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h5 class="fw-bold mb-0 text-light"><i class="bi bi-sliders text-info me-2"></i><?= term('machine_configuration', 'Machine Configuration') ?></h5>
                        <a href="<?= BASE_URL ?>/dashboard/archive.php?tab=configuration" class="btn-view-activity text-decoration-none d-inline-flex align-items-center">
                            <?= term('view_more', 'View More') ?>
                        </a>
                    </div>
                    <div class="activity-list">
                        <?php if (empty($recentActivities)): ?>
                            <div class="text-center py-3 text-slate-400 small">No recent machine activity recorded.</div>
                        <?php else: ?>
                            <?php foreach ($recentActivities as $act): ?>
                                <div class="activity-item-row">
                                    <div class="activity-item-content">
                                        <i class="bi <?= $act['event_info']['icon'] ?> fs-6"></i>
                                        <span class="activity-item-title"><?= e($act['event_info']['title']) ?></span>
                                    </div>
                                    <span class="activity-item-time"><?= e($act['formatted_time']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

            <?php endif; ?>
        </main>
        <footer class="app-footer"><span><?= APP_NAME ?> v<?= APP_VERSION ?> &copy; <?= date('Y') ?></span><span>Powered by VendoCore IoT Controller</span></footer>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= ASSETS_URL ?>/js/app.js?v=<?= e((string) filemtime(ROOT_PATH . '/assets/js/app.js')) ?>"></script>
<script src="<?= ASSETS_URL ?>/js/machine-config.js?v=<?= e((string) filemtime(ROOT_PATH . '/assets/js/machine-config.js')) ?>"></script>

<!-- Direct-Editable 2004A LCD & Hardware Profile Controller Script -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const inputs = [
        { input: document.getElementById('lcdLine1'), count: document.getElementById('cntLine1') },
        { input: document.getElementById('lcdLine2'), count: document.getElementById('cntLine2') },
        { input: document.getElementById('lcdLine3'), count: document.getElementById('cntLine3') },
        { input: document.getElementById('lcdLine4'), count: document.getElementById('cntLine4') }
    ];

    function updateLineCount(item) {
        if (!item.input || !item.count) return;
        let text = item.input.value;
        if (text.length > 20) {
            text = text.substring(0, 20);
            item.input.value = text;
        }
        item.count.textContent = text.length + '/20';
    }

    inputs.forEach(function (item) {
        if (item.input) {
            item.input.addEventListener('input', function () { updateLineCount(item); });
            updateLineCount(item);
        }
    });

    const iconTemplates = {
        water: {
            html: '<i class="bi bi-droplet-fill fs-5"></i>',
            style: 'background: rgba(6,182,212,.15); border: 1px solid rgba(6,182,212,.35); color: #22d3ee;',
            activeClass: 'btn-info text-dark fw-bold',
            type: 'water'
        },
        soap: {
            html: '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9.5" cy="14.5" r="5" fill="rgba(245, 158, 11, 0.25)"/><path d="M8 12.5a2 2 0 0 1 2-1" stroke="#ffffff" stroke-width="1.2"/><circle cx="16" cy="8.5" r="3.5" fill="rgba(245, 158, 11, 0.25)"/><circle cx="6" cy="6" r="2" fill="rgba(245, 158, 11, 0.2)"/></svg>',
            style: 'background: rgba(245,158,11,.15); border: 1px solid rgba(245,158,11,.35); color: #fbbf24;',
            activeClass: 'btn-warning text-dark fw-bold',
            type: 'soap'
        },
        tire_air: {
            html: '<i class="bi bi-speedometer2 fs-5"></i>',
            style: 'background: rgba(6,182,212,.15); border: 1px solid rgba(6,182,212,.35); color: #22d3ee;',
            activeClass: 'btn-info text-dark fw-bold',
            type: 'air'
        },
        blower: {
            html: '<i class="bi bi-wind fs-5"></i>',
            style: 'background: rgba(245,158,11,.15); border: 1px solid rgba(245,158,11,.35); color: #fbbf24;',
            activeClass: 'btn-warning text-dark fw-bold',
            type: 'blower'
        }
    };

    window.toggleIconOverlay = function (outletNum) {
        if (window.event) {
            window.event.preventDefault();
            window.event.stopPropagation();
        }
        const overlay = document.getElementById('out' + outletNum + 'Overlay');
        const otherNum = outletNum === 1 ? 2 : 1;
        window.closeIconOverlay(otherNum);
        if (overlay) {
            if (overlay.classList.contains('hidden-popover')) {
                overlay.classList.remove('hidden-popover');
                overlay.classList.add('show-popover');
            } else {
                window.closeIconOverlay(outletNum);
            }
        }
    };

    window.closeIconOverlay = function (outletNum) {
        const overlay = document.getElementById('out' + outletNum + 'Overlay');
        if (overlay) {
            overlay.classList.remove('show-popover');
            overlay.classList.add('hidden-popover');
        }
    };

    window.selectOutletIcon = function (outletNum, iconKey) {
        if (window.event) {
            window.event.preventDefault();
            window.event.stopPropagation();
        }
        const tmpl = iconTemplates[iconKey];
        if (!tmpl) return;

        // Set hidden input values
        const iconInput = document.getElementById('out' + outletNum + 'InputIcon');
        const typeInput = document.getElementById('out' + outletNum + 'InputType');
        if (iconInput) iconInput.value = iconKey;
        if (typeInput) typeInput.value = tmpl.type;

        // Update squircle badge preserving the edit pencil badge
        const badge = document.getElementById('out' + outletNum + 'IconBadge');
        if (badge) {
            badge.innerHTML = tmpl.html + '<span class="position-absolute rounded-circle d-flex align-items-center justify-content-center bg-dark border border-secondary text-info shadow" style="width: 16px; height: 16px; bottom: -3px; right: -3px; font-size: 8px;"><i class="bi bi-pencil-fill"></i></span>';
            badge.style.cssText = tmpl.style + ' cursor: pointer;';
            badge.setAttribute('style', tmpl.style + ' cursor: pointer;');
        }

        // Close overlay popover automatically upon selection
        window.closeIconOverlay(outletNum);
    };

    // Close overlays when clicking outside
    document.addEventListener('click', function (e) {
        [1, 2].forEach(function (num) {
            const badge = document.getElementById('out' + num + 'IconBadge');
            const overlay = document.getElementById('out' + num + 'Overlay');
            if (badge && overlay && !badge.contains(e.target) && !overlay.contains(e.target)) {
                window.closeIconOverlay(num);
            }
        });
    });

    const profilePresets = {
        carwash: {
            label: 'Carwash Vendo',
            lines: [
                '== KENJI CARWASH ==',
                'WELCOME CUSTOMER!',
                'INSERT COIN FIRST!',
                'WATER:P5 | SOAP:P5'
            ],
            floater: 'Active (Water & Soap Tank Protection)',
            outlets: [
                { name: 'Water Service', icon: 'water', type: 'water' },
                { name: 'Soap Service', icon: 'soap', type: 'soap' }
            ]
        },
        air_blower: {
            label: 'Tire Air & Blower',
            lines: [
                '== AIR & BLOWER ==',
                'AIR & BLOWER READY!',
                'INSERT COIN FIRST!',
                'AIR:P5  | BLOWER:P5'
            ],
            floater: 'Disabled (Auto-Off \u2022 Unplugged Safe)',
            outlets: [
                { name: 'Tire Air Compressor', icon: 'tire_air', type: 'air' },
                { name: 'Car Blower Fan', icon: 'blower', type: 'blower' }
            ]
        },
        preset_1: {
            label: 'Preset 1 (Custom Dual)',
            lines: [
                '== CUSTOM PRESET 1==',
                'SELECT YOUR SERVICE ',
                'INSERT P5 COIN NOW  ',
                'FAST DUAL OPERATION '
            ],
            floater: 'Disabled (Auto-Off \u2022 Unplugged Safe)',
            outlets: [
                { name: 'Custom Outlet 1', icon: 'water', type: 'water' },
                { name: 'Custom Outlet 2', icon: 'soap', type: 'soap' }
            ]
        },
        preset_2: {
            label: 'Preset 2 (Custom Dual)',
            lines: [
                '== CUSTOM PRESET 2==',
                'SELECT YOUR SERVICE ',
                'INSERT P5 COIN NOW  ',
                'FAST DUAL OPERATION '
            ],
            floater: 'Disabled (Auto-Off \u2022 Unplugged Safe)',
            outlets: [
                { name: 'Custom Outlet 1', icon: 'tire_air', type: 'air' },
                { name: 'Custom Outlet 2', icon: 'blower', type: 'blower' }
            ]
        }
    };

    function applyPreset(presetKey) {
        const preset = profilePresets[presetKey];
        if (!preset) return;

        // Update labels
        const saveBtnLabel = document.getElementById('saveBtnPresetLabel');
        if (saveBtnLabel) saveBtnLabel.textContent = preset.label;

        const saveTargetLabel = document.getElementById('saveTargetLabel');
        if (saveTargetLabel) saveTargetLabel.textContent = preset.label;

        const activeProfileBadge = document.getElementById('activeProfileBadge');
        if (activeProfileBadge) activeProfileBadge.textContent = 'Active: ' + preset.label;

        const floaterStatusText = document.getElementById('floaterStatusText');
        if (floaterStatusText) floaterStatusText.textContent = preset.floater;

        // Populate LCD lines
        preset.lines.forEach(function (text, i) {
            if (inputs[i] && inputs[i].input) {
                inputs[i].input.value = text;
                updateLineCount(inputs[i]);
            }
        });

        // Populate Outlets
        if (preset.outlets && preset.outlets.length >= 2) {
            const out1Name = document.getElementById('out1InputName');
            if (out1Name) out1Name.value = preset.outlets[0].name;
            window.selectOutletIcon(1, preset.outlets[0].icon);

            const out2Name = document.getElementById('out2InputName');
            if (out2Name) out2Name.value = preset.outlets[1].name;
            window.selectOutletIcon(2, preset.outlets[1].icon);
        }
    }

    const machineTemplateSelect = document.getElementById('machineTemplateSelect');
    if (machineTemplateSelect) {
        machineTemplateSelect.addEventListener('change', function () {
            applyPreset(this.value);
        });
    }

    const btnResetToProfile = document.getElementById('btnResetToProfile');
    if (btnResetToProfile) {
        btnResetToProfile.addEventListener('click', function () {
            const currentVal = machineTemplateSelect ? machineTemplateSelect.value : '<?= e($machineType) ?>';
            applyPreset(currentVal);
        });
    }

    const btnClearLcd = document.getElementById('btnClearLcd');
    if (btnClearLcd) {
        btnClearLcd.addEventListener('click', function () {
            inputs.forEach(function (item) {
                if (item.input) {
                    item.input.value = '';
                    updateLineCount(item);
                }
            });
        });
    }
});
</script>
</body>
</html>
