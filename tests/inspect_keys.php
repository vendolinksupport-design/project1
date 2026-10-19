<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance();

echo "=== MACHINES ===\n";
$machines = $db->query("SELECT id, owner_id, machine_code, machine_key, name, status, registration_status, device_mac FROM machines")->fetchAll();
print_r($machines);

echo "\n=== PRODUCT KEY 155 ===\n";
$pk155 = $db->query("SELECT * FROM product_keys WHERE id = 155")->fetch();
print_r($pk155);

try {
    echo "\n=== LEGACY LICENSE KEYS ===\n";
    $lks = $db->query("SELECT * FROM license_keys")->fetchAll();
    print_r($lks);
} catch (Throwable $e) {
    echo "No license_keys table or error: " . $e->getMessage() . "\n";
}

