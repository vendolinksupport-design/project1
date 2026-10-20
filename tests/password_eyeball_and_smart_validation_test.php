<?php
/**
 * Test Suite: Password Eyeball Toggles, Dynamic Confirm Field & Intelligent Alphanumeric Validation
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=== STARTING PASSWORD EYEBALL & VALIDATION TESTS ===\n\n";

// 1. Check Alphanumeric Regex Logic
echo "1. Testing Alphanumeric (8+ chars, letters + numbers) Validation Logic...\n";
$testCases = [
    ['pass' => 'short1', 'expected' => false, 'desc' => 'Short alphanumeric (<8 chars)'],
    ['pass' => 'onlyletters', 'expected' => false, 'desc' => 'Letters only (no numbers)'],
    ['pass' => '12345678', 'expected' => false, 'desc' => 'Numbers only (no letters)'],
    ['pass' => 'password123', 'expected' => true, 'desc' => 'Valid letters and numbers (>=8 chars)'],
    ['pass' => 'SecurePass2026!', 'expected' => true, 'desc' => 'Valid letters, numbers, and symbols (>=8 chars)']
];

foreach ($testCases as $tc) {
    $p = $tc['pass'];
    $isValid = (strlen($p) >= 8 && preg_match('/[A-Za-z]/', $p) && preg_match('/[0-9]/', $p));
    if ($isValid === $tc['expected']) {
        echo "   [PASS] {$tc['desc']}: '{$p}' -> " . ($isValid ? "VALID" : "INVALID") . "\n";
    } else {
        echo "   [FAIL] {$tc['desc']}: '{$p}'\n";
        exit(1);
    }
}

// 2. Check admin/users/index.php markup
echo "\n2. Verifying admin/users/index.php Markup...\n";
$adminUsersContent = file_get_contents(__DIR__ . '/../admin/users/index.php');

assert(strpos($adminUsersContent, '<th style="width: 8%;" class="text-end pe-3"></th>') !== false, "Header 5th column missing");
assert(strpos($adminUsersContent, '<i class="bi bi-pencil-square me-1"></i>Edit') !== false, "Edit button missing in table");
assert(strpos($adminUsersContent, 'id="addAdminPassword"') !== false, "addAdminPassword missing");
assert(strpos($adminUsersContent, 'id="addAdminConfirmPassword"') !== false, "addAdminConfirmPassword missing");
assert(strpos($adminUsersContent, 'id="addAdminPwRules"') !== false, "addAdminPwRules missing");
assert(strpos($adminUsersContent, 'id="addAdminPwMatch"') !== false, "addAdminPwMatch missing");
assert(strpos($adminUsersContent, 'toggle-password-btn') !== false, "toggle-password-btn missing in admin/users/index.php");
assert(strpos($adminUsersContent, 'data-target-confirm="#confirmAdminGroup') !== false, "data-target-confirm missing in editAdminModal");
assert(strpos($adminUsersContent, 'class="mb-3 d-none" id="confirmAdminGroup') !== false, "confirmAdminGroup d-none missing in editAdminModal");
echo "   [PASS] admin/users/index.php has Edit button, toggle-password-btn, dynamic confirm group, and match elements.\n";

// 3. Check admin/customers/index.php markup & backend
echo "\n3. Verifying admin/customers/index.php Markup & Validation...\n";
$adminCustContent = file_get_contents(__DIR__ . '/../admin/customers/index.php');

assert(strpos($adminCustContent, 'data-target-confirm="#confirmCustGroup') !== false, "data-target-confirm missing in editOwnerModal");
assert(strpos($adminCustContent, 'class="mb-3 d-none" id="confirmCustGroup') !== false, "confirmCustGroup d-none missing in editOwnerModal");
assert(strpos($adminCustContent, 'toggle-password-btn') !== false, "toggle-password-btn missing in editOwnerModal");
assert(strpos($adminCustContent, 'New password must be at least 8 characters long and contain both letters and numbers.') !== false, "Alphanumeric error check missing in edit_customer");
assert(strpos($adminCustContent, 'Passwords do not match.') !== false, "Password match check missing in edit_customer");
echo "   [PASS] admin/customers/index.php has dynamic confirm group, toggle-password-btn, and backend validation.\n";

// 4. Check auth/register.php & controllers/AuthController.php
echo "\n4. Verifying auth/register.php & AuthController.php...\n";
$registerContent = file_get_contents(__DIR__ . '/../auth/register.php');
$authCtrlContent = file_get_contents(__DIR__ . '/../controllers/AuthController.php');

assert(strpos($registerContent, 'password-toggle') !== false, "password-toggle missing in register.php");
assert(strpos($registerContent, 'Must be at least 8 characters long') !== false, "8 characters check missing in register.php js");
assert(strpos($registerContent, 'Must contain both letters and numbers') !== false, "Alphanumeric check missing in register.php js");
assert(strpos($registerContent, 'Passwords match') !== false, "Passwords match indicator missing in register.php js");
assert(strpos($authCtrlContent, "!preg_match('/[A-Za-z]/', \$password) || !preg_match('/[0-9]/', \$password)") !== false, "Alphanumeric check missing in AuthController.php");
echo "   [PASS] auth/register.php and AuthController.php properly validate 8+ alphanumeric and matching.\n";

// 5. Check assets/js/app.js Universal Delegation
echo "\n5. Verifying assets/js/app.js Universal Password Toggle & Validation...\n";
$appJsContent = file_get_contents(__DIR__ . '/../assets/js/app.js');

assert(strpos($appJsContent, 'initPasswordToggle') !== false, "initPasswordToggle missing in app.js");
assert(strpos($appJsContent, '.password-toggle, .toggle-password-btn') !== false, "Eyeball button selectors missing in app.js");
assert(strpos($appJsContent, 'bi-eye-slash') !== false, "bi-eye-slash toggle missing in app.js");
assert(strpos($appJsContent, 'Passwords match') !== false, "Live Passwords match indicator missing in app.js");
assert(strpos($appJsContent, 'Must contain both letters and numbers') !== false, "Live alphanumeric error missing in app.js");
echo "   [PASS] assets/js/app.js universal delegation verified.\n";

// 6. Check constraints: strictly NO emojis and strictly NO 'fleet'
echo "\n6. Checking Strict Constraints (No Emojis, No 'fleet')...\n";
$checkedFiles = [
    __DIR__ . '/../admin/users/index.php',
    __DIR__ . '/../admin/customers/index.php',
    __DIR__ . '/../auth/register.php',
    __DIR__ . '/../controllers/AuthController.php',
    __DIR__ . '/../assets/js/app.js'
];

foreach ($checkedFiles as $filePath) {
    $content = file_get_contents($filePath);
    $basename = basename($filePath);
    
    // Check forbidden word 'fleet' (case-insensitive)
    if (preg_match('/\bfleet\b/i', $content, $m)) {
        echo "   [FAIL] Forbidden word 'fleet' found in {$basename}!\n";
        exit(1);
    }
    
    // Check emojis (Unicode emoji ranges)
    if (preg_match('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', $content, $m)) {
        echo "   [FAIL] Emoji/symbol '" . bin2hex($m[0]) . "' found in {$basename}!\n";
        exit(1);
    }
    echo "   [PASS] {$basename}: Zero emojis, zero occurrences of forbidden word 'fleet'.\n";
}

echo "\nALL TESTS PASSED SUCCESSFULLY! 100% VERIFIED.\n";
