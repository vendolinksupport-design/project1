<?php
/**
 * ============================================================
 * VendoLink — Dashboard Stat Cards & Management Overview
 * ============================================================
 *
 * Layout (Canva wireframe):
 * 1. Maintenance Status          — full width
 * 2. Top KPI Summary             — 4 equal columns
 * 3. Today's Sales Overview      — full width
 * 4. Service Performance         — 2 equal columns
 *    + Machine & Controller Status
 * 5. Recent Transactions         — full width
 * 6. Recent System & Business Activity — full width
 *
 * @package VendoLink
 * @version 1.1
 */
?>

<?php
// Dynamic machine profile and service metadata
$activeMachType = $machineInfo['machine_type'] ?? 'carwash';

// Dynamic Service 1 metadata (Relay 1 / water key)
$s1RawName = $waterService['name'] ?? 'Water Service';
$s1Title   = preg_replace('/\s+Service$/i', '', $s1RawName);
$s1Icon    = 'bi-droplet-fill';
if ($activeMachType === 'air_blower' || stripos($s1Title, 'Air') !== false) {
    $s1Icon = 'bi-speedometer2';
}

// Dynamic Service 2 metadata (Relay 2 / soap key)
$s2RawName = $soapService['name'] ?? 'Soap Service';
$s2Title   = preg_replace('/\s+Service$/i', '', $s2RawName);
$s2Icon    = 'bi-stars';
if ($activeMachType === 'air_blower' || stripos($s2Title, 'Blower') !== false) {
    $s2Icon = 'bi-wind';
}
?>

<!-- ============================================================
     0. Low Tank Alert Banners (Water / Soap Floaters)
     ============================================================ -->
<?php
if (!empty($machineInfo) && $activeMachType === 'carwash') {
    $machineAlerts = (new Machine())->getMachineAlerts($machineInfo, $maintItems ?? []);
    foreach ($machineAlerts as $alert):
        if (strpos($alert['title'], 'Tank') !== false):
?>
<div class="alert alert-warning border-warning bg-warning bg-opacity-10 text-warning d-flex align-items-center justify-content-between p-3 mb-4 rounded-3" style="border: 1px solid rgba(245, 158, 11, 0.3);">
    <div class="d-flex align-items-center">
        <i class="bi <?= e($alert['icon']) ?> fs-3 me-3"></i>
        <div>
            <strong class="d-block text-warning mb-0 fs-6"><?= e($alert['title']) ?></strong>
            <span class="small text-light"><?= e($alert['message']) ?></span>
        </div>
    </div>
    <a href="<?= BASE_URL ?>/dashboard/machines.php" class="btn btn-sm btn-warning fw-bold text-dark ms-3">
        <i class="bi bi-gear-fill me-1"></i>Check Hardware
    </a>
</div>
<?php 
        endif;
    endforeach;
}
?>

<!-- ============================================================
     1. Maintenance — Compact Status Card
     ============================================================ -->
<?php
$dueItems = [];
foreach ($maintItems as $mItem) {
    if (in_array($mItem['status'], ['soon', 'due', 'overdue'], true)) {
        $dueItems[] = $mItem;
    }
}
$hasDue = !empty($dueItems);
$dueCount = count($dueItems);
$totalMaintCount = count($maintItems);
?>
<div class="report-section-card mb-4 p-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="stat-card-icon rounded-circle d-flex align-items-center justify-content-center"
                 style="width: 42px; height: 42px; background: <?= $hasDue ? 'rgba(234, 179, 8, 0.15)' : 'rgba(34, 197, 94, 0.15)' ?>; color: <?= $hasDue ? '#eab308' : '#22c55e' ?>;">
                <i class="bi <?= $hasDue ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill' ?> fs-5"></i>
            </div>
            <div>
                <div class="d-flex align-items-center gap-2">
                    <h6 class="mb-0 fw-bold <?= $hasDue ? 'text-warning' : 'text-success' ?>">
                        <?= $hasDue ? 'Maintenance due' : 'No maintenance due' ?>
                    </h6>
                    <?php if ($hasDue): ?>
                        <span class="badge bg-warning-subtle text-warning border border-warning" style="font-size: 0.72rem;">
                            <?= $dueCount ?> pending reminder<?= $dueCount === 1 ? '' : 's' ?>
                        </span>
                    <?php else: ?>
                        <span class="badge bg-success-subtle text-success border border-success" style="font-size: 0.72rem;">
                            <?= $totalMaintCount ?> active reminder<?= $totalMaintCount === 1 ? '' : 's' ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="text-muted small mt-0.5">
                    <?php if ($hasDue): ?>
                        <?= implode(', ', array_map(fn($it) => e($it['name']), array_slice($dueItems, 0, 3))) ?><?= count($dueItems) > 3 ? '...' : '' ?>
                    <?php else: ?>
                        All equipment servicing is up to date.
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div>
            <a href="<?= BASE_URL ?>/dashboard/maintenance.php" class="btn btn-sm <?= $hasDue ? 'btn-warning text-dark fw-semibold' : 'btn-outline-primary' ?>">
                <i class="bi bi-wrench-adjustable me-1"></i><?= term('view_maintenance', 'View Maintenance') ?>
            </a>
        </div>
    </div>
</div>

<!-- ============================================================
     2. Top KPI Summary Cards (4 equal columns)
     ============================================================ -->
