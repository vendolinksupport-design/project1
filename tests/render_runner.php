<?php
/**
 * Generic Page Render Runner for CLI Testing
 */
if ($argc < 2) {
    exit(1);
}

$page = $argv[1];
$role = $argv[2] ?? 'owner';

session_start();
$_SESSION['user_id'] = ($role === 'administrator') ? 1 : 2;
$_SESSION['user_role'] = $role;
$_SESSION['role'] = $role;
$_SESSION['username'] = ($role === 'administrator') ? 'admin' : 'owner';
$_SESSION['full_name'] = ($role === 'administrator') ? 'Administrator' : 'Business Owner';
$_SESSION['email'] = ($role === 'administrator') ? 'admin@vendolink.test' : 'owner@vendolink.test';
$_SESSION['last_activity'] = time();

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/' . ltrim($page, '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

chdir(__DIR__ . '/..');

ob_start();
require __DIR__ . '/../' . $page;
$html = ob_get_clean();

echo "HTML_LEN:" . strlen($html);
if (strpos($html, 'bootstrap-icons.min.css') !== false) {
    echo "|HAS_BI";
}
if (strpos($html, 'data-lucide') !== false) {
    echo "|HAS_LUCIDE";
}
if (strpos($html, 'app.css') !== false) {
    echo "|HAS_APPCSS";
}
if (strpos($html, 'sidebar') !== false) {
    echo "|HAS_SIDEBAR";
}
if (strpos($html, 'topnav') !== false) {
    echo "|HAS_TOPNAV";
}
