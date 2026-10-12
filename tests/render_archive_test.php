<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/auth.php';

$_SESSION['user_id'] = 1;
$_SESSION['role'] = ROLE_ADMIN;
$_SESSION['user_role'] = ROLE_ADMIN;
$_SESSION['full_name'] = 'Admin User';
$_SESSION['email'] = 'admin@vendolink.test';
$_SESSION['last_activity'] = time();

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/dashboard/archive.php';
$_GET = ['tab' => 'transactions', 'page' => 1];

ob_start();
require __DIR__ . '/../dashboard/archive.php';
$html = ob_get_clean();

// Test tab=maintenance
$_GET = ['tab' => 'maintenance', 'page' => 1];
ob_start();
require __DIR__ . '/../dashboard/archive.php';
$htmlMaint = ob_get_clean();
file_put_contents(__DIR__ . '/maint_test.html', $htmlMaint);
echo "Maintenance tab HTML length: " . strlen($htmlMaint) . "\n";


// Test page 2
$_GET = ['tab' => 'transactions', 'page' => 2];
ob_start();
require __DIR__ . '/../dashboard/archive.php';
$html2 = ob_get_clean();
file_put_contents(__DIR__ . '/page2_test.html', $html2);
echo "Page 2 rendered HTML length: " . strlen($html2) . "\n";
preg_match_all('/<tr style="border-bottom: 1px solid rgba\(255,255,255,\.04\); font-size: \.88rem;">/i', $html2, $trMatches2);
preg_match_all('/<tr class="tx-empty-row"/i', $html2, $emptyMatches2);
echo "Page 2: " . count($trMatches2[0]) . " data rows + " . count($emptyMatches2[0]) . " empty rows = " . (count($trMatches2[0]) + count($emptyMatches2[0])) . " total rows\n";

echo "\n--- SIMULATED CASES VERIFICATION ---\n";
$cases = [0, 1, 5, 9, 15, 16, 24, 30, 31];
$perPage = 15;

foreach ($cases as $total) {
    $totalPages = max(1, (int)ceil($total / $perPage));
    echo "\nTest Case: {$total} total records\n";
    echo "  Total Pages: {$totalPages}\n";
    
    for ($p = 1; $p <= $totalPages; $p++) {
        $offset = ($p - 1) * $perPage;
        $itemsOnPage = max(0, min($perPage, $total - $offset));
        $emptyRows = ($total === 0) ? 0 : ($perPage - $itemsOnPage);
        $totalTableRows = ($total === 0) ? 0 : ($itemsOnPage + $emptyRows);
        echo "  Page {$p}: {$itemsOnPage} transactions + {$emptyRows} empty rows = {$totalTableRows} table rows";
        if ($total === 0) {
            echo " (Empty state message displayed)";
        }
        echo "\n";
    }
}

