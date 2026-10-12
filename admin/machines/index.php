<?php
/**
 * ============================================================
 * VendoLink — Global ESP32 Machines Fleet (VendoAdmin Module 2)
 * ============================================================
 *
 * Centralized master control room for all deployed ESP32 vending hardware units nationwide.
 * Displays unit identity, IP address, online status, firmware version, and API token management.
 * Allows System Admins to register new hardware units (Machine 02, Machine 03, etc.).
 *
 * @package VendoLink
 * @version 1.1.0
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../models/AdminDashboard.php';

require_admin();

$dashboardModel = new AdminDashboard();
$flashSuccess   = '';
$flashError     = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action    = $_POST['action'] ?? '';
    $machineId = (int)($_POST['machine_id'] ?? 0);

    if ($action === 'regen_token' && $machineId > 0) {
        $newToken = $dashboardModel->regenerateMachineToken($machineId);
        $flashSuccess = "Security API token regenerated successfully: <code>{$newToken}</code>";
        log_activity('ADMIN_ACTION', "Regenerated security token for machine ID #{$machineId}");
    } elseif ($action === 'register_machine') {
        $name        = trim($_POST['name'] ?? '');
        $machineCode = trim($_POST['machine_code'] ?? '');
        $machineKey  = trim($_POST['machine_key'] ?? '');
        $userId      = (int)($_POST['user_id'] ?? 0);

        if (empty($name) || empty($machineCode) || empty($machineKey) || $userId <= 0) {
            $flashError = "Machine Name, Machine Code, Machine Key, and Owner Account are required.";
        } else {
            try {
                $result = $dashboardModel->registerNewMachine($name, $machineCode, $machineKey, $userId);
                $flashSuccess = "New Vending Hardware Unit <strong>{$machineCode}</strong> registered successfully! Security Token: <code>{$result['api_token']}</code>";
                log_activity('ADMIN_ACTION', "Registered new hardware unit {$machineCode} (#{$result['id']})");
            } catch (InvalidArgumentException $e) {
                $flashError = $e->getMessage();
            } catch (Exception $e) {
                $flashError = "Failed to register hardware unit: " . $e->getMessage();
            }
        }
    }
}

$machines  = $dashboardModel->getAllMachines();
$customers = $dashboardModel->getAllCustomers();
$metrics   = $dashboardModel->getSummaryMetrics();

$pageTitle   = term('machines');
$currentPage = 'machines';
$currentDir  = 'machines';
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

        .admin-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px -6px rgba(0, 0, 0, 0.4);
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

        .admin-table {
            color: #f8fafc;
            width: 100%;
            margin-bottom: 0;
        }

        .admin-table th {
            background: #1e293b;
            color: #94a3b8;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 0.85rem 1rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.12);
        }

        .admin-table td {
            padding: 1rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.08);
            vertical-align: middle;
            background: transparent;
        }

        .admin-table tbody tr:hover {
            background: rgba(30, 41, 59, 0.5);
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
                    <h3 class="fw-bold mb-1 text-light"><i class="bi bi-cpu-fill text-primary me-2"></i><?= term('machines') ?></h3>
                    <p class="text-secondary mb-0">Centralized control room for all deployed vending machines.</p>
                </div>
                <div>
                    <button type="button" class="btn btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#registerMachineModal">
                        <i class="bi bi-plus-lg me-1.5"></i><?= term('btn_register_machine') ?>
                    </button>
                </div>
            </div>

            <?php if ($flashSuccess): ?>
                <div class="alert alert-success alert-dismissible fade show border-0 bg-success bg-opacity-10 text-success" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?= $flashSuccess ?>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($flashError): ?>
                <div class="alert alert-danger alert-dismissible fade show border-0 bg-danger bg-opacity-10 text-danger" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($flashError) ?>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Metrics Summary Cards -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-md-4">
                    <div class="admin-stat-card admin-stat-primary">
                        <div>
                            <div class="admin-stat-value"><?= count($machines) ?></div>
                            <div class="admin-stat-label">Total Machines</div>
                        </div>
                        <div class="admin-stat-icon bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-cpu-fill"></i>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <div class="admin-stat-card admin-stat-success">
                        <div>
                            <div class="admin-stat-value text-success"><?= (int)($metrics['online_machines'] ?? 0) ?></div>
                            <div class="admin-stat-label">Online Machines</div>
                        </div>
                        <div class="admin-stat-icon bg-success bg-opacity-10 text-success">
                            <i class="bi bi-wifi"></i>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <div class="admin-stat-card admin-stat-danger">
                        <div>
                            <div class="admin-stat-value text-danger"><?= (int)($metrics['offline_machines'] ?? 0) ?></div>
                            <div class="admin-stat-label">Offline Machines</div>
                        </div>
                        <div class="admin-stat-icon bg-danger bg-opacity-10 text-danger">
                            <i class="bi bi-wifi-off"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Machines Registry Table Card -->
            <div class="admin-card">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="fw-bold text-light mb-0"><i class="bi bi-hdd-network-fill me-2 text-info"></i>Machines Registry</h5>
                    <span class="badge bg-secondary bg-opacity-20 text-slate-300 rounded-pill px-3 py-1.5 font-monospace">
                        <?= count($machines) ?> Machine(s)
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table admin-table mb-0">
                        <thead>
                            <tr>
                                <th>Machine Name</th>
                                <th>Status</th>
                                <th>IP Address</th>
                                <th>Firmware</th>
                                <th>Security Token</th>
                                <th>Last Seen</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($machines)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-secondary">
                                    <i class="bi bi-cpu fs-2 d-block mb-2 text-muted"></i>No machines registered yet.
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($machines as $m): 
                                    $calcStatus = $m['calculated_status'] ?? 'offline';
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-light"><?= e($m['name']) ?></div>
                                        <div class="small font-monospace text-info"><?= e($m['machine_code']) ?> &bull; <span class="text-secondary"><?= e($m['machine_key']) ?></span></div>
                                    </td>
                                    <td>
                                        <?php if ($calcStatus === 'online'): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-3 py-1.5 fw-semibold">
                                                <i class="bi bi-wifi me-1"></i>Online
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-3 py-1.5 fw-semibold">
                                                <i class="bi bi-wifi-off me-1"></i>Offline
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="font-monospace text-slate-300 small">
                                            <?= e($m['ip_address'] ?? 'Not Assigned') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-dark border border-secondary text-light rounded-pill px-2.5 py-1 font-monospace">
                                            v<?= e($m['firmware_version'] ?? '1.0.0') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <code class="text-warning small" style="font-size: 0.78rem;">
                                            <?= e(mb_strimwidth($m['api_token'] ?? 'No Token', 0, 18, '...')) ?>
                                        </code>
                                    </td>
                                    <td class="small text-secondary">
                                        <?= $m['last_seen'] ? date('M d, g:i A', strtotime($m['last_seen'])) : 'Never' ?>
                                    </td>
                                    <td>
                                        <!-- Regenerate API Token Form -->
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Regenerate security token for this machine?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="regen_token">
                                            <input type="hidden" name="machine_id" value="<?= (int)$m['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-warning rounded-2 px-2.5 py-1" title="Issue New Security Token">
                                                <i class="bi bi-key-fill me-1"></i>New Token
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
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

<!-- REGISTER MACHINE MODAL -->
<div class="modal fade" id="registerMachineModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-light border-secondary border-opacity-25" style="background:#0f172a; border-color:#1e293b;">
            <div class="modal-header border-secondary border-opacity-25">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-cpu-fill me-2 text-primary"></i><?= term('btn_register_machine') ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="register_machine">

                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">MACHINE DISPLAY NAME</label>
                        <input type="text" name="name" class="form-control bg-dark text-light border-secondary border-opacity-25" placeholder="e.g. Kenji Carwash Branch 2" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">MACHINE CODE</label>
                        <input type="text" name="machine_code" class="form-control bg-dark text-light border-secondary border-opacity-25" placeholder="e.g. MACHINE-02" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">MACHINE KEY (HARDWARE ID)</label>
                        <input type="text" name="machine_key" class="form-control bg-dark text-light border-secondary border-opacity-25" placeholder="e.g. machine_02" required>
                        <div class="form-text text-muted small">Must match the MACHINE_KEY in ESP32 config.h.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">ASSIGN TO CUSTOMER ACCOUNT</label>
                        <select name="user_id" class="form-select bg-dark text-light border-secondary border-opacity-25" required>
                            <option value="">-- Select Customer --</option>
                            <?php foreach ($customers as $cust): ?>
                                <option value="<?= (int)$cust['id'] ?>"><?= e($cust['full_name']) ?> (@<?= e($cust['username']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-secondary border-opacity-25">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold">
                        <i class="bi bi-cpu me-1"></i><?= term('btn_register_machine') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= ASSETS_URL ?>/js/app.js?v=<?= e((string) filemtime(ROOT_PATH . '/assets/css/app.css')) ?>"></script>
</body>
</html>
