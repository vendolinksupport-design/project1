<?php
/**
 * ============================================================
 * VendoLink — Admin Accounts Management
 * ============================================================
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../models/User.php';

require_auth();
require_role(ROLE_ADMIN);

$currentUser = current_user();
$pageTitle   = term('admin_accounts', 'Admin Accounts');
$currentPage = 'users';
$currentDir  = 'users';
$userModel   = new User();
$db          = Database::getInstance();

const MASTER_ADMIN_EMAIL = 'vendolink.support@gmail.com';

// ============================================================
// Form Submissions (Add Admin, Edit Admin, Toggle Status)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = trim($_POST['action'] ?? '');

    // 1. ADD ADMIN ACCOUNT
    if ($action === 'add_admin') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        $errors = [];
        if (empty($fullName)) {
            $errors[] = 'Full Name is required.';
        }
        if (empty($username)) {
            $errors[] = 'Username is required.';
        } elseif ($userModel->findByUsername($username)) {
            $errors[] = 'Username is already taken.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        } elseif ($userModel->findByEmail($email)) {
            $errors[] = 'Email address is already in use.';
        }
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must be at least 8 characters long and contain both letters and numbers.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        if (empty($errors)) {
            $newId = $userModel->create([
                'full_name' => $fullName,
                'username'  => $username,
                'email'     => $email,
                'password'  => $password,
                'role'      => ROLE_ADMIN,
                'status'    => 'active',
            ]);
            $db->query("UPDATE users SET email_verified = 1 WHERE id = ?", [$newId]);
            log_activity('ADMIN_CREATED', "Created new Administrator account: @{$username} ({$email})", $currentUser['id']);
            flash('success', 'Admin account created successfully.');
            redirect('admin/users/index.php');
        } else {
            flash('danger', implode('<br>', $errors));
            redirect('admin/users/index.php');
        }
    }

    // 2. EDIT ADMIN ACCOUNT
    if ($action === 'edit_admin') {
        $userId   = (int)($_POST['user_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $username = trim($_POST['username'] ?? '');
        $password        = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        $target = $userModel->findById($userId);
        if (!$target) {
            flash('danger', 'Admin user not found.');
            redirect('admin/users/index.php');
        }

        $errors = [];
        if (empty($fullName)) {
            $errors[] = 'Full Name is required.';
        }
        if (empty($username)) {
            $errors[] = 'Username is required.';
        } else {
            $existing = $userModel->findByUsername($username);
            if ($existing && (int)$existing['id'] !== $userId) {
                $errors[] = 'Username is already taken by another account.';
            }
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        } else {
            $existing = $userModel->findByEmail($email);
            if ($existing && (int)$existing['id'] !== $userId) {
                $errors[] = 'Email address is already in use by another account.';
            }
        }
        if (!empty($password)) {
            if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
                $errors[] = 'New password must be at least 8 characters long and contain both letters and numbers.';
            }
            if ($password !== $confirmPassword) {
                $errors[] = 'Passwords do not match.';
            }
        }

        if (empty($errors)) {
            $userModel->update($userId, [
                'full_name' => $fullName,
                'username'  => $username,
                'email'     => $email,
                'role'      => $target['role'],
                'status'    => $target['status'],
                'phone'     => $target['phone'] ?? null,
            ]);

            if (!empty($password)) {
                $userModel->updatePassword($userId, $password);
            }

            log_activity('ADMIN_UPDATED', "Updated Administrator account: @{$username} (ID: {$userId})", $currentUser['id']);
            flash('success', 'Admin account updated successfully.');
            redirect('admin/users/index.php');
        } else {
            flash('danger', implode('<br>', $errors));
            redirect('admin/users/index.php');
        }
    }

    // 3. TOGGLE ADMIN STATUS (ACTIVATE / DEACTIVATE)
    if ($action === 'toggle_status') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $target = $userModel->findById($userId);

        if (!$target) {
            flash('danger', 'Admin user not found.');
            redirect('admin/users/index.php');
        }

        $isMaster = ($target['email'] === MASTER_ADMIN_EMAIL || (int)$target['id'] === 1);
        if ($isMaster && $target['status'] === 'active') {
            flash('danger', 'Cannot deactivate the Master Admin account.');
            redirect('admin/users/index.php');
        }

        if ($target['status'] === 'active') {
            // Guard: check if only 1 active admin remains
            $activeAdminCount = $userModel->countActiveAdmins();
            if ($activeAdminCount <= 1) {
                flash('danger', 'Cannot deactivate the only active administrator account. At least one administrator must remain active.');
                redirect('admin/users/index.php');
            }

            // Guard: cannot self-deactivate
            if ($userId === (int)$currentUser['id']) {
                flash('danger', 'You cannot deactivate your own account while signed in.');
                redirect('admin/users/index.php');
            }

            try {
                $userModel->setStatus($userId, 'inactive');
                log_activity('ADMIN_DEACTIVATED', "Deactivated Administrator account: @{$target['username']}", $currentUser['id']);
                flash('success', "Admin account @{$target['username']} deactivated.");
            } catch (Exception $e) {
                flash('danger', $e->getMessage());
            }
        } else {
            try {
                $userModel->setStatus($userId, 'active');
                log_activity('ADMIN_ACTIVATED', "Activated Administrator account: @{$target['username']}", $currentUser['id']);
                flash('success', "Admin account @{$target['username']} activated.");
            } catch (Exception $e) {
                flash('danger', $e->getMessage());
            }
        }

        redirect('admin/users/index.php');
    }
}

// Fetch internal administrators
$users = $userModel->getInternalUsers();
$activeAdminCount = $userModel->countActiveAdmins();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — VendoAdmin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/app.css">

    <style>
        .admin-card {
            background: #0f172a;
            border: 1px solid rgba(148, 163, 184, 0.12);
            border-radius: 14px;
            padding: 1.25rem;
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5);
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

        .avatar-circle-master {
            background: linear-gradient(135deg, #38bdf8, #2563eb);
            box-shadow: 0 0 10px rgba(56, 189, 248, 0.35);
        }
    </style>
</head>
<body class="dashboard-body">
<div class="app-shell">
    <?php require_once __DIR__ . '/../../views/layouts/admin_sidebar.php'; ?>
    <div class="main-content" id="mainContent">
        <?php require_once __DIR__ . '/../../views/layouts/admin_topnav.php'; ?>
        <main class="content-wrapper p-3 p-md-4">
            <?php if ($flashHtml = render_flash()): ?>
            <div class="flash-container mb-3"><?= $flashHtml ?></div>
            <?php endif; ?>

            <!-- Header Section -->
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 gap-2">
                <div>
                    <h3 class="fw-bold mb-1 text-light">
                        <i class="bi bi-people-fill text-primary me-2"></i><?= term('admin_accounts') ?>
                    </h3>
                    <p class="text-secondary mb-0">View, monitor, and manage administrative accounts.</p>
                </div>
                <div>
                    <button type="button" class="btn btn-primary btn-sm fw-semibold px-3 py-2" data-bs-toggle="modal" data-bs-target="#addAdminModal">
                        <i class="bi bi-plus-lg me-1"></i><?= term('add_admin_account') ?>
                    </button>
                </div>
            </div>

            <!-- Admin Accounts Table Card -->
            <div class="admin-card">
                <div class="table-responsive">
                    <table class="table table-dark admin-table mb-0">
                        <thead>
                            <tr>
                                <th style="width: 36%;">Admin User</th>
                                <th style="width: 28%;">Email Address</th>
                                <th style="width: 14%;">Status</th>
                                <th style="width: 14%;">Last Login</th>
                                <th style="width: 8%;" class="text-end pe-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-secondary">
                                    <i class="bi bi-inbox fs-2 d-block mb-2 text-muted"></i>No admin accounts found.
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($users as $u): ?>
                                <?php
                                    $firstChar = strtoupper(mb_substr($u['full_name'] ?? $u['username'] ?? 'A', 0, 1));
                                    $isMaster = ($u['email'] === MASTER_ADMIN_EMAIL || (int)$u['id'] === 1);
                                ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="avatar-circle <?= $isMaster ? 'avatar-circle-master' : '' ?>">
                                                <?= e($firstChar) ?>
                                            </div>
                                            <div class="fw-bold text-white">
                                                <?= e($u['full_name']) ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="text-info font-monospace small"><?= e($u['email']) ?></span>
                                    </td>
                                    <td>
                                        <?php if ($u['status'] === 'active'): ?>
                                        <span class="text-success small fw-medium d-inline-flex align-items-center">
                                            <span class="me-2" style="width: 7px; height: 7px; background: #22c55e; border-radius: 50%; display: inline-block; margin-right: 8px; flex-shrink: 0;"></span>Active
                                        </span>
                                        <?php else: ?>
                                        <span class="text-secondary small fw-medium d-inline-flex align-items-center">
                                            <span class="me-2" style="width: 7px; height: 7px; background: #64748b; border-radius: 50%; display: inline-block; margin-right: 8px; flex-shrink: 0;"></span>Inactive
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="text-slate-300 small font-monospace">
                                            <?= $u['last_login'] ? format_date($u['last_login'], 'M d, Y h:i A') : '<span class="text-slate-500">Never</span>' ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-3">
                                        <button type="button" class="btn btn-sm btn-outline-primary py-1 px-2.5 fw-semibold" style="font-size: .78rem;" data-bs-toggle="modal" data-bs-target="#editAdminModal<?= (int)$u['id'] ?>">
                                            <i class="bi bi-pencil-square me-1"></i>Edit
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ============================================================
                 MODAL 1: ADD ADMIN ACCOUNT (OVERLAY DIALOG)
                 ============================================================ -->
            <div class="modal fade" id="addAdminModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content bg-dark text-light border-secondary border-opacity-25" style="background:#0f172a; border-color:#1e293b;">
                        <div class="modal-header border-secondary border-opacity-25">
                            <h5 class="modal-title fw-bold">
                                <i class="bi bi-person-plus-fill me-2 text-primary"></i><?= term('add_admin_account') ?>
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST" action="index.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_admin">

                            <div class="modal-body p-4">
                                <div class="mb-3">
                                    <label class="form-label text-secondary small fw-bold">FULL NAME</label>
                                    <input type="text" name="full_name" class="form-control bg-dark text-light border-secondary border-opacity-25" placeholder="e.g. Alex Rivera" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label text-secondary small fw-bold">EMAIL ADDRESS</label>
                                    <input type="email" name="email" class="form-control bg-dark text-light border-secondary border-opacity-25" placeholder="e.g. alex.admin@vendolink.local" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label text-secondary small fw-bold">USERNAME</label>
                                    <input type="text" name="username" class="form-control bg-dark text-light border-secondary border-opacity-25" placeholder="e.g. alex_admin" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label text-secondary small fw-bold">PASSWORD</label>
                                    <div class="input-group">
                                        <input type="password" id="addAdminPassword" name="password" class="form-control bg-dark text-light border-secondary border-opacity-25" placeholder="Min. 8 alphanumeric" minlength="8" required>
                                        <button class="btn btn-outline-secondary border-secondary border-opacity-25 toggle-password-btn text-light" type="button" aria-label="Show password">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                    <div id="addAdminPwRules" class="text-slate-400 small mt-1">Must be at least 8 characters with letters and numbers.</div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label text-secondary small fw-bold">CONFIRM PASSWORD</label>
                                    <div class="input-group">
                                        <input type="password" id="addAdminConfirmPassword" name="confirm_password" class="form-control bg-dark text-light border-secondary border-opacity-25" placeholder="Re-enter password" minlength="8" required>
                                        <button class="btn btn-outline-secondary border-secondary border-opacity-25 toggle-password-btn text-light" type="button" aria-label="Show password">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                    <div id="addAdminPwMatch" class="mt-1"></div>
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

            <!-- ============================================================
                 MODAL 2: EDIT ADMIN INFO (OVERLAY DIALOG FOR EACH ADMIN)
                 ============================================================ -->
            <?php if (!empty($users)): ?>
                <?php foreach ($users as $u): ?>
                    <div class="modal fade" id="editAdminModal<?= (int)$u['id'] ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content bg-dark text-light border-secondary border-opacity-25" style="background:#0f172a; border-color:#1e293b;">
                                <div class="modal-header border-secondary border-opacity-25">
                                    <h5 class="modal-title fw-bold">
                                        <i class="bi bi-pencil-square me-2 text-primary"></i>Edit Admin User
                                    </h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <form method="POST" action="index.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="edit_admin">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">

                                    <div class="modal-body p-4">
                                        <div class="mb-3">
                                            <label class="form-label text-secondary small fw-bold">FULL NAME</label>
                                            <input type="text" name="full_name" class="form-control bg-dark text-light border-secondary border-opacity-25" value="<?= e($u['full_name']) ?>" required>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label text-secondary small fw-bold">EMAIL ADDRESS</label>
                                            <input type="email" name="email" class="form-control bg-dark text-light border-secondary border-opacity-25" value="<?= e($u['email']) ?>" required>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label text-secondary small fw-bold">USERNAME</label>
                                            <input type="text" name="username" class="form-control bg-dark text-light border-secondary border-opacity-25" value="<?= e($u['username']) ?>" required>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label text-secondary small fw-bold">NEW PASSWORD (OPTIONAL)</label>
                                            <div class="input-group">
                                                <input type="password" name="password" class="form-control bg-dark text-light border-secondary border-opacity-25 edit-admin-password" placeholder="Leave blank to keep current password"
                                                       data-target-confirm="#confirmAdminGroup<?= (int)$u['id'] ?>"
                                                       data-target-rules="#rulesAdminGroup<?= (int)$u['id'] ?>"
                                                       data-target-match="#matchAdminGroup<?= (int)$u['id'] ?>">
                                                <button class="btn btn-outline-secondary border-secondary border-opacity-25 toggle-password-btn text-light" type="button" aria-label="Show password">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            </div>
                                            <div id="rulesAdminGroup<?= (int)$u['id'] ?>" class="text-slate-400 small mt-1">Leave blank unless you wish to change the password.</div>
                                        </div>

                                        <div class="mb-3 d-none" id="confirmAdminGroup<?= (int)$u['id'] ?>">
                                            <label class="form-label text-secondary small fw-bold">CONFIRM NEW PASSWORD</label>
                                            <div class="input-group">
                                                <input type="password" name="confirm_password" class="form-control bg-dark text-light border-secondary border-opacity-25 edit-admin-confirm" placeholder="Re-enter new password"
                                                       data-target-match="#matchAdminGroup<?= (int)$u['id'] ?>">
                                                <button class="btn btn-outline-secondary border-secondary border-opacity-25 toggle-password-btn text-light" type="button" aria-label="Show password">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            </div>
                                            <div id="matchAdminGroup<?= (int)$u['id'] ?>" class="mt-1"></div>
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

<script>
/**
 * Guard against deactivating the last active administrator
 */
function handleStatusToggle(userId, activeCount, currentStatus) {
    if (currentStatus === 'active') {
        if (activeCount <= 1) {
            alert('Cannot deactivate the only active administrator account. At least one administrator must remain active.');
            return false;
        }
        return confirm('Are you sure you want to deactivate this admin account?');
    }
    return confirm('Activate this admin account?');
}
</script>
</body>
</html>
