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
    if (($_POST['action'] ?? '') === 'save_lcd_config') {
        (new MachineController())->saveLcdConfiguration();
    } else {
        (new MachineController())->saveServiceConfiguration();
    }
}

$pageTitle = term('machines', 'Machines');
$currentPage = 'machines';
$user = current_user();

$ownerId = current_owner_id();
$machineModel = new Machine();
$machine = $machineModel->primaryWithServices($ownerId);
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

    <!-- Scoped LCD 2004A Simulator Styles -->
    <style>
        .lcd-sim-card {
            background: #0f172a;
            border: 1px solid rgba(148, 163, 184, 0.12);
            border-radius: 14px;
            padding: 1.25rem;
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5);
        }
        .lcd-matrix-box {
            background: #061826;
            border: 3px solid #0284c7;
            border-radius: 10px;
            padding: 1rem;
            box-shadow: inset 0 0 15px rgba(2, 132, 199, 0.4);
            font-family: 'Consolas', 'Courier New', monospace;
            color: #38bdf8;
            letter-spacing: 0.12em;
            line-height: 1.6;
            text-shadow: 0 0 8px rgba(56, 189, 248, 0.6);
        }
        .lcd-line {
            height: 1.6rem;
            white-space: pre;
            overflow: hidden;
            border-bottom: 1px dashed rgba(56, 189, 248, 0.15);
        }
        .lcd-line:last-child {
            border-bottom: none;
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
            <div class="page-header mb-4">
                <div>
                    <h1 class="page-title"><i class="bi bi-hdd-stack me-2"></i><?= term('machines') ?></h1>
                    <p class="page-subtitle">Configure services and display settings for your machine.</p>
                </div>
            </div>

            <?php if (!$machine): ?>
                <div class="alert alert-warning">Phase 2 database setup is required. Import <code>database/schema_v2_step1.sql</code> first.</div>
            <?php else: ?>
                <?php
                $calcStatus = $machine['calculated_status'] ?? 'offline';
                $statusColor = ($calcStatus === 'online') ? 'success' : 'danger';
                $statusLabel = ($calcStatus === 'online') ? term('online') : term('offline');
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
                                        <h3 class="mh-name"><?= e($machine['name']) ?></h3>
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

                <!-- 📺 20x4 LCD SCREEN REAL-TIME SIMULATOR & CUSTOMIZER -->
                <div class="lcd-sim-card mb-4 text-light">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-3 gap-2">
                        <div>
                            <h5 class="fw-bold mb-1 text-light"><i class="bi bi-display text-info me-2"></i>20x4 LCD Screen Live Simulator & Customizer</h5>
                            <p class="text-slate-400 small mb-0">Design, preview, and format your physical LCD 2004A screen display in real-time.</p>
                        </div>
                        <div>
                            <select id="lcdTemplateSelect" class="form-select form-select-sm bg-dark text-info border-secondary border-opacity-25" style="width: auto;">
                                <option value="">-- Select Preset Template --</option>
                                <option value="carwash">🚗 Carwash Vendo Template</option>
                                <option value="blank">⚡ Blank / Custom</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-4">
                        <!-- Physical LCD 2004A Simulator Display Box -->
                        <div class="col-12 col-lg-5">
                            <div class="p-3 rounded-3 bg-dark border border-secondary border-opacity-25 shadow-lg h-100">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="small font-monospace text-slate-400"><i class="bi bi-cpu me-1"></i>PHYSICAL 2004A DISPLAY</span>
                                    <span class="badge badge-dark-blue">REAL-TIME LIVE</span>
                                </div>
                                <!-- Green/Blue Matrix Display Box -->
                                <div class="lcd-matrix-box mt-2">
                                    <div class="lcd-line" id="simLine1"><?= e(str_pad(mb_substr($machine['lcd_line1'] ?? '== VendoLink v2.2 ==', 0, 20), 20, ' ')) ?></div>
                                    <div class="lcd-line" id="simLine2"><?= e(str_pad(mb_substr($machine['lcd_line2'] ?? 'WELCOME CUSTOMER!', 0, 20), 20, ' ')) ?></div>
                                    <div class="lcd-line" id="simLine3"><?= e(str_pad(mb_substr($machine['lcd_line3'] ?? 'INSERT COIN FIRST!', 0, 20), 20, ' ')) ?></div>
                                    <div class="lcd-line" id="simLine4"><?= e(str_pad(mb_substr($machine['lcd_line4'] ?? 'WATER:P5 | SOAP:P5', 0, 20), 20, ' ')) ?></div>
                                </div>
                                <div class="small text-slate-400 text-center mt-2" style="font-size: 0.72rem;">
                                    <i class="bi bi-info-circle me-1"></i>Syncs with physical ESP32 screen over Wi-Fi on every heartbeat.
                                </div>
                            </div>
                        </div>

                        <!-- Customizer Input Form -->
                        <div class="col-12 col-lg-7">
                            <form method="POST" id="lcdForm" data-confirm-save="true" data-confirm-message="Are you sure you want to save and push these custom 20x4 LCD lines to your physical ESP32 screen?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="save_lcd_config">
                                <input type="hidden" name="machine_id" value="<?= (int)$machine['id'] ?>">

                                <!-- Line 1 Input -->
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label text-slate-300 small fw-bold mb-0">LINE 1 (HEADER / TITLE)</label>
                                        <span class="badge bg-dark text-info border border-info border-opacity-25 px-2 py-1" id="countLine1">0/20</span>
                                    </div>
                                    <input type="text" maxlength="20" id="inputLine1" name="lcd_line1" class="form-control form-control-sm bg-dark text-light border-secondary border-opacity-25 font-monospace px-3 py-2" value="<?= e($machine['lcd_line1'] ?? '== VendoLink v2.2 ==') ?>">
                                </div>

                                <!-- Line 2 Input -->
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label text-slate-300 small fw-bold mb-0">LINE 2 (WELCOME / STATUS)</label>
                                        <span class="badge bg-dark text-info border border-info border-opacity-25 px-2 py-1" id="countLine2">0/20</span>
                                    </div>
                                    <input type="text" maxlength="20" id="inputLine2" name="lcd_line2" class="form-control form-control-sm bg-dark text-light border-secondary border-opacity-25 font-monospace px-3 py-2" value="<?= e($machine['lcd_line2'] ?? 'WELCOME CUSTOMER!') ?>">
                                </div>

                                <!-- Line 3 Input -->
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label text-slate-300 small fw-bold mb-0">LINE 3 (PROMPT / INSTRUCTION)</label>
                                        <span class="badge bg-dark text-info border border-info border-opacity-25 px-2 py-1" id="countLine3">0/20</span>
                                    </div>
                                    <input type="text" maxlength="20" id="inputLine3" name="lcd_line3" class="form-control form-control-sm bg-dark text-light border-secondary border-opacity-25 font-monospace px-3 py-2" value="<?= e($machine['lcd_line3'] ?? 'INSERT COIN FIRST!') ?>">
                                </div>

                                <!-- Line 4 Input -->
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label text-slate-300 small fw-bold mb-0">LINE 4 (PRICING / RATES)</label>
                                        <span class="badge bg-dark text-info border border-info border-opacity-25 px-2 py-1" id="countLine4">0/20</span>
                                    </div>
                                    <input type="text" maxlength="20" id="inputLine4" name="lcd_line4" class="form-control form-control-sm bg-dark text-light border-secondary border-opacity-25 font-monospace px-3 py-2" value="<?= e($machine['lcd_line4'] ?? 'WATER:P5 | SOAP:P5') ?>">
                                </div>

                                <button type="submit" class="btn btn-info w-100 fw-bold py-2 text-dark">
                                    <i class="bi bi-send-check-fill me-2"></i>Save &amp; Push Template to Physical Screen
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- UNIFIED SERVICE CONFIGURATION PANEL -->
                <form id="unifiedServiceForm" class="service-config-card unified-service-panel mb-4" method="POST" data-confirm-save="true" data-confirm-message="Are you sure you want to save the updated service configuration?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_service_config">

                        <div class="service-panel-header d-flex flex-wrap align-items-center justify-content-between pb-3 mb-4 border-bottom border-secondary border-opacity-25 gap-2">
                            <div class="d-flex align-items-center gap-2.5">
                                <div class="service-panel-icon me-2">
                                    <i class="bi bi-sliders text-info fs-5"></i>
                                </div>
                                <div>
                                    <h4 class="service-panel-title fw-bold text-light mb-0">Service Configuration</h4>
                                    <p class="text-slate-400 small mb-0">Configure service operation rates, hardware toggles, and runtime limits.</p>
                                </div>
                            </div>
                            <div class="d-flex align-items-center">
                                <span class="save-state mb-0" id="unifiedSaveState" aria-live="polite">No changes yet.</span>
                            </div>
                        </div>

                        <div class="row g-4">
                        <?php foreach ($machine['services'] as $service):
                            $isWater = $service['service_key'] === 'water';
                            $serviceTitle = $isWater ? 'Water Service' : 'Soap Service';
                            $serviceIcon = $isWater ? 'bi-droplet-fill text-info' : 'bi-sparkles text-warning';
                            $isRunning = (bool) ($service['is_running'] ?? false);
                            $maintenanceSummary = $maintenanceSummaries[$service['service_key']] ?? null;
                            $serviceKey = e($service['service_key']);
                            $serviceId = (int) $service['id'];
                        ?>
                            <div class="col-12 col-lg-6">
                                <div class="service-section-box p-3 p-md-4 rounded-3 h-100" data-service-section="<?= $serviceKey ?>">
                                    <input type="hidden" name="services[<?= $serviceId ?>][id]" value="<?= $serviceId ?>">
                                    <input type="hidden" name="services[<?= $serviceId ?>][name]" value="<?= e($serviceTitle) ?>">
                                    <input type="hidden" name="services[<?= $serviceId ?>][key]" value="<?= $serviceKey ?>">
                                    <input type="hidden" name="services[<?= $serviceId ?>][coin_value]" value="5.00">

                                    <div class="service-card-header mb-3">
                                        <div class="service-card-title-group">
                                            <?php if ($isWater): ?>
                                                <div class="service-icon-badge service-icon-water" title="Water Service">
                                                    <i class="bi bi-droplet-fill"></i>
                                                </div>
                                            <?php else: ?>
                                                <div class="service-icon-badge service-icon-soap" title="Soap Service &amp; Foam Bubbles">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="bubble-logo-svg">
                                                        <!-- Main Center Bubble -->
                                                        <circle cx="9.5" cy="14.5" r="5.5" fill="rgba(245, 158, 11, 0.25)" stroke="#f59e0b" stroke-width="1.8"/>
                                                        <!-- Glossy Bubble Highlight Arc -->
                                                        <path d="M8 12.5a2 2 0 0 1 2.2-1.2" stroke="#ffffff" stroke-width="1.5" stroke-linecap="round"/>
                                                        <!-- Secondary Bubble Top Right -->
                                                        <circle cx="16.5" cy="8.5" r="4" fill="rgba(245, 158, 11, 0.25)" stroke="#f59e0b" stroke-width="1.8"/>
                                                        <path d="M15.2 7.2a1.4 1.4 0 0 1 1.5-.8" stroke="#ffffff" stroke-width="1.2" stroke-linecap="round"/>
                                                        <!-- Small Bubble Top Left -->
                                                        <circle cx="6" cy="6" r="2.5" fill="rgba(245, 158, 11, 0.2)" stroke="#f59e0b" stroke-width="1.5"/>
                                                        <!-- Floating Micro-bubble -->
                                                        <circle cx="18" cy="16.5" r="2" fill="rgba(245, 158, 11, 0.2)" stroke="#f59e0b" stroke-width="1.5"/>
                                                    </svg>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <h5 class="service-card-title mb-0 fw-bold text-light"><?= e($serviceTitle) ?></h5>
                                                <span class="service-card-key text-slate-400 font-monospace small"><?= $serviceKey ?></span>
                                            </div>
                                        </div>
                                        <span class="badge bg-<?= $service['is_enabled'] ? 'success' : 'secondary' ?> rounded-pill px-3 py-1.5 fw-semibold service-status-pill" id="statusPill-<?= $serviceId ?>">
                                            <?= $service['is_enabled'] ? 'Enabled' : 'Disabled' ?>
                                        </span>
                                    </div>

                                    <div class="service-runtime-grid runtime-summary mb-3">
                                        <div><span><?= term('current_session_time', 'Current Session Time') ?></span><strong><?= $isRunning ? runtime_clock_label((int) ($service['remaining_runtime_seconds'] ?? 0)) : '00:00' ?></strong></div>
                                        <div><span><?= term('total_runtime', 'Total Runtime') ?></span><strong><?= service_runtime_label((int) ($service['runtime_seconds'] ?? 0)) ?></strong></div>
                                        <div><span><?= term('machine_status', 'Machine Status') ?></span><strong class="runtime-status <?= $isRunning ? 'runtime-running' : '' ?>"><?= $isRunning ? 'Running' : 'Idle' ?></strong></div>
                                    </div>

                                    <div class="service-maintenance-summary mb-3">
                                        <div class="maintenance-summary-heading"><i class="bi bi-wrench-adjustable text-info me-1"></i><?= term('maintenance', 'Maintenance') ?></div>
                                        <?php if ($maintenanceSummary): ?>
                                            <p class="mb-1"><strong class="maintenance-inline-status status-<?= e($maintenanceSummary['status']) ?>"><?= $maintenanceSummary['status'] === 'soon' ? 'Upcoming' : ($maintenanceSummary['status'] === 'due' || $maintenanceSummary['status'] === 'overdue' ? 'Due' : strtoupper(e($maintenanceSummary['status']))) ?></strong> <?= e($maintenanceSummary['name']) ?></p>
                                            <p class="mb-1">Next maintenance: <?= $maintenanceSummary['next_due_date'] ? e($maintenanceSummary['next_due_date']->format('M j, Y')) : 'Not date-based' ?></p>
                                            <?php if ($maintenanceSummary['runtime_status']): ?><p class="mb-0">Runtime maintenance: <?= $maintenanceSummary['runtime_status'] === 'soon' ? 'Upcoming' : ($maintenanceSummary['runtime_status'] === 'due' || $maintenanceSummary['runtime_status'] === 'overdue' ? 'Due' : ucfirst(e($maintenanceSummary['runtime_status']))) ?></p><?php endif; ?>
                                        <?php else: ?>
                                            <p class="mb-0 text-slate-400">No service maintenance reminder configured.</p>
                                        <?php endif; ?>
                                    </div>

                                    <div class="config-field config-enabled">
                                        <div>
                                            <label for="enabled-<?= $serviceId ?>">Enabled</label>
                                            <p class="config-help">Allow customers to use this service.</p>
                                        </div>
                                        <label class="switch" title="<?= $service['is_enabled'] ? 'Service enabled' : 'Service disabled' ?>">
                                            <input
                                                type="checkbox"
                                                class="checkbox service-enable-toggle"
                                                id="enabled-<?= $serviceId ?>"
                                                name="services[<?= $serviceId ?>][is_enabled]"
                                                data-target-pill="statusPill-<?= $serviceId ?>"
                                                role="switch"
                                                aria-label="Enable <?= e($service['name']) ?>"
                                                <?= $service['is_enabled'] ? 'checked' : '' ?>
                                            >
                                            <div class="slider"></div>
                                        </label>
                                    </div>

                                    <?php /* Coin value fixed at ₱5.00 — controlled by coin slot hardware pulses */ ?>
                                    <div class="config-field border-bottom-0 pb-0">
                                        <div>
                                            <label><?= term('time_per_coin', 'Time per ₱5') ?></label>
                                            <p class="config-help">Time the machine runs per ₱5 coin inserted.</p>
                                        </div>
                                        <div class="stepper time-stepper" data-step="10" data-min="10" data-max="600" data-service-name="<?= e($serviceTitle) ?>">
                                            <button type="button" class="stepper-btn" data-stepper="down" aria-label="Decrease service time for <?= e($serviceTitle) ?>"><i class="bi bi-dash-lg"></i></button>
                                            <input name="services[<?= $serviceId ?>][service_seconds]" type="hidden" value="<?= (int) $service['service_seconds'] ?>">
                                            <input class="time-entry" type="text" inputmode="numeric" value="<?= service_time_label((int) $service['service_seconds']) ?>" aria-label="Service time in minutes and seconds for <?= e($serviceTitle) ?>" title="Enter time as MM:SS">
                                            <button type="button" class="stepper-btn" data-stepper="up" aria-label="Increase service time for <?= e($serviceTitle) ?>"><i class="bi bi-plus-lg"></i></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>

                        <!-- Single Full-Width Save Configuration Button -->
                        <div class="mt-4 pt-3 border-top border-secondary border-opacity-25">
                            <button class="btn-save-config btn-unified-save w-100" type="submit" id="unifiedSaveBtn">
                                <span class="btn-text"><i class="bi bi-check2-circle me-2"></i><?= term('save_changes', 'Save Changes') ?></span>
                                <span class="btn-loading d-none"><span class="spinner-border spinner-border-sm me-2"></span>Saving...</span>
                            </button>
                        </div>
                    </form>

                <!-- RECENT ACTIVITY STREAM -->
                <section class="activity-card">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h5 class="fw-bold mb-0 text-light"><i class="bi bi-clock-history text-info me-2"></i><?= term('machine_activity') ?></h5>
                        <button class="btn-view-activity" type="button" data-bs-toggle="modal" data-bs-target="#activityHistoryModal">
                            <?= term('view_activity') ?>
                        </button>
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

<!-- Real-time LCD 2004A Simulator Interactive Script -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const inputs = [
        { input: document.getElementById('inputLine1'), sim: document.getElementById('simLine1'), count: document.getElementById('countLine1') },
        { input: document.getElementById('inputLine2'), sim: document.getElementById('simLine2'), count: document.getElementById('countLine2') },
        { input: document.getElementById('inputLine3'), sim: document.getElementById('simLine3'), count: document.getElementById('countLine3') },
        { input: document.getElementById('inputLine4'), sim: document.getElementById('simLine4'), count: document.getElementById('countLine4') }
    ];

    function updateSimLine(item) {
        if (!item.input || !item.sim) return;
        let text = item.input.value;
        if (text.length > 20) text = text.substring(0, 20);
        item.count.textContent = text.length + '/20';
        
        // Pad with spaces to exactly 20 characters for grid simulation
        let paddedText = text;
        while (paddedText.length < 20) {
            paddedText += ' ';
        }
        item.sim.textContent = paddedText;
    }

    inputs.forEach(item => {
        if (item.input) {
            item.input.addEventListener('input', () => updateSimLine(item));
            updateSimLine(item); // Initialize
        }
    });

    // Preset Template Dropdown Handler
    const templateSelect = document.getElementById('lcdTemplateSelect');
    if (templateSelect) {
        templateSelect.addEventListener('change', function () {
            const val = this.value;
            if (val === 'carwash') {
                inputs[0].input.value = '== KENJI CARWASH ==';
                inputs[1].input.value = 'WELCOME CUSTOMER!';
                inputs[2].input.value = 'INSERT COIN FIRST!';
                inputs[3].input.value = 'WATER:P5 | SOAP:P5';
            } else if (val === 'blank') {
                inputs[0].input.value = '';
                inputs[1].input.value = '';
                inputs[2].input.value = '';
                inputs[3].input.value = '';
            }
            inputs.forEach(item => updateSimLine(item));
        });
    }
});

// Text Alignment Helper (Left, Center, Right)
function alignLine(inputId, type) {
    const input = document.getElementById(inputId);
    if (!input) return;
    let text = input.value.trim();
    if (text.length === 0) return;
    
    if (type === 'center') {
        const totalPad = 20 - text.length;
        if (totalPad > 0) {
            const leftPad = Math.floor(totalPad / 2);
            input.value = ' '.repeat(leftPad) + text;
        }
    } else if (type === 'right') {
        const totalPad = 20 - text.length;
        if (totalPad > 0) {
            input.value = ' '.repeat(totalPad) + text;
        }
    } else {
        input.value = text;
    }
    input.dispatchEvent(new Event('input'));
}
</script>
</body>
</html>
