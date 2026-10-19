<?php
/**
 * ============================================================
 * VendoLink — History Search Icon Styling Verification Test
 * ============================================================
 * Tests that all 4 History views:
 * 1. Transaction History (records)
 * 2. Cash Collections (collections)
 * 3. Maintenance History (maintenance)
 * 4. Configuration History (configuration)
 * render the search icon wrapper without background, border,
 * border-radius, or box styling, with the icon positioned immediately
 * to the left of the search input, vertically centered, while
 * preserving the search input's styling and search functionality.
 */

chdir('c:/xampp/htdocs/vendolink_claude_v1');

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'models/Report.php';
require_once 'models/Archive.php';
require_once 'models/Transaction.php';

function assert_test(bool $condition, string $message): void {
    if ($condition) {
        echo "  [PASS] {$message}\n";
    } else {
        echo "  [FAIL] {$message}\n";
        exit(1);
    }
}

echo "===================================================================\n";
echo " VENDOLINK — HISTORY SEARCH ICON STYLING TEST \n";
echo "===================================================================\n\n";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
$_SESSION['role'] = 'admin';
$_SESSION['username'] = 'admin';
$_SESSION['full_name'] = 'Administrator';
$_SESSION['email'] = 'admin@vendolink.test';
$_SESSION['owner_id'] = null;
$_SESSION['last_activity'] = time();
$_SESSION['created_at'] = time();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['PHP_SELF'] = '/dashboard/archive.php';

// -----------------------------------------------------------------
// 1. Verify CSS Rules in assets/css/app.css
// -----------------------------------------------------------------
echo "--- 1. Testing Scoped CSS Rules (assets/css/app.css) ---\n";
$cssContent = file_get_contents('assets/css/app.css');

assert_test(strpos($cssContent, '.tx-filter-card .history-search-group') !== false, "history-search-group scoped to .tx-filter-card");
assert_test(strpos($cssContent, '.tx-filter-card .history-search-icon') !== false, "history-search-icon scoped to .tx-filter-card");
assert_test(strpos($cssContent, 'background: transparent') !== false, "Icon wrapper has transparent background");
assert_test(strpos($cssContent, 'border: none') !== false, "Icon wrapper has border: none");
assert_test(strpos($cssContent, 'border-radius: 0') !== false, "Icon wrapper has border-radius: 0");
assert_test(strpos($cssContent, 'box-shadow: none') !== false, "Icon wrapper has box-shadow: none");
assert_test(strpos($cssContent, 'align-items: center') !== false, "Group aligns icon vertically with center of input");
assert_test(strpos($cssContent, '.tx-filter-card .history-search-group > .form-control.dark-input') !== false, "Input preserves dark-input border and radius");

// -----------------------------------------------------------------
// 2. Tab 1: Transaction History (tab=transactions&section=records)
// -----------------------------------------------------------------
echo "\n--- 2. Testing Transaction History Search Bar ---\n";
$_GET = ['tab' => 'transactions', 'section' => 'records', 'page' => 1];
ob_start();
require 'dashboard/archive.php';
$htmlTx = ob_get_clean();

assert_test(strpos($htmlTx, 'class="input-group input-group-sm history-search-group"') !== false, "Transaction History uses history-search-group container");
assert_test(strpos($htmlTx, 'class="history-search-icon"') !== false, "Transaction History uses history-search-icon wrapper");
assert_test(strpos($htmlTx, 'data-lucide="search"') !== false || strpos($htmlTx, '<svg') !== false, "Magnifying-glass icon is rendered");
assert_test(strpos($htmlTx, 'input-group-text dark-input" style="border-right: 0;"') === false, "Old box styling input-group-text dark-input removed from icon wrapper");
assert_test(strpos($htmlTx, 'name="search"') !== false, "Search input name='search' preserved");
assert_test(strpos($htmlTx, 'placeholder="Search ID (#00001) or service..."') !== false, "Transaction History placeholder preserved");
assert_test(strpos($htmlTx, 'style="border-left: 0;"') === false, "Inline style border-left: 0 removed from search input");

// -----------------------------------------------------------------
// 3. Tab 2: Cash Collections (tab=transactions&section=collections)
// -----------------------------------------------------------------
echo "\n--- 3. Testing Cash Collections in History Search Bar ---\n";
$_GET = ['tab' => 'transactions', 'section' => 'collections', 'page' => 1];
ob_start();
require 'dashboard/archive.php';
$htmlCc = ob_get_clean();