<div class="row g-2 g-md-3 mb-4">

    <!-- Today's Revenue -->
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-card-accent-success">
            <div class="stat-card-body">
                <div class="stat-card-info">
                    <p class="stat-card-label"><?= (!empty($fleetMetrics) && count($fleetMetrics) > 1) ? "Today's Fleet Revenue" : "Today's Revenue" ?></p>
                    <h3 class="stat-card-value text-success">
                        ₱<?= number_format($todayStats['total_revenue'], 2) ?>
                    </h3>
                    <p class="stat-card-trend">
                        <span class="trend-badge trend-up">
                            <i class="bi bi-calendar-event"></i> <?= (!empty($fleetMetrics) && count($fleetMetrics) > 1) ? count($fleetMetrics) . ' Machines Total' : term('todays_sales', "Today's Sales") ?>
                        </span>
                    </p>
                </div>
                <div class="stat-card-icon" style="background: rgba(34,197,94,.1); color: var(--vl-success);">
                    <i class="bi bi-cash-stack"></i>
                </div>
            </div>
            <div class="stat-card-footer">
                <a href="<?= BASE_URL ?>/dashboard/sales.php?date_from=<?= $today ?>&date_to=<?= $today ?>" class="stat-card-link text-muted">
                    <?= term('view_sales', 'View Sales') ?> <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Today's Transactions -->
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-card-accent-primary">
            <div class="stat-card-body">
                <div class="stat-card-info">
                    <p class="stat-card-label">Today's Transactions</p>
                    <h3 class="stat-card-value text-primary-vl">
                        <?= number_format($todayStats['total_transactions']) ?>
                    </h3>
                    <p class="stat-card-trend">
                        <span class="trend-badge trend-neutral">
                            <i class="bi bi-receipt"></i> Completed
                        </span>
                        <span class="text-muted d-none d-sm-inline">Avg. ₱<?= number_format($todayStats['avg_transaction_value'], 2) ?></span>
                    </p>
                </div>
                <div class="stat-card-icon" style="background: rgba(14,165,233,.1); color: var(--vl-primary);">
                    <i class="bi bi-receipt-cutoff"></i>
                </div>
            </div>
            <div class="stat-card-footer">
                <a href="<?= BASE_URL ?>/dashboard/sales.php" class="stat-card-link text-muted">
                    <?= term('view_history', 'View History') ?> <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Service 1 Sales -->
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-card-accent-info">
            <div class="stat-card-body">
                <div class="stat-card-info">
                    <p class="stat-card-label"><?= e($s1Title) ?> Sales</p>
                    <h3 class="stat-card-value text-info">
                        ₱<?= number_format($todayStats['water_revenue'], 2) ?>
                    </h3>
                    <p class="stat-card-trend">
                        <span class="trend-badge" style="background: rgba(56,189,248,.15); color: #38bdf8;">
                            <i class="bi <?= $s1Icon ?>"></i> <?= $todayStats['water_share'] ?>% share
                        </span>
                    </p>
                </div>
                <div class="stat-card-icon" style="background: rgba(56,189,248,.1); color: var(--vl-info);">
                    <i class="bi <?= $s1Icon ?>"></i>
                </div>
            </div>
            <div class="stat-card-footer">
                <a href="<?= BASE_URL ?>/dashboard/sales.php?service=water" class="stat-card-link text-muted">
                    <?= number_format($todayStats['water_count']) ?> transactions <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Service 2 Sales -->
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-card-accent-warning">
            <div class="stat-card-body">
                <div class="stat-card-info">
                    <p class="stat-card-label"><?= e($s2Title) ?> Sales</p>
                    <h3 class="stat-card-value text-warning">
                        ₱<?= number_format($todayStats['soap_revenue'], 2) ?>
                    </h3>
                    <p class="stat-card-trend">
                        <span class="trend-badge" style="background: rgba(234,179,8,.15); color: #fbbf24;">
                            <i class="bi <?= $s2Icon ?>"></i> <?= $todayStats['soap_share'] ?>% share
                        </span>
                    </p>
                </div>
                <div class="stat-card-icon" style="background: rgba(245,158,11,.1); color: var(--vl-warning);">
                    <i class="bi <?= $s2Icon ?>"></i>
                </div>
            </div>
            <div class="stat-card-footer">
                <a href="<?= BASE_URL ?>/dashboard/sales.php?service=soap" class="stat-card-link text-muted">
                    <?= number_format($todayStats['soap_count']) ?> transactions <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

</div>

<!-- ============================================================
     2.1 Fleet Machine Financial Breakdown (Multi-Machine Fleet)
     ============================================================ -->
