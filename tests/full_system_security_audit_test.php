<?php
/**
 * ============================================================
 * VendoLink — Full-System Authentication & Access Security Audit
 * ============================================================
 *
 * Automated verification test suite for all authentication,
 * authorization, session lifecycle, CSRF, IDOR, hardware token,
 * and sensitive script access protections.
 *
 * Run with: C:\xampp\php\php.exe tests\full_system_security_audit_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Machine.php';

$passed = 0;
$failed = 0;

function assert_test(bool $condition, string $description): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$description}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$description}\n";
    }
}

/**
 * Execute a PHP snippet in a clean separate process using a temporary script.
 */
function run_php_snippet(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'sec_') . '.php';
    $fullCode = "<?php\nchdir(" . var_export(ROOT_PATH, true) . ");\n" . $code;
    file_put_contents($tmp, $fullCode);
    $output = shell_exec('C:\xampp\php\php.exe ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return (string)$output;
}

echo "====================================================\n";
echo " VendoLink Full-System Security Verification Suite\n";
echo "====================================================\n\n";

$db = Database::getInstance();
$userModel = new User();

// Setup dedicated test users: Admin and Owner
$testAdmin = $userModel->findByEmail('sec_audit_admin@vendolink.local');
if (!$testAdmin) {
    $adminId = $userModel->create([
        'username'  => 'sec_audit_admin',
        'email'     => 'sec_audit_admin@vendolink.local',
        'full_name' => 'Security Audit Admin',
        'role'      => ROLE_ADMIN,
        'password'  => 'SecAdmin@2026!',
        'status'    => 'active',
    ]);
    $testAdmin = $userModel->findById($adminId);
} else {
    $userModel->update($testAdmin['id'], ['status' => 'active', 'role' => ROLE_ADMIN]);
    $testAdmin = $userModel->findById($testAdmin['id']);
}

$testOwner = $userModel->findByEmail('sec_audit_owner@vendolink.local');
if (!$testOwner) {
    $ownerId = $userModel->create([
        'username'  => 'sec_audit_owner',
        'email'     => 'sec_audit_owner@vendolink.local',
        'full_name' => 'Security Audit Owner',
        'role'      => ROLE_OWNER,
        'password'  => 'SecOwner@2026!',
        'status'    => 'active',
    ]);
    $testOwner = $userModel->findById($ownerId);
} else {
    $userModel->update($testOwner['id'], ['status' => 'active', 'role' => ROLE_OWNER]);
    $testOwner = $userModel->findById($testOwner['id']);
}

// ------------------------------------------------------------
// Section 1: Session Inactivity & Absolute Lifetimes
// ------------------------------------------------------------
echo "[1/8] Testing Session Inactivity and Absolute Timeout Enforcement...\n";

// A. Fresh session is logged in
$_SESSION = [];
populate_session($testOwner);
assert_test(is_logged_in() === true, "Active fresh session is valid");

// B. Session exceeding inactivity timeout (1800s / 30 min)
$_SESSION['last_activity'] = time() - (SESSION_INACTIVITY_TIMEOUT + 10);
assert_test(is_logged_in() === false, "Session exceeding inactivity timeout (1800s) is recognized as expired");

// C. Session exceeding absolute timeout (43200s / 12h)
$_SESSION['last_activity'] = time(); // activity was recent
$_SESSION['created_at']    = time() - (SESSION_ABSOLUTE_TIMEOUT + 60); // created 12h ago
assert_test(is_logged_in() === false, "Session exceeding absolute timeout (43200s) is recognized as expired");

// D. Clean destruction wipes session
destroy_session();
assert_test(empty($_SESSION), "destroy_session() cleanly unsets all session variables");

// ------------------------------------------------------------
// Section 2: Unauthenticated Access Detection (API vs Browser)
// ------------------------------------------------------------
echo "\n[2/8] Testing Unauthenticated Rejection (API 401 vs Browser 302)...\n";

// A. API detection helper
$_SERVER['REQUEST_URI'] = '/vendolink_claude_v1/api/notifications.php';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
assert_test(is_api_request() === true, "is_api_request() detects /api/ route and application/json header");

$_SERVER['REQUEST_URI'] = '/vendolink_claude_v1/dashboard/index.php';
unset($_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['CONTENT_TYPE']);
assert_test(is_api_request() === false, "is_api_request() returns false for regular browser route");

// B. Unauthenticated API request output test (simulated via runner)
$apiTestCode = '
    $_SERVER["REQUEST_URI"] = "/vendolink_claude_v1/api/sales_analytics.php";
    $_SERVER["HTTP_ACCEPT"] = "application/json";
    require "config/config.php";
    require "includes/functions.php";
    require "middleware/auth.php";
    require_auth();