assert_test(strpos($htmlCc, 'class="input-group input-group-sm history-search-group"') !== false, "Cash Collections uses history-search-group container");
assert_test(strpos($htmlCc, 'class="history-search-icon"') !== false, "Cash Collections uses history-search-icon wrapper");
assert_test(strpos($htmlCc, 'data-lucide="search"') !== false || strpos($htmlCc, '<svg') !== false, "Cash Collections magnifying-glass icon rendered");
assert_test(strpos($htmlCc, 'input-group-text dark-input" style="border-right: 0;"') === false, "Old box styling removed from Cash Collections search icon");
assert_test(strpos($htmlCc, 'name="search"') !== false, "Cash Collections search input name preserved");
assert_test(strpos($htmlCc, 'placeholder="Search notes, machine, or recorded by..."') !== false, "Cash Collections placeholder preserved");
assert_test(strpos($htmlCc, 'style="border-left: 0;"') === false, "Inline style border-left: 0 removed from Cash Collections input");

// -----------------------------------------------------------------
// 4. Tab 3: Maintenance History (tab=maintenance)
// -----------------------------------------------------------------
echo "\n--- 4. Testing Maintenance History Search Bar ---\n";
$_GET = ['tab' => 'maintenance', 'page' => 1];
ob_start();
require 'dashboard/archive.php';
$htmlMaint = ob_get_clean();

assert_test(strpos($htmlMaint, 'class="input-group input-group-sm history-search-group"') !== false, "Maintenance History uses history-search-group container");
assert_test(strpos($htmlMaint, 'class="history-search-icon"') !== false, "Maintenance History uses history-search-icon wrapper");
assert_test(strpos($htmlMaint, 'data-lucide="search"') !== false || strpos($htmlMaint, '<svg') !== false, "Maintenance magnifying-glass icon rendered");
assert_test(strpos($htmlMaint, 'input-group-text dark-input" style="border-right: 0;"') === false, "Old box styling removed from Maintenance search icon");
assert_test(strpos($htmlMaint, 'name="search"') !== false, "Maintenance search input name preserved");
assert_test(strpos($htmlMaint, 'placeholder="Search maintenance name or notes..."') !== false, "Maintenance placeholder preserved");
assert_test(strpos($htmlMaint, 'style="border-left: 0;"') === false, "Inline style border-left: 0 removed from Maintenance input");

// -----------------------------------------------------------------
// 5. Tab 4: Configuration History (tab=configuration)
// -----------------------------------------------------------------
echo "\n--- 5. Testing Configuration History Search Bar ---\n";
$_GET = ['tab' => 'configuration', 'page' => 1];
ob_start();
require 'dashboard/archive.php';
$htmlConfig = ob_get_clean();

assert_test(strpos($htmlConfig, 'class="input-group input-group-sm history-search-group"') !== false, "Configuration History uses history-search-group container");
assert_test(strpos($htmlConfig, 'class="history-search-icon"') !== false, "Configuration History uses history-search-icon wrapper");
assert_test(strpos($htmlConfig, 'data-lucide="search"') !== false || strpos($htmlConfig, '<svg') !== false, "Configuration magnifying-glass icon rendered");
assert_test(strpos($htmlConfig, 'input-group-text dark-input" style="border-right: 0;"') === false, "Old box styling removed from Configuration search icon");
assert_test(strpos($htmlConfig, 'name="search"') !== false, "Configuration search input name preserved");
assert_test(strpos($htmlConfig, 'placeholder="Search service, user, or machine..."') !== false, "Configuration placeholder preserved");
assert_test(strpos($htmlConfig, 'style="border-left: 0;"') === false, "Inline style border-left: 0 removed from Configuration input");

// -----------------------------------------------------------------
// 6. Search Execution & Filtering Preserved
// -----------------------------------------------------------------
echo "\n--- 6. Testing Search Query Execution Across All 4 Views ---\n";
// Transaction History search execution
$_GET = ['tab' => 'transactions', 'section' => 'records', 'search' => 'water', 'page' => 1];
ob_start();
require 'dashboard/archive.php';
$htmlTxSearch = ob_get_clean();
assert_test(strpos($htmlTxSearch, 'value="water"') !== false, "Search value populated in Transaction History");

// Cash Collections search execution
$_GET = ['tab' => 'transactions', 'section' => 'collections', 'search' => 'routine', 'page' => 1];
ob_start();
require 'dashboard/archive.php';
$htmlCcSearch = ob_get_clean();
assert_test(strpos($htmlCcSearch, 'value="routine"') !== false, "Search value populated in Cash Collections");

// Maintenance History search execution
$_GET = ['tab' => 'maintenance', 'search' => 'filter', 'page' => 1];
ob_start();
require 'dashboard/archive.php';
$htmlMaintSearch = ob_get_clean();
assert_test(strpos($htmlMaintSearch, 'value="filter"') !== false, "Search value populated in Maintenance History");

// Configuration History search execution
$_GET = ['tab' => 'configuration', 'search' => 'price', 'page' => 1];
ob_start();
require 'dashboard/archive.php';
$htmlConfigSearch = ob_get_clean();
assert_test(strpos($htmlConfigSearch, 'value="price"') !== false, "Search value populated in Configuration History");

echo "\n===================================================================\n";
echo "🎉 ALL HISTORY SEARCH ICON STYLING TESTS PASSED 100%!\n";
echo "===================================================================\n";