<?php if (!empty($fleetMetrics) && count($fleetMetrics) > 1): ?>
<div class="report-section-card mb-4 p-3 rounded-3" style="border: 1px solid rgba(56, 189, 248, 0.2); background: rgba(15, 23, 42, 0.75);">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 pb-2" style="border-bottom: 1px solid var(--vl-border);">
        <div class="d-flex align-items-center gap-2">
            <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; background: rgba(56, 189, 248, 0.15); color: #38bdf8;">
                <i class="bi bi-hdd-stack-fill fs-6"></i>
            </div>
            <div>
                <h6 class="mb-0 fw-bold text-white d-flex align-items-center gap-2 flex-wrap">
                    Fleet Financial Breakdown (Today)
                    <span class="badge bg-info-subtle text-info border border-info border-opacity-25 rounded-pill" style="font-size: 0.7rem;">
                        <?= count($fleetMetrics) ?> Machines &bull; Total ₱<?= number_format($todayStats['total_revenue'], 2) ?>
                    </span>
                </h6>
                <span class="text-muted small">Individual earnings from each vending unit contributing to your main total.</span>
            </div>
        </div>
        <div>
            <a href="<?= BASE_URL ?>/dashboard/sales.php" class="btn btn-sm btn-outline-info text-nowrap">
                <i class="bi bi-funnel me-1"></i>Filter In Sales <i class="bi bi-arrow-right ms-1"></i>
            </a>
        </div>
    </div>

    <div class="row g-3">
        <?php foreach ($fleetMetrics as $fItem): 
            $fRev = (float)$fItem['period_revenue'];
            $fTx  = (int)$fItem['period_transactions'];
            $fShare = ($todayStats['total_revenue'] > 0) ? round(($fRev / $todayStats['total_revenue']) * 100, 1) : 0;
            $fActive = ($machineInfo && (int)$machineInfo['id'] === (int)$fItem['id']);
            $fStat = $fItem['status'] ?? 'offline';
            $fDot = ($fStat === 'online') ? 'status-online' : 'status-offline';
        ?>
        <div class="col-12 col-md-6 col-xl-<?= count($fleetMetrics) <= 2 ? '6' : (count($fleetMetrics) <= 3 ? '4' : '3') ?>">
            <div class="p-3 rounded-3 h-100 d-flex flex-column justify-content-between position-relative" 
                 style="background: rgba(15, 23, 42, 0.6); border: 1px solid <?= $fActive ? 'rgba(56, 189, 248, 0.45)' : 'var(--vl-border)' ?>; <?= $fActive ? 'box-shadow: 0 0 15px rgba(56, 189, 248, 0.12);' : '' ?>">
                
                <div>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="d-flex align-items-center gap-2 min-w-0">
                            <span class="status-dot <?= $fDot ?>"></span>
                            <strong class="text-white text-truncate" style="font-size: 0.92rem;"><?= e($fItem['name']) ?></strong>
                            <?php if ($fActive): ?>
                                <span class="badge bg-primary-subtle text-primary border border-primary border-opacity-25" style="font-size: 0.65rem;">Active</span>
                            <?php endif; ?>
                        </div>
                        <span class="badge bg-dark border border-secondary text-muted font-monospace" style="font-size: 0.7rem;">
                            <?= e($fItem['machine_code'] ?: 'Unit') ?>
                        </span>
                    </div>

                    <div class="d-flex align-items-baseline justify-content-between my-2">
                        <div>
                            <span class="text-muted small d-block" style="font-size: 0.72rem;">Today's Revenue</span>
                            <h4 class="mb-0 fw-bold <?= $fRev > 0 ? 'text-success' : 'text-light' ?>">
                                ₱<?= number_format($fRev, 2) ?>
                            </h4>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-success-subtle text-success border border-success border-opacity-25" style="font-size: 0.75rem;">
                                <?= $fTx ?> tx
                            </span>
                            <span class="text-muted small d-block mt-0.5" style="font-size: 0.7rem;"><?= $fShare ?>% of total</span>
                        </div>
                    </div>

                    <div class="progress bg-dark mb-3" style="height: 5px;">
                        <div class="progress-bar bg-success" role="progressbar" style="width: <?= $fShare ?>%;" aria-valuenow="<?= $fShare ?>" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </div>

                <div class="d-flex align-items-center justify-content-between pt-2 border-top border-secondary border-opacity-25 mt-auto">
                    <a href="<?= BASE_URL ?>/dashboard/sales.php?machine=<?= (int)$fItem['id'] ?>" class="btn btn-sm btn-link text-decoration-none text-info p-0" style="font-size: 0.75rem;">
                        <i class="bi bi-graph-up me-1"></i>View Machine Sales
                    </a>
                    <?php if (!$fActive): ?>
                    <a href="<?= BASE_URL ?>/dashboard/switch_machine.php?id=<?= (int)$fItem['id'] ?>&return_to=<?= urlencode(BASE_URL . '/dashboard/index.php') ?>" 
                       class="btn btn-sm btn-outline-secondary py-0.5 px-2 text-light" style="font-size: 0.72rem;">
                        Switch Context
                    </a>
                    <?php else: ?>
                    <span class="text-muted small" style="font-size: 0.72rem;">Selected in Topnav</span>
                    <?php endif; ?>
                </div>

            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     3.5 VendoAI Insights — Smart Predictions & Daily Tips
     ============================================================ -->
<?php if (isset($aiInsights) && is_array($aiInsights)): 
    $currentHour  = (int) date('G');
    $isNight      = ($currentHour < 6 || $currentHour >= 18);
    $weatherIcon  = $isNight ? 'bi-moon-stars-fill' : 'bi-sun-fill';
    $weatherColor = $isNight ? '#93c5fd' : '#f59e0b';
    $weatherText  = $isNight ? 'Clear 27°C' : 'Sunny 32°C';
    $tipsList     = !empty($aiInsights['priority_tips']) ? $aiInsights['priority_tips'] : [];
