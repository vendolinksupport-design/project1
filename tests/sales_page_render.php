<?php
/**
 * Test rendering sales.php in an authenticated session environment.
 */
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'administrator';
$_SESSION['username'] = 'admin';
$_SESSION['full_name'] = 'System Administrator';

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/dashboard/sales.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

ob_start();
require __DIR__ . '/../dashboard/sales.php';
$output = ob_get_clean();

if (strpos($output, 'Sales Overview') === false && strpos($output, 'Transactions &amp; Sales') === false && strpos($output, 'Transactions & Sales') === false) {
    throw new RuntimeException("Page title not rendered.");
}
if (strpos($output, 'Transaction Records') === false) {
    throw new RuntimeException("Transaction Records panel not rendered.");
}

echo "✅ Sales page rendered successfully without errors (" . strlen($output) . " bytes).\n";
