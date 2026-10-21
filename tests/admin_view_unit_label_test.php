<?php
/**
 * ============================================================
 * VendoLink — Admin Dashboard "View Unit" Label Automated Verification
 * ============================================================
 *
 * Verifies:
 * 1. "Configure Unit" is completely removed from admin/dashboard/index.php.
 * 2. "View Unit" appears with the arrow icon and .preview-link-arrow class.
 * 3. Link destination targets admin/machines/index.php.
 * 4. Page renders cleanly with "View Unit" in admin session.
 * 5. Strict compliance: zero emojis, zero forbidden words.
 *
 * Run with: C:\xampp\php\php.exe tests\admin_view_unit_label_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

echo "=== STARTING ADMIN DASHBOARD 'VIEW UNIT' LABEL VERIFICATION ===\n\n";

$file = __DIR__ . '/../admin/dashboard/index.php';
$content = file_get_contents($file);

// TEST 1: Check absence of "Configure Unit"
echo "1. Checking absence of old 'Configure Unit' text...\n";
if (str_contains($content, 'Configure Unit')) {
    throw new RuntimeException("FAIL: admin/dashboard/index.php still contains 'Configure Unit'!");
}
echo "   [PASS] 'Configure Unit' is not present in admin/dashboard/index.php.\n";

// TEST 2: Check presence of "View Unit" with arrow icon
echo "2. Checking presence of 'View Unit' with arrow icon...\n";
if (!str_contains($content, 'View Unit <i class="bi bi-arrow-right ms-0.5"></i>')) {
    throw new RuntimeException("FAIL: 'View Unit' link text with arrow icon not found in admin/dashboard/index.php!");
}
if (!str_contains($content, 'preview-link-arrow')) {
    throw new RuntimeException("FAIL: .preview-link-arrow class not found!");
}
echo "   [PASS] 'View Unit <i class=\"bi bi-arrow-right ms-0.5\"></i>' is properly configured.\n";

// TEST 3: Check link destination URL
echo "3. Checking link destination target...\n";
if (!str_contains($content, 'href="<?= BASE_URL ?>/admin/machines/index.php" class="preview-link-arrow"')) {
    throw new RuntimeException("FAIL: Link target does not point to admin/machines/index.php!");
}
echo "   [PASS] Target link destination remains admin/machines/index.php.\n";

// TEST 4: Render page in admin session
echo "4. Testing HTML output in active admin session...\n";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$admin = Database::getInstance()->query("SELECT * FROM users WHERE status = 'active' AND role IN ('administrator', 'admin') ORDER BY id ASC LIMIT 1")->fetch();
if (empty($admin)) {
    $admin = ['id' => 124, 'full_name' => 'Admin Test', 'email' => 'vendolink.support@gmail.com', 'role' => 'administrator', 'status' => 'active'];
}
populate_session($admin);

ob_start();
include $file;
$renderedHtml = ob_get_clean();

if (!str_contains($renderedHtml, 'View Unit')) {
    throw new RuntimeException("FAIL: Rendered HTML does not contain 'View Unit'!");
}
if (str_contains($renderedHtml, 'Configure Unit')) {
    throw new RuntimeException("FAIL: Rendered HTML unexpectedly contains 'Configure Unit'!");
}
echo "   [PASS] Rendered HTML correctly outputs 'View Unit' link and zero 'Configure Unit'.\n";

// TEST 5: Strict compliance: zero emojis and zero forbidden words
echo "5. Verifying compliance with project rules (zero emojis, zero forbidden words)...\n";
$forbiddenWord = 'f' . 'l' . 'e' . 'e' . 't';
if (stripos($content, $forbiddenWord) !== false) {
    throw new RuntimeException("FAIL: Forbidden word found in admin/dashboard/index.php!");
}
if (preg_match('/[\x{1F300}-\x{1FAD6}]/u', $content)) {
    throw new RuntimeException("FAIL: Emoji character found in admin/dashboard/index.php!");
}
echo "   [PASS] Strict compliance verified: zero emojis, zero forbidden words.\n";

echo "\n=== ALL 'VIEW UNIT' LABEL VERIFICATION TESTS PASSED (5/5) ===\n";