?>
<div class="report-section-card vendoai-card mb-4 p-3 p-md-4" id="vendoAiInsightsWidget">
    
    <!-- Top Header -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 pb-2 border-bottom border-secondary border-opacity-10">
        <div>
            <h4 class="mb-1 fw-bold text-white fs-5 d-flex align-items-center gap-2">
                <span class="vendoai-robot-badge">🤖</span>
                <?= term('vendoai_insights', 'VendoAI Insights') ?>
            </h4>
            <span class="text-muted small">Smart predictions &amp; daily tips by Google Gemini 3.8</span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge vendoai-weather-badge">
                <i class="bi <?= $weatherIcon ?> me-1" style="color: <?= $weatherColor ?>;"></i> <?= $weatherText ?>
            </span>
        </div>
    </div>

    <!-- 4 Hero Metric Cards (2x2 on Mobile, 4x1 on Desktop) -->
    <div class="row g-3 mb-3">
        <!-- Card 1: Monthly Sales -->
        <div class="col-6 col-xl-3">
            <div class="vendoai-metric-box h-100">
                <div class="d-flex align-items-center gap-2 mb-1.5">
                    <i class="bi bi-graph-up-arrow" style="color: #22c55e;"></i>
                    <span class="vendoai-metric-label"><?= term('estimated_monthly_sales', 'Estimated Monthly Sales') ?></span>
                </div>
                <?php if (empty($aiInsights['has_sales'])): ?>
                    <div class="vendoai-metric-value text-success">
                        ₱0.00
                    </div>
                    <div class="vendoai-metric-subtext">
                        ● Standby &bull; Awaiting initial vend
                    </div>
                    <div class="vendoai-metric-footer">
                        <span class="badge bg-secondary-subtle text-light border border-secondary border-opacity-25" style="font-size: 0.7rem;">Standby Mode</span>
                    </div>
                <?php else: ?>
                    <div class="vendoai-metric-value text-success">
                        ₱<?= number_format((float)($aiInsights['predicted_monthly_rev'] ?? 0), 2) ?>
                    </div>
                    <div class="vendoai-metric-subtext">
                        <span class="text-success fw-semibold"><i class="bi bi-arrow-up-short"></i>Growing</span> &bull; ₱<?= number_format((float)($aiInsights['avg_daily_revenue'] ?? 0), 2) ?>/day
                    </div>
                    <div class="vendoai-metric-footer">
                        <span class="badge bg-success-subtle text-success border border-success border-opacity-25" style="font-size: 0.7rem;">Normal Track</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Card 2: Water Supply -->
        <div class="col-6 col-xl-3">
            <div class="vendoai-metric-box h-100">
                <div class="d-flex align-items-center gap-2 mb-1.5">
                    <i class="bi bi-droplet-half" style="color: #38bdf8;"></i>
                    <span class="vendoai-metric-label"><?= term('water_refill_estimate', 'Water Refill Estimate') ?></span>
                </div>
                <?php if (!empty($aiInsights['water_tank_low'])): ?>
                    <div class="vendoai-metric-value text-danger">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>10L Reserve
                    </div>
                    <div class="vendoai-metric-subtext text-danger">
                        <i class="bi bi-exclamation-circle-fill me-1"></i>Check Water Supply &bull; ~<?= (int)($aiInsights['water_reserve_washes'] ?? 10) ?> washes left
                    </div>
                    <div class="vendoai-metric-footer">
                        <span class="badge bg-danger-subtle text-danger border border-danger border-opacity-25" style="font-size: 0.7rem;">Supply Alert</span>
                    </div>
                <?php else: ?>
                    <div class="vendoai-metric-value" style="color: #38bdf8;">
                        Auto-Supply
                    </div>
                    <div class="vendoai-metric-subtext">
                        ● Auto-Valve Active &bull; 100L Drum
                    </div>
                    <div class="vendoai-metric-footer">
                        <span class="badge bg-info-subtle text-info border border-info border-opacity-25" style="font-size: 0.7rem;">Auto-Supply OK</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Card 3: Soap Supply -->
        <div class="col-6 col-xl-3">
            <div class="vendoai-metric-box h-100">
                <div class="d-flex align-items-center gap-2 mb-1.5">
                    <i class="bi bi-funnel-fill" style="color: #c084fc;"></i>
                    <span class="vendoai-metric-label"><?= term('soap_refill_estimate', 'Soap Refill Estimate') ?></span>
                </div>
                <?php if (!empty($aiInsights['soap_tank_low'])): ?>
                    <div class="vendoai-metric-value text-warning">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>2L Reserve
                    </div>
                    <div class="vendoai-metric-subtext text-warning">
                        <i class="bi bi-exclamation-circle-fill me-1"></i>Refill Soon &bull; ~<?= (int)($aiInsights['soap_reserve_washes'] ?? 20) ?> washes left
                    </div>
                    <div class="vendoai-metric-footer">
                        <span class="badge bg-warning-subtle text-warning border border-warning border-opacity-25" style="font-size: 0.7rem;">10% Low Reserve</span>
                    </div>
                <?php elseif (empty($aiInsights['has_sales'])): ?>
                    <div class="vendoai-metric-value" style="color: #c084fc;">
                        20L Full
                    </div>
                    <div class="vendoai-metric-subtext">
                        ● ~<?= number_format((int)($aiInsights['soap_total_washes'] ?? 200)) ?> washes ready &bull; Standby
                    </div>
                    <div class="vendoai-metric-footer">
                        <span class="badge bg-info-subtle text-info border border-info border-opacity-25" style="font-size: 0.7rem;">10% Reserve Armed</span>
                    </div>
                <?php else: ?>
                    <div class="vendoai-metric-value" style="color: #c084fc;">
                        ~<?= (int)($aiInsights['days_until_soap_refill'] ?? 0) ?> Day(s)
                    </div>
                    <div class="vendoai-metric-subtext">
                        ● <?= number_format((int)($aiInsights['soap_cycles_left'] ?? 0)) ?> washes left
                    </div>
                    <div class="vendoai-metric-footer">
                        <span class="badge bg-info-subtle text-info border border-info border-opacity-25" style="font-size: 0.7rem;">20L Capacity</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Card 4: Peak Usage Window -->
        <div class="col-6 col-xl-3">
            <div class="vendoai-metric-box h-100">
                <div class="d-flex align-items-center gap-2 mb-1.5">
                    <i class="bi bi-clock-history" style="color: #38bdf8;"></i>
                    <span class="vendoai-metric-label"><?= term('peak_usage_time', 'Peak Usage Time') ?></span>
                </div>
                <?php if (empty($aiInsights['has_sales']) || ((int)($aiInsights['total_tx'] ?? 0) < 5)): ?>
                    <div class="vendoai-metric-value text-white" style="font-size: 1.15rem;">
                        Calibrating...
                    </div>
                    <div class="vendoai-metric-subtext">
                        ● AI learning traffic patterns
                    </div>
                    <div class="vendoai-metric-footer">
                        <span class="badge bg-primary-subtle text-primary border border-primary border-opacity-25" style="font-size: 0.7rem;">Needs 5+ Vends</span>
                    </div>
                <?php else: ?>
                    <div class="vendoai-metric-value text-white">
                        <?= e($aiInsights['peak_hour']) ?>
                    </div>
                    <div class="vendoai-metric-subtext">
                        ● Highest customer traffic window
                    </div>
                    <div class="vendoai-metric-footer">
                        <span class="badge bg-primary-subtle text-primary border border-primary border-opacity-25" style="font-size: 0.7rem;">Peak Window</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Priority Action Slider [ < 1 of 3 > ] -->
    <div class="vendoai-priority-card p-3 rounded-3 mt-2">
        <div class="d-flex align-items-center justify-content-between flex-nowrap gap-2 mb-2">
            <div class="d-flex align-items-center gap-2 min-w-0">
                <i class="bi bi-lightbulb-fill text-warning fs-5 flex-shrink-0"></i>
                <span class="fw-bold text-white fs-6 text-truncate">VendoAI Priority Action</span>
            </div>
            <div class="d-flex align-items-center gap-1 flex-shrink-0" style="white-space: nowrap;">
                <button type="button" class="btn btn-sm btn-outline-secondary py-0.5 px-2 text-light" id="vendoaiTipPrev" title="Previous tip" style="font-size: 0.75rem;">
                    <i class="bi bi-chevron-left"></i>
                </button>
                <span class="text-muted small fw-semibold px-1 text-nowrap" id="vendoaiTipCounter" style="font-size: 0.75rem; white-space: nowrap; min-width: 44px; text-align: center; display: inline-block;">1 of <?= count($tipsList) ?: 3 ?></span>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0.5 px-2 text-light" id="vendoaiTipNext" title="Next tip" style="font-size: 0.75rem;">
                    <i class="bi bi-chevron-right"></i>
                </button>
            </div>
        </div>

        <div id="vendoaiTipsContainer">
            <?php foreach ($tipsList as $idx => $tip): ?>
            <div class="vendoai-tip-slide <?= $idx === 0 ? '' : 'd-none' ?>" data-index="<?= $idx ?>">
                <p class="text-light mb-2" style="font-size: 0.9rem; line-height: 1.5;">
                    <?= e($tip['text']) ?>
                </p>
                <div class="text-muted small d-flex align-items-center gap-2 flex-wrap" style="font-size: 0.75rem;">
                    <span><i class="bi bi-tag me-1 text-info"></i>Category: <strong class="text-info"><?= e($tip['category']) ?></strong></span>
                    <span>&bull;</span>
                    <span>Updated: <?= e($tip['updated']) ?> (Next check in 3 hrs)</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<script>
