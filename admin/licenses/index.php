<?php
/**
 * ============================================================
 * VendoLink — Product Key / Hardware Activation Generator
 * ============================================================
 *
 * Allows Administrators to generate 16-character license keys to activate
 * newly manufactured vending machine hardware kits for customers.
 *
 * @package VendoLink
 * @version 1.0.0
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../models/AdminDashboard.php';

require_admin();

$dashboardModel = new AdminDashboard();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action         = $_POST['action'] ?? '';
    $id             = (int)($_POST['id'] ?? 0);
    $ownerName      = trim($_POST['assigned_owner'] ?? '');
    $hardwareSerial = strtoupper(trim($_POST['hardware_serial'] ?? 'MACHINE-01'));

    if (empty($hardwareSerial)) {
        $hardwareSerial = 'MACHINE-01';
    }

    if ($action === 'generate_key') {
        $db = Database::getInstance();
        $existing = $db->query(
            "SELECT product_key, hardware_serial, status FROM product_keys 
             WHERE hardware_serial = :serial AND status IN ('UNUSED', 'USED')
             ORDER BY id DESC LIMIT 1",
            [':serial' => $hardwareSerial]
        )->fetch();

        if ($existing && !empty($existing['product_key'])) {
            $existingKey = $existing['product_key'];
            $stat = $existing['status'];
            flash('warning', "Machine <strong>" . e($hardwareSerial) . "</strong> already has an Activation Key ({$stat}): <code class='fs-5 text-warning'>" . e($existingKey) . "</code>. Existing key preserved.", true);
        } else {
            $newKey = $dashboardModel->generateLicenseKey($ownerName ?: null, $hardwareSerial);
            flash('success', "New Activation Key Generated for <strong>" . e($hardwareSerial) . "</strong>: <code class='fs-5 text-warning'>" . e($newKey) . "</code>", true);
            log_activity('ADMIN_ACTION', "Generated new machine activation key {$newKey} for machine {$hardwareSerial}");
        }
        redirect('admin/licenses/index.php');
        exit;
    } elseif ($action === 'revoke_key' && $id > 0) {
        if ($dashboardModel->revokeLicenseKey($id)) {
            flash('success', "Activation key #{$id} has been revoked.", true);
            log_activity('ADMIN_ACTION', "Revoked activation key ID #{$id}");
        } else {
            flash('danger', "Failed to revoke activation key.", true);
        }
        redirect('admin/licenses/index.php');
        exit;
    }
}

$licenseKeys = $dashboardModel->getLicenseKeys();
$metrics     = $dashboardModel->getSummaryMetrics();
$allMachines = $dashboardModel->getAllMachines();

$pageTitle   = term('machine_activation', 'Machine Activation');
$currentPage = 'licenses';
$currentDir  = 'licenses';
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
            padding: 0.8rem 1rem;
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
                    <h3 class="fw-bold mb-1 text-light"><i class="bi bi-key-fill text-warning me-2"></i><?= term('machine_activation') ?></h3>
                    <p class="text-secondary mb-0">Generate and manage vending machine activation keys.</p>
                </div>
            </div>

            <!-- Flash Alerts -->
            <?= render_flash() ?>

            <!-- Metrics Summary Cards -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="admin-stat-card admin-stat-warning">
                        <div>
                            <div class="admin-stat-value text-warning"><?= count($licenseKeys) ?></div>
                            <div class="admin-stat-label">Total Keys</div>
                        </div>
                        <div class="admin-stat-icon bg-warning bg-opacity-10 text-warning">
                            <i class="bi bi-key-fill"></i>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="admin-stat-card admin-stat-success">
                        <div>
                            <?php 
                            $activeCount = count(array_filter($licenseKeys, fn($k) => in_array($k['status'], ['active', 'used'], true)));
                            ?>
                            <div class="admin-stat-value text-success"><?= $activeCount ?></div>
                            <div class="admin-stat-label"><?= term('active_activations') ?></div>
                        </div>
                        <div class="admin-stat-icon bg-success bg-opacity-10 text-success">
                            <i class="bi bi-patch-check-fill"></i>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="admin-stat-card admin-stat-primary">
                        <div>
                            <?php 
                            $unusedCount = count(array_filter($licenseKeys, fn($k) => $k['status'] === 'unused'));
                            ?>
                            <div class="admin-stat-value text-primary"><?= $unusedCount ?></div>
                            <div class="admin-stat-label"><?= term('unused_keys') ?></div>
                        </div>
                        <div class="admin-stat-icon bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-ticket-perforated-fill"></i>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="admin-stat-card admin-stat-danger">
                        <div>
                            <?php 
                            $revokedCount = count(array_filter($licenseKeys, fn($k) => in_array($k['status'], ['revoked', 'disabled'], true)));
                            ?>
                            <div class="admin-stat-value text-danger"><?= $revokedCount ?></div>
                            <div class="admin-stat-label">Revoked Keys</div>
                        </div>
                        <div class="admin-stat-icon bg-danger bg-opacity-10 text-danger">
                            <i class="bi bi-slash-circle-fill"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Key Generator Form & License List Row -->
            <div class="row g-4 mb-4">
                <!-- Generate Key Form -->
                <div class="col-12 col-lg-4">
                    <div class="admin-card">
                        <h5 class="fw-bold text-light mb-3"><i class="bi bi-key-fill text-warning me-2"></i>Generate Activation Key</h5>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="generate_key">
                            
                            <div class="mb-3">
                                <label class="form-label text-secondary small fw-bold">MACHINE CODE <span class="text-danger">*</span></label>
                                <input type="text" name="hardware_serial" class="form-control bg-dark text-light border-secondary border-opacity-25 font-monospace" placeholder="e.g. MACHINE-01" value="MACHINE-01" required>
                                <div class="form-text text-muted small">Each machine can have only one key. If a key already exists, the existing key is returned.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label text-secondary small fw-bold">ASSIGN TO CUSTOMER (OPTIONAL)</label>
                                <input type="text" name="assigned_owner" class="form-control bg-dark text-light border-secondary border-opacity-25" placeholder="e.g. Kenji Prado">
                                <div class="form-text text-muted small">Leave blank to create an unassigned key.</div>
                            </div>

                            <button type="submit" class="btn btn-warning w-100 fw-bold py-2 text-dark">
                                <i class="bi bi-key-fill me-2"></i>Generate Key
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Activation Keys Table -->
                <div class="col-12 col-lg-8">
                    <div class="admin-card">
                        <h5 class="fw-bold text-light mb-3"><i class="bi bi-card-checklist text-info me-2"></i>Activation Keys</h5>
                        <div class="table-responsive">
                            <table class="table admin-table mb-0">
                                <thead>
                                    <tr>
                                        <th style="white-space: nowrap;">Activation Key</th>
                                        <th style="white-space: nowrap;">Machine</th>
                                        <th style="white-space: nowrap;">Customer</th>
                                        <th style="white-space: nowrap;">Status</th>
                                        <th style="white-space: nowrap;">Created</th>
                                        <th style="white-space: nowrap;" class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($licenseKeys)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-secondary">
                                             <i class="bi bi-key fs-3 d-block mb-2"></i>No activation keys generated yet.
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($licenseKeys as $lk): ?>
                                        <tr>
                                            <td>
                                                <div class="font-monospace text-warning fw-bold fs-6 text-break"><?= e($lk['product_key'] ?? $lk['license_key']) ?></div>
                                            </td>
                                            <td class="text-nowrap">
                                                <span class="badge bg-dark border border-secondary text-info fw-bold font-monospace">
                                                    <?= e($lk['hardware_serial'] ?? 'MACHINE-01') ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="text-light fw-medium"><?= e($lk['assigned_owner'] ?? 'Unassigned') ?></div>
                                            </td>
                                            <td class="text-nowrap">
                                                <?php if (in_array($lk['status'], ['active', 'used'], true)): ?>
                                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2.5 py-1 fw-semibold">
                                                        <i class="bi bi-check-circle-fill me-1"></i>Active
                                                    </span>
                                                <?php elseif ($lk['status'] === 'unused'): ?>
                                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-2.5 py-1 fw-semibold">
                                                        <i class="bi bi-ticket-fill me-1"></i>Unused
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-2.5 py-1 fw-semibold">
                                                        <i class="bi bi-slash-circle-fill me-1"></i>Revoked
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="small text-secondary text-nowrap">
                                                <?= date('M d, Y', strtotime($lk['created_at'])) ?>
                                            </td>
                                            <td class="text-end text-nowrap">
                                                <?php if (!in_array($lk['status'], ['revoked', 'disabled'], true)): ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to revoke this activation key?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="revoke_key">
                                                    <input type="hidden" name="id" value="<?= (int)$lk['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-2 px-2.5 py-1" title="Revoke Key">
                                                        <i class="bi bi-slash-circle me-1"></i>Revoke
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
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
</body>
</html>
