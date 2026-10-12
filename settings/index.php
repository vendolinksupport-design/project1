<?php
/**
 * ============================================================
 * VendoLink — Owner & System Settings
 * ============================================================
 *
 * Allows Owners and Administrators to configure business
 * preferences, notification channels, and system defaults.
 *
 * Settings are persisted in the `settings` database table.
 *
 * @package VendoLink
 * @version 1.1
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Setting.php';

// Access guard — Owner and Admin only
require_role([ROLE_ADMIN, ROLE_OWNER]);

$settingModel = new Setting();
$user         = current_user();

// Define notification keys and defaults
$notifDefaults = [
    'inapp_notif_enabled'    => '1',
    'email_notif_enabled'    => '1',
    'notif_maint_reminders'  => '1',
    'notif_machine_offline'  => '1',
    'notif_machine_online'   => '1',
    'notif_daily_sales'      => '1',
    'notif_weekly_sales'     => '1',
    'notif_security_notices' => '1',
];
$notifKeys = array_keys($notifDefaults);

// Define business keys and defaults
$businessDefaults = [
    'business_name'    => 'VendoLink Car Wash',
    'business_email'   => 'vendolink.support@gmail.com',
    'business_phone'   => '+63 917 123 4567',
    'business_address' => '123 VendoLink Way, Metro Manila, Philippines',
];
$businessKeys = array_keys($businessDefaults);

// Handle POST — Save business or notification settings
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? 'save_notifications';

    if ($action === 'save_business') {
        $businessName    = trim($_POST['business_name']    ?? '');
        $businessEmail   = trim($_POST['business_email']   ?? '');
        $businessPhone   = trim($_POST['business_phone']   ?? '');
        $businessAddress = trim($_POST['business_address'] ?? '');

        if (empty($businessName) || empty($businessEmail) || empty($businessPhone) || empty($businessAddress)) {
            flash('danger', 'Please fill in all required business fields.');
            redirect('settings/index.php');
        }

        if (!validate_email($businessEmail)) {
            flash('danger', 'Please enter a valid business email address.');
            redirect('settings/index.php');
        }

        $settingModel->saveMany([
            'business_name'    => $businessName,
            'business_email'   => $businessEmail,
            'business_phone'   => $businessPhone,
            'business_address' => $businessAddress,
        ]);

        log_activity('BUSINESS_SETTINGS_UPDATED', 'Updated business preferences.', (int)$user['id']);

        flash('success', 'Business preferences saved successfully!');
        redirect('settings/index.php');
    }

    if ($action === 'save_notifications') {
        $newSettings = [];
        foreach ($notifKeys as $key) {
            $newSettings[$key] = isset($_POST[$key]) ? '1' : '0';
        }

        $settingModel->saveMany($newSettings);

        log_activity('SETTINGS_UPDATED', 'Updated notification preferences.', (int)$user['id']);

        flash('success', 'Notification preferences saved successfully!');
        redirect('settings/index.php');
    }
}

// Fetch current persisted settings
$notifSettings    = $settingModel->getMany($notifKeys, $notifDefaults);
$businessSettings = $settingModel->getMany($businessKeys, $businessDefaults);

$pageTitle   = 'Settings';
$currentPage = 'settings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= APP_NAME ?> Owner &amp; System Settings">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> &mdash; <?= APP_NAME ?></title>

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

    <!-- Sidebar Layout -->
    <?php require_once __DIR__ . '/../views/layouts/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content" id="mainContent">

        <!-- Top Navigation -->
        <?php require_once __DIR__ . '/../views/layouts/topnav.php'; ?>

        <!-- Page Content -->
        <main class="page-content" id="pageContent">

            <!-- Flash Messages -->
            <?php if ($flashHtml = render_flash()): ?>
            <div class="flash-container mb-3"><?= $flashHtml ?></div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="page-header mb-4">
                <div>
                    <h1 class="page-title text-white">
                        <i class="bi bi-gear-fill me-2" style="color: var(--vl-primary);"></i>Settings
                    </h1>
                    <p class="page-subtitle text-slate-300">
                        Manage business profile preferences, owner notification channels, and system defaults.
                    </p>
                </div>
                <div class="page-header-actions">
                    <a href="<?= BASE_URL ?>/dashboard/index.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-speedometer2 me-1"></i>Dashboard
                    </a>
                </div>
            </div>

            <!-- Settings Grid Layout -->
            <div class="row g-4">

                <!-- LEFT / MAIN COLUMN: Business & Notification Settings -->
                <div class="col-12 col-lg-8">

                    <!-- ====================================================
                         1. BUSINESS PREFERENCES SECTION
                         ==================================================== -->
                    <div class="vl-profile-panel mb-4">
                        <div class="vl-profile-panel-heading text-white fw-bold">
                            <i class="bi bi-building-fill me-2" style="color: var(--vl-primary);"></i>Business Preferences
                        </div>

                        <p class="text-slate-300 small mb-4">
                            Configure your establishment details and official business contact information for automated financial reports and audit summaries.
                        </p>

                        <form method="POST" action="<?= BASE_URL ?>/settings/index.php" id="businessForm" data-loading="true" data-confirm-save="true" data-confirm-message="Are you sure you want to save these updated business preferences?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_business">

                            <div class="row g-3 mb-3">
                                <!-- Business Name -->
                                <div class="col-12 col-sm-6">
                                    <label for="business_name" class="form-label text-white fw-semibold small">
                                        <i class="bi bi-shop me-1 text-primary"></i>Business / Establishment Name <span class="text-danger">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="business_name"
                                        name="business_name"
                                        class="form-control dark-input"
                                        value="<?= e($businessSettings['business_name']) ?>"
                                        placeholder="e.g. VendoLink Car Wash"
                                        required
                                    >
                                </div>

                                <!-- Business Email -->
                                <div class="col-12 col-sm-6">
                                    <label for="business_email" class="form-label text-white fw-semibold small">
                                        <i class="bi bi-envelope-at me-1 text-info"></i>Business Email <span class="text-danger">*</span>
                                    </label>
                                    <input
                                        type="email"
                                        id="business_email"
                                        name="business_email"
                                        class="form-control dark-input"
                                        value="<?= e($businessSettings['business_email']) ?>"
                                        placeholder="business@domain.com"
                                        required
                                    >
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <!-- Contact Number -->
                                <div class="col-12 col-sm-6">
                                    <label for="business_phone" class="form-label text-white fw-semibold small">
                                        <i class="bi bi-telephone me-1 text-success"></i>Contact Number <span class="text-danger">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="business_phone"
                                        name="business_phone"
                                        class="form-control dark-input"
                                        value="<?= e($businessSettings['business_phone']) ?>"
                                        placeholder="+63 917 123 4567"
                                        required
                                    >
                                </div>

                                <!-- Business Address -->
                                <div class="col-12 col-sm-6">
                                    <label for="business_address" class="form-label text-white fw-semibold small">
                                        <i class="bi bi-geo-alt me-1 text-warning"></i>Business Address <span class="text-danger">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="business_address"
                                        name="business_address"
                                        class="form-control dark-input"
                                        value="<?= e($businessSettings['business_address']) ?>"
                                        placeholder="123 VendoLink Way, Metro Manila"
                                        required
                                    >
                                </div>
                            </div>

                            <div class="pt-3 border-top border-slate-700 text-end">
                                <button type="submit" class="btn btn-primary px-4 py-2 fw-semibold">
                                    <span class="btn-text">
                                        <i class="bi bi-check2-circle me-1"></i><?= term('save_changes') ?>
                                    </span>
                                    <span class="btn-loading d-none">
                                        <span class="spinner-border spinner-border-sm me-1"></span>Saving...
                                    </span>
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- ====================================================
                         2. NOTIFICATION PREFERENCES SECTION
                         ==================================================== -->
                    <form method="POST" action="<?= BASE_URL ?>/settings/index.php" id="notifForm" data-loading="true">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_notifications">

                        <div class="vl-profile-panel mb-4">
                            <div class="vl-profile-panel-heading text-white fw-bold">
                                <i class="bi bi-bell-fill me-2" style="color: var(--vl-primary);"></i>Owner Notifications
                            </div>

                            <p class="text-slate-300 small mb-4">
                                Control how and when you receive real-time operational alerts, sales reports, and machine updates.
                            </p>

                            <!-- Master Toggles -->
                            <div class="settings-master-section mb-4">
                                <div class="row g-3">
                                    
                                    <!-- Web Notifications -->
                                    <div class="col-12 col-sm-6">
                                        <div class="settings-toggle-card">
                                            <div class="d-flex align-items-center justify-content-between">
                                                <div class="d-flex align-items-center gap-3">
                                                    <div class="settings-card-icon bg-primary-subtle text-primary">
                                                        <i class="bi bi-bell-fill"></i>
                                                    </div>
                                                    <div>
                                                        <div class="settings-card-title text-white fw-bold">Web Notifications</div>
                                                        <div class="settings-card-sub text-slate-300">Receive real-time operational alerts and system updates directly in the VendoLink web dashboard.</div>
                                                    </div>
                                                </div>
                                                <label class="switch" title="Toggle Web Notifications">
                                                    <input
                                                        type="checkbox"
                                                        class="checkbox"
                                                        id="inapp_notif_enabled"
                                                        name="inapp_notif_enabled"
                                                        role="switch"
                                                        aria-label="Toggle Web Notifications"
                                                        <?= $notifSettings['inapp_notif_enabled'] === '1' ? 'checked' : '' ?>
                                                    >
                                                    <div class="slider"></div>
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Email Notifications -->
                                    <div class="col-12 col-sm-6">
                                        <div class="settings-toggle-card">
                                            <div class="d-flex align-items-center justify-content-between">
                                                <div class="d-flex align-items-center gap-3">
                                                    <div class="settings-card-icon bg-info-subtle text-info">
                                                        <i class="bi bi-envelope-at-fill"></i>
                                                    </div>
                                                    <div>
                                                        <div class="settings-card-title text-white fw-bold">Email Notifications</div>
                                                        <div class="settings-card-sub text-slate-300">SMTP email alerts</div>
                                                    </div>
                                                </div>
                                                <label class="switch" title="Toggle Email Notifications">
                                                    <input
                                                        type="checkbox"
                                                        class="checkbox"
                                                        id="email_notif_enabled"
                                                        name="email_notif_enabled"
                                                        role="switch"
                                                        aria-label="Toggle Email Notifications"
                                                        <?= $notifSettings['email_notif_enabled'] === '1' ? 'checked' : '' ?>
                                                    >
                                                    <div class="slider"></div>
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                </div>
                            </div>

                            <div class="vl-profile-section-divider text-white fw-bold">
                                Email Notification Preferences
                            </div>

                            <!-- Individual Email Preferences List -->
                            <div class="settings-preferences-list" id="emailPreferencesContainer">

                                <!-- Maintenance Alerts -->
                                <div class="settings-notif-row">
                                    <div class="settings-notif-info">
                                        <div class="settings-notif-label text-white fw-bold">
                                            <i class="bi bi-tools me-2 text-warning"></i>Maintenance Alerts
                                        </div>
                                        <div class="settings-notif-desc text-slate-300">
                                            Allow email alerts when vending unit maintenance tasks or cleaning schedules are due.
                                        </div>
                                    </div>
                                    <label class="switch" title="Toggle Maintenance Alerts">
                                        <input
                                            type="checkbox"
                                            class="checkbox email-sub-toggle"
                                            name="notif_maint_reminders"
                                            role="switch"
                                            aria-label="Toggle Maintenance Alerts"
                                            <?= $notifSettings['notif_maint_reminders'] === '1' ? 'checked' : '' ?>
                                        >
                                        <div class="slider"></div>
                                    </label>
                                </div>

                                <!-- Machine Offline Alerts -->
                                <div class="settings-notif-row">
                                    <div class="settings-notif-info">
                                        <div class="settings-notif-label text-white fw-bold">
                                            <i class="bi bi-wifi-off me-2 text-danger"></i>Machine Offline Alerts
                                        </div>
                                        <div class="settings-notif-desc text-slate-300">
                                            Receive instant email notifications if a vending unit loses connection or goes offline.
                                        </div>
                                    </div>
                                    <label class="switch" title="Toggle Machine Offline Alerts">
                                        <input
                                            type="checkbox"
                                            class="checkbox email-sub-toggle"
                                            name="notif_machine_offline"
                                            role="switch"
                                            aria-label="Toggle Machine Offline Alerts"
                                            <?= $notifSettings['notif_machine_offline'] === '1' ? 'checked' : '' ?>
                                        >
                                        <div class="slider"></div>
                                    </label>
                                </div>

                                <!-- Machine Back Online Alerts -->
                                <div class="settings-notif-row">
                                    <div class="settings-notif-info">
                                        <div class="settings-notif-label text-white fw-bold">
                                            <i class="bi bi-wifi me-2 text-success"></i>Machine Back Online Alerts
                                        </div>
                                        <div class="settings-notif-desc text-slate-300">
                                            Receive confirmation emails when a disconnected vending unit successfully reconnects.
                                        </div>
                                    </div>
                                    <label class="switch" title="Toggle Machine Back Online Alerts">
                                        <input
                                            type="checkbox"
                                            class="checkbox email-sub-toggle"
                                            name="notif_machine_online"
                                            role="switch"
                                            aria-label="Toggle Machine Back Online Alerts"
                                            <?= $notifSettings['notif_machine_online'] === '1' ? 'checked' : '' ?>
                                        >
                                        <div class="slider"></div>
                                    </label>
                                </div>

                                <!-- Daily Sales Summary -->
                                <div class="settings-notif-row">
                                    <div class="settings-notif-info">
                                        <div class="settings-notif-label text-white fw-bold">
                                            <i class="bi bi-calendar-check me-2 text-info"></i>Daily Sales Summary
                                        </div>
                                        <div class="settings-notif-desc text-slate-300">
                                            Receive an automated daily email breakdown of total sales revenue and transaction metrics.
                                        </div>
                                    </div>
                                    <label class="switch" title="Toggle Daily Sales Summary">
                                        <input
                                            type="checkbox"
                                            class="checkbox email-sub-toggle"
                                            name="notif_daily_sales"
                                            role="switch"
                                            aria-label="Toggle Daily Sales Summary"
                                            <?= $notifSettings['notif_daily_sales'] === '1' ? 'checked' : '' ?>
                                        >
                                        <div class="slider"></div>
                                    </label>
                                </div>

                                <!-- Weekly Sales Summary -->
                                <div class="settings-notif-row">
                                    <div class="settings-notif-info">
                                        <div class="settings-notif-label text-white fw-bold">
                                            <i class="bi bi-graph-up-arrow me-2 text-primary"></i>Weekly Sales Summary
                                        </div>
                                        <div class="settings-notif-desc text-slate-300">
                                            Receive a weekly audit summary highlighting revenue growth, machine usage, and audit trends.
                                        </div>
                                    </div>
                                    <label class="switch" title="Toggle Weekly Sales Summary">
                                        <input
                                            type="checkbox"
                                            class="checkbox email-sub-toggle"
                                            name="notif_weekly_sales"
                                            role="switch"
                                            aria-label="Toggle Weekly Sales Summary"
                                            <?= $notifSettings['notif_weekly_sales'] === '1' ? 'checked' : '' ?>
                                        >
                                        <div class="slider"></div>
                                    </label>
                                </div>

                                <!-- Security & System Notices -->
                                <div class="settings-notif-row border-0 pb-0">
                                    <div class="settings-notif-info">
                                        <div class="settings-notif-label text-white fw-bold">
                                            <i class="bi bi-shield-exclamation me-2 text-warning"></i>Security &amp; System Notices
                                        </div>
                                        <div class="settings-notif-desc text-slate-300">
                                            Receive critical security updates, password change logs, and system environment alerts.
                                        </div>
                                    </div>
                                    <label class="switch" title="Toggle Security & System Notices">
                                        <input
                                            type="checkbox"
                                            class="checkbox email-sub-toggle"
                                            name="notif_security_notices"
                                            role="switch"
                                            aria-label="Toggle Security Notices"
                                            <?= $notifSettings['notif_security_notices'] === '1' ? 'checked' : '' ?>
                                        >
                                        <div class="slider"></div>
                                    </label>
                                </div>

                            </div>

                            <!-- Save Notification Preferences Button -->
                            <div class="mt-4 pt-3 border-top border-slate-700 text-end">
                                <button type="submit" class="btn btn-primary px-4 py-2 fw-semibold">
                                    <span class="btn-text">
                                        <i class="bi bi-check2-circle me-1"></i><?= term('save_changes') ?>
                                    </span>
                                    <span class="btn-loading d-none">
                                        <span class="spinner-border spinner-border-sm me-1"></span>Saving...
                                    </span>
                                </button>
                            </div>

                        </div>
                    </form>
                </div>

                <!-- RIGHT COLUMN: Support & Assistance -->
                <div class="col-12 col-lg-4">
                    <!-- Quick Support Panel -->
                    <div class="vl-profile-panel">
                        <div class="vl-profile-panel-heading text-white fw-bold">
                            <i class="bi bi-headset me-2" style="color: var(--vl-primary);"></i><?= term('customer_support') ?>
                        </div>
                        <p class="text-slate-300 small mb-3">
                            For system configurations, hardware ESP32 integration, or custom notification setups, reach out to customer support.
                        </p>
                        <button type="button" class="btn btn-outline-info btn-sm w-100 fw-semibold" data-bs-toggle="modal" data-bs-target="#contactSupportModal">
                            <i class="bi bi-envelope-fill me-1"></i><?= term('customer_support') ?>
                        </button>
                    </div>
                </div>

            </div>

        </main>
        
        <!-- Page Footer -->
        <footer class="app-footer">
            <span><?= APP_NAME ?> v<?= APP_VERSION ?> &copy; <?= date('Y') ?></span>
            <span>Powered by VendoCore IoT Controller</span>
        </footer>
    </div>
</div>

<!-- Contact Support Modal -->
<div class="modal fade" id="contactSupportModal" tabindex="-1" aria-labelledby="contactSupportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-slate-900 border-slate-700 text-white shadow-lg">
            <div class="modal-header border-slate-800">
                <h5 class="modal-title fw-bold text-white" id="contactSupportModalLabel">
                    <i class="bi bi-headset me-2 text-primary"></i>VendoLink <?= term('customer_support') ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <div class="text-center mb-4">
                    <div class="d-inline-flex align-items-center justify-content-center bg-primary-subtle text-primary rounded-circle mb-3" style="width: 60px; height: 60px; font-size: 1.6rem;">
                        <i class="bi bi-headset"></i>
                    </div>
                    <h6 class="fw-bold text-white mb-1">Official Support Contacts</h6>
                    <p class="text-slate-300 small mb-2">Direct assistance for hardware, software, and accounting.</p>

                    <!-- Support Email Box -->
                    <div class="p-3 bg-slate-800 border border-slate-700 rounded-3 d-flex align-items-center justify-content-between gap-2 mt-3">
                        <div class="d-flex align-items-center gap-2 min-w-0">
                            <i class="bi bi-envelope-at-fill text-info flex-shrink-0"></i>
                            <span class="font-monospace text-info fw-bold text-truncate" id="supportEmailText" style="font-size: .92rem;">vendolink.support@gmail.com</span>
                        </div>
                        <button type="button" class="btn btn-sm btn-primary fw-semibold text-nowrap" id="copySupportEmailBtn">
                            <i class="bi bi-clipboard me-1"></i><span id="copyBtnText">Copy Email</span>
                        </button>
                    </div>

                    <!-- Support Phone Box -->
                    <div class="p-3 bg-slate-800 border border-slate-700 rounded-3 d-flex align-items-center justify-content-between gap-2 mt-2">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-telephone-fill text-success flex-shrink-0"></i>
                            <span class="font-monospace text-white fw-bold" id="supportPhoneText" style="font-size: .95rem;">0962 525 6463</span>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="tel:09625256463" class="btn btn-sm btn-outline-success fw-semibold">
                                <i class="bi bi-telephone-out me-1"></i>Call
                            </a>
                            <button type="button" class="btn btn-sm btn-secondary fw-semibold text-nowrap" id="copySupportPhoneBtn">
                                <i class="bi bi-clipboard me-1"></i><span id="copyPhoneBtnText">Copy Phone</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Webmail Quick Compose Options -->
                <div class="border-top border-slate-800 pt-3 mb-3">
                    <div class="text-white fw-bold small mb-2"><i class="bi bi-box-arrow-up-right me-1 text-info"></i>Open Directly in Webmail</div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="https://mail.google.com/mail/?view=cm&fs=1&to=vendolink.support@gmail.com&su=VendoLink%20Support%20Request" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-danger flex-fill fw-semibold">
                            <i class="bi bi-google me-1"></i>Open Gmail
                        </a>
                        <a href="https://outlook.live.com/mail/0/deeplink/compose?to=vendolink.support@gmail.com&subject=VendoLink%20Support%20Request" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary flex-fill fw-semibold">
                            <i class="bi bi-microsoft me-1"></i>Open Outlook
                        </a>
                        <a href="https://compose.mail.yahoo.com/?to=vendolink.support@gmail.com&subject=VendoLink%20Support%20Request" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-warning flex-fill fw-semibold">
                            <i class="bi bi-envelope-paper me-1"></i>Open Yahoo
                        </a>
                    </div>
                </div>

                <div class="border-top border-slate-800 pt-3">
                    <div class="row g-3 text-start small">
                        <div class="col-6">
                            <div class="text-white fw-bold mb-1"><i class="bi bi-cpu me-1 text-warning"></i>Hardware &amp; ESP32</div>
                            <div class="text-slate-400">Sensor calibration, relay wiring, and coin acceptor support.</div>
                        </div>
                        <div class="col-6">
                            <div class="text-white fw-bold mb-1"><i class="bi bi-clock-history me-1 text-success"></i>Support Hours</div>
                            <div class="text-slate-400">Mon - Sat, 8:00 AM - 6:00 PM</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- VendoLink JS -->
<script src="<?= ASSETS_URL ?>/js/app.js?v=<?= e((string) filemtime(ROOT_PATH . '/assets/css/app.css')) ?>"></script>

<script>
(function(){
    'use strict';
    var emailMaster  = document.getElementById('email_notif_enabled');
    var container    = document.getElementById('emailPreferencesContainer');

    function syncEmailToggles() {
        if (!emailMaster || !container) return;
        var isEnabled = emailMaster.checked;
        container.style.opacity       = isEnabled ? '1' : '0.45';
        container.style.pointerEvents = isEnabled ? 'auto' : 'none';
    }

    if (emailMaster) {
        emailMaster.addEventListener('change', syncEmailToggles);
        syncEmailToggles();
    }

    // Copy Email Action
    var copyBtn  = document.getElementById('copySupportEmailBtn');
    var copyText = document.getElementById('copyBtnText');
    if (copyBtn) {
        copyBtn.addEventListener('click', function() {
            var email = 'vendolink.support@gmail.com';
            copyToClipboard(email, copyBtn, copyText, 'Copy Email');
        });
    }

    // Copy Phone Action
    var copyPhoneBtn  = document.getElementById('copySupportPhoneBtn');
    var copyPhoneText = document.getElementById('copyPhoneBtnText');
    if (copyPhoneBtn) {
        copyPhoneBtn.addEventListener('click', function() {
            var phone = '09625256463';
            copyToClipboard(phone, copyPhoneBtn, copyPhoneText, 'Copy Phone');
        });
    }

    function copyToClipboard(text, btnEl, textEl, defaultLabel) {
        var doFeedback = function() {
            if (!textEl) return;
            textEl.textContent = 'Copied!';
            btnEl.classList.remove('btn-primary', 'btn-secondary');
            btnEl.classList.add('btn-success');
            setTimeout(function() {
                textEl.textContent = defaultLabel;
                btnEl.classList.remove('btn-success');
                btnEl.classList.add('btn-primary');
            }, 2500);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(doFeedback).catch(function() {
                doFallback(text, doFeedback);
            });
        } else {
            doFallback(text, doFeedback);
        }
    }

    function doFallback(text, callback) {
        var tempInput = document.createElement('textarea');
        tempInput.value = text;
        document.body.appendChild(tempInput);
        tempInput.select();
        document.execCommand('copy');
        document.body.removeChild(tempInput);
        callback();
    }
})();
</script>

</body>
</html>