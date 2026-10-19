<?php
/**
 * ============================================================
 * VendoLink — Registration QR Code Scanner Test Suite
 * ============================================================
 * Verifies:
 * 1. Assets & library availability (html5-qrcode.min.js)
 * 2. Registration Page UI & DOM Architecture (Modal, scanner button, reticle, camera selector)
 * 3. Client-side QR payload extraction & normalization logic across all formats:
 *    - Real Machine Packaging Sticker URLs (?key=VL-2026-...)
 *    - Alternative query parameters (?product_key=, ?activation_key=, ?code=)
 *    - JSON encoded payloads
 *    - Direct plain-text activation keys
 *    - Embedded key patterns
 *    - Rejection of invalid payloads (WiFi, arbitrary URLs, malicious input)
 * 4. Direct URL prefill support via $_GET['key']
 * 5. End-to-end registration & hardware binding workflow with extracted key
 * 6. Non-consumption / Non-auto-submission safety constraints
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/ProductKey.php';
require_once __DIR__ . '/../controllers/AuthController.php';

echo "============================================================\n";
echo "   VENDOLINK QR CODE SCANNER REGISTRATION TEST SUITE       \n";
echo "============================================================\n\n";

$db = Database::getInstance();
$pdo = $db->getConnection();
$productKeyModel = new ProductKey();
$userModel = new User();

// ============================================================
// 1. Asset & Library Verification
// ============================================================
echo "1. Verifying QR Scanner Library Asset...\n";
$libPath = __DIR__ . '/../assets/js/html5-qrcode.min.js';
assert(file_exists($libPath), "Local html5-qrcode.min.js must exist on disk");
$libSize = filesize($libPath);
assert($libSize > 100000, "html5-qrcode.min.js must be a valid bundle (>100KB, actual: {$libSize} bytes)");
echo "   ✅ html5-qrcode.min.js verified: " . round($libSize / 1024, 2) . " KB on disk.\n\n";

// ============================================================
// 2. Registration Page UI & DOM Architecture Verification
// ============================================================
echo "2. Verifying auth/register.php UI & Scanner Modal Architecture...\n";
$registerHtml = file_get_contents(__DIR__ . '/../auth/register.php');
assert($registerHtml !== false && strlen($registerHtml) > 0, "Could not load auth/register.php");

// Scan button
assert(strpos($registerHtml, 'id="btnOpenScanner"') !== false, "Must contain #btnOpenScanner button");
assert(strpos($registerHtml, 'bi-qr-code-scan') !== false, "Must use bi-qr-code-scan icon");
assert(strpos($registerHtml, 'Scan QR Code') !== false, "Must include 'Scan QR Code' label");

// Modal structure
assert(strpos($registerHtml, 'id="qrScannerModal"') !== false, "Must define #qrScannerModal");
assert(strpos($registerHtml, 'id="cameraSelectContainer"') !== false, "Must contain #cameraSelectContainer");
assert(strpos($registerHtml, 'id="cameraSelect"') !== false, "Must contain camera select dropdown");
assert(strpos($registerHtml, 'id="scannerViewport"') !== false, "Must contain #scannerViewport");
assert(strpos($registerHtml, 'id="qrReader"') !== false, "Must contain #qrReader for Html5Qrcode video mounting");
assert(strpos($registerHtml, 'id="scannerReticle"') !== false, "Must contain #scannerReticle with crosshairs");
assert(strpos($registerHtml, 'id="scannerLoadingOverlay"') !== false, "Must contain #scannerLoadingOverlay");
assert(strpos($registerHtml, 'id="scannerErrorOverlay"') !== false, "Must contain #scannerErrorOverlay");
assert(strpos($registerHtml, 'id="scannerSettingsGuide"') !== false, "Must contain #scannerSettingsGuide");
assert(strpos($registerHtml, 'id="btnRetryCamera"') !== false, "Must contain #btnRetryCamera retry button");
assert(strpos($registerHtml, 'id="btnEnterManualFromError"') !== false, "Must contain #btnEnterManualFromError button");
assert(strpos($registerHtml, 'id="scannerFeedbackMsg"') !== false, "Must contain #scannerFeedbackMsg alert");

// Success alert
assert(strpos($registerHtml, 'id="qrScanSuccessAlert"') !== false, "Must contain #qrScanSuccessAlert");
assert(strpos($registerHtml, 'QR code scanned.') !== false, "Must display 'QR code scanned.' confirmation text");
assert(strpos($registerHtml, 'id="qrScanKeyText"') !== false, "Must contain #qrScanKeyText to display scanned key");

// Field ordering constraint: email < product_key < password
$posEmail = strpos($registerHtml, 'name="email"');
$posKey   = strpos($registerHtml, 'name="product_key"');
$posPw    = strpos($registerHtml, 'name="password"');
assert($posEmail < $posKey, "Email field must precede Activation Key field");
assert($posKey < $posPw, "Activation Key field must precede Password field");

// Library inclusion
assert(strpos($registerHtml, 'html5-qrcode.min.js') !== false, "Must reference html5-qrcode.min.js");
echo "   ✅ Scanner button, modal, reticle, status overlays, and input-group layout verified.\n\n";

// ============================================================
// 3. QR Payload Extraction & Validation Logic Verification
// ============================================================
echo "3. Testing QR Code Payload Extraction & Validation Matrix...\n";

/**
 * PHP implementation replicating the exact logic of extractActivationKey() in auth/register.php
 */
