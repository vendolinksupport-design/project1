<?php
/**
 * ============================================================
 * VendoLink — Collection Date Formatting Verification Test
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=== STARTING COLLECTION DATE FORMATTING TESTS ===\n\n";

// Test 1: Standard DB Timestamp
$dbTime = '2026-09-08 21:03:00';
$formatted = format_date($dbTime, 'M d, Y, g:i A');
echo "1. DB Timestamp: '{$dbTime}' => '{$formatted}'\n";
if ($formatted !== 'Sep 08, 2026, 9:03 PM') {
    throw new RuntimeException("Expected 'Sep 08, 2026, 9:03 PM', got '{$formatted}'");
}
echo "   [PASS] Clean date and time output without corrupted tokens or entities.\n\n";

// Test 2: ISO 8601 String
$isoTime = '2026-09-08T21:03:00+08:00';
$formattedIso = format_date($isoTime, 'M d, Y, g:i A');
echo "2. ISO 8601 String: '{$isoTime}' => '{$formattedIso}'\n";
if ($formattedIso !== 'Sep 08, 2026, 9:03 PM') {
    throw new RuntimeException("Expected 'Sep 08, 2026, 9:03 PM', got '{$formattedIso}'");
}
echo "   [PASS] Clean ISO date parsing.\n\n";

// Test 3: Unix Timestamp (Integer and Numeric String)
$tsInt = strtotime('2026-09-08 21:03:00');
$formattedTsInt = format_date($tsInt, 'M d, Y, g:i A');
$formattedTsStr = format_date((string)$tsInt, 'M d, Y, g:i A');
echo "3. Unix Timestamp: {$tsInt} => '{$formattedTsInt}' / '{$formattedTsStr}'\n";
if ($formattedTsInt !== 'Sep 08, 2026, 9:03 PM' || $formattedTsStr !== 'Sep 08, 2026, 9:03 PM') {
    throw new RuntimeException("Failed unix timestamp conversion");
}
echo "   [PASS] Clean Unix timestamp formatting.\n\n";

// Test 4: Null / Empty / Zero Date Fallbacks
$nullRes = format_date(null);
$emptyRes = format_date('');
$zeroRes = format_date('0000-00-00 00:00:00');
echo "4. Fallback Handling:\n";
echo "   - null => '{$nullRes}'\n";
echo "   - ''   => '{$emptyRes}'\n";
echo "   - zero => '{$zeroRes}'\n";
if ($nullRes !== '—' || $emptyRes !== '—' || $zeroRes !== '—') {
    throw new RuntimeException("Failed fallback handling");
}
echo "   [PASS] Graceful fallback on null/empty/zero date.\n\n";

// Test 5: Verify no unescaped entity strings exist in sales.php
$salesFile = file_get_contents(__DIR__ . '/../dashboard/sales.php');
if (strpos($salesFile, "date('M d, Y &bull;") !== false) {
    throw new RuntimeException("Found corrupted date('M d, Y &bull; ...') in sales.php!");
}
echo "5. sales.php Codebase Check:\n";
echo "   [PASS] No corrupted date format masks found in sales.php.\n\n";

echo "🎉 ALL COLLECTION DATE FORMATTING TESTS PASSED 100% SUCCESSFULLY!\n";
