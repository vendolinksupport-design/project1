<?php
/**
 * ============================================================
 * VendoLink — Mobile Responsive Layout Test Suite
 * ============================================================
 *
 * Verifies that the Owner Account -> My Profile page (users/profile.php)
 * and stylesheet (assets/css/app.css, views/layouts/ai_floating_chat.php)
 * correctly support mobile screens (320px, 360px, 375px, 390px, 430px)
 * without horizontal overflow, clipped content, overlapping panels,
 * or broken text.
 */

$rootDir = dirname(__DIR__);
$cssFile = $rootDir . '/assets/css/app.css';
$profileFile = $rootDir . '/users/profile.php';
$chatFile = $rootDir . '/views/layouts/ai_floating_chat.php';

$totalTests = 0;
$passedTests = 0;

function assertTest(bool $condition, string $message): void {
    global $totalTests, $passedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "   [PASS] {$message}\n";
    } else {
        echo "   [FAIL] {$message}\n";
    }
}

echo "=== STARTING PROFILE MOBILE RESPONSIVE TEST SUITE ===\n\n";

// ------------------------------------------------------------
// 1. Verifying Mobile Layout CSS in assets/css/app.css
// ------------------------------------------------------------
echo "1. Verifying Main Layout & Specificity Rules in assets/css/app.css...\n";
$appCss = file_get_contents($cssFile);

// Check that desktop has 2-column grid
assertTest(
    preg_match('/\.profile-page-grid\s*,\s*\.layout\.profile-page-grid\s*\{[^}]*grid-template-columns:\s*1fr\s+1fr/s', $appCss) === 1,
    "Desktop/tablet layout defines 2 equal columns (grid-template-columns: 1fr 1fr)"
);

// Check specificity fix for mobile profile-page-grid
assertTest(
    preg_match('/@media\s*\(max-width:\s*767\.98px\)[^{]*\{[\s\S]*?\.profile-container\s+\.layout\.profile-page-grid[\s\S]*?grid-template-columns:\s*1fr\s*!important/s', $appCss) === 1,
    "Mobile (<768px) overrides desktop specificity with '.profile-container .layout.profile-page-grid' to 1fr !important"
);

// Check specificity fix for mobile identity-strip
assertTest(
    preg_match('/@media\s*\(max-width:\s*767\.98px\)[^{]*\{[\s\S]*?\.profile-container\s+\.identity-strip[\s\S]*?flex-direction:\s*column\s*!important/s', $appCss) === 1,
    "Mobile (<768px) overrides desktop identity-strip to flex-direction: column !important"
);

// Check that profile container has max-width and overflow containment
assertTest(
    strpos($appCss, '.profile-container') !== false &&
    preg_match('/\.profile-container\s*\{[^}]*box-sizing:\s*border-box/s', $appCss) === 1 &&
    preg_match('/\.profile-container\s*\{[^}]*min-width:\s*0/s', $appCss) === 1,
    ".profile-container enforces box-sizing: border-box and min-width: 0"
);

// Check that horizontal overflow prevention properties exist on grids and panels
assertTest(
    preg_match('/\.profile-page-grid\s*,\s*\.layout\.profile-page-grid\s*\{[^}]*max-width:\s*100%[^}]*min-width:\s*0/s', $appCss) === 1,
    ".layout.profile-page-grid enforces max-width: 100% and min-width: 0"
);

// Check mobile clearance for bottom elements/chat button
assertTest(
    preg_match('/@media\s*\(max-width:\s*767\.98px\)[^{]*\{[\s\S]*?\.profile-container\s*\{[^}]*padding-bottom:\s*3\.5rem/s', $appCss) === 1,
    "Mobile (<768px) .profile-container provides clearance padding-bottom for floating widgets"
);

// ------------------------------------------------------------
// 2. Verifying Profile Header Responsive Rules
// ------------------------------------------------------------
echo "\n2. Verifying Profile Header (Identity Strip) Stacking & Details...\n";

// Identity meta positioned below with top border on mobile
assertTest(
    preg_match('/@media\s*\(max-width:\s*767\.98px\)[^{]*\{[\s\S]*?\.identity-meta\s*\{[^}]*width:\s*100%[^}]*border-top:/s', $appCss) === 1,
    ".identity-meta spans 100% width with border-top separator below primary details on mobile"
);

// Identity badges wrap naturally
assertTest(
    preg_match('/@media\s*\(max-width:\s*767\.98px\)[^{]*\{[\s\S]*?\.identity-badges[\s\S]*?flex-wrap:\s*wrap/s', $appCss) === 1,
    ".identity-badges has flex-wrap: wrap to prevent badge cutoff on narrow viewports"
);

// Long names and handles wrap safely
assertTest(
    preg_match('/@media\s*\(max-width:\s*767\.98px\)[^{]*\{[\s\S]*?\.identity-name[\s\S]*?word-break:\s*break-word/s', $appCss) === 1,
    ".identity-name has word-break: break-word to prevent header horizontal overflow"
);
assertTest(
    preg_match('/@media\s*\(max-width:\s*767\.98px\)[^{]*\{[\s\S]*?\.identity-handle[\s\S]*?word-break:\s*break-all/s', $appCss) === 1,
    ".identity-handle has word-break: break-all to prevent handle overflow"
);

// Polish for ultra-narrow phones (<=375px and <=576px)
assertTest(
    preg_match('/@media\s*\(max-width:\s*375px\)[^{]*\{[\s\S]*?\.avatar-lg\.profile-avatar-large\s*\{[^}]*width:\s*48px/s', $appCss) === 1,
    "Ultra-narrow breakpoint (<=375px) scales avatar to 48px to preserve content space"
);