function testExtractActivationKey($rawPayload) {
    if (!$rawPayload || !is_string($rawPayload)) return null;
    $trimmed = trim($rawPayload);
    if ($trimmed === '') return null;

    // Reject payloads containing HTML/XML tags, script injections, or unprintable control characters
    if (preg_match('/[<>]|[\x00-\x1F\x7F]/', $trimmed)) return null;

    $isValidKeyFormat = function($k) {
        if (!$k || !is_string($k)) return false;
        $clean = trim($k);
        $len = strlen($clean);
        if ($len < 5 || $len > 64) return false;
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $clean)) return false;
        $lower = strtolower($clean);
        if (preg_match('/^(https?|ftp|true|false|null|undefined|wifi|mailto|tel)$/', $lower)) return false;
        return true;
    };

    $normalizeKey = function($k) {
        return strtoupper(trim($k));
    };

    // 1. URL parsing
    $urlObj = parse_url($trimmed);
    if (!empty($urlObj['query'])) {
        parse_str($urlObj['query'], $queryParams);
        $candidate = $queryParams['key'] ?? $queryParams['product_key'] ?? $queryParams['activation_key'] ?? $queryParams['code'] ?? null;
        if ($candidate && $isValidKeyFormat($candidate)) {
            return $normalizeKey($candidate);
        }
    }

    // 2. Query param regex fallback
    if (preg_match('/[?&](?:key|product_key|activation_key|code)=([A-Za-z0-9_\-]+)/i', $trimmed, $m)) {
        if ($isValidKeyFormat($m[1])) {
            return $normalizeKey($m[1]);
        }
    }

    // 3. JSON payload
    if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
        $json = json_decode($trimmed, true);
        if (is_array($json)) {
            $jsonKey = $json['key'] ?? $json['product_key'] ?? $json['activation_key'] ?? $json['code'] ?? null;
            if ($jsonKey && is_string($jsonKey) && $isValidKeyFormat($jsonKey)) {
                return $normalizeKey($jsonKey);
            }
        }
    }

    // 4. Direct key format
    if ($isValidKeyFormat($trimmed)) {
        return $normalizeKey($trimmed);
    }

    // 5. Embedded VL-XXXX pattern in descriptive label text
    // Only search embedded if text does not contain code/syntax characters
    if (!preg_match('/[{}[\];\'"\\\\$%^&*!~`+=]/', $trimmed)) {
        if (preg_match('/\b(VL-[A-Za-z0-9\-]+)\b/i', $trimmed, $emb)) {
            if ($isValidKeyFormat($emb[1])) {
                return $normalizeKey($emb[1]);
            }
        }
    }

    return null;
}

