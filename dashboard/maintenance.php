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

$pageTitle   = 'Maintenance';
$currentPage = 'maintenance';
$user        = current_user();
$ownerId     = current_owner_id();

$maintenance = new Maintenance();
$items       = $maintenance->items($ownerId);
$services    = $maintenance->services($ownerId);
$machineRuntimeSeconds = $maintenance->getMachineRuntime($ownerId);
$machineRuntimeMinutes = (int) floor($machineRuntimeSeconds / 60);

$page        = max(1, (int) ($_GET['page'] ?? 1));
$perPage     = 15;
$historyData = $maintenance->historyPaginated($ownerId, $page, $perPage);
$history     = $historyData['items'];
$totalHistoryItems = $historyData['total_items'];
$totalPages  = $historyData['total_pages'];
$page        = $historyData['page'];

if (!function_exists('build_maintenance_url')) {
    function build_maintenance_url(array $overrides = []): string
    {
        $params = array_merge($_GET, $overrides);
        return 'maintenance.php' . (!empty($params) ? '?' . http_build_query($params) : '');
    }
}

function runtime_label(int $seconds): string
{
    $hours = intdiv($seconds, 3600);
    return $hours . 'h ' . intdiv($seconds % 3600, 60) . 'm';
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
                        <i class="bi bi-wrench-adjustable-circle me-2 text-primary-vl"></i>Maintenance
                    </h1>
                    <p class="page-subtitle text-light">
                        Calendar and runtime reminders for VendoLink Machine 01.
                    </p>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <!-- Add Reminder Form Column -->
                <div class="col-12 col-xl-4">
                    <section class="maintenance-form-card">
                        <h2>Add Maintenance Reminder</h2>
                        <p>Create a maintenance reminder based on calendar time, machine runtime, or both.</p>
                        <form method="post" id="addReminderForm">
                            <input type="hidden" name="action" value="add">
                            <?= csrf_field() ?>

                            <label for="new_rem_name">Maintenance Name
                                <input class="form-control dark-input" id="new_rem_name" name="name" required maxlength="120" placeholder="e.g. Water Pump">
                            </label>

                            <label for="new_rem_service">Applies To
                                <select class="form-select dark-input" id="new_rem_service" name="service_id">
                                    <option value="" data-runtime-minutes="<?= $machineRuntimeMinutes ?>">Entire Machine</option>
                                    <?php foreach ($services as $service): ?>
                                        <option value="<?= (int) $service['id'] ?>" data-runtime-minutes="<?= (int) floor(((int)($service['runtime_seconds'] ?? 0)) / 60) ?>">
                                            <?= e($service['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label for="new_rem_type">Reminder Type
                                <select class="form-select dark-input" id="new_rem_type" name="reminder_type">
                                    <option value="both" selected>Both</option>
                                    <option value="calendar">Calendar</option>
                                    <option value="runtime">Runtime</option>
                                </select>
                            </label>

                            <label for="new_rem_date">Last Maintenance
                                <input class="form-control dark-input" id="new_rem_date" type="date" name="last_completed_date" value="<?= date('Y-m-d') ?>" required>
                            </label>

                            <div class="row g-2" id="calendarIntervalGroup">
                                <div class="col-6">
                                    <label for="new_rem_cal_val">Calendar Interval
                                        <input class="form-control dark-input" id="new_rem_cal_val" type="number" min="1" name="calendar_interval_value" value="5">
                                    </label>
                                </div>
                                <div class="col-6">
                                    <label for="new_rem_cal_unit">&nbsp;
                                        <select class="form-select dark-input" id="new_rem_cal_unit" name="calendar_interval_unit">
                                            <option value="days">Days</option>
                                            <option value="weeks">Weeks</option>
                                            <option value="months" selected>Months</option>
                                            <option value="years">Years</option>
                                        </select>
                                    </label>
                                </div>
                            </div>

                            <div id="runtimeIntervalGroup" class="mt-2">
                                <label for="new_rem_run_val">Runtime Interval (minutes)
                                    <input class="form-control dark-input" id="new_rem_run_val" type="number" min="1" name="runtime_interval_minutes" placeholder="e.g. 500">
                                </label>
                                <small class="text-muted d-block mt-1" style="font-size: 0.78rem;">Based on accumulated service runtime.</small>
                            </div>

                            <!-- Next-Due Preview Box -->
                            <div class="maintenance-preview-box my-3 p-3 rounded" id="maintenancePreviewBox" style="background: var(--vl-surface-2); border: 1px solid var(--vl-border);">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="text-uppercase fw-bold" style="font-size: 0.72rem; letter-spacing: 0.05em; color: var(--vl-primary);">
                                        <i class="bi bi-clock-history me-1"></i>Next-Due Preview
                                    </span>
                                    <span class="badge" id="previewBadge" style="font-size: 0.68rem; background: rgba(56, 189, 248, 0.15); color: var(--vl-primary); border: 1px solid rgba(56, 189, 248, 0.25);">Both</span>
                                </div>

                                <!-- CALENDAR ONLY PREVIEW -->
                                <div id="previewCalendarMode" class="d-none">
                                    <div class="d-flex justify-content-between align-items-center py-1 border-bottom" style="border-color: rgba(255,255,255,0.06) !important;">
                                        <span class="text-muted small">Last Maintenance</span>
                                        <span class="fw-semibold text-white small" id="prevCalLast">—</span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center py-1 border-bottom" style="border-color: rgba(255,255,255,0.06) !important;">
                                        <span class="text-muted small">Calendar Interval</span>
                                        <span class="fw-semibold text-white small" id="prevCalInterval">—</span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center pt-1">
                                        <span class="text-muted small">Next Due</span>
                                        <span class="fw-bold text-info small" id="prevCalNext">—</span>
                                    </div>
                                </div>

                                <!-- RUNTIME ONLY PREVIEW -->
                                <div id="previewRuntimeMode" class="d-none">
                                    <div class="d-flex justify-content-between align-items-center py-1 border-bottom" style="border-color: rgba(255,255,255,0.06) !important;">
                                        <span class="text-muted small">Runtime Interval</span>
                                        <span class="fw-semibold text-white small" id="prevRunInterval">—</span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center py-1 border-bottom" style="border-color: rgba(255,255,255,0.06) !important;">
                                        <span class="text-muted small">Current Runtime</span>
                                        <span class="fw-semibold text-white small" id="prevRunCurrent">0 minutes</span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center pt-1">
                                        <span class="text-muted small">Remaining</span>
                                        <span class="fw-bold text-info small" id="prevRunRemaining">—</span>
                                    </div>
                                </div>

                                <!-- BOTH MODE PREVIEW -->
                                <div id="previewBothMode">
                                    <div class="d-flex justify-content-between align-items-center py-1 border-bottom" style="border-color: rgba(255,255,255,0.06) !important;">
                                        <span class="text-muted small">Next Calendar Due</span>
                                        <span class="fw-bold text-info small" id="prevBothCalDue">—</span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center pt-1">
                                        <span class="text-muted small">Runtime Remaining</span>
                                        <span class="fw-bold text-info small" id="prevBothRunRemaining">—</span>
                                    </div>
                                </div>
                            </div>

                            <div class="form-check form-switch mt-3">
                                <input class="form-check-input" type="checkbox" role="switch" name="is_enabled" id="new-enabled" checked>
                                <label class="form-check-label" for="new-enabled">Reminder enabled</label>
                            </div>

                            <button class="btn-save-config mt-3" type="submit">
                                <i class="bi bi-plus-circle me-2"></i>Add Reminder
                            </button>
                        </form>
                    </section>
                </div>

                <!-- Current Reminders Column -->
                <div class="col-12 col-xl-8">
                    <section class="maintenance-list-card">
                        <div class="section-heading">
                            <h2>Current Reminders</h2>
                            <span><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?></span>
                        </div>

                        <?php if (!$items): ?>
                        <div class="maintenance-empty py-5">
                            <i class="bi bi-calendar-check" style="font-size: 3rem; color: var(--vl-text-muted);"></i>
                            <p class="text-light mt-2">No maintenance reminders yet.</p>
                        </div>
                        <?php endif; ?>

                        <?php foreach ($items as $item): ?>
                        <article class="maintenance-item">
                            <div class="maintenance-item-main">
                                <div>
                                    <h3><?= e($item['name']) ?></h3>
                                    <p><?= e($item['service_name'] ?: 'Entire Machine') ?> &middot; <?= e(ucfirst($item['reminder_type'])) ?> reminder</p>
                                </div>
                                <span class="maintenance-status status-<?= e($item['status']) ?>">
                                    <?= match($item['status']) {
                                        'soon' => 'Upcoming',
                                        'due', 'overdue' => 'Due',
                                        'ok' => 'Normal',
                                        default => ucfirst(e($item['status']))
                                    } ?>
                                </span>
                            </div>

                            <div class="maintenance-details">
                                <?php if ($item['next_due_date']): ?>
                                <span><i class="bi bi-calendar3"></i>Next date: <?= e($item['next_due_date']->format('M j, Y')) ?></span>
                                <?php endif; ?>

                                <?php if ($item['runtime_interval_seconds']): ?>
                                <span><i class="bi bi-stopwatch"></i><?= runtime_label((int) $item['current_runtime_seconds'] - (int) $item['runtime_baseline_seconds']) ?> / <?= runtime_label((int) $item['runtime_interval_seconds']) ?></span>
                                <?php endif; ?>

                                <span><i class="bi bi-clock-history"></i>Last: <?= format_date($item['last_completed_at'], 'M j, Y') ?></span>
                            </div>

                            <div class="maintenance-actions">
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="action" value="toggle">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                    <input type="hidden" name="enabled" value="<?= $item['is_enabled'] ? '0' : '1' ?>">
                                    <button class="btn-maintenance-secondary" type="submit">
                                        <?= $item['is_enabled'] ? 'Turn Off' : 'Turn On' ?>
                                    </button>
                                </form>

                                <button class="btn-maintenance-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#edit-<?= (int) $item['id'] ?>">
                                    Edit
                                </button>

                                <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this reminder?');">
                                    <input type="hidden" name="action" value="delete">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                    <button class="btn-maintenance-secondary text-danger" type="submit" style="border-color: #ef4444;">
                                        Delete
                                    </button>
                                </form>

                                <button class="btn-maintenance-primary" type="button" data-bs-toggle="collapse" data-bs-target="#complete-<?= (int) $item['id'] ?>">
                                    Mark Completed
                                </button>
                            </div>

                            <div class="collapse mt-3" id="complete-<?= (int) $item['id'] ?>">
                                <form method="post" class="complete-form">
                                    <input type="hidden" name="action" value="complete">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                    <input class="form-control dark-input" name="notes" maxlength="1000" placeholder="Optional completion notes">
                                    <button class="btn-maintenance-primary" type="submit">Confirm Completion</button>
                                </form>
                            </div>

                            <div class="collapse mt-3" id="edit-<?= (int) $item['id'] ?>">
                                <form method="post" class="maintenance-form-card" style="padding: 1.25rem; border: 1px solid var(--vl-border); border-radius: var(--border-radius); background: var(--vl-surface-2);">
                                    <input type="hidden" name="action" value="edit">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">

                                    <label>Maintenance Name
                                        <input class="form-control dark-input" name="name" required maxlength="120" value="<?= e($item['name']) ?>">
                                    </label>

                                    <label>Applies To
                                        <select class="form-select dark-input" name="service_id">
                                            <option value="">Entire Machine</option>
                                            <?php foreach ($services as $service): ?>
                                                <option value="<?= (int) $service['id'] ?>" <?= $item['service_id'] == $service['id'] ? 'selected' : '' ?>>
                                                    <?= e($service['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>

                                    <label>Reminder Type
                                        <select class="form-select dark-input" name="reminder_type">
                                            <option value="calendar" <?= $item['reminder_type'] === 'calendar' ? 'selected' : '' ?>>Calendar</option>
                                            <option value="runtime" <?= $item['reminder_type'] === 'runtime' ? 'selected' : '' ?>>Runtime</option>
                                            <option value="both" <?= $item['reminder_type'] === 'both' ? 'selected' : '' ?>>Both</option>
                                        </select>
                                    </label>

                                    <label>Last Maintenance
                                        <input class="form-control dark-input" type="date" name="last_completed_date" value="<?= date('Y-m-d', strtotime($item['last_completed_at'])) ?>" required>
                                    </label>

                                    <div class="row g-2">
                                        <div class="col-6">
                                            <label>Calendar Interval
                                                <input class="form-control dark-input" type="number" min="1" name="calendar_interval_value" value="<?= e((string) $item['calendar_interval_value']) ?>">
                                            </label>
                                        </div>
                                        <div class="col-6">
                                            <label>&nbsp;
                                                <select class="form-select dark-input" name="calendar_interval_unit">
                                                    <option value="months" <?= $item['calendar_interval_unit'] === 'months' ? 'selected' : '' ?>>Months</option>
                                                    <option value="weeks" <?= $item['calendar_interval_unit'] === 'weeks' ? 'selected' : '' ?>>Weeks</option>
                                                    <option value="days" <?= $item['calendar_interval_unit'] === 'days' ? 'selected' : '' ?>>Days</option>
                                                    <option value="years" <?= $item['calendar_interval_unit'] === 'years' ? 'selected' : '' ?>>Years</option>
                                                </select>
                                            </label>
                                        </div>
                                    </div>

                                    <label>Runtime Interval (minutes)
                                        <input class="form-control dark-input" type="number" min="1" name="runtime_interval_minutes" value="<?= $item['runtime_interval_seconds'] ? (int) $item['runtime_interval_seconds'] / 60 : '' ?>">
                                    </label>
                                    <small class="text-muted d-block mt-1" style="font-size: 0.78rem;">Based on accumulated service runtime.</small>

                                    <div class="form-check form-switch mt-3">
                                        <input class="form-check-input" type="checkbox" role="switch" name="is_enabled" id="edit-enabled-<?= (int) $item['id'] ?>" <?= $item['is_enabled'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="edit-enabled-<?= (int) $item['id'] ?>">Reminder enabled</label>
                                    </div>

                                    <button class="btn-save-config mt-3" type="submit">Save Changes</button>
                                </form>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </section>
                </div>
            </div>

            <!-- Maintenance History Section -->
            <div class="tx-table-card archive-table-card maintenance-table-card mb-4">
                <div class="panel-header">
                    <h5 class="panel-title">
                        <i class="bi bi-wrench-adjustable-circle me-2"></i>Maintenance History
                    </h5>
                    <span class="text-muted small">
                        Showing <?= number_format(count($history)) ?> of <?= number_format($totalHistoryItems) ?> records
                    </span>
                </div>

                <?php if (empty($history)): ?>
                <div class="activity-empty py-5">
                    <i class="bi bi-wrench-adjustable-circle" style="font-size: 3rem; color: var(--vl-text-muted);"></i>
                    <p class="text-light mt-2">Completed maintenance will appear here.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle mb-0" style="--bs-table-bg: transparent;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--vl-border); color: var(--vl-text-muted); font-size: .78rem; text-transform: uppercase; letter-spacing: .06em;">
                                <th class="ps-4">Maintenance</th>
                                <th>Completed</th>
                                <th>By</th>
                                <th>Runtime</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $record): ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,.04); font-size: .88rem;">
                                <td class="ps-4">
                                    <div class="text-light fw-bold">
                                        <i class="bi bi-wrench-adjustable-circle me-1 text-primary-vl"></i>
                                        <?= e($record['maintenance_name']) ?>
                                    </div>
                                </td>
                                <td><?= format_date($record['completed_at']) ?></td>
                                <td>
                                    <div class="text-light">
                                        <i class="bi bi-person me-1 text-muted"></i>
                                        <?= e($record['full_name'] ?: 'System') ?>
                                    </div>
                                </td>
                                <td><?= runtime_label((int) $record['runtime_at_completion_seconds']) ?></td>
                                <td><?= e($record['notes'] ?: '—') ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php
                            $emptyRowsCount = max(0, $perPage - count($history));
                            for ($i = 0; $i < $emptyRowsCount; $i++):
                            ?>
                            <tr class="tx-empty-row" aria-hidden="true">
                                <td class="ps-4">&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Controls -->
                <?php if ($totalPages > 1): ?>
                <div class="tx-pagination">
                    <div class="text-muted small">
                        Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong> (Total <?= number_format($totalHistoryItems) ?> records)
                    </div>
                    <nav aria-label="Maintenance pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <!-- Previous -->
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= build_maintenance_url(['page' => $page - 1]) ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;&nbsp;Prev</span>
                                </a>
                            </li>

                            <!-- Page Numbers -->
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage   = min($totalPages, $page + 2);

                            if ($startPage > 1) {
                                echo '<li class="page-item"><a class="page-link" href="' . build_maintenance_url(['page' => 1]) . '">1</a></li>';
                                if ($startPage > 2) {
                                    echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                }
                            }

                            for ($p = $startPage; $p <= $endPage; $p++): ?>
                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= build_maintenance_url(['page' => $p]) ?>"><?= $p ?></a>
                            </li>
                            <?php endfor;

                            if ($endPage < $totalPages) {
                                if ($endPage < $totalPages - 1) {
                                    echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                }
                                echo '<li class="page-item"><a class="page-link" href="' . build_maintenance_url(['page' => $totalPages]) . '">' . $totalPages . '</a></li>';
                            }
                            ?>

                            <!-- Next -->
                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= build_maintenance_url(['page' => $page + 1]) ?>" aria-label="Next">
                                    <span aria-hidden="true">Next&nbsp;&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

        </main>

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
    const typeSelect      = document.getElementById('new_rem_type');
    const serviceSelect   = document.getElementById('new_rem_service');
    const dateInput       = document.getElementById('new_rem_date');
    const calValInput     = document.getElementById('new_rem_cal_val');
    const calUnitSelect   = document.getElementById('new_rem_cal_unit');
    const runValInput     = document.getElementById('new_rem_run_val');

    const calGroup        = document.getElementById('calendarIntervalGroup');
    const runGroup        = document.getElementById('runtimeIntervalGroup');

    const prevBadge       = document.getElementById('previewBadge');
    const modeCal         = document.getElementById('previewCalendarMode');
    const modeRun         = document.getElementById('previewRuntimeMode');
    const modeBoth        = document.getElementById('previewBothMode');

    // Calendar Elements
    const prevCalLast     = document.getElementById('prevCalLast');
    const prevCalInterval = document.getElementById('prevCalInterval');
    const prevCalNext     = document.getElementById('prevCalNext');

    // Runtime Elements
    const prevRunInterval = document.getElementById('prevRunInterval');
    const prevRunCurrent  = document.getElementById('prevRunCurrent');
    const prevRunRemaining= document.getElementById('prevRunRemaining');

    // Both Elements
    const prevBothCalDue  = document.getElementById('prevBothCalDue');
    const prevBothRunRemaining = document.getElementById('prevBothRunRemaining');

    if (!typeSelect || !dateInput || !calValInput || !calUnitSelect || !runValInput) {
        return;
    }

    function formatShortDate(d) {
        if (!d || isNaN(d.getTime())) return '—';
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        const yyyy = d.getFullYear();
        return `${mm}/${dd}/${yyyy}`;
    }

    function formatLongDate(d) {
        if (!d || isNaN(d.getTime())) return '—';
        return d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
    }

    function calculateNextDate(dateStr, val, unit) {
        if (!dateStr || !val || val <= 0) return null;
        const parts = dateStr.split('-');
        if (parts.length !== 3) return null;
        const y = parseInt(parts[0], 10);
        const m = parseInt(parts[1], 10) - 1;
        const day = parseInt(parts[2], 10);
        const d = new Date(y, m, day);
        if (isNaN(d.getTime())) return null;

        if (unit === 'days') {
            d.setDate(d.getDate() + val);
        } else if (unit === 'weeks') {
            d.setDate(d.getDate() + (val * 7));
        } else if (unit === 'months') {
            d.setMonth(d.getMonth() + val);
        } else if (unit === 'years') {
            d.setFullYear(d.getFullYear() + val);
        }
        return d;
    }

    function updateFormAndPreview() {
        const type   = typeSelect.value;
        const isCal  = (type === 'calendar');
        const isRun  = (type === 'runtime');
        const isBoth = (type === 'both');

        // 1. Control Enabled / Disabled State (Values are NEVER cleared)
        calValInput.disabled   = isRun;
        calUnitSelect.disabled = isRun;
        runValInput.disabled   = isCal;

        calValInput.required   = (isCal || isBoth);
        calUnitSelect.required = (isCal || isBoth);
        runValInput.required   = (isRun || isBoth);

        // Visual opacity styling for disabled sections
        if (calGroup) {
            calGroup.style.opacity = isRun ? '0.45' : '1';
            calGroup.style.pointerEvents = isRun ? 'none' : 'auto';
        }
        if (runGroup) {
            runGroup.style.opacity = isCal ? '0.45' : '1';
            runGroup.style.pointerEvents = isCal ? 'none' : 'auto';
        }

        // 2. Toggle active preview layout
        modeCal.classList.toggle('d-none', !isCal);
        modeRun.classList.toggle('d-none', !isRun);
        modeBoth.classList.toggle('d-none', !isBoth);

        if (prevBadge) {
            prevBadge.textContent = type.charAt(0).toUpperCase() + type.slice(1);
        }

        // 3. Compute Calendar Next Due
        const dateVal      = dateInput.value;
        const calInterval  = parseInt(calValInput.value, 10);
        const calUnit      = calUnitSelect.value;
        const calUnitLabel = calUnitSelect.options[calUnitSelect.selectedIndex]?.text || calUnit;

        const nextDate     = calculateNextDate(dateVal, calInterval, calUnit);
        const shortNextDue = nextDate ? formatShortDate(nextDate) : '—';
        const longNextDue  = nextDate ? formatLongDate(nextDate) : '—';

        let shortLastDate  = '—';
        if (dateVal) {
            const dp = dateVal.split('-');
            if (dp.length === 3) {
                shortLastDate = `${dp[1]}/${dp[2]}/${dp[0]}`;
            }
        }

        prevCalLast.textContent     = shortLastDate;
        prevCalInterval.textContent = (calInterval > 0) ? `${calInterval} ${calUnitLabel}` : '—';
        prevCalNext.textContent     = shortNextDue;

        // 4. Compute Runtime Remaining
        const selectedOpt    = serviceSelect ? serviceSelect.options[serviceSelect.selectedIndex] : null;
        const currentRuntime = selectedOpt ? parseInt(selectedOpt.getAttribute('data-runtime-minutes') || '0', 10) : 0;
        const runIntervalRaw = runValInput.value.trim();
        const runInterval    = runIntervalRaw !== '' ? parseInt(runIntervalRaw, 10) : null;

        let runIntervalText  = '—';
        let remainingText    = '—';
        let isDue            = false;

        if (runInterval !== null && !isNaN(runInterval) && runInterval > 0) {
            runIntervalText = `${runInterval} minutes`;
            const remaining = Math.max(0, runInterval - currentRuntime);
            if (currentRuntime >= runInterval) {
                isDue = true;
                remainingText = '0 minutes (Due)';
            } else {
                remainingText = `${remaining} minutes`;
            }
        }

        prevRunInterval.textContent  = runIntervalText;
        prevRunCurrent.textContent   = `${currentRuntime} minutes`;
        if (isDue) {
            prevRunRemaining.innerHTML = '<span class="badge bg-warning text-dark">Due</span>';
        } else {
            prevRunRemaining.textContent = remainingText;
        }

        prevBothCalDue.textContent   = longNextDue;
        if (isDue) {
            prevBothRunRemaining.innerHTML = '<span class="badge bg-warning text-dark">Due</span>';
        } else {
            prevBothRunRemaining.textContent = remainingText;
        }
    }

    // Immediate reactive updates on change or typing
    typeSelect.addEventListener('change', updateFormAndPreview);
    if (serviceSelect) serviceSelect.addEventListener('change', updateFormAndPreview);
    dateInput.addEventListener('input', updateFormAndPreview);
    dateInput.addEventListener('change', updateFormAndPreview);
    calValInput.addEventListener('input', updateFormAndPreview);
    calValInput.addEventListener('change', updateFormAndPreview);
    calUnitSelect.addEventListener('change', updateFormAndPreview);
    runValInput.addEventListener('input', updateFormAndPreview);
    runValInput.addEventListener('change', updateFormAndPreview);

    // Initial render
    updateFormAndPreview();
});
</script>

</body>
</html>
