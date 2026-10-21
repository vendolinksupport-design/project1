<?php
/**
 * ============================================================
 * VendoLink — Transaction Model
 * ============================================================
 *
 * Creates completed service transactions, stores service configuration
 * snapshots at the exact time of purchase, and provides pagination,
 * filtering, and sales summary statistics.
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Notification.php';


class Transaction
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Records a completed transaction using the rate currently saved on
     * the service. The rate and time snapshot are stored on the transaction forever.
     * Atomically adds generated runtime to service and machine.
     *
     * @param int   $serviceId The service ID
     * @param float $amount    Amount paid (e.g. 5.00, 10.00)
     * @return int  The inserted transaction ID
     */
    public function recordCompleted(int $serviceId, float $amount, ?string $clientTxId = null): int
    {
        return $this->record($serviceId, $amount, 'completed', $clientTxId);
    }

    /**
     * Records an incomplete transaction (e.g. power interruption cancelled).
     *
     * @param int         $serviceId            The service ID
     * @param float       $amount               Amount paid
     * @param string|null $clientTxId           Client idempotency key
     * @param string      $reason               Cancellation reason
     * @param int|null    $actualRuntimeSeconds Actual pump runtime elapsed before interruption
     * @return int Inserted or updated transaction ID
     */
    public function recordIncomplete(
        int $serviceId,
        float $amount,
        ?string $clientTxId = null,
        string $reason = 'POWER INTERRUPTION — SESSION CANCELLED',
        ?int $actualRuntimeSeconds = null
    ): int {
        return $this->record($serviceId, $amount, 'incomplete', $clientTxId, $reason, $actualRuntimeSeconds);
    }

    /**
     * Records a transaction with a given status, capturing the service
     * configuration snapshot at the time of the transaction.
     *
     * @param int         $serviceId            The service ID
     * @param float       $amount               The coin/amount value
     * @param string      $status               'completed' | 'active' | 'pending' | 'cancelled' | 'failed' | 'interrupted' | 'incomplete'
     * @param string|null $clientTxId           Optional client idempotency key (e.g. VL-MAC-SEQ)
     * @param string|null $statusReason         Optional reason (e.g. 'POWER INTERRUPTION — SESSION CANCELLED')
     * @param int|null    $actualRuntimeSeconds Optional actual runtime seconds completed
     * @return int   The inserted or existing transaction ID
     */
    public function record(
        int $serviceId,
        float $amount,
        string $status = 'completed',
        ?string $clientTxId = null,
        ?string $statusReason = null,
        ?int $actualRuntimeSeconds = null
    ): int {
        $validStatuses = ['completed', 'active', 'pending', 'cancelled', 'failed', 'interrupted', 'incomplete'];
        if (!in_array($status, $validStatuses, true)) {
            $status = 'completed';
        }

        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();

        try {
            $service = $this->db->query(
                'SELECT id, machine_id, is_enabled, coin_value, service_seconds
                 FROM services WHERE id = :id FOR UPDATE',
                [':id' => $serviceId]
            )->fetch();

            if (!$service || !(int) $service['is_enabled']) {
                throw new InvalidArgumentException('The selected service is unavailable.');
            }

            // Idempotency & Lifecycle: update existing transaction if already recorded for this machine
            if (!empty($clientTxId)) {
                $existing = $this->db->query(
                    'SELECT id, status, runtime_generated_seconds FROM service_transactions WHERE machine_id = :mid AND client_transaction_id = :ctx LIMIT 1',
                    [':mid' => $service['machine_id'], ':ctx' => $clientTxId]
                )->fetch();
                if ($existing) {
                    $existingId = (int) $existing['id'];
                    $existingStatus = $existing['status'];

                    // Case A: Transition from interrupted/pending/active to COMPLETED
                    if ($existingStatus !== 'completed' && $status === 'completed') {
                        $amountCentavos = (int) round($amount * 100);
                        $coinCentavos = (int) round((float) $service['coin_value'] * 100);
                        $coinsCount = ($coinCentavos > 0) ? intdiv($amountCentavos, $coinCentavos) : 1;
                        $runtimeSeconds = $coinsCount * (int) $service['service_seconds'];

                        $this->db->query(
                            'UPDATE service_transactions
                             SET status = "completed",
                                 status_reason = :status_reason,
                                 actual_runtime_seconds = :actual_runtime,
                                 completed_at = NOW()
                             WHERE id = :id',
                            [
                                ':status_reason'  => $statusReason,
                                ':actual_runtime' => ($actualRuntimeSeconds !== null) ? $actualRuntimeSeconds : $runtimeSeconds,
                                ':id'             => $existingId
                            ]
                        );

                        // Add runtime to services and machines
                        $this->db->query(
                            'UPDATE services SET runtime_seconds = runtime_seconds + :seconds WHERE id = :id',
                            [':seconds' => $runtimeSeconds, ':id' => $serviceId]
                        );
                        $this->db->query(
                            'UPDATE machines SET runtime_seconds = runtime_seconds + :seconds WHERE id = :id',
                            [':seconds' => $runtimeSeconds, ':id' => $service['machine_id']]
                        );

                        $pdo->commit();
                        return $existingId;
                    }

                    // Case B: Transition to INCOMPLETE (cancelled during recovery)
                    if ($existingStatus !== 'incomplete' && $status === 'incomplete') {
                        $this->db->query(
                            'UPDATE service_transactions
                             SET status = "incomplete",
                                 status_reason = :status_reason,
                                 actual_runtime_seconds = :actual_runtime
                             WHERE id = :id',
                            [
                                ':status_reason'  => $statusReason ?: 'POWER INTERRUPTION — SESSION CANCELLED',
                                ':actual_runtime' => ($actualRuntimeSeconds !== null) ? $actualRuntimeSeconds : 0,
                                ':id'             => $existingId
                            ]
                        );

                        $pdo->commit();
                        return $existingId;
                    }

                    // If already same status or completed, simply return existing ID
                    $pdo->rollBack();
                    return $existingId;
                }
            }

            $amountCentavos = (int) round($amount * 100);
            $coinCentavos = (int) round((float) $service['coin_value'] * 100);
            if ($amountCentavos < $coinCentavos || $amountCentavos % $coinCentavos !== 0) {
                throw new InvalidArgumentException('The amount must be a multiple of the configured coin value.');
            }

            $coinsCount = intdiv($amountCentavos, $coinCentavos);
            $runtimeSeconds = $coinsCount * (int) $service['service_seconds'];
            $completedAt = ($status === 'completed') ? date('Y-m-d H:i:s') : null;
            $actualRuntime = ($actualRuntimeSeconds !== null)
                ? $actualRuntimeSeconds
                : (($status === 'completed') ? $runtimeSeconds : 0);

            $createdAt = date('Y-m-d H:i:s');
            $this->db->query(
                'INSERT INTO service_transactions
                    (machine_id, client_transaction_id, service_id, amount, coin_value_at_purchase,
                     service_seconds_at_purchase, runtime_generated_seconds, actual_runtime_seconds, status, status_reason, completed_at, created_at)
                 VALUES (:machine_id, :client_tx_id, :service_id, :amount, :coin_value, :service_seconds, :runtime_seconds, :actual_runtime, :status, :status_reason, :completed_at, :created_at)',
                [
                    ':machine_id'      => $service['machine_id'],
                    ':client_tx_id'    => !empty($clientTxId) ? $clientTxId : null,
                    ':service_id'      => $serviceId,
                    ':amount'          => number_format($amount, 2, '.', ''),
                    ':coin_value'      => $service['coin_value'],
                    ':service_seconds' => $service['service_seconds'],
                    ':runtime_seconds' => $runtimeSeconds,
                    ':actual_runtime'  => $actualRuntime,
                    ':status'          => $status,
                    ':status_reason'   => $statusReason,
                    ':completed_at'    => $completedAt,
                    ':created_at'      => $createdAt,
                ]
            );

            $transactionId = (int) $this->db->query('SELECT LAST_INSERT_ID()')->fetchColumn();

            // Only add runtime to machine/service if the transaction is completed
            if ($status === 'completed') {
                $this->db->query(
                    'UPDATE services SET runtime_seconds = runtime_seconds + :seconds WHERE id = :id',
                    [':seconds' => $runtimeSeconds, ':id' => $serviceId]
                );
                $this->db->query(
                    'UPDATE machines SET runtime_seconds = runtime_seconds + :seconds WHERE id = :id',
                    [':seconds' => $runtimeSeconds, ':id' => $service['machine_id']]
                );
            }

            $pdo->commit();
            return $transactionId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Retrieve paginated and filtered transactions.
     *
     * @param array    $filters [search, service, status, date_from, date_to]
     * @param int      $page    Current page number (1-based)
     * @param int      $perPage Records per page
     * @param int|null $ownerId Optional owner ID for multi-tenant isolation
     * @return array [items, total_items, page, per_page, total_pages, has_prev, has_next]
     */
    public function getPaginated(array $filters = [], int $page = 1, int $perPage = 15, ?int $ownerId = null): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $where = ['1=1'];
        $params = [];

        // Owner isolation filter
        if ($ownerId !== null && $ownerId > 0) {
            $where[] = '(m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))';
            $params[':owner_id'] = $ownerId;
            $params[':owner_id2'] = $ownerId;
        }

        // Search by Transaction ID, Service Name, Machine Code, Status, or Client Transaction ID
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

        // Machine filter
        if (!empty($filters['machine']) && $filters['machine'] !== 'all') {
            $where[] = 'st.machine_id = :filter_machine_id';
            $params[':filter_machine_id'] = (int) $filters['machine'];
        }

        // Service filter
        if (!empty($filters['service']) && $filters['service'] !== 'all') {
            if (is_numeric($filters['service'])) {
                $where[] = 'st.service_id = :service_id';
                $params[':service_id'] = (int) $filters['service'];
            } else {
                $where[] = 's.service_key = :service_key';
                $params[':service_key'] = $filters['service'];
            }
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

        // Date range filter
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(st.created_at) >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(st.created_at) <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }

        $whereClause = implode(' AND ', $where);

        // Count total items
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

        // Fetch records
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

        // Format items
        foreach ($items as &$item) {
            $item['formatted_id'] = '#' . str_pad((string) $item['id'], 5, '0', STR_PAD_LEFT);
            $item['amount_formatted'] = '₱' . number_format((float) $item['amount'], 2);
            $item['purchased_time_label'] = self::formatDuration((int) $item['runtime_generated_seconds']);
            $item['actual_time_label']    = self::formatDuration((int) ($item['actual_runtime_seconds'] ?? 0));
            $item['rate_snapshot_label']  = '₱' . number_format((float) $item['coin_value_at_purchase'], 2) . ' = ' . self::formatDuration((int) $item['service_seconds_at_purchase']);
        }
        unset($item);

        return [
            'items'       => $items,
            'total_items' => $totalItems,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
            'has_prev'    => $page > 1,
            'has_next'    => $page < $totalPages,
        ];
    }

    /**
     * Compute actual sales statistics and transaction counts.
     *
     * @param string|null $period
     * @param int|null    $ownerId
     * @return array [today_sales, today_count, total_sales, total_count, water_sales, water_count, soap_sales, soap_count]
     */
    public function getSummaryStats(?string $period = 'today', ?int $ownerId = null): array
    {
        $stats = [
            'today_sales' => 0.00,
            'today_count' => 0,
            'total_sales' => 0.00,
            'total_count' => 0,
            'water_sales' => 0.00,
            'water_count' => 0,
            'soap_sales'  => 0.00,
            'soap_count'  => 0,
        ];

        try {
            $machineJoin = "INNER JOIN machines m ON m.id = st.machine_id";
            $ownerWhere  = "";
            $ownerParams = [];

            if ($ownerId !== null && $ownerId > 0) {
                $ownerWhere = " AND (m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))";
                $ownerParams = [':owner_id' => $ownerId, ':owner_id2' => $ownerId];
            }

            // Today's completed sales
            $today = $this->db->query(
                "SELECT COALESCE(SUM(st.amount), 0) AS sales, COUNT(*) AS count
                 FROM service_transactions st
                 {$machineJoin}
                 WHERE st.status = 'completed' AND DATE(COALESCE(st.completed_at, st.created_at)) = CURDATE() {$ownerWhere}",
                $ownerParams
            )->fetch();
            if ($today) {
                $stats['today_sales'] = (float) $today['sales'];
                $stats['today_count'] = (int) $today['count'];
            }

            // All-time completed sales and total count
            $totals = $this->db->query(
                "SELECT COALESCE(SUM(CASE WHEN st.status = 'completed' THEN st.amount ELSE 0 END), 0) AS total_sales,
                        COUNT(*) AS total_count
                 FROM service_transactions st
                 {$machineJoin}
                 WHERE 1=1 {$ownerWhere}",
                $ownerParams
            )->fetch();
            if ($totals) {
                $stats['total_sales'] = (float) $totals['total_sales'];
                $stats['total_count'] = (int) $totals['total_count'];
            }

            // Water sales & count
            $water = $this->db->query(
                "SELECT COALESCE(SUM(CASE WHEN st.status = 'completed' THEN st.amount ELSE 0 END), 0) AS sales,
                        COUNT(*) AS count
                 FROM service_transactions st
                 {$machineJoin}
                 INNER JOIN services s ON s.id = st.service_id
                 WHERE s.service_key = 'water' {$ownerWhere}",
                $ownerParams
            )->fetch();
            if ($water) {
                $stats['water_sales'] = (float) $water['sales'];
                $stats['water_count'] = (int) $water['count'];
            }

            // Soap sales & count
            $soap = $this->db->query(
                "SELECT COALESCE(SUM(CASE WHEN st.status = 'completed' THEN st.amount ELSE 0 END), 0) AS sales,
                        COUNT(*) AS count
                 FROM service_transactions st
                 {$machineJoin}
                 INNER JOIN services s ON s.id = st.service_id
                 WHERE s.service_key = 'soap' {$ownerWhere}",
                $ownerParams
            )->fetch();
            if ($soap) {
                $stats['soap_sales'] = (float) $soap['sales'];
                $stats['soap_count'] = (int) $soap['count'];
            }
        } catch (PDOException $e) {
            error_log('[VendoLink][TransactionStats] ' . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Returns list of available services for filters.
     *
     * @param int|null $ownerId
     * @return array
     */
    public function getServices(?int $ownerId = null): array
    {
        if ($ownerId !== null && $ownerId > 0) {
            return $this->db->query(
                'SELECT DISTINCT s.id, s.service_key, s.name, s.coin_value, s.service_seconds, s.is_enabled
                 FROM services s
                 INNER JOIN machines m ON s.machine_id = m.id
                 LEFT JOIN product_keys pk ON (pk.hardware_serial = m.machine_code OR pk.hardware_serial = m.machine_key)
                 WHERE m.owner_id = :owner_id OR pk.owner_id = :owner_id2
                 ORDER BY FIELD(s.service_key, "water", "soap"), s.id',
                [':owner_id' => $ownerId, ':owner_id2' => $ownerId]
            )->fetchAll();
        }

        return $this->db->query(
            'SELECT id, service_key, name, coin_value, service_seconds, is_enabled
             FROM services
             ORDER BY FIELD(service_key, "water", "soap"), id'
        )->fetchAll();
    }

    /**
     * Compute summary metrics for Financial Audit cards based on applied filters.
     *
     * @param array    $filters [search, service, status, date_range, date_from, date_to]
     * @param int|null $ownerId
     * @return array [total_revenue, transaction_count, completed_count, water_revenue, water_count, soap_revenue, soap_count]
     */
    public function getAuditMetrics(array $filters = [], ?int $ownerId = null): array
    {
        $dateRange = trim($filters['date_range'] ?? '');
        $dateFrom  = trim($filters['date_from'] ?? '');
        $dateTo    = trim($filters['date_to'] ?? '');

        if ($dateRange === 'today' && empty($dateFrom) && empty($dateTo)) {
            $dateFrom = date('Y-m-d');
            $dateTo   = date('Y-m-d');
        } elseif ($dateRange === 'week' && empty($dateFrom) && empty($dateTo)) {
            $dateFrom = date('Y-m-d', strtotime('monday this week'));
            $dateTo   = date('Y-m-d');
        } elseif ($dateRange === 'month' && empty($dateFrom) && empty($dateTo)) {
            $dateFrom = date('Y-m-01');
            $dateTo   = date('Y-m-d');
        }

        $where = ['1=1'];
        $params = [];

        // Owner isolation filter
        if ($ownerId !== null && $ownerId > 0) {
            $where[] = '(m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))';
            $params[':owner_id'] = $ownerId;
            $params[':owner_id2'] = $ownerId;
        }

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

        if (!empty($filters['service']) && $filters['service'] !== 'all') {
            if (is_numeric($filters['service'])) {
                $where[] = 'st.service_id = :service_id';
                $params[':service_id'] = (int) $filters['service'];
            } else {
                $where[] = 's.service_key = :service_key';
                $params[':service_key'] = $filters['service'];
            }
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

        if (!empty($dateFrom)) {
            $where[] = 'DATE(st.created_at) >= :date_from';
            $params[':date_from'] = $dateFrom;
        }
        if (!empty($dateTo)) {
            $where[] = 'DATE(st.created_at) <= :date_to';
            $params[':date_to'] = $dateTo;
        }

        $whereClause = implode(' AND ', $where);

        $sql = "SELECT
                    COALESCE(SUM(CASE WHEN st.status = 'completed' THEN st.amount ELSE 0 END), 0) AS total_revenue,
                    COUNT(*) AS transaction_count,
                    COALESCE(SUM(CASE WHEN st.status = 'completed' THEN 1 ELSE 0 END), 0) AS completed_count,
                    COALESCE(SUM(CASE WHEN st.status = 'completed' AND s.service_key = 'water' THEN st.amount ELSE 0 END), 0) AS water_revenue,
                    COALESCE(SUM(CASE WHEN s.service_key = 'water' THEN 1 ELSE 0 END), 0) AS water_count,
                    COALESCE(SUM(CASE WHEN st.status = 'completed' AND s.service_key = 'soap' THEN st.amount ELSE 0 END), 0) AS soap_revenue,
                    COALESCE(SUM(CASE WHEN s.service_key = 'soap' THEN 1 ELSE 0 END), 0) AS soap_count
                FROM service_transactions st
                LEFT JOIN services s ON s.id = st.service_id
                LEFT JOIN machines m ON m.id = st.machine_id
                WHERE {$whereClause}";

        $row = $this->db->query($sql, $params)->fetch();

        return [
            'total_revenue'     => (float) ($row['total_revenue'] ?? 0.00),
            'transaction_count' => (int) ($row['transaction_count'] ?? 0),
            'completed_count'   => (int) ($row['completed_count'] ?? 0),
            'water_revenue'     => (float) ($row['water_revenue'] ?? 0.00),
            'water_count'       => (int) ($row['water_count'] ?? 0),
            'soap_revenue'      => (float) ($row['soap_revenue'] ?? 0.00),
            'soap_count'        => (int) ($row['soap_count'] ?? 0),
        ];
    }

    /**
     * Helper to fetch all filtered transaction records without pagination.
     *
     * @param array    $filters
     * @param int|null $ownerId
     * @return array
     */
    public function getFilteredRows(array $filters = [], ?int $ownerId = null): array
    {
        $dateRange = trim($filters['date_range'] ?? '');
        $dateFrom  = trim($filters['date_from'] ?? '');
        $dateTo    = trim($filters['date_to'] ?? '');

        if ($dateRange === 'today' && empty($dateFrom) && empty($dateTo)) {
            $dateFrom = date('Y-m-d');
            $dateTo   = date('Y-m-d');
        } elseif ($dateRange === 'week' && empty($dateFrom) && empty($dateTo)) {
            $dateFrom = date('Y-m-d', strtotime('monday this week'));
            $dateTo   = date('Y-m-d');
        } elseif ($dateRange === 'month' && empty($dateFrom) && empty($dateTo)) {
            $dateFrom = date('Y-m-01');
            $dateTo   = date('Y-m-d');
        }

        $where = ['1=1'];
        $params = [];

        // Owner isolation filter
        if ($ownerId !== null && $ownerId > 0) {
            $where[] = '(m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))';
            $params[':owner_id'] = $ownerId;
            $params[':owner_id2'] = $ownerId;
        }

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

        if (!empty($filters['service']) && $filters['service'] !== 'all') {
            if (is_numeric($filters['service'])) {
                $where[] = 'st.service_id = :service_id';
                $params[':service_id'] = (int) $filters['service'];
            } else {
                $where[] = 's.service_key = :service_key';
                $params[':service_key'] = $filters['service'];
            }
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

        if (!empty($dateFrom)) {
            $where[] = 'DATE(st.created_at) >= :date_from';
            $params[':date_from'] = $dateFrom;
        }
        if (!empty($dateTo)) {
            $where[] = 'DATE(st.created_at) <= :date_to';
            $params[':date_to'] = $dateTo;
        }

        $whereClause = implode(' AND ', $where);

        $sql = "SELECT st.id,
                       st.created_at,
                       m.name AS machine_name,
                       s.name AS service_name,
                       s.service_key,
                       st.amount,
                       st.coin_value_at_purchase,
                       st.service_seconds_at_purchase,
                       st.runtime_generated_seconds,
                       st.actual_runtime_seconds,
                       st.status,
                       st.status_reason
                FROM service_transactions st
                LEFT JOIN services s ON s.id = st.service_id
                LEFT JOIN machines m ON m.id = st.machine_id
                WHERE {$whereClause}
                ORDER BY st.created_at DESC, st.id DESC";

        return $this->db->query($sql, $params)->fetchAll();
    }

    /**
     * Resolve date/range label and safe filename slug for exports.
     *
     * @param array $filters
     * @return array [label, slug]
     */
    public static function resolvePeriodInfo(array $filters): array
    {
        $dateRange = trim($filters['date_range'] ?? '');
        $dateFrom  = trim($filters['date_from'] ?? '');
        $dateTo    = trim($filters['date_to'] ?? '');

        if ($dateRange === 'today' && empty($dateFrom) && empty($dateTo)) {
            $dateFrom = date('Y-m-d');
            $dateTo   = date('Y-m-d');
        } elseif ($dateRange === 'week' && empty($dateFrom) && empty($dateTo)) {
            $dateFrom = date('Y-m-d', strtotime('monday this week'));
            $dateTo   = date('Y-m-d');
        } elseif ($dateRange === 'month' && empty($dateFrom) && empty($dateTo)) {
            $dateFrom = date('Y-m-01');
            $dateTo   = date('Y-m-d');
        }

        if (empty($dateFrom) && empty($dateTo)) {
            return [
                'label' => 'All Time',
                'slug'  => 'all-time',
            ];
        }

        if ($dateFrom === $dateTo && !empty($dateFrom)) {
            $formatted = date('F j, Y', strtotime($dateFrom));
            return [
                'label' => $formatted,
                'slug'  => $dateFrom,
            ];
        }

        if (!empty($dateFrom) && !empty($dateTo)) {
            $fromDt = strtotime($dateFrom);
            $toDt   = strtotime($dateTo);
            if (date('Y', $fromDt) === date('Y', $toDt)) {
                if (date('M', $fromDt) === date('M', $toDt)) {
                    $formatted = date('F j', $fromDt) . '–' . date('j, Y', $toDt);
                } else {
                    $formatted = date('M j', $fromDt) . ' – ' . date('M j, Y', $toDt);
                }
            } else {
                $formatted = date('M j, Y', $fromDt) . ' – ' . date('M j, Y', $toDt);
            }
            return [
                'label' => $formatted,
                'slug'  => $dateFrom . '-to-' . $dateTo,
            ];
        }

        if (!empty($dateFrom)) {
            return [
                'label' => 'From ' . date('F j, Y', strtotime($dateFrom)),
                'slug'  => 'from-' . $dateFrom,
            ];
        }

        return [
            'label' => 'Up to ' . date('F j, Y', strtotime($dateTo)),
            'slug'  => 'to-' . $dateTo,
        ];
    }

    /**
     * Resolve user-facing label for service filter key.
     *
     * @param string $serviceKey
     * @return string
     */
    public static function resolveServiceLabel(string $serviceKey): string
    {
        if ($serviceKey === 'water') {
            return 'Water Service';
        }
        if ($serviceKey === 'soap') {
            return 'Soap Service';
        }
        if (empty($serviceKey) || $serviceKey === 'all') {
            return 'All Services';
        }
        return ucfirst($serviceKey);
    }

    /**
     * Resolve user-facing label for status filter key.
     *
     * @param string $statusKey
     * @return string
     */
    public static function resolveStatusLabel(string $statusKey): string
    {
        $statusKey = strtolower(trim($statusKey));
        if (empty($statusKey) || $statusKey === 'all') {
            return 'All Statuses';
        }
        if ($statusKey === 'incomplete') {
            return 'Incomplete';
        }
        if ($statusKey === 'unsuccessful') {
            return 'Unsuccessful';
        }
        return ucfirst($statusKey);
    }

    /**
     * Export complete Sales Report CSV matching current filters.
     * Contains Title, Report Period, Filters, Summary Block, and Transaction Records table.
     *
     * @param array    $filters [search, service, status, date_from, date_to]
     * @param int|null $ownerId
     */
    public function exportSalesReport(array $filters = [], ?int $ownerId = null): void
    {
        $this->exportTransactionsCsv($filters, $ownerId);
    }

    /**
     * Export Sales Report CSV matching current filters.
     * Contains Title, Date Range, Filters, Sales Summary Block, Transaction Records table, and Bottom Totals.
     *
     * @param array    $filters [search, service, status, date_from, date_to]
     * @param int|null $ownerId
     */
    public function exportTransactionsCsv(array $filters = [], ?int $ownerId = null): void
    {
        $periodInfo = self::resolvePeriodInfo($filters);
        $rows = $this->getFilteredRows($filters, $ownerId);

        $filename = 'vendolink-sales-report-' . $periodInfo['slug'] . '.csv';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        $output = fopen('php://output', 'w');
        // UTF-8 BOM for Excel / Windows / Mac compatibility
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // 1. Report Header
        fputcsv($output, ['VendoLink — Sales Report']);
        fputcsv($output, ['Date Range', $periodInfo['label']]);
        fputcsv($output, ['Service', self::resolveServiceLabel($filters['service'] ?? 'all')]);
        fputcsv($output, ['Status', self::resolveStatusLabel($filters['status'] ?? 'all')]);
        fputcsv($output, ['Search', !empty($filters['search']) ? $filters['search'] : 'None']);
        fputcsv($output, ['Export Date & Time', date('M j, Y, g:i A')]);
        fputcsv($output, []); // blank line

        // 2. Dynamic Metric Calculations from filtered dataset
        $totalSalesAmount = 0.0;
        $completedTransactions = 0;
        $waterSales = 0.0;
        $soapSales = 0.0;
        $waterTransactions = 0;
        $soapTransactions = 0;
        $totalPurchasedSeconds = 0;

        foreach ($rows as $r) {
            $status = strtolower(trim((string)($r['status'] ?? '')));
            $isCompleted = ($status === 'completed');
            $amount = (float)($r['amount'] ?? 0);
            $purchasedSeconds = (int)($r['runtime_generated_seconds'] ?? 0);

            // Match service key or fallback to service name
            $serviceKey = strtolower(trim((string)($r['service_key'] ?? '')));
            $serviceName = strtolower(trim((string)($r['service_name'] ?? '')));
            $isWater = ($serviceKey === 'water' || strpos($serviceName, 'water') !== false);
            $isSoap  = ($serviceKey === 'soap' || strpos($serviceName, 'soap') !== false);

            if ($isCompleted) {
                $totalSalesAmount += $amount;
                $completedTransactions++;
                if ($isWater) {
                    $waterSales += $amount;
                    $waterTransactions++;
                } elseif ($isSoap) {
                    $soapSales += $amount;
                    $soapTransactions++;
                }
            }

            $totalPurchasedSeconds += $purchasedSeconds;
        }

        // 3. Sales Summary block (above transaction records)
        fputcsv($output, ['SALES SUMMARY']);
        fputcsv($output, ['Total Sales Amount', '₱' . number_format($totalSalesAmount, 2, '.', '')]);
        fputcsv($output, ['Completed Transactions', (string)$completedTransactions]);
        fputcsv($output, ['Water Sales', '₱' . number_format($waterSales, 2, '.', '')]);
        fputcsv($output, ['Soap Sales', '₱' . number_format($soapSales, 2, '.', '')]);
        fputcsv($output, ['Water Transactions', (string)$waterTransactions]);
        fputcsv($output, ['Soap Transactions', (string)$soapTransactions]);
        fputcsv($output, []); // blank line

        // 4. Transaction Records block
        fputcsv($output, ['TRANSACTION RECORDS']);

        if (empty($rows)) {
            fputcsv($output, ['No transactions found.']);
        } else {
            fputcsv($output, [
                'Transaction ID',
                'Date & Time',
                'Machine',
                'Service',
                'Amount',
                'Purchased Time',
                'Actual Time',
                'Status',
                'Reason',
            ]);

            foreach ($rows as $r) {
                fputcsv($output, [
                    '#' . str_pad((string) $r['id'], 5, '0', STR_PAD_LEFT),
                    $r['created_at'],
                    $r['machine_name'] ?: 'VendoLink Machine 01',
                    $r['service_name'] ?: 'Service',
                    '₱' . number_format((float) $r['amount'], 2, '.', ''),
                    self::formatDuration((int) $r['runtime_generated_seconds']),
                    self::formatDuration((int) ($r['actual_runtime_seconds'] ?? 0)),
                    ucfirst($r['status']),
                    $r['status_reason'] ?? '',
                ]);
            }
        }

        // 5. Bottom Totals block (below transaction table, preceded by 1 blank line)
        fputcsv($output, []);
        fputcsv($output, ['Total Sales Amount', '₱' . number_format($totalSalesAmount, 2, '.', '')]);
        fputcsv($output, ['Total Completed Transactions', (string)$completedTransactions]);
        fputcsv($output, ['Total Water Sales', '₱' . number_format($waterSales, 2, '.', '')]);
        fputcsv($output, ['Total Soap Sales', '₱' . number_format($soapSales, 2, '.', '')]);
        fputcsv($output, ['Total Purchased Time', self::formatDuration($totalPurchasedSeconds)]);

        fclose($output);
        exit;
    }

    /**
     * Alias for exportTransactionsCsv for backwards compatibility.
     *
     * @param array    $filters
     * @param int|null $ownerId
     */
    public function exportAuditCsv(array $filters = [], ?int $ownerId = null): void
    {
        $this->exportTransactionsCsv($filters, $ownerId);
    }

    /**
     * Export complete Cash Collections Report CSV matching current filters.
     * Contains Title, Date Range, Filter info, Summary Block, and Collection Records table.
     *
     * @param array    $filters [search, date_from, date_to]
     * @param int|null $ownerId
     */
    public function exportCashCollectionsCsv(array $filters = [], ?int $ownerId = null): void
    {
        $periodInfo = self::resolvePeriodInfo($filters);
        $rows = $this->getCashCollections(0, $ownerId, $filters);

        $filename = 'vendolink-cash-collections-' . $periodInfo['slug'] . '.csv';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        $output = fopen('php://output', 'w');
        // UTF-8 BOM for Excel / Windows / Mac compatibility
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // 1. Report Header
        fputcsv($output, ['VendoLink — Cash Collections Audit Report']);
        fputcsv($output, ['Date Range', $periodInfo['label']]);
        fputcsv($output, ['Search', !empty($filters['search']) ? $filters['search'] : 'None']);
        fputcsv($output, ['Export Date & Time', date('M j, Y, g:i A')]);
        fputcsv($output, []); // blank line

        // 2. Summary Calculations from filtered dataset
        $totalCollected = 0.0;
        $totalSystemSales = 0.0;
        $totalVariance = 0.0;
        $totalRecords = count($rows);

        foreach ($rows as $r) {
            $totalCollected += (float)($r['collected_amount'] ?? 0);
            $totalSystemSales += (float)($r['system_sales_amount'] ?? 0);
            $totalVariance += (float)($r['variance_amount'] ?? 0);
        }

        $varianceLabel = ($totalVariance > 0) ? '+₱' . number_format($totalVariance, 2, '.', '') . ' (Net Surplus)'
                       : (($totalVariance < 0) ? '-₱' . number_format(abs($totalVariance), 2, '.', '') . ' (Net Shortage)'
                       : '₱0.00 (Balanced)');

        // 3. Collections Summary block
        fputcsv($output, ['RECONCILIATION SUMMARY']);
        fputcsv($output, ['Total Collection Records', (string)$totalRecords]);
        fputcsv($output, ['Total Physical Cash Collected', '₱' . number_format($totalCollected, 2, '.', '')]);
        fputcsv($output, ['Total System Sales Target', '₱' . number_format($totalSystemSales, 2, '.', '')]);
        fputcsv($output, ['Total Net Variance', $varianceLabel]);
        fputcsv($output, []); // blank line

        // 4. Collection Records block
        fputcsv($output, ['COLLECTION RECORDS']);

        if (empty($rows)) {
            fputcsv($output, ['No cash collection records found.']);
        } else {
            fputcsv($output, [
                'Collection ID',
                'Collection Date & Time',
                'Machine',
                'Recorded By',
                'Physical Cash Collected',
                'System Sales Target',
                'Variance Amount',
                'Variance Status',
                'Notes',
            ]);

            foreach ($rows as $r) {
                $var = (float)($r['variance_amount'] ?? 0);
                $varStatus = ($var == 0) ? 'Matched' : (($var > 0) ? 'Above' : 'Below');
                $varFormatted = ($var > 0 ? '+₱' : ($var < 0 ? '-₱' : '₱')) . number_format(abs($var), 2, '.', '');
                $machineDisplayName = preg_replace('/^VendoLink\s+/i', '', $r['machine_name'] ?: 'Machine 01');

                fputcsv($output, [
                    '#' . str_pad((string) $r['id'], 5, '0', STR_PAD_LEFT),
                    $r['collected_at'],
                    sanitize_excel_cell($machineDisplayName),
                    sanitize_excel_cell($r['full_name'] ?? '—'),
                    '₱' . number_format((float)$r['collected_amount'], 2, '.', ''),
                    '₱' . number_format((float)$r['system_sales_amount'], 2, '.', ''),
                    $varFormatted,
                    $varStatus,
                    sanitize_excel_cell($r['notes'] ?? ''),
                ]);
            }
        }

        // 5. Bottom Totals block (below table, preceded by 1 blank line)
        fputcsv($output, []);
        fputcsv($output, ['Total Physical Cash Collected', '₱' . number_format($totalCollected, 2, '.', '')]);
        fputcsv($output, ['Total System Sales Target', '₱' . number_format($totalSystemSales, 2, '.', '')]);
        fputcsv($output, ['Total Net Variance', $varianceLabel]);
        fputcsv($output, ['Total Collection Records', (string)$totalRecords]);

        fclose($output);
        exit;
    }

    /**
     * Format duration in seconds to human readable string (e.g. 0s -> "0 sec", 120s -> "2 min", 4830s -> "1h 20m 30s").
     *
     * @param int $seconds
     * @return string
     */
    public static function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0 sec';
        }
        if ($seconds < 60) {
            return $seconds . ' sec';
        }
        $hours = intdiv($seconds, 3600);
        $rem = $seconds % 3600;
        $mins = intdiv($rem, 60);
        $secs = $rem % 60;

        if ($hours > 0) {
            $parts = [$hours . 'h'];
            if ($mins > 0) {
                $parts[] = $mins . 'm';
            }
            if ($secs > 0) {
                $parts[] = $secs . 's';
            }
            return implode(' ', $parts);
        }

        if ($secs === 0) {
            return $mins . ' min';
        }
        return $mins . 'm ' . $secs . 's';
    }

    /**
     * Get cash collection records for a machine.
     *
     * @param int        $machineId
     * @param int|null   $ownerId
     * @param array      $filters
     * @return array
     */
    public function getCashCollections(int $machineId = 0, ?int $ownerId = null, array $filters = []): array
    {
        $conditions = [];
        $params = [];

        $mid = $machineId > 0 ? $machineId : (int)($filters['machine_id'] ?? $filters['machine'] ?? 0);
        if ($mid > 0) {
            $conditions[] = "cc.machine_id = :mid";
            $params[':mid'] = $mid;
        }

        if ($ownerId !== null && $ownerId > 0) {
            $conditions[] = "(m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))";
            $params[':owner_id'] = $ownerId;
            $params[':owner_id2'] = $ownerId;
        }

        if (!empty($filters['date_from'])) {
            $conditions[] = "DATE(cc.collected_at) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $conditions[] = "DATE(cc.collected_at) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        // Reconciliation filter (Matched, Above/Excess/Override, Below/Shortage)
        $recon = strtolower(trim($filters['reconciliation'] ?? $filters['status'] ?? 'all'));
        if ($recon === 'matched') {
            $conditions[] = "ABS(cc.variance_amount) < 0.001";
        } elseif ($recon === 'above' || $recon === 'excess' || $recon === 'override') {
            $conditions[] = "(cc.variance_amount > 0.001 OR cc.reconciliation_status = 'override')";
        } elseif ($recon === 'below' || $recon === 'shortage') {
            $conditions[] = "cc.variance_amount < -0.001";
        }

        if (!empty($filters['search'])) {
            $conditions[] = "(cc.notes LIKE :search_cc1 OR cc.override_reason LIKE :search_cc2 OR u.full_name LIKE :search_cc3 OR m.name LIKE :search_cc4)";
            $params[':search_cc1'] = '%' . $filters['search'] . '%';
            $params[':search_cc2'] = '%' . $filters['search'] . '%';
            $params[':search_cc3'] = '%' . $filters['search'] . '%';
            $params[':search_cc4'] = '%' . $filters['search'] . '%';
        }

        $where = !empty($conditions) ? implode(" AND ", $conditions) : "1=1";

        return $this->db->query(
            "SELECT cc.*, u.full_name, m.name AS machine_name, m.machine_code
             FROM cash_collections cc
             JOIN users u ON cc.user_id = u.id
             JOIN machines m ON cc.machine_id = m.id
             WHERE {$where}
             ORDER BY cc.collected_at DESC",
            $params
        )->fetchAll();
    }

    /**
     * Calculate the expected uncollected cash balance for a machine.
     *
     * Expected Cash = Total Eligible Completed Sales
     *               - Total Previous Cash Collections
     *               + Total Previous Authorized Excess Overrides
     *
     * Uses integer cents arithmetic to avoid floating-point inaccuracy.
     *
     * @param int $machineId
     * @param int|null $ownerId Optional owner scoping
     * @return array
     */
    public function getExpectedCashBalance(int $machineId, ?int $ownerId = null): array
    {
        $pdo = $this->db->getConnection();

        // 1. Verify machine exists and check ownership if ownerId provided
        if ($ownerId !== null && $ownerId > 0) {
            $checkStmt = $pdo->prepare(
                "SELECT m.id, m.name, m.machine_code
                 FROM machines m
                 WHERE m.id = :mid
                   AND (m.owner_id = :owner_id OR m.id IN (
                       SELECT m2.id FROM machines m2
                       JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key)
                       WHERE pk.owner_id = :owner_id2
                   ))
                 LIMIT 1"
            );
            $checkStmt->execute([':mid' => $machineId, ':owner_id' => $ownerId, ':owner_id2' => $ownerId]);
            $machineRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if (!$machineRow) {
                return [
                    'success'              => false,
                    'error'                => 'Machine not found or access denied.',
                    'machine_id'           => $machineId,
                    'expected_balance'     => 0.00,
                    'eligible_sales'       => 0.00,
                    'previous_collections' => 0.00,
                    'authorized_overrides' => 0.00,
                    'last_collected_at'    => null,
                ];
            }
        }

        // 2. Total eligible completed sales (only status = 'completed' represents real cash)
        $salesStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM service_transactions
             WHERE machine_id = :mid AND status = 'completed'"
        );
        $salesStmt->execute([':mid' => $machineId]);
        $eligibleSales = (float)$salesStmt->fetchColumn();

        // 3. Previous physical collections accounted for
        $collStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(collected_amount), 0) FROM cash_collections
             WHERE machine_id = :mid"
        );
        $collStmt->execute([':mid' => $machineId]);
        $previousCollections = (float)$collStmt->fetchColumn();

        // 4. Authorized excess collection adjustments (is_override = 1 and variance_amount > 0)
        $authorizedOverrides = 0.0;
        try {
            $overrideStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(variance_amount), 0) FROM cash_collections
                 WHERE machine_id = :mid AND is_override = 1 AND variance_amount > 0"
            );
            $overrideStmt->execute([':mid' => $machineId]);
            $authorizedOverrides = (float)$overrideStmt->fetchColumn();
        } catch (\Throwable $e) {
            $authorizedOverrides = 0.0;
        }

        // 5. Integer-cent arithmetic
        $salesCents    = (int)round($eligibleSales * 100);
        $collCents     = (int)round($previousCollections * 100);
        $overrideCents = (int)round($authorizedOverrides * 100);

        $expectedCents = $salesCents - $collCents + $overrideCents;
        if ($expectedCents < 0) {
            $expectedCents = 0;
        }
        $expectedBalance = round($expectedCents / 100, 2);

        // 6. Last collection timestamp
        $lastStmt = $pdo->prepare(
            "SELECT collected_at FROM cash_collections
             WHERE machine_id = :mid ORDER BY collected_at DESC LIMIT 1"
        );
        $lastStmt->execute([':mid' => $machineId]);
        $lastCollectedAt = $lastStmt->fetchColumn() ?: null;

        return [
            'success'              => true,
            'machine_id'           => $machineId,
            'expected_balance'     => $expectedBalance,
            'eligible_sales'       => round($eligibleSales, 2),
            'previous_collections' => round($previousCollections, 2),
            'authorized_overrides' => round($authorizedOverrides, 2),
            'last_collected_at'    => $lastCollectedAt,
        ];
    }

    /**
     * Record a physical cash collection from coin box with strict validation & reconciliation.
     *
     * Enforces:
     * - Case A: Exact match (zero difference, status = 'matched')
     * - Case B: Shortage (difference < 0, mandatory note/reason required, uncollected balance preserved)
     * - Case C: Excess (difference > 0, strictly blocked by default unless authorized override with reason)
     * - Case D: No available cash (expected = 0, blocked unless authorized override)
     * - Case E: Invalid input (empty, zero, negative, malformed)
     * - Concurrency: Row locking (FOR UPDATE) inside PDO transaction
     *
     * @param int $userId
     * @param int $machineId
     * @param float $collectedAmount
     * @param string|null $notes
     * @param bool $isOverride
     * @param string|null $overrideReason
     * @param int|null $overrideUserId
     * @param string|null $userRole
     * @return int Inserted cash collection ID
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function recordCashCollection(
        int $userId,
        int $machineId,
        float $collectedAmount,
        ?string $notes = null,
        bool $isOverride = false,
        ?string $overrideReason = null,
        ?int $overrideUserId = null,
        ?string $userRole = null
    ): int {
        // Case E: Invalid input validation
        if ($collectedAmount <= 0 || is_nan($collectedAmount) || is_infinite($collectedAmount)) {
            throw new InvalidArgumentException("Please enter a valid positive cash collection amount.");
        }

        $enteredCents = (int)round($collectedAmount * 100);
        if ($enteredCents <= 0) {
            throw new InvalidArgumentException("Please enter a valid cash amount greater than zero.");
        }

        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();

        try {
            // Lock machine row to prevent concurrent race conditions
            $lockStmt = $pdo->prepare("SELECT id, name FROM machines WHERE id = :mid FOR UPDATE");
            $lockStmt->execute([':mid' => $machineId]);
            $machineRow = $lockStmt->fetch(PDO::FETCH_ASSOC);
            if (!$machineRow) {
                throw new RuntimeException("Target machine #{$machineId} not found.");
            }

            // Calculate live expected cash balance inside transaction
            $salesStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM service_transactions
                 WHERE machine_id = :mid AND status = 'completed'"
            );
            $salesStmt->execute([':mid' => $machineId]);
            $eligibleSales = (float)$salesStmt->fetchColumn();

            $collStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(collected_amount), 0) FROM cash_collections
                 WHERE machine_id = :mid"
            );
            $collStmt->execute([':mid' => $machineId]);
            $previousCollections = (float)$collStmt->fetchColumn();

            $authorizedOverrides = 0.0;
            try {
                $overrideStmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(variance_amount), 0) FROM cash_collections
                     WHERE machine_id = :mid AND is_override = 1 AND variance_amount > 0"
                );
                $overrideStmt->execute([':mid' => $machineId]);
                $authorizedOverrides = (float)$overrideStmt->fetchColumn();
            } catch (\Throwable $e) {
                $authorizedOverrides = 0.0;
            }

            $salesCents    = (int)round($eligibleSales * 100);
            $prevCollCents = (int)round($previousCollections * 100);
            $prevOverCents = (int)round($authorizedOverrides * 100);

            $expectedCents = $salesCents - $prevCollCents + $prevOverCents;
            if ($expectedCents < 0) {
                $expectedCents = 0;
            }

            $expectedAmount = round($expectedCents / 100, 2);
            $diffCents      = $enteredCents - $expectedCents;
            $diffAmount     = round($diffCents / 100, 2);

            $reconStatus        = 'matched';
            $isOverrideFlag     = 0;
            $cleanOverrideReason = null;
            $cleanOverrideUserId = null;
            $uncollectedBalance = 0.00;

            // Resolve role for authorization check if needed
            if ($userRole === null && $overrideUserId !== null) {
                $uStmt = $pdo->prepare("SELECT role FROM users WHERE id = :uid");
                $uStmt->execute([':uid' => $overrideUserId]);
                $userRole = (string)$uStmt->fetchColumn();
            } elseif ($userRole === null && $userId > 0) {
                $uStmt = $pdo->prepare("SELECT role FROM users WHERE id = :uid");
                $uStmt->execute([':uid' => $userId]);
                $userRole = (string)$uStmt->fetchColumn();
            }
            $userRoleNormalized = strtolower(trim((string)$userRole));
            $isAuthorizedRole   = in_array($userRoleNormalized, ['administrator', 'admin', 'owner'], true);

            // Case D: Zero expected balance
            if ($expectedCents === 0) {
                if ($isOverride) {
                    if (!$isAuthorizedRole) {
                        throw new InvalidArgumentException("Permission denied. Only Owners and Administrators can authorize an override.");
                    }
                    if (empty(trim($overrideReason ?? '')) || strlen(trim($overrideReason)) < 5) {
                        throw new InvalidArgumentException("A detailed explanation (at least 5 characters) is required when authorizing an override.");
                    }
                    $reconStatus         = 'override';
                    $isOverrideFlag      = 1;
                    $cleanOverrideReason = trim($overrideReason);
                    $cleanOverrideUserId = $overrideUserId ?: $userId;
                    $uncollectedBalance  = 0.00;
                } else {
                    throw new InvalidArgumentException("No eligible uncollected cash is available for this machine (Expected: ₱0.00). Cannot record collection without an authorized override.");
                }
            }
            // Case A: Exact match
            elseif ($diffCents === 0) {
                $reconStatus        = 'matched';
                $isOverrideFlag     = 0;
                $uncollectedBalance = 0.00;
            }
            // Case B: Shortage
            elseif ($diffCents < 0) {
                $cleanNotes = trim($notes ?? '');
                if (empty($cleanNotes)) {
                    throw new InvalidArgumentException("A note or explanation is required when the collected cash (₱" . number_format($collectedAmount, 2) . ") is less than the expected balance of ₱" . number_format($expectedAmount, 2) . ".");
                }
                $reconStatus        = 'shortage';
                $isOverrideFlag     = 0;
                $uncollectedBalance = round(abs($diffCents) / 100, 2);
            }
            // Case C: Excess collection
            elseif ($diffCents > 0) {
                if (!$isOverride) {
                    $excessAmount = round($diffCents / 100, 2);
                    throw new InvalidArgumentException("Collected cash (₱" . number_format($collectedAmount, 2) . ") exceeds the expected machine balance of ₱" . number_format($expectedAmount, 2) . " by ₱" . number_format($excessAmount, 2) . ". Excess collections are blocked by default unless authorized with an override explanation.");
                }

                if (!$isAuthorizedRole) {
                    throw new InvalidArgumentException("Permission denied. Only Owners and Administrators can authorize an excess collection override.");
                }

                if (empty(trim($overrideReason ?? '')) || strlen(trim($overrideReason)) < 5) {
                    throw new InvalidArgumentException("A detailed explanation (at least 5 characters) is required when overriding an excess cash collection.");
                }

                $reconStatus         = 'override';
                $isOverrideFlag      = 1;
                $cleanOverrideReason = trim($overrideReason);
                $cleanOverrideUserId = $overrideUserId ?: $userId;
                $uncollectedBalance  = 0.00;
            }

            // Insert reconciliation record
            $insertStmt = $pdo->prepare(
                "INSERT INTO cash_collections (
                    user_id, machine_id, expected_amount, collected_amount,
                    system_sales_amount, variance_amount, reconciliation_status,
                    is_override, override_reason, override_user_id,
                    uncollected_balance, notes, collected_at, created_at
                ) VALUES (
                    :uid, :mid, :exp_amt, :c_amt,
                    :s_amt, :var_amt, :recon_status,
                    :is_override, :override_reason, :override_uid,
                    :uncoll_bal, :notes, NOW(), NOW()
                )"
            );

            $insertStmt->execute([
                ':uid'             => $userId,
                ':mid'             => $machineId,
                ':exp_amt'         => $expectedAmount,
                ':c_amt'           => $collectedAmount,
                ':s_amt'           => $expectedAmount,
                ':var_amt'         => $diffAmount,
                ':recon_status'    => $reconStatus,
                ':is_override'     => $isOverrideFlag,
                ':override_reason' => $cleanOverrideReason,
                ':override_uid'    => $cleanOverrideUserId,
                ':uncoll_bal'      => $uncollectedBalance,
                ':notes'           => $notes,
            ]);

            $collId = (int)$pdo->lastInsertId();
            $pdo->commit();

            return $collId;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