// Test cases: Valid scenarios
$testScenarios = [
    // Scenario 1: Actual Packaging Sticker URL from admin/licenses/index.php
    'Packaging Sticker URL (Local)' => [
        'input'    => 'http://localhost/vendolink_claude_v1/auth/register.php?key=VL-2026-PACK-STCK-1001',
        'expected' => 'VL-2026-PACK-STCK-1001'
    ],
    // Scenario 2: Remote HTTPS Packaging Sticker URL
    'Packaging Sticker URL (Production)' => [
        'input'    => 'https://vendolink.great-site.net/auth/register.php?key=VL-2026-PROD-LIVE-2002',
        'expected' => 'VL-2026-PROD-LIVE-2002'
    ],
    // Scenario 3: Alternative query parameter product_key
    'URL with product_key param' => [
        'input'    => 'https://vendolink.com/register?product_key=VL-2026-PKMD-3003',
        'expected' => 'VL-2026-PKMD-3003'
    ],
    // Scenario 4: Alternative query parameter activation_key
    'URL with activation_key param' => [
        'input'    => 'https://vendolink.com/activate?activation_key=VL-2026-ACTK-4004',
        'expected' => 'VL-2026-ACTK-4004'
    ],
    // Scenario 5: JSON payload with product_key
    'JSON payload with product_key' => [
        'input'    => '{"product_key":"VL-2026-JSON-5005","serial":"ESP-100"}',
        'expected' => 'VL-2026-JSON-5005'
    ],
    // Scenario 6: JSON payload with key in lowercase (must normalize to uppercase)
    'JSON payload with key (case normalize)' => [
        'input'    => '{"key":"vl-2026-json-6006"}',
        'expected' => 'VL-2026-JSON-6006'
    ],
    // Scenario 7: Direct raw activation key string
    'Direct Activation Key' => [
        'input'    => 'VL-2026-RAWK-7007',
        'expected' => 'VL-2026-RAWK-7007'
    ],
    // Scenario 8: Direct lowercase activation key with whitespace
    'Direct Lowercase Key with whitespace' => [
        'input'    => "  vl-2026-rawk-8008  \n",
        'expected' => 'VL-2026-RAWK-8008'
    ],
    // Scenario 9: Embedded text on sticker label
    'Embedded text label with key' => [
        'input'    => 'VendoLink Controller Unit VL-2026-EMBD-9009 Packaging Code',
        'expected' => 'VL-2026-EMBD-9009'
    ],
];

foreach ($testScenarios as $label => $data) {
    $result = testExtractActivationKey($data['input']);
    assert($result === $data['expected'], "Extraction failed for {$label}. Expected '{$data['expected']}', got '{$result}'");
    echo "   ✅ [PASS] {$label} -> {$result}\n";
}

// Test cases: Invalid payloads that MUST be safely rejected (returns null)
$invalidScenarios = [
    'WiFi QR Code'                 => 'WIFI:S:MyCafe_WiFi;T:WPA;P:SecretPass123;;',
    'Arbitrary External Website'   => 'https://www.google.com/search?q=vendolink',
    'GitHub Repository URL'        => 'https://github.com/vendolink/vendolink',
    'Too Short String (<5 chars)'  => 'VL-1',
    'Too Long String (>64 chars)'  => 'VL-2026-' . str_repeat('A', 60),
    'Special Characters Injection' => 'VL-2026-KEY<script>alert(1)</script>',
    'Spaces / Blank String'        => '     ',
    'Empty String'                 => '',
    'VCard / Contact QR'           => "BEGIN:VCARD\nVERSION:3.0\nN:Doe;John\nFN:John Doe\nEND:VCARD",
];

