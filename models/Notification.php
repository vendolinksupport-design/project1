<?php
/**
 * ============================================================
 * VendoLink — Notification Data Model
 * ============================================================
 *
 * Manages database persistence, retrieval, unread tracking,
 * and delivery of operational alerts, hardware warnings,
 * and maintenance notices.
 *
 * @package VendoLink
 * @version 2.5
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/Setting.php';

class Notification
{
    private Database $db;
    private Setting $setting;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->setting = new Setting();
    }

    /**
     * Create a new system notification / alert with de-duplication
     * and preference-aware notification/email routing.
     *
     * @param array $data
     * @return array Created notification data
     */
    public function create(array $data): array
    {
        $userId     = (int)($data['user_id'] ?? 0);
        $machineId  = !empty($data['machine_id']) ? (int)$data['machine_id'] : null;
        $category   = $data['category'] ?? 'system';
        $type       = $data['type'] ?? 'info';
        $title      = trim($data['title'] ?? '');
        $message    = trim($data['message'] ?? '');
        $icon       = $data['icon'] ?? 'bi-bell';
        $badge      = $data['badge'] ?? 'Alert';
        $isCritical = !empty($data['is_critical']) ? 1 : 0;
        $actionLink = $data['action_link'] ?? null;

        if ($userId <= 0 || empty($title) || empty($message)) {
            return ['success' => false, 'error' => 'user_id, title, and message are required.'];
        }

        // 1. De-duplication: Check if identical unread alert exists within the last 10 minutes
        $existing = $this->db->query(
            "SELECT id, created_at FROM notifications
             WHERE user_id = :uid 
               AND (machine_id = :mid OR (machine_id IS NULL AND :mid2 IS NULL))
               AND category = :cat 
               AND title = :title 
               AND is_read = 0 
               AND created_at >= (NOW() - INTERVAL 10 MINUTE)
             ORDER BY id DESC LIMIT 1",
            [
                ':uid'   => $userId,
                ':mid'   => $machineId,
                ':mid2'  => $machineId,
                ':cat'   => $category,
                ':title' => $title,
            ]
        )->fetch();

        if ($existing) {
            return [
                'success'       => true,
                'id'            => (int)$existing['id'],
                'is_duplicate'  => true,
                'message'       => 'Duplicate alert suppressed cleanly.',
            ];
        }

        // 2. Insert into database
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare(
            "INSERT INTO notifications 
                (user_id, machine_id, category, type, title, message, icon, badge, is_critical, action_link, is_read, created_at, updated_at)
             VALUES 
                (:user_id, :machine_id, :category, :type, :title, :message, :icon, :badge, :is_critical, :action_link, 0, NOW(), NOW())"
        );

        $stmt->execute([
            ':user_id'     => $userId,
            ':machine_id'  => $machineId,
            ':category'    => $category,
            ':type'        => $type,
            ':title'       => $title,
            ':message'     => $message,
            ':icon'        => $icon,
            ':badge'       => $badge,
            ':is_critical' => $isCritical,
            ':action_link' => $actionLink,
        ]);

        $notificationId = (int)$pdo->lastInsertId();

        // 3. Evaluate Owner Notification Preferences
        $emailEnabled = ($this->setting->get('email_notif_enabled', '1') === '1');

        if ($emailEnabled) {
            $userRow = $this->db->query(
                "SELECT email, full_name FROM users WHERE id = :id LIMIT 1",
                [':id' => $userId]
            )->fetch();

            if ($userRow && !empty($userRow['email'])) {
                $recipientEmail = $userRow['email'];
                $recipientName  = $userRow['full_name'] ?? 'VendoLink Owner';

                $subject = "[VendoLink Notification] " . $title;
                $htmlBody = $this->buildAlertEmailBody($recipientName, $title, $message, $isCritical, $badge);

                $sent = send_system_email($recipientEmail, $subject, $htmlBody);
                if (!$sent) {
                    error_log("[VendoLink Notification] Failed to send email alert to {$recipientEmail} for notification #{$notificationId}");
                }
            }
        }

        return [
            'success'       => true,
            'id'            => $notificationId,
            'user_id'       => $userId,
            'title'         => $title,
            'category'      => $category,
            'is_duplicate'  => false,
        ];
    }

    /**
     * Get paginated notifications for an owner with category filtering.
     *
     * @param int    $ownerId
     * @param string $filter 'all', 'critical', 'hardware', 'maintenance'
     * @param int    $limit
     * @param int    $offset
     * @return array
     */
    public function getForOwner(int $ownerId, string $filter = 'all', int $limit = 15, int $offset = 0): array
    {
        $where = ["user_id = :uid", "category != 'financial'"];
        $params = [':uid' => $ownerId];

        if ($filter === 'critical') {
            $where[] = "(category = 'critical' OR is_critical = 1)";
        } elseif (in_array($filter, ['hardware', 'logs', 'system_logs', 'system'], true)) {
            $where[] = "(category = 'hardware' OR category = 'system')";
        } elseif ($filter === 'maintenance') {
            $where[] = "category = 'maintenance'";
        }

        $whereClause = implode(' AND ', $where);
        $limit  = max(1, (int)$limit);
        $offset = max(0, (int)$offset);

        $sql = "SELECT id, user_id, machine_id, category, type, title, message, icon, badge, is_critical, action_link, is_read, read_at, created_at
                FROM notifications
                WHERE {$whereClause}
                ORDER BY created_at DESC, id DESC
                LIMIT {$limit} OFFSET {$offset}";

        $rows = $this->db->query($sql, $params)->fetchAll();

        foreach ($rows as &$r) {
            $r['formatted_time'] = $this->formatTime($r['created_at']);
        }

        return $rows;
    }

    /**
     * Get total notification count for an owner and filter.
     *
     * @param int    $ownerId
     * @param string $filter
     * @return int
     */
    public function getTotalCount(int $ownerId, string $filter = 'all'): int
    {
        $where = ["user_id = :uid", "category != 'financial'"];
        $params = [':uid' => $ownerId];

        if ($filter === 'critical') {
            $where[] = "(category = 'critical' OR is_critical = 1)";
        } elseif (in_array($filter, ['hardware', 'logs', 'system_logs', 'system'], true)) {
            $where[] = "(category = 'hardware' OR category = 'system')";
        } elseif ($filter === 'maintenance') {
            $where[] = "category = 'maintenance'";
        }

        $whereClause = implode(' AND ', $where);
        $row = $this->db->query(
            "SELECT COUNT(*) as cnt FROM notifications WHERE {$whereClause}",
            $params
        )->fetch();

        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Get unread notifications count for an owner.
     *
     * @param int $ownerId
     * @return int
     */
    public function getUnreadCount(int $ownerId): int
    {
        $row = $this->db->query(
            "SELECT COUNT(*) as cnt FROM notifications WHERE user_id = :uid AND is_read = 0 AND category != 'financial'",
            [':uid' => $ownerId]
        )->fetch();

        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Get 3 KPI summary counts for the owner dashboard.
     *
     * @param int $ownerId
     * @return array
     */
    public function getSummaryCounts(int $ownerId): array
    {
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN category = 'critical' OR is_critical = 1 THEN 1 ELSE 0 END) as critical,
                    SUM(CASE WHEN category = 'hardware' OR category = 'system' THEN 1 ELSE 0 END) as hardware
                FROM notifications
                WHERE user_id = :uid AND category != 'financial'";

        $row = $this->db->query($sql, [':uid' => $ownerId])->fetch();

        return [
            'total'    => (int)($row['total'] ?? 0),
            'critical' => (int)($row['critical'] ?? 0),
            'hardware' => (int)($row['hardware'] ?? 0),
        ];
    }

    /**
     * Get recent notifications formatted for the topnav dropdown.
     *
     * @param int $ownerId
     * @param int $limit
     * @return array
     */
    public function getRecentDropdown(int $ownerId, int $limit = 5): array
    {
        $items = $this->getForOwner($ownerId, 'all', $limit, 0);

        if (empty($items)) {
            return [
                [
                    'id'     => 0,
                    'icon'   => 'bi-shield-check',
                    'type'   => 'success',
                    'text'   => 'Welcome to VendoLink — System Ready',
                    'time'   => 'Just now',
                    'unread' => false,
                ]
            ];
        }

        $dropdown = [];
        foreach ($items as $item) {
            $dropdown[] = [
                'id'     => (int)$item['id'],
                'icon'   => $item['icon'],
                'type'   => $item['type'],
                'text'   => $item['title'],
                'time'   => $item['formatted_time'],
                'unread' => empty($item['is_read']),
            ];
        }

        return $dropdown;
    }

    /**
     * Mark all notifications as read for a specific owner.
     *
     * @param int $ownerId
     * @return int Number of updated records
     */
    public function markAllAsRead(int $ownerId): int
    {
        $stmt = $this->db->query(
            "UPDATE notifications SET is_read = 1, read_at = NOW(), updated_at = NOW() 
             WHERE user_id = :uid AND is_read = 0 AND category != 'financial'",
            [':uid' => $ownerId]
        );

        return $stmt->rowCount();
    }

    /**
     * Mark a single notification as read for a specific owner.
     *
     * @param int $id
     * @param int $ownerId
     * @return bool
     */
    public function markAsRead(int $id, int $ownerId): bool
    {
        $stmt = $this->db->query(
            "UPDATE notifications SET is_read = 1, read_at = NOW(), updated_at = NOW() 
             WHERE id = :id AND user_id = :uid",
            [':id' => $id, ':uid' => $ownerId]
        );

        return $stmt->rowCount() > 0;
    }

    /**
     * Format timestamp cleanly for UI cards.
     */
    private function formatTime(string $timestamp): string
    {
        $time = strtotime($timestamp);
        if (!$time) return $timestamp;

        $diff = time() - $time;
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';

        $today = strtotime('today');
        if ($time >= $today) {
            return date('g:i A', $time);
        }

        $yesterday = strtotime('yesterday');
        if ($time >= $yesterday) {
            return 'Yesterday, ' . date('g:i A', $time);
        }

        return date('M j, Y g:i A', $time);
    }

    /**
     * Build standard HTML email alert body.
     */
    private function buildAlertEmailBody(string $name, string $title, string $message, int $isCritical, string $badge): string
    {
        $accentColor = $isCritical ? '#ef4444' : '#f59e0b';
        $badgeBg     = $isCritical ? 'rgba(239, 68, 68, 0.2)' : 'rgba(245, 158, 11, 0.2)';

        return '
        <div style="background-color: #0b1329; color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; padding: 24px; border-radius: 12px; max-width: 600px; margin: 0 auto; border: 1px solid #1e293b;">
            <div style="border-bottom: 1px solid #1e293b; padding-bottom: 16px; margin-bottom: 20px;">
                <h2 style="color: #38bdf8; margin: 0; font-size: 20px; font-weight: 700; letter-spacing: -0.02em;">VendoLink Telemetry Alert</h2>
                <p style="color: #94a3b8; font-size: 13px; margin: 4px 0 0 0;">Real-time operational monitoring system</p>
            </div>
            <div style="background-color: #0f172a; border-left: 4px solid ' . $accentColor . '; border-radius: 6px; padding: 18px; margin-bottom: 20px;">
                <span style="display: inline-block; background: ' . $badgeBg . '; color: ' . $accentColor . '; font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 2px 8px; border-radius: 4px; margin-bottom: 8px;">' . htmlspecialchars($badge) . '</span>
                <h3 style="color: #ffffff; margin: 4px 0 8px 0; font-size: 16px; font-weight: 600;">' . htmlspecialchars($title) . '</h3>
                <p style="color: #cbd5e1; font-size: 14px; line-height: 1.5; margin: 0;">' . nl2br(htmlspecialchars($message)) . '</p>
            </div>
            <div style="text-align: center; margin-bottom: 20px;">
                <a href="' . BASE_URL . '/dashboard/alerts.php" style="display: inline-block; background-color: #0284c7; color: #ffffff; text-decoration: none; font-weight: 600; font-size: 13px; padding: 10px 20px; border-radius: 6px;">Open VendoLink Notifications Center</a>
            </div>
            <div style="border-top: 1px solid #1e293b; padding-top: 14px; text-align: center;">
                <p style="color: #64748b; font-size: 11px; margin: 0;">This is an automated operational notification sent to ' . htmlspecialchars($name) . '. You can manage alert settings in Owner Notifications preferences.</p>
            </div>
        </div>';
    }
}
