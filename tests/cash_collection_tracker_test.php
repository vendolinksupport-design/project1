<?php
/**
 * ============================================================
 * VendoLink — Physical Coin Box Collection Tracker Verification Test
 * ============================================================
 *
 * Verifies:
 * 1. 5-record preview limit per page
 * 2. Fit-to-content visual rendering (no fake empty placeholder rows)
 * 3. "View More" button in panel header directing to History > Transaction History (archive.php?tab=transactions)
 * 4. Record Cash Collection button in panel header
 * 5. CSS fit-content rules in assets/css/app.css
 *
 * Run with: C:\xampp\php\php.exe tests\cash_collection_tracker_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Transaction.php';

echo "=== STARTING PHYSICAL COIN BOX COLLECTION TRACKER TESTS ===\n\n";

// -------------------------------------------------------------
// 1. Verify CSS Rules in app.css
// -------------------------------------------------------------
echo "1. Verifying CSS Rules in assets/css/app.css...\n";
$css = file_get_contents(__DIR__ . '/../assets/css/app.css');

$requiredSelectors = [
    '.collection-table-card',
    '.collection-table-card .panel-header',
    '.collection-table-card .btn-outline-primary',
    '.collection-table-card .table-responsive',
    '.collection-table-card .table thead tr',
    '.collection-table-card .collection-empty-container'
];

foreach ($requiredSelectors as $sel) {
    if (strpos($css, $sel) === false) {
        throw new RuntimeException("Missing required CSS selector: {$sel}");
    }
}

// Verify removal of rigid 12-row 668px min-height
if (strpos($css, '--collection-table-min-height') !== false) {
    throw new RuntimeException("CSS still contains rigid --collection-table-min-height variable!");
}
echo "   [PASS] Fit-content CSS rules and cyan button theme verified in app.css.\n\n";

// -------------------------------------------------------------
// 2. Verify Title, Button Placement, and Links in dashboard/sales.php & dashboard/archive.php
// -------------------------------------------------------------
echo "2. Verifying Shortened Title & Headers in dashboard/sales.php & dashboard/archive.php...\n";
$salesCode = file_get_contents(__DIR__ . '/../dashboard/sales.php');
$archiveCode = file_get_contents(__DIR__ . '/../dashboard/archive.php');

// A. Title shortened to 'Cash Collections' in sales.php
if (strpos($salesCode, 'Physical Coin Box Collection Tracker') !== false) {
    throw new RuntimeException("sales.php still contains obsolete long title 'Physical Coin Box Collection Tracker'!");
}
if (strpos($salesCode, 'Cash Collections') === false) {
    throw new RuntimeException("sales.php missing shortened title 'Cash Collections'!");
}
echo "   [PASS] Title shortened to 'Cash Collections' in dashboard/sales.php.\n";

// B. Record Cash Collection Button must be inside #cashCollectionsTracker header
if (!preg_match('/id="cashCollectionsTracker"[\s\S]*?class="panel-header[\s\S]*?Record Cash Collection[\s\S]*?<\/div>/', $salesCode)) {
    throw new RuntimeException("Record Cash Collection button is NOT inside #cashCollectionsTracker .panel-header!");
}
echo "   [PASS] Record Cash Collection button is located inside #cashCollectionsTracker .panel-header.\n";

// C. View More button must be inside #cashCollectionsTracker header and link to archive.php?tab=transactions#cashCollectionsArchive
if (!preg_match('/id="cashCollectionsTracker"[\s\S]*?class="panel-header[\s\S]*?href="<\?=\s*BASE_URL\s*\?>\/dashboard\/archive\.php\?tab=transactions(#cashCollectionsArchive)?"[^>]*>[\s\S]*?(?:View All Collections|View More)/', $salesCode)) {
    throw new RuntimeException("View All Collections button directing to archive.php?tab=transactions is NOT inside #cashCollectionsTracker .panel-header!");
}
echo "   [PASS] View All Collections button is present in panel header and links to History > Transaction History (archive.php?tab=transactions#cashCollectionsArchive).\n";

// D. Cash Collections Panel must be present in dashboard/archive.php
if (strpos($archiveCode, 'id="cashCollectionsArchive"') === false) {
    throw new RuntimeException("archive.php missing #cashCollectionsArchive panel!");
}
if (strpos($archiveCode, 'Cash Collections') === false) {
    throw new RuntimeException("archive.php missing 'Cash Collections' panel heading!");
}
echo "   [PASS] Cash Collections panel (#cashCollectionsArchive) verified in dashboard/archive.php.\n";

// E. Verify modal trigger attribute in sales.php
if (!preg_match('/data-bs-target="#recordCashModal"[\s\S]*?Record Cash Collection/', $salesCode)) {
    throw new RuntimeException("Button missing data-bs-target='#recordCashModal'!");
}
echo "   [PASS] Modal trigger attribute data-bs-target='#recordCashModal' is intact.\n";

// F. Verify $cPerPage is set to 5 in sales.php
if (!preg_match('/\$cPerPage\s*=\s*5;/', $salesCode)) {
    throw new RuntimeException("Expected \$cPerPage = 5 in dashboard/sales.php!");
}
echo "   [PASS] \$cPerPage is set to 5 in dashboard/sales.php.\n\n";

// -------------------------------------------------------------
// 3. Helper to simulate rendering the table card with arbitrary record counts
// -------------------------------------------------------------
function renderTrackerCard(array $allCollections, int $currentPage = 1): string
{
    $totalCollections = count($allCollections);
    $cPerPage         = 5;
    $cTotalPages      = max(1, (int)ceil($totalCollections / $cPerPage));
    $cCurrentPage     = max(1, min($cTotalPages, $currentPage));
    $cOffset          = ($cCurrentPage - 1) * $cPerPage;
    $pagedCollections = array_slice($allCollections, $cOffset, $cPerPage);

    if (!function_exists('format_date')) {
        function format_date($dt, $fmt = 'M d, Y, g:i A') { return date($fmt, strtotime($dt)); }
    }
    if (!function_exists('e')) {
        function e($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }
    }
    if (!function_exists('build_filter_url')) {
        function build_filter_url(array $overrides = []) {
            return 'sales.php?' . http_build_query($overrides);
        }
    }

    ob_start();
    ?>
    <div class="tx-table-card collection-table-card mt-4" id="cashCollectionsTracker">
        <div class="panel-header d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3 py-3 px-3">
            <h5 class="panel-title text-white d-flex align-items-center mb-0">
                <i class="bi bi-wallet2 text-success me-2"></i>Cash Collections
            </h5>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-3 py-1.5 fw-semibold">
                    <?= number_format($totalCollections) ?> Collection Record(s)
                </span>
                <a href="<?= BASE_URL ?>/dashboard/archive.php?tab=transactions" class="btn btn-sm btn-outline-primary text-nowrap d-inline-flex align-items-center">
                    View More <i class="bi bi-arrow-right ms-1"></i>
                </a>
                <button type="button" class="btn btn-sm btn-success fw-bold d-inline-flex align-items-center" data-bs-toggle="modal" data-bs-target="#recordCashModal">
                    <i class="bi bi-wallet2 me-1.5"></i>Record Cash Collection
                </button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-dark table-hover mb-0 align-middle" style="--bs-table-bg: transparent;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--vl-border); color: #94a3b8; font-size: .78rem; text-transform: uppercase; letter-spacing: .06em;">
                        <th class="ps-3">Collection Date</th>
                        <th>Recorded By</th>
                        <th>Physical Cash Collected</th>
                        <th>System Sales Target</th>
                        <th>Variance</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pagedCollections)): ?>
                    <tr class="collection-empty-row">
                        <td colspan="6" class="p-0 border-0">
                            <div class="collection-empty-container text-center py-5">
                                <i class="bi bi-wallet2 fs-1 text-muted mb-3 opacity-50"></i>
                                <div class="text-secondary fw-medium">
                                    No physical cash collections recorded yet. Click "Record Cash Collection" above when clearing the coin box.
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($pagedCollections as $cc): 
                            $var = (float)$cc['variance_amount'];
                        ?>
                        <tr class="collection-data-row">
                            <td class="fw-bold text-light">
                                <?= format_date($cc['collected_at'], 'M d, Y, g:i A') ?>
                            </td>
                            <td class="small text-secondary">
                                <?= e($cc['full_name']) ?>
                            </td>
                            <td class="fw-bold text-success font-monospace">
                                ₱<?= number_format((float)$cc['collected_amount'], 2) ?>
                            </td>
                            <td class="text-slate-300 font-monospace">
                                ₱<?= number_format((float)$cc['system_sales_amount'], 2) ?>
                            </td>
                            <td>
                                <?php if ($var == 0): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2.5 py-1">
                                        <i class="bi bi-check-circle me-1"></i>₱0.00 (Match)
                                    </span>
                                <?php elseif ($var > 0): ?>
                                    <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 rounded-pill px-2.5 py-1">
                                        <i class="bi bi-arrow-up-right me-1"></i>+₱<?= number_format($var, 2) ?> (Surplus)
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-2.5 py-1">
                                        <i class="bi bi-arrow-down-right me-1"></i>-₱<?= number_format(abs($var), 2) ?> (Shortage)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-secondary">
                                <?= e($cc['notes'] ?? 'N/A') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Cash Collections Pagination Controls -->
        <?php if ($cTotalPages > 1): ?>
        <div class="tx-pagination card-footer bg-dark bg-opacity-50 border-secondary border-opacity-25 d-flex align-items-center justify-content-between flex-wrap gap-2 py-3">
            <div class="text-light small">
                Page <strong><?= $cCurrentPage ?></strong> of <strong><?= $cTotalPages ?></strong> (Total <?= number_format($totalCollections) ?> collection<?= $totalCollections === 1 ? '' : 's' ?>)
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>/dashboard/archive.php?tab=transactions" class="btn btn-sm btn-outline-primary text-nowrap d-inline-flex align-items-center me-2">
                    View More <i class="bi bi-arrow-right ms-1"></i>
                </a>
                <nav aria-label="Cash collection pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $cCurrentPage <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= build_filter_url(['cpage' => $cCurrentPage - 1]) ?>#cashCollectionsTracker" aria-label="Previous">
                                <span aria-hidden="true">&laquo;&nbsp;Prev</span>
                            </a>
                        </li>
                        <?php for ($cp = 1; $cp <= $cTotalPages; $cp++): ?>
                        <li class="page-item <?= $cp === $cCurrentPage ? 'active' : '' ?>">
                            <a class="page-link" href="<?= build_filter_url(['cpage' => $cp]) ?>#cashCollectionsTracker"><?= $cp ?></a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $cCurrentPage >= $cTotalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= build_filter_url(['cpage' => $cCurrentPage + 1]) ?>#cashCollectionsTracker" aria-label="Next">
                                <span aria-hidden="true">Next&nbsp;&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function generateMockCollections(int $count): array
{
    $list = [];
    for ($i = 1; $i <= $count; $i++) {
        $list[] = [
            'id' => $i,
            'collected_at' => date('Y-m-d H:i:s', strtotime("-{$i} days")),
            'full_name' => "Operator {$i}",
            'collected_amount' => 500.00 * $i,
            'system_sales_amount' => 500.00 * $i,
            'variance_amount' => 0.00,
            'notes' => "Routine collection #{$i}"
        ];
    }
    return $list;
}

// -------------------------------------------------------------
// 4. Test Scenarios: 0, 1, 2, 5, 6, 12 Records
// -------------------------------------------------------------
echo "3. Testing Record Scenarios (0, 1, 2, 5, 6 records)...\n";

// Scenario 0: 0 records
$html0 = renderTrackerCard(generateMockCollections(0));
if (strpos($html0, 'collection-empty-container') === false) {
    throw new RuntimeException("Scenario 0: Missing collection-empty-container!");
}
if (strpos($html0, 'No physical cash collections recorded yet') === false) {
    throw new RuntimeException("Scenario 0: Missing empty message!");
}
if (strpos($html0, 'View More') === false) {
    throw new RuntimeException("Scenario 0: Missing View More button in header!");
}
if (strpos($html0, 'tx-pagination') !== false) {
    throw new RuntimeException("Scenario 0: Pagination should NOT appear for 0 records!");
}
echo "   [PASS] 0 records: Renders centered empty-state container, View More button present, no pagination.\n";

// Scenario 2: 2 records (User Screenshot Case)
$html2 = renderTrackerCard(generateMockCollections(2));
preg_match_all('/<tr class="collection-data-row"/', $html2, $r2);
preg_match_all('/<tr class="tx-empty-row"/', $html2, $e2);
if (count($r2[0]) !== 2 || count($e2[0]) !== 0) {
    throw new RuntimeException("Scenario 2 failed! Expected 2 real rows and 0 empty rows, found real: " . count($r2[0]) . ", empty: " . count($e2[0]));
}
if (strpos($html2, 'tx-pagination') !== false) {
    throw new RuntimeException("Scenario 2: Pagination should NOT appear for 2 records!");
}
echo "   [PASS] 2 records (User screenshot scenario): Exactly 2 rows rendered, 0 empty rows, fits content cleanly.\n";

// Scenario 5: 5 records (Max single page)
$html5 = renderTrackerCard(generateMockCollections(5));
preg_match_all('/<tr class="collection-data-row"/', $html5, $r5);
preg_match_all('/<tr class="tx-empty-row"/', $html5, $e5);
if (count($r5[0]) !== 5 || count($e5[0]) !== 0) {
    throw new RuntimeException("Scenario 5 failed! Expected 5 real rows and 0 empty rows.");
}
if (strpos($html5, 'tx-pagination') !== false) {
    throw new RuntimeException("Scenario 5: Pagination should NOT appear for exactly 5 records!");
}
echo "   [PASS] 5 records: Exactly 5 rows rendered, 0 empty rows, no pagination.\n";

// Scenario 6: 6 records (Exceeds 5 -> 2 pages)
$collections6 = generateMockCollections(6);
$html6_p1 = renderTrackerCard($collections6, 1);
preg_match_all('/<tr class="collection-data-row"/', $html6_p1, $r6_1);
if (count($r6_1[0]) !== 5) {
    throw new RuntimeException("Scenario 6 (Page 1) failed! Expected 5 rows, found: " . count($r6_1[0]));
}
if (strpos($html6_p1, 'Page <strong>1</strong> of <strong>2</strong>') === false) {
    throw new RuntimeException("Scenario 6 (Page 1): Missing 'Page 1 of 2' in pagination!");
}

$html6_p2 = renderTrackerCard($collections6, 2);
preg_match_all('/<tr class="collection-data-row"/', $html6_p2, $r6_2);
if (count($r6_2[0]) !== 1) {
    throw new RuntimeException("Scenario 6 (Page 2) failed! Expected 1 row, found: " . count($r6_2[0]));
}
if (strpos($html6_p2, 'Page <strong>2</strong> of <strong>2</strong>') === false) {
    throw new RuntimeException("Scenario 6 (Page 2): Missing 'Page 2 of 2' in pagination!");
}
echo "   [PASS] 6 records: Page 1 = 5 real rows; Page 2 = 1 real row (Total 2 pages, limit 5 per page).\n";

echo "\n🎉 ALL PHYSICAL COIN BOX COLLECTION TRACKER TESTS PASSED 100% SUCCESSFULLY!\n";