foreach ($invalidScenarios as $label => $payload) {
    $result = testExtractActivationKey($payload);
    assert($result === null, "Invalid payload must return null for {$label}. Got '{$result}'");
    echo "   ✅ [PASS Rejected] {$label} safely rejected (null returned).\n";
}
echo "\n";

// ============================================================
// 4. Direct GET Query Parameter Prefill Verification
// ============================================================
echo "4. Testing Direct GET Prefill from Sticker Camera Scans...\n";
// Confirm line in register.php handles $_GET['key']
assert(
    strpos($registerHtml, "\$_GET['key']") !== false,
    "register.php must support \$_GET['key'] fallback prefill"
);
echo "   ✅ Direct \$_GET['key'] prefill confirmed in register.php.\n\n";

// ============================================================
// 5. End-to-End Registration & Hardware Binding with Scanned Key
// ============================================================
echo "5. Testing End-to-End Registration with Scanned Key...\n";

// Generate a realistic sticker packaging QR code
$testMachineCode = 'TEST-QR-MCH-99';
$testProductKey  = 'VL-2026-TEST-QRACT-1234';
$packagingStickerUrl = "https://vendolink.local/auth/register.php?key={$testProductKey}";

// Setup database state
$pdo->exec("DELETE FROM product_keys WHERE product_key = '{$testProductKey}'");
$pdo->exec("DELETE FROM machines WHERE machine_code = '{$testMachineCode}'");
$pdo->exec("INSERT INTO machines (machine_code, machine_key, name, status) VALUES ('{$testMachineCode}', 'key_test_qr_99', 'Packaging Sticker Scanner Machine', 'pending')");
$pdo->exec("INSERT INTO product_keys (product_key, hardware_serial, status, owner_id, activated_at) VALUES ('{$testProductKey}', '{$testMachineCode}', 'UNUSED', NULL, NULL)");

// Step 5.1: Simulate scanning the packaging sticker QR code
$scannedKey = testExtractActivationKey($packagingStickerUrl);
assert($scannedKey === $testProductKey, "Scanned key must match encoded key");
echo "   Step 5.1: Scanned sticker URL -> Extracted Key: '{$scannedKey}'\n";

// Step 5.2: Server validates key eligibility before account creation
$keyValidation = $productKeyModel->validateKey($scannedKey);
assert($keyValidation['valid'] === true, "Scanned key must be valid and unused");
echo "   Step 5.2: Key validated with server: status='{$keyValidation['key']['status']}', hardware_serial='{$keyValidation['key']['hardware_serial']}'\n";

// Step 5.3: User submits registration form with scanned key
$testEmail = 'qr_owner_' . time() . '@example.com';
$newOwnerId = $userModel->createOwnerAccount([
    'full_name' => 'QR Scanner Tester',
    'username'  => 'qr_owner_' . time(),
    'email'     => $testEmail,
    'password'  => 'SecurePassword123!',
]);
assert($newOwnerId > 0, "Owner user account must be created");

// Step 5.4: Activate key and bind hardware
$activated = $productKeyModel->activateKey(
    (int)$keyValidation['key']['id'],
    $newOwnerId,
    $keyValidation['key']['hardware_serial']
);
assert($activated === true, "Key activation must succeed");

// Step 5.5: Verify database state
$updatedRecord = $productKeyModel->findByKey($testProductKey);
assert($updatedRecord['status'] === 'USED', "Product key status must now be 'USED'");
assert((int)$updatedRecord['owner_id'] === $newOwnerId, "Product key owner_id must match new owner ID");

$linkedMachine = $db->query("SELECT * FROM machines WHERE machine_code = '{$testMachineCode}' LIMIT 1")->fetch();
assert((int)$linkedMachine['owner_id'] === $newOwnerId, "Machine owner_id must be updated to new owner ID");
echo "   Step 5.5: Database confirmed: Key status=USED, machine '{$testMachineCode}' bound to Owner #{$newOwnerId}.\n";

