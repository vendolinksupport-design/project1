<?php
/**
 * ============================================================
 * VendoLink — Setup / Installation Wizard
 * ============================================================
 *
 * Run this script ONCE after uploading the project to XAMPP.
 * It will:
 *   1. Connect to MySQL and create the database
 *   2. Import the schema (tables)
 *   3. Seed default settings
 *   4. Create the administrator account with a bcrypt password
 *
 * Access via browser: http://localhost/vendolink_claude_v1/setup.php
 *
 * DELETE or RENAME this file after successful setup!
 *
 * @package VendoLink
 * @version 1.0
 */

// ============================================================
// Guard — prevent re-run if lock file exists
// ============================================================
$lockFile = __DIR__ . '/logs/setup.lock';

if (file_exists($lockFile)) {
    die(renderPage('Already Installed', '
        <div class="alert alert-warning">
          <i class="bi bi-shield-exclamation me-2"></i>
          <strong>Setup has already been completed.</strong><br>
          If you need to re-run setup, delete <code>logs/setup.lock</code> and reload.
        </div>
    '));
}

// ============================================================
// Load config (DB credentials, constants)
// ============================================================
require_once __DIR__ . '/config/config.php';

// ============================================================
// Handle POST — run the actual setup
// ============================================================
$errors  = [];
$success = false;
$log     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Collect admin credentials from form ---
    $adminFullName = trim($_POST['admin_full_name'] ?? 'System Administrator');
    $adminUsername = trim($_POST['admin_username']  ?? 'admin');
    $adminEmail    = trim($_POST['admin_email']     ?? 'admin@vendolink.local');
    $adminPassword = $_POST['admin_password']       ?? '';
    $adminConfirm  = $_POST['admin_confirm']        ?? '';

    // --- Basic validation ---
    if (empty($adminUsername) || strlen($adminUsername) < 3) {
        $errors[] = 'Username must be at least 3 characters.';
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }
    if (strlen($adminPassword) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($adminPassword !== $adminConfirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        try {
            // Step 1 — Connect to MySQL without a database selected
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET),
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            $log[] = '✅ Connected to MySQL successfully.';

            // Step 2 — Create database
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`
                        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `" . DB_NAME . "`");
            $log[] = '✅ Database <strong>' . DB_NAME . '</strong> created / verified.';

            // Step 3 — Import schema SQL
            $schemaSql = file_get_contents(__DIR__ . '/database/schema.sql');
            if ($schemaSql === false) {
                throw new RuntimeException('Cannot read database/schema.sql');
            }

            // Split SQL by semicolons, skip comments and empty statements
            $statements = array_filter(
                array_map('trim', explode(';', $schemaSql)),
                fn($s) => !empty($s) && !str_starts_with($s, '--')
            );

            foreach ($statements as $stmt) {
                if (!empty(trim($stmt))) {
                    $pdo->exec($stmt);
                }
            }
            $log[] = '✅ Schema imported — tables created.';

            // Step 4 — Seed administrator account
            $hashedPassword = password_hash($adminPassword, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

            $stmt = $pdo->prepare("
                INSERT INTO `users`
                    (`username`, `email`, `password`, `full_name`, `role`, `status`, `email_verified`)
                VALUES
                    (:username, :email, :password, :full_name, 'administrator', 'active', 1)
                ON DUPLICATE KEY UPDATE
                    `password`   = VALUES(`password`),
                    `full_name`  = VALUES(`full_name`),
                    `updated_at` = NOW()
            ");
            $stmt->execute([
                ':username'  => $adminUsername,
                ':email'     => $adminEmail,
                ':password'  => $hashedPassword,
                ':full_name' => $adminFullName,
            ]);
            $log[] = '✅ Administrator account seeded.';

            // Step 5 — Log first activity
            $userId = $pdo->lastInsertId() ?: null;
            $pdo->prepare("
                INSERT INTO `activity_logs` (`user_id`, `action`, `description`, `ip_address`)
                VALUES (:uid, 'SETUP', 'VendoLink installed and configured.', :ip)
            ")->execute([':uid' => $userId, ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);
            $log[] = '✅ Installation activity logged.';

            // Step 6 — Write lock file to prevent re-run
            if (!is_dir(dirname($lockFile))) {
                mkdir(dirname($lockFile), 0755, true);
            }
            file_put_contents($lockFile, date('Y-m-d H:i:s') . ' — Setup completed.');
            $log[] = '✅ Lock file created. Setup cannot be re-run without deleting it.';

            $success = true;

        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . htmlspecialchars($e->getMessage());
        } catch (RuntimeException $e) {
            $errors[] = 'File error: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// ============================================================
// Helper — render the page
// ============================================================
function renderPage(string $title, string $body): string
{
    return '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>' . htmlspecialchars($title) . ' — VendoLink Setup</title>
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body { font-family: "Inter", sans-serif; background: #0f172a; min-height: 100vh;
           display: flex; align-items: center; justify-content: center; padding: 2rem; }
    .setup-card { background: #1e293b; border-radius: 16px; padding: 2.5rem;
                  box-shadow: 0 25px 60px rgba(0,0,0,.5); max-width: 560px; width: 100%; }
    .setup-logo { color: #38bdf8; font-size: 2rem; font-weight: 700; margin-bottom: .25rem; }
    .setup-logo span { color: #f97316; }
    h2 { color: #f1f5f9; font-size: 1.1rem; font-weight: 500; margin-bottom: 1.5rem; }
    label { color: #94a3b8; font-size: .85rem; font-weight: 500; margin-bottom: .35rem; }
    .form-control { background: #0f172a; border: 1px solid #334155; color: #f1f5f9;
                    border-radius: 8px; padding: .65rem 1rem; }
    .form-control:focus { background: #0f172a; border-color: #38bdf8;
                          box-shadow: 0 0 0 3px rgba(56,189,248,.15); color: #f1f5f9; }
    .btn-setup { background: linear-gradient(135deg, #0ea5e9, #6366f1);
                 border: none; color: #fff; padding: .75rem; border-radius: 8px;
                 font-weight: 600; width: 100%; font-size: 1rem; transition: opacity .2s; }
    .btn-setup:hover { opacity: .9; color: #fff; }
    .log-item { font-size: .85rem; padding: .25rem 0; color: #94a3b8; }
    .step-badge { background: #0f172a; color: #38bdf8; border-radius: 50%;
                  width: 28px; height: 28px; display: inline-flex; align-items: center;
                  justify-content: center; font-size: .75rem; font-weight: 700;
                  margin-right: .5rem; flex-shrink: 0; }
    .alert { border-radius: 8px; font-size: .9rem; }
    .success-icon { font-size: 3rem; color: #22c55e; }
    .cred-box { background: #0f172a; border-radius: 8px; padding: 1rem; margin-top: 1rem; }
    .cred-box code { color: #f97316; }
    hr { border-color: #334155; }
  </style>
</head>
<body>
  <div class="setup-card">
    <div class="setup-logo">Vendo<span>Link</span></div>
    <h2>' . $title . '</h2>
    <hr>
    ' . $body . '
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>';
}

// ============================================================
// Render — Success State
// ============================================================
if ($success) {
    $logHtml = implode('', array_map(
        fn($item) => '<div class="log-item">' . $item . '</div>',
        $log
    ));

    echo renderPage('Installation Complete', '
        <div class="text-center mb-3">
          <div class="success-icon"><i class="bi bi-patch-check-fill"></i></div>
          <h3 class="text-light mt-2">VendoLink is Ready!</h3>
        </div>
        <div class="mb-3">' . $logHtml . '</div>
        <div class="cred-box">
          <div class="mb-1 text-light fw-semibold"><i class="bi bi-key me-2"></i>Admin Login Credentials</div>
          <div class="mt-2">
            <small class="text-muted">Email / Username:</small><br>
            <code>' . htmlspecialchars($_POST['admin_email']) . '</code>
          </div>
          <div class="mt-2">
            <small class="text-muted">Password:</small><br>
            <code>The password you entered during setup</code>
          </div>
        </div>
        <div class="alert alert-warning mt-3">
          <i class="bi bi-exclamation-triangle me-2"></i>
          <strong>Security Notice:</strong> Delete or rename <code>setup.php</code> now.
        </div>
        <a href="' . BASE_URL . '/" class="btn btn-setup mt-2">
          <i class="bi bi-box-arrow-in-right me-2"></i>Go to VendoLink
        </a>
    ');
    exit;
}

// ============================================================
// Render — Error State
// ============================================================
$errorHtml = '';
if (!empty($errors)) {
    $errorItems = implode('', array_map(
        fn($e) => '<li>' . htmlspecialchars($e) . '</li>',
        $errors
    ));
    $errorHtml = '<div class="alert alert-danger"><ul class="mb-0">' . $errorItems . '</ul></div>';
}

// ============================================================
// Render — Setup Form
// ============================================================
echo renderPage('Installation Wizard', '
  <p style="color:#94a3b8;font-size:.9rem;margin-bottom:1.5rem;">
    Fill in the form below to configure VendoLink and create the administrator account.
  </p>

  ' . $errorHtml . '

  <form method="POST" action="">
    <div class="mb-3">
      <label class="form-label">
        <span class="step-badge">1</span>Full Name
      </label>
      <input type="text" name="admin_full_name" class="form-control"
             value="' . htmlspecialchars($_POST['admin_full_name'] ?? 'System Administrator') . '"
             placeholder="System Administrator">
    </div>

    <div class="mb-3">
      <label class="form-label">
        <span class="step-badge">2</span>Username
      </label>
      <input type="text" name="admin_username" class="form-control"
             value="' . htmlspecialchars($_POST['admin_username'] ?? 'admin') . '"
             placeholder="admin" autocomplete="username">
    </div>

    <div class="mb-3">
      <label class="form-label">
        <span class="step-badge">3</span>Email Address
      </label>
      <input type="email" name="admin_email" class="form-control"
             value="' . htmlspecialchars($_POST['admin_email'] ?? 'admin@vendolink.local') . '"
             placeholder="admin@vendolink.local" autocomplete="email">
    </div>

    <div class="mb-3">
      <label class="form-label">
        <span class="step-badge">4</span>Password
      </label>
      <input type="password" name="admin_password" class="form-control"
             placeholder="Min. 8 characters" autocomplete="new-password">
    </div>

    <div class="mb-4">
      <label class="form-label">
        <span class="step-badge">5</span>Confirm Password
      </label>
      <input type="password" name="admin_confirm" class="form-control"
             placeholder="Re-enter password" autocomplete="new-password">
    </div>

    <button type="submit" class="btn btn-setup">
      <i class="bi bi-rocket-takeoff me-2"></i>Install VendoLink
    </button>
  </form>
');