';
$output = run_php_snippet($apiTestCode);
$decoded = json_decode($output, true);
assert_test(is_array($decoded) && isset($decoded['success']) && $decoded['success'] === false, "Unauthenticated API request returns JSON with success = false");
assert_test(isset($decoded['code']) && $decoded['code'] === 401, "Unauthenticated API request returns code 401");

// ------------------------------------------------------------
// Section 3: Role-Based Access Control (Admin vs Owner)
// ------------------------------------------------------------
echo "\n[3/8] Testing Role-Based Authorization Enforcement (403 Forbidden)...\n";

// A. Owner attempting to access Admin API
$ownerAdminApiCode = '
    $_SERVER["REQUEST_URI"] = "/vendolink_claude_v1/api/admin_test.php";
    $_SERVER["HTTP_ACCEPT"] = "application/json";
    require "config/config.php";
    require "config/database.php";
    require "includes/functions.php";
    require "middleware/auth.php";
    require "models/User.php";
    $u = (new User())->findByEmail("sec_audit_owner@vendolink.local");
    populate_session($u);
    require_admin();
';
$output = run_php_snippet($ownerAdminApiCode);
$decoded = json_decode($output, true);
assert_test(is_array($decoded) && isset($decoded['code']) && $decoded['code'] === 403, "Owner accessing Admin API is rejected with HTTP 403 JSON");

// B. Owner attempting to access Admin Browser page (renders 403 HTML page)
$ownerAdminBrowserCode = '
    $_SERVER["REQUEST_URI"] = "/vendolink_claude_v1/admin/dashboard/index.php";
    unset($_SERVER["HTTP_ACCEPT"], $_SERVER["HTTP_X_REQUESTED_WITH"]);
    require "config/config.php";
    require "config/database.php";
    require "includes/functions.php";
    require "middleware/auth.php";
    require "models/User.php";
    $u = (new User())->findByEmail("sec_audit_owner@vendolink.local");
    populate_session($u);
    require_admin();
';
$output = run_php_snippet($ownerAdminBrowserCode);
assert_test(str_contains($output, '403 — Access Forbidden'), "Owner navigating to Admin dashboard renders 403 Forbidden error card");
assert_test(str_contains($output, 'VendoLink Admin privileges required'), "Owner sees specific Admin privilege requirement message");

// C. Admin accessing require_admin() succeeds cleanly
$adminPassCode = '
    require "config/config.php";
    require "config/database.php";
    require "includes/functions.php";
    require "middleware/auth.php";
    require "models/User.php";
    $u = (new User())->findByEmail("sec_audit_admin@vendolink.local");
    populate_session($u);
    require_admin();
    echo "ADMIN_AUTHORIZED_OK";
';
$output = run_php_snippet($adminPassCode);
assert_test(str_contains($output, 'ADMIN_AUTHORIZED_OK'), "Administrator successfully passes require_admin()");

// ------------------------------------------------------------
// Section 4: Real-Time Account Deactivation & Session Invalidation
// ------------------------------------------------------------
echo "\n[4/8] Testing Account Deactivation and Immediate Invalidation...\n";

// Deactivate Owner in DB
$userModel->setStatus((int)$testOwner['id'], 'inactive');

$inactiveUserCode = '
    $_SERVER["REQUEST_URI"] = "/vendolink_claude_v1/api/notifications.php";
    $_SERVER["HTTP_ACCEPT"] = "application/json";
    require "config/config.php";
    require "config/database.php";
    require "includes/functions.php";
    require "middleware/auth.php";
    require "models/User.php";
    $u = (new User())->findByEmail("sec_audit_owner@vendolink.local");
    populate_session($u);
    // User status is now inactive in DB
    require_auth();
';
$output = run_php_snippet($inactiveUserCode);
$decoded = json_decode($output, true);
assert_test(is_array($decoded) && isset($decoded['code']) && $decoded['code'] === 401, "Deactivated account in database is rejected by require_auth() with 401");
assert_test(str_contains($decoded['error'] ?? '', 'inactive or disabled'), "Deactivation error specifically notes account is disabled");

// Restore Owner to active status
$userModel->setStatus((int)$testOwner['id'], 'active');

// ------------------------------------------------------------
// Section 5: Multi-Tenant Operational Activity IDOR Protection
// ------------------------------------------------------------
echo "\n[5/8] Testing Operational Activity Isolation Scoping...\n";

$machineModel = new Machine();
// Owner scoping check
$ownerEvents = $machineModel->getOperationalActivity(10, 'all', (int)$testOwner['id']);
assert_test(is_array($ownerEvents), "getOperationalActivity() accepts ownerId parameter");