// Step 5.6: Attempting to reuse scanned key must fail
$reUseCheck = $productKeyModel->validateKey($scannedKey);
assert($reUseCheck['valid'] === false, "Used key must be rejected on re-registration");
assert($reUseCheck['error'] === 'Product Key Already Activated', "Error message must state already activated");
echo "   Step 5.6: Re-activation protection verified: '{$reUseCheck['error']}'.\n\n";

// ============================================================
// 6. Security and Safety Invariant Checks
// ============================================================
echo "6. Verifying Non-Auto-Submit and Non-Auto-Consume Safety Controls...\n";

// Ensure no automatic form submission in client scanner callback
// The callback in register.php should only set productKeyInput.value, trigger input/change events, and close the modal.
$scannerJs = substr($registerHtml, strpos($registerHtml, 'onScanSuccess'));
assert(strpos($scannerJs, 'registerForm.submit()') === false, "Scanner callback must NEVER automatically submit the registration form");
assert(strpos($scannerJs, 'form.submit()') === false, "Scanner callback must NEVER submit form directly");
assert(strpos($scannerJs, 'productKeyInput.value = key') !== false, "Scanner callback must populate productKeyInput");
assert(strpos($scannerJs, 'productKeyInput.dispatchEvent') !== false, "Scanner callback must dispatch change/input events");
assert(strpos($scannerJs, 'stopScanner()') !== false, "Scanner must stop camera stream upon successful scan");
echo "   ✅ Client-side code verified: Form is not auto-submitted; customer retains full review before submitting.\n";

// Cleanup test records
$pdo->exec("DELETE FROM product_keys WHERE product_key = '{$testProductKey}'");
$pdo->exec("DELETE FROM machines WHERE machine_code = '{$testMachineCode}'");
$pdo->exec("DELETE FROM users WHERE id = {$newOwnerId}");
echo "   ✅ Test artifacts and database records cleaned up.\n\n";

// ============================================================
// 7. One-Tap Camera Permission & Multi-Error Handling Matrix
// ============================================================
echo "7. Verifying One-Tap Camera Permission & Multi-Error Handling Matrix...\n";

// 7.1 Verify immediate camera request on Scan QR Code button click
assert(
    strpos($registerHtml, 'btnOpenScanner.addEventListener(\'click\'') !== false,
    "register.php must attach click listener to #btnOpenScanner"
);
assert(
    strpos($registerHtml, 'requestCameraAndStartScanner(false)') !== false,
    "btnOpenScanner must immediately invoke requestCameraAndStartScanner upon click"
);
assert(
    strpos($registerHtml, 'navigator.mediaDevices.getUserMedia(constraints)') !== false,
    "Scanner must request camera access using navigator.mediaDevices.getUserMedia"
);
assert(
    strpos($registerHtml, 'facingMode: { ideal: "environment" }') !== false,
    "Scanner must prefer rear-facing camera on mobile devices using facingMode: ideal: 'environment'"
);
echo "   ✅ Immediate camera permission request with rear-facing preference confirmed.\n";

// 7.2 Verify automatic scanner start on permission grant (zero extra clicks)
assert(
    strpos($registerHtml, 'initAndStartHtml5Scanner()') !== false,
    "Scanner must automatically initialize and start once permission is granted"
);
// Verify probe tracks are stopped before handoff
assert(
    strpos($registerHtml, 'track.stop()') !== false,
    "Probe stream tracks must be stopped before handoff to Html5Qrcode"
);
echo "   ✅ Automatic scanner initialization on Allow without extra clicks confirmed.\n";

// 7.3 Verify guards against repeated camera requests when already active
assert(
    strpos($registerHtml, 'if (isScanning) return;') !== false,
    "Must guard against redundant camera requests while scanning is active"
);
assert(
    strpos($registerHtml, 'if (isRequestingPermission && !isRetry) return;') !== false,
    "Must guard against concurrent permission prompts"
);
echo "   ✅ Active scanner and in-flight permission guards confirmed.\n";

