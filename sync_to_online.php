<?php
/**
 * VendoLink - 1-Click Synchronizer (Local XAMPP -> vendolink.online)
 */

$secret = 'vl_deploy_secret_2026_x89q';
$deployUrl = 'https://vendolink.online/deploy.php';
$rootDir = realpath(__DIR__);
$zipPath = $rootDir . '/sync_payload.zip';
$listPath = $rootDir . '/sync_files.txt';

if (file_exists($zipPath)) unlink($zipPath);
if (file_exists($listPath)) unlink($listPath);

// 1. Determine files to sync
$filesToSync = [];
if (!empty($argv[1])) {
    for ($i = 1; $i < $argc; $i++) {
        $filesToSync[] = str_replace('\\', '/', $argv[$i]);
    }
} else {
    echo "============================================================\n";
    echo "  VendoLink Synchronizer: Local XAMPP -> vendolink.online\n";
    echo "============================================================\n";
    echo "[SCAN] Scanning for recently updated files (last 3 days)...\n";

    $cutoff = time() - (3 * 86400);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) continue;
        $relPath = str_replace('\\', '/', substr($item->getPathname(), strlen($rootDir) + 1));

        // Skip ignored paths & files
        if (preg_match('#^(esp32|build|database/backups|\.git|\.gemini|node_modules|scratch|tests)/#i', $relPath)) continue;
        if (preg_match('#\.(zip|sql|log|bak|bin|docx)$#i', $relPath)) continue;
        if (basename($relPath) === 'db.production.php') continue; // Never overwrite live production DB credentials!
        if (basename($relPath) === 'config.php') continue;        // Never overwrite server DB settings!
        if (basename($relPath) === 'sync_payload.zip') continue;
        if (basename($relPath) === 'sync_files.txt') continue;
        if (basename($relPath) === 'sync_to_online.php') continue;
        if (basename($relPath) === 'sync_to_online.bat') continue;

        if ($item->getMTime() >= $cutoff) {
            $filesToSync[] = $relPath;
        }
    }
}

if (empty($filesToSync)) {
    echo "[INFO] All online files are already up to date. Nothing to sync.\n";
    exit(0);
}

echo "[PACKAGE] Found " . count($filesToSync) . " file(s) to deploy:\n";
foreach ($filesToSync as $f) {
    echo "  + " . $f . "\n";
}

// 2. Create Zip using tar.exe
file_put_contents($listPath, implode("\n", $filesToSync));
$cmd = 'tar.exe -a -c -f "' . $zipPath . '" -T "' . $listPath . '"';
exec($cmd, $out, $ret);
if (file_exists($listPath)) unlink($listPath);

if ($ret !== 0 || !file_exists($zipPath)) {
    die("[ERROR] Could not create zip package using tar.exe.\n");
}

echo "[DEPLOY] Uploading payload (" . number_format(filesize($zipPath)) . " bytes) to $deployUrl ...\n";

// 3. Upload via cURL
$ch = curl_init($deployUrl);
$cfile = new CURLFile($zipPath, 'application/zip', 'package.zip');
$postData = [
    'secret'  => $secret,
    'package' => $cfile
];

curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if (file_exists($zipPath)) {
    unlink($zipPath);
}

if ($httpCode === 200) {
    echo "============================================================\n";
    echo "[SUCCESS] Successfully deployed to vendolink.online! (HTTP 200)\n";
    echo "Server Response: $response\n";
    echo "============================================================\n";
} else {
    echo "============================================================\n";
    echo "[ERROR] Deployment failed (HTTP $httpCode):\n";
    echo $curlErr ? "cURL Error: $curlErr\n" : "Response: $response\n";
    echo "============================================================\n";
    exit(1);
}