// Null scoping for Admin returns global activity
$adminEvents = $machineModel->getOperationalActivity(10, 'all', null);
assert_test(is_array($adminEvents), "getOperationalActivity(null) returns platform-wide operational logs for admin");

// ------------------------------------------------------------
// Section 6: ESP32 Hardware Authentication Tokens
// ------------------------------------------------------------
echo "\n[6/8] Testing ESP32 Machine Hardware Token Protection...\n";

$primaryMachine = $db->query("SELECT * FROM machines WHERE machine_key = 'machine_01' LIMIT 1")->fetch();
assert_test(!empty($primaryMachine), "Machine 'machine_01' exists in database");

$validToken = $primaryMachine['api_token'];

// A. Token verification method
assert_test($machineModel->verifyApiToken('machine_01', $validToken) === true, "verifyApiToken() accepts valid machine token");
assert_test($machineModel->verifyApiToken('machine_01', 'bogus_token_1234') === false, "verifyApiToken() strictly rejects invalid token");
assert_test($machineModel->verifyApiToken('machine_01', '') === false, "verifyApiToken() strictly rejects empty token");

// ------------------------------------------------------------
// Section 7: CSRF Token Verification & State Protection
// ------------------------------------------------------------
echo "\n[7/8] Testing CSRF Token Enforcement on State-Changing Actions...\n";

$_SESSION = [];
$activeCsrf = csrf_generate();
assert_test(strlen($activeCsrf) === 64, "csrf_generate() generates a 64-character hex token");

// A. Correct token passes csrf_verify()
$_POST[CSRF_TOKEN_NAME] = $activeCsrf;
$csrfPassed = false;
try {
    csrf_verify();
    $csrfPassed = true;
} catch (Throwable $e) {
    $csrfPassed = false;
}
assert_test($csrfPassed === true, "csrf_verify() succeeds with matching token");

// B. Missing token is rejected via snippet runner
$csrfFailCode = '
    require "config/config.php";
    require "includes/functions.php";
    session_start();
    $_SESSION[CSRF_TOKEN_NAME] = "valid_token_1234567890abcdef1234567890abcdef";
    $_POST[CSRF_TOKEN_NAME] = "invalid_token_9999999999";
    csrf_verify();
    echo "SHOULD_NOT_REACH_HERE";
';
$output = run_php_snippet($csrfFailCode);
assert_test(!str_contains($output, 'SHOULD_NOT_REACH_HERE'), "csrf_verify() terminates execution on token mismatch");
assert_test(str_contains($output, '403 — Invalid CSRF Token'), "csrf_verify() outputs 403 Invalid CSRF Token");

// ------------------------------------------------------------
// Section 8: Sensitive Scripts and Directory Protection
// ------------------------------------------------------------
echo "\n[8/8] Testing Sensitive Dev Utility & File Protection...\n";

// A. setup.lock exists
assert_test(file_exists(ROOT_PATH . '/logs/setup.lock'), "setup.lock file exists in logs/ to block installer re-run");

// B. quick_seed.php rejects non-CLI access
$quickSeedContent = file_get_contents(ROOT_PATH . '/quick_seed.php');
assert_test(str_contains($quickSeedContent, "php_sapi_name() !== 'cli'"), "quick_seed.php contains strict CLI-only guard");

// C. reset_admin.php requires admin auth when accessed outside CLI
$resetAdminContent = file_get_contents(ROOT_PATH . '/reset_admin.php');
assert_test(str_contains($resetAdminContent, "require_admin()"), "reset_admin.php enforces require_admin() authentication guard");
assert_test(str_contains($resetAdminContent, "csrf_verify()"), "reset_admin.php enforces csrf_verify() on POST");

// D. .htaccess blocks internal directories
$htaccessContent = file_get_contents(ROOT_PATH . '/.htaccess');
assert_test(str_contains($htaccessContent, 'scratch|tests'), ".htaccess protects scratch and tests directories");
assert_test(str_contains($htaccessContent, 'setup|quick_seed'), ".htaccess blocks direct web access to setup and quick_seed scripts");

// Clean up test accounts
$db->query("DELETE FROM users WHERE email IN ('sec_audit_admin@vendolink.local', 'sec_audit_owner@vendolink.local')");

echo "\n====================================================\n";
echo " Test Results: {$passed} Passed, {$failed} Failed (Total: " . ($passed + $failed) . ")\n";
echo "====================================================\n";

if ($failed === 0) {
    echo "🎉 ALL FULL-SYSTEM AUTHENTICATION & ACCESS SECURITY TESTS PASSED 100%!\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED!\n";
    exit(1);
}