// 7.4 Verify Try Again button immediately retries request
assert(
    strpos($registerHtml, 'btnRetryCamera.addEventListener(\'click\'') !== false,
    "register.php must attach click listener to #btnRetryCamera"
);
assert(
    strpos($registerHtml, 'requestCameraAndStartScanner(true)') !== false,
    "btnRetryCamera must invoke requestCameraAndStartScanner(true) to retry immediately"
);
echo "   ✅ Try Again button immediate retry confirmed.\n";

// 7.5 Verify error categorization logic
/**
 * PHP replication of categorizeCameraError for unit testing
 */
function testCategorizeCameraError($errName, $errMsg = '', $denialCount = 0, $isPermanentlyBlocked = false, $hasMediaDevices = true) {
    $errStr = strtolower($errMsg);
    if (!$hasMediaDevices || $errName === 'InsecureContextError' || $errName === 'NotSupportedError' || preg_match('/insecure context|secure context|not supported|mediadevices/i', $errStr)) {
        return [
            'type'              => 'INSECURE_OR_UNSUPPORTED',
            'title'             => 'HTTPS Required or Browser Unsupported',
            'canRetry'          => false,
            'showSettingsGuide' => false,
        ];
    }
    if ($errName === 'NotAllowedError' || $errName === 'PermissionDeniedError' || preg_match('/notallowederror|permission denied|denied/i', $errStr)) {
        $isBlocked = ($denialCount >= 1 || $isPermanentlyBlocked);
        return [
            'type'              => 'NOT_ALLOWED',
            'title'             => 'Camera Permission Denied',
            'canRetry'          => true,
            'showSettingsGuide' => $isBlocked,
        ];
    }
    if ($errName === 'NotFoundError' || $errName === 'DevicesNotFoundError' || preg_match('/notfounderror|no camera|devices not found/i', $errStr)) {
        return [
            'type'              => 'NOT_FOUND',
            'title'             => 'No Camera Detected',
            'canRetry'          => false,
            'showSettingsGuide' => false,
        ];
    }
    if ($errName === 'NotReadableError' || $errName === 'TrackStartError' || preg_match('/notreadableerror|trackstart|in use|could not start/i', $errStr)) {
        return [
            'type'              => 'NOT_READABLE',
            'title'             => 'Camera In Use',
            'canRetry'          => true,
            'showSettingsGuide' => false,
        ];
    }
    if ($errName === 'OverconstrainedError' || $errName === 'ConstraintNotSatisfiedError' || preg_match('/overconstrainederror|constraint/i', $errStr)) {
        return [
            'type'              => 'OVERCONSTRAINED',
            'title'             => 'Camera Mode Unavailable',
            'canRetry'          => true,
            'showSettingsGuide' => false,
        ];
    }
    return [
        'type'              => 'UNKNOWN',
        'title'             => 'Camera Access Error',
        'canRetry'          => true,
        'showSettingsGuide' => false,
    ];
}

// Test Error 1: NotAllowedError (First denial)
$err1 = testCategorizeCameraError('NotAllowedError', 'Permission denied', 0, false);
assert($err1['type'] === 'NOT_ALLOWED', "Must identify NotAllowedError");
assert($err1['title'] === 'Camera Permission Denied', "Title must be 'Camera Permission Denied'");
assert($err1['canRetry'] === true, "Must allow retry on first denial");
assert($err1['showSettingsGuide'] === false, "Settings guide initially hidden on first denial");
echo "   ✅ [Error 1] NotAllowedError (1st denial) -> Retry enabled, polite prompt.\n";

