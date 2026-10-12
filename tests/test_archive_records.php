<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Archive.php';

$archive = new Archive();
$res = $archive->getTransactionHistory([], 1, 15, null);
echo "Total records in DB: " . $res['total_items'] . "\n";
echo "Records returned on Page 1: " . count($res['items']) . "\n";
echo "Total pages: " . $res['total_pages'] . "\n";
if ($res['total_pages'] > 1) {
    $res2 = $archive->getTransactionHistory([], 2, 15, null);
    echo "Records returned on Page 2: " . count($res2['items']) . "\n";
}
