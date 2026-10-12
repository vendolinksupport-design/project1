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

require_admin();

$dashboardModel = new AdminDashboard();
$flashSuccess   = '';
$flashError     = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

            if (empty($fullName) || empty($email) || empty($username)) {
                $flashError = "Full Name, Email Address, and Username are required fields.";
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

$customers = $dashboardModel->getAllCustomers();
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
            padding: 0.8rem 1rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.08);
            vertical-align: middle;
            background: transparent;
        }

        .admin-table tbody tr:hover {
            background: rgba(30, 41, 59, 0.5);
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

            <!-- Metrics Summary Cards (3 Cards) -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-md-4">
                    <div class="admin-stat-card admin-stat-primary">
                        <div>
                            <div class="admin-stat-value"><?= (int)($metrics['total_customers'] ?? 0) ?></div>
                            <div class="admin-stat-label">Total Customers</div>
                        </div>
                        <div class="admin-stat-icon bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-people-fill"></i>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <div class="admin-stat-card admin-stat-success">
                        <div>
                            <div class="admin-stat-value text-success"><?= (int)($metrics['active_customers'] ?? 0) ?></div>
                            <div class="admin-stat-label">Active Customers</div>
                        </div>
                        <div class="admin-stat-icon bg-success bg-opacity-10 text-success">
                            <i class="bi bi-person-check-fill"></i>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <div class="admin-stat-card admin-stat-info">
                        <div>
                            <div class="admin-stat-value text-info"><?= (int)($metrics['total_machines'] ?? 0) ?></div>
                            <div class="admin-stat-label">Machines</div>
                        </div>
                        <div class="admin-stat-icon bg-info bg-opacity-10 text-info">
                            <i class="bi bi-cpu-fill"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Customers Table Card -->
            <div class="admin-card">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="fw-bold text-light mb-0"><i class="bi bi-people-fill me-2 text-info"></i><?= term('customers') ?></h5>
                </div>

                <div class="table-responsive">
                    <table class="table admin-table mb-0">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Email / Username</th>
                                <th>Status</th>
                                <th>Verification</th>
                                <th>Machines</th>
                                <th>Registered</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($customers)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-secondary">
                                    <i class="bi bi-inbox fs-2 d-block mb-2 text-muted"></i>No registered customers found.
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($customers as $c): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="avatar-circle">
                                                <?= strtoupper(mb_substr($c['full_name'] ?? 'U', 0, 1)) ?>
                                            </div>
                                            <div>
                                                <div class="fw-semibold text-light"><?= e($c['full_name']) ?></div>
                                                <div class="small text-secondary">ID #<?= (int)$c['id'] ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="text-light fw-medium"><?= e($c['email']) ?></div>
                                        <div class="small text-secondary">@<?= e($c['username']) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($c['status'] === 'active'): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-3 py-1.5 fw-semibold">
                                                <i class="bi bi-circle-fill me-1 small"></i>Active
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 rounded-pill px-3 py-1.5 fw-semibold">
                                                <i class="bi bi-slash-circle me-1 small"></i>Inactive
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($c['email_verified'])): ?>
                                            <span class="text-success small fw-semibold"><i class="bi bi-patch-check-fill me-1"></i>Verified</span>
                                        <?php else: ?>
                                            <span class="text-warning small fw-semibold"><i class="bi bi-clock-history me-1"></i>Pending OTP</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-dark border border-secondary text-info rounded-pill px-2.5 py-1 font-monospace">
                                            <i class="bi bi-cpu me-1"></i><?= (int)$c['total_machines'] ?>
                                        </span>
                                    </td>
                                    <td class="small text-secondary">
                                        <?= date('M d, Y', strtotime($c['created_at'])) ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <!-- Edit Button -->
                                            <button type="button" class="btn btn-sm btn-outline-primary rounded-2 px-2.5 py-1" data-bs-toggle="modal" data-bs-target="#editOwnerModal<?= (int)$c['id'] ?>" title="Edit Customer">
                                                <i class="bi bi-pencil-square me-1"></i><?= term('btn_edit') ?>
                                            </button>

                                            <!-- Toggle Status Form with Confirmation -->
                                            <form method="POST" class="d-inline" onsubmit="return confirm('<?= $c['status'] === 'active' ? 'Are you sure you want to disable this customer account?' : 'Are you sure you want to enable this customer account?' ?>');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="user_id" value="<?= (int)$c['id'] ?>">
                                                <input type="hidden" name="status" value="<?= $c['status'] === 'active' ? 'inactive' : 'active' ?>">
                                                <?php if ($c['status'] === 'active'): ?>
                                                    <button type="submit" class="btn btn-sm btn-outline-warning rounded-2 px-2.5 py-1" title="Disable Customer Account">
                                                        <i class="bi bi-slash-circle me-1"></i>Disable
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" class="btn btn-sm btn-outline-success rounded-2 px-2.5 py-1" title="Enable Customer Account">
                                                        <i class="bi bi-check-circle me-1"></i>Enable
                                                    </button>
                                                <?php endif; ?>
                                            </form>

                                            <!-- Verify Email Form if pending with Confirmation -->
                                            <?php if (empty($c['email_verified'])): ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Manually verify OTP for <?= e($c['full_name']) ?>?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="verify_email">
                                                    <input type="hidden" name="user_id" value="<?= (int)$c['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-info rounded-2 px-2.5 py-1" title="Manually verify OTP">
                                                        <i class="bi bi-shield-check me-1"></i>Verify OTP
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>

                                        <!-- EDIT CUSTOMER ACCOUNT MODAL -->
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
                                                                <input type="password" name="password" class="form-control bg-dark text-light border-secondary border-opacity-25" placeholder="Leave blank to keep current password">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= ASSETS_URL ?>/js/app.js?v=<?= e((string) filemtime(ROOT_PATH . '/assets/css/app.css')) ?>"></script>
</body>
</html>
