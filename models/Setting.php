<?php
/**
 * ============================================================
 * VendoLink — Setting Model
 * ============================================================
 *
 * Key-value configuration store using the `settings` table.
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/../config/database.php';

class Setting
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get a setting value by key.
     *
     * @param string $key
     * @param string|null $default Fallback value if setting key does not exist
     * @return string|null
     */
    public function get(string $key, ?string $default = null): ?string
    {
        $row = $this->db->query(
            'SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1',
            [$key]
        )->fetch();

        return $row ? $row['setting_value'] : $default;
    }

    /**
     * Get multiple settings by keys as an associative array.
     *
     * @param array $keys List of setting keys
     * @param array $defaults Default key => value map
     * @return array [setting_key => setting_value]
     */
    public function getMany(array $keys, array $defaults = []): array
    {
        $result = $defaults;
        if (empty($keys)) return $result;

        $in   = implode(',', array_fill(0, count($keys), '?'));
        $rows = $this->db->query(
            "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ({$in})",
            $keys
        )->fetchAll();

        foreach ($rows as $row) {
            $result[$row['setting_key']] = $row['setting_value'];
        }

        return $result;
    }

    /**
     * Set or insert a setting value by key.
     *
     * @param string $key
     * @param string $value
     * @param string|null $description
     * @return bool
     */
    public function set(string $key, string $value, ?string $description = null): bool
    {
        $row = $this->db->query(
            'SELECT id FROM settings WHERE setting_key = ? LIMIT 1',
            [$key]
        )->fetch();

        if ($row) {
            $this->db->query(
                'UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?',
                [$value, $key]
            );
        } else {
            $this->db->query(
                'INSERT INTO settings (setting_key, setting_value, description, created_at, updated_at)
                 VALUES (?, ?, ?, NOW(), NOW())',
                [$key, $value, $description ?? 'System preference']
            );
        }
        return true;
    }

    /**
     * Batch save an associative array of key => value settings.
     *
     * @param array $map
     * @return void
     */
    public function saveMany(array $map): void
    {
        foreach ($map as $key => $value) {
            $this->set((string)$key, (string)$value);
        }
    }
}
