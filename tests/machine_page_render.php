<?php
/** CLI render check for the protected Machine & Services page. */

session_save_path(__DIR__);
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'administrator';
$_SESSION['full_name'] = 'Render Test';
$_SESSION['email'] = 'test@example.com';
$_SESSION['username'] = 'test';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/dashboard/machines.php';

ob_start();
require __DIR__ . '/../dashboard/machines.php';
$html = ob_get_clean();

foreach (['Water Service', 'Soap Service', 'Current Runtime', 'Total Runtime', 'Runtime Status', 'Maintenance'] as $term) {
    if (strpos($html, $term) === false) {
        throw new RuntimeException('Missing machine page content: ' . $term);
    }
}

echo "Machine page runtime and maintenance render test passed.\n";