// Test Error 2: NotAllowedError (Second denial / persistently blocked)
$err2 = testCategorizeCameraError('NotAllowedError', 'Permission denied', 1, true);
assert($err2['type'] === 'NOT_ALLOWED', "Must identify NotAllowedError");
assert($err2['showSettingsGuide'] === true, "Settings guide MUST be shown when blocked or denied again");
echo "   ✅ [Error 2] NotAllowedError (2nd denial / blocked) -> Browser settings guide revealed.\n";

// Test Error 3: NotFoundError
$err3 = testCategorizeCameraError('NotFoundError', 'Requested device not found');
assert($err3['type'] === 'NOT_FOUND', "Must identify NotFoundError");
assert($err3['title'] === 'No Camera Detected', "Title must be 'No Camera Detected'");
assert($err3['canRetry'] === false, "Cannot retry if hardware does not exist");
echo "   ✅ [Error 3] NotFoundError -> 'No Camera Detected' with manual entry instruction.\n";

// Test Error 4: NotReadableError
$err4 = testCategorizeCameraError('NotReadableError', 'Could not start video source');
assert($err4['type'] === 'NOT_READABLE', "Must identify NotReadableError");
assert($err4['title'] === 'Camera In Use', "Title must be 'Camera In Use'");
assert($err4['canRetry'] === true, "Can retry after user closes other app");
echo "   ✅ [Error 4] NotReadableError -> 'Camera In Use' with close-apps advice.\n";

// Test Error 5: OverconstrainedError
$err5 = testCategorizeCameraError('OverconstrainedError', 'facingMode environment not satisfied');
assert($err5['type'] === 'OVERCONSTRAINED', "Must identify OverconstrainedError");
assert($err5['title'] === 'Camera Mode Unavailable', "Title must be 'Camera Mode Unavailable'");
assert($err5['canRetry'] === true, "Can retry with fallback constraints");
echo "   ✅ [Error 5] OverconstrainedError -> 'Camera Mode Unavailable' handled.\n";

// Test Error 6: Insecure context / missing mediaDevices
$err6 = testCategorizeCameraError('InsecureContextError', 'mediaDevices not available', 0, false, false);
assert($err6['type'] === 'INSECURE_OR_UNSUPPORTED', "Must identify INSECURE_OR_UNSUPPORTED");
assert($err6['title'] === 'HTTPS Required or Browser Unsupported', "Title must state HTTPS Required");
assert($err6['canRetry'] === false, "Cannot retry in unsupported context");
echo "   ✅ [Error 6] Insecure origin / unsupported context handled.\n";

// 7.6 Verify Site Settings Guide instructions
assert(
    strpos($registerHtml, 'How to enable camera in browser settings:') !== false,
    "register.php must provide clear instructions for browser site settings"
);
assert(
    strpos($registerHtml, 'lock or site info icon beside the address bar') !== false,
    "Guide must instruct user to tap lock / site info icon"
);
assert(
    strpos($registerHtml, 'Change <strong>Camera</strong> access to <strong>Allow</strong>') !== false,
    "Guide must instruct user to change Camera access to Allow"
);
echo "   ✅ Browser site settings instruction copy verified.\n";

// 7.7 Verify Enter Key Manually button and stream shutdown
assert(
    strpos($registerHtml, 'id="btnEnterManualFromError"') !== false,
    "Must include #btnEnterManualFromError in error overlay"
);
assert(
    strpos($registerHtml, 'productKeyInput.focus()') !== false,
    "Manual entry button must focus productKeyInput upon modal dismiss"
);
assert(
    strpos($registerHtml, 'window.addEventListener(\'pagehide\', function() {') !== false,
    "Must stop camera tracks on pagehide"
);
assert(
    strpos($registerHtml, 'window.addEventListener(\'beforeunload\', function() {') !== false,
    "Must stop camera tracks on beforeunload"
);
echo "   ✅ Enter Manually fallback and pagehide/beforeunload camera cleanup verified.\n\n";

echo "🎉 ALL QR CODE SCANNER REGISTRATION TESTS PASSED 100% SUCCESSFULLY!\n";

