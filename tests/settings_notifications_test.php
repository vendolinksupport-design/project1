<?php
/**
 * Automated Test Suite — Settings, Business Preferences & Notification Persistence
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Setting.php';

echo "=== STARTING SETTINGS & BUSINESS PREFERENCES TESTS ===\n\n";

$settingModel = new Setting();

$keys = [
    'inapp_notif_enabled',
    'email_notif_enabled',
    'notif_maint_reminders',
    'notif_machine_offline',
    'notif_machine_online',
    'notif_daily_sales',
    'notif_weekly_sales',
    'notif_security_notices',
];

// 1. Verify reading default settings
echo "1. Testing Settings Retrieval...\n";
$current = $settingModel->getMany($keys);
assert(is_array($current), "getMany must return an array");
echo "? Retrieved current notification settings.\n";

// 2. Test saving custom toggle state (OFF state)
echo "\n2. Testing Saving OFF Notification Preferences...\n";
$offState = [
    'inapp_notif_enabled'    => '0',
    'email_notif_enabled'    => '0',
    'notif_maint_reminders'  => '0',
    'notif_machine_offline'  => '0',
    'notif_machine_online'   => '0',
    'notif_daily_sales'      => '0',
    'notif_weekly_sales'     => '0',
    'notif_security_notices' => '0',
];

$settingModel->saveMany($offState);

$persistedOff = $settingModel->getMany($keys);
foreach ($offState as $key => $expectedVal) {
    assert($persistedOff[$key] === $expectedVal, "Key '{$key}' must persist value '{$expectedVal}'");
}
echo "? Saved and verified OFF notification settings.\n";

// 3. Test saving custom toggle state (ON state)
echo "\n3. Testing Saving ON Notification Preferences...\n";
$onState = [
    'inapp_notif_enabled'    => '1',
    'email_notif_enabled'    => '1',
    'notif_maint_reminders'  => '1',
    'notif_machine_offline'  => '1',
    'notif_machine_online'   => '1',
    'notif_daily_sales'      => '1',
    'notif_weekly_sales'     => '1',
    'notif_security_notices' => '1',
];

$settingModel->saveMany($onState);

$persistedOn = $settingModel->getMany($keys);
foreach ($onState as $key => $expectedVal) {
    assert($persistedOn[$key] === $expectedVal, "Key '{$key}' must persist value '{$expectedVal}'");
}
echo "? Saved and verified ON notification settings.\n";

// 4. Test Business Preferences Persistence
echo "\n4. Testing Business Preferences Retrieval and Saving...\n";
$businessKeys = ['business_name', 'business_email', 'business_phone', 'business_address'];
$businessData = [
    'business_name'    => 'Metro VendoLink Express',
    'business_email'   => 'owner.express@vendolink.local',
    'business_phone'   => '+63 999 888 7777',
    'business_address' => '456 Innovation Blvd, Quezon City, Philippines',
];

$settingModel->saveMany($businessData);

$persistedBusiness = $settingModel->getMany($businessKeys);
foreach ($businessData as $bKey => $bVal) {
    assert($persistedBusiness[$bKey] === $bVal, "Business Key '{$bKey}' must persist value '{$bVal}'");
}
echo "? Business Preferences saved and verified successfully.\n";

echo "\n?? ALL SETTINGS & BUSINESS PREFERENCES TESTS PASSED 100% SUCCESSFULLY!\n";