(function() {
    document.addEventListener('DOMContentLoaded', function() {
        const prevBtn = document.getElementById('vendoaiTipPrev');
        const nextBtn = document.getElementById('vendoaiTipNext');
        const counter = document.getElementById('vendoaiTipCounter');
        const slides = document.querySelectorAll('.vendoai-tip-slide');
        
        if (!slides.length || !prevBtn || !nextBtn) return;
        
        let currentIdx = 0;
        const total = slides.length;

        function showSlide(idx) {
            slides.forEach((slide, i) => {
                if (i === idx) {
                    slide.classList.remove('d-none');
                } else {
                    slide.classList.add('d-none');
                }
            });
            if (counter) {
                counter.textContent = (idx + 1) + ' of ' + total;
            }
        }

        prevBtn.addEventListener('click', function() {
            currentIdx = (currentIdx - 1 + total) % total;
            showSlide(currentIdx);
        });

        nextBtn.addEventListener('click', function() {
            currentIdx = (currentIdx + 1) % total;
            showSlide(currentIdx);
        });
    });
})();
</script>
<?php endif; ?>

<!-- ============================================================
     4. Service Performance (Hidden for now) + Machine & Controller Status
     ============================================================ -->
<?php if (false): /* Service Performance (Hidden for now) */ ?>
<div class="vl-panel-grid mb-4">
    <div class="vl-panel-col">
        <div class="report-section-card h-100">
            <div class="breakdown-header">
                <div>
                    <h5 class="panel-title mb-1">
                        <i class="bi bi-pie-chart me-2 text-primary-vl"></i>Service Performance (Today)
                    </h5>
                    <p class="text-muted small mb-0">
                        Comparison of customer utilization between Water and Soap services.
                    </p>
                </div>
                <span class="vl-badge-machine font-monospace">
                    <?= number_format($todayStats['total_transactions']) ?> Tx
                </span>
            </div>

            <!-- Water Performance -->
            <div class="p-3 mb-3 rounded" style="background: rgba(15,23,42,.6); border: 1px solid rgba(56,189,248,.2);">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <div class="stat-card-icon" style="background: rgba(56,189,248,.15); color: #38bdf8; width: 32px; height: 32px; font-size: 1rem;">
                            <i class="bi bi-droplet-fill"></i>
                        </div>
                        <div>
                            <strong class="text-light">Water Service</strong>
                            <span class="text-muted small ms-1">&bull; Current Rate: ₱<?= number_format((float)$waterService['coin_value'], 2) ?> / <?= Transaction::formatDuration((int)$waterService['service_seconds']) ?></span>
                        </div>
                    </div>
                    <span class="badge bg-info-subtle text-info border border-info">
                        <?= $todayStats['water_share'] ?>% of Sales
                    </span>
                </div>
                <div class="d-flex justify-content-between text-light small mb-2">
                    <span>Revenue: <strong class="text-info">₱<?= number_format($todayStats['water_revenue'], 2) ?></strong></span>
                    <span>Volume: <strong><?= number_format($todayStats['water_count']) ?> tx</strong></span>
                    <span>Purchased: <strong><?= e($todayStats['water_purchased_label']) ?></strong></span>
                </div>
                <div class="progress bg-dark" style="height: 6px;">
                    <div class="progress-bar bg-info" role="progressbar"
                         style="width: <?= $todayStats['water_share'] ?>%;"
                         aria-valuenow="<?= $todayStats['water_share'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
            </div>

            <!-- Soap Performance -->
            <div class="p-3 rounded" style="background: rgba(15,23,42,.6); border: 1px solid rgba(234,179,8,.2);">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <div class="stat-card-icon d-flex align-items-center justify-content-center" style="background: rgba(234,179,8,.15); color: #eab308; width: 32px; height: 32px; font-size: 1rem; border-radius: 8px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="9.5" cy="14.5" r="5.5" fill="rgba(234, 179, 8, 0.25)" stroke="#eab308" stroke-width="1.8"/>
                                <path d="M8 12.5a2 2 0 0 1 2.2-1.2" stroke="#ffffff" stroke-width="1.5" stroke-linecap="round"/>
                                <circle cx="16.5" cy="8.5" r="4" fill="rgba(234, 179, 8, 0.25)" stroke="#eab308" stroke-width="1.8"/>
                                <circle cx="6" cy="6" r="2.5" fill="rgba(234, 179, 8, 0.2)" stroke="#eab308" stroke-width="1.5"/>
                            </svg>
                        </div>
                        <div>
                            <strong class="text-light">Soap Service</strong>
                            <span class="text-muted small ms-1">&bull; Current Rate: ₱<?= number_format((float)$soapService['coin_value'], 2) ?> / <?= Transaction::formatDuration((int)$soapService['service_seconds']) ?></span>
                        </div>
                    </div>
                    <span class="badge bg-warning-subtle text-warning border border-warning">
                        <?= $todayStats['soap_share'] ?>% of Sales
                    </span>
                </div>
                <div class="d-flex justify-content-between text-light small mb-2">
                    <span>Revenue: <strong class="text-warning">₱<?= number_format($todayStats['soap_revenue'], 2) ?></strong></span>
                    <span>Volume: <strong><?= number_format($todayStats['soap_count']) ?> tx</strong></span>
                    <span>Purchased: <strong><?= e($todayStats['soap_purchased_label']) ?></strong></span>
                </div>
                <div class="progress bg-dark" style="height: 6px;">
                    <div class="progress-bar bg-warning" role="progressbar"
                         style="width: <?= $todayStats['soap_share'] ?>%;"
                         aria-valuenow="<?= $todayStats['soap_share'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Machine & Controller Status -->
