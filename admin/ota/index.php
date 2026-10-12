<?php
/**
 * ============================================================
 * VendoLink — OTA Wireless Firmware Deployment Center
 * ============================================================
 *
 * Allows Administrators to upload compiled ESP32 firmware binaries (.bin)
 * and trigger wireless Over-The-Air (OTA) flashes to online vending hardware.
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
$flashSuccess   = '';
$flashError     = '';

// Handle POST actions (Upload .bin binary firmware)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action    = $_POST['action'] ?? '';
    $version   = trim($_POST['version'] ?? '');
    $changelog = trim($_POST['changelog'] ?? '');

    if ($action === 'upload_firmware') {
        if (empty($version)) {
            $flashError = "Version tag is required (e.g., v2.3.0).";
        } elseif (!isset($_FILES['firmware_file']) || $_FILES['firmware_file']['error'] !== UPLOAD_ERR_OK) {
            $flashError = "Please select a valid ESP32 binary (.bin) file to upload.";
        } else {
            $file     = $_FILES['firmware_file'];
            $fileName = basename($file['name']);
            $fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $fileSize = (int)$file['size'];

            if ($fileExt !== 'bin') {
                $flashError = "Invalid file type. Only compiled ESP32 firmware binaries (.bin) are accepted.";
            } elseif ($fileSize > 4194304) { // 4MB Max
                $flashError = "File size exceeds 4MB limit.";
            } else {
                $uploadDir = ROOT_PATH . '/uploads/firmware/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $safeFilename = 'vendolink_esp32_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '', $version) . '.bin';
                $targetPath   = $uploadDir . $safeFilename;

                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    $dashboardModel->createOtaRelease($version, $safeFilename, $fileSize, $changelog);
                    $flashSuccess = "Firmware release <strong>{$version}</strong> uploaded successfully! Binary saved as <code>{$safeFilename}</code>.";
                    log_activity('ADMIN_ACTION', "Uploaded OTA firmware binary release {$version}");
                } else {
                    $flashError = "Failed to save binary file to upload directory.";
                }
            }
        }
    }
}

$otaReleases = $dashboardModel->getOtaReleases();
$metrics     = $dashboardModel->getSummaryMetrics();
$latestRelease = !empty($otaReleases) ? $otaReleases[0] : null;

$pageTitle   = 'OTA Firmware Deployment Center';
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
        .admin-stat-info::before    { background: var(--vl-info, #38bdf8); }
        .admin-stat-warning::before { background: var(--vl-warning, #f59e0b); }

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
                    <h3 class="fw-bold mb-1 text-light"><i class="bi bi-cloud-arrow-up-fill text-primary me-2"></i>OTA Wireless Firmware Deployment</h3>
                    <p class="text-secondary mb-0">Upload compiled ESP32 firmware binaries (.bin) and deploy wireless updates to online machines.</p>
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
                <div class="col-6 col-md-3">
                    <div class="admin-stat-card admin-stat-primary">
                        <div>
                            <div class="admin-stat-value text-primary"><?= e($latestRelease['version'] ?? 'v2.2.0') ?></div>
                            <div class="admin-stat-label">Active Release</div>
                        </div>
                        <div class="admin-stat-icon bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-tag-fill"></i>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="admin-stat-card admin-stat-success">
                        <div>
                            <div class="admin-stat-value text-success"><?= (int)($metrics['online_machines'] ?? 0) ?></div>
                            <div class="admin-stat-label"><?= term('online_machines') ?></div>
                        </div>
                        <div class="admin-stat-icon bg-success bg-opacity-10 text-success">
                            <i class="bi bi-wifi"></i>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="admin-stat-card admin-stat-info">
                        <div>
                            <div class="admin-stat-value text-info"><?= count($otaReleases) ?></div>
                            <div class="admin-stat-label">Stored Builds</div>
                        </div>
                        <div class="admin-stat-icon bg-info bg-opacity-10 text-info">
                            <i class="bi bi-hdd-stack"></i>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="admin-stat-card admin-stat-warning">
                        <div>
                            <div class="admin-stat-value text-warning">ESP32</div>
                            <div class="admin-stat-label">Target Architecture</div>
                        </div>
                        <div class="admin-stat-icon bg-warning bg-opacity-10 text-warning">
                            <i class="bi bi-cpu"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Firmware Upload & Release Table Row -->
            <div class="row g-4 mb-4">
                <!-- Upload New Build Card -->
                <div class="col-12 col-lg-5">
                    <div class="admin-card h-100">
                        <h5 class="fw-bold text-light mb-3"><i class="bi bi-upload text-primary me-2"></i>Upload Firmware Binary</h5>
                        <form method="POST" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="upload_firmware">
                            
                            <div class="mb-3">
                                <label class="form-label text-secondary small fw-bold">VERSION TAG</label>
                                <input type="text" name="version" class="form-control bg-dark text-light border-secondary border-opacity-25" placeholder="e.g. v2.3.0" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label text-secondary small fw-bold">SELECT BINARY FILE (.BIN)</label>
                                <input type="file" name="firmware_file" accept=".bin" class="form-control bg-dark text-light border-secondary border-opacity-25" required>
                                <div class="form-text text-muted small">Only compiled ESP32 binary files (.bin) up to 4MB.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label text-secondary small fw-bold">RELEASE NOTES / CHANGELOG</label>
                                <textarea name="changelog" rows="3" class="form-control bg-dark text-light border-secondary border-opacity-25" placeholder="Describe fixes or new features..."></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 fw-bold py-2">
                                <i class="bi bi-cloud-upload me-2"></i>Upload & Publish Release
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Release History Table Card -->
                <div class="col-12 col-lg-7">
                    <div class="admin-card h-100">
                        <h5 class="fw-bold text-light mb-3"><i class="bi bi-journal-code text-info me-2"></i>Firmware Build Repository</h5>
                        <div class="table-responsive">
                            <table class="table admin-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Version</th>
                                        <th>Binary Filename</th>
                                        <th>Size</th>
                                        <th>Status</th>
                                        <th>Uploaded Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($otaReleases)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-secondary">
                                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>No firmware builds uploaded yet.
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($otaReleases as $rel): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-primary bg-opacity-20 text-primary border border-primary border-opacity-25 rounded-pill px-2.5 py-1 fw-bold">
                                                    <?= e($rel['version']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="text-light font-monospace small"><?= e($rel['filename']) ?></div>
                                                <div class="small text-secondary"><?= e(mb_strimwidth($rel['changelog'] ?? '', 0, 45, '...')) ?></div>
                                            </td>
                                            <td class="small text-secondary">
                                                <?= number_format($rel['filesize'] / 1024, 1) ?> KB
                                            </td>
                                            <td>
                                                <?php if (!empty($rel['is_latest'])): ?>
                                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2.5 py-1 fw-semibold">
                                                        <i class="bi bi-check-circle-fill me-1"></i>Active Latest
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary bg-opacity-20 text-muted rounded-pill px-2.5 py-1">
                                                        Archived
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="small text-secondary">
                                                <?= date('M d, Y', strtotime($rel['created_at'])) ?>
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
