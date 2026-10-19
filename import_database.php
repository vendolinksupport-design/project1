<?php
/**
 * VendoLink — Production Database Initializer & Migration Tool
 * Safely imports vendolink_db.sql into the configured database.
 */

require_once __DIR__ . '/config/config.php';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>VendoLink — Database Setup</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; padding: 2rem; }
    .card { max-width: 650px; margin: 2rem auto; background: #1e293b; border-radius: 12px; padding: 2rem; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
    h1 { color: #38bdf8; font-size: 1.5rem; margin-bottom: 1rem; }
    .log { background: #0f172a; border-radius: 8px; padding: 1rem; font-family: monospace; font-size: 0.9rem; max-height: 250px; overflow-y: auto; color: #94a3b8; margin: 1rem 0; }
    .success { color: #4ade80; }
    .error { color: #f87171; }
    .btn { display: inline-block; background: #0284c7; color: white; padding: 0.75rem 1.5rem; border-radius: 6px; text-decoration: none; font-weight: bold; margin-top: 1rem; }
    .btn:hover { background: #0369a1; }
    .creds { background: #334155; padding: 1rem; border-radius: 6px; margin-top: 1rem; }
  </style>
</head>
<body>
<div class="card">
  <h1>⚙️ VendoLink Database Initializer</h1>
<?php
try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
    echo "<p class='success'>✅ Connected to database: <strong>" . htmlspecialchars(DB_NAME) . "</strong> as <strong>" . htmlspecialchars(DB_USER) . "</strong></p>";

    $sqlFile = __DIR__ . '/vendolink_db.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("File vendolink_db.sql not found in " . __DIR__);
    }

    $sql = file_get_contents($sqlFile);
    echo "<div class='log'>";
    echo "Reading vendolink_db.sql (" . number_format(strlen($sql)) . " bytes)...<br>";
    
    // Execute SQL script
    $pdo->exec($sql);
    echo "SQL script executed successfully!<br>";
    echo "</div>";

    // Verify tables
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "<p class='success'>🎉 <strong>Success! " . count($tables) . " tables created/verified:</strong></p>";
    echo "<div class='log'>" . implode(', ', $tables) . "</div>";

    echo "<div class='creds'>";
    echo "<h3>🔑 Admin Login Credentials</h3>";
    echo "<p><strong>URL:</strong> <a style='color:#38bdf8' href='" . BASE_URL . "/auth/login.php'>" . BASE_URL . "/auth/login.php</a><br>";
    echo "<strong>Username:</strong> <code>admin</code> (or <code>vendolink.support@gmail.com</code>)<br>";
    echo "<strong>Password:</strong> <code>Admin@12345</code></p>";
    echo "</div>";

    echo "<p style='color:#facc15; margin-top:1rem;'>⚠️ <strong>Security Note:</strong> Please delete <code>import_database.php</code> and <code>vendolink_db.sql</code> from your server once completed.</p>";
    echo "<a class='btn' href='" . BASE_URL . "/auth/login.php'>Go to Sign In &rarr;</a>";

} catch (Throwable $e) {
    echo "<p class='error'>❌ <strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
</div>
</body>
</html>
