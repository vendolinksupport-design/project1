<?php
/**
 * ============================================================
 * VendoLink — History Archive Model
 * ============================================================
 *
 * Provides read-only querying, filtering, and pagination for:
 * 1. Transaction History (service_transactions)
 * 2. Maintenance History (maintenance_history)
 * 3. Configuration History (configuration_history)
 *
 * Strictly read-only to preserve a reliable audit trail.
 * Supports multi-tenant scoping by Owner ID.
 *
 * @package VendoLink
 * @version 2.0
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Transaction.php';

class Archive
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Retrieve paginated and filtered historical transactions.
     *
     * @param array    $filters [search, service, date_from, date_to]
     * @param int      $page
     * @param int      $perPage
     * @param int|null $ownerId
     * @return array
     */
    public function getTransactionHistory(array $filters = [], int $page = 1, int $perPage = 15, ?int $ownerId = null): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $where = ['1=1'];
        $params = [];

        // Owner isolation filter
        if ($ownerId !== null && $ownerId > 0) {
            $where[] = '(m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))';
            $params[':owner_id']  = $ownerId;
            $params[':owner_id2'] = $ownerId;
        }

        // Search by Transaction ID, Client Transaction ID, Service Name, Machine, or Status
        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $idSearch = ltrim(preg_replace('/^#0*/', '', $search), '0');
            if (is_numeric($idSearch) && (int)$idSearch > 0) {
                $where[] = '(st.id = :id_search OR s.name LIKE :search_w1 OR m.name LIKE :search_w2 OR st.client_transaction_id LIKE :search_w3)';
                $params[':id_search'] = (int) $idSearch;
                $params[':search_w1'] = '%' . $search . '%';
                $params[':search_w2'] = '%' . $search . '%';
                $params[':search_w3'] = '%' . $search . '%';
            } else {
                $where[] = '(s.name LIKE :search_w1 OR s.service_key LIKE :search_w2 OR m.name LIKE :search_w3 OR st.status LIKE :search_w4 OR st.client_transaction_id LIKE :search_w5)';
                $params[':search_w1'] = '%' . $search . '%';
                $params[':search_w2'] = '%' . $search . '%';
                $params[':search_w3'] = '%' . $search . '%';
                $params[':search_w4'] = '%' . $search . '%';
                $params[':search_w5'] = '%' . $search . '%';
            }
        }

        // Service filter (water, soap, all)
        if (!empty($filters['service']) && $filters['service'] !== 'all') {
            $where[] = 's.service_key = :service_key';
            $params[':service_key'] = $filters['service'];
        }

        // Status filter:
        // 'completed'   => st.status = 'completed'
        // 'incomplete'  => st.status IN ('incomplete', 'interrupted')
        // 'unsuccessful'=> st.status IN ('cancelled', 'failed')
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $stFilter = strtolower(trim($filters['status']));
            if ($stFilter === 'incomplete') {
                $where[] = "st.status IN ('incomplete', 'interrupted')";
            } elseif ($stFilter === 'unsuccessful') {
                $where[] = "st.status IN ('cancelled', 'failed')";
            } else {
                $where[] = 'st.status = :status';
                $params[':status'] = $stFilter;
            }
        }

        // Date range
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(st.created_at) >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(st.created_at) <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }

        $whereClause = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) FROM service_transactions st
                     LEFT JOIN services s ON s.id = st.service_id
                     LEFT JOIN machines m ON m.id = st.machine_id
                     WHERE {$whereClause}";
        $totalItems = (int) $this->db->query($countSql, $params)->fetchColumn();

        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT st.*,
                       s.name AS service_name,
                       s.service_key AS service_key,
                       s.icon AS service_icon,
                       m.name AS machine_name,
                       m.machine_code AS machine_code,
                       m.location AS machine_location
                FROM service_transactions st
                LEFT JOIN services s ON s.id = st.service_id
                LEFT JOIN machines m ON m.id = st.machine_id
                WHERE {$whereClause}
                ORDER BY st.created_at DESC, st.id DESC
                LIMIT {$perPage} OFFSET {$offset}";

        $items = $this->db->query($sql, $params)->fetchAll();

        foreach ($items as &$item) {
            $item['formatted_id'] = '#' . str_pad((string) $item['id'], 5, '0', STR_PAD_LEFT);
            $item['amount_formatted'] = '₱' . number_format((float) $item['amount'], 2);
            $item['purchased_time_label'] = Transaction::formatDuration((int) $item['runtime_generated_seconds']);
            $item['rate_snapshot_label'] = '₱' . number_format((float) $item['coin_value_at_purchase'], 2) . ' = ' . Transaction::formatDuration((int) $item['service_seconds_at_purchase']);
        }
        unset($item);

        return [
            'items'       => $items,
            'total_items' => $totalItems,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Retrieve paginated and filtered maintenance history.
     *
     * @param array    $filters [search, date_from, date_to]
     * @param int      $page
     * @param int      $perPage
     * @param int|null $ownerId
     * @return array
     */
    public function getMaintenanceHistory(array $filters = [], int $page = 1, int $perPage = 15, ?int $ownerId = null): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $where = ['1=1'];
        $params = [];

        // Owner isolation filter
        if ($ownerId !== null && $ownerId > 0) {
            $where[] = '(m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))';
            $params[':owner_id']  = $ownerId;
            $params[':owner_id2'] = $ownerId;
        }

        // Search by maintenance name, notes, or user full name
        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $where[] = '(mi.name LIKE :search_m1 OR mh.notes LIKE :search_m2 OR u.full_name LIKE :search_m3 OR s.name LIKE :search_m4)';
            $params[':search_m1'] = '%' . $search . '%';
            $params[':search_m2'] = '%' . $search . '%';
            $params[':search_m3'] = '%' . $search . '%';
            $params[':search_m4'] = '%' . $search . '%';
        }

        // Date range
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(mh.completed_at) >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(mh.completed_at) <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }

        $whereClause = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) FROM maintenance_history mh
                     INNER JOIN maintenance_items mi ON mi.id = mh.maintenance_item_id
                     LEFT JOIN services s ON s.id = mi.service_id
                     LEFT JOIN machines m ON m.id = mi.machine_id
                     LEFT JOIN users u ON u.id = mh.completed_by_user_id
                     WHERE {$whereClause}";
        $totalItems = (int) $this->db->query($countSql, $params)->fetchColumn();

        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT mh.*,
                       mi.name AS maintenance_name,
                       mi.reminder_type,
                       s.name AS service_name,
                       s.service_key,
                       m.name AS machine_name,
                       u.full_name AS completed_by_name,
                       u.role AS completed_by_role
                FROM maintenance_history mh
                INNER JOIN maintenance_items mi ON mi.id = mh.maintenance_item_id
                LEFT JOIN services s ON s.id = mi.service_id
                LEFT JOIN machines m ON m.id = mi.machine_id
                LEFT JOIN users u ON u.id = mh.completed_by_user_id
                WHERE {$whereClause}
                ORDER BY mh.completed_at DESC, mh.id DESC
                LIMIT {$perPage} OFFSET {$offset}";

        $items = $this->db->query($sql, $params)->fetchAll();

        foreach ($items as &$item) {
            $seconds = (int) $item['runtime_at_completion_seconds'];
            $hours = intdiv($seconds, 3600);
            $mins = intdiv($seconds % 3600, 60);
            $item['runtime_label'] = $hours . 'h ' . $mins . 'm';
            $item['service_label'] = $item['service_name'] ?: 'Entire Machine';
        }
        unset($item);

        return [
            'items'       => $items,
            'total_items' => $totalItems,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Retrieve paginated and filtered configuration history.
     *
     * @param array       $filters [search, service, date_from, date_to]
     * @param int         $page
     * @param int         $perPage
     * @param int|null    $ownerId
     * @param string|null $viewerRole
     * @return array
     */
    public function getConfigurationHistory(
        array $filters = [],
        int $page = 1,
        int $perPage = 15,
        ?int $ownerId = null,
        ?string $viewerRole = null
    ): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $where = ['1=1'];
        $params = [];

        // Determine if the viewer has an internal administrative role (Admin or Support)
        $isInternal = false;
        if ($viewerRole !== null && $viewerRole !== '') {
            $isInternal = in_array(strtolower($viewerRole), [
                ROLE_ADMIN,
                ROLE_SUPPORT,
                'administrator',
                'support',
                'vendolink_support',
                'admin'
            ], true);
        } elseif (function_exists('is_logged_in') && is_logged_in() && function_exists('is_internal_user')) {
            $isInternal = is_internal_user();
        }

        // Owner-scoped view: Owner is viewing their machine configuration changes
        $isOwnerView = !$isInternal && ($ownerId !== null && $ownerId > 0);

        if ($isOwnerView) {
            // Scope to machine owned by or registered to this owner
            $where[] = '(m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))';
            $params[':owner_id']  = $ownerId;
            $params[':owner_id2'] = $ownerId;

            // Restrict to owner-authorized changes only:
            // Hide Administrator and VendoLink Support Admin entries for Water/Soap service time or configuration changes that the Owner cannot edit.
            // Keep owner-made configuration changes visible.
            $where[] = '(u.role = :owner_role AND ch.changed_by_user_id = :owner_user_id)';
            $params[':owner_role']    = ROLE_OWNER;
            $params[':owner_user_id'] = $ownerId;
        } elseif ($ownerId !== null && $ownerId > 0) {
            // Administrator scoped inspection of a specific owner's machines (admin retains full visibility into all audit records)
            $where[] = '(m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))';
            $params[':owner_id']  = $ownerId;
            $params[':owner_id2'] = $ownerId;
        }

        // Search by service name or changer full name
        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $where[] = '(s.name LIKE :search_c1 OR u.full_name LIKE :search_c2 OR m.name LIKE :search_c3)';
            $params[':search_c1'] = '%' . $search . '%';
            $params[':search_c2'] = '%' . $search . '%';
            $params[':search_c3'] = '%' . $search . '%';
        }

        // Service filter (water, soap, all)
        if (!empty($filters['service']) && $filters['service'] !== 'all') {
            $where[] = 's.service_key = :service_key';
            $params[':service_key'] = $filters['service'];
        }

        // Date range
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(ch.created_at) >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(ch.created_at) <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }

        $whereClause = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) FROM configuration_history ch
                     INNER JOIN services s ON s.id = ch.service_id
                     LEFT JOIN machines m ON m.id = s.machine_id
                     LEFT JOIN users u ON u.id = ch.changed_by_user_id
                     WHERE {$whereClause}";
        $totalItems = (int) $this->db->query($countSql, $params)->fetchColumn();

        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT ch.*,
                       s.name AS service_name,
                       s.service_key,
                       s.icon AS service_icon,
                       m.name AS machine_name,
                       u.full_name AS changed_by_name,
                       u.role AS changed_by_role
                FROM configuration_history ch
                INNER JOIN services s ON s.id = ch.service_id
                LEFT JOIN machines m ON m.id = s.machine_id
                LEFT JOIN users u ON u.id = ch.changed_by_user_id
                WHERE {$whereClause}
                ORDER BY ch.created_at DESC, ch.id DESC
                LIMIT {$perPage} OFFSET {$offset}";

        $items = $this->db->query($sql, $params)->fetchAll();

        foreach ($items as &$item) {
            $item['previous_label'] = '₱' . number_format((float) $item['previous_coin_value'], 2) . ' = ' . Transaction::formatDuration((int) $item['previous_service_seconds']);
            $item['new_label']      = '₱' . number_format((float) $item['new_coin_value'], 2) . ' = ' . Transaction::formatDuration((int) $item['new_service_seconds']);
        }
        unset($item);

        return [
            'items'       => $items,
            'total_items' => $totalItems,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }
}