// ------------------------------------------------------------
// 3. Verifying Account Information & Security Panels Responsiveness
// ------------------------------------------------------------
echo "\n3. Verifying Account Info & Security Panels Responsiveness...\n";

// Field grid collapses to 1 column on mobile
assertTest(
    preg_match('/@media\s*\(max-width:\s*767\.98px\)[^{]*\{[\s\S]*?\.profile-container\s+\.field-grid[\s\S]*?grid-template-columns:\s*1fr\s*!important/s', $appCss) === 1,
    ".field-grid collapses to single column 1fr !important on mobile"
);

// Detail values wrap long content
assertTest(
    preg_match('/@media\s*\(max-width:\s*767\.98px\)[^{]*\{[\s\S]*?\.field-value[\s\S]*?overflow-wrap:\s*anywhere/s', $appCss) === 1,
    ".field-value uses overflow-wrap: anywhere to prevent text clipping"
);

// Monospace values (like email) break properly
assertTest(
    preg_match('/@media\s*\(max-width:\s*767\.98px\)[^{]*\{[\s\S]*?\.field-value\.mono[\s\S]*?word-break:\s*break-all/s', $appCss) === 1,
    ".field-value.mono uses word-break: break-all for long emails and identifiers"
);

// Action buttons are full width and wrap labels
assertTest(
    preg_match('/@media\s*\(max-width:\s*767\.98px\)[^{]*\{[\s\S]*?\.security-card-action\s+\.btn[\s\S]*?width:\s*100%[\s\S]*?white-space:\s*normal/s', $appCss) === 1,
    "Security action buttons (.btn.btn-action) use width: 100% and white-space: normal"
);

// Test assertion string required by profile_security_redesign_test.php is preserved
assertTest(
    strpos($appCss, ".security-card         { flex-direction: column; align-items: stretch; gap: 0.85rem; text-align: left; }") !== false,
    "Exact formatted string required by profile_security_redesign_test.php is intact"
);

// Security cards stack collapses to 1 column
assertTest(
    preg_match('/@media\s*\(max-width:\s*767\.98px\)[^{]*\{[\s\S]*?\.security-cards-stack[\s\S]*?grid-template-columns:\s*1fr\s*!important/s', $appCss) === 1,
    ".security-cards-stack collapses to single column 1fr !important on mobile"
);

// ------------------------------------------------------------
// 4. Verifying Floating VendoAI Button & Chat Safe Areas
// ------------------------------------------------------------
echo "\n4. Verifying Floating Chat & Safe-Area Insets...\n";
$chatCode = file_get_contents($chatFile);

assertTest(
    strpos($chatCode, 'env(safe-area-inset-bottom, 0px)') !== false,
    "Floating chat supports env(safe-area-inset-bottom, 0px) for notched mobile devices"
);

assertTest(
    strpos($chatCode, 'env(safe-area-inset-right, 0px)') !== false,
    "Floating chat supports env(safe-area-inset-right, 0px) for landscape mobile viewports"
);

assertTest(
    preg_match('/@media\s*\(max-width:\s*575\.98px\)[^{]*\{[\s\S]*?#vendoAiFloatBtn\s*\{[^}]*bottom:\s*calc\(16px\s*\+\s*env\(safe-area-inset-bottom/s', $chatCode) === 1,
    "#vendoAiFloatBtn uses safe-area-inset in mobile media query"
);

assertTest(
    preg_match('/@media\s*\(max-width:\s*575\.98px\)[^{]*\{[\s\S]*?\.vendo-ai-chat-box\s*\{[^}]*bottom:\s*calc\(74px\s*\+\s*env\(safe-area-inset-bottom/s', $chatCode) === 1,
    ".vendo-ai-chat-box uses safe-area-inset in mobile media query"
);

// ------------------------------------------------------------
// 5. Verifying HTML Structure in users/profile.php
// ------------------------------------------------------------
echo "\n5. Verifying HTML Structure in users/profile.php...\n";
$profilePhp = file_get_contents($profileFile);

assertTest(
    strpos($profilePhp, 'class="profile-container"') !== false,
    ".profile-container wrapper exists in users/profile.php"
);

assertTest(
    strpos($profilePhp, 'class="panel vl-profile-panel identity-strip mb-4"') !== false,
    "Identity strip header card exists with identity-strip class"
);

assertTest(
    strpos($profilePhp, 'class="identity-info min-w-0 flex-grow-1"') !== false,
    "Identity header info block has min-w-0 and flex-grow-1 for proper flex shrink/wrap"
);

assertTest(
    strpos($profilePhp, 'class="layout profile-page-grid"') !== false,
    "Main layout uses profile-page-grid"
);

assertTest(
    strpos($profilePhp, 'class="field-value profile-detail-value d-flex flex-wrap align-items-center gap-2"') !== false,
    "Email verified row uses flex-wrap and gap-2 to prevent button overflow on narrow screens"
);

assertTest(
    strpos($profilePhp, 'style="word-break:break-all;"') !== false,
    "Email displays have inline word-break:break-all for guaranteed safe wrapping"
);

echo "\n=== TEST RESULTS: {$passedTests} PASSED, " . ($totalTests - $passedTests) . " FAILED (TOTAL: {$totalTests}) ===\n";

if ($passedTests === $totalTests) {
    echo "🎉 ALL PROFILE MOBILE RESPONSIVE TESTS PASSED 100%!\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED.\n";
    exit(1);
}
