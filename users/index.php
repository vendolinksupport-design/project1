<?php
/**
 * ============================================================
 * VendoLink — User Management Dashboard
 * ============================================================
 *
 * Allows Owners and Administrators to view, add, edit,
 * enable/disable, and change passwords for user accounts.
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/User.php';

// Access guard
require_role([ROLE_ADMIN, ROLE_OWNER]);

$userModel = new User();

// Handle POST actions via UserController
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_once __DIR__ . '/../controllers/UserController.php';
    (new UserController())->handle();
    exit;
}

// Get filter inputs
$search = trim($_GET['search'] ?? '');
$role   = trim($_GET['role']   ?? 'all');
$status = trim($_GET['status'] ?? 'all');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

$filters = [
    'search' => $search,
    'role'   => $role,
    'status' => $status,
    'customerOnly' => true,
];

$metrics = $userModel->getUserMetrics(true);
$result  = $userModel->getPaginated($filters, $page, $perPage);

$userList   = $result['items'];
$totalItems = $result['total_items'];
$totalPages = $result['total_pages'];
$pageNumber = $result['page'];

$pageTitle   = 'User Management';
$currentPage = 'users';
$user        = current_user();

/**
 * Build URL preserving active filters with query overrides.
 */
function build_user_filter_url(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    $clean = [];
    foreach ($params as $k => $v) {
        if ($v !== '' && $v !== null && $v !== 'all') {
            $clean[$k] = $v;
        }
    }
    return 'index.php' . (!empty($clean) ? '?' . http_build_query($clean) : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= APP_NAME ?> User Management — <?= APP_TAGLINE ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>

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

    <!-- Sidebar -->
    <?php require_once __DIR__ . '/../views/layouts/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content" id="mainContent">

        <!-- Top Navigation -->
        <?php require_once __DIR__ . '/../views/layouts/topnav.php'; ?>

        <!-- Page Content -->
        <main class="page-content" id="pageContent">

            <!-- Flash Messages -->
            <?php if ($flashHtml = render_flash()): ?>
            <div class="flash-container mb-3">
                <?= $flashHtml ?>
            </div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="page-header mb-4">
                <div>
                    <h1 class="page-title text-white">
                        <i class="bi bi-people me-2 text-primary-vl"></i>User Management
                    </h1>
                    <p class="page-subtitle text-light">
                        Manage VendoLink team accounts, operational roles, and access credentials.
                    </p>
                </div>
                <div class="page-header-actions">
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
                        <i class="bi bi-person-plus me-1"></i>Add User
                    </button>
                    <div class="page-date text-light">
                        <?= date('l, F j, Y') ?>
                    </div>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="user-stats-grid mb-4">
                <!-- Total Users -->
                <div class="stat-card">
                    <div class="stat-card-body">
                        <div class="stat-card-info">
                            <div class="stat-card-label">Total Users</div>
                            <div class="stat-card-value text-white"><?= number_format($metrics['total_users']) ?></div>
                        </div>
                        <div class="stat-card-icon bg-primary-subtle text-primary">
                            <i class="bi bi-people-fill"></i>
                        </div>
                    </div>
                </div>

                <!-- Active Accounts -->
                <div class="stat-card">
                    <div class="stat-card-body">
                        <div class="stat-card-info">
                            <div class="stat-card-label">Active Accounts</div>
                            <div class="stat-card-value text-success"><?= number_format($metrics['active_users']) ?></div>
                        </div>
                        <div class="stat-card-icon bg-success-subtle text-success">
                            <i class="bi bi-person-check-fill"></i>
                        </div>
                    </div>
                </div>

                <!-- Owners -->
                <div class="stat-card">
                    <div class="stat-card-body">
                        <div class="stat-card-info">
                            <div class="stat-card-label">Owners</div>
                            <div class="stat-card-value text-warning"><?= number_format($metrics['owner_count']) ?></div>
                        </div>
                        <div class="stat-card-icon bg-warning-subtle text-warning">
                            <i class="bi bi-shield-lock-fill"></i>
                        </div>
                    </div>
                </div>

                <!-- Staff -->
                <div class="stat-card">
                    <div class="stat-card-body">
                        <div class="stat-card-info">
                            <div class="stat-card-label">Staff</div>
                            <div class="stat-card-value text-info"><?= number_format($metrics['staff_count']) ?></div>
                        </div>
                        <div class="stat-card-icon bg-info-subtle text-info">
                            <i class="bi bi-person-badge-fill"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Controls -->
            <div class="filter-card mb-4">
                <form method="GET" action="index.php" class="row g-3 align-items-end">
                    <!-- Search Input -->
                    <div class="col-12 col-md-5 col-xl-4">
                        <label class="form-label text-light small mb-1">Search User</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text dark-input" style="border-right: 0;">
                                <i class="bi bi-search text-muted"></i>
                            </span>
                            <input type="text" class="form-control dark-input text-white" name="search"
                                   placeholder="Search by name, email, or username..."
                                   value="<?= e($search) ?>" style="border-left: 0;">
                        </div>
                    </div>

                    <!-- Role Filter -->
                    <div class="col-12 col-sm-6 col-md-3 col-xl-3">
                        <label class="form-label text-light small mb-1">Role</label>
                        <select class="form-select form-select-sm dark-input text-white" name="role">
                            <option value="all" <?= $role === 'all' ? 'selected' : '' ?>>All Roles</option>
                            <option value="owner" <?= $role === 'owner' ? 'selected' : '' ?>>Owner</option>
                            <option value="staff" <?= $role === 'staff' ? 'selected' : '' ?>>Staff</option>
                            <option value="technician" <?= $role === 'technician' ? 'selected' : '' ?>>Technician</option>
                        </select>
                    </div>

                    <!-- Status Filter -->
                    <div class="col-12 col-sm-6 col-md-2 col-xl-3">
                        <label class="form-label text-light small mb-1">Status</label>
                        <select class="form-select form-select-sm dark-input text-white" name="status">
                            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>

                    <!-- Action Buttons -->
                    <div class="col-12 col-md-2 col-xl-2">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm flex-fill">
                                <i class="bi bi-filter me-1"></i>Filter
                            </button>
                            <?php if (!empty($search) || $role !== 'all' || $status !== 'all'): ?>
                            <a href="index.php" class="btn btn-outline-secondary btn-sm" title="Reset filters">
                                <i class="bi bi-x-circle"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Users Table Card -->
            <div class="tx-table-card mb-4">
                <div class="panel-header d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="panel-title text-white">System Users</h2>
                        <p class="panel-subtitle text-light mb-0">
                            Showing <?= number_format(count($userList)) ?> of <?= number_format($totalItems) ?> registered user accounts
                        </p>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle text-white mb-0">
                        <thead>
                            <tr class="text-uppercase text-muted small">
                                <th>Name &amp; Details</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Last Login</th>
                                <th>Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($userList)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    <i class="bi bi-person-x fs-3 d-block mb-2 text-secondary"></i>
                                    No user accounts match your search filters.
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($userList as $u): ?>
                                <tr>
                                    <!-- Name -->
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-circle me-2 bg-slate-800 text-info font-monospace fw-bold">
                                                <?= strtoupper(mb_substr($u['full_name'] ?: $u['username'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <div class="fw-semibold text-white"><?= e($u['full_name']) ?></div>
                                                <div class="text-muted small">@<?= e($u['username']) ?></div>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Email -->
                                    <td class="font-monospace small text-slate-300">
                                        <?= e($u['email']) ?>
                                    </td>

                                    <!-- Role -->
                                    <td>
                                        <?php if ($u['role'] === 'owner' || $u['role'] === 'administrator'): ?>
                                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle">
                                                <i class="bi bi-shield-lock me-1"></i><?= ucfirst(e($u['role'])) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-info-subtle text-info border border-info-subtle">
                                                <i class="bi bi-person-badge me-1"></i><?= ucfirst(e($u['role'])) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Status -->
                                    <td>
                                        <?php if ($u['status'] === 'active'): ?>
                                            <span class="badge bg-success-subtle text-success">
                                                <i class="bi bi-check-circle me-1"></i>Active
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary">
                                                <i class="bi bi-dash-circle me-1"></i>Inactive
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Last Login -->
                                    <td class="small text-muted">
                                        <?= $u['last_login'] ? date('M j, Y g:i A', strtotime($u['last_login'])) : '<span class="text-secondary">Never</span>' ?>
                                    </td>

                                    <!-- Created -->
                                    <td class="small text-muted">
                                        <?= date('M j, Y', strtotime($u['created_at'])) ?>
                                    </td>

                                    <!-- Actions -->
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <!-- Edit User Button -->
                                            <button type="button" class="btn btn-outline-light btn-sm"
                                                    title="Edit User"
                                                    onclick='openEditModal(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                <i class="bi bi-pencil-square"></i>
                                            </button>

                                            <!-- Change Password Button -->
                                            <button type="button" class="btn btn-outline-warning btn-sm"
                                                    title="Change User Password"
                                                    onclick="openChangePwdModal(<?= (int)$u['id'] ?>, '<?= e($u['full_name']) ?>')">
                                                <i class="bi bi-key"></i>
                                            </button>

                                            <!-- Toggle Status (Enable/Disable) -->
                                            <form method="POST" action="index.php" class="d-inline"
                                                  onsubmit="return confirm('Are you sure you want to <?= $u['status'] === 'active' ? 'disable' : 'enable' ?> account <?= e($u['full_name']) ?>?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                                <input type="hidden" name="status" value="<?= $u['status'] === 'active' ? 'inactive' : 'active' ?>">
                                                <?php if ($u['status'] === 'active'): ?>
                                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Disable Account">
                                                        <i class="bi bi-slash-circle"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" class="btn btn-outline-success btn-sm" title="Enable Account">
                                                        <i class="bi bi-check-circle"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="panel-footer d-flex justify-content-between align-items-center p-3 border-top border-slate-700">
                    <span class="small text-muted">
                        Page <?= $pageNumber ?> of <?= $totalPages ?>
                    </span>
                    <nav aria-label="User table pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $pageNumber <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= build_user_filter_url(['page' => $pageNumber - 1]) ?>">&laquo;&nbsp;Prev</a>
                            </li>
                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <li class="page-item <?= $p === $pageNumber ? 'active' : '' ?>">
                                <a class="page-link" href="<?= build_user_filter_url(['page' => $p]) ?>"><?= $p ?></a>
                            </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $pageNumber >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= build_user_filter_url(['page' => $pageNumber + 1]) ?>">Next&nbsp;&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>

        </main>
        
        <!-- Page Footer -->
        <footer class="app-footer">
            <span><?= APP_NAME ?> v<?= APP_VERSION ?> &copy; <?= date('Y') ?></span>
            <span>Powered by VendoCore IoT Controller</span>
        </footer>
    </div>
</div>

<!-- ============================================================
     MODAL 1: Add User
     ============================================================ -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-slate-900 border-slate-700 text-white">
            <div class="modal-header border-slate-700">
                <h5 class="modal-title" id="addUserModalLabel">
                    <i class="bi bi-person-plus text-primary-vl me-2"></i>Add New User
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="index.php" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">

                <div class="modal-body">
                    <!-- Full Name -->
                    <div class="mb-3">
                        <label for="add_full_name" class="form-label text-light small">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control dark-input text-white" id="add_full_name" name="full_name" required placeholder="e.g. Maria Santos">
                    </div>

                    <!-- Email -->
                    <div class="mb-3">
                        <label for="add_email" class="form-label text-light small">Email Address <span class="text-danger">*</span></label>
                        <input type="email" class="form-control dark-input text-white" id="add_email" name="email" required placeholder="e.g. maria@vendolink.local">
                    </div>

                    <!-- Role -->
                    <div class="mb-3">
                        <label for="add_role" class="form-label text-light small">Role <span class="text-danger">*</span></label>
                        <select class="form-select dark-input text-white" id="add_role" name="role" required>
                            <option value="staff" selected>Staff (Operational Monitoring)</option>
                            <option value="owner">Owner (Full System Access)</option>
                            <option value="technician">Technician</option>
                        </select>
                    </div>

                    <!-- Initial Password -->
                    <div class="mb-3">
                        <label for="add_password" class="form-label text-light small">Initial Password (min 8 alphanumeric)</label>
                        <div class="input-password-wrapper">
                            <input type="password" class="form-control dark-input text-white" id="add_password" name="password" placeholder="Leave blank to auto-generate"
                                   data-target-rules="#add_password_rules">
                            <button type="button" class="password-toggle" aria-label="Toggle password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div id="add_password_rules" class="text-slate-400 small mt-1" data-default-text="If left blank, a secure temporary password will be generated.">If left blank, a secure temporary password will be generated.</div>
                    </div>

                    <!-- Status -->
                    <div class="mb-3">
                        <label for="add_status" class="form-label text-light small">Account Status</label>
                        <select class="form-select dark-input text-white" id="add_status" name="status">
                            <option value="active" selected>Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="modal-footer border-slate-700">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check2-circle me-1"></i>Create User Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================
     MODAL 2: Edit User
     ============================================================ -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-slate-900 border-slate-700 text-white">
            <div class="modal-header border-slate-700">
                <h5 class="modal-title" id="editUserModalLabel">
                    <i class="bi bi-pencil-square text-warning me-2"></i>Edit User Account
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="index.php" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id" value="">

                <div class="modal-body">
                    <!-- Full Name -->
                    <div class="mb-3">
                        <label for="edit_full_name" class="form-label text-light small">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control dark-input text-white" id="edit_full_name" name="full_name" required>
                    </div>

                    <!-- Email -->
                    <div class="mb-3">
                        <label for="edit_email" class="form-label text-light small">Email Address <span class="text-danger">*</span></label>
                        <input type="email" class="form-control dark-input text-white" id="edit_email" name="email" required>
                    </div>

                    <!-- Role -->
                    <div class="mb-3">
                        <label for="edit_role" class="form-label text-light small">Role <span class="text-danger">*</span></label>
                        <select class="form-select dark-input text-white" id="edit_role" name="role" required>
                            <option value="owner">Owner (Full System Access)</option>
                            <option value="staff">Staff (Operational Monitoring)</option>
                            <option value="technician">Technician</option>
                        </select>
                    </div>

                    <!-- Status -->
                    <div class="mb-3">
                        <label for="edit_status" class="form-label text-light small">Status <span class="text-danger">*</span></label>
                        <select class="form-select dark-input text-white" id="edit_status" name="status" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="modal-footer border-slate-700">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm text-dark font-weight-bold">
                        <i class="bi bi-check2-circle me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================
     MODAL 3: Change User Password
     ============================================================ -->
<div class="modal fade" id="changeUserPasswordModal" tabindex="-1" aria-labelledby="changePwdModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-slate-900 border-slate-700 text-white">
            <div class="modal-header border-slate-700">
                <h5 class="modal-title" id="changePwdModalLabel">
                    <i class="bi bi-key text-warning me-2"></i>Change User Password
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="index.php" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="id" id="pwd_user_id" value="">

                <div class="modal-body">
                    <p class="text-light small mb-3">
                        Updating password for: <strong id="pwd_user_name" class="text-white"></strong>
                    </p>

                    <!-- New Password -->
                    <div class="mb-3">
                        <label for="pwd_new_password" class="form-label text-light small">New Password <span class="text-danger">*</span></label>
                        <div class="input-password-wrapper">
                            <input type="password" class="form-control dark-input text-white" id="pwd_new_password" name="new_password" required minlength="8" placeholder="Min. 8 alphanumeric"
                                   data-target-rules="#pwd_new_rules"
                                   data-target-match="#pwd_match_hint">
                            <button type="button" class="password-toggle" aria-label="Toggle password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div id="pwd_new_rules" class="text-slate-400 small mt-1" data-default-text="Must be at least 8 characters with letters and numbers.">Must be at least 8 characters with letters and numbers.</div>
                    </div>

                    <!-- Confirm Password -->
                    <div class="mb-3">
                        <label for="pwd_confirm_password" class="form-label text-light small">Confirm New Password <span class="text-danger">*</span></label>
                        <div class="input-password-wrapper">
                            <input type="password" class="form-control dark-input text-white" id="pwd_confirm_password" name="confirm_password" required minlength="8" placeholder="Re-enter password"
                                   data-target-match="#pwd_match_hint">
                            <button type="button" class="password-toggle" aria-label="Toggle password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div id="pwd_match_hint" class="mt-1"></div>
                    </div>
                </div>

                <div class="modal-footer border-slate-700">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm text-dark font-weight-bold">
                        <i class="bi bi-check2-circle me-1"></i>Update Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- VendoLink JS -->
<script src="<?= ASSETS_URL ?>/js/app.js?v=<?= e((string) filemtime(ROOT_PATH . '/assets/js/app.js')) ?>"></script>

<script>
    function openEditModal(userData) {
        document.getElementById('edit_id').value = userData.id;
        document.getElementById('edit_full_name').value = userData.full_name;
        document.getElementById('edit_email').value = userData.email;
        document.getElementById('edit_role').value = userData.role;
        document.getElementById('edit_status').value = userData.status;
        
        var modal = new bootstrap.Modal(document.getElementById('editUserModal'));
        modal.show();
    }

    function openChangePwdModal(userId, userName) {
        document.getElementById('pwd_user_id').value = userId;
        document.getElementById('pwd_user_name').textContent = userName;
        document.getElementById('pwd_new_password').value = '';
        document.getElementById('pwd_confirm_password').value = '';

        var modal = new bootstrap.Modal(document.getElementById('changeUserPasswordModal'));
        modal.show();
    }
</script>
</body>
</html>
