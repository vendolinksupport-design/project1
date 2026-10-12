<?php
/**
 * ============================================================
 * VendoLink — Real-Time Notification & Alerts API Endpoint
 * ============================================================
 *
 * Provides polling, live unread counts, dynamic topnav dropdown
 * feeds, and mark-as-read operations for the owner dashboard.
 *
 * @package VendoLink
 * @version 2.5
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Notification.php';

// Requires authentication
require_auth();

$ownerId = current_owner_id() ?: (int)($_SESSION['user_id'] ?? 0);
if ($ownerId <= 0) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'Forbidden: Invalid user context.'
    ]);
    exit;
}
$notifModel = new Notification();

$action = trim($_GET['action'] ?? ($_POST['action'] ?? 'poll'));

if ($action === 'mark_all_read') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
        exit;
    }

    $updated = $notifModel->markAllAsRead($ownerId);
    $_SESSION['notifications_read_at'] = time();

    echo json_encode([
        'success'      => true,
        'updated_rows' => $updated,
        'unread_count' => 0,
    ]);
    exit;
}

if ($action === 'mark_read') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $ok = ($id > 0) ? $notifModel->markAsRead($id, $ownerId) : false;

    echo json_encode([
        'success'      => $ok,
        'unread_count' => $notifModel->getUnreadCount($ownerId),
    ]);
    exit;
}

// Default action: 'poll'
$lastId = max(0, (int)($_GET['last_id'] ?? 0));
$unreadCount = $notifModel->getUnreadCount($ownerId);
$summary     = $notifModel->getSummaryCounts($ownerId);
$dropdown    = $notifModel->getRecentDropdown($ownerId, 5);

// Fetch any new alerts created since lastId for the Live Stream
$newAlerts = [];
if ($lastId > 0) {
    $db = Database::getInstance();
    $sql = "SELECT id, category, type, title, message, icon, badge, is_critical, action_link, is_read, created_at
            FROM notifications
            WHERE user_id = :uid AND id > :last_id AND category != 'financial'
            ORDER BY created_at DESC, id DESC LIMIT 15";
    $rows = $db->query($sql, [':uid' => $ownerId, ':last_id' => $lastId])->fetchAll();
    foreach ($rows as $r) {
        $newAlerts[] = [
            'id'             => (int)$r['id'],
            'category'       => $r['category'],
            'type'           => $r['type'],
            'title'          => $r['title'],
            'message'        => $r['message'],
            'icon'           => $r['icon'],
            'badge'          => $r['badge'],
            'is_critical'    => (bool)$r['is_critical'],
            'action_link'    => $r['action_link'] ?: BASE_URL . '/dashboard/machines.php',
            'is_read'        => (bool)$r['is_read'],
            'formatted_time' => date('M j, Y g:i A', strtotime($r['created_at'])),
        ];
    }
}

// Get setting for inapp web notification
$settingModel = new Setting();
$webNotifEnabled = ($settingModel->get('inapp_notif_enabled', '1') === '1');

echo json_encode([
    'success'            => true,
    'owner_id'           => $ownerId,
    'unread_count'       => $unreadCount,
    'summary'            => $summary,
    'dropdown_items'     => $dropdown,
    'new_alerts'         => $newAlerts,
    'web_notif_enabled'  => $webNotifEnabled,
    'server_time'        => date('Y-m-d H:i:s'),
]);
