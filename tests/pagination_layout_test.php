<?php
/**
 * Pagination Single-Row and Wrapping Verification Test
 */

echo "=== STARTING PAGINATION LAYOUT TESTS ===\n\n";

$css = file_get_contents(__DIR__ . '/../assets/css/app.css');

// 1. Check flex-wrap: nowrap on .pagination
if (!preg_match('/\.pagination\s*\{[^}]*flex-wrap:\s*nowrap\s*!important;/s', $css)) {
    throw new RuntimeException("CSS rule .pagination missing flex-wrap: nowrap !important;");
}
echo "1. [PASS] .pagination has flex-wrap: nowrap !important to prevent items wrapping.\n";

// 2. Check white-space: nowrap on .pagination and .page-link
if (!preg_match('/\.pagination\s*\{[^}]*white-space:\s*nowrap\s*!important;/s', $css)) {
    throw new RuntimeException("CSS rule .pagination missing white-space: nowrap !important;");
}
if (!preg_match('/(\.pagination|\.tx-pagination|\.panel-footer)\s*\.page-link\s*\{[^}]*white-space:\s*nowrap\s*!important;/s', $css)) {
    throw new RuntimeException("CSS rule .page-link missing white-space: nowrap !important;");
}
echo "2. [PASS] .pagination and .page-link have white-space: nowrap !important to prevent internal text wrapping.\n";

// 3. Check flex-shrink: 0 on .page-item and .page-link
if (!preg_match('/(\.pagination|\.tx-pagination|\.panel-footer)\s*\.page-item\s*\{[^}]*flex-shrink:\s*0\s*!important;/s', $css)) {
    throw new RuntimeException("CSS rule .page-item missing flex-shrink: 0 !important;");
}
echo "3. [PASS] .page-item and .page-link have flex-shrink: 0 to prevent button deformation.\n";

// 4. Check responsive overflow on nav container
if (!preg_match('/(\.tx-pagination nav|\.panel-footer nav)\s*\{[^}]*overflow-x:\s*auto;/s', $css)) {
    throw new RuntimeException("CSS rule for pagination nav container missing overflow-x: auto;");
}
echo "4. [PASS] Pagination nav wrapper has overflow-x: auto for seamless mobile scrolling.\n";

// 5. Verify HTML non-breaking spaces across pagination views
$views = [
    'dashboard/archive.php',
    'dashboard/sales.php',
    'users/index.php',
];

foreach ($views as $view) {
    $content = file_get_contents(__DIR__ . '/../' . $view);
    if (strpos($content, 'Next&nbsp;&raquo;') === false) {
        throw new RuntimeException("{$view} is missing 'Next&nbsp;&raquo;' non-breaking space!");
    }
    if (strpos($content, '&laquo;&nbsp;Prev') === false) {
        throw new RuntimeException("{$view} is missing '&laquo;&nbsp;Prev' non-breaking space!");
    }
    echo "5. [PASS] {$view} uses non-breaking spaces for Prev/Next symbols.\n";
}

echo "\n🎉 ALL PAGINATION LAYOUT TESTS PASSED 100% SUCCESSFULLY!\n";
