<?php
/**
 * VendoLink — Secure Auto-Deployment & Rollback Bridge
 */
define('DEPLOY_SECRET', 'vl_deploy_secret_2026_x89q');

$headers = function_exists('getallheaders') ? getallheaders() : [];
$token = $headers['X-Deploy-Secret'] ?? $_POST['secret'] ?? '';

if (!hash_equals(DEPLOY_SECRET, (string)$token)) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Forbidden: Invalid Deploy Secret']));
}

$action = $_POST['action'] ?? 'deploy';

// ============================================================
// Action: Rollback to previous backup
// ============================================================
if ($action === 'rollback') {
    $backupDir = __DIR__ . '/backups/deploy_backups';
    if (!is_dir($backupDir)) {
        die(json_encode(['success' => false, 'error' => 'No backups found to revert to.']));
    }

    $backups = glob($backupDir . '/*.zip');
    if (empty($backups)) {
        die(json_encode(['success' => false, 'error' => 'No backup zip files available.']));
    }

    rsort($backups); // latest first
    $latestBackup = $backups[0];

    $zip = new ZipArchive();
    if ($zip->open($latestBackup) === true) {
        $zip->extractTo(__DIR__);
        $zip->close();
        echo json_encode([
            'success' => true,
            'message' => 'Successfully reverted to previous backup: ' . basename($latestBackup)
        ]);
        exit;
    }
    die(json_encode(['success' => false, 'error' => 'Failed to extract backup file.']));
}

// ============================================================
// Action: Deploy with Automatic Pre-Deployment Backup
// ============================================================
if (!empty($_FILES['package']['tmp_name'])) {
    $backupDir = __DIR__ . '/backups/deploy_backups';
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0755, true);
    }

    // 1. Create automatic backup of existing files before overwriting
    $zipNew = new ZipArchive();
    if ($zipNew->open($_FILES['package']['tmp_name']) === true) {
        $filesToBackup = [];
        for ($i = 0; $i < $zipNew->numFiles; $i++) {
            $name = $zipNew->getNameIndex($i);
            if (file_exists(__DIR__ . '/' . $name) && !is_dir(__DIR__ . '/' . $name)) {
                $filesToBackup[] = $name;
            }
        }

        if (!empty($filesToBackup)) {
            $backupZipPath = $backupDir . '/backup_' . date('Ymd_His') . '.zip';
            $backupZip = new ZipArchive();
            if ($backupZip->open($backupZipPath, ZipArchive::CREATE) === true) {
                foreach ($filesToBackup as $f) {
                    $backupZip->addFile(__DIR__ . '/' . $f, $f);
                }
                $backupZip->close();
            }
        }

        // 2. Extract new update
        $zipNew->extractTo(__DIR__);
        $zipNew->close();

        echo json_encode([
            'success' => true,
            'message' => 'Package deployed successfully! Automatic backup saved for safe rollback.'
        ]);
        exit;
    }

    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'Failed to extract uploaded package.']));
}

echo json_encode([
    'success' => true,
    'message' => 'Deployer endpoint is active and listening.'
]);
