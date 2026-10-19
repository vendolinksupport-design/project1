<?php
/**
 * ============================================================
 * VendoLink — Share Machine Packaging Sticker API
 * ============================================================
 *
 * Allows Administrators to email the Machine Packaging Sticker
 * credentials, Product Key, setup instructions, and claim link
 * directly to a customer or business owner.
 *
 * @package VendoLink
 * @version 1.0.0
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../includes/Mailer.php';

// Strict Admin Privileges Required
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$recipientEmail = filter_var(trim($_POST['recipient_email'] ?? ''), FILTER_VALIDATE_EMAIL);
if (!$recipientEmail) {
    echo json_encode(['success' => false, 'error' => 'Please provide a valid recipient email address.']);
    exit;
}

$machineName = sanitize($_POST['machine_name'] ?? 'Vendo Controller');
$macAddress  = sanitize($_POST['mac_address'] ?? 'N/A');
$productKey  = sanitize($_POST['product_key'] ?? '');

if (empty($productKey)) {
    echo json_encode(['success' => false, 'error' => 'Product Activation Key is missing.']);
    exit;
}

$claimUrl = BASE_URL . '/auth/register.php?key=' . urlencode($productKey);
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($claimUrl);
$subject  = "Your VendoLink Machine Packaging Sticker & Product Key [" . $machineName . "]";

$htmlBody = '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; background-color: #0b1329; margin: 0; padding: 25px 15px; color: #f8fafc;">
<div style="background-color: #111e38; border: 1px solid #1e3a5f; border-radius: 12px; max-width: 500px; margin: 0 auto; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
  
  <!-- Header -->
  <div style="background-color: #0284c7; padding: 22px 20px; text-align: center; color: #ffffff;">
    <h2 style="margin: 0; font-size: 24px; font-weight: 800; letter-spacing: -0.5px;">⚡ VendoLink</h2>
    <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; opacity: 0.95; margin-top: 4px; font-weight: 700;">Machine Packaging Sticker Details</div>
  </div>

  <!-- Body Content -->
  <div style="padding: 25px 22px; color: #cbd5e1; font-size: 14px; line-height: 1.6;">
    <p style="margin: 0 0 12px 0; color: #f1f5f9; font-size: 15px; font-weight: 600;">Hello,</p>
    <p style="margin: 0 0 18px 0; color: #cbd5e1; font-size: 14px;">Here are the official setup credentials and packaging sticker details for your smart vending machine:</p>
    
    <!-- Credentials Table -->
    <table style="width: 100%; font-size: 14px; margin-bottom: 18px; border-collapse: collapse;">
      <tr>
        <td style="color: #94a3b8; width: 120px; padding: 6px 0; font-weight: 600;">Device Name:</td>
        <td style="color: #f8fafc; padding: 6px 0; font-weight: bold; font-size: 15px;">' . htmlspecialchars($machineName) . '</td>
      </tr>
      <tr>
        <td style="color: #94a3b8; padding: 6px 0; font-weight: 600;">Hardware MAC:</td>
        <td style="color: #38bdf8; padding: 6px 0; font-family: Consolas, Monaco, monospace; font-size: 14px; font-weight: bold;">' . htmlspecialchars($macAddress) . '</td>
      </tr>
    </table>

    <!-- QR Code Scan Box -->
    <div style="text-align: center; margin: 20px 0 22px 0; background-color: #0d172e; border: 1px solid #1e3a5f; border-radius: 10px; padding: 18px 15px;">
      <div style="display: inline-block; background-color: #ffffff; padding: 8px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); border: 2px solid #0284c7;">
        <img src="' . htmlspecialchars($qrUrl) . '" alt="Scan QR Code to Claim" width="140" height="140" style="display: block; margin: 0 auto; border: 0;" />
      </div>
      <div style="font-size: 11px; color: #94a3b8; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; margin-top: 10px;">
        Scan with Phone Camera to Claim
      </div>
    </div>

    <!-- Product Key Box -->
    <div style="background-color: #0b1329; border: 2px dashed #0284c7; border-radius: 8px; padding: 15px; text-align: center; margin: 18px 0;">
      <div style="font-size: 11px; color: #94a3b8; font-weight: bold; letter-spacing: 1px; margin-bottom: 6px;">PRODUCT ACTIVATION KEY</div>
      <div style="font-family: Consolas, Monaco, monospace; font-size: 22px; font-weight: bold; color: #38bdf8; letter-spacing: 2px;">' . htmlspecialchars($productKey) . '</div>
    </div>

    <!-- 3-Step Setup Guide -->
    <div style="background-color: #162444; border-left: 4px solid #0284c7; border-radius: 6px; padding: 15px; margin: 20px 0; font-size: 13.5px; line-height: 1.6; color: #cbd5e1;">
      <div style="color: #ffffff; font-weight: bold; margin-bottom: 6px;">Quick 3-Step Setup:</div>
      1. Turn on the machine &amp; connect your phone to Wi-Fi: <strong style="color: #38bdf8;">VendoLink-Setup-XXXX</strong><br>
      2. Enter your venue Wi-Fi name &amp; password in the popup window.<br>
      3. Scan the QR code above or create an account to claim your machine!
    </div>

    <!-- CTA Button -->
    <div style="text-align: center; margin-top: 25px; margin-bottom: 10px;">
      <a href="' . htmlspecialchars($claimUrl) . '" style="display: inline-block; background-color: #0284c7; color: #ffffff !important; padding: 13px 30px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 15px; box-shadow: 0 4px 15px rgba(2,132,199,0.4);">Claim Machine Now</a>
    </div>

  </div>

  <!-- Footer -->
  <div style="font-size: 11px; color: #64748b; text-align: center; padding: 16px 20px; border-top: 1px solid #162444; background-color: #0c1527;">
    VendoLink IoT System &bull; Automated Hardware Packaging Delivery
  </div>
</div>
</body>
</html>';

$plainText = "VendoLink Machine Packaging Sticker\n\n"
    . "Device Name: " . $machineName . "\n"
    . "Hardware MAC: " . $macAddress . "\n"
    . "Product Activation Key: " . $productKey . "\n\n"
    . "Setup Steps:\n"
    . "1. Turn on machine & connect phone to Wi-Fi: VendoLink-Setup-XXXX\n"
    . "2. Enter your venue Wi-Fi name & password in the popup window.\n"
    . "3. Claim your machine using this link: " . $claimUrl . "\n";

$sent = Mailer::send($recipientEmail, $subject, $htmlBody, $plainText);

if ($sent) {
    echo json_encode([
        'success' => true,
        'message' => 'Sticker details successfully emailed to ' . $recipientEmail
    ]);
} else {
    $err = Mailer::getLastError() ?: 'SMTP server failed to deliver. You can use "Open in Mail App" to send via your local email client.';
    echo json_encode([
        'success'   => false,
        'error'     => $err,
        'claim_url' => $claimUrl,
    ]);
}
exit;
