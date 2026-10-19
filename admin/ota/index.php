<?php
/**
 * ============================================================
 * VendoLink — Wireless Updates (OTA Firmware Center)
 * ============================================================
 *
 * Allows Administrators to upload ESP32 firmware binaries (.bin)
 * and wirelessly deploy updates over Wi-Fi:
 * 1. General Update (All Machines)
 * 2. Specific Customer (All machines belonging to a customer)
 * 3. Specific Machine (e.g. Machine 1 or Machine 2)
 *
 * @package VendoLink
 * @version 3.0.0
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

// Check if a specific machine was pre-selected from "All Machines" screen
$preselectedMachineId = filter_var($_GET['target_machine'] ?? 0, FILTER_VALIDATE_INT) ?: 0;

// AJAX Live Status Endpoint for Real-time Polling
if (isset($_GET['action']) && $_GET['action'] === 'poll_status') {
    header('Content-Type: application/json; charset=UTF-8');
    $active = $dashboardModel->getActiveOtaDeployments();
    echo json_encode([
        'success'      => true,
        'active_count' => count($active),
        'active'       => $active,
        'timestamp'    => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// Handle POST actions with strict Post-Redirect-Get (PRG) to prevent duplicate submissions on refresh
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // Action 1: Upload New Firmware Binary (.bin)
    if ($action === 'upload_firmware') {
        $version   = trim($_POST['version'] ?? '');
        $changelog = trim($_POST['changelog'] ?? '');

        if (empty($version)) {
            flash('danger', "Please enter a version name (e.g. v3.1.0).");
        } elseif (!isset($_FILES['firmware_file']) || $_FILES['firmware_file']['error'] !== UPLOAD_ERR_OK) {
            flash('danger', "Please select a valid ESP32 binary (.bin) file to upload.");
        } else {
            $file     = $_FILES['firmware_file'];
            $fileName = basename($file['name']);
            $fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $fileSize = (int)$file['size'];

            if ($fileExt !== 'bin') {
                flash('danger', "Invalid file type. Only compiled ESP32 firmware (.bin) files are accepted.");
            } elseif ($fileSize > 4194304) { // 4MB Max
                flash('danger', "File size exceeds 4MB limit.");
            } else {
                $uploadDir = ROOT_PATH . '/uploads/firmware/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $cleanVer     = preg_replace('/[^a-zA-Z0-9_\.-]/', '', $version);
                $safeFilename = 'vendolink_esp32_' . $cleanVer . '.bin';
                $targetPath   = $uploadDir . $safeFilename;

                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    $dashboardModel->createOtaRelease($version, $safeFilename, $fileSize, $changelog);
                    flash('success', "Firmware release <strong>{$version}</strong> uploaded successfully! Ready for wireless deployment.", true);
                    log_activity('ADMIN_ACTION', "Uploaded OTA firmware binary release {$version}");
                } else {
                    flash('danger', "Failed to save binary file to uploads directory.");
                }
            }
        }
        redirect('admin/ota/index.php' . ($preselectedMachineId > 0 ? '?target_machine=' . $preselectedMachineId : ''));
        exit;
    }

    // Action 2: Deploy Wireless Update (General or Specific)
    elseif ($action === 'deploy_ota') {
        $releaseId  = (int)($_POST['release_id'] ?? 0);
        $targetType = trim($_POST['target_type'] ?? 'all');
        $targetId   = null;

        if ($targetType === 'customer') {
            $targetId = (int)($_POST['customer_id'] ?? 0);
        } elseif ($targetType === 'machine') {
            $targetId = (int)($_POST['machine_id'] ?? 0);
        }

        if ($releaseId <= 0) {
            flash('danger', "Please select a firmware release build to deploy.");
        } elseif ($targetType === 'customer' && ($targetId === null || $targetId <= 0)) {
            flash('danger', "Please select which customer will receive the update.");
        } elseif ($targetType === 'machine' && ($targetId === null || $targetId <= 0)) {
            flash('danger', "Please select which machine will receive the update.");
        } else {
            $targetDesc = 'All Machines Nationwide';
            if ($targetType === 'customer') {
                $targetDesc = 'Customer ID #' . $targetId;
            } elseif ($targetType === 'machine') {
                $targetDesc = 'Machine ID #' . $targetId;
            }

            // Pre-validation: Check if all target machines in scope already run this version
            $db = Database::getInstance();
            $targetRelease = $db->query("SELECT version FROM ota_releases WHERE id = :id", [':id' => $releaseId])->fetch();
            $alreadyUpdated = false;

            if ($targetRelease) {
                $cleanTargetVer = strtolower(ltrim(trim($targetRelease['version']), 'v'));

                if ($targetType === 'machine') {
                    $m = $db->query("SELECT firmware_version, name, machine_code FROM machines WHERE id = :id", [':id' => $targetId])->fetch();
                    if ($m && strtolower(ltrim(trim($m['firmware_version'] ?? ''), 'v')) === $cleanTargetVer) {
                        $alreadyUpdated = true;
                        $targetDesc = $m['name'] ?: $m['machine_code'];
                    }
                } elseif ($targetType === 'customer') {
                    $mList = $db->query("SELECT firmware_version FROM machines WHERE owner_id = :id AND registration_status != 'disabled'", [':id' => $targetId])->fetchAll();
                    if (!empty($mList)) {
                        $allSame = true;
                        foreach ($mList as $tm) {
                            if (strtolower(ltrim(trim($tm['firmware_version'] ?? ''), 'v')) !== $cleanTargetVer) {
                                $allSame = false;
                                break;
                            }
                        }
                        $alreadyUpdated = $allSame;
                    }
                } else {
                    // all
                    $mList = $db->query("SELECT firmware_version FROM machines WHERE registration_status != 'disabled'")->fetchAll();
                    if (!empty($mList)) {
                        $allSame = true;
                        foreach ($mList as $tm) {
                            if (strtolower(ltrim(trim($tm['firmware_version'] ?? ''), 'v')) !== $cleanTargetVer) {
                                $allSame = false;
                                break;
                            }
                        }
                        $alreadyUpdated = $allSame;
                    }
                }

                if ($alreadyUpdated) {
                    flash('warning', "Notice: <strong>{$targetDesc}</strong> is already running firmware version <strong>v{$cleanTargetVer}</strong>. No update needed.", true);
                    redirect('admin/ota/index.php' . ($preselectedMachineId > 0 ? '?target_machine=' . $preselectedMachineId : ''));
                    exit;
                }
            }

            $deploymentId = $dashboardModel->createOtaDeployment($releaseId, $targetType, $targetId);

            flash('success', "Wireless update armed! Target: <strong>{$targetDesc}</strong>. Online controllers will update on their next signal.", true);
            log_activity('ADMIN_ACTION', "Dispatched OTA deployment #{$deploymentId} to {$targetType} target");
        }
        redirect('admin/ota/index.php' . ($preselectedMachineId > 0 ? '?target_machine=' . $preselectedMachineId : ''));
        exit;
    }

    // Action 3: Cancel Active Deployment
    elseif ($action === 'cancel_deployment') {
        $deploymentId = (int)($_POST['deployment_id'] ?? 0);
        if ($deploymentId > 0) {
            $dashboardModel->cancelOtaDeployment($deploymentId);
            flash('success', "Wireless update deployment #{$deploymentId} has been cancelled.");
            log_activity('ADMIN_ACTION', "Cancelled OTA deployment #{$deploymentId}");
        }
        redirect('admin/ota/index.php' . ($preselectedMachineId > 0 ? '?target_machine=' . $preselectedMachineId : ''));
        exit;
    }

    // Action 4: Manually Complete Active Deployment
    elseif ($action === 'complete_deployment') {
        $deploymentId = (int)($_POST['deployment_id'] ?? 0);
        if ($deploymentId > 0) {
            $dashboardModel->completeOtaDeployment($deploymentId);
            flash('success', "Wireless update deployment #{$deploymentId} has been marked as completed.");
            log_activity('ADMIN_ACTION', "Marked OTA deployment #{$deploymentId} completed");
        }
        redirect('admin/ota/index.php' . ($preselectedMachineId > 0 ? '?target_machine=' . $preselectedMachineId : ''));
        exit;
    }

    // Action 5: Delete Stored Firmware Release Build
    elseif ($action === 'delete_release') {
        $releaseId = (int)($_POST['release_id'] ?? 0);
        if ($releaseId > 0) {
            $dashboardModel->deleteOtaRelease($releaseId);
            flash('success', "Firmware build release has been deleted successfully.");
            log_activity('ADMIN_ACTION', "Deleted OTA firmware release build #{$releaseId}");
        }
        redirect('admin/ota/index.php' . ($preselectedMachineId > 0 ? '?target_machine=' . $preselectedMachineId : ''));
        exit;
    }

}

// Automatically clean up any duplicate completed history records
$dashboardModel->cleanDuplicateOtaHistory();

// 1. Stored Firmware Releases
$otaReleases = $dashboardModel->getOtaReleases();

// 2. Active Wireless Deployments (In-Progress) with Target Details & Progress
$activeDeployments = $dashboardModel->getActiveOtaDeployments();
$totalActive       = count($activeDeployments);

// 3. Separated Deployment History (Completed & Cancelled) with Status Filter & Pagination
$histFilter    = trim($_GET['hist_filter'] ?? 'all');
if (!in_array($histFilter, ['all', 'completed', 'cancelled'], true)) {
    $histFilter = 'all';
}
$historyCounts = $dashboardModel->getOtaHistoryCounts();
$totalHistory  = ($histFilter === 'all') ? $historyCounts['all'] : ($historyCounts[$histFilter] ?? 0);
$histPerPage   = 5;
$totalHistPages = max(1, (int)ceil($totalHistory / $histPerPage));
$histPage       = max(1, min((int)($_GET['hist_page'] ?? 1), $totalHistPages));
$histOffset     = ($histPage - 1) * $histPerPage;
$otaHistory     = $dashboardModel->getOtaDeploymentHistory($histPerPage, $histOffset, $histFilter);
$histUrl        = function(int $p, ?string $filter = null) use ($preselectedMachineId, $histFilter): string {
    $params = ['hist_page' => $p];
    $activeFilter = $filter ?? $histFilter;
    if ($activeFilter !== 'all') {
        $params['hist_filter'] = $activeFilter;
    }
    if ($preselectedMachineId > 0) {
        $params['target_machine'] = $preselectedMachineId;
    }
    return '?' . http_build_query($params);
};

$allMachines    = $dashboardModel->getAllMachines();
$allCustomers   = $dashboardModel->getAllCustomers();
$metrics        = $dashboardModel->getSummaryMetrics();
$latestRelease  = !empty($otaReleases) ? $otaReleases[0] : null;

$pageTitle   = 'Wireless Updates (OTA)';
$currentPage = 'ota';
$currentDir  = 'ota';
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
            border: 1px solid rgba(148, 163, 184, 0.14);
            border-radius: 12px;
            padding: 1.25rem;
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
            letter-spacing: 0.05em;
            padding: 0.85rem 1rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.18) !important;
        }
        .table.admin-table td {
            padding: 0.95rem 1rem;
            vertical-align: middle;
            background-color: transparent !important;
            color: #f8fafc !important;
        }
        .table.admin-table tbody tr:hover > * {
            background-color: rgba(30, 41, 59, 0.6) !important;
        }
        .target-box-selected {
            background: #131d36;
            border: 1px solid #0284c7;
            border-radius: 10px;
            padding: 1rem;
        }
        .nav-tabs .nav-link {
            color: #94a3b8;
            border: none;
            border-bottom: 2px solid transparent;
            padding: 0.75rem 1.25rem;
            font-weight: 600;
        }
        .nav-tabs .nav-link.active {
            color: #38bdf8;
            background: transparent;
            border-bottom: 2px solid #38bdf8;
        }
    </style>
</head>
<body class="dashboard-body">

<div class="app-shell">
    <?php require_once __DIR__ . '/../../views/layouts/admin_sidebar.php'; ?>

    <div class="main-content" id="mainContent">
        <?php require_once __DIR__ . '/../../views/layouts/admin_topnav.php'; ?>

        <main class="content-wrapper p-3 p-md-4">
            
            <!-- Page Title -->
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 gap-2">
                <div>
                    <h3 class="fw-bold mb-1 text-light">
                        <i class="bi bi-radio text-primary me-2"></i>Wireless Updates (OTA)
                    </h3>
                    <p class="text-secondary mb-0">Send new software updates to ESP32 vending machines wirelessly over Wi-Fi.</p>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php 
            $queuedFlash = get_flash_messages();
            foreach ($queuedFlash as $f): 
                $alertType = $f['type'] ?? 'info';
                if ($alertType === 'danger' || $alertType === 'error') $alertType = 'danger';
                elseif ($alertType === 'warning') $alertType = 'warning';
                else $alertType = 'success';
                
                $icon = ($alertType === 'danger') ? 'exclamation-triangle-fill' : (($alertType === 'warning') ? 'exclamation-circle-fill' : 'check-circle-fill');
            ?>
                <div class="alert alert-<?= $alertType ?> alert-dismissible fade show border-0 bg-<?= $alertType ?> bg-opacity-10 text-<?= $alertType ?> mb-3" role="alert">
                    <i class="bi bi-<?= $icon ?> me-2"></i><?= !empty($f['raw']) ? $f['message'] : e($f['message']) ?>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
                </div>
            <?php endforeach; ?>

            <!-- Metric Summary Cards -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="admin-stat-card d-flex align-items-center justify-content-between">
                        <div>
                            <div class="fs-4 fw-bold text-primary"><?= e($latestRelease['version'] ?? 'None') ?></div>
                            <div class="text-secondary small fw-semibold text-uppercase">Latest Build</div>
                        </div>
                        <div class="p-3 rounded-3 bg-primary bg-opacity-10 text-primary fs-4">
                            <i class="bi bi-tag-fill"></i>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="admin-stat-card d-flex align-items-center justify-content-between">
                        <div>
                            <div class="fs-4 fw-bold text-success"><?= (int)($metrics['online_machines'] ?? 0) ?></div>
                            <div class="text-secondary small fw-semibold text-uppercase">Online Machines</div>
                        </div>
                        <div class="p-3 rounded-3 bg-success bg-opacity-10 text-success fs-4">
                            <i class="bi bi-wifi"></i>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="admin-stat-card d-flex align-items-center justify-content-between">
                        <div>
                            <div class="fs-4 fw-bold text-info"><?= count($otaReleases) ?></div>
                            <div class="text-secondary small fw-semibold text-uppercase">Stored Builds</div>
                        </div>
                        <div class="p-3 rounded-3 bg-info bg-opacity-10 text-info fs-4">
                            <i class="bi bi-hdd-stack"></i>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="admin-stat-card d-flex align-items-center justify-content-between">
                        <div>
                            <div class="fs-4 fw-bold text-warning"><?= $totalDeployments ?></div>
                            <div class="text-secondary small fw-semibold text-uppercase">Deployments</div>
                        </div>
                        <div class="p-3 rounded-3 bg-warning bg-opacity-10 text-warning fs-4">
                            <i class="bi bi-send-check"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab Navigation: 1) Send Update, 2) Upload Build -->
            <ul class="nav nav-tabs mb-4 border-secondary border-opacity-25" id="otaTab" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active" id="deploy-tab" data-bs-toggle="tab" data-bs-target="#deployPane" type="button" role="tab">
                        <i class="bi bi-send me-1"></i>Send Wireless Update
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" id="upload-tab" data-bs-toggle="tab" data-bs-target="#uploadPane" type="button" role="tab">
                        <i class="bi bi-cloud-upload me-1"></i>Upload New Firmware (.bin)
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="otaTabContent">
                
                <!-- TAB 1: SEND WIRELESS UPDATE (SEPARATED ACTIVE STATUS & HISTORY) -->
                <div class="tab-pane fade show active" id="deployPane" role="tabpanel">
                    
                    <!-- Top Row: Dispatch Form (Left) & Active Deployments Live Status (Right) -->
                    <div class="row g-4 mb-4">
                        <!-- Left Column: Deployment Dispatch Form -->
                        <div class="col-12 col-lg-5">
                            <div class="admin-card h-100">
                                <h5 class="fw-bold text-light mb-3">
                                    <i class="bi bi-send-check text-primary me-2"></i>Send Update to Machines
                                </h5>

                                <?php if (empty($otaReleases)): ?>
                                    <div class="alert alert-warning border-0 bg-warning bg-opacity-10 text-warning">
                                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                        No firmware builds uploaded yet. Please click the <strong>Upload New Firmware</strong> tab first to upload your <code>.bin</code> file.
                                    </div>
                                <?php else: ?>
                                    <form method="POST" onsubmit="const btn=document.getElementById('deploySubmitBtn'); if(btn){btn.disabled=true; btn.innerHTML='<span class=\'spinner-border spinner-border-sm me-2\'></span>Dispatching Update...';}">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="deploy_ota">

                                        <!-- Step 1: Select Firmware Build -->
                                        <div class="mb-3">
                                            <label class="form-label text-secondary small fw-bold">1. CHOOSE FIRMWARE VERSION TO SEND</label>
                                            <select name="release_id" class="form-select bg-dark text-light border-secondary border-opacity-25" required>
                                                <?php foreach ($otaReleases as $r): ?>
                                                    <option value="<?= (int)$r['id'] ?>" <?= (!empty($r['is_latest']) ? 'selected' : '') ?>>
                                                        <?= e($r['version']) ?> (<?= e($r['filename']) ?> &bull; <?= number_format($r['filesize'] / 1024, 0) ?> KB) <?= (!empty($r['is_latest']) ? '— [Active Latest]' : '') ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <!-- Step 2: Choose Target Scope -->
                                        <div class="mb-3">
                                            <label class="form-label text-secondary small fw-bold">2. CHOOSE WHO GETS THE UPDATE</label>
                                            
                                            <!-- Option A: All Machines -->
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="radio" name="target_type" id="targetAll" value="all" <?= ($preselectedMachineId === 0 ? 'checked' : '') ?> onchange="toggleTargetOptions()">
                                                <label class="form-check-label text-light fw-semibold" for="targetAll">
                                                    All Machines Nationwide <span class="text-secondary small fw-normal">(General Update to every connected machine)</span>
                                                </label>
                                            </div>

                                            <!-- Option B: By Customer -->
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="radio" name="target_type" id="targetCustomer" value="customer" onchange="toggleTargetOptions()">
                                                <label class="form-check-label text-light fw-semibold" for="targetCustomer">
                                                    By Customer <span class="text-secondary small fw-normal">(Updates all machines owned by one person)</span>
                                                </label>
                                            </div>

                                            <!-- Option C: By Specific Machine -->
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="radio" name="target_type" id="targetMachine" value="machine" <?= ($preselectedMachineId > 0 ? 'checked' : '') ?> onchange="toggleTargetOptions()">
                                                <label class="form-check-label text-light fw-semibold" for="targetMachine">
                                                    By Specific Machine <span class="text-secondary small fw-normal">(Target Machine 1 or Machine 2 only)</span>
                                                </label>
                                            </div>

                                            <!-- Dynamic Dropdown: Customer Selector -->
                                            <div id="customerSelectBox" class="mb-3 target-box-selected" style="display: none;">
                                                <label class="form-label text-light small fw-bold">SELECT CUSTOMER:</label>
                                                <select name="customer_id" class="form-select bg-dark text-light border-secondary border-opacity-50">
                                                    <option value="">-- Choose a Customer --</option>
                                                    <?php foreach ($allCustomers as $c): ?>
                                                        <option value="<?= (int)$c['id'] ?>">
                                                            <?= e($c['full_name']) ?> (<?= e($c['email']) ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="form-text text-secondary small">All machines owned by this customer will receive the update.</div>
                                            </div>

                                            <!-- Dynamic Dropdown: Machine Selector -->
                                            <div id="machineSelectBox" class="mb-3 target-box-selected" style="<?= ($preselectedMachineId > 0 ? 'display: block;' : 'display: none;') ?>">
                                                <label class="form-label text-light small fw-bold">SELECT TARGET MACHINE:</label>
                                                <select name="machine_id" class="form-select bg-dark text-light border-secondary border-opacity-50">
                                                    <option value="">-- Choose Machine 1 or Machine 2 --</option>
                                                    <?php foreach ($allMachines as $m): ?>
                                                        <option value="<?= (int)$m['id'] ?>" <?= ($preselectedMachineId === (int)$m['id'] ? 'selected' : '') ?>>
                                                            <?= e($m['name'] ?: $m['machine_code']) ?> (Code: <?= e($m['machine_code']) ?> &bull; Current FW: v<?= e($m['firmware_version'] ?? '3.0.0') ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="form-text text-secondary small">Only this specific machine will download and install the update.</div>
                                            </div>
                                        </div>

                                        <!-- Step 3: Trigger Button -->
                                        <button type="submit" id="deploySubmitBtn" class="btn btn-primary w-100 fw-bold py-2.5">
                                            <i class="bi bi-radio me-2"></i>Send Wireless Update Now
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Right Column: Active Deployments (Live Rollout & Progress) -->
                        <div class="col-12 col-lg-7">
                            <div class="admin-card h-100 d-flex flex-column" id="activeDeploymentsCard">
                                <div class="d-flex align-items-center justify-content-between mb-3">
                                    <div>
                                        <h5 class="fw-bold text-light mb-0">
                                            <i class="bi bi-broadcast text-warning me-2"></i>Active Rollouts (Live Progress)
                                        </h5>
                                        <div class="text-secondary small mt-0.5">Real-time status of wireless updates in progress</div>
                                    </div>
                                    <?php if ($totalActive > 0): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2.5 py-1 small">
                                            <i class="bi bi-circle-fill me-1 small animate-pulse"></i> Live Auto-Sync
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary bg-opacity-20 text-secondary rounded-pill px-2.5 py-1 small">
                                            Idle
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <?php if (empty($activeDeployments)): ?>
                                    <div class="text-center py-5 text-secondary d-flex flex-column align-items-center justify-content-center flex-grow-1">
                                        <div class="p-3 rounded-circle bg-dark border border-secondary border-opacity-25 mb-3 text-secondary">
                                            <i class="bi bi-check2-circle fs-2 text-success"></i>
                                        </div>
                                        <div class="fw-semibold text-light mb-1">No Wireless Updates In Progress</div>
                                        <div class="small text-muted" style="max-width: 380px;">
                                            All connected machines are up to date. When you send an update using the form on the left, live machine progress will stream here automatically.
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="d-flex flex-column gap-3 flex-grow-1">
                                        <?php foreach ($activeDeployments as $dep): ?>
                                            <div class="p-3 rounded-3 bg-dark bg-opacity-50 border border-secondary border-opacity-25" id="dep-card-<?= (int)$dep['id'] ?>">
                                                <!-- Deployment Header -->
                                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                                    <div>
                                                        <span class="badge bg-primary bg-opacity-25 text-primary fw-bold font-monospace px-2.5 py-1">
                                                            v<?= e(ltrim($dep['version'], 'v')) ?>
                                                        </span>
                                                        <span class="ms-2 text-light fw-bold">
                                                            <?php if ($dep['target_type'] === 'all'): ?>
                                                                General Rollout &bull; All Machines Nationwide
                                                            <?php elseif ($dep['target_type'] === 'customer'): ?>
                                                                Customer Fleet: <?= e($dep['target_customer_name'] ?? 'Customer #' . $dep['target_id']) ?>
                                                            <?php elseif ($dep['target_type'] === 'machine'): ?>
                                                                Target Unit: <?= e($dep['target_machine_name'] ?: $dep['target_machine_code'] ?: 'Machine #' . $dep['target_id']) ?>
                                                            <?php endif; ?>
                                                        </span>
                                                    </div>
                                                    <div class="d-flex align-items-center gap-1.5">
                                                        <!-- Complete Action -->
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Mark this update deployment as completed?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="complete_deployment">
                                                            <input type="hidden" name="deployment_id" value="<?= (int)$dep['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-success px-2.5 py-1" title="Mark this update finished">
                                                                <i class="bi bi-check-lg me-1"></i>Mark Finished
                                                            </button>
                                                        </form>
                                                        <!-- Stop Action -->
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Stop and cancel this wireless update?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="cancel_deployment">
                                                            <input type="hidden" name="deployment_id" value="<?= (int)$dep['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger px-2.5 py-1" title="Cancel this update">
                                                                <i class="bi bi-x-lg me-1"></i>Stop
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>

                                                <!-- Visual Progress Bar -->
                                                <div class="mb-2">
                                                    <div class="progress bg-secondary bg-opacity-25" style="height: 10px; border-radius: 6px;">
                                                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                                                             id="dep-prog-bar-<?= (int)$dep['id'] ?>"
                                                             style="width: <?= (int)$dep['progress_pct'] ?>%; transition: width 0.4s ease;">
                                                        </div>
                                                    </div>
                                                    <div class="d-flex align-items-center justify-content-between mt-1 text-secondary small">
                                                        <span id="dep-prog-txt-<?= (int)$dep['id'] ?>" class="fw-semibold text-light">
                                                            <?= (int)$dep['updated_count'] ?> of <?= (int)$dep['total_targets'] ?> <?= $dep['total_targets'] === 1 ? 'Machine' : 'Machines' ?> Updated (<?= (int)$dep['progress_pct'] ?>%)
                                                        </span>
                                                        <span>
                                                            <?= (int)$dep['pending_count'] ?> Awaiting Signal
                                                        </span>
                                                    </div>
                                                </div>

                                                <!-- Target Machines Breakdown -->
                                                <?php if (!empty($dep['target_machines'])): ?>
                                                    <div class="mt-2.5 pt-2 border-top border-secondary border-opacity-10">
                                                        <div class="text-secondary small fw-bold mb-1.5 text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Machine Status Breakdown:</div>
                                                        <div class="d-flex flex-wrap gap-2">
                                                            <?php foreach ($dep['target_machines'] as $tm): ?>
                                                                <div class="d-flex align-items-center gap-1.5 px-2.5 py-1 rounded-2 <?= $tm['is_updated'] ? 'bg-success bg-opacity-10 border border-success border-opacity-25 text-success' : 'bg-warning bg-opacity-10 border border-warning border-opacity-25 text-warning' ?> small">
                                                                    <i class="bi <?= $tm['is_updated'] ? 'bi-check-circle-fill' : 'bi-hourglass-split' ?>"></i>
                                                                    <span class="fw-semibold text-light"><?= e($tm['name'] ?: $tm['machine_code']) ?></span>
                                                                    <span class="text-secondary font-monospace">(v<?= e($tm['firmware_version'] ?? '3.0.0') ?>)</span>
                                                                    <?php if ($tm['is_updated']): ?>
                                                                        <span class="badge bg-success bg-opacity-25 text-success px-1.5 py-0.5" style="font-size: 0.7rem;">Updated</span>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-warning bg-opacity-25 text-warning px-1.5 py-0.5" style="font-size: 0.7rem;">Pending Signal</span>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($tm['is_online'])): ?>
                                                                        <span class="badge bg-success text-dark px-1 py-0" style="font-size: 0.65rem;" title="Machine is online">Online</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Bottom Full-Width Row: Dedicated Deployment History (Goal: Seperate Deployment History) -->
                    <div class="row g-4">
                        <div class="col-12">
                            <div class="admin-card d-flex flex-column">
                                <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
                                    <div>
                                        <h5 class="fw-bold text-light mb-0">
                                            <i class="bi bi-clock-history text-info me-2"></i>Deployment History
                                        </h5>
                                        <div class="text-secondary small mt-0.5">Authoritative audit ledger of past wireless deployments</div>
                                    </div>
                                    
                                    <!-- Status Filter Tabs: All, Completed, Cancelled -->
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Deployment history filters">
                                        <a href="<?= $histUrl(1, 'all') ?>" class="btn <?= ($histFilter === 'all') ? 'btn-primary' : 'btn-outline-secondary text-secondary' ?> px-2.5 py-1">
                                            All <span class="opacity-75 ms-1">(<?= $historyCounts['all'] ?>)</span>
                                        </a>
                                        <a href="<?= $histUrl(1, 'completed') ?>" class="btn <?= ($histFilter === 'completed') ? 'btn-success' : 'btn-outline-secondary text-secondary' ?> px-2.5 py-1">
                                            <i class="bi bi-check-circle-fill me-1"></i>Completed <span class="opacity-75 ms-1">(<?= $historyCounts['completed'] ?>)</span>
                                        </a>
                                        <a href="<?= $histUrl(1, 'cancelled') ?>" class="btn <?= ($histFilter === 'cancelled') ? 'btn-secondary text-light' : 'btn-outline-secondary text-secondary' ?> px-2.5 py-1">
                                            Cancelled <span class="opacity-75 ms-1">(<?= $historyCounts['cancelled'] ?>)</span>
                                        </a>
                                    </div>
                                </div>

                                <?php if (empty($otaHistory)): ?>
                                    <div class="text-center py-5 text-secondary">
                                        <i class="bi bi-archive fs-2 d-block mb-2 text-muted"></i>
                                        <div class="fw-semibold text-light mb-1">No <?= ($histFilter !== 'all') ? ucfirst($histFilter) : 'Past' ?> Deployments</div>
                                        <div class="small text-muted">Finished and stopped updates will be logged here.</div>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-dark admin-table mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Version</th>
                                                    <th>Target Scope</th>
                                                    <th>Dispatched Date</th>
                                                    <th>Finished / Stopped Date</th>
                                                    <th>Final Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($otaHistory as $dep): ?>
                                                <tr>
                                                    <td>
                                                        <span class="badge bg-primary bg-opacity-25 text-primary fw-bold font-monospace">
                                                            <?= e($dep['version']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($dep['target_type'] === 'all'): ?>
                                                            <span class="text-light fw-semibold">All Machines Nationwide</span>
                                                        <?php elseif ($dep['target_type'] === 'customer'): ?>
                                                            <div class="text-light fw-semibold"><?= e($dep['target_customer_name'] ?? 'Customer #' . $dep['target_id']) ?></div>
                                                            <span class="badge bg-secondary bg-opacity-25 text-secondary">Customer Group</span>
                                                        <?php elseif ($dep['target_type'] === 'machine'): ?>
                                                            <div class="text-light fw-semibold"><?= e($dep['target_machine_name'] ?: $dep['target_machine_code'] ?: 'Machine #' . $dep['target_id']) ?></div>
                                                            <span class="badge bg-info bg-opacity-10 text-info">Single Machine</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-secondary font-monospace small">
                                                        <?= date('M d, Y h:i A', strtotime($dep['created_at'])) ?>
                                                    </td>
                                                    <td class="text-secondary font-monospace small">
                                                        <?= date('M d, Y h:i A', strtotime($dep['updated_at'])) ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($dep['status'] === 'completed'): ?>
                                                            <span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-25 rounded-pill px-2.5 py-1">
                                                                 <i class="bi bi-check-circle-fill me-1"></i>Completed
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary bg-opacity-25 text-muted rounded-pill px-2.5 py-1">
                                                                Cancelled
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <!-- Reserved Space Indicator (Shown when current page has fewer than 5 records) -->
                                    <?php if (!empty($otaHistory) && count($otaHistory) < 5): ?>
                                    <div class="table-reserved-space text-center py-4 d-flex flex-column align-items-center justify-content-center" style="min-height: <?= max(110, (5 - count($otaHistory)) * 48) ?>px;">
                                        <?= lucide_icon('layers', 'layers-icon mb-2') ?>
                                        <div class="text-slate-400 fw-medium small">Reserved space for the remaining rows</div>
                                        <div class="small" style="color: #64748B;">No placeholder rows are displayed.</div>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Pagination Controls at Bottom -->
                                    <div class="tx-pagination mt-3 pt-3" style="border-top: 1px solid rgba(148, 163, 184, 0.1);">
                                        <div class="text-secondary small">
                                            Page <strong><?= $histPage ?></strong> of <strong><?= $totalHistPages ?></strong> (Total <?= number_format($totalHistory) ?> records)
                                        </div>
                                        <nav aria-label="Deployment history pagination">
                                            <ul class="pagination pagination-sm mb-0">
                                                <li class="page-item <?= ($histPage <= 1) ? 'disabled' : '' ?>">
                                                    <a class="page-link" href="<?= ($histPage > 1) ? $histUrl($histPage - 1) : '#' ?>" aria-label="Previous">
                                                        <span aria-hidden="true">&laquo;&nbsp;Prev</span>
                                                    </a>
                                                </li>
                                                <?php for ($p = max(1, $histPage - 2); $p <= min($totalHistPages, $histPage + 2); $p++): ?>
                                                    <li class="page-item <?= $p === $histPage ? 'active' : '' ?>">
                                                        <a class="page-link" href="<?= $histUrl($p) ?>"><?= $p ?></a>
                                                    </li>
                                                <?php endfor; ?>
                                                <li class="page-item <?= ($histPage >= $totalHistPages) ? 'disabled' : '' ?>">
                                                    <a class="page-link" href="<?= ($histPage < $totalHistPages) ? $histUrl($histPage + 1) : '#' ?>" aria-label="Next">
                                                        <span aria-hidden="true">Next&nbsp;&raquo;</span>
                                                    </a>
                                                </li>
                                            </ul>
                                        </nav>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB 2: UPLOAD NEW FIRMWARE (.BIN) -->
                <div class="tab-pane fade" id="uploadPane" role="tabpanel">
                    <div class="row g-4">
                        <!-- Upload Form -->
                        <div class="col-12 col-lg-5">
                            <div class="admin-card">
                                <h5 class="fw-bold text-light mb-3">
                                    <i class="bi bi-cloud-upload text-primary me-2"></i>Upload New Firmware (.bin)
                                </h5>
                                <form method="POST" enctype="multipart/form-data">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="upload_firmware">
                                    
                                    <div class="mb-3">
                                        <label class="form-label text-secondary small fw-bold">VERSION TAG</label>
                                        <input type="text" name="version" class="form-control bg-dark text-light border-secondary border-opacity-25" placeholder="e.g. v3.1.0" required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label text-secondary small fw-bold">SELECT COMPILED BINARY FILE (.BIN)</label>
                                        <input type="file" name="firmware_file" accept=".bin" class="form-control bg-dark text-light border-secondary border-opacity-25" required>
                                        <div class="form-text text-secondary small">Export from Arduino IDE via <em>Sketch &gt; Export Compiled Binary</em>. Max 4MB.</div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label text-secondary small fw-bold">RELEASE NOTES / WHAT'S NEW</label>
                                        <textarea name="changelog" rows="3" class="form-control bg-dark text-light border-secondary border-opacity-25" placeholder="Describe fixes or new features..."></textarea>
                                    </div>

                                    <button type="submit" class="btn btn-primary w-100 fw-bold py-2">
                                        <i class="bi bi-upload me-2"></i>Upload Firmware Build
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Stored Builds Table -->
                        <div class="col-12 col-lg-7">
                            <div class="admin-card">
                                <h5 class="fw-bold text-light mb-3">
                                    <i class="bi bi-journal-code text-info me-2"></i>Firmware Build Repository
                                </h5>
                                <div class="table-responsive">
                                    <table class="table table-dark admin-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>Version</th>
                                                <th>Filename</th>
                                                <th>Size</th>
                                                <th>Status</th>
                                                <th>Uploaded</th>
                                                <th class="text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($otaReleases)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-4 text-secondary">
                                                    No firmware builds uploaded yet.
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                                <?php foreach ($otaReleases as $rel): ?>
                                                <tr>
                                                    <td>
                                                        <span class="badge bg-info bg-opacity-15 text-info border border-info border-opacity-30 rounded-pill px-2.5 py-1 fw-bold font-monospace">
                                                            <?= e($rel['version']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="text-light fw-medium font-monospace small"><i class="bi bi-file-earmark-binary text-primary me-1"></i><?= e($rel['filename']) ?></div>
                                                        <div class="small text-secondary mt-0.5"><?= e(mb_strimwidth($rel['changelog'] ?? '', 0, 45, '...')) ?></div>
                                                    </td>
                                                    <td class="small text-secondary font-monospace">
                                                        <?= number_format($rel['filesize'] / 1024, 1) ?> KB
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($rel['is_latest'])): ?>
                                                            <span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-30 rounded-pill px-2.5 py-1 fw-semibold">
                                                                <i class="bi bi-check2 me-1"></i>Latest
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary bg-opacity-20 text-slate-300 border border-secondary border-opacity-25 rounded-pill px-2.5 py-1 fw-semibold">
                                                                Archived
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="small text-secondary font-monospace">
                                                        <?= date('M d, Y', strtotime($rel['created_at'])) ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Permanently delete firmware build v<?= e(ltrim($rel['version'], 'v')) ?>?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="delete_release">
                                                            <input type="hidden" name="release_id" value="<?= (int)$rel['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger p-1 px-2" title="Delete this firmware release">
                                                                <i class="bi bi-trash"></i>
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
function toggleTargetOptions() {
    const isCustomer = document.getElementById('targetCustomer').checked;
    const isMachine  = document.getElementById('targetMachine').checked;

    document.getElementById('customerSelectBox').style.display = isCustomer ? 'block' : 'none';
    document.getElementById('machineSelectBox').style.display  = isMachine  ? 'block' : 'none';
}

// Live polling for Active Wireless Updates
let otaPollInterval = null;
const initialActiveCount = <?= (int)$totalActive ?>;

function checkActiveOtaStatus() {
    fetch('?action=poll_status')
        .then(response => {
            if (!response.ok) throw new Error('Network error');
            return response.json();
        })
        .then(data => {
            if (data && data.success) {
                // If the number of active deployments changed (e.g. completed or cancelled), reload page cleanly via GET
                if (data.active_count !== initialActiveCount) {
                    if (otaPollInterval) clearInterval(otaPollInterval);
                    window.location.replace(window.location.pathname + window.location.search);
                    return;
                }

                // If currently active, update progress bars and machine statuses
                if (data.active && data.active.length > 0) {
                    data.active.forEach(dep => {
                        const bar = document.getElementById('dep-prog-bar-' + dep.id);
                        const txt = document.getElementById('dep-prog-txt-' + dep.id);
                        if (bar) {
                            bar.style.width = dep.progress_pct + '%';
                        }
                        if (txt) {
                            txt.textContent = dep.updated_count + ' of ' + dep.total_targets + ' Machines Updated (' + dep.progress_pct + '%)';
                        }
                        if (dep.is_finished) {
                            // Automatically reload to display updated badge & history cleanly via GET
                            if (otaPollInterval) clearInterval(otaPollInterval);
                            setTimeout(() => { 
                                window.location.replace(window.location.pathname + window.location.search); 
                            }, 1200);
                        }
                    });
                }
            }
        })
        .catch(err => {
            console.warn('[OTA Sync] Heartbeat poll error:', err);
        });
}

// Poll every 4 seconds if there is an active update in progress
if (initialActiveCount > 0) {
    otaPollInterval = setInterval(checkActiveOtaStatus, 4000);
}
</script>
</body>
</html>