<div class="report-section-card mb-4 d-flex flex-column">
    <div class="breakdown-header">
        <div>
            <h5 class="panel-title mb-1">
                <i class="bi bi-hdd-network me-2 text-primary-vl"></i>Machine &amp; Controller Status
            </h5>
            <p class="text-muted small mb-0">
                <?= e($machineInfo['name'] ?? 'VendoLink Machine Unit') ?> &bull; <?= e($machineInfo['machine_code'] ?? 'Hardware Unit') ?>
            </p>
        </div>
        <span class="vl-badge-machine font-monospace">
            <i class="bi bi-cpu me-1"></i>VendoCore ESP32
        </span>
    </div>

        <?php
        $mStatus = $machineInfo['calculated_status'] ?? 'offline';
        ?>
        <?php if ($mStatus === 'online'): ?>
        <div class="runtime-notice-box mb-3 d-flex align-items-start gap-3 p-3 rounded-3" style="background: rgba(34,197,94,.12) !important; border: 1px solid rgba(34,197,94,.35) !important;">
            <i class="bi bi-wifi text-success fs-3 flex-shrink-0 mt-1"></i>
            <div>
                <strong class="text-success d-block mb-1 fs-6">✓ ESP32 Connected &amp; Online</strong>
                <div class="text-light small">
                    <span>IP: <strong class="font-monospace text-cyan" style="color: #38bdf8;"><?= e($machineInfo['ip_address'] ?? '192.168.x.x') ?></strong></span> &bull;
                    <span>Firmware: <strong class="font-monospace text-light">v<?= e($machineInfo['firmware_version'] ?? '1.0.0') ?></strong></span> &bull;
                    <span><?= term('last_seen') ?>: <strong class="text-white"><?= time_ago($machineInfo['last_seen']) ?></strong></span>
                </div>
            </div>
        </div>
        <?php elseif ($mStatus === 'stale'): ?>
        <div class="runtime-notice-box mb-3 d-flex align-items-start gap-3 p-3 rounded-3" style="background: rgba(245,158,11,.12) !important; border: 1px solid rgba(245,158,11,.35) !important;">
            <i class="bi bi-exclamation-triangle-fill text-warning fs-3 flex-shrink-0 mt-1"></i>
            <div>
                <strong class="text-warning d-block mb-1 fs-6">⚠️ ESP32 Connection Stale</strong>
                <div class="text-light small">
                    <?= term('last_seen') ?> <strong class="text-white"><?= time_ago($machineInfo['last_seen']) ?></strong>. Connection may be intermittent.
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="runtime-notice-box mb-3 d-flex align-items-start gap-3 p-3 rounded-3" style="background: rgba(15,23,42,.6) !important; border: 1px solid var(--vl-border) !important;">
            <i class="bi bi-broadcast text-info fs-4 flex-shrink-0 mt-1"></i>
            <div>
                <strong class="text-light d-block mb-1 fs-6">Awaiting ESP32 Connection</strong>
                <p class="text-muted small mb-0">
                    The physical ESP32 microcontroller is not currently sending active heartbeats to the local server.
                    <?= !empty($machineInfo['last_seen']) ? term('last_seen') . ': ' . time_ago($machineInfo['last_seen']) : 'No heartbeats recorded yet.' ?>
                </p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Live Service Activity Rows -->
        <div class="row g-2 mb-3">
            <div class="col-6">
                <div class="p-2 rounded" style="background: rgba(15,23,42,.6); border: 1px solid var(--vl-border);">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small"><i class="bi <?= $s1Icon ?> text-info me-1"></i><?= e($s1Title) ?></span>
                        <span class="badge bg-dark border border-secondary text-info">
                            <i class="bi bi-circle-fill me-1" style="font-size: 6px;"></i>IDLE
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="p-2 rounded" style="background: rgba(15,23,42,.6); border: 1px solid var(--vl-border);">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small"><i class="bi <?= $s2Icon ?> text-warning me-1"></i><?= e($s2Title) ?></span>
                        <span class="badge bg-dark border border-secondary text-warning">
                            <i class="bi bi-circle-fill me-1" style="font-size: 6px;"></i>IDLE
                        </span>
                    </div>
                </div>
            </div>
            <?php
            // Coin slot power logic
            $waterSvc        = $servicesByKey['water'] ?? null;
            $soapSvc         = $servicesByKey['soap'] ?? null;
            $waterEnabled    = !empty($waterSvc['is_enabled']);
            $soapEnabled     = !empty($soapSvc['is_enabled']);
            $waterTankLow    = !empty($machineInfo['water_tank_low']);
            if ($activeMachType === 'carwash') {
                $coinSlotOn  = $waterEnabled && !$waterTankLow;
            } else {
                $coinSlotOn  = ($waterEnabled || $soapEnabled);
            }
            ?>
            <div class="col-12">
                <div class="p-2 rounded" style="background: rgba(15,23,42,.6); border: 1px solid var(--vl-border);">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small">
                            <i class="bi bi-plug-fill me-1" style="color: <?= $coinSlotOn ? '#22c55e' : '#ef4444'; ?>;"></i>
                            Coin Slot (GPIO 14)
                        </span>
                        <?php if ($coinSlotOn): ?>
                        <span class="badge" style="background: rgba(34,197,94,.15); color: #22c55e; border: 1px solid rgba(34,197,94,.4);">
                            <i class="bi bi-circle-fill me-1" style="font-size: 6px;"></i>🟢 Powered
                        </span>
                        <?php else: ?>
                        <span class="badge" style="background: rgba(239,68,68,.15); color: #ef4444; border: 1px solid rgba(239,68,68,.4);">
                            <i class="bi bi-circle-fill me-1" style="font-size: 6px;"></i>🔴 Disabled
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="text-muted mt-1" style="font-size: 0.72rem;">
                        <?php if ($coinSlotOn): ?>
                            HW-482 relay de-energized — NC closed — 12V connected
                        <?php elseif ($activeMachType === 'carwash' && $waterTankLow): ?>
                            Water tank empty — relay energized — 12V cut off
                        <?php else: ?>
                            Services disabled — relay energized — 12V cut off
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Runtime Comparison -->
        <div class="mt-auto pt-2 d-flex justify-content-between align-items-center flex-wrap gap-2"
             style="border-top: 1px solid var(--vl-border);">
            <div>
                <span class="text-muted small d-block">Today's Hardware Runtime:</span>
                <span class="vl-badge-machine font-monospace">
                    Unavailable (Awaiting ESP32)
                </span>
            </div>
            <a href="<?= BASE_URL ?>/dashboard/machines.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-hdd-stack me-1"></i><?= term('view_machine', 'View Machine') ?>
            </a>
        </div>
