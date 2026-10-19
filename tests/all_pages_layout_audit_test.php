<?php
/**
 * Layout and Sticky Footer Comprehensive Audit Test
 */

$pages = [
    'dashboard/index.php',
    'dashboard/machines.php',
    'dashboard/alerts.php',
    'dashboard/maintenance.php',
    'dashboard/sales.php',
    'dashboard/archive.php',
    'dashboard/reports.php',
    'dashboard/ai.php',
    'users/index.php',
    'users/profile.php',
    'users/change_password.php',
    'settings/index.php',
    'admin/dashboard/index.php',
    'admin/users/index.php',
    'admin/users/create.php',
    'admin/machines/index.php',
    'admin/customers/index.php',
    'admin/licenses/index.php',
    'admin/ota/index.php',
];

echo "=== VENDOLINK PAGE LAYOUT AUDIT ===\n\n";

$allPassed = true;
foreach ($pages as $p) {
    $fullPath = __DIR__ . '/../' . $p;
    if (!file_exists($fullPath)) {
        echo "Skipping non-existent: $p\n";
        continue;
    }
    $c = file_get_contents($fullPath);
    if (strpos($c, "require __DIR__ . '/../machines/index.php'") !== false) {
        $c = file_get_contents(__DIR__ . '/../admin/machines/index.php');
    }
    $hasBody = strpos($c, 'dashboard-body') !== false || strpos($c, 'admin-body') !== false;
    $hasShell = strpos($c, 'app-shell') !== false || strpos($c, 'admin-shell') !== false;
    $hasMain = strpos($c, 'main-content') !== false;
    $hasContent = strpos($c, 'page-content') !== false || strpos($c, 'content-wrapper') !== false;
    $hasFooter = strpos($c, 'app-footer') !== false;

    $passed = $hasBody && $hasShell && $hasMain && $hasContent && $hasFooter;
    if (!$passed) {
        $allPassed = false;
    }

    echo sprintf(
        "%-30s | Body: %s | Shell: %s | Main: %s | Content: %s | Footer: %s | Result: %s\n",
        $p,
        $hasBody ? 'OK' : 'MISS',
        $hasShell ? 'OK' : 'MISS',
        $hasMain ? 'OK' : 'MISS',
        $hasContent ? 'OK' : 'MISS',
        $hasFooter ? 'OK' : 'MISS',
        $passed ? '[PASS]' : '[FAIL]'
    );
}

// Check CSS rules
$css = file_get_contents(__DIR__ . '/../assets/css/app.css');

echo "\n=== CSS STICKY FOOTER RULES AUDIT ===\n";

$cssChecks = [
    'dashboard-body flex column' => preg_match('/\.dashboard-body\s*\{[^}]*min-height:\s*100(vh|dvh);[^}]*display:\s*flex;[^}]*flex-direction:\s*column;/s', $css),
    'app-shell flex stretch' => preg_match('/\.app-shell\s*\{[^}]*display:\s*flex;[^}]*flex:\s*1\s*0\s*auto;[^}]*min-height:\s*100(vh|dvh);/s', $css),
    'main-content flex column' => preg_match('/(#mainContent,\s*)?\.main-content\s*\{[^}]*display:\s*flex;[^}]*flex-direction:\s*column;[^}]*min-height:\s*100(vh|dvh);/s', $css),
    'page-content flex 1 0 auto' => preg_match('/\.page-content[^{]*\{[^}]*flex:\s*1\s*0\s*auto;/s', $css),
    'app-footer margin-top auto' => preg_match('/\.app-footer\s*\{[^}]*margin-top:\s*auto(\s*!important)?;/s', $css),
    'app-footer flex-shrink 0' => preg_match('/\.app-footer\s*\{[^}]*flex-shrink:\s*0(\s*!important)?;/s', $css),
    'no position fixed on app-footer' => !preg_match('/\.app-footer\s*\{[^}]*position:\s*fixed/s', $css),
    'no position absolute on app-footer' => !preg_match('/\.app-footer\s*\{[^}]*position:\s*absolute/s', $css),
];

foreach ($cssChecks as $name => $res) {
    if (!$res) {
        $allPassed = false;
    }
    echo sprintf("%-40s: %s\n", $name, $res ? '[PASS]' : '[FAIL]');
}

if ($allPassed) {
    echo "\n🎉 AUDIT COMPLETE: ALL LAYOUT & CSS RULES PASS 100%!\n";
    exit(0);
} else {
    echo "\n⚠️ AUDIT FAILED: SOME CHECKS DID NOT PASS.\n";
    exit(1);
}
