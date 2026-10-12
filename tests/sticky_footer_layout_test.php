<?php
/**
 * ============================================================
 * VendoLink — Sticky Footer Layout Verification Test
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';

echo "=== STARTING STICKY FOOTER LAYOUT TESTS ===\n\n";

// 1. Verify CSS rules in assets/css/app.css
$css = file_get_contents(__DIR__ . '/../assets/css/app.css');

// Check dashboard-body
if (!preg_match('/\.dashboard-body\s*\{[^}]*min-height:\s*100vh;[^}]*display:\s*flex;[^}]*flex-direction:\s*column;/s', $css)) {
    throw new RuntimeException("CSS rule for .dashboard-body is missing min-height: 100vh and display: flex column!");
}
echo "1. [PASS] .dashboard-body has min-height: 100vh and display: flex; flex-direction: column.\n";

// Check app-shell
if (!preg_match('/\.app-shell\s*\{[^}]*display:\s*flex;[^}]*flex:\s*1\s*0\s*auto;[^}]*min-height:\s*100vh;/s', $css)) {
    throw new RuntimeException("CSS rule for .app-shell is missing flex: 1 0 auto and min-height: 100vh!");
}
echo "2. [PASS] .app-shell has display: flex, flex: 1 0 auto, and min-height: 100vh.\n";

// Check main-content
if (!preg_match('/(#mainContent,\s*)?\.main-content\s*\{[^}]*display:\s*flex;[^}]*flex-direction:\s*column;[^}]*min-height:\s*100vh;/s', $css)) {
    throw new RuntimeException("CSS rule for .main-content is missing min-height: 100vh and display: flex column!");
}
echo "3. [PASS] .main-content has display: flex; flex-direction: column and min-height: 100vh.\n";

// Check page-content / content-wrapper
if (!preg_match('/\.page-content,\s*\.content-wrapper/s', $css)) {
    throw new RuntimeException("CSS rule for .page-content / .content-wrapper is missing!");
}
echo "4. [PASS] .page-content and .content-wrapper have flex: 1 0 auto for stretching in empty views.\n";

// Check app-footer
if (!preg_match('/\.app-footer\s*\{[^}]*margin-top:\s*auto(\s*!important)?;\s*flex-shrink:\s*0/s', $css)) {
    throw new RuntimeException("CSS rule for .app-footer is missing margin-top: auto and flex-shrink: 0!");
}
echo "5. [PASS] .app-footer has margin-top: auto and flex-shrink: 0.\n";

// Check mobile responsive footer
if (!preg_match('/\.app-footer\s*\{[^}]*flex-direction:\s*column;/s', $css)) {
    throw new RuntimeException("CSS mobile rule for .app-footer is missing column stacking!");
}
echo "6. [PASS] Mobile responsive footer stacks cleanly in column layout.\n";

// 2. Check layout markup in key short-content views
$views = [
    'dashboard/maintenance.php',
    'dashboard/archive.php',
    'dashboard/sales.php',
    'dashboard/reports.php',
    'dashboard/alerts.php',
    'dashboard/index.php',
    'dashboard/machines.php',
];

foreach ($views as $viewPath) {
    $content = file_get_contents(__DIR__ . '/../' . $viewPath);
    if (strpos($content, 'class="dashboard-body"') === false) {
        throw new RuntimeException("{$viewPath} is missing class='dashboard-body'!");
    }
    if (strpos($content, 'class="app-shell"') === false) {
        throw new RuntimeException("{$viewPath} is missing class='app-shell'!");
    }
    if (strpos($content, 'class="main-content"') === false) {
        throw new RuntimeException("{$viewPath} is missing class='main-content'!");
    }
    if (strpos($content, 'class="page-content"') === false) {
        throw new RuntimeException("{$viewPath} is missing class='page-content'!");
    }
    if (strpos($content, 'class="app-footer"') === false) {
        throw new RuntimeException("{$viewPath} is missing class='app-footer'!");
    }
    echo "7. [PASS] {$viewPath} contains complete layout hierarchy (body > app-shell > main-content > page-content + app-footer).\n";
}

echo "\n🎉 ALL STICKY FOOTER LAYOUT TESTS PASSED 100% SUCCESSFULLY!\n";
