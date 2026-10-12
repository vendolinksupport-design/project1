<?php
/**
 * VendoLink — Quick Seed Script
 * Creates the database, tables, and admin user in one shot.
 * DELETE THIS FILE after running it!
 */

require_once __DIR__ . '/config/config.php';

// Security Guard: quick_seed.php can only be executed via CLI
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Access Denied: quick_seed.php can only be executed via the command line interface (CLI).\n");
}

$adminUsername = 'admin';
$adminEmail    = 'admin@vendolink.local';
$adminFullName = 'System Administrator';
$adminPassword = 'Admin@1234';  // <-- CHANGE THIS after first login

$errors = [];
$log    = [];

try {
    // Step 1 — Connect to MySQL (no DB selected yet)
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET),
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $log[] = '✅ Connected to MySQL.';

    // Step 2 — Create database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `" . DB_NAME . "`");
    $log[] = '✅ Database <strong>' . DB_NAME . '</strong> ready.';

    // Step 3 — Create users table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
        `id`                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        `username`            VARCHAR(50)      NOT NULL,
        `email`               VARCHAR(150)     NOT NULL,
        `password`            VARCHAR(255)     NOT NULL,
        `full_name`           VARCHAR(100)     NOT NULL,
        `role`                ENUM('administrator','owner','technician') NOT NULL DEFAULT 'technician',
        `status`              ENUM('active','inactive','banned')         NOT NULL DEFAULT 'active',
        `email_verified`      TINYINT(1)       NOT NULL DEFAULT 1,
        `email_verify_token`  VARCHAR(100)     NULL DEFAULT NULL,
        `reset_token`         VARCHAR(100)     NULL DEFAULT NULL,
        `reset_token_expires` DATETIME         NULL DEFAULT NULL,
        `remember_token`      VARCHAR(100)     NULL DEFAULT NULL,
        `login_attempts`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `locked_until`        DATETIME         NULL DEFAULT NULL,
        `last_login`          DATETIME         NULL DEFAULT NULL,
        `profile_picture`     VARCHAR(255)     NULL DEFAULT NULL,
        `phone`               VARCHAR(20)      NULL DEFAULT NULL,
        `created_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_email`    (`email`),
        UNIQUE KEY `uq_username` (`username`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log[] = '✅ Table <strong>users</strong> ready.';

    // Step 4 — Create activity_logs table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `activity_logs` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`     INT UNSIGNED NULL DEFAULT NULL,
        `action`      VARCHAR(100) NOT NULL,
        `description` TEXT         NULL DEFAULT NULL,
        `ip_address`  VARCHAR(45)  NULL DEFAULT NULL,
        `user_agent`  VARCHAR(500) NULL DEFAULT NULL,
        `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        INDEX `idx_user_id`    (`user_id`),
        INDEX `idx_created_at` (`created_at`),
        CONSTRAINT `fk_al_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log[] = '✅ Table <strong>activity_logs</strong> ready.';

    // Step 5 — Create settings table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
        `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `setting_key`   VARCHAR(100) NOT NULL,
        `setting_value` TEXT         NULL DEFAULT NULL,
        `description`   VARCHAR(255) NULL DEFAULT NULL,
        `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_setting_key` (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log[] = '✅ Table <strong>settings</strong> ready.';

    // Step 6 — Seed settings (ignore duplicates)
    $pdo->exec("INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
        ('app_name',           'VendoLink',              'Application display name'),
        ('app_version',        '1.0',                    'Current application version'),
        ('app_timezone',       'Asia/Manila',             'Default timezone'),
        ('maintenance_mode',   '0',                      '1 = maintenance mode ON')");
    $log[] = '✅ Default settings seeded.';

    // Step 7 — Create admin user
    $hashed = password_hash($adminPassword, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    $stmt = $pdo->prepare("
        INSERT INTO `users` (`username`, `email`, `password`, `full_name`, `role`, `status`, `email_verified`)
        VALUES (:u, :e, :p, :f, 'administrator', 'active', 1)
        ON DUPLICATE KEY UPDATE `password` = VALUES(`password`), `login_attempts` = 0, `locked_until` = NULL, `updated_at` = NOW()
    ");
    $stmt->execute([':u' => $adminUsername, ':e' => $adminEmail, ':p' => $hashed, ':f' => $adminFullName]);
    $log[] = '✅ Admin user <strong>' . htmlspecialchars($adminUsername) . '</strong> created/updated.';

    // Step 8 — Log it
    $uid = $pdo->lastInsertId() ?: null;
    $pdo->prepare("INSERT INTO `activity_logs` (`user_id`,`action`,`description`,`ip_address`) VALUES (?,?,?,?)")
        ->execute([$uid, 'SETUP', 'Quick seed ran — admin account created.', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);

    $success = true;

} catch (PDOException $e) {
    $errors[] = $e->getMessage();
    $success  = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>VendoLink Quick Seed</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,sans-serif;background:#0f172a;color:#f1f5f9;
       min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem}
  .card{background:#1e293b;border-radius:14px;padding:2rem;max-width:540px;width:100%;
        box-shadow:0 20px 60px rgba(0,0,0,.5)}
  h1{font-size:1.3rem;margin-bottom:1.25rem}
  .log{font-size:.85rem;line-height:2;margin-bottom:1.25rem}
  .cred{background:#0f172a;border-radius:8px;padding:1rem;margin-bottom:1rem}
  .cred-row{display:flex;justify-content:space-between;align-items:center;
            padding:.3rem 0;border-bottom:1px solid #1e293b;font-size:.88rem}
  .cred-row:last-child{border:none}
  .cred-label{color:#64748b;font-size:.78rem;text-transform:uppercase;letter-spacing:.05em}
  .cred-value{color:#f97316;font-family:monospace;font-size:.95rem;font-weight:600}
  .btn{display:block;background:linear-gradient(135deg,#0ea5e9,#6366f1);border:none;
       color:#fff;padding:.7rem 1.5rem;border-radius:8px;font-weight:600;
       cursor:pointer;text-align:center;text-decoration:none;font-size:.95rem;margin-top:1rem}
  .btn:hover{opacity:.9;color:#fff}
  .warn{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);
        border-radius:8px;padding:.75rem 1rem;font-size:.83rem;color:#fbbf24;margin-top:.75rem}
  .err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);
       border-radius:8px;padding:.75rem 1rem;font-size:.85rem;color:#fca5a5;margin-bottom:1rem}
</style>
</head>
<body>
<div class="card">
<?php if (!$success): ?>
  <h1>❌ Seed Failed</h1>
  <?php foreach ($errors as $err): ?>
  <div class="err"><?= htmlspecialchars($err) ?></div>
  <?php endforeach; ?>
  <p style="color:#94a3b8;font-size:.85rem">Check that XAMPP MySQL is running and your <code>config/config.php</code> DB credentials are correct.</p>
<?php else: ?>
  <h1>✅ VendoLink Ready!</h1>
  <div class="log"><?= implode('<br>', $log) ?></div>

  <div class="cred">
    <div style="color:#f1f5f9;font-weight:600;margin-bottom:.6rem">
      🔑 Login Credentials
    </div>
    <div class="cred-row">
      <span class="cred-label">Username</span>
      <span class="cred-value"><?= htmlspecialchars($adminUsername) ?></span>
    </div>
    <div class="cred-row">
      <span class="cred-label">Email</span>
      <span class="cred-value"><?= htmlspecialchars($adminEmail) ?></span>
    </div>
    <div class="cred-row">
      <span class="cred-label">Password</span>
      <span class="cred-value"><?= htmlspecialchars($adminPassword) ?></span>
    </div>
  </div>

  <a href="<?= BASE_URL ?>/auth/login.php" class="btn">
    → Go to Login Page
  </a>

  <div class="warn">
    ⚠ <strong>Change your password</strong> after first login.<br>
    Then <strong>delete</strong> <code>quick_seed.php</code> and <code>reset_admin.php</code> from your server.
  </div>
<?php endif; ?>
</div>
</body>
</html>
