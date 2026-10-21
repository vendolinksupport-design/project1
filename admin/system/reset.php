<?php
/**
 * ============================================================
 * VendoLink — Pre-Sale System Reset & Clean Tool
 * ============================================================
 *
 * Allows Administrators to safely reset VendoLink to a factory clean
 * pre-sale state before deploying or selling machines to customers.
 *
 * Safety Features:
 * - Strictly restricted to role = 'administrator' (require_admin).
 * - Preserves the primary Admin user (ID 1, credentials, hash).
 * - Preserves all system settings, SMTP config, and OTA releases.
 * - Preserves all database tables, columns, indexes, and FK constraints.
 * - Automatic database backup created prior to running deletions.
 * - Requires explicit confirmation phrase ('RESET-PRE-SALE') and admin password.
 *
 * @package VendoLink
 * @version 1.0.0
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../models/SystemReset.php';
require_once __DIR__ . '/../../models/User.php';

require_admin();

$currentUser = current_user();
$resetService = new SystemReset();
$userModel    = new User();

// Handle Reset POST Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action        = $_POST['action'] ?? '';
    $confirmPhrase = trim($_POST['confirm_phrase'] ?? '');
    $adminPassword = $_POST['admin_password'] ?? '';

    if ($action === 'execute_system_reset') {
        // 1. Validate confirmation phrase
        if ($confirmPhrase !== 'RESET-PRE-SALE') {
            flash('danger', 'Confirmation failed: You must type <strong>RESET-PRE-SALE</strong> exactly to confirm.', true);
            redirect('admin/system/reset.php');
            exit;
        }

        // 2. Validate administrator password for security
        if (empty($adminPassword)) {
            flash('danger', 'Please enter your administrator password to authorize this reset.', true);
            redirect('admin/system/reset.php');
            exit;
        }

        $freshAdmin = $userModel->findById((int)$currentUser['id']);
        if (!$freshAdmin || !password_verify($adminPassword, $freshAdmin['password'])) {
            flash('danger', 'Authorization failed: Invalid administrator password.', true);
            log_activity('RESET_FAILED', 'Failed system reset attempt: Invalid admin password.', (int)$currentUser['id']);
            redirect('admin/system/reset.php');
            exit;
        }

        // 3. Execute transactional system reset
        try {
            $report = $resetService->executeReset((int)$currentUser['id']);

            $del = $report['deleted'];
            $msg = "<div class='mb-2'><strong>System Reset Complete!</strong> The system is now in a clean pre-sale state.</div>";
            $msg .= "<ul class='mb-1 ps-3 small'>";
            $msg .= "<li><strong>Auto-Backup:</strong> <code class='text-info'>" . e($report['backup_file']) . "</code></li>";
            $msg .= "<li><strong>Machines Removed:</strong> " . (int)$del['machines'] . "</li>";
            $msg .= "<li><strong>Customer Accounts Removed:</strong> " . (int)$del['users'] . "</li>";
            $msg .= "<li><strong>Transactions Cleared:</strong> " . (int)$del['service_transactions'] . "</li>";
            $msg .= "<li><strong>Collections Cleared:</strong> " . (int)$del['cash_collections'] . "</li>";
            $msg .= "<li><strong>Product &amp; License Keys Cleared:</strong> " . ((int)$del['product_keys'] + (int)$del['license_keys']) . "</li>";
            $msg .= "<li><strong>Admin Account Preserved:</strong> " . e($report['preserved']['admin_email']) . " (ID #" . (int)$report['preserved']['admin_id'] . ")</li>";
            $msg .= "<li><strong>Settings Preserved:</strong> " . (int)$report['preserved']['settings_count'] . " system configuration entries</li>";
            $msg .= "<li><strong>Machine ID Counter:</strong> Reset to <strong>#1</strong> (clean demonstration state)</li>";
            $msg .= "<li><strong>OTA Releases Preserved:</strong> <strong class='text-info'>v3.3.0</strong> (Default Latest Build) &amp; <strong class='text-info'>v3.4.0</strong> (Available Update Build)</li>";
            $msg .= "</ul>";

            flash('success', $msg, true);
        } catch (Throwable $e) {
            flash('danger', 'Reset failed with error: ' . $e->getMessage(), true);
        }

        redirect('admin/system/reset.php');
        exit;
    }
}

$summary = $resetService->getSummary();
$pageTitle = 'Pre-Sale System Reset';
$currentPage = 'system_reset';
$currentDir  = 'system';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> &mdash; VendoAdmin</title>

    <!-- Bootstrap 5 & Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- VendoLink CSS -->
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/app.css?v=<?= e((string) filemtime(ROOT_PATH . '/assets/css/app.css')) ?>">

    <style>
        .admin-card {
            background: #0f172a;
            border: 1px solid rgba(148, 163, 184, 0.12);
            border-radius: 14px;
            padding: 1.25rem;
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5);
        }

        .admin-stat-card {
            background: #0f172a;
            border: 1px solid rgba(148, 163, 184, 0.12);
            border-radius: 14px;
            padding: 1.25rem;
            position: relative;
            overflow: hidden;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-sizing: border-box;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
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
        .admin-stat-success::before { background: var(--vl-success, #22c55e); }
        .admin-stat-warning::before { background: var(--vl-warning, #f59e0b); }
        .admin-stat-danger::before  { background: var(--vl-danger, #ef4444); }

        .admin-stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            flex-shrink: 0;
        }

        .admin-stat-value {
            font-size: 1.75rem;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 0.2rem;
            color: #f8fafc;
        }

        .admin-stat-label {
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
            white-space: nowrap;
        }

        .danger-panel {
            background: rgba(239, 68, 68, 0.04);
            border: 1px solid rgba(239, 68, 68, 0.25);
            border-radius: 14px;
            padding: 1.5rem;
        }
    </style>
</head>
<body class="dashboard-body">

<div class="app-shell">
    <?php require_once __DIR__ . '/../../views/layouts/admin_sidebar.php'; ?>

    <div class="main-content" id="mainContent">
        <?php require_once __DIR__ . '/../../views/layouts/admin_topnav.php'; ?>

        <main class="content-wrapper p-3 p-md-4">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 gap-2">
                <div>
                    <h3 class="fw-bold mb-1 text-light"><i class="bi bi-arrow-counterclockwise text-danger me-2"></i>Pre-Sale System Reset</h3>
                    <p class="text-secondary mb-0">Clean all test machines, transactions, customer accounts, and logs to prepare machines for deployment and sale.</p>
                </div>
            </div>

            <!-- Flash Alerts -->
            <?= render_flash() ?>

            <!-- Metrics Summary Cards -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="admin-stat-card admin-stat-danger">
                        <div>
                            <div class="admin-stat-value text-danger"><?= (int)$summary['machines'] ?></div>
                            <div class="admin-stat-label">Machines in DB</div>
                        </div>
                        <div class="admin-stat-icon bg-danger bg-opacity-10 text-danger">
                            <i class="bi bi-cpu"></i>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="admin-stat-card admin-stat-warning">
                        <div>
                            <div class="admin-stat-value text-warning"><?= (int)$summary['customer_users'] ?></div>
                            <div class="admin-stat-label">Customer Accounts</div>
                        </div>
                        <div class="admin-stat-icon bg-warning bg-opacity-10 text-warning">
                            <i class="bi bi-people-fill"></i>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="admin-stat-card admin-stat-primary">
                        <div>
                            <div class="admin-stat-value text-primary"><?= (int)$summary['product_keys'] + (int)$summary['license_keys'] ?></div>
                            <div class="admin-stat-label">Product / License Keys</div>
                        </div>
                        <div class="admin-stat-icon bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-key-fill"></i>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="admin-stat-card admin-stat-success">
                        <div>
                            <div class="admin-stat-value text-success"><?= (int)$summary['service_transactions'] ?></div>
                            <div class="admin-stat-label">Transactions</div>
                        </div>
                        <div class="admin-stat-icon bg-success bg-opacity-10 text-success">
                            <i class="bi bi-receipt"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Safety Guarantee & Preservation Cards Row -->
            <div class="row g-4 mb-4">
                <!-- What is Preserved -->
                <div class="col-12 col-md-6">
                    <div class="admin-card h-100 border-success border-opacity-25">
                        <h5 class="fw-bold text-success mb-3"><i class="bi bi-shield-check me-2"></i>Guaranteed to be PRESERVED</h5>
                        <ul class="list-unstyled mb-0 small text-slate-300">
                            <li class="mb-2.5 d-flex align-items-start">
                                <i class="bi bi-check-circle-fill text-success me-2 mt-1"></i>
                                <div>
                                    <strong class="text-light">Administrator Account (ID #1):</strong><br>
                                    Username: <code class="text-info"><?= e($currentUser['username'] ?? 'admin') ?></code> &bull; Email: <code class="text-info"><?= e($currentUser['email'] ?? 'vendolink.support@gmail.com') ?></code><br>
                                    Your password hash, login access, and admin permissions are completely preserved.
                                </div>
                            </li>
                            <li class="mb-2.5 d-flex align-items-start">
                                <i class="bi bi-check-circle-fill text-success me-2 mt-1"></i>
                                <div>
                                    <strong class="text-light">Application Configuration &amp; Settings:</strong><br>
                                    All <?= (int)$summary['settings'] ?> system settings (Business name, Timezone, Session rules, Mail config) are kept intact.
                                </div>
                            </li>
                            <li class="mb-2.5 d-flex align-items-start">
                                <i class="bi bi-check-circle-fill text-success me-2 mt-1"></i>
                                <div>
                                    <strong class="text-light">OTA Firmware Releases:</strong><br>
                                    Firmware packages are preserved with <strong class="text-info">v3.3.0</strong> set as default Latest Build, and <strong class="text-info">v3.4.0</strong> available for presentation update deployments.
                                </div>
                            </li>
                            <li class="mb-2.5 d-flex align-items-start">
                                <i class="bi bi-check-circle-fill text-success me-2 mt-1"></i>
                                <div>
                                    <strong class="text-light">Database Schema &amp; Structure:</strong><br>
                                    All 16 tables, columns, indexes, foreign keys, and relationships remain 100% intact (zero tables dropped).
                                </div>
                            </li>
                            <li class="d-flex align-items-start">
                                <i class="bi bi-check-circle-fill text-success me-2 mt-1"></i>
                                <div>
                                    <strong class="text-light">Automatic Pre-Reset SQL Backup:</strong><br>
                                    A full database snapshot is automatically saved to <code class="text-warning">database/backups/</code> before any data is touched.
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- What will be Cleaned -->
                <div class="col-12 col-md-6">
                    <div class="admin-card h-100 border-danger border-opacity-25">
                        <h5 class="fw-bold text-danger mb-3"><i class="bi bi-trash3-fill me-2"></i>Test Data to be REMOVED</h5>
                        <ul class="list-unstyled mb-0 small text-slate-300">
                            <li class="mb-2.5 d-flex align-items-start">
                                <i class="bi bi-x-circle-fill text-danger me-2 mt-1"></i>
                                <div>
                                    <strong class="text-light">Customer &amp; Owner Test Accounts:</strong><br>
                                    All non-admin users (<?= (int)$summary['customer_users'] ?> accounts) will be removed.
                                </div>
                            </li>
                            <li class="mb-2.5 d-flex align-items-start">
                                <i class="bi bi-x-circle-fill text-danger me-2 mt-1"></i>
                                <div>
                                    <strong class="text-light">Machines &amp; Hardware Records:</strong><br>
                                    All <?= (int)$summary['machines'] ?> machines and their configured services (<?= (int)$summary['services'] ?> records) will be cleared. Machine ID counter resets to #1 for demonstration.
                                </div>
                            </li>
                            <li class="mb-2.5 d-flex align-items-start">
                                <i class="bi bi-x-circle-fill text-danger me-2 mt-1"></i>
                                <div>
                                    <strong class="text-light">Product &amp; License Keys:</strong><br>
                                    All <?= (int)$summary['product_keys'] ?> Product Keys and <?= (int)$summary['license_keys'] ?> software license keys will be purged.
                                </div>
                            </li>
                            <li class="mb-2.5 d-flex align-items-start">
                                <i class="bi bi-x-circle-fill text-danger me-2 mt-1"></i>
                                <div>
                                    <strong class="text-light">Operational History &amp; Transactions:</strong><br>
                                    All <?= (int)$summary['service_transactions'] ?> vending transactions, <?= (int)$summary['cash_collections'] ?> collection logs, <?= (int)$summary['activity_logs'] ?> activity logs, and <?= (int)$summary['notifications'] ?> notifications will be cleared.
                                </div>
                            </li>
                            <li class="d-flex align-items-start">
                                <i class="bi bi-x-circle-fill text-danger me-2 mt-1"></i>
                                <div>
                                    <strong class="text-light">Maintenance Records:</strong><br>
                                    All <?= (int)$summary['maintenance_items'] ?> maintenance reminders and <?= (int)$summary['maintenance_history'] ?> history entries will be cleared.
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Pre-Sale Reset Action Panel -->
            <div class="danger-panel mb-4">
                <div class="d-flex align-items-center mb-3">
                    <div class="bg-danger bg-opacity-10 text-danger p-2 rounded-3 me-3 fs-4">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold text-danger mb-0">Execute Pre-Sale System Reset</h5>
                        <p class="text-secondary small mb-0">This operation requires explicit confirmation and cannot be undone. An automatic SQL backup will be created.</p>
                    </div>
                </div>

                <form method="POST" id="resetForm" onsubmit="return confirmReset();">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="execute_system_reset">

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label text-secondary small fw-bold">TYPE CONFIRMATION PHRASE <span class="text-danger">*</span></label>
                            <input type="text" name="confirm_phrase" id="confirmPhraseInput" class="form-control bg-dark text-light border-danger border-opacity-50 font-monospace" placeholder="RESET-PRE-SALE" autocomplete="off" required>
                            <div class="form-text text-muted small">Type <code class="text-warning">RESET-PRE-SALE</code> to confirm.</div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label text-secondary small fw-bold">ADMINISTRATOR PASSWORD <span class="text-danger">*</span></label>
                            <input type="password" name="admin_password" id="adminPasswordInput" class="form-control bg-dark text-light border-secondary border-opacity-50" placeholder="Enter your login password" autocomplete="current-password" required>
                            <div class="form-text text-muted small">Required to authorize this high-security administrative operation.</div>
                        </div>
                    </div>

                    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
                        <div class="form-check">
                            <input class="form-check-input bg-dark border-danger" type="checkbox" id="backupAck" required>
                            <label class="form-check-label text-secondary small" for="backupAck">
                                I understand this will remove all test machines, customers, and transactions, returning VendoLink to a factory pre-sale state.
                            </label>
                        </div>
                        <button type="submit" id="btnExecuteReset" class="btn btn-danger fw-bold px-4 py-2 text-nowrap">
                            <i class="bi bi-trash3-fill me-2"></i>Reset to Pre-Sale State
                        </button>
                    </div>
                </form>
            </div>

            <!-- Pre-Sale Hardware Preparation Workflow Instructions -->
            <div class="admin-card border-info border-opacity-25">
                <h5 class="fw-bold text-light mb-2"><i class="bi bi-box-seam-fill text-info me-2"></i>Standard Pre-Sale Machine Preparation Checklist</h5>
                <p class="text-secondary small mb-3">Follow this standard workflow when building, testing, and packaging VendoLink ESP32 machines for customers:</p>

                <div class="row g-3 small">
                    <div class="col-12 col-md-3">
                        <div class="p-3 rounded-3 bg-dark bg-opacity-50 border border-secondary border-opacity-25 h-100">
                            <div class="fw-bold text-info mb-1">Step 1: Flash Firmware</div>
                            <p class="text-secondary mb-0">Flash the universal firmware (<code class="text-light">vendolink_esp32_heartbeat.ino</code>) to the ESP32. No shared keys or customer credentials are hardcoded.</p>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="p-3 rounded-3 bg-dark bg-opacity-50 border border-secondary border-opacity-25 h-100">
                            <div class="fw-bold text-info mb-1">Step 2: Hardware QA Test</div>
                            <p class="text-secondary mb-0">Test coin pulse detection, water/soap relays, and LCD. Verify that pulses increment credits and relays trigger dispensing.</p>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="p-3 rounded-3 bg-dark bg-opacity-50 border border-secondary border-opacity-25 h-100">
                            <div class="fw-bold text-warning mb-1">Step 3: Run Pre-Sale Reset</div>
                            <p class="text-secondary mb-0">Run this Pre-Sale Reset to clear your test transactions, test logs, and temporary owner bindings from the database.</p>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="p-3 rounded-3 bg-dark bg-opacity-50 border border-secondary border-opacity-25 h-100">
                            <div class="fw-bold text-success mb-1">Step 4: Package &amp; Ship</div>
                            <p class="text-secondary mb-0">The machine is clean! When the customer powers it on, it opens the Wi-Fi portal, auto-registers, and accepts their Product Key.</p>
                        </div>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= ASSETS_URL ?>/js/app.js?v=<?= e((string) filemtime(ROOT_PATH . '/assets/css/app.css')) ?>"></script>
<script>
function confirmReset() {
    var phrase = document.getElementById('confirmPhraseInput').value.trim();
    if (phrase !== 'RESET-PRE-SALE') {
        alert("Please type 'RESET-PRE-SALE' exactly in the confirmation box.");
        return false;
    }
    return confirm("FINAL CONFIRMATION:\n\nAre you sure you want to reset VendoLink to a clean pre-sale state?\n\n- All test machines, transactions, and customer accounts will be removed.\n- Administrator ID #1 and system settings will be preserved.\n- An automatic backup will be created.\n\nClick OK to execute.");
}
</script>
</body>
</html>
