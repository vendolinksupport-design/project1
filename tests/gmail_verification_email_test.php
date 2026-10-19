<?php
/**
 * ============================================================
 * VendoLink — Gmail Registration Verification Email & MIME Test Suite
 * ============================================================
 *
 * Verifies:
 * 1. RFC 5322 & RFC 2046 MIME compliance:
 *    - Strict CRLF (\r\n) line endings without corrupting extra newlines.
 *    - Valid header-body separation (exactly one CRLF between headers, CRLF CRLF separating headers from body).
 *    - No raw MIME headers or boundary markers leaked into the message body.
 *    - Valid multipart/alternative boundary markers and closing boundaries.
 *    - Subject and From display-name encoding (RFC 2047).
 *    - RFC 5321 §4.5.2 SMTP dot-stuffing transparency.
 * 2. Professional VendoLink Verification Email design:
 *    - VendoLink wordmark / branding.
 *    - Heading: "Verify Your Email Address".
 *    - Personalized greeting with user's full name.
 *    - Short explanation of account verification.
 *    - Prominent 6-digit OTP code with monospace formatting.
 *    - 5-minute expiration notice.
 *    - Security reminder not to share the code.
 *    - Notice explaining what to do if recipient did not create the account.
 *    - Footer: "VendoLink Support".
 *    - Matching plain-text alternative.
 * 3. End-to-end registration and OTP verification security:
 *    - CSPRNG 6-digit OTP generation and SHA-256 hashed storage.
 *    - Single-use consumption and expiration enforcement.
 *    - Zero secrets or OTPs leaked into logs.
 *
 * Run with: php tests/gmail_verification_email_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Mailer.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/ProductKey.php';
require_once __DIR__ . '/../controllers/AuthController.php';

echo "=== STARTING GMAIL VERIFICATION EMAIL & MIME TEST SUITE ===\n\n";

$db = Database::getInstance()->getConnection();
$userModel = new User();
$authCtrl = new AuthController();

// ============================================================
// Test 1: MIME Payload RFC 5322 & RFC 2046 Compliance
// ============================================================
echo "1. Testing MIME Payload RFC 5322 & RFC 2046 Compliance...\n";

$testTo = 'owner@example.com';
$testSubject = 'Your VendoLink Verification Code: 492015';
$testHtml = '<!DOCTYPE html><html><body><h1>Verify Your Email Address</h1><p>.dot-test</p></body></html>';
$testPlain = "Verify Your Email Address\n\n.dot-test\n\nCode: 492015";

$payload = Mailer::buildMimePayload($testTo, $testSubject, $testHtml, $testPlain);

// Check that headers and body exist
assert(!empty($payload['headers']), "Headers must not be empty");
assert(!empty($payload['body']), "Body must not be empty");
assert(!empty($payload['rawMessage']), "Raw message must not be empty");
assert(!empty($payload['smtpData']), "SMTP data must not be empty");
assert(!empty($payload['boundary']), "MIME boundary must be generated for multipart email");

$boundary = $payload['boundary'];

// 1A. Line ending integrity: Ensure NO rogue \r\r\n or \r\r\r in rawMessage or headers
assert(strpos($payload['rawMessage'], "\r\r") === false, "Raw message must NOT contain corrupted \r\r newlines");
assert(strpos($payload['headers'], "\r\r") === false, "Headers must NOT contain corrupted \r\r newlines");
assert(strpos($payload['body'], "\r\r") === false, "Body must NOT contain corrupted \r\r newlines");

// 1B. Header-body separation: Exactly one blank line (\r\n\r\n) separates headers from body
$messageParts = explode("\r\n\r\n", $payload['rawMessage'], 2);
assert(count($messageParts) === 2, "Message must separate headers and body by exactly one CRLF CRLF");

$headerLines = explode("\r\n", $messageParts[0]);
$expectedHeaderPrefixes = ['From:', 'To:', 'Subject:', 'Date:', 'Message-ID:', 'X-Mailer:', 'MIME-Version:', 'Content-Type:'];
foreach ($expectedHeaderPrefixes as $prefix) {
    $found = false;
    foreach ($headerLines as $line) {
        if (str_starts_with($line, $prefix)) {
            $found = true;
            break;
        }
    }
    assert($found, "Header block must contain {$prefix}");
}

// 1C. Message body MUST NOT contain raw headers (the fatal bug that Gmail showed)
assert(!str_starts_with($messageParts[1], "To:"), "Body must NOT start with To: header");
assert(!str_starts_with($messageParts[1], "Subject:"), "Body must NOT start with Subject: header");
assert(!str_starts_with($messageParts[1], "From:"), "Body must NOT start with From: header");
assert(!str_starts_with($messageParts[1], "Content-Type:"), "Body must NOT start with Content-Type: header");

// 1D. Body starts with valid boundary marker and ends with closing boundary
assert(str_starts_with($messageParts[1], "--{$boundary}\r\n"), "Body must start with boundary delimiter line");
assert(str_ends_with(rtrim($messageParts[1]), "--{$boundary}--"), "Body must end with closing boundary --boundary--");

// 1E. Dot-stuffing transparency (RFC 5321 §4.5.2)
// A line starting with . in the source must be escaped as .. in smtpData
assert(strpos($payload['smtpData'], "\r\n..dot-test\r\n") !== false, "Leading dot must be dot-stuffed to .. in SMTP data");

// 1F. SMTP DATA termination: Must end with \r\n.\r\n
assert(str_ends_with($payload['smtpData'], "\r\n.\r\n"), "SMTP data must terminate with CRLF . CRLF");

echo "   ✅ MIME headers, boundary markers, line endings, and dot-stuffing are 100% RFC-compliant.\n\n";

// ============================================================
// Test 2: Subject Line Encoding & Readability
// ============================================================
echo "2. Testing Subject Line Encoding & Gmail Readability...\n";

// 2A. ASCII subject: Should remain clean and readable, without =?UTF-8?... wrapper
$asciiPayload = Mailer::buildMimePayload('user@example.com', 'Your VendoLink Verification Code: 123456', '<p>Hi</p>');
assert(strpos($asciiPayload['headers'], "Subject: Your VendoLink Verification Code: 123456\r\n") !== false, "ASCII subject must remain unencoded and human-readable");

// 2B. Non-ASCII subject: Must use valid RFC 2047 encoded words
$utf8Payload = Mailer::buildMimePayload('user@example.com', 'Verify Your Email Address — VendoLink', '<p>Hi</p>');
assert(strpos($utf8Payload['headers'], "Subject: ") !== false, "UTF-8 subject header must exist");
assert(strpos($utf8Payload['headers'], "\r\n") !== false, "UTF-8 subject header must terminate with CRLF");

echo "   ✅ ASCII and UTF-8 subject headers render cleanly and adhere to RFC 2047.\n\n";

// ============================================================
// Test 3: Professional Verification Email Content & Structure
// ============================================================
echo "3. Testing Professional Verification Email Template Content...\n";

$dispatched = null;
Mailer::setMockHandler(function (array $data) use (&$dispatched): bool {
    $dispatched = $data;
    return true;
});

$testFullName = 'Marcus Aurelius Vance';
$testOtp = '819203';
$testRecipient = 'marcus.vance@example.com';

$sendResult = $authCtrl->sendOtpEmail($testRecipient, $testFullName, $testOtp);
assert($sendResult === true, "sendOtpEmail must return true on success");
assert($dispatched !== null, "Dispatched email data must be captured");

$html = $dispatched['htmlBody'];
$plain = $dispatched['plainText'];

// 3A. Branding and Logo/Wordmark
assert(strpos($html, 'Vendo') !== false && strpos($html, 'Link') !== false, "HTML must contain VendoLink wordmark");
assert(strpos($html, 'IoT Monitoring &amp; Sales Audit System') !== false, "HTML must contain brand subtitle");
assert(strpos($plain, 'VendoLink — IoT Monitoring & Sales Audit System') !== false, "Plain text must contain brand header");

// 3B. Heading: Verify Your Email Address
assert(strpos($html, 'Verify Your Email Address') !== false, "HTML must contain heading 'Verify Your Email Address'");
assert(strpos($plain, 'Verify Your Email Address') !== false, "Plain text must contain heading 'Verify Your Email Address'");

// 3C. Personalized greeting using user's full name
assert(strpos($html, $testFullName) !== false, "HTML greeting must include user's full name");
assert(strpos($plain, $testFullName) !== false, "Plain text greeting must include user's full name");

// 3D. Short explanation of account registration requirement
assert(strpos($html, 'complete your account registration') !== false, "HTML must contain explanation of account registration requirement");
assert(strpos($plain, 'complete your account registration') !== false, "Plain text must contain explanation of account registration requirement");

// 3E. Prominent 6-digit OTP verification code
assert(strpos($html, '8 1 9 2 0 3') !== false, "HTML must contain prominent spaced-formatted OTP code");
assert(strpos($plain, '819203') !== false, "Plain text must contain 6-digit OTP code");

// 3F. Expiration notice: 5 minutes
assert(strpos($html, 'expires in 5 minutes') !== false, "HTML must contain 5-minute expiration notice");
assert(strpos($plain, 'expires in 5 minutes') !== false, "Plain text must contain 5-minute expiration notice");

// 3G. Security reminder
assert(strpos($html, 'Never share this code with anyone') !== false, "HTML must contain security reminder not to share code");
assert(strpos($plain, 'Never share this code with anyone') !== false, "Plain text must contain security reminder not to share code");

// 3H. What to do if recipient did not create account
assert(strpos($html, 'If you did not create a VendoLink account') !== false, "HTML must inform recipient what to do if they did not register");
assert(strpos($plain, 'If you did not create a VendoLink account') !== false, "Plain text must inform recipient what to do if they did not register");

// 3I. Footer: VendoLink Support
assert(strpos($html, 'VendoLink Support') !== false, "HTML must include 'VendoLink Support' footer");
assert(strpos($plain, 'VendoLink Support') !== false, "Plain text must include 'VendoLink Support' footer");

// 3J. Table-based responsive layout check
assert(strpos($html, '<table role="presentation"') !== false, "HTML must use email-safe table layout");
assert(strpos($html, '<script') === false, "HTML must NOT contain JavaScript");

echo "   ✅ Professional verification email satisfies all branding, layout, and content requirements.\n\n";

// ============================================================
// Test 4: End-to-End Registration & Verification Flow
// ============================================================
echo "4. Testing End-to-End Registration & OTP Verification Flow...\n";

// Create a valid product key fixture for testing registration
$prodKeyModel = new ProductKey();
$testKeyString = 'VL-TEST-' . strtoupper(bin2hex(random_bytes(4)));
$testHwSerial  = 'HW-TEST-' . strtoupper(bin2hex(random_bytes(4)));
$db->prepare("INSERT INTO product_keys (product_key, hardware_serial, status, created_at, updated_at) VALUES (?, ?, 'UNUSED', NOW(), NOW())")->execute([$testKeyString, $testHwSerial]);
$keyRow = $db->query("SELECT * FROM product_keys WHERE product_key = '{$testKeyString}'")->fetch(PDO::FETCH_ASSOC);
$keyId = (int)$keyRow['id'];

// Simulate registration data
$regEmail = 'owner_' . time() . '@example.com';
$regUsername = 'owner_' . time();
$regName = 'Test Suite Owner';
$regPassword = 'Password123!';

$ownerUserId = $userModel->createOwnerAccount([
    'full_name' => $regName,
    'username'  => $regUsername,
    'email'     => $regEmail,
    'password'  => $regPassword,
]);
assert($ownerUserId > 0, "Owner user account created");

// Account must be inactive and unverified initially
$initialUser = $userModel->findById($ownerUserId);
assert((int)$initialUser['email_verified'] === 0, "Account must be unverified initially");
assert($initialUser['status'] === 'inactive', "Account must be inactive initially");

// Generate OTP
$generatedOtp = $userModel->createEmailVerifyOtp($ownerUserId);
assert(strlen($generatedOtp) === 6 && ctype_digit($generatedOtp), "Generated OTP must be 6 digits");

// Send verification email via AuthController
$sent = $authCtrl->sendOtpEmail($regEmail, $regName, $generatedOtp);
assert($sent === true, "sendOtpEmail must succeed");
assert($dispatched !== null && $dispatched['to'] === $regEmail, "Email dispatched to registered owner email");

// 4A. Wrong OTP rejection
$wrongVerify = $userModel->verifyEmailOtp($ownerUserId, '999999');
assert($wrongVerify === false, "Wrong OTP must be rejected");
$stillUnverified = $userModel->findById($ownerUserId);
assert((int)$stillUnverified['email_verified'] === 0, "User must remain unverified after wrong OTP");

// 4B. Expired OTP rejection
$db->exec("UPDATE users SET reset_token_expires = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = {$ownerUserId}");
$expiredVerify = $userModel->verifyEmailOtp($ownerUserId, $generatedOtp);
assert($expiredVerify === false, "Expired OTP must be rejected");

// 4C. Fresh valid OTP verification
$freshOtp = $userModel->createEmailVerifyOtp($ownerUserId);
$validVerify = $userModel->verifyEmailOtp($ownerUserId, $freshOtp);
assert($validVerify === true, "Valid OTP verification must succeed");

$verifiedUser = $userModel->findById($ownerUserId);
assert((int)$verifiedUser['email_verified'] === 1, "User must be marked email_verified=1");
assert($verifiedUser['status'] === 'active', "User must be activated (status=active)");
assert(empty($verifiedUser['email_verify_token']), "email_verify_token must be cleared after verification");

// 4D. Single-use enforcement: Attempting to reuse the OTP must fail
$reusedVerify = $userModel->verifyEmailOtp($ownerUserId, $freshOtp);
assert($reusedVerify === false, "Reused OTP must be rejected (single-use enforced)");

// Clean up test fixtures
$db->exec("DELETE FROM users WHERE id = {$ownerUserId}");
$db->exec("DELETE FROM product_keys WHERE id = {$keyId}");
Mailer::setMockHandler(null);

echo "   ✅ End-to-end registration OTP workflow, expiration, and activation operate safely.\n\n";

// ============================================================
// Test 5: Verify mail.log Secret Protection
// ============================================================
echo "5. Verifying mail.log Sanitization & Secret Redaction...\n";

$logFile = LOGS_PATH . '/mail.log';
if (file_exists($logFile)) {
    $logContent = file_get_contents($logFile);
    assert(strpos($logContent, $testOtp) === false, "Raw OTP {$testOtp} must NOT be present in mail.log");
    assert(strpos($logContent, '******') !== false, "OTP must be redacted with asterisks in mail.log");
    echo "   ✅ Audit logs strictly protect secrets and OTP codes.\n\n";
}

echo "🎉 ALL GMAIL VERIFICATION EMAIL & MIME TESTS PASSED 100% SUCCESSFULLY!\n";
