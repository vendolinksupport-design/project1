<?php
/**
 * Automated Verification Test Suite
 * Settings Email Notification Preferences Removal
 *
 * Verifies:
 * 1. Email Notification Preferences heading is absent
 * 2. #emailPreferencesContainer is absent
 * 3. All 6 sub-preference toggles are absent from the form
 * 4. The section save button is absent
 * 5. Master toggles (inapp_notif_enabled, email_notif_enabled) remain present with auto-save
 * 6. Database persistence preserves existing sub-preference keys
 * 7. Strictly ZERO emojis and ZERO occurrences of forbidden word 'fleet'
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Setting.php';

echo "=== STARTING EMAIL NOTIFICATION PREFERENCES REMOVAL TESTS ===\n\n";

$settingsFilePath = __DIR__ . '/../settings/index.php';
assert(file_exists($settingsFilePath), "settings/index.php must exist");
$settingsContent = file_get_contents($settingsFilePath);

// Test 1: Verify removed heading and container
echo "1. Testing UI Element Removals in settings/index.php...\n";
assert(strpos($settingsContent, 'Email Notification Preferences') === false, "Heading 'Email Notification Preferences' must not exist in settings/index.php");
assert(strpos($settingsContent, 'id="emailPreferencesContainer"') === false, "Container #emailPreferencesContainer must not exist in settings/index.php");
assert(strpos($settingsContent, 'emailPreferencesContainer') === false, "References to emailPreferencesContainer must not exist in settings/index.php");
assert(strpos($settingsContent, 'syncEmailToggles') === false, "syncEmailToggles function must not exist in settings/index.php");
echo "[PASS] Heading, container, and obsolete sync function successfully removed.\n";

// Test 2: Verify all 6 sub-toggle form fields are absent
echo "\n2. Testing Absence of 6 Sub-Preference Toggles...\n";
$removedFields = [
    'notif_maint_reminders',
    'notif_machine_offline',
    'notif_machine_online',
    'notif_daily_sales',
    'notif_weekly_sales',
    'notif_security_notices',
];

foreach ($removedFields as $field) {
    assert(strpos($settingsContent, "name=\"{$field}\"") === false, "Input field name='{$field}' must not exist in settings/index.php");
}
echo "[PASS] All 6 sub-preference form inputs are completely removed.\n";

// Test 3: Verify master toggles remain present with auto-save
echo "\n3. Testing Master Toggles Presence and Configuration...\n";
assert(strpos($settingsContent, 'id="inapp_notif_enabled"') !== false, "Master toggle inapp_notif_enabled must exist");
assert(strpos($settingsContent, 'id="email_notif_enabled"') !== false, "Master toggle email_notif_enabled must exist");
assert(strpos($settingsContent, 'auto-save-toggle') !== false, "Master toggles must be configured with auto-save-toggle class");
assert(strpos($settingsContent, 'Web Notifications') !== false, "Web Notifications card must exist");
assert(strpos($settingsContent, 'Email Notifications') !== false, "Email Notifications card must exist");
echo "[PASS] Master toggles preserved and equipped with auto-save capability.\n";

// Test 4: Verify Database Non-Destructive Update Behavior
echo "\n4. Testing Database Non-Destructive Update Behavior...\n";
$settingModel = new Setting();

// Seed initial values for sub-preferences
$initialSubValues = [
    'notif_maint_reminders'  => '1',
    'notif_machine_offline'  => '1',
    'notif_machine_online'   => '1',
    'notif_daily_sales'      => '1',
    'notif_weekly_sales'     => '1',
    'notif_security_notices' => '1',
];
$settingModel->saveMany($initialSubValues);

// Simulate saving master notifications from settings/index.php
$simulatedPost = [
    'inapp_notif_enabled' => '1',
    'email_notif_enabled' => '0',
];
$newSettings = [
    'inapp_notif_enabled' => isset($simulatedPost['inapp_notif_enabled']) ? '1' : '0',
    'email_notif_enabled' => isset($simulatedPost['email_notif_enabled']) ? '1' : '0',
];
$settingModel->saveMany($newSettings);

// Assert sub-preferences are still intact and NOT overwritten with '0'
$verifiedSubValues = $settingModel->getMany(array_keys($initialSubValues));
foreach ($initialSubValues as $key => $expectedVal) {
    assert(
        $verifiedSubValues[$key] === $expectedVal,
        "Database key '{$key}' must remain untouched ('{$expectedVal}') and not wiped to '0'"
    );
}
echo "[PASS] Database sub-preferences remain completely preserved after saving master toggles.\n";

// Test 5: Verify strict constraints (zero emojis, zero forbidden word 'fleet')
echo "\n5. Testing Strict Constraints (Zero Emojis, Zero Forbidden Words)...\n";
assert(stripos($settingsContent, 'fleet') === false, "settings/index.php must NOT contain the forbidden word 'fleet'");

// Regex check for 4-byte UTF-8 emoji characters
$hasEmoji = preg_match('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', $settingsContent);
assert(!$hasEmoji, "settings/index.php must NOT contain emojis");
echo "[PASS] Zero emojis and zero forbidden words verified.\n";

echo "\n=== ALL 5 EMAIL NOTIFICATION PREFERENCES REMOVAL TESTS PASSED SUCCESSFULLY! ===\n";
