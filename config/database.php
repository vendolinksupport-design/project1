<?php
/**
 * ============================================================
 * VendoLink — PDO Database Connection (Singleton Pattern)
 * ============================================================
 *
 * Usage:
 *   $db   = Database::getInstance();
 *   $pdo  = $db->getConnection();
 *   $stmt = $db->query('SELECT * FROM users WHERE id = ?', [$id]);
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/config.php';

class Database
{
    // ----------------------------------------------------------
    // Singleton instance
    // ----------------------------------------------------------
    private static ?self $instance = null;

    // ----------------------------------------------------------
    // Active PDO connection
    // ----------------------------------------------------------
    private PDO $pdo;

    /**
     * Private constructor — establishes the PDO connection.
     */
    private function __construct()
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_FOUND_ROWS   => true,
        ];

        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Log the real error; show a safe message to the user
            error_log('[VendoLink][DB] Connection failed: ' . $e->getMessage());
            http_response_code(503);
            die($this->renderDbError());
        }
    }

    // ----------------------------------------------------------
    // Public API
    // ----------------------------------------------------------

    /**
     * Retrieve the singleton Database instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Return the underlying PDO object for advanced usage.
     */
    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    /**
     * Prepare and execute a query with bound parameters.
     *
     * @param string $sql    SQL with named or positional placeholders
     * @param array  $params Parameters to bind
     * @return PDOStatement  The executed statement (use fetch/fetchAll on it)
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Return the last auto-increment ID inserted.
     */
    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * Begin a database transaction.
     */
    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit the current transaction.
     */
    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    /**
     * Roll back the current transaction.
     */
    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }

    // ----------------------------------------------------------
    // Internal helpers
    // ----------------------------------------------------------

    /**
     * Render a user-safe DB error page.
     */
    private function renderDbError(): string
    {
        return '<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Service Unavailable — VendoLink</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:system-ui,sans-serif;background:#0f172a;
       display:flex;align-items:center;justify-content:center;min-height:100vh}
  .card{background:#1e293b;color:#f1f5f9;padding:2.5rem 3rem;border-radius:16px;
        text-align:center;box-shadow:0 25px 50px rgba(0,0,0,.5);max-width:420px}
  h1{font-size:1.5rem;color:#ef4444;margin-bottom:.75rem}
  p{color:#94a3b8;font-size:.95rem;line-height:1.6}
  code{background:#0f172a;padding:.25rem .5rem;border-radius:4px;
       font-size:.85rem;color:#38bdf8}
</style>
</head>
<body>
  <div class="card">
    <h1>&#9888; Database Unavailable</h1>
    <p>VendoLink cannot connect to the database.<br>
       Check <code>config/config.php</code> and ensure
       MySQL is running.</p>
  </div>
</body></html>';
    }

    /** Prevent external cloning of singleton */
    private function __clone() {}
}
