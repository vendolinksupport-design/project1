<?php
/**
 * ============================================================
 * VendoLink — VendoAI Smart Assistant & Predictive Insights
 * ============================================================
 *
 * Protected route — requires authentication.
 * Provides machine learning-based sales forecasting, predictive tank
 * maintenance alerts, peak traffic analysis, and interactive AI assistant.
 *
 * @package VendoLink
 * @version 2.5
 */

// ---- Bootstrap application ----
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/AiAssistant.php';

// ---- Guard: must be logged in ----
require_auth();

$ai = new AiAssistant();
$insights = $ai->generateInsights();

// Handle Interactive Chat Prompt POST
$aiResponse = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['prompt'])) {
    $aiResponse = $ai->ask($_POST['prompt']);
}

$pageTitle   = term('vendoai_insights', 'VendoAI Insights');
$currentPage = 'ai';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= APP_NAME ?> — VendoAI Insights">
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
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚡</text></svg>">
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

            <!-- Page Header -->
            <div class="page-header mb-4">
                <div>
                    <h1 class="page-title">
                        <i class="bi bi-robot me-2" style="color: var(--vl-cyan);"></i><?= term('vendoai_insights', 'VendoAI Insights') ?>
                    </h1>
                    <p class="page-subtitle">
                        AI-generated predictions for sales revenue, tank refills, and customer traffic.
                    </p>
                </div>
                <div class="page-header-actions">
                    <span class="badge bg-info-subtle text-info border border-info border-opacity-25 px-3 py-2 fw-semibold">
                        <i class="bi bi-cpu-fill me-1"></i> VendoAI Active
                    </span>
                </div>
            </div>

            <!-- KPI Predictive Summary Cards (4 Equal Columns) -->
            <div class="row g-3 mb-4">

                <!-- Estimated Monthly Sales -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card stat-card-accent-success">
                        <div class="stat-card-body">
                            <div class="stat-card-info">
                                <p class="stat-card-label"><?= term('estimated_monthly_sales', 'Estimated Monthly Sales') ?></p>
                                <h3 class="stat-card-value text-success">₱<?= number_format($insights['predicted_monthly_rev'], 2) ?></h3>
                                <p class="stat-card-trend">
                                    <span class="text-muted small">Prediction &bull; ₱<?= number_format($insights['avg_daily_revenue'], 2) ?>/day avg</span>
                                </p>
                            </div>
                            <div class="stat-card-icon" style="background: rgba(34, 197, 94, 0.15); color: #4ade80;">
                                <i class="bi bi-graph-up-arrow"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Peak Usage Time -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card stat-card-accent-info">
                        <div class="stat-card-body">
                            <div class="stat-card-info">
                                <p class="stat-card-label"><?= term('peak_usage_time', 'Peak Usage Time') ?></p>
                                <h3 class="stat-card-value text-info" style="font-size: 1.25rem;"><?= e($insights['peak_hour']) ?></h3>
                                <p class="stat-card-trend">
                                    <span class="text-muted small">Prediction &bull; Highest customer traffic</span>
                                </p>
                            </div>
                            <div class="stat-card-icon" style="background: rgba(56, 189, 248, 0.15); color: var(--vl-cyan);">
                                <i class="bi bi-clock-history"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Water Refill Estimate -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card stat-card-accent-warning">
                        <div class="stat-card-body">
                            <div class="stat-card-info">
                                <p class="stat-card-label"><?= term('water_refill_estimate', 'Water Refill Estimate') ?></p>
                                <h3 class="stat-card-value text-warning">~<?= $insights['days_until_water_refill'] ?> Day(s)</h3>
                                <p class="stat-card-trend">
                                    <span class="text-muted small">Prediction &bull; <?= $insights['water_cycles_left'] ?> vends remaining</span>
                                </p>
                            </div>
                            <div class="stat-card-icon" style="background: rgba(245, 158, 11, 0.15); color: #fbbf24;">
                                <i class="bi bi-droplet-half"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Soap Refill Estimate -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card stat-card-accent-primary">
                        <div class="stat-card-body">
                            <div class="stat-card-info">
                                <p class="stat-card-label"><?= term('soap_refill_estimate', 'Soap Refill Estimate') ?></p>
                                <h3 class="stat-card-value text-primary-vl">~<?= $insights['days_until_soap_refill'] ?> Day(s)</h3>
                                <p class="stat-card-trend">
                                    <span class="text-muted small">Prediction &bull; <?= $insights['soap_cycles_left'] ?> vends remaining</span>
                                </p>
                            </div>
                            <div class="stat-card-icon" style="background: rgba(14, 165, 233, 0.15); color: #38bdf8;">
                                <i class="bi bi-sparkles"></i>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- VendoAI Interactive Assistant Panel -->
            <div class="row g-4 mb-4">
                
                <!-- Left: AI Recommendations List -->
                <div class="col-12 col-lg-7">
                    <div class="report-section-card h-100">
                        <div class="breakdown-header border-bottom border-secondary border-opacity-25 pb-3 mb-3">
                            <div>
                                <h5 class="panel-title mb-1">
                                    <i class="bi bi-lightbulb-fill me-2 text-warning"></i>Smart Business Recommendations
                                </h5>
                                <p class="text-muted small mb-0">
                                    Automated telemetry suggestions to optimize machine uptime and profit margins.
                                </p>
                            </div>
                        </div>

                        <div class="d-flex flex-column gap-3 p-2">
                            <?php foreach ($insights['recommendations'] as $rec): ?>
                                <div class="p-3 rounded-3 d-flex align-items-start gap-3"
                                     style="background: var(--vl-surface-2); border: 1px solid var(--vl-border);">
                                    <div class="p-2 rounded-3 text-<?= $rec['type'] ?>" style="background: rgba(255,255,255,0.05);">
                                        <i class="bi bi-cpu fs-4"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                            <strong class="text-light"><?= e($rec['title']) ?></strong>
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25 small">
                                                <?= e($rec['category']) ?>
                                            </span>
                                        </div>
                                        <p class="text-muted small mb-2 line-height-md">
                                            <?= e($rec['description']) ?>
                                        </p>
                                        <a href="<?= $rec['action_link'] ?>" class="btn btn-sm btn-link text-cyan text-decoration-none p-0 small font-semibold">
                                            View Related Module <i class="bi bi-arrow-right ms-1"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Right: Interactive VendoAI Prompt Chatbot -->
                <div class="col-12 col-lg-5">
                    <div class="report-section-card h-100 d-flex flex-column">
                        <div class="breakdown-header border-bottom border-secondary border-opacity-25 pb-3 mb-3">
                            <div>
                                <h5 class="panel-title mb-1">
                                    <i class="bi bi-chat-square-text-fill me-2" style="color: var(--vl-cyan);"></i>Ask VendoAI Assistant
                                </h5>
                                <p class="text-muted small mb-0">
                                    Ask questions about sales, reservoir refills, or cash audit schedules.
                                </p>
                            </div>
                        </div>

                        <div class="flex-grow-1 d-flex flex-column justify-content-between p-2">

                            <!-- Response Display Box -->
                            <div class="p-3 rounded-3 mb-3" style="background: var(--vl-surface-2); border: 1px solid var(--vl-border); min-height: 180px;">
                                <?php if ($aiResponse): ?>
                                    <div class="badge bg-cyan bg-opacity-20 text-cyan border border-cyan border-opacity-25 mb-2">
                                        <?= e($aiResponse['category']) ?>
                                    </div>
                                    <div class="text-light small line-height-md whitespace-pre-line" style="white-space: pre-line;">
                                        <?= e($aiResponse['answer']) ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4 text-muted">
                                        <i class="bi bi-robot display-5 d-block mb-2 text-cyan"></i>
                                        <p class="small mb-0">Select a quick prompt below or type a question to consult VendoAI!</p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Quick Action Prompt Buttons -->
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="prompt" value="Predict next month sales revenue">
                                    <button type="submit" class="btn btn-xs btn-outline-info rounded-pill font-semibold small">
                                        📈 Predict Revenue
                                    </button>
                                </form>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="prompt" value="When should I refill tanks">
                                    <button type="submit" class="btn btn-xs btn-outline-warning rounded-pill font-semibold small">
                                        💧 Tank Refill Forecast
                                    </button>
                                </form>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="prompt" value="Recommend cash collection schedule">
                                    <button type="submit" class="btn btn-xs btn-outline-success rounded-pill font-semibold small">
                                        💵 Cash Audit Schedule
                                    </button>
                                </form>
                            </div>

                            <!-- Input Form -->
                            <form method="POST" action="" class="mt-auto">
                                <div class="input-group">
                                    <input type="text" name="prompt" class="form-control form-control-sm bg-dark text-light border-secondary border-opacity-25" placeholder="Ask VendoAI a question..." required>
                                    <button type="submit" class="btn btn-sm btn-info text-dark font-bold">
                                        <i class="bi bi-send-fill me-1"></i> Ask AI
                                    </button>
                                </div>
                            </form>

                        </div>
                    </div>
                </div>

            </div>

        </main>

        <!-- Page Footer -->
        <footer class="app-footer">
            <span><?= APP_NAME ?> v<?= APP_VERSION ?> &copy; <?= date('Y') ?></span>
            <span>Powered by VendoCore IoT Controller &amp; VendoAI Engine</span>
        </footer>

    </div><!-- /.main-content -->

</div><!-- /.app-shell -->

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- VendoLink JS -->
<script src="<?= ASSETS_URL ?>/js/app.js?v=<?= e((string) filemtime(ROOT_PATH . '/assets/js/app.js')) ?>"></script>
</body>
</html>
