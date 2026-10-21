<?php
/**
 * ============================================================
 * VendoLink — Maintenance Management & Reminders
 * ============================================================
 *
 * Equipment maintenance schedules, calendar intervals, runtime
 * intervals, and completion history tracking.
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Maintenance.php';
require_once __DIR__ . '/../controllers/MaintenanceController.php';

require_role([ROLE_ADMIN, ROLE_OWNER]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    (new MaintenanceController())->handle();
}

$pageTitle   = 'Service Reminders';
$currentPage = 'maintenance';
$user        = current_user();
$ownerId     = current_owner_id();

$maintenance = new Maintenance();
$page        = max(1, (int) ($_GET['page'] ?? 1));
$perPage     = 5;
$paginationData = $maintenance->itemsPaginated($ownerId, $page, $perPage);
$items       = $paginationData['items'];
$totalItems  = $paginationData['total_items'];
$totalPages  = $paginationData['total_pages'];
$page        = $paginationData['page'];
$countText   = $paginationData['count_text'];
$activeCount = $maintenance->countActive($ownerId);
$isLimitReached = ($activeCount >= Maintenance::MAX_ACTIVE_REMINDERS);

$services    = $maintenance->services($ownerId);
$machineRuntimeSeconds = $maintenance->getMachineRuntime($ownerId);
$machineRuntimeMinutes = (int) floor($machineRuntimeSeconds / 60);

if (!function_exists('build_maintenance_url')) {
    function build_maintenance_url(array $overrides = []): string
    {
        $params = array_merge($_GET, $overrides);
        return 'maintenance.php' . (!empty($params) ? '?' . http_build_query($params) : '');
    }
}

if (!function_exists('runtime_label')) {
    function runtime_label(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        return $hours . 'h ' . intdiv($seconds % 3600, 60) . 'm';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
                        <i class="bi bi-wrench-adjustable-circle me-2 text-primary-vl"></i>Service Reminders
                    </h1>
                    <p class="page-subtitle text-light">
                        Manage service schedules and keep your machine running smoothly.
                    </p>
                </div>
            </div>

            <!-- Active Reminders Section (Full Width) -->
            <div class="row g-4 mb-4">
                <div class="col-12">
                    <section class="maintenance-list-card">
                        <div class="section-heading d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
                            <div>
                                <h2 class="mb-1 text-white">Active Reminders</h2>
                                <p class="text-muted small mb-0">Manage your scheduled maintenance tasks.</p>
                            </div>
                            <div>
                                <button type="button" class="btn-maintenance-primary d-inline-flex align-items-center" data-bs-toggle="modal" data-bs-target="#addReminderModal"<?= $isLimitReached ? ' title="Maximum 50 active reminders reached"' : '' ?>>
                                    <i class="bi bi-plus-circle me-1.5"></i>Add Reminder
                                </button>
                            </div>
                        </div>

                        <?php if ($isLimitReached): ?>
                        <div class="alert alert-warning py-2 px-3 small my-3 d-flex align-items-center gap-2">
                            <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
                            <span>Maximum active reminder limit reached (50 of 50 reminders). Delete or turn off an existing reminder to add a new one.</span>
                        </div>
                        <?php endif; ?>

                        <?php if ($totalItems > 0 && $activeCount === 0): ?>
                        <div class="alert alert-info py-2 px-3 small my-3 d-flex align-items-center gap-2" style="background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(56, 189, 248, 0.25); color: #cbd5e1;">
                            <i class="bi bi-info-circle text-primary-vl flex-shrink-0"></i>
                            <span><strong>No active reminders:</strong> All scheduled reminders are currently turned off. Turn on a reminder below or create a new one to resume service schedule tracking.</span>
                        </div>
                        <?php endif; ?>

                        <div class="maintenance-items-container" id="maintenanceItemsContainer">
                            <?php if ($totalItems === 0): ?>
                            <div class="maintenance-empty-state">
                                <div class="maintenance-empty-icon-circle">
                                    <i class="bi bi-calendar-check"></i>
                                </div>
                                <h3 class="maintenance-empty-heading">No active reminders</h3>
                                <p class="maintenance-empty-desc">You haven't scheduled any service reminders yet.</p>
                                <p class="maintenance-empty-subtext">Create one to keep your machine in good condition.</p>
                                <div class="mt-3">
                                    <button type="button" class="btn-maintenance-primary d-inline-flex align-items-center" data-bs-toggle="modal" data-bs-target="#addReminderModal" id="btnCreateFirstReminder">
                                        <i class="bi bi-plus-circle me-1.5"></i>Create First Reminder
                                    </button>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="maintenance-items-list">

                        <?php foreach ($items as $item): 
                            $serviceKey = strtolower($item['service_name'] ?? '');
                            $serviceIcon = 'bi-cpu';
                            $serviceIconColor = 'var(--vl-primary)';
                            if (str_contains($serviceKey, 'water')) {
                                $serviceIcon = 'bi-droplet-fill';
                                $serviceIconColor = '#38bdf8';
                            } elseif (str_contains($serviceKey, 'soap')) {
                                $serviceIcon = 'bi-sparkles';
                                $serviceIconColor = '#f59e0b';
                            }
                        ?>
                        <article class="maintenance-item">
                            <div class="maintenance-item-main">
                                <div>
                                    <h3 class="d-flex align-items-center gap-2 mb-1">
                                        <i class="bi <?= $serviceIcon ?>" style="color: <?= $serviceIconColor ?>; font-size: 0.95em;"></i>
                                        <span><?= e($item['name']) ?></span>
                                    </h3>
                                    <p class="text-muted small mb-0">
                                        <?= e($item['service_name'] ?: 'Entire Machine') ?> &bull; <?= e(ucfirst($item['reminder_type'])) ?> reminder
                                    </p>
                                </div>
                                <span class="maintenance-status status-<?= e($item['status']) ?>">
                                    <?= match($item['status']) {
                                        'soon', 'due' => 'Due Soon',
                                        'overdue' => 'Overdue',
                                        'ok' => 'Normal',
                                        'off' => 'Turned Off',
                                        default => ucfirst(e($item['status']))
                                    } ?>
                                </span>
                            </div>

                            <div class="maintenance-details d-flex flex-wrap align-items-center gap-2 mt-3">
                                <div class="d-flex align-items-center gap-1.5">
                                    <i class="bi bi-calendar3 text-primary-vl"></i>
                                    <span class="text-secondary small fw-semibold text-uppercase" style="letter-spacing: 0.04em;">NEXT DUE:</span>
                                    <span class="text-light fw-medium">
                                        <?= $item['next_due_date'] ? e($item['next_due_date']->format('M j, Y')) : 'Runtime only' ?>
                                    </span>
                                    <?php if ($item['runtime_interval_seconds']): ?>
                                    <span class="text-muted small ms-1">(<i class="bi bi-stopwatch text-primary-vl"></i><?= runtime_label((int) $item['current_runtime_seconds'] - (int) $item['runtime_baseline_seconds']) ?> / <?= runtime_label((int) $item['runtime_interval_seconds']) ?>)</span>
                                    <?php endif; ?>
                                </div>

                                <span class="text-secondary opacity-50 d-none d-sm-inline mx-2">|</span>

                                <div class="d-flex align-items-center gap-1.5">
                                    <i class="bi bi-check-circle-fill text-success"></i>
                                    <span class="text-secondary small fw-semibold text-uppercase" style="letter-spacing: 0.04em;">LAST COMPLETED:</span>
                                    <span class="text-light fw-medium">
                                        <?= $item['last_completed_at'] ? format_date($item['last_completed_at'], 'M j, Y') : 'Never' ?>
                                    </span>
                                </div>
                            </div>

                            <div class="maintenance-actions mt-3">
                                <button class="btn-maintenance-secondary" type="button" data-bs-toggle="modal" data-bs-target="#editModal-<?= (int) $item['id'] ?>">
                                    Edit
                                </button>

                                <form method="post" class="d-inline">
                                    <input type="hidden" name="action" value="toggle">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                    <input type="hidden" name="enabled" value="<?= $item['is_enabled'] ? '0' : '1' ?>">
                                    <input type="hidden" name="page" value="<?= (int) $page ?>">
                                    <button class="btn-maintenance-secondary" type="submit">
                                        <?= $item['is_enabled'] ? 'Turn Off' : 'Turn On' ?>
                                    </button>
                                </form>

                                <button class="btn-maintenance-primary" type="button" data-bs-toggle="collapse" data-bs-target="#complete-<?= (int) $item['id'] ?>">
                                    Mark Completed
                                </button>
                            </div>

                            <div class="collapse mt-3" id="complete-<?= (int) $item['id'] ?>">
                                <form method="post" class="complete-form" onsubmit="var btn=this.querySelector('button[type=submit]'); if(btn){btn.disabled=true; btn.innerHTML='<span class=\'spinner-border spinner-border-sm me-1\'></span>Saving...';}">
                                    <input type="hidden" name="action" value="complete">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                    <input type="hidden" name="page" value="<?= (int) $page ?>">
                                    <input class="form-control dark-input" name="notes" maxlength="1000" placeholder="Optional completion notes">
                                    <button class="btn-maintenance-primary" type="submit">Confirm Completion</button>
                                </form>
                            </div>
                        </article>
                        <?php endforeach; ?>
                            </div>
                            <?php if (count($items) > 0 && count($items) < 5): ?>
                            <!-- Reserved Space Helper Message for Unused Rows -->
                            <div class="maintenance-reserved-space">
                                <div class="maintenance-reserved-content">
                                    <svg class="maintenance-reserved-icon" width="28" height="24" viewBox="0 0 28 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                        <path d="M5 2H23L26 9H2L5 2Z" stroke="#64748b" stroke-width="1.8" stroke-linejoin="round" fill="none" opacity="0.75"/>
                                        <line x1="4" y1="14" x2="24" y2="14" stroke="#64748b" stroke-width="1.8" stroke-linecap="round" opacity="0.65"/>
                                        <line x1="4" y1="19" x2="24" y2="19" stroke="#64748b" stroke-width="1.8" stroke-linecap="round" opacity="0.5"/>
                                    </svg>
                                    <p class="maintenance-reserved-title">Reserved space for the remaining reminder rows</p>
                                    <p class="maintenance-reserved-sub">No placeholder cards are displayed.</p>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Compact Pagination Controls (Always Rendered at Bottom) -->
                        <div class="tx-pagination maintenance-pagination d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3 pt-3 mt-auto border-top border-secondary border-opacity-25" id="maintenancePagination">
                            <div class="text-muted small" id="maintenancePaginationCount">
                                <?= e($countText) ?>
                            </div>
                            <nav aria-label="Active reminders pagination">
                                <ul class="pagination pagination-sm mb-0">
                                    <!-- Previous -->
                                    <li class="page-item <?= ($page <= 1 || $totalItems === 0) ? 'disabled' : '' ?>">
                                        <?php if ($page <= 1 || $totalItems === 0): ?>
                                            <span class="page-link" aria-disabled="true">&laquo;&nbsp;Prev</span>
                                        <?php else: ?>
                                            <a class="page-link" href="<?= build_maintenance_url(['page' => $page - 1]) ?>" aria-label="Previous">&laquo;&nbsp;Prev</a>
                                        <?php endif; ?>
                                    </li>

                                    <!-- Page Numbers -->
                                    <?php if ($totalItems === 0): ?>
                                        <li class="page-item disabled" aria-current="page"><span class="page-link">1</span></li>
                                    <?php else: ?>
                                        <?php
                                        $startPage = max(1, $page - 2);
                                        $endPage   = min($totalPages, $page + 2);

                                        if ($startPage > 1) {
                                            echo '<li class="page-item"><a class="page-link" href="' . build_maintenance_url(['page' => 1]) . '">1</a></li>';
                                            if ($startPage > 2) {
                                                echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                            }
                                        }

                                        for ($p = $startPage; $p <= $endPage; $p++):
                                            if ($p === $page):
                                                echo '<li class="page-item active" aria-current="page"><span class="page-link">' . $p . '</span></li>';
                                            else:
                                                echo '<li class="page-item"><a class="page-link" href="' . build_maintenance_url(['page' => $p]) . '">' . $p . '</a></li>';
                                            endif;
                                        endfor;

                                        if ($endPage < $totalPages) {
                                            if ($endPage < $totalPages - 1) {
                                                echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                            }
                                            echo '<li class="page-item"><a class="page-link" href="' . build_maintenance_url(['page' => $totalPages]) . '">' . $totalPages . '</a></li>';
                                        }
                                        ?>
                                    <?php endif; ?>

                                    <!-- Next -->
                                    <li class="page-item <?= ($page >= $totalPages || $totalItems === 0) ? 'disabled' : '' ?>">
                                        <?php if ($page >= $totalPages || $totalItems === 0): ?>
                                            <span class="page-link" aria-disabled="true">Next&nbsp;&raquo;</span>
                                        <?php else: ?>
                                            <a class="page-link" href="<?= build_maintenance_url(['page' => $page + 1]) ?>" aria-label="Next">Next&nbsp;&raquo;</a>
                                        <?php endif; ?>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    </section>
                </div>
            </div>

        </main>

        <!-- EDIT MAINTENANCE REMINDER MODALS -->
        <?php foreach ($items as $item): ?>
        <div class="modal fade" id="editModal-<?= (int) $item['id'] ?>" tabindex="-1" aria-labelledby="editModalLabel-<?= (int) $item['id'] ?>" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content text-light border-secondary border-opacity-25" style="background:#0f172a; border-color:#1e293b;">
                    <div class="modal-header border-secondary border-opacity-25 pb-3">
                        <h5 class="modal-title fw-bold text-white fs-6" id="editModalLabel-<?= (int) $item['id'] ?>">
                            <i class="bi bi-wrench-adjustable-circle me-2 text-primary-vl"></i>Edit Reminder
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="post" id="editForm-<?= (int)$item['id'] ?>" action="<?= BASE_URL ?>/dashboard/maintenance.php">
                        <input type="hidden" name="action" value="edit">
                        <?= csrf_field() ?>
                        <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                        <input type="hidden" name="last_completed_date" value="<?= date('Y-m-d', strtotime($item['last_completed_at'])) ?>">
                        <input type="hidden" name="page" value="<?= (int) $page ?>">

                        <div class="modal-body p-4">
                            <!-- Maintenance Name -->
                            <div class="mb-3">
                                <label class="form-label text-light small fw-semibold mb-1" for="edit_name_<?= (int)$item['id'] ?>">
                                    Maintenance Name <span class="text-danger">*</span>
                                </label>
                                <input class="form-control dark-input mt-0" id="edit_name_<?= (int)$item['id'] ?>" name="name" required minlength="2" maxlength="120" value="<?= e($item['name']) ?>">
                            </div>

                            <!-- Applies To -->
                            <div class="mb-3">
                                <label class="form-label text-light small fw-semibold mb-1" for="edit_service_<?= (int)$item['id'] ?>">Applies To</label>
                                <select class="form-select dark-input mt-0" id="edit_service_<?= (int)$item['id'] ?>" name="service_id">
                                    <option value="">Entire Machine</option>
                                    <?php foreach ($services as $service): ?>
                                        <option value="<?= (int) $service['id'] ?>" <?= $item['service_id'] == $service['id'] ? 'selected' : '' ?>>
                                            <?= e($service['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Reminder Type -->
                            <div class="mb-3">
                                <label class="form-label text-light small fw-semibold mb-2 d-block">Reminder Type</label>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input edit-type-radio" type="radio" name="reminder_type" id="edit_type_cal_<?= (int)$item['id'] ?>" value="calendar" data-item-id="<?= (int)$item['id'] ?>" <?= $item['reminder_type'] === 'calendar' ? 'checked' : '' ?>>
                                        <label class="form-check-label text-light small" for="edit_type_cal_<?= (int)$item['id'] ?>">Calendar</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input edit-type-radio" type="radio" name="reminder_type" id="edit_type_run_<?= (int)$item['id'] ?>" value="runtime" data-item-id="<?= (int)$item['id'] ?>" <?= $item['reminder_type'] === 'runtime' ? 'checked' : '' ?>>
                                        <label class="form-check-label text-light small" for="edit_type_run_<?= (int)$item['id'] ?>">Runtime</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input edit-type-radio" type="radio" name="reminder_type" id="edit_type_both_<?= (int)$item['id'] ?>" value="both" data-item-id="<?= (int)$item['id'] ?>" <?= $item['reminder_type'] === 'both' ? 'checked' : '' ?>>
                                        <label class="form-check-label text-light small" for="edit_type_both_<?= (int)$item['id'] ?>">Both</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Repeat Every (Calendar Interval Group) -->
                            <div class="mb-3 <?= $item['reminder_type'] === 'runtime' ? 'd-none' : '' ?>" id="editCalendarGroup-<?= (int)$item['id'] ?>">
                                <label class="form-label text-light small fw-semibold mb-1" for="edit_cal_val_<?= (int)$item['id'] ?>">
                                    Repeat Every <span class="text-danger">*</span>
                                </label>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <input class="form-control dark-input mt-0" id="edit_cal_val_<?= (int)$item['id'] ?>" type="number" min="1" step="1" name="calendar_interval_value" value="<?= e((string) $item['calendar_interval_value']) ?>" <?= $item['reminder_type'] === 'runtime' ? 'disabled' : '' ?>>
                                    </div>
                                    <div class="col-6">
                                        <select class="form-select dark-input mt-0" name="calendar_interval_unit" <?= $item['reminder_type'] === 'runtime' ? 'disabled' : '' ?>>
                                            <option value="days" <?= $item['calendar_interval_unit'] === 'days' ? 'selected' : '' ?>>Days</option>
                                            <option value="weeks" <?= $item['calendar_interval_unit'] === 'weeks' ? 'selected' : '' ?>>Weeks</option>
                                            <option value="months" <?= $item['calendar_interval_unit'] === 'months' ? 'selected' : '' ?>>Months</option>
                                            <option value="years" <?= $item['calendar_interval_unit'] === 'years' ? 'selected' : '' ?>>Years</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Runtime Interval Group -->
                            <div class="mb-3 <?= $item['reminder_type'] === 'calendar' ? 'd-none' : '' ?>" id="editRuntimeGroup-<?= (int)$item['id'] ?>">
                                <label class="form-label text-light small fw-semibold mb-1" for="edit_run_val_<?= (int)$item['id'] ?>">
                                    Runtime Interval (minutes) <span class="text-danger">*</span>
                                </label>
                                <input class="form-control dark-input mt-0" id="edit_run_val_<?= (int)$item['id'] ?>" type="number" min="1" step="1" name="runtime_interval_minutes" value="<?= $item['runtime_interval_seconds'] ? (int) $item['runtime_interval_seconds'] / 60 : '' ?>" placeholder="e.g. 500" <?= $item['reminder_type'] === 'calendar' ? 'disabled' : '' ?>>
                                <small class="text-muted d-block mt-1" style="font-size: 0.78rem;">Based on accumulated service runtime.</small>
                            </div>

                            <!-- Reminder Enabled Toggle -->
                            <div class="form-check form-switch mt-3">
                                <input class="form-check-input" type="checkbox" role="switch" name="is_enabled" id="edit-enabled-<?= (int) $item['id'] ?>" <?= $item['is_enabled'] ? 'checked' : '' ?>>
                                <label class="form-check-label text-light small" for="edit-enabled-<?= (int) $item['id'] ?>">Reminder enabled</label>
                            </div>
                        </div>

                        <div class="modal-footer border-secondary border-opacity-25 px-4 py-3 d-flex flex-column gap-3">
                            <div class="d-flex justify-content-between align-items-center w-100">
                                <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn-maintenance-primary px-4">Save Changes</button>
                            </div>
                            <div class="w-100 pt-2 border-top border-secondary border-opacity-25 text-center">
                                <button type="submit" form="deleteForm-<?= (int)$item['id'] ?>" class="btn btn-link text-danger text-decoration-none p-0 small fw-medium" onclick="return confirm('Are you sure you want to delete this reminder? This will not delete past maintenance history.');">
                                    <i class="bi bi-trash3 me-1"></i>Delete this reminder
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Hidden standalone delete form -->
                    <form method="post" action="<?= BASE_URL ?>/dashboard/maintenance.php" id="deleteForm-<?= (int)$item['id'] ?>" class="d-none">
                        <input type="hidden" name="action" value="delete">
                        <?= csrf_field() ?>
                        <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                        <input type="hidden" name="page" value="<?= (int) $page ?>">
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- ADD SERVICE REMINDER MODAL -->
        <div class="modal fade" id="addReminderModal" tabindex="-1" aria-labelledby="addReminderModalLabel" aria-label="Add Service Reminder" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content text-light border-secondary border-opacity-25" style="background:#0f172a; border-color:#1e293b;">
                    <div class="modal-header border-secondary border-opacity-25 pb-3">
                        <h5 class="modal-title fw-bold text-white fs-6" id="addReminderModalLabel">
                            <i class="bi bi-wrench-adjustable-circle me-2 text-primary-vl"></i>Add Reminder
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="post" id="modalAddReminderForm" action="<?= BASE_URL ?>/dashboard/maintenance.php" novalidate>
                        <input type="hidden" name="action" value="add">
                        <?= csrf_field() ?>
                        <input type="hidden" name="last_completed_date" value="<?= date('Y-m-d') ?>">
                        <input type="hidden" name="is_enabled" value="1">
                        <input type="hidden" name="page" value="1">

                        <div class="modal-body p-4">
                            <!-- Maintenance Name -->
                            <div class="mb-3">
                                <label class="form-label text-light small fw-semibold mb-1" for="modal_rem_name">
                                    Maintenance Name <span class="text-danger">*</span>
                                </label>
                                <input class="form-control dark-input mt-0" id="modal_rem_name" name="name" required minlength="2" maxlength="120" placeholder="e.g. Clean water filter">
                                <div class="invalid-feedback">Please enter a maintenance name (2–120 characters).</div>
                            </div>

                            <!-- Applies To -->
                            <div class="mb-3">
                                <label class="form-label text-light small fw-semibold mb-1" for="modal_rem_service">Applies To</label>
                                <select class="form-select dark-input mt-0" id="modal_rem_service" name="service_id">
                                    <option value="">Entire Machine</option>
                                    <?php foreach ($services as $service): ?>
                                        <option value="<?= (int) $service['id'] ?>">
                                            <?= e($service['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Reminder Type -->
                            <div class="mb-3">
                                <label class="form-label text-light small fw-semibold mb-2 d-block">Reminder Type</label>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="reminder_type" id="type_calendar" value="calendar" checked>
                                        <label class="form-check-label text-light small" for="type_calendar">Calendar</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="reminder_type" id="type_runtime" value="runtime">
                                        <label class="form-check-label text-light small" for="type_runtime">Runtime</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="reminder_type" id="type_both" value="both">
                                        <label class="form-check-label text-light small" for="type_both">Both</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Repeat Every (Calendar Interval Group) -->
                            <div class="mb-3" id="modalCalendarGroup">
                                <label class="form-label text-light small fw-semibold mb-1" for="modal_rem_cal_val">
                                    Repeat Every <span class="text-danger">*</span>
                                </label>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <input class="form-control dark-input mt-0" id="modal_rem_cal_val" type="number" min="1" step="1" name="calendar_interval_value" value="1" placeholder="e.g. 1">
                                    </div>
                                    <div class="col-6">
                                        <select class="form-select dark-input mt-0" id="modal_rem_cal_unit" name="calendar_interval_unit">
                                            <option value="days">Days</option>
                                            <option value="weeks">Weeks</option>
                                            <option value="months" selected>Months</option>
                                            <option value="years">Years</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="invalid-feedback d-none" id="modalCalFeedback">Please enter a valid interval (at least 1).</div>
                            </div>

                            <!-- Runtime Interval Group -->
                            <div class="mb-3 d-none" id="modalRuntimeGroup">
                                <label class="form-label text-light small fw-semibold mb-1" for="modal_rem_run_val">
                                    Runtime Interval (minutes) <span class="text-danger">*</span>
                                </label>
                                <input class="form-control dark-input mt-0" id="modal_rem_run_val" type="number" min="1" step="1" name="runtime_interval_minutes" placeholder="e.g. 500" disabled>
                                <div class="invalid-feedback d-none" id="modalRunFeedback">Please enter a valid runtime interval in minutes (at least 1).</div>
                            </div>
                        </div>

                        <div class="modal-footer border-secondary border-opacity-25 px-4 py-3">
                            <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn-maintenance-primary px-4 d-inline-flex align-items-center" id="modalSubmitBtn">
                                <i class="bi bi-plus-circle me-1.5"></i>Add Reminder
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Page Footer -->
        <footer class="app-footer">
            <span><?= APP_NAME ?> v<?= APP_VERSION ?> &copy; <?= date('Y') ?></span>
            <span>Powered by VendoCore IoT Controller</span>
        </footer>

    </div><!-- /.main-content -->

</div><!-- /.app-shell -->

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- VendoLink JS -->
<script src="<?= ASSETS_URL ?>/js/app.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // --- ADD REMINDER MODAL LOGIC ---
    const modalForm           = document.getElementById('modalAddReminderForm');
    const modalNameInput      = document.getElementById('modal_rem_name');
    const modalTypeRadios     = modalForm ? modalForm.querySelectorAll('input[name="reminder_type"]') : [];
    const modalCalGroup       = document.getElementById('modalCalendarGroup');
    const modalCalValInput    = document.getElementById('modal_rem_cal_val');
    const modalCalUnitSelect  = document.getElementById('modal_rem_cal_unit');
    const modalRunGroup       = document.getElementById('modalRuntimeGroup');
    const modalRunValInput    = document.getElementById('modal_rem_run_val');

    function updateModalTypeVisibility() {
        if (!modalForm) return;
        const checkedRadio = modalForm.querySelector('input[name="reminder_type"]:checked');
        const type = checkedRadio ? checkedRadio.value : 'calendar';
        const isCal  = (type === 'calendar');
        const isRun  = (type === 'runtime');
        const isBoth = (type === 'both');

        // Calendar group
        if (modalCalGroup && modalCalValInput && modalCalUnitSelect) {
            modalCalGroup.classList.toggle('d-none', isRun);
            modalCalValInput.disabled = isRun;
            modalCalUnitSelect.disabled = isRun;
            modalCalValInput.required = (isCal || isBoth);
            modalCalUnitSelect.required = (isCal || isBoth);
            if (isRun) {
                modalCalValInput.classList.remove('is-invalid');
                document.getElementById('modalCalFeedback')?.classList.add('d-none');
            }
        }

        // Runtime group
        if (modalRunGroup && modalRunValInput) {
            modalRunGroup.classList.toggle('d-none', isCal);
            modalRunValInput.disabled = isCal;
            modalRunValInput.required = (isRun || isBoth);
            if (isCal) {
                modalRunValInput.classList.remove('is-invalid');
                document.getElementById('modalRunFeedback')?.classList.add('d-none');
            }
        }
    }

    modalTypeRadios.forEach(function (radio) {
        radio.addEventListener('change', updateModalTypeVisibility);
        radio.addEventListener('click', updateModalTypeVisibility);
    });

    // Initial state
    updateModalTypeVisibility();

    // Reset validation when modal opens
    const addModalEl = document.getElementById('addReminderModal');
    if (addModalEl) {
        addModalEl.addEventListener('show.bs.modal', function () {
            if (modalForm) {
                modalForm.classList.remove('was-validated');
                modalNameInput?.classList.remove('is-invalid');
                modalCalValInput?.classList.remove('is-invalid');
                modalRunValInput?.classList.remove('is-invalid');
                document.getElementById('modalCalFeedback')?.classList.add('d-none');
                document.getElementById('modalRunFeedback')?.classList.add('d-none');
            }
            updateModalTypeVisibility();
        });
    }

    // Modal form submit validation
    if (modalForm) {
        modalForm.addEventListener('submit', function (e) {
            let isValid = true;
            const checkedRadio = modalForm.querySelector('input[name="reminder_type"]:checked');
            const type = checkedRadio ? checkedRadio.value : 'calendar';

            // 1. Validate Name
            const name = modalNameInput ? modalNameInput.value.trim() : '';
            if (name.length < 2 || name.length > 120) {
                modalNameInput.classList.add('is-invalid');
                isValid = false;
            } else {
                modalNameInput.classList.remove('is-invalid');
            }

            // 2. Validate Calendar if needed
            if (type === 'calendar' || type === 'both') {
                const calVal = modalCalValInput ? parseInt(modalCalValInput.value, 10) : 0;
                if (isNaN(calVal) || calVal < 1) {
                    modalCalValInput.classList.add('is-invalid');
                    document.getElementById('modalCalFeedback')?.classList.remove('d-none');
                    isValid = false;
                } else {
                    modalCalValInput.classList.remove('is-invalid');
                    document.getElementById('modalCalFeedback')?.classList.add('d-none');
                }
            }

            // 3. Validate Runtime if needed
            if (type === 'runtime' || type === 'both') {
                const runVal = modalRunValInput ? parseInt(modalRunValInput.value, 10) : 0;
                if (isNaN(runVal) || runVal < 1) {
                    modalRunValInput.classList.add('is-invalid');
                    document.getElementById('modalRunFeedback')?.classList.remove('d-none');
                    isValid = false;
                } else {
                    modalRunValInput.classList.remove('is-invalid');
                    document.getElementById('modalRunFeedback')?.classList.add('d-none');
                }
            }

            if (!isValid) {
                e.preventDefault();
                e.stopPropagation();
                return;
            }

            const btn = document.getElementById('modalSubmitBtn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1.5" role="status" aria-hidden="true"></span>Saving...';
            }
        });
    }

    // --- EDIT MODAL DYNAMIC SHOW/HIDE ---
    document.querySelectorAll('.edit-type-radio').forEach(function (radio) {
        radio.addEventListener('change', function () {
            const id = this.getAttribute('data-item-id');
            const cal = document.getElementById('editCalendarGroup-' + id);
            const run = document.getElementById('editRuntimeGroup-' + id);
            const calValInput = document.getElementById('edit_cal_val_' + id);
            const runValInput = document.getElementById('edit_run_val_' + id);
            const val = this.value;
            if (cal) cal.classList.toggle('d-none', val === 'runtime');
            if (run) run.classList.toggle('d-none', val === 'calendar');
            if (calValInput) calValInput.disabled = (val === 'runtime');
            if (runValInput) runValInput.disabled = (val === 'calendar');
        });
    });

    // --- CREATE FIRST REMINDER HANDLER ---
    const btnCreateFirst = document.getElementById('btnCreateFirstReminder');
    if (btnCreateFirst) {
        btnCreateFirst.addEventListener('click', function () {
            const addModalEl = document.getElementById('addReminderModal');
            if (addModalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const modalInstance = bootstrap.Modal.getOrCreateInstance(addModalEl);
                modalInstance.show();
            }
        });
    }
});
</script>

</body>
</html>