</div>

<!-- ============================================================
     5. Transaction Records — Full Width (5-Item Quick Preview)
     ============================================================ -->
<div class="report-section-card dashboard-recent-tx-card mb-4">
    <div class="breakdown-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="me-auto">
            <h5 class="panel-title mb-1 text-white">
                <i class="bi bi-receipt me-2 text-primary-vl"></i>Transaction Records
            </h5>
            <p class="text-muted small mb-0">
                Latest coin-operated service purchases logged in system.
            </p>
        </div>
        <a href="<?= BASE_URL ?>/dashboard/archive.php" class="btn btn-sm btn-outline-primary text-nowrap d-inline-flex align-items-center">
            <?= term('view_more', 'View More') ?>
        </a>
    </div>

    <?php
    $previewTxs = array_slice($recentTxs ?? [], 0, 5);
    ?>

    <?php if (empty($previewTxs)): ?>
    <div class="activity-empty py-3">
        <i class="bi bi-receipt" style="font-size: 1.8rem; color: var(--vl-text-muted);"></i>
        <p class="text-muted mt-2 mb-0 small">No transactions recorded yet.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0" style="--bs-table-bg: transparent;">
            <thead>
                <tr style="border-bottom: 1px solid var(--vl-border); color: var(--vl-text-muted); font-size: .75rem; text-transform: uppercase; letter-spacing: .06em;">
                    <th class="ps-2">ID</th>
                    <th>Service</th>
                    <th>Amount</th>
                    <th>Purchased Time</th>
                    <th>Status</th>
                    <th class="pe-2 text-end">Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($previewTxs as $tx):
                    $txSvcName = !empty($tx['service_name']) ? preg_replace('/\s+Service$/i', '', $tx['service_name']) : '';
                    if (empty($txSvcName)) {
                        $txSvcName = match (strtolower($tx['service_key'] ?? '')) {
                            'water' => 'Water',
                            'soap'  => 'Soap',
                            default => 'Service',
                        };
                    }
                    $isWater = ($tx['service_key'] === 'water');
                    $txIcon = 'bi-stars text-warning';
                    if (stripos($txSvcName, 'Air') !== false) {
                        $txIcon = 'bi-speedometer2 text-info';
                    } elseif (stripos($txSvcName, 'Blower') !== false) {
                        $txIcon = 'bi-wind text-warning';
                    } elseif ($isWater) {
                        $txIcon = 'bi-droplet-fill text-info';
                    }
                    $serviceDisplayName = $txSvcName;
                    $statusLabel = match (strtolower($tx['status'] ?? '')) {
                        'completed'   => 'Completed',
                        'incomplete'  => 'Incomplete',
                        'interrupted' => 'Interrupted',
                        'failed'      => 'Unsuccessful',
                        'active'      => 'Active',
                        'pending'     => 'Pending',
                        'cancelled'   => 'Cancelled',
                        default       => ucfirst(strtolower($tx['status'] ?? '')),
                    };
                ?>
                <tr style="border-bottom: 1px solid rgba(255,255,255,.04); font-size: .84rem;">
                    <td class="ps-2">
                        <span class="tx-id text-white fw-bold"><?= e($tx['formatted_id']) ?></span>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-1.5">
                            <i class="bi <?= $txIcon ?>"></i>
                            <span class="text-light fw-medium"><?= e($serviceDisplayName) ?></span>
                        </div>
                    </td>
                    <td>
                        <strong class="text-success"><?= e($tx['amount_formatted']) ?></strong>
                    </td>
                    <td>
                        <span class="text-light fw-medium">
                            <i class="bi bi-clock-history me-1 text-primary-vl"></i><?= e($tx['purchased_time_label']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="tx-badge tx-badge-<?= e($tx['status']) ?>">
                            <?= e($statusLabel) ?>
                        </span>
                    </td>
                    <td class="pe-2 text-end">
                        <div class="text-light fw-medium small"><?= format_date($tx['created_at'], 'M j, Y, g:i A') ?></div>
                        <div class="text-muted" style="font-size: .72rem;"><?= time_ago($tx['created_at']) ?></div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ============================================================
     6. Recent Business & System Activity — Full Width (5-Item Quick Preview)
     ============================================================ -->
<div class="report-section-card dashboard-recent-activity-card mb-4">
    <div class="breakdown-header">
        <div>
            <h5 class="panel-title mb-1">
                <i class="bi bi-activity me-2 text-primary-vl"></i><?= term('recent_activity') ?>
            </h5>
            <p class="text-muted small mb-0">
                Audit feed of system authentications, service configurations, and operations.
            </p>
        </div>
        <a href="<?= BASE_URL ?>/dashboard/archive.php?tab=transactions" class="btn btn-sm btn-outline-primary text-nowrap d-inline-flex align-items-center">
            <?= term('view_more', 'View More') ?>
        </a>
    </div>
    <div class="p-0 activity-container" style="margin: 0 -1.35rem -1.25rem;">
        <?php
        try {
            $db      = Database::getInstance();
            $ownerId = current_owner_id();
            if ($ownerId !== null && $ownerId > 0) {
                $logs = $db->query(
                    'SELECT al.*, u.full_name, u.role
                     FROM activity_logs al
                     LEFT JOIN users u ON al.user_id = u.id
                     WHERE al.user_id = :uid
                     ORDER BY al.created_at DESC, al.id DESC
                     LIMIT 5',
                    [':uid' => $ownerId]
                )->fetchAll();
            } else {
                $logs = $db->query(
                    'SELECT al.*, u.full_name, u.role
                     FROM activity_logs al
                     LEFT JOIN users u ON al.user_id = u.id
                     ORDER BY al.created_at DESC, al.id DESC
                     LIMIT 5'
                )->fetchAll();
            }
        } catch (PDOException $e) {
            $logs = [];
        }

        $previewLogs = array_slice($logs ?? [], 0, 5);

        if (empty($previewLogs)): ?>
        <div class="activity-empty py-3">
            <i class="bi bi-clock-history" style="font-size: 1.8rem; color: var(--vl-text-muted);"></i>
            <p class="text-muted mt-2 mb-0 small">No activity recorded yet.</p>
        </div>
        <?php else: ?>
        <div class="activity-list">
            <?php foreach ($previewLogs as $log):
                $action = strtoupper($log['action']);
                $actionIcon = match ($action) {
                    'LOGIN', 'AUTO_LOGIN'                     => ['bi-box-arrow-in-right', 'activity-icon-success', 'USER LOGIN'],
                    'LOGOUT'                                  => ['bi-box-arrow-left',     'activity-icon-secondary', 'LOGOUT'],
                    'LOGIN_FAILED'                            => ['bi-x-circle',           'activity-icon-danger', 'AUTH FAILED'],
                    'CONFIG_UPDATE', 'SERVICE_CONFIG_UPDATED' => ['bi-sliders',            'activity-icon-info', 'CONFIG UPDATE'],
                    'MAINTENANCE_COMPLETED'                   => ['bi-check2-circle',      'activity-icon-success', 'MAINTENANCE'],
                    'TX_RECORD', 'SERVICE'                    => ['bi-receipt',            'activity-icon-info', 'TRANSACTION'],
                    'PASSWORD_RESET'                          => ['bi-key',                'activity-icon-warning', 'SECURITY'],
                    'SETUP'                                   => ['bi-rocket-takeoff',     'activity-icon-info', 'SETUP'],
                    default                                   => ['bi-activity',           'activity-icon-secondary', 'SYSTEM'],
                };
            ?>
            <div class="activity-item">
                <div class="activity-icon <?= $actionIcon[1] ?>">
                    <i class="bi <?= $actionIcon[0] ?>"></i>
                </div>
                <div class="activity-content">
                    <div class="activity-text">
                        <strong class="text-light"><?= e($log['full_name'] ?? 'System') ?></strong>
                        <span class="text-secondary">&mdash; <?= e($log['description'] ?? $log['action']) ?></span>
                    </div>
                    <div class="activity-meta">
                        <span class="activity-time">
                            <i class="bi bi-clock me-1"></i>
                            <?= time_ago($log['created_at']) ?>
                        </span>
                        <?php if (!empty($log['ip_address'])): ?>
                        <span class="activity-ip">
                            <i class="bi bi-geo me-1"></i>
                            <?= e($log['ip_address']) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="activity-action-label">
                    <span class="action-tag"><?= e($actionIcon[2]) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php
            $emptySlots = max(0, 5 - count($previewLogs));
            for ($i = 0; $i < $emptySlots; $i++):
            ?>
            <div class="activity-item-spacer" aria-hidden="true"></div>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
