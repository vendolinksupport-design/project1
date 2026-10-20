<?php
/**
 * ============================================================
 * VendoLink — Machines & Hardware Fleet Hub (Unified Command)
 * ============================================================
 *
 * Unified command center for:
 * 1. Fleet Operations (Live telemetry, coins, water/soap levels, status)
 * 2. Factory Provisioning & Keys (ESP32 enrollment, key generation, packaging stickers)
 *
 * @package VendoLink
 * @version 4.0.0
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../models/AdminDashboard.php';
require_once __DIR__ . '/../../models/Machine.php';

require_admin();

$dashboardModel = new AdminDashboard();
$machineModel   = new Machine();
$db             = Database::getInstance();
$pdo            = $db->getConnection();

$flashSuccess = '';
$flashError   = '';

// Active Tab: 'fleet' (default) or 'provisioning'
$activeTab = ($_GET['tab'] ?? 'fleet') === 'provisioning' ? 'provisioning' : 'fleet';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action         = $_POST['action'] ?? '';
    $id             = (int)($_POST['id'] ?? 0);
    $machineId      = (int)($_POST['machine_id'] ?? 0);
    $ownerName      = trim($_POST['assigned_owner'] ?? '');
    $hardwareSerial = strtoupper(trim($_POST['hardware_serial'] ?? 'MACHINE-01'));
    if (empty($hardwareSerial)) {
        $hardwareSerial = 'MACHINE-01';
    }

    // Action 1: Rename Machine
    if ($action === 'update_machine_name' && $machineId > 0) {
        $newName = trim($_POST['name'] ?? '');
        if (empty($newName)) {
            flash('danger', "Machine name cannot be empty.", true);
        } else {
            $stmt = $pdo->prepare("UPDATE machines SET name = :name WHERE id = :id");
            $stmt->execute([':name' => $newName, ':id' => $machineId]);
            flash('success', "Machine name updated successfully to <strong>" . e($newName) . "</strong>.", true);
            log_activity('ADMIN_ACTION', "Renamed machine ID #{$machineId} to '{$newName}'");
        }
        redirect('admin/machines/index.php?tab=fleet');
        exit;
    }

    // Action 2: Delete Unowned Machine
    elseif ($action === 'delete_unowned_machine' && $machineId > 0) {
        $check = $pdo->prepare("SELECT id, machine_code, owner_id FROM machines WHERE id = :id LIMIT 1");
        $check->execute([':id' => $machineId]);
        $target = $check->fetch();

        if (!$target) {
            flash('danger', "Machine not found.", true);
        } elseif (!empty($target['owner_id'])) {
            flash('danger', "Security Guard: Cannot delete machine because it is already owned by a customer.", true);
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM services WHERE machine_id = :id")->execute([':id' => $machineId]);
                $pdo->prepare("DELETE FROM product_keys WHERE machine_id = :id")->execute([':id' => $machineId]);
                $pdo->prepare("DELETE FROM machines WHERE id = :id")->execute([':id' => $machineId]);
                $pdo->commit();
                flash('success', "Unassigned machine <strong>" . e($target['machine_code']) . "</strong> removed successfully.", true);
                log_activity('ADMIN_ACTION', "Removed unassigned machine {$target['machine_code']} (#{$machineId})");
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash('danger', "Database error: " . $e->getMessage(), true);
            }
        }
        redirect('admin/machines/index.php?tab=fleet');
        exit;
    }

    // Action 3: Generate Activation Key
    elseif ($action === 'generate_key') {
        if ($machineId > 0) {
            $mRow = $db->query("SELECT id, machine_code, registration_status, owner_id FROM machines WHERE id = :id", [':id' => $machineId])->fetch();
        } elseif (!empty($hardwareSerial)) {
            $mRow = $db->query("SELECT id, machine_code, registration_status, owner_id FROM machines WHERE machine_code = :code LIMIT 1", [':code' => $hardwareSerial])->fetch();
            if ($mRow) {
                $machineId = (int)$mRow['id'];
            }
        } else {
            $mRow = null;
        }

        if (!$mRow) {
            flash('danger', "Cannot generate activation key: No unassigned ESP32 controller found. Please connect an ESP32 controller to Wi-Fi to auto-enroll it first.", true);
            redirect('admin/machines/index.php?tab=provisioning');
            exit;
        }

        $hardwareSerial = $mRow['machine_code'];

        if ($mRow['registration_status'] === 'disabled') {
            flash('danger', "Cannot generate activation key for disabled machine <strong>" . e($hardwareSerial) . "</strong>.", true);
            redirect('admin/machines/index.php?tab=provisioning');
            exit;
        }

        if ($mRow['registration_status'] === 'claimed' || !empty($mRow['owner_id'])) {
            flash('danger', "Machine <strong>" . e($hardwareSerial) . "</strong> is already claimed by an owner. Activation keys are one-time use tokens and cannot be generated for claimed machines.", true);
            redirect('admin/machines/index.php?tab=provisioning');
            exit;
        }

        // Deduplication check
        $existing = $db->query(
            "SELECT product_key, hardware_serial, status FROM product_keys 
             WHERE (machine_id = :mid OR (hardware_serial = :serial AND hardware_serial != ''))
               AND (status IN ('UNUSED', 'USED') OR status IS NULL OR status = '')
             ORDER BY id DESC LIMIT 1",
            [':mid' => ($machineId > 0 ? $machineId : null), ':serial' => $hardwareSerial]
        )->fetch();

        if ($existing && !empty($existing['product_key'])) {
            $existingKey = $existing['product_key'];
            $stat = !empty($existing['status']) ? strtoupper($existing['status']) : 'USED';
            flash('warning', "Machine <strong>" . e($hardwareSerial) . "</strong> already has an Activation Key ({$stat}): <code class='fs-5 text-warning'>" . e($existingKey) . "</code>. Activation keys are one-time use; existing key preserved.", true);
        } else {
            $newKey = $dashboardModel->generateLicenseKey($ownerName ?: null, $hardwareSerial, ($machineId > 0 ? $machineId : null));
            flash('success', "New Activation Key Generated for <strong>" . e($hardwareSerial) . "</strong>: <code class='fs-5 text-warning'>" . e($newKey) . "</code>", true);
            log_activity('ADMIN_ACTION', "Generated new machine activation key {$newKey} for machine {$hardwareSerial}");
        }
        redirect('admin/machines/index.php?tab=provisioning');
        exit;
    }

    // Action 4: Revoke / Disable Activation Key
    elseif ($action === 'revoke_key' && $id > 0) {
        if ($dashboardModel->revokeLicenseKey($id)) {
            flash('success', "Activation key #{$id} has been disabled.", true);
            log_activity('ADMIN_ACTION', "Disabled activation key ID #{$id}");
        } else {
            flash('danger', "Failed to disable activation key.", true);
        }
        redirect('admin/machines/index.php?tab=provisioning');
        exit;
    }
}

// Fetch all machines with details
$machines = $dashboardModel->getAllMachines();
$metrics  = $dashboardModel->getSummaryMetrics();

// Calculate live statuses
$onlineCount  = 0;
$offlineCount = 0;
foreach ($machines as &$m) {
    $m['calculated_status'] = $machineModel->evaluateOnlineStatus($m);
    if ($m['calculated_status'] === 'online') {
        $onlineCount++;
    } else {
        $offlineCount++;
    }
}
unset($m);

// Fetch enrolled machines with key pairing status for Provisioning tab
$enrolledMachines = $db->query(
    "SELECT m.*, 
            pk.id AS pk_id, 
            pk.product_key, 
            COALESCE(NULLIF(pk.status, ''), CASE WHEN m.registration_status = 'claimed' OR m.owner_id IS NOT NULL THEN 'USED' ELSE 'UNUSED' END) AS pk_status, 
            pk.owner_id AS pk_owner_id, 
            u.full_name AS owner_name,
            u.email AS owner_email
     FROM machines m
     LEFT JOIN product_keys pk ON pk.id = (
         SELECT pk2.id FROM product_keys pk2 
         WHERE (pk2.machine_id = m.id OR (pk2.hardware_serial = m.machine_code AND pk2.hardware_serial != ''))
         ORDER BY (pk2.machine_id = m.id) DESC, (pk2.status = 'USED') DESC, pk2.id DESC 
         LIMIT 1
     )
     LEFT JOIN users u ON (m.owner_id = u.id OR pk.owner_id = u.id)
     ORDER BY 
         CASE 
             WHEN m.registration_status = 'registered' AND pk.id IS NULL THEN 1
             WHEN m.registration_status = 'key_issued' THEN 2
             WHEN m.registration_status = 'claimed' THEN 3
             WHEN m.registration_status = 'legacy' THEN 4
             ELSE 5
         END ASC,
         m.created_at DESC"
)->fetchAll();

$allMachines = $dashboardModel->getAllMachines();

$availableUnassignedMachines = array_filter($allMachines, function($m) {
    $isClaimed = (($m['registration_status'] ?? '') === 'claimed' || !empty($m['owner_id']));
    $hasKey = !empty($m['product_key']);
    $isDisabled = (($m['registration_status'] ?? '') === 'disabled');
    return !$isClaimed && !$hasKey && !$isDisabled;
});

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
        .machine-card-compact {
            background: #0f172a;
            border: 1px solid rgba(148, 163, 184, 0.14);
            border-radius: 12px;
            margin-bottom: 0.85rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            overflow: hidden;
        }
        .machine-card-compact:hover {
            border-color: rgba(56, 189, 248, 0.35);
            box-shadow: 0 8px 24px -6px rgba(0, 0, 0, 0.4);
        }
        .machine-header-row {
            padding: 0.95rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .machine-status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
        }
        .status-dot-online {
            background-color: #22c55e;
            box-shadow: 0 0 8px #22c55e;
        }
        .status-dot-offline {
            background-color: #ef4444;
        }
        .machine-details-drawer {
            background: #0b1120;
            border-top: 1px solid rgba(148, 163, 184, 0.12);
            padding: 1.25rem 1.35rem;
        }
        .detail-box {
            background: #131d36;
            border: 1px solid rgba(148, 163, 184, 0.08);
            border-radius: 10px;
            padding: 1rem;
            height: 100%;
        }
        .detail-title {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
            margin-bottom: 0.65rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .detail-row {
            font-size: 0.84rem;
            margin-bottom: 0.45rem;
            color: #cbd5e1;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .detail-label {
            color: #64748b;
        }
        .detail-value {
            font-weight: 500;
            font-family: monospace;
            color: #f1f5f9;
        }
        .admin-card {
            background: #0f172a;
            border: 1px solid rgba(148, 163, 184, 0.12);
            border-radius: 14px;
            padding: 1.25rem;
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5);
            margin-bottom: 1.25rem;
        }
        .admin-stat-card {
            background: #0f172a;
            border: 1px solid rgba(148, 163, 184, 0.12);
            border-radius: 12px;
            padding: 1rem 1.25rem;
            height: 100%;
        }
        .table.admin-table {
            --bs-table-bg: transparent;
            --bs-table-color: #f8fafc;
            --bs-table-border-color: rgba(148, 163, 184, 0.12);
            --bs-table-hover-bg: rgba(30, 41, 59, 0.6);
            --bs-table-hover-color: #f8fafc;
            color: #f8fafc !important;
            width: 100%;
            margin-bottom: 0;
            background-color: transparent !important;
        }
        .table.admin-table > :not(caption) > * > * {
            background-color: transparent !important;
            color: #f8fafc !important;
            box-shadow: none !important;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1) !important;
        }
        .table.admin-table th {
            background: #1e293b !important;
            color: #94a3b8 !important;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 0.85rem 1rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.18) !important;
        }
        .table.admin-table td {
            padding: 0.85rem 1rem;
            vertical-align: middle;
            background-color: transparent !important;
            color: #f8fafc !important;
        }
        .table.admin-table tbody tr:hover > * {
            background-color: rgba(30, 41, 59, 0.6) !important;
        }
        .nav-tabs {
            border-bottom: 1px solid rgba(148, 163, 184, 0.2);
        }
        .nav-tabs .nav-link {
            color: #94a3b8;
            border: 1px solid transparent;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
            font-weight: 600;
            padding: 0.65rem 1.25rem;
            transition: all 0.15s ease;
        }
        .nav-tabs .nav-link:hover {
            color: #f8fafc;
            border-color: rgba(148, 163, 184, 0.15);
        }
        .nav-tabs .nav-link.active {
            color: #38bdf8;
            background: #0f172a;
            border-color: rgba(148, 163, 184, 0.25) rgba(148, 163, 184, 0.25) #0f172a;
        }
        .sticker-box {
            background: #ffffff;
            color: #0f172a;
            border: 2px dashed #0284c7;
            border-radius: 12px;
            padding: 20px;
            max-width: 440px;
            margin: 0 auto;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .step-num-badge {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.8rem;
        }
    </style>
</head>
<body class="dashboard-body">

<div class="app-shell">
    <?php require_once __DIR__ . '/../../views/layouts/admin_sidebar.php'; ?>

    <div class="main-content" id="mainContent">
        <?php require_once __DIR__ . '/../../views/layouts/admin_topnav.php'; ?>

        <main class="content-wrapper p-3 p-md-4">
            
            <!-- Page Header -->
            <div class="mb-4">
                <h3 class="fw-bold mb-1 text-light">
                    <i class="bi bi-cpu text-info me-2"></i><?= term('machines') ?>
                </h3>
                <p class="text-secondary mb-0">Overview of all vending controllers, live status, and activation keys.</p>
            </div>

            <!-- Flash Messages -->
            <?php if ($flashSuccess): ?>
                <div class="alert alert-success alert-dismissible fade show border-0 bg-success bg-opacity-10 text-success mb-3" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?= $flashSuccess ?>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($flashError): ?>
                <div class="alert alert-danger alert-dismissible fade show border-0 bg-danger bg-opacity-10 text-danger mb-3" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($flashError) ?>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Navigation Tabs: Tab 1 = All Machines, Tab 2 = Activation & Keys -->
            <ul class="nav nav-tabs mb-4" id="machinesHubTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $activeTab !== 'provisioning' ? 'active' : '' ?>" id="fleet-tab" data-bs-toggle="tab" data-bs-target="#fleetPane" type="button" role="tab" aria-controls="fleetPane" aria-selected="<?= $activeTab !== 'provisioning' ? 'true' : 'false' ?>">
                        <i class="bi bi-cpu text-info me-2"></i>All Machines (<?= count($machines) ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $activeTab === 'provisioning' ? 'active' : '' ?>" id="provisioning-tab" data-bs-toggle="tab" data-bs-target="#provisioningPane" type="button" role="tab" aria-controls="provisioningPane" aria-selected="<?= $activeTab === 'provisioning' ? 'true' : 'false' ?>">
                        <i class="bi bi-key-fill text-warning me-2"></i>Activation &amp; Keys
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="machinesHubTabContent">
                
                <!-- ======================================================== -->
                <!-- TAB 1: ACTIVE FLEET OPERATIONS                           -->
                <!-- ======================================================== -->
                <div class="tab-pane fade <?= $activeTab !== 'provisioning' ? 'show active' : '' ?>" id="fleetPane" role="tabpanel" aria-labelledby="fleet-tab">
                    
                    <!-- Metric Summary Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-4">
                            <div class="admin-stat-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="fs-3 fw-bold text-light"><?= count($machines) ?></div>
                                    <div class="text-secondary small fw-semibold text-uppercase">Total Machines</div>
                                </div>
                                <div class="p-3 rounded-3 bg-primary bg-opacity-10 text-primary fs-3">
                                    <i class="bi bi-cpu"></i>
                                </div>
                            </div>
                        </div>

                        <div class="col-6 col-md-4">
                            <div class="admin-stat-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="fs-3 fw-bold text-success"><?= $onlineCount ?></div>
                                    <div class="text-secondary small fw-semibold text-uppercase">Online Now</div>
                                </div>
                                <div class="p-3 rounded-3 bg-success bg-opacity-10 text-success fs-3">
                                    <i class="bi bi-wifi"></i>
                                </div>
                            </div>
                        </div>

                        <div class="col-6 col-md-4">
                            <div class="admin-stat-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="fs-3 fw-bold text-danger"><?= $offlineCount ?></div>
                                    <div class="text-secondary small fw-semibold text-uppercase">Offline</div>
                                </div>
                                <div class="p-3 rounded-3 bg-danger bg-opacity-10 text-danger fs-3">
                                    <i class="bi bi-wifi-off"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Machine List Section -->
                    <div class="mb-3 d-flex align-items-center justify-content-between">
                        <h5 class="fw-bold text-light mb-0">
                            <i class="bi bi-list-task text-primary me-2"></i>Registered Machine Units (<?= count($machines) ?>)
                        </h5>
                        <span class="text-secondary small">Click <strong>View Details</strong> to expand machine info</span>
                    </div>

                    <?php if (empty($machines)): ?>
                        <div class="text-center py-5 bg-dark border border-secondary border-opacity-25 rounded-3 text-secondary my-3">
                            <i class="bi bi-cpu fs-1 d-block mb-3 text-muted"></i>
                            <h5 class="text-light fw-bold">No Machines Enrolled Yet</h5>
                            <p class="small text-secondary mb-3">Power on an ESP32 connected to Wi-Fi to automatically enroll your first machine.</p>
                            <button type="button" class="btn btn-sm btn-primary" onclick="bootstrap.Tab.getOrCreateInstance(document.getElementById('provisioning-tab')).show();">
                                <i class="bi bi-key me-1"></i>Go to Factory Provisioning
                            </button>
                        </div>
                    <?php else: ?>
                        <?php foreach ($machines as $m): 
                            $isOnline = ($m['calculated_status'] === 'online');
                            $collapseId = 'collapseMachine' . $m['id'];
                            $hasKey = !empty($m['product_key']);
                            $hasOwner = !empty($m['owner_name']);
                        ?>
                        <!-- Compact Machine Card with Full Details Drawer -->
                        <div class="machine-card-compact">
                            <div class="machine-header-row">
                                <!-- Left: Status & Identity -->
                                <div class="d-flex align-items-center gap-3">
                                    <span class="machine-status-dot <?= $isOnline ? 'status-dot-online' : 'status-dot-offline' ?>" title="<?= $isOnline ? 'Online' : 'Offline' ?>"></span>
                                    <div>
                                        <span class="fw-bold text-light fs-6"><?= e($m['name'] ?: $m['machine_code']) ?></span>
                                        <span class="badge bg-secondary bg-opacity-25 text-info font-monospace ms-2"><?= e($m['machine_code']) ?></span>
                                        <?php
                                        $mType = $m['machine_type'] ?? 'carwash';
                                        $mBadge = Machine::getMachineTypeBadge($mType);
                                        ?>
                                        <span class="badge bg-<?= $mBadge['color'] ?>-subtle text-<?= $mBadge['color'] ?> border border-<?= $mBadge['color'] ?> border-opacity-25 rounded-pill px-2 py-0.5 ms-1" style="font-size: 0.68rem;">
                                            <i class="bi <?= $mBadge['icon'] ?> me-1"></i><?= e($mBadge['short']) ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- Center: Owner & Firmware -->
                                <div class="d-flex align-items-center gap-3 text-secondary small">
                                    <div>
                                        <i class="bi bi-person me-1"></i>
                                        <?php if ($hasOwner): ?>
                                            <span class="text-light fw-semibold"><?= e($m['owner_name']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic">Unassigned (Ready for Customer)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-none d-md-block">
                                        <span class="badge bg-dark border border-secondary border-opacity-25 text-light font-monospace">
                                            v<?= e($m['firmware_version'] ?? '3.0.0') ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- Right: Action Buttons -->
                                <div class="d-flex align-items-center gap-2">
                                    <?php if ($hasKey && !$hasOwner): ?>
                                    <button type="button" class="btn btn-sm btn-outline-warning px-2.5 py-1" onclick="openSticker('<?= e($m['name'] ?: $m['machine_code']) ?>', '<?= e($m['device_mac'] ?? '') ?>', '<?= e($m['product_key']) ?>');" title="Print Machine Packaging Sticker or Share">
                                        <i class="bi bi-tag-fill me-1"></i>Sticker
                                    </button>
                                    <?php endif; ?>

                                    <!-- Quick Update Button -->
                                    <a href="<?= BASE_URL ?>/admin/ota/index.php?target_machine=<?= (int)$m['id'] ?>" class="btn btn-sm btn-outline-info px-2.5 py-1" title="Send Wireless Update to this machine">
                                        <i class="bi bi-radio me-1"></i>Update
                                    </a>

                                    <!-- Expandable Details Button -->
                                    <button class="btn btn-sm btn-dark border-secondary border-opacity-50 text-light px-3 py-1" 
                                            type="button" 
                                            data-bs-toggle="collapse" 
                                            data-bs-target="#<?= $collapseId ?>" 
                                            aria-expanded="false" 
                                            aria-controls="<?= $collapseId ?>">
                                        <i class="bi bi-chevron-down me-1"></i>View Details
                                    </button>
                                </div>
                            </div>

                            <!-- EXPANDABLE DRAWER (Slides down when clicked) -->
                            <div class="collapse" id="<?= $collapseId ?>">
                                <div class="machine-details-drawer">
                                    <div class="row g-3">
                                        <!-- Box 1: Hardware & Network Details -->
                                        <div class="col-12 col-md-4">
                                            <div class="detail-box">
                                                <div class="detail-title">
                                                    <i class="bi bi-hdd-network text-info"></i>Hardware &amp; Network
                                                </div>
                                                <div class="detail-row">
                                                    <span class="detail-label">Hardware MAC:</span>
                                                    <span class="detail-value text-info"><?= e($m['device_mac'] ?? 'Legacy Unit') ?></span>
                                                </div>
                                                <div class="detail-row">
                                                    <span class="detail-label">Local IP Address:</span>
                                                    <span class="detail-value"><?= e($m['ip_address'] ?? 'Not Assigned') ?></span>
                                                </div>
                                                <div class="detail-row">
                                                    <span class="detail-label">Firmware Version:</span>
                                                    <span class="detail-value">v<?= e($m['firmware_version'] ?? '3.0.0') ?></span>
                                                </div>
                                                <div class="detail-row">
                                                    <span class="detail-label">Last Signal:</span>
                                                    <span class="detail-value">
                                                        <?= $m['last_seen'] ? time_ago($m['last_seen']) : 'Never' ?>
                                                    </span>
                                                </div>
                                                <div class="detail-row">
                                                    <span class="detail-label">Machine Key:</span>
                                                    <span class="detail-value text-secondary"><?= e($m['machine_key']) ?></span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Box 2: Vending & Health -->
                                        <div class="col-12 col-md-4">
                                            <div class="detail-box">
                                                <div class="detail-title">
                                                    <i class="bi bi-activity text-warning"></i>Vending &amp; Health
                                                </div>
                                                <div class="detail-row">
                                                    <span class="detail-label">Machine Profile:</span>
                                                    <span class="detail-value text-<?= $mBadge['color'] ?> fw-semibold">
                                                        <i class="bi <?= $mBadge['icon'] ?> me-1"></i><?= e($mBadge['label']) ?>
                                                    </span>
                                                </div>
                                                <div class="detail-row">
                                                    <span class="detail-label">Completed Vends:</span>
                                                    <span class="detail-value"><?= number_format((int)($m['total_transactions'] ?? 0)) ?> vends</span>
                                                </div>
                                                <?php if ($mType === 'carwash'): ?>
                                                <div class="detail-row">
                                                    <span class="detail-label">Current Water Tank:</span>
                                                    <span class="detail-value"><?= !empty($m['water_tank_low']) ? '<span class="text-danger">LOW</span>' : '<span class="text-success">NORMAL</span>' ?></span>
                                                </div>
                                                <div class="detail-row">
                                                    <span class="detail-label">Current Soap Tank:</span>
                                                    <span class="detail-value"><?= !empty($m['soap_tank_low']) ? '<span class="text-danger">LOW</span>' : '<span class="text-success">NORMAL</span>' ?></span>
                                                </div>
                                                <?php else: ?>
                                                <div class="detail-row">
                                                    <span class="detail-label">Tank Sensors:</span>
                                                    <span class="detail-value text-muted">Disabled (Air / Blower Unit)</span>
                                                </div>
                                                <?php endif; ?>
                                                <div class="detail-row">
                                                    <span class="detail-label">Registration State:</span>
                                                    <span class="detail-value text-info font-monospace"><?= strtoupper(e($m['registration_status'])) ?></span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Box 3: Product Key & Settings -->
                                        <div class="col-12 col-md-4">
                                            <div class="detail-box">
                                                <div class="detail-title">
                                                    <i class="bi bi-shield-lock text-success"></i>Product Key &amp; Settings
                                                </div>
                                                
                                                <div class="mb-2">
                                                    <div class="detail-label mb-1">Product Key:</div>
                                                    <?php if ($hasKey): ?>
                                                        <div class="d-flex align-items-center gap-1.5 flex-wrap">
                                                            <span class="detail-value text-warning fw-bold fs-6 font-monospace text-break"><?= e($m['product_key']) ?></span>
                                                            <?php if (!$hasOwner && strtoupper($m['pk_status'] ?? '') !== 'USED'): ?>
                                                                <button type="button" class="btn btn-sm btn-outline-warning py-0 px-2 rounded-pill" style="font-size: 0.72rem;" onclick="copyKey('<?= e($m['product_key']) ?>', this);" title="Copy Key">
                                                                    <i class="bi bi-clipboard"></i> Copy
                                                                </button>
                                                                <button type="button" class="btn btn-sm btn-outline-info py-0 px-2 rounded-pill" style="font-size: 0.72rem;" onclick="openSticker('<?= e($m['name'] ?: $m['machine_code']) ?>', '<?= e($m['device_mac'] ?? '') ?>', '<?= e($m['product_key']) ?>');" title="Packaging Sticker">
                                                                    <i class="bi bi-tag-fill me-1"></i>Sticker
                                                                </button>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary bg-opacity-25 text-slate-400 font-monospace small">
                                                                    <i class="bi bi-lock-fill me-1"></i>Activated
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <form method="POST" class="d-inline">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="generate_key">
                                                            <input type="hidden" name="machine_id" value="<?= (int)$m['id'] ?>">
                                                            <input type="hidden" name="hardware_serial" value="<?= e($m['machine_code']) ?>">
                                                            <button type="submit" class="btn btn-sm btn-warning text-dark fw-bold px-2.5 py-0.5">
                                                                <i class="bi bi-key-fill me-1"></i>Generate Key
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>

                                                <div class="detail-row">
                                                    <span class="detail-label">Customer Owner:</span>
                                                    <span class="detail-value"><?= e($m['owner_name'] ?? 'Unclaimed') ?></span>
                                                </div>
                                                <div class="detail-row">
                                                    <span class="detail-label">API Token:</span>
                                                    <span class="detail-value text-secondary" style="font-size: 0.72rem;">
                                                        <?= e(mb_strimwidth($m['api_token'] ?? 'None', 0, 16, '...')) ?>
                                                    </span>
                                                </div>

                                                <!-- Quick Edit / Delete Buttons -->
                                                <div class="pt-3 border-top border-secondary border-opacity-10 mt-2 d-flex gap-2">
                                                    <button type="button" class="btn btn-sm btn-outline-light flex-fill" data-bs-toggle="modal" data-bs-target="#editModal<?= $m['id'] ?>">
                                                        <i class="bi bi-pencil me-1"></i>Rename
                                                    </button>
                                                    
                                                    <?php if (empty($m['owner_id'])): ?>
                                                    <form method="POST" onsubmit="return confirm('Remove unassigned machine <?= e($m['machine_code']) ?>?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete_unowned_machine">
                                                        <input type="hidden" name="machine_id" value="<?= (int)$m['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete unassigned machine">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Modal: Rename Machine -->
                        <div class="modal fade" id="editModal<?= $m['id'] ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content bg-dark text-light border-secondary border-opacity-25">
                                    <form method="POST">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_machine_name">
                                        <input type="hidden" name="machine_id" value="<?= (int)$m['id'] ?>">
                                        <div class="modal-header border-secondary border-opacity-25">
                                            <h5 class="modal-title fw-bold">Rename Machine</h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <label class="form-label text-secondary small fw-bold">MACHINE CODE</label>
                                                <input type="text" class="form-control bg-dark text-light border-secondary border-opacity-25" value="<?= e($m['machine_code']) ?>" disabled>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label text-secondary small fw-bold">DISPLAY NAME</label>
                                                <input type="text" name="name" class="form-control bg-dark text-light border-secondary border-opacity-25" value="<?= e($m['name']) ?>" placeholder="e.g. Machine 1 (Full Vendo) or Machine 2 (Demo Setup)" required>
                                            </div>
                                        </div>
                                        <div class="modal-footer border-secondary border-opacity-25">
                                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary btn-sm fw-bold">Save Name</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <?php endforeach; ?>
                    <?php endif; ?>

                </div>


                <!-- ======================================================== -->
                <!-- TAB 2: FACTORY PROVISIONING & KEYS                       -->
                <!-- ======================================================== -->
                <div class="tab-pane fade <?= $activeTab === 'provisioning' ? 'show active' : '' ?>" id="provisioningPane" role="tabpanel" aria-labelledby="provisioning-tab">
                    
                    <!-- Hardware Provisioning Workflow Visual Guide -->
                    <div class="admin-card mb-4">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h5 class="fw-bold text-light mb-0">
                                <i class="bi bi-cpu text-info me-2"></i>ESP32 Hardware Provisioning Workflow
                            </h5>
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#setupGuideDetails">
                                <i class="bi bi-question-circle me-1"></i>Setup Steps
                            </button>
                        </div>

                        <div class="row g-3">
                            <div class="col-12 col-md-4">
                                <div class="p-3 rounded-3 h-100" style="background: rgba(14, 165, 233, 0.08); border: 1px solid rgba(14, 165, 233, 0.25);">
                                    <div class="d-flex align-items-center mb-2">
                                        <span class="step-num-badge bg-primary text-light me-2">1</span>
                                        <span class="fw-bold text-info">ESP32 Connects to Wi-Fi</span>
                                    </div>
                                    <p class="small text-secondary mb-0">Power on controller $\rightarrow$ Connect phone to <code class="text-info">VendoLink-Setup-XXXX</code> $\rightarrow$ Enter venue Wi-Fi name and password.</p>
                                </div>
                            </div>

                            <div class="col-12 col-md-4">
                                <div class="p-3 rounded-3 h-100" style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25);">
                                    <div class="d-flex align-items-center mb-2">
                                        <span class="step-num-badge bg-warning text-dark me-2">2</span>
                                        <span class="fw-bold text-warning">Automatic Enrollment</span>
                                    </div>
                                    <p class="small text-secondary mb-0">Controller contacts the server with its hardware MAC address and receives a machine ID (<code class="text-warning">MCH-XXXX</code>).</p>
                                </div>
                            </div>

                            <div class="col-12 col-md-4">
                                <div class="p-3 rounded-3 h-100" style="background: rgba(34, 197, 94, 0.08); border: 1px solid rgba(34, 197, 94, 0.25);">
                                    <div class="d-flex align-items-center mb-2">
                                        <span class="step-num-badge bg-success text-light me-2">3</span>
                                        <span class="fw-bold text-success">Key Generation &amp; Packaging</span>
                                    </div>
                                    <p class="small text-secondary mb-0">Click <strong>Generate Key</strong> below, print the packaging sticker, and paste it on the machine box for customer claiming.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Collapsible Full Guide -->
                        <div class="collapse mt-3" id="setupGuideDetails">
                            <div class="p-3 rounded-3 bg-dark bg-opacity-75 border border-secondary border-opacity-25 small text-secondary">
                                <h6 class="fw-bold text-light mb-2"><i class="bi bi-gear-wide-connected text-info me-2"></i>Detailed Controller Setup &amp; Wi-Fi Provisioning Steps</h6>
                                <div class="row g-3">
                                    <div class="col-12 col-md-6">
                                        <div class="fw-semibold text-info mb-1"><i class="bi bi-phone me-1"></i>Step-by-Step Hardware Wi-Fi Setup:</div>
                                        <ol class="ps-3 mb-2">
                                            <li>Power on the ESP32 vending machine controller (12V PSU or USB).</li>
                                            <li>The 20x4 LCD will display <span class="badge bg-secondary">PORTAL ACTIVE</span>.</li>
                                            <li>On your smartphone or PC, look for Wi-Fi SSID: <strong class="text-light">VendoLink-Setup-XXXX</strong>.</li>
                                            <li>Connect using default password: <code class="text-warning">vendolink123</code>.</li>
                                            <li>A captive portal popup will appear (or navigate to <code class="text-info">http://192.168.4.1</code>).</li>
                                            <li>Enter your venue's Wi-Fi SSID and Password, then click <strong>Save &amp; Connect</strong>.</li>
                                        </ol>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <div class="fw-semibold text-info mb-1"><i class="bi bi-cloud-check me-1"></i>Automatic Handshake &amp; Enrollment:</div>
                                        <ul class="ps-3 mb-2">
                                            <li>The ESP32 connects to Wi-Fi and gets an IP address from your router.</li>
                                            <li>It securely contacts <code class="text-light">/api/machine/register.php</code> using its hardware eFuse MAC.</li>
                                            <li>The controller receives unique per-device credentials (<span class="badge bg-primary">MCH-XXXX</span>).</li>
                                            <li>The machine immediately appears in the <strong>Provisioning &amp; Key Pairing Table</strong> below!</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Single Unified Hardware & Keys Table -->
                    <div class="admin-card">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-2">
                            <div>
                                <h5 class="fw-bold text-light mb-1">
                                    <i class="bi bi-cpu text-info me-2"></i>Hardware Enrollment &amp; Product Keys
                                </h5>
                                <p class="text-secondary small mb-0">Every physical ESP32 controller and its paired 16-character Product Key.</p>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <div class="input-group input-group-sm" style="max-width: 250px;">
                                    <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary"><i class="bi bi-search"></i></span>
                                    <input type="text" id="machineSearchInput" class="form-control bg-dark text-light border-secondary border-opacity-25" placeholder="Filter MAC or Code..." onkeyup="filterMachinesTable(this.value);">
                                </div>
                                <button type="button" class="btn btn-warning btn-sm text-dark fw-bold text-nowrap" data-bs-toggle="modal" data-bs-target="#quickGenerateModal">
                                    <i class="bi bi-plus-circle-fill me-1"></i>New Key
                                </button>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-dark admin-table mb-0" id="enrolledMachinesTable">
                                <thead>
                                    <tr>
                                        <th style="white-space: nowrap;">Machine Hardware</th>
                                        <th style="white-space: nowrap;">Factory MAC</th>
                                        <th style="white-space: nowrap;">Status</th>
                                        <th style="white-space: nowrap;">Product Key / Sticker</th>
                                        <th style="white-space: nowrap;">Customer Owner</th>
                                        <th style="white-space: nowrap;" class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($enrolledMachines)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-5 text-secondary">
                                            <div class="p-4 rounded-3 d-inline-block text-center" style="background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(148, 163, 184, 0.15); max-width: 520px;">
                                                <i class="bi bi-cpu text-info fs-1 d-block mb-3"></i>
                                                <h5 class="text-light fw-bold mb-2">No Auto-Enrolled ESP32s Detected Yet</h5>
                                                <p class="text-secondary small mb-3">When you power on your ESP32 controller and connect it to venue Wi-Fi, it will automatically register here.<br>Or you can create an activation key manually.</p>
                                                <button type="button" class="btn btn-warning text-dark fw-bold px-4 py-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#quickGenerateModal">
                                                    <i class="bi bi-plus-circle-fill me-1"></i>Generate Key Now
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($enrolledMachines as $em): ?>
                                            <?php
                                             $regStatus = $em['registration_status'] ?? 'legacy';
                                             $hasKey = !empty($em['pk_id']) && !empty($em['product_key']);
                                             $isClaimed = ($regStatus === 'claimed' || !empty($em['owner_id']) || !empty($em['owner_name']));
                                             $isEligible = (!$hasKey && !$isClaimed && $regStatus !== 'disabled');
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold text-light"><?= e($em['machine_code']) ?></div>
                                                    <div class="small text-secondary"><?= e($em['name']) ?></div>
                                                </td>
                                                <td>
                                                    <?php if (!empty($em['device_mac'])): ?>
                                                        <code class="text-info"><?= e($em['device_mac']) ?></code>
                                                    <?php else: ?>
                                                        <span class="text-secondary small fst-italic">None (Legacy)</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($regStatus === 'registered'): ?>
                                                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-2.5 py-1 fw-semibold">
                                                            <i class="bi bi-cpu me-1"></i>Enrolled (Ready for Key)
                                                        </span>
                                                    <?php elseif ($regStatus === 'key_issued'): ?>
                                                        <span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 rounded-pill px-2.5 py-1 fw-semibold">
                                                            <i class="bi bi-key me-1"></i>Key Ready
                                                        </span>
                                                    <?php elseif ($regStatus === 'claimed'): ?>
                                                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2.5 py-1 fw-semibold">
                                                            <i class="bi bi-patch-check-fill me-1"></i>Claimed
                                                        </span>
                                                    <?php elseif ($regStatus === 'disabled'): ?>
                                                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-2.5 py-1 fw-semibold">
                                                            <i class="bi bi-slash-circle-fill me-1"></i>Disabled
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary bg-opacity-25 text-slate-300 border border-secondary border-opacity-25 rounded-pill px-2.5 py-1 fw-semibold">
                                                            <i class="bi bi-archive me-1"></i>Legacy
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($hasKey): ?>
                                                        <div class="d-inline-flex align-items-center">
                                                            <span class="font-monospace text-warning fw-bold fs-6 text-break"><?= e($em['product_key']) ?></span>
                                                            <?php if (!$isClaimed && strtoupper($em['pk_status'] ?? '') !== 'USED'): ?>
                                                                <button type="button" class="btn btn-sm btn-outline-warning ms-2 py-0 px-2 rounded-pill" style="font-size: 0.72rem;" onclick="copyKey('<?= e($em['product_key']) ?>', this);" title="Copy Key">
                                                                    <i class="bi bi-clipboard"></i> Copy
                                                                </button>
                                                                <button type="button" class="btn btn-sm btn-outline-info ms-1 py-0 px-2 rounded-pill" style="font-size: 0.72rem;" onclick="openSticker('<?= e($em['name'] ?: $em['machine_code']) ?>', '<?= e($em['device_mac'] ?? '') ?>', '<?= e($em['product_key']) ?>');" title="Print Packaging Sticker">
                                                                    <i class="bi bi-tag-fill"></i> Sticker
                                                                </button>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="mt-1 d-flex align-items-center gap-1">
                                                            <?php if ($isClaimed || strtoupper($em['pk_status'] ?? '') === 'USED'): ?>
                                                                <span class="badge bg-secondary bg-opacity-25 text-slate-400 small" title="This key has already been activated once and cannot be reused">
                                                                    <i class="bi bi-lock-fill me-1"></i>One-Time Used
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="badge bg-light bg-opacity-10 text-light"><?= strtoupper(e($em['pk_status'])) ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-secondary small fst-italic">No Key Issued</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($em['owner_name'])): ?>
                                                        <div class="text-light fw-medium"><?= e($em['owner_name']) ?></div>
                                                        <div class="small text-secondary"><?= e($em['owner_email'] ?? '') ?></div>
                                                    <?php else: ?>
                                                        <span class="text-secondary small fst-italic">Unassigned (Ready for Customer)</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end text-nowrap">
                                                    <?php if ($isEligible): ?>
                                                        <form method="POST" class="d-inline">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="generate_key">
                                                            <input type="hidden" name="machine_id" value="<?= (int)$em['id'] ?>">
                                                            <input type="hidden" name="hardware_serial" value="<?= e($em['machine_code']) ?>">
                                                            <button type="submit" class="btn btn-sm btn-warning text-dark fw-bold px-3 py-1 shadow-sm">
                                                                <i class="bi bi-key-fill me-1"></i>Generate Key
                                                            </button>
                                                        </form>
                                                    <?php elseif ($isClaimed): ?>
                                                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2.5 py-1 fw-semibold">
                                                            <i class="bi bi-patch-check-fill me-1"></i>Claimed &amp; Active
                                                        </span>
                                                    <?php elseif ($hasKey): ?>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Disable this activation key?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="revoke_key">
                                                            <input type="hidden" name="id" value="<?= (int)$em['pk_id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger py-0.5 px-2" title="Disable Key">
                                                                <i class="bi bi-slash-circle me-1"></i>Disable
                                                            </button>
                                                        </form>
                                                    <?php elseif ($regStatus === 'disabled'): ?>
                                                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-2.5 py-1">Decommissioned</span>
                                                    <?php else: ?>
                                                        <span class="text-secondary small fst-italic">Legacy Unit</span>
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

<!-- ================================================================ -->
<!-- MODALS: QUICK GENERATE, PACKAGING STICKER, SHARE EMAIL          -->
<!-- ================================================================ -->

<!-- Quick Generate Activation Key Modal -->
<div class="modal fade" id="quickGenerateModal" tabindex="-1" aria-labelledby="quickGenerateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title text-light" id="quickGenerateModalLabel">
                    <i class="bi bi-key-fill text-warning me-2"></i>Generate Activation Key
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <?php if (empty($availableUnassignedMachines)): ?>
                <div class="modal-body p-4 text-center py-5">
                    <div class="p-4 rounded-3 d-inline-block text-center w-100" style="background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(148, 163, 184, 0.15);">
                        <i class="bi bi-cpu text-info fs-1 d-block mb-3"></i>
                        <h5 class="text-light fw-bold mb-2">No Unassigned ESP32 Available</h5>
                        <p class="text-secondary small mb-3">
                            All detected ESP32 controllers already have product keys or are claimed.<br>
                            To issue a new activation key, power on a new ESP32 controller and connect it to Wi-Fi to auto-enroll it first.
                        </p>
                        <button type="button" class="btn btn-outline-secondary px-4 py-2" data-bs-dismiss="modal">
                            Close
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="generate_key">
                    <div class="modal-body p-4">
                        <div class="alert alert-secondary border-secondary bg-dark text-secondary small mb-3">
                            <i class="bi bi-info-circle text-info me-1"></i>
                            Select an enrolled ESP32 controller to issue a unique 16-character Product Key for packaging.
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold">CHOOSE ENROLLED ESP32 CONTROLLER <span class="text-danger">*</span></label>
                            <select name="machine_id" class="form-select bg-dark text-light border-secondary" required onchange="var opt=this.options[this.selectedIndex]; var code=opt.getAttribute('data-code'); if(code) document.getElementById('modalSerialInput').value=code;">
                                <option value="">-- Select Enrolled ESP32 --</option>
                                <?php foreach ($availableUnassignedMachines as $m): ?>
                                    <option value="<?= (int)$m['id'] ?>" data-code="<?= e($m['machine_code']) ?>">
                                        <?= e($m['machine_code']) ?> &bull; <?= e($m['name'] ?: 'ESP32 Controller') ?> (MAC: <?= e($m['device_mac'] ?? 'Pending') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold">MACHINE HARDWARE CODE</label>
                            <input type="text" name="hardware_serial" id="modalSerialInput" class="form-control bg-dark text-light border-secondary font-monospace" placeholder="Select machine above" readonly required>
                            <div class="form-text text-secondary small">Automatically locked to the selected enrolled hardware.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold">ASSIGN TO CUSTOMER (OPTIONAL)</label>
                            <input type="text" name="assigned_owner" class="form-control bg-dark text-light border-secondary" placeholder="e.g. Kenji Prado (Leave blank to assign later)">
                            <div class="form-text text-secondary small">Customer can also claim it later by entering this key during signup.</div>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning text-dark fw-bold px-4">
                            <i class="bi bi-key-fill me-1"></i>Generate Key Now
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Packaging Sticker Modal -->
<div class="modal fade" id="stickerModal" tabindex="-1" aria-labelledby="stickerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary">
            <div class="modal-header border-secondary no-print">
                <h5 class="modal-title text-light" id="stickerModalLabel">
                    <i class="bi bi-tag-fill text-warning me-2"></i>Machine Packaging Sticker
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <p class="text-secondary small mb-3 no-print">Attach this sticker to the ESP32 controller box or machine chassis for the customer.</p>
                
                <div id="stickerPrintArea">
                    <div class="sticker-box text-start">
                        <div class="d-flex align-items-center justify-content-between border-bottom pb-2 mb-3">
                            <div>
                                <h5 class="fw-bold mb-0 text-primary" style="letter-spacing: -0.5px;">VendoLink</h5>
                                <div style="color: #475569; font-size: 0.72rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em;">Smart Vendo Controller</div>
                            </div>
                            <span class="badge bg-primary text-white px-2 py-1" style="font-size: 0.7rem;">OFFICIAL HARDWARE</span>
                        </div>

                        <div class="row align-items-center mb-3">
                            <div class="col-7">
                                <div style="color: #475569; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.05em;">DEVICE NAME</div>
                                <div class="fw-bold text-dark fs-5 text-truncate" id="stickerMachineName">MACHINE-01</div>
                                
                                <div class="mt-2" style="color: #475569; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.05em;">HARDWARE MAC</div>
                                <div class="font-monospace small fw-bold" id="stickerMac" style="color: #334155;">AA:BB:CC:DD:EE:FF</div>
                            </div>
                            <div class="col-5 text-center">
                                <img id="stickerQrCode" src="" alt="Claim QR Code" class="img-fluid rounded border p-1 bg-white" style="width: 105px; height: 105px;" />
                                <div class="text-center" style="color: #475569; font-size: 0.65rem; font-weight: 700; margin-top: 3px; letter-spacing: 0.05em;">SCAN TO CLAIM</div>
                            </div>
                        </div>

                        <div class="p-2.5 rounded-3 mb-3 text-center" style="background: #f1f5f9; border: 1px solid #cbd5e1;">
                            <div style="color: #475569; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.05em;">PRODUCT ACTIVATION KEY</div>
                            <div class="font-monospace fw-bolder text-primary fs-4 letter-spacing-1 mt-1 text-break" id="stickerProductKey" style="letter-spacing: 1px;">VL-2026-XXXX</div>
                        </div>

                        <div class="rounded-2 p-2" style="background: #f8fafc; border-left: 3px solid #0284c7; font-size: 0.75rem; line-height: 1.4; color: #475569;">
                            <div class="fw-bold text-dark mb-1">Simple 3-Step Setup:</div>
                            <div>1. Turn on machine &amp; connect phone to Wi-Fi: <strong>VendoLink-Setup-XXXX</strong></div>
                            <div>2. Enter venue Wi-Fi name &amp; password in popup window.</div>
                            <div>3. Scan QR code or create account and enter Product Key!</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-secondary justify-content-between no-print">
                <span class="text-secondary small"><i class="bi bi-printer me-1"></i>Standard 4x3 or Label Print</span>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>

                    <!-- Icon-Only Share Dropdown Button -->
                    <div class="dropdown d-inline-block">
                        <button type="button" class="btn btn-outline-info px-2.5" id="btnStickerShareDropdown" data-bs-toggle="dropdown" aria-expanded="false" title="Share Options (Email / Photo)">
                            <i class="bi bi-share-fill"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end bg-dark border-secondary shadow-lg py-1" aria-labelledby="btnStickerShareDropdown" style="min-width: 220px;">
                            <li>
                                <button type="button" class="dropdown-item text-light d-flex align-items-center gap-2.5 py-2" onclick="openShareEmailModal();">
                                    <i class="bi bi-envelope-fill text-info fs-5"></i>
                                    <div>
                                        <div class="fw-semibold small">Share to Email</div>
                                        <div class="text-secondary" style="font-size: 0.72rem;">Send key &amp; instructions</div>
                                    </div>
                                </button>
                            </li>
                            <li><hr class="dropdown-divider border-secondary opacity-50 my-1"></li>
                            <li>
                                <button type="button" class="dropdown-item text-light d-flex align-items-center gap-2.5 py-2" onclick="exportStickerAsPhoto();">
                                    <i class="bi bi-image-fill text-success fs-5"></i>
                                    <div>
                                        <div class="fw-semibold small">Export as Photo</div>
                                        <div class="text-secondary" style="font-size: 0.72rem;">Download crisp PNG image</div>
                                    </div>
                                </button>
                            </li>
                        </ul>
                    </div>

                    <button type="button" class="btn btn-primary fw-semibold" onclick="printSticker();">
                        <i class="bi bi-printer-fill me-1"></i>Print Sticker
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Share Sticker to Email Modal -->
<div class="modal fade" id="stickerEmailModal" tabindex="-1" aria-labelledby="stickerEmailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary shadow-lg">
            <div class="modal-header border-secondary">
                <h5 class="modal-title text-light" id="stickerEmailModalLabel">
                    <i class="bi bi-envelope-fill text-info me-2"></i>Share Sticker to Email
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="shareEmailForm" onsubmit="sendStickerEmail(event);">
                <div class="modal-body p-4 text-start">
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">RECIPIENT EMAIL ADDRESS <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-envelope"></i></span>
                            <input type="email" class="form-control bg-dark text-light border-secondary" id="shareRecipientEmail" placeholder="e.g. customer@gmail.com" required>
                        </div>
                        <div class="form-text text-muted small">The customer will receive the Product Activation Key, Wi-Fi setup steps, and 1-click claim link.</div>
                    </div>

                    <div class="p-3 rounded-3 bg-dark border border-secondary mb-3 small">
                        <div class="d-flex justify-content-between mb-1.5">
                            <span class="text-secondary">Device Name:</span>
                            <span class="text-light fw-bold" id="emailPreviewMachine">MACHINE-01</span>
                        </div>
                        <div class="d-flex justify-content-between mb-1.5">
                            <span class="text-secondary">Hardware MAC:</span>
                            <span class="font-monospace text-light" id="emailPreviewMac">AA:BB:CC:DD:EE:FF</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-secondary">Product Key:</span>
                            <span class="font-monospace text-primary fw-bold fs-6" id="emailPreviewKey">VL-2026-XXXX</span>
                        </div>
                    </div>

                    <div id="emailSendAlert" class="d-none"></div>
                </div>
                <div class="modal-footer border-secondary justify-content-between">
                    <button type="button" class="btn btn-sm btn-outline-info" onclick="openMailtoApp();" title="Open in your default email client (Gmail / Outlook / Apple Mail)">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Open in Mail App
                    </button>
                    <div>
                        <button type="button" class="btn btn-outline-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary fw-semibold" id="btnSendStickerEmail">
                            <i class="bi bi-send-fill me-1"></i>Send Email
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="<?= ASSETS_URL ?>/js/app.js?v=<?= e((string) filemtime(ROOT_PATH . '/assets/css/app.css')) ?>"></script>
<script>
// Clipboard Copy Helper
function copyKey(keyText, btn) {
    if (!keyText) return;
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(keyText).then(function() {
            showCopied(btn);
        }).catch(function() {
            fallbackCopy(keyText, btn);
        });
    } else {
        fallbackCopy(keyText, btn);
    }
}

function fallbackCopy(text, btn) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try {
        document.execCommand('copy');
        showCopied(btn);
    } catch (e) {}
    document.body.removeChild(ta);
}

function showCopied(btn) {
    var orig = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check2"></i> Copied!';
    btn.classList.remove('btn-outline-warning');
    btn.classList.add('btn-success', 'text-white');
    setTimeout(function() {
        btn.innerHTML = orig;
        btn.classList.remove('btn-success', 'text-white');
        btn.classList.add('btn-outline-warning');
    }, 2000);
}

// Packaging Sticker Modal Handler
var currentStickerData = { machineName: '', mac: '', productKey: '' };

function openSticker(machineName, mac, productKey) {
    currentStickerData.machineName = machineName || 'Vendo Controller';
    currentStickerData.mac = mac || '';
    currentStickerData.productKey = productKey || '';

    document.getElementById('stickerMachineName').textContent = currentStickerData.machineName;
    document.getElementById('stickerMac').textContent = mac ? 'MAC: ' + mac : 'MAC: N/A';
    document.getElementById('stickerProductKey').textContent = currentStickerData.productKey;
    
    var baseUrl = <?= json_encode(BASE_URL) ?>;
    var registerUrl = baseUrl + '/auth/register.php?key=' + encodeURIComponent(currentStickerData.productKey);
    var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' + encodeURIComponent(registerUrl);
    
    var qrImg = document.getElementById('stickerQrCode');
    if (qrImg) {
        qrImg.crossOrigin = 'anonymous';
        qrImg.src = qrUrl;
    }

    var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('stickerModal'));
    modal.show();
}

// Export Sticker as Photo (3x DPI PNG)
function exportStickerAsPhoto() {
    var stickerEl = document.querySelector('#stickerPrintArea .sticker-box');
    if (!stickerEl) return;
    
    var shareBtn = document.getElementById('btnStickerShareDropdown');
    var origHtml = shareBtn ? shareBtn.innerHTML : '';
    if (shareBtn) shareBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span>';

    html2canvas(stickerEl, {
        scale: 3,
        useCORS: true,
        allowTaint: true,
        backgroundColor: '#ffffff',
        logging: false
    }).then(function(canvas) {
        if (shareBtn) shareBtn.innerHTML = origHtml;
        var mName = currentStickerData.machineName || 'Machine';
        var safeName = mName.replace(/[^a-zA-Z0-9_-]/g, '_');
        var link = document.createElement('a');
        link.download = 'Sticker_' + safeName + '_' + (currentStickerData.productKey || 'Key') + '.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
    }).catch(function(err) {
        if (shareBtn) shareBtn.innerHTML = origHtml;
        console.error('Photo export error:', err);
        alert('Could not export photo directly. You can use Print -> Save as PDF.');
    });
}

// Share to Email Modal
function openShareEmailModal() {
    document.getElementById('emailPreviewMachine').textContent = currentStickerData.machineName || 'Machine';
    document.getElementById('emailPreviewMac').textContent = currentStickerData.mac ? 'MAC: ' + currentStickerData.mac : 'MAC: N/A';
    document.getElementById('emailPreviewKey').textContent = currentStickerData.productKey || 'N/A';
    
    var alertBox = document.getElementById('emailSendAlert');
    if (alertBox) {
        alertBox.className = 'd-none';
        alertBox.innerHTML = '';
    }
    
    var emailInput = document.getElementById('shareRecipientEmail');
    if (emailInput) emailInput.value = '';

    var emailModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('stickerEmailModal'));
    emailModal.show();
}

function sendStickerEmail(e) {
    if (e) e.preventDefault();
    var email = document.getElementById('shareRecipientEmail').value.trim();
    if (!email) return;

    var alertBox = document.getElementById('emailSendAlert');
    var sendBtn = document.getElementById('btnSendStickerEmail');
    var origBtnHtml = sendBtn ? sendBtn.innerHTML : '';

    if (sendBtn) {
        sendBtn.disabled = true;
        sendBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending...';
    }

    var baseUrl = <?= json_encode(BASE_URL) ?>;
    var formData = new URLSearchParams();
    formData.append('recipient_email', email);
    formData.append('machine_name', currentStickerData.machineName);
    formData.append('mac_address', currentStickerData.mac);
    formData.append('product_key', currentStickerData.productKey);

    fetch(baseUrl + '/api/share_sticker.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData.toString()
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (sendBtn) {
            sendBtn.disabled = false;
            sendBtn.innerHTML = origBtnHtml;
        }
        if (alertBox) {
            if (data.success) {
                alertBox.className = 'alert alert-success small mb-0 py-2';
                alertBox.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> ' + (data.message || 'Email sent successfully!');
                setTimeout(function() {
                    var emailModal = bootstrap.Modal.getInstance(document.getElementById('stickerEmailModal'));
                    if (emailModal) emailModal.hide();
                }, 1800);
            } else {
                alertBox.className = 'alert alert-warning small mb-0 py-2';
                alertBox.innerHTML = '<div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + (data.error || 'Failed to send email.') + '</div>'
                    + '<button type="button" class="btn btn-sm btn-outline-light mt-1.5" onclick="openMailtoApp();"><i class="bi bi-box-arrow-up-right me-1"></i>Open in Mail App instead</button>';
            }
        }
    })
    .catch(function(err) {
        if (sendBtn) {
            sendBtn.disabled = false;
            sendBtn.innerHTML = origBtnHtml;
        }
        if (alertBox) {
            alertBox.className = 'alert alert-warning small mb-0 py-2';
            alertBox.innerHTML = 'Delivery failed. <a href="javascript:void(0)" onclick="openMailtoApp()" class="text-light text-decoration-underline">Click here to send via your email client.</a>';
        }
    });
}

function openMailtoApp() {
    var email = document.getElementById('shareRecipientEmail') ? document.getElementById('shareRecipientEmail').value.trim() : '';
    var baseUrl = <?= json_encode(BASE_URL) ?>;
    var claimUrl = baseUrl + '/auth/register.php?key=' + encodeURIComponent(currentStickerData.productKey);
    var subject = encodeURIComponent("VendoLink Machine Packaging Sticker & Product Key [" + currentStickerData.machineName + "]");
    var body = encodeURIComponent(
        "Hello,\n\n"
        + "Here are your VendoLink smart vending machine setup details:\n\n"
        + "Device Name: " + currentStickerData.machineName + "\n"
        + "Hardware MAC: " + currentStickerData.mac + "\n"
        + "Product Activation Key: " + currentStickerData.productKey + "\n\n"
        + "Simple 3-Step Setup:\n"
        + "1. Turn on machine & connect phone to Wi-Fi: VendoLink-Setup-XXXX\n"
        + "2. Enter your venue Wi-Fi name & password in the popup window.\n"
        + "3. Claim your machine using this link:\n"
        + claimUrl + "\n\n"
        + "VendoLink IoT Support"
    );

    window.location.href = "mailto:" + encodeURIComponent(email) + "?subject=" + subject + "&body=" + body;
}

// 1-Page Isolated Print Helper
function printSticker() {
    var stickerEl = document.getElementById('stickerPrintArea');
    if (!stickerEl) return;

    var iframe = document.getElementById('printStickerIframe');
    if (iframe) iframe.remove();
    iframe = document.createElement('iframe');
    iframe.id = 'printStickerIframe';
    iframe.style.position = 'fixed';
    iframe.style.right = '0';
    iframe.style.bottom = '0';
    iframe.style.width = '0';
    iframe.style.height = '0';
    iframe.style.border = '0';
    document.body.appendChild(iframe);

    var doc = iframe.contentWindow.document;
    doc.open();
    doc.write('<!DOCTYPE html>'
        + '<html><head><meta charset="UTF-8">'
        + '<title>Machine Packaging Sticker - VendoLink</title>'
        + '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">'
        + '<style>'
        + '@page { size: auto; margin: 10mm; }'
        + 'body { margin: 0; padding: 25px 0; background: #ffffff !important; color: #0f172a !important; display: flex; justify-content: center; }'
        + '.sticker-box { background: #ffffff !important; color: #0f172a !important; border: 2px dashed #0284c7 !important; border-radius: 12px; padding: 20px; width: 440px; max-width: 100%; box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }'
        + '</style>'
        + '</head><body>'
        + stickerEl.innerHTML
        + '</body></html>'
    );
    doc.close();

    setTimeout(function() {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    }, 250);
}

// Filter Hardware Table in Provisioning Tab
function filterMachinesTable(query) {
    var q = (query || '').toLowerCase().trim();
    var table = document.getElementById('enrolledMachinesTable');
    if (!table) return;
    var rows = table.querySelectorAll('tbody tr');
    rows.forEach(function(row) {
        var text = row.innerText.toLowerCase();
        row.style.display = text.indexOf(q) !== -1 ? '' : 'none';
    });
}

// Sync tab with URL hash if present
document.addEventListener('DOMContentLoaded', function() {
    var hash = window.location.hash;
    if (hash === '#provisioning') {
        var pTab = document.getElementById('provisioning-tab');
        if (pTab) bootstrap.Tab.getOrCreateInstance(pTab).show();
    } else if (hash === '#fleet') {
        var fTab = document.getElementById('fleet-tab');
        if (fTab) bootstrap.Tab.getOrCreateInstance(fTab).show();
    }
});
</script>
</body>
</html>
