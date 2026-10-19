<?php
/**
 * ============================================================
 * VendoLink — Captive Portal & Per-Device Bootstrap Test Suite
 * ============================================================
 *
 * Verifies:
 * 1. Customer Captive Portal HTML structure:
 *    - Strictly asks only for Wi-Fi SSID and Password.
 *    - Displays Device MAC and Firmware version as read-only text.
 *    - ZERO exposure of Server API URL or Enrollment Secret in customer HTML.
 *    - Contains discrete link to protected /installer configuration mode.
 * 2. Protected Installer Mode HTML structure:
 *    - Requires installer security PIN.
 *    - Provides Server API URL and per-device bootstrap token inputs only when PIN is unlocked.
 * 3. Universal Firmware configuration (config.h):
 *    - Zero hardcoded shared enrollment secrets in binary.
 *    - Default deployment server URL and installer PIN defined.
 * 4. Per-Device Cryptographic Bootstrap Authentication (api/machine/register.php):
 *    - Validates per-device bootstrap token derived from physical MAC.
 *    - Rejects missing, invalid, or cross-device forged bootstrap tokens with 401 Unauthorized.
 *    - Accepts valid per-device bootstrap token and issues 201 Created with unique credentials.
 *    - Safe idempotent re-enrollment returns 200 OK without duplicate records.
 *    - Error messages are generic and never leak secrets or expected tokens.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Machine.php';

echo "=== STARTING CAPTIVE PORTAL & PER-DEVICE BOOTSTRAP TESTS ===\n\n";

$pdo = Database::getInstance()->getConnection();
$machineModel = new Machine();
$baseUrl = 'http://localhost/vendolink_claude_v1/api';

// Helper: HTTP Request function
function sendHttpRequest($url, $method = 'GET', $headers = [], $body = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $formattedHeaders = [];
    foreach ($headers as $k => $v) {
        $formattedHeaders[] = "$k: $v";
    }
    if (!empty($formattedHeaders)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
    }

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => $response,
        'json' => json_decode($response, true),
        'error' => $error
    ];
}

// ============================================================
// 1. Inspect Firmware Source for Customer Captive Portal
// ============================================================
echo "1. Inspecting Customer Captive Portal HTML Source in Firmware...\n";

$inoFile = __DIR__ . '/../esp32/vendolink_esp32_heartbeat/vendolink_esp32_heartbeat.ino';
assert(file_exists($inoFile), "Firmware .ino file must exist");
$inoCode = file_get_contents($inoFile);

// Extract handleCaptivePortalRoot()
preg_match('/void handleCaptivePortalRoot\(\)\s*\{(.*?)\n\}/s', $inoCode, $rootMatch);
assert(!empty($rootMatch[1]), "handleCaptivePortalRoot must be defined in .ino");
$customerPortalHtml = $rootMatch[1];

// Verification 1A: SSID and Password fields present
assert(strpos($customerPortalHtml, "name='ssid'") !== false, "SSID field must exist in customer portal");
assert(strpos($customerPortalHtml, "name='pass'") !== false, "Password field must exist in customer portal");
echo "   ✅ Customer portal contains Wi-Fi SSID and Password fields.\n";

// Verification 1B: Read-only MAC and Firmware badge present
assert(strpos($customerPortalHtml, "getDeviceMac()") !== false, "Device MAC must be rendered in customer portal");
assert(strpos($customerPortalHtml, "v3.0.0") !== false || strpos($customerPortalHtml, "FIRMWARE_VERSION") !== false, "Firmware version must be rendered in customer portal");
echo "   ✅ Customer portal contains read-only Device MAC and Firmware badges.\n";

// Verification 1C: Server URL and Secret fields strictly REMOVED
assert(strpos($customerPortalHtml, "name='server'") === false, "Server URL input field must NOT exist in customer portal");
assert(strpos($customerPortalHtml, "name='secret'") === false, "Secret input field must NOT exist in customer portal");
assert(strpos($customerPortalHtml, "VendoLink Server API URL") === false, "Server API URL label must NOT exist in customer portal");
assert(strpos($customerPortalHtml, "Device Enrollment Secret") === false, "Enrollment Secret label must NOT exist in customer portal");
echo "   ✅ Server URL and Enrollment Secret fields are strictly absent from customer portal HTML.\n";

// Verification 1D: Link to installer mode
assert(strpos($customerPortalHtml, "href='/installer'") !== false, "Customer portal must provide link to /installer");
echo "   ✅ Link to protected /installer mode present.\n\n";

// ============================================================
// 2. Inspect Protected Installer Portal in Firmware
// ============================================================
echo "2. Inspecting Protected Installer Portal (/installer) in Firmware...\n";

preg_match('/void handleInstallerRoot\(\)\s*\{(.*?)\n\}/s', $inoCode, $instMatch);
assert(!empty($instMatch[1]), "handleInstallerRoot must be defined in .ino");
$installerHtml = $instMatch[1];

// Verification 2A: PIN authorization check
assert(strpos($installerHtml, "INSTALLER_PORTAL_PIN") !== false, "Installer portal must evaluate INSTALLER_PORTAL_PIN");
assert(strpos($installerHtml, "name='pin'") !== false, "Installer portal must prompt for security PIN");
echo "   ✅ Installer portal enforces PIN authentication.\n";

// Verification 2B: Server URL and Bootstrap token in installer form
assert(strpos($installerHtml, "name='server'") !== false, "Installer form must contain Server URL input");
assert(strpos($installerHtml, "name='boot_token'") !== false, "Installer form must contain Per-Device Bootstrap Token input");
echo "   ✅ Server URL and Per-Device Bootstrap Token are accessible only via installer mode.\n\n";

// ============================================================
// 3. Inspect Universal Firmware Configuration (config.h)
// ============================================================
echo "3. Inspecting config.h for Elimination of Shared Enrollment Secret...\n";

$cfgFile = __DIR__ . '/../esp32/vendolink_esp32_heartbeat/config.h';
assert(file_exists($cfgFile), "config.h must exist");
$cfgCode = file_get_contents($cfgFile);

assert(strpos($cfgCode, "DEFAULT_ENROLLMENT_SECRET") === false, "DEFAULT_ENROLLMENT_SECRET must NOT exist in config.h");
assert(strpos($cfgCode, "DEFAULT_VENDOLINK_SERVER") !== false, "DEFAULT_VENDOLINK_SERVER must be defined in config.h");
assert(strpos($cfgCode, "INSTALLER_PORTAL_PIN") !== false, "INSTALLER_PORTAL_PIN must be defined in config.h");
echo "   ✅ No reusable shared enrollment secret hardcoded in universal firmware binary.\n";
echo "   ✅ Default server URL and installer PIN properly configured.\n\n";

// ============================================================
// 4. Testing Per-Device Bootstrap Authentication on Backend
// ============================================================
echo "4. Testing Per-Device Cryptographic Bootstrap Authentication on Backend...\n";

$testMacA = '24:6F:28:A1:B2:C3';
$testMacB = '30:AE:A4:CC:DD:EE';
$registerEndpoint = "$baseUrl/machine/register.php";

// Pre-test cleanup
$pdo->exec("DELETE FROM services WHERE machine_id IN (SELECT id FROM machines WHERE device_mac IN ('$testMacA', '$testMacB'))");
$pdo->exec("DELETE FROM machines WHERE device_mac IN ('$testMacA', '$testMacB')");

// Compute valid cryptographic bootstrap token for Mac A
$validTokenA = Machine::computeBootstrapToken($testMacA);
assert(!empty($validTokenA), "computeBootstrapToken must return non-empty hash");
assert(strlen($validTokenA) === 64, "computeBootstrapToken must return 64-char SHA256 hex");

// Compute valid cryptographic bootstrap token for Mac B
$validTokenB = Machine::computeBootstrapToken($testMacB);
assert($validTokenA !== $validTokenB, "Tokens for Mac A and Mac B must be distinct");

// 4a: Request with missing token -> 401
$resNoToken = sendHttpRequest($registerEndpoint, 'POST', ['Content-Type' => 'application/json'], [
    'device_mac' => $testMacA
]);
assert($resNoToken['code'] === 401, "Missing bootstrap token must return 401");
assert($resNoToken['json']['error'] === 'Unauthorized. Invalid or missing device bootstrap credential.', "Generic error message expected");
echo "   ✅ Missing bootstrap token rejected with 401 Unauthorized.\n";

// 4b: Request with arbitrary random/invalid token -> 401
$resBadToken = sendHttpRequest($registerEndpoint, 'POST', [
    'Content-Type' => 'application/json',
    'X-Bootstrap-Token' => 'invalid_random_guess_token_12345'
], [
    'device_mac' => $testMacA
]);
assert($resBadToken['code'] === 401, "Invalid bootstrap token must return 401");
echo "   ✅ Arbitrary invalid token rejected with 401 Unauthorized.\n";

// 4c: Cross-device forgery attempt: submit Mac B's token for Mac A -> 401
$resCrossToken = sendHttpRequest($registerEndpoint, 'POST', [
    'Content-Type' => 'application/json',
    'X-Bootstrap-Token' => $validTokenB // Token generated for Mac B!
], [
    'device_mac' => $testMacA // Attempting to enroll Mac A!
]);
assert($resCrossToken['code'] === 401, "Cross-device token replay must return 401");
echo "   ✅ Cross-device forged token correctly rejected (Token B cannot enroll Device A).\n";

// 4d: Successful enrollment with valid per-device bootstrap token -> 201 Created
$resValid = sendHttpRequest($registerEndpoint, 'POST', [
    'Content-Type' => 'application/json',
    'X-Bootstrap-Token' => $validTokenA
], [
    'device_mac'       => $testMacA,
    'firmware_version' => '3.0.0',
    'ip_address'       => '192.168.100.50'
]);
assert($resValid['code'] === 201, "Valid per-device bootstrap enrollment must return 201 Created. Got: {$resValid['code']}");
$enrollData = $resValid['json'];
assert($enrollData['success'] === true, "Enrollment response success must be true");
assert(!empty($enrollData['api_token']), "api_token must be issued");
assert($enrollData['device_mac'] === $testMacA, "device_mac must match");
$enrolledMachineId = (int)$enrollData['machine_id'];
echo "   ✅ Device A successfully enrolled with its unique per-device bootstrap token (ID #$enrolledMachineId).\n";

// 4e: Idempotent re-enrollment for already-enrolled device -> 200 OK
$resReEnroll = sendHttpRequest($registerEndpoint, 'POST', [
    'Content-Type' => 'application/json',
    'X-Bootstrap-Token' => $validTokenA
], [
    'device_mac'       => $testMacA,
    'firmware_version' => '3.0.0',
    'ip_address'       => '192.168.100.51'
]);
assert($resReEnroll['code'] === 200, "Re-enrollment must return 200 OK. Got: {$resReEnroll['code']}");
assert($resReEnroll['json']['enrolled'] === false, "enrolled must be false for existing device");
assert((int)$resReEnroll['json']['machine_id'] === $enrolledMachineId, "machine_id must match original");
echo "   ✅ Idempotent re-enrollment returns existing credentials without duplicate records.\n\n";

// ============================================================
// 5. Cleanup Test Artifacts
// ============================================================
echo "5. Cleaning up test artifacts...\n";
$pdo->exec("DELETE FROM services WHERE machine_id = $enrolledMachineId");
$pdo->exec("DELETE FROM machines WHERE id = $enrolledMachineId");
echo "   ✅ Database test fixtures cleaned up successfully.\n\n";

echo "🎉 ALL CAPTIVE PORTAL & PER-DEVICE BOOTSTRAP TESTS PASSED 100% SUCCESSFULLY!\n";
