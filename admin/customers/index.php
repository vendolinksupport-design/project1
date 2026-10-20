<?php
/**
 * ============================================================
 * VendoLink — Machine Owners Directory (VendoAdmin Module 1)
 * ============================================================
 *
 * Master directory of registered vending machine owners.
 * Allows System Admins to view owner stats, toggle account status,
 * edit account profiles, and manually verify OTP email addresses.
 *
 * @package VendoLink
 * @version 1.1.0
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../models/AdminDashboard.php';
require_once __DIR__ . '/../../models/Machine.php';

require_admin();

$dashboardModel = new AdminDashboard();
$flashSuccess   = '';
$flashError     = '';

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);

    if ($userId > 0) {
        if ($action === 'toggle_status') {
            $newStatus = $_POST['status'] ?? 'inactive';
            if ($dashboardModel->toggleCustomerStatus($userId, $newStatus)) {
                $flashSuccess = "Customer account status updated to '" . strtoupper($newStatus) . "'.";
                log_activity('ADMIN_ACTION', "Updated customer account #{$userId} status to {$newStatus}");
            } else {
                $flashError = "Failed to update account status.";
            }
        } elseif ($action === 'verify_email') {
            if ($dashboardModel->verifyCustomerEmail($userId)) {
                $flashSuccess = "Customer email address manually verified and account activated.";
                log_activity('ADMIN_ACTION', "Manually verified customer account #{$userId}");
            } else {
                $flashError = "Failed to verify email address.";
            }
        } elseif ($action === 'edit_customer') {
            $fullName = trim($_POST['full_name'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $confirmPassword = trim($_POST['confirm_password'] ?? '');

            if (empty($fullName) || empty($email) || empty($username)) {
                $flashError = "Full Name, Email Address, and Username are required fields.";
            } elseif (!empty($password) && (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password))) {
                $flashError = "New password must be at least 8 characters long and contain both letters and numbers.";
            } elseif (!empty($password) && $password !== $confirmPassword) {
                $flashError = "Passwords do not match.";
            } else {
                if ($dashboardModel->updateCustomerAccount($userId, $fullName, $email, $username, $password ?: null)) {
                    $flashSuccess = "Customer account details updated successfully for <strong>" . e($fullName) . "</strong>!";
                    log_activity('ADMIN_ACTION', "Updated account details for customer #{$userId}");
                } else {
                    $flashError = "Failed to update customer account details.";
                }
            }
        }
    }
}

$customers = $dashboardModel->getAllCustomersWithMachines();
$metrics   = $dashboardModel->getSummaryMetrics();

$pageTitle   = term('customers');
$currentPage = 'customers';
$currentDir  = 'customers';
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
            border-color: rgba(148, 163, 184, 0.3) !important;
        }

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

        .table.admin-table tbody tr.customer-row {
            cursor: pointer;
            transition: background-color 0.15s ease-in-out;
        }

        .table.admin-table tbody tr.customer-row:hover > * {
            background-color: rgba(30, 41, 59, 0.8) !important;
        }

        .table.admin-table tbody tr.customer-row[aria-expanded="true"] > * {
            background-color: rgba(30, 41, 59, 0.95) !important;
            border-bottom-color: transparent !important;
        }

        .table.admin-table tbody tr.customer-row .chevron-icon {
            transition: transform 0.25s ease-in-out;
            display: inline-block;
        }

        .table.admin-table tbody tr.customer-row[aria-expanded="true"] .chevron-icon {
            transform: rotate(180deg);
            color: #38bdf8 !important;
        }

        /* Decoupled Machine Cell & Independent Drawer Button */
        .table.admin-table td.machine-cell {
            position: relative;
        }

        .table.admin-table .machine-count-badge {
            display: inline-block;
            font-size: 0.875rem;
        }

        .table.admin-table .machine-drawer-btn {
            position: absolute;
            right: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            width: 24px;
            height: 24px;
            color: #94a3b8;
            font-size: 0.95rem;
            transition: color 0.2s ease-in-out;
            cursor: pointer;
        }

        .table.admin-table tbody tr.customer-row:hover .machine-drawer-btn {
            color: #f8fafc;
        }

        .table.admin-table tbody tr.customer-drawer-row:hover > * {
            background-color: transparent !important;
        }

        .customer-drawer-row > td {
            padding: 0 !important;
            background: transparent !important;
            border-top: none !important;
        }

        .customer-drawer-content {
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.95) 0%, rgba(10, 15, 29, 0.98) 100%);
            border-bottom: 2px solid rgba(56, 189, 248, 0.25);
            box-shadow: inset 0 6px 12px -4px rgba(0, 0, 0, 0.4);
        }

        .machine-mini-card {
            background: rgba(30, 41, 59, 0.55);
            border: 1px solid rgba(148, 163, 184, 0.15);
            border-radius: 8px;
            padding: 0.85rem 1rem;
            transition: border-color 0.15s, background-color 0.15s, transform 0.15s;
        }

        .machine-mini-card:hover {
            background: rgba(30, 41, 59, 0.85);
            border-color: rgba(56, 189, 248, 0.4);
            transform: translateY(-1px);
        }

        .extra-small {
            font-size: 0.75rem;
        }

        .tracking-wide {
            letter-spacing: 0.05em;
        }

        .avatar-circle {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            color: #ffffff;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
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
                    <h3 class="fw-bold mb-1 text-light"><i class="bi bi-person-badge-fill text-primary me-2"></i><?= term('customers') ?></h3>
                    <p class="text-secondary mb-0">View, monitor, and manage registered customer accounts.</p>
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

            <!-- Customers Table Card -->
            <div class="admin-card">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-secondary small"><i class="bi bi-info-circle me-1 text-info"></i>Click any customer row to view connected machines and details</span>
                </div>

                <div class="table-responsive">
                    <table class="table table-dark admin-table mb-0">
                        <thead>
                            <tr>
                                <th style="width: 35%;">Owner / Customer</th>
                                <th style="width: 30%;">Email & Username</th>
                                <th style="width: 15%;">Account Status</th>
                                <th style="width: 20%;" class="text-center">Machines</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($customers)): ?>
                            <tr>
                                <td colspan="4" class="text-center py-5 text-secondary">
                                    <i class="bi bi-inbox fs-2 d-block mb-2 text-muted"></i>No registered customers found.
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($customers as $c): ?>
                                <tr class="customer-row" data-bs-toggle="collapse" data-bs-target="#customerDrawer<?= (int)$c['id'] ?>" aria-expanded="false" aria-controls="customerDrawer<?= (int)$c['id'] ?>">
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="avatar-circle">
                                                <?= strtoupper(mb_substr($c['full_name'] ?? 'U', 0, 1)) ?>
                                            </div>
                                            <div>
                                                <div class="fw-bold text-light"><?= e($c['full_name']) ?></div>
                                                <div class="small text-secondary">Owner ID #<?= (int)$c['id'] ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="text-light fw-medium"><?= e($c['email']) ?></div>
                                        <div class="small text-secondary">@<?= e($c['username']) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($c['status'] === 'active'): ?>
                                            <span class="text-success small fw-medium d-inline-flex align-items-center">
                                                <span class="me-2" style="width: 7px; height: 7px; background: #22c55e; border-radius: 50%; display: inline-block; margin-right: 8px; flex-shrink: 0;"></span>Active
                                            </span>
                                        <?php else: ?>
                                            <span class="text-secondary small fw-medium d-inline-flex align-items-center">
                                                <span class="me-2" style="width: 7px; height: 7px; background: #64748b; border-radius: 50%; display: inline-block; margin-right: 8px; flex-shrink: 0;"></span>Inactive
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center machine-cell">
                                        <!-- Independent Centered Machine Count -->
                                        <span class="fw-bold text-white small font-monospace machine-count-badge" title="<?= (int)$c['total_machines'] ?> <?= (int)$c['total_machines'] === 1 ? 'Machine' : 'Machines' ?>">
                                            <?= (int)$c['total_machines'] ?>
                                        </span>
                                        <!-- Independent Dropdown Toggle Chevron (single icon, clean without circle) -->
                                        <span class="machine-drawer-btn d-inline-flex align-items-center justify-content-center" aria-label="Toggle machines drawer">
                                            <i class="bi bi-chevron-down chevron-icon"></i>
                                        </span>
                                    </td>
                                </tr>

                                <!-- Collapsible Accordion Drawer Row -->
                                <tr class="customer-drawer-row">
                                    <td colspan="4">
                                        <div class="collapse" id="customerDrawer<?= (int)$c['id'] ?>">
                                            <div class="customer-drawer-content p-3 p-md-4">
                                                <div class="row g-4">
                                                    <!-- Left: Customer Profile & Quick Actions -->
                                                    <div class="col-12 col-lg-4 col-xl-3 border-end border-secondary border-opacity-10">
                                                        <div class="mb-3">
                                                            <span class="fw-bold text-light text-uppercase small tracking-wide">Owner Profile</span>
                                                        </div>

                                                        <div class="mb-3 p-3 rounded-3" style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(148, 163, 184, 0.1);">
                                                            <div class="mb-2">
                                                                <span class="text-secondary small d-block mb-1">Email Verification</span>
                                                                <?php if (!empty($c['email_verified'])): ?>
                                                                    <span class="text-success small fw-medium d-inline-flex align-items-center">
                                                                        <span class="me-2" style="width: 7px; height: 7px; background: #22c55e; border-radius: 50%; display: inline-block; margin-right: 8px; flex-shrink: 0;"></span>Verified
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="text-warning small fw-medium d-inline-flex align-items-center">
                                                                        <span class="me-2" style="width: 7px; height: 7px; background: #f59e0b; border-radius: 50%; display: inline-block; margin-right: 8px; flex-shrink: 0;"></span>Pending OTP
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="mb-2">
                                                                <span class="text-secondary small d-block">Registered On</span>
                                                                <span class="text-light small fw-medium"><?= date('M d, Y h:i A', strtotime($c['created_at'])) ?></span>
                                                            </div>
                                                            <div>
                                                                <span class="text-secondary small d-block">Account Role</span>
                                                                <span class="text-light small fw-medium">Owner</span>
                                                            </div>
                                                        </div>

                                                        <!-- Quick Management Actions -->
                                                        <div class="d-flex flex-column gap-2" onclick="event.stopPropagation();">
                                                            <button type="button" class="btn btn-sm btn-outline-primary d-flex align-items-center justify-content-center gap-2 py-1.5" data-bs-toggle="modal" data-bs-target="#editOwnerModal<?= (int)$c['id'] ?>">
                                                                <i class="bi bi-pencil-square"></i><?= term('btn_edit_customer') ?>
                                                            </button>

                                                            <form method="POST" class="d-inline" onsubmit="return confirm('<?= $c['status'] === 'active' ? 'Are you sure you want to disable this customer account?' : 'Are you sure you want to enable this customer account?' ?>');">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="toggle_status">
                                                                <input type="hidden" name="user_id" value="<?= (int)$c['id'] ?>">
                                                                <input type="hidden" name="status" value="<?= $c['status'] === 'active' ? 'inactive' : 'active' ?>">
                                                                <?php if ($c['status'] === 'active'): ?>
                                                                    <button type="submit" class="btn btn-sm btn-outline-warning w-100 d-flex align-items-center justify-content-center gap-2 py-1.5">
                                                                        <i class="bi bi-slash-circle"></i>Disable Account
                                                                    </button>
                                                                <?php else: ?>
                                                                    <button type="submit" class="btn btn-sm btn-outline-success w-100 d-flex align-items-center justify-content-center gap-2 py-1.5">
                                                                        <i class="bi bi-check-circle"></i>Enable Account
                                                                    </button>
                                                                <?php endif; ?>
                                                            </form>

                                                            <?php if (empty($c['email_verified'])): ?>
                                                                <form method="POST" class="d-inline" onsubmit="return confirm('Manually verify OTP for <?= e($c['full_name']) ?>?');">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="verify_email">
                                                                    <input type="hidden" name="user_id" value="<?= (int)$c['id'] ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-info w-100 d-flex align-items-center justify-content-center gap-2 py-1.5">
                                                                        <i class="bi bi-shield-check"></i>Verify OTP Manually
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>

                                                    <!-- Right: Connected Machines -->
                                                    <div class="col-12 col-lg-8 col-xl-9">
                                                        <div class="d-flex align-items-center justify-content-between mb-3">
                                                            <div class="d-flex align-items-center gap-2">
                                                                <span class="fw-bold text-light text-uppercase small tracking-wide">Connected Machines</span>
                                                                <span class="fw-bold text-info fs-6 font-monospace ms-1">(<?= count($c['machines']) ?>)</span>
                                                            </div>
                                                        </div>

                                                        <?php if (empty($c['machines'])): ?>
                                                            <div class="text-center py-4 px-3 rounded-3" style="background: rgba(15, 23, 42, 0.4); border: 1px dashed rgba(148, 163, 184, 0.2);">
                                                                <i class="bi bi-hdd-network text-secondary fs-3 d-block mb-2"></i>
                                                                <div class="text-secondary small fw-medium">No vending machines connected to this owner yet.</div>
                                                                <div class="text-muted extra-small mt-1">When machines activate with product keys linked to this account, they will automatically appear here.</div>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="row g-2">
                                                                <?php foreach ($c['machines'] as $m): ?>
                                                                    <?php
                                                                        $mStatus = $m['calculated_status'] ?? 'offline';
                                                                        $statusClass = 'bg-secondary text-secondary';
                                                                        $statusIcon = 'bi-circle-fill';
                                                                        $statusText = 'Offline';

                                                                        if ($mStatus === 'online') {
                                                                            $statusClass = 'bg-success bg-opacity-15 text-success border border-success border-opacity-25';
                                                                            $statusIcon = 'bi-broadcast';
                                                                            $statusText = 'Online';
                                                                        } elseif ($mStatus === 'stale') {
                                                                            $statusClass = 'bg-warning bg-opacity-15 text-warning border border-warning border-opacity-25';
                                                                            $statusIcon = 'bi-clock-history';
                                                                            $statusText = 'Stale';
                                                                        } else {
                                                                            $statusClass = 'bg-danger bg-opacity-15 text-danger border border-danger border-opacity-25';
                                                                            $statusIcon = 'bi-slash-circle';
                                                                            $statusText = 'Offline';
                                                                        }

                                                                        $displaySerial = !empty($m['hardware_serial']) ? $m['hardware_serial'] : (!empty($m['product_key']) ? $m['product_key'] : ($m['machine_key'] ?? 'N/A'));
                                                                    ?>
                                                                    <div class="col-12 col-md-6">
                                                                        <div class="machine-mini-card h-100">
                                                                            <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                                                                                <div class="text-truncate">
                                                                                    <span class="fw-semibold text-light fs-6 d-block text-truncate" title="<?= e($m['name'] ?: 'Machine #' . $m['id']) ?>">
                                                                                        <i class="bi bi-hdd-rack text-primary me-1.5"></i><?= e($m['name'] ?: 'Machine #' . $m['id']) ?>
                                                                                    </span>
                                                                                    <span class="text-muted extra-small">ID #<?= (int)$m['id'] ?> &bull; <?= e($m['location'] ? ucfirst($m['location']) : 'Standard') ?></span>
                                                                                </div>
                                                                                <span class="badge <?= $statusClass ?> rounded-pill px-2.5 py-1 small flex-shrink-0">
                                                                                    <i class="bi <?= $statusIcon ?> me-1"></i><?= $statusText ?>
                                                                                </span>
                                                                            </div>

                                                                            <div class="d-flex flex-wrap gap-2 mb-2">
                                                                                <!-- Machine Code (e.g. MCH-3D60) -->
                                                                                <div class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-20 font-monospace py-1 px-2 text-truncate" title="Machine Code">
                                                                                    <i class="bi bi-tag-fill me-1 text-primary"></i><?= e($m['machine_code'] ?: 'No Code') ?>
                                                                                </div>

                                                                                <!-- Hardware Serial (e.g. has-9192) -->
                                                                                <?php if (!empty($m['hardware_serial']) && $m['hardware_serial'] !== $m['machine_code']): ?>
                                                                                    <div class="badge bg-dark text-info border border-info border-opacity-25 font-monospace py-1 px-2 text-truncate" title="Hardware Serial">
                                                                                        <i class="bi bi-cpu me-1"></i><?= e($m['hardware_serial']) ?>
                                                                                    </div>
                                                                                <?php endif; ?>

                                                                                <!-- Activation Key / Product Key -->
                                                                                <?php 
                                                                                    $keyVal = !empty($m['product_key']) ? $m['product_key'] : (!empty($m['machine_key']) ? $m['machine_key'] : '');
                                                                                ?>
                                                                                <?php if (!empty($keyVal)): ?>
                                                                                    <div class="badge bg-dark text-warning border border-warning border-opacity-25 font-monospace py-1 px-2 text-truncate" title="Activation / Product Key">
                                                                                        <i class="bi bi-key-fill me-1"></i><?= e($keyVal) ?>
                                                                                    </div>
                                                                                <?php endif; ?>
                                                                            </div>

                                                                            <div class="d-flex align-items-center justify-content-between text-secondary extra-small border-top border-secondary border-opacity-10 pt-2 mt-1">
                                                                                <span><i class="bi bi-globe me-1"></i><?= e($m['ip_address'] ?? '—') ?></span>
                                                                                <span><i class="bi bi-activity me-1"></i><?= !empty($m['last_seen']) ? date('M d, H:i', strtotime($m['last_seen'])) : 'No Heartbeat' ?></span>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Edit Customer Modals placed cleanly outside the table -->
            <?php if (!empty($customers)): ?>
                <?php foreach ($customers as $c): ?>
                    <div class="modal fade" id="editOwnerModal<?= (int)$c['id'] ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content bg-dark text-light border-secondary border-opacity-25" style="background:#0f172a; border-color:#1e293b;">
                                <div class="modal-header border-secondary border-opacity-25">
                                    <h5 class="modal-title fw-bold">
                                        <i class="bi bi-pencil-square me-2 text-primary"></i><?= term('btn_edit_customer') ?>
                                    </h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <div class="modal-body p-4">
                                        <input type="hidden" name="action" value="edit_customer">
                                        <input type="hidden" name="user_id" value="<?= (int)$c['id'] ?>">

                                        <div class="mb-3">
                                            <label class="form-label text-secondary small fw-bold">FULL NAME</label>
                                            <input type="text" name="full_name" class="form-control bg-dark text-light border-secondary border-opacity-25" value="<?= e($c['full_name']) ?>" required>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label text-secondary small fw-bold">EMAIL ADDRESS</label>
                                            <input type="email" name="email" class="form-control bg-dark text-light border-secondary border-opacity-25" value="<?= e($c['email']) ?>" required>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label text-secondary small fw-bold">USERNAME</label>
                                            <input type="text" name="username" class="form-control bg-dark text-light border-secondary border-opacity-25" value="<?= e($c['username']) ?>" required>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label text-secondary small fw-bold">NEW PASSWORD (OPTIONAL)</label>
                                            <div class="input-group">
                                                <input type="password" name="password" class="form-control bg-dark text-light border-secondary border-opacity-25 edit-admin-password" placeholder="Leave blank to keep current password"
                                                       data-target-confirm="#confirmCustGroup<?= (int)$c['id'] ?>"
                                                       data-target-rules="#rulesCustGroup<?= (int)$c['id'] ?>"
                                                       data-target-match="#matchCustGroup<?= (int)$c['id'] ?>">
                                                <button class="btn btn-outline-secondary border-secondary border-opacity-25 toggle-password-btn text-light" type="button" aria-label="Show password">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            </div>
                                            <div id="rulesCustGroup<?= (int)$c['id'] ?>" class="text-slate-400 small mt-1">Leave blank unless you wish to change the password.</div>
                                        </div>

                                        <div class="mb-3 d-none" id="confirmCustGroup<?= (int)$c['id'] ?>">
                                            <label class="form-label text-secondary small fw-bold">CONFIRM NEW PASSWORD</label>
                                            <div class="input-group">
                                                <input type="password" name="confirm_password" class="form-control bg-dark text-light border-secondary border-opacity-25 edit-admin-confirm" placeholder="Re-enter new password"
                                                       data-target-match="#matchCustGroup<?= (int)$c['id'] ?>">
                                                <button class="btn btn-outline-secondary border-secondary border-opacity-25 toggle-password-btn text-light" type="button" aria-label="Show password">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            </div>
                                            <div id="matchCustGroup<?= (int)$c['id'] ?>" class="mt-1"></div>
                                        </div>
                                    </div>
                                    <div class="modal-footer border-secondary border-opacity-25">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-primary fw-bold">
                                            <i class="bi bi-check-lg me-1"></i>Save Changes
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
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
