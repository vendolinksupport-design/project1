<?php
/**
 * ============================================================
 * VendoLink — Report Model
 * ============================================================
 *
 * Calculates performance analytics, revenue breakdowns, sales over time,
 * runtime summaries, and maintenance statistics for selected periods.
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Transaction.php';

class Report
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Resolve date bounds and human-readable label from preset or custom input.
     *
     * @param string      $preset     'today' | 'week' | 'month' | 'custom'
     * @param string|null $customFrom
     * @param string|null $customTo
     * @return array [date_from, date_to, label, type]
     */
    public function resolvePeriod(string $preset, ?string $customFrom = null, ?string $customTo = null): array
    {
        $today = date('Y-m-d');

        if ($preset === 'today') {
            return [
                'date_from' => $today,
                'date_to'   => $today,
                'label'     => "Today's Report — " . date('F j, Y'),
                'type'      => 'today',
            ];
        }

        if ($preset === 'week') {
            $weekStart = date('Y-m-d', strtotime('monday this week'));
            return [
                'date_from' => $weekStart,
                'date_to'   => $today,
                'label'     => 'This Week — ' . date('M j', strtotime($weekStart)) . ' to ' . date('M j, Y', strtotime($today)),
                'type'      => 'week',
            ];
        }

        if ($preset === 'month') {
            $monthStart = date('Y-m-01');
            return [
                'date_from' => $monthStart,
                'date_to'   => $today,
                'label'     => 'This Month — ' . date('M j', strtotime($monthStart)) . ' to ' . date('M j, Y', strtotime($today)),
                'type'      => 'month',
            ];
        }

        // Custom range fallback
        $from = !empty($customFrom) ? $customFrom : date('Y-m-01');
        $to   = !empty($customTo) ? $customTo : $today;

        if ($from > $to) {
            $temp = $from;
            $from = $to;
            $to   = $temp;
        }

        $fromDt = new DateTime($from);
        $toDt   = new DateTime($to);

        $label = ($from === $to)
            ? 'Single Day — ' . $fromDt->format('F j, Y')
            : 'Custom Range — ' . $fromDt->format('M j, Y') . ' to ' . $toDt->format('M j, Y');

        return [
            'date_from' => $from,
            'date_to'   => $to,
            'label'     => $label,
            'type'      => ($from === $to) ? 'today' : 'custom',
        ];
    }

    /**
     * Compute summary financial metrics for the selected period.
     *
     * @param string   $dateFrom
     * @param string   $dateTo
     * @param string   $serviceFilter 'all' | 'water' | 'soap'
     * @param int|null $ownerId
     * @return array
     */
    public function getSummaryMetrics(string $dateFrom, string $dateTo, string $serviceFilter = 'all', ?int $ownerId = null, ?int $machineId = null): array
    {
        $params = [
            ':date_from' => $dateFrom,
            ':date_to'   => $dateTo,
        ];

        $ownerClause = '';
        if ($ownerId !== null && $ownerId > 0) {
            $ownerClause = ' AND (m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))';
            $params[':owner_id']  = $ownerId;
            $params[':owner_id2'] = $ownerId;
        }

        $machineClause = '';
        if ($machineId !== null && $machineId > 0) {
            $machineClause = ' AND st.machine_id = :filter_machine_id';
            $params[':filter_machine_id'] = $machineId;
        }

        $serviceClause = '';
        if ($serviceFilter !== 'all' && ($serviceFilter === 'water' || $serviceFilter === 'soap')) {
            $serviceClause = ' AND s.service_key = :service_key';
            $params[':service_key'] = $serviceFilter;
        }

        // 1. Overall Completed Revenue and Transactions in Period (using COALESCE to gracefully handle timezone offsets)
        $sql = "SELECT COALESCE(SUM(st.amount), 0) AS total_revenue,
                       COUNT(*) AS total_transactions,
                       COALESCE(SUM(st.runtime_generated_seconds), 0) AS total_purchased_seconds
                FROM service_transactions st
                INNER JOIN services s ON s.id = st.service_id
                INNER JOIN machines m ON m.id = st.machine_id
                WHERE st.status = 'completed'
                  AND DATE(COALESCE(st.completed_at, st.created_at)) >= :date_from
                  AND DATE(COALESCE(st.completed_at, st.created_at)) <= :date_to
                  {$serviceClause}
                  {$machineClause}
                  {$ownerClause}";

        $row = $this->db->query($sql, $params)->fetch();
        $totalRevenue          = (float) ($row['total_revenue'] ?? 0);
        $totalTransactions     = (int) ($row['total_transactions'] ?? 0);
        $totalPurchasedSeconds = (int) ($row['total_purchased_seconds'] ?? 0);

        // 2. Water Breakdown in Period
        $waterParams = [':date_from' => $dateFrom, ':date_to' => $dateTo];
        $waterOwnerClause = '';
        if ($ownerId !== null && $ownerId > 0) {
            $waterOwnerClause = ' AND (m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))';
            $waterParams[':owner_id']  = $ownerId;
            $waterParams[':owner_id2'] = $ownerId;
        }
        if ($machineId !== null && $machineId > 0) {
            $waterParams[':filter_machine_id'] = $machineId;
        }

        $waterSql = "SELECT COALESCE(SUM(st.amount), 0) AS revenue,
                            COUNT(*) AS count,
                            COALESCE(SUM(st.runtime_generated_seconds), 0) AS purchased_seconds
                     FROM service_transactions st
                     INNER JOIN services s ON s.id = st.service_id
                     INNER JOIN machines m ON m.id = st.machine_id
                     WHERE st.status = 'completed'
                       AND s.service_key = 'water'
                       AND DATE(COALESCE(st.completed_at, st.created_at)) >= :date_from
                       AND DATE(COALESCE(st.completed_at, st.created_at)) <= :date_to
                       {$machineClause}
                       {$waterOwnerClause}";
        $water = $this->db->query($waterSql, $waterParams)->fetch();

        $waterRevenue          = (float) ($water['revenue'] ?? 0);
        $waterCount            = (int) ($water['count'] ?? 0);
        $waterPurchasedSeconds = (int) ($water['purchased_seconds'] ?? 0);

        // 3. Soap Breakdown in Period
        $soap = $this->db->query(
            "SELECT COALESCE(SUM(st.amount), 0) AS revenue,
                    COUNT(*) AS count,
                    COALESCE(SUM(st.runtime_generated_seconds), 0) AS purchased_seconds
             FROM service_transactions st
             INNER JOIN services s ON s.id = st.service_id
             INNER JOIN machines m ON m.id = st.machine_id
             WHERE st.status = 'completed'
               AND s.service_key = 'soap'
               AND DATE(COALESCE(st.completed_at, st.created_at)) >= :date_from
               AND DATE(COALESCE(st.completed_at, st.created_at)) <= :date_to
               {$machineClause}
               {$waterOwnerClause}",
            $waterParams
        )->fetch();

        $soapRevenue          = (float) ($soap['revenue'] ?? 0);
        $soapCount            = (int) ($soap['count'] ?? 0);
        $soapPurchasedSeconds = (int) ($soap['purchased_seconds'] ?? 0);

        // Percentage shares
        $waterShare = ($totalRevenue > 0) ? round(($waterRevenue / $totalRevenue) * 100, 1) : 0;
        $soapShare  = ($totalRevenue > 0) ? round(($soapRevenue / $totalRevenue) * 100, 1) : 0;

        return [
            'total_revenue'           => $totalRevenue,
            'total_transactions'      => $totalTransactions,
            'total_purchased_seconds' => $totalPurchasedSeconds,
            'total_purchased_label'   => Transaction::formatDuration($totalPurchasedSeconds),
            'avg_transaction_value'   => ($totalTransactions > 0) ? round($totalRevenue / $totalTransactions, 2) : 0.00,

            // Water
            'water_revenue'           => $waterRevenue,
            'water_count'             => $waterCount,
            'water_purchased_seconds' => $waterPurchasedSeconds,
            'water_purchased_label'   => Transaction::formatDuration($waterPurchasedSeconds),
            'water_share'             => $waterShare,

            // Soap
            'soap_revenue'            => $soapRevenue,
            'soap_count'              => $soapCount,
            'soap_purchased_seconds'  => $soapPurchasedSeconds,
            'soap_purchased_label'    => Transaction::formatDuration($soapPurchasedSeconds),
            'soap_share'              => $soapShare,
        ];
    }

    /**
     * Retrieve individual financial metrics for each machine in the owner fleet.
     * Allows owners to see per-machine performance alongside total fleet revenue.
     *
     * @param int    $ownerId
     * @param string $dateFrom
     * @param string $dateTo
     * @return array
     */
    public function getFleetMachineMetrics(int $ownerId, string $dateFrom, string $dateTo): array
    {
        $sql = "SELECT m.id, m.name, m.machine_code, m.machine_type, m.status,
                       COALESCE(today_stats.period_revenue, 0) AS period_revenue,
                       COALESCE(today_stats.period_transactions, 0) AS period_transactions,
                       COALESCE(life_stats.lifetime_revenue, 0) AS lifetime_revenue
                FROM machines m
                LEFT JOIN (
                    SELECT st.machine_id,
                           COALESCE(SUM(st.amount), 0) AS period_revenue,
                           COUNT(*) AS period_transactions
                    FROM service_transactions st
                    WHERE st.status = 'completed'
                      AND DATE(COALESCE(st.completed_at, st.created_at)) >= :date_from
                      AND DATE(COALESCE(st.completed_at, st.created_at)) <= :date_to
                    GROUP BY st.machine_id
                ) today_stats ON today_stats.machine_id = m.id
                LEFT JOIN (
                    SELECT st.machine_id,
                           COALESCE(SUM(st.amount), 0) AS lifetime_revenue
                    FROM service_transactions st
                    WHERE st.status = 'completed'
                    GROUP BY st.machine_id
                ) life_stats ON life_stats.machine_id = m.id
                WHERE (m.owner_id = :owner_id OR m.id IN (
                    SELECT m2.id FROM machines m2 
                    JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) 
                    WHERE pk.owner_id = :owner_id2
                ))
                ORDER BY period_revenue DESC, m.name ASC";

        return $this->db->query($sql, [
            ':date_from' => $dateFrom,
            ':date_to'   => $dateTo,
            ':owner_id'  => $ownerId,
            ':owner_id2' => $ownerId,
        ])->fetchAll();
    }

    /**
     * Generate structured time-series data for the Sales Over Time chart.
     *
     * @param string   $dateFrom
     * @param string   $dateTo
     * @param string   $periodType 'today' | 'week' | 'month' | 'custom'
     * @param string   $serviceFilter
     * @param int|null $ownerId
     * @return array [labels, water_data, soap_data, total_data, max_value]
     */
    public function getTimeSeries(string $dateFrom, string $dateTo, string $periodType, string $serviceFilter = 'all', ?int $ownerId = null, ?int $machineId = null): array
    {
        $isSingleDay = ($dateFrom === $dateTo);

        $ownerClause = '';
        $tsParams    = [':date_from' => $dateFrom];
        if ($ownerId !== null && $ownerId > 0) {
            $ownerClause = ' AND (m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))';
            $tsParams[':owner_id']  = $ownerId;
            $tsParams[':owner_id2'] = $ownerId;
        }

        $machineClause = '';
        if ($machineId !== null && $machineId > 0) {
            $machineClause = ' AND st.machine_id = :filter_machine_id';
            $tsParams[':filter_machine_id'] = $machineId;
        }

        if ($isSingleDay) {
            // Hourly breakdown (00:00 to 23:00)
            $labels = [];
            $waterData = [];
            $soapData  = [];
            $totalData = [];

            // Query actual completed sales grouped by HOUR
            $sql = "SELECT HOUR(COALESCE(st.completed_at, st.created_at)) AS hr,
                           s.service_key,
                           COALESCE(SUM(st.amount), 0) AS revenue
                    FROM service_transactions st
                    INNER JOIN services s ON s.id = st.service_id
                    INNER JOIN machines m ON m.id = st.machine_id
                    WHERE st.status = 'completed'
                      AND DATE(COALESCE(st.completed_at, st.created_at)) = :date_from
                      {$machineClause}
                      {$ownerClause}
                    GROUP BY hr, s.service_key";
            $rows = $this->db->query($sql, $tsParams)->fetchAll();

            $hourMap = [];
            foreach ($rows as $r) {
                $hr = (int) $r['hr'];
                $key = $r['service_key'];
                $hourMap[$hr][$key] = (float) $r['revenue'];
            }

            // Fill all 24 hours
            for ($h = 0; $h < 24; $h++) {
                $label = date('g A', strtotime("{$h}:00:00"));
                $w = $hourMap[$h]['water'] ?? 0.0;
                $s = $hourMap[$h]['soap'] ?? 0.0;

                if ($serviceFilter === 'water') {
                    $s = 0.0;
                } elseif ($serviceFilter === 'soap') {
                    $w = 0.0;
                }

                $t = $w + $s;

                $labels[]    = $label;
                $waterData[] = $w;
                $soapData[]  = $s;
                $totalData[] = $t;
            }

            $maxValue = max(array_merge([0], $totalData));

            return [
                'interval'   => 'hourly',
                'labels'     => $labels,
                'water_data' => $waterData,
                'soap_data'  => $soapData,
                'total_data' => $totalData,
                'max_value'  => $maxValue,
            ];
        }

        // Multi-day breakdown (Daily)
        $start = new DateTime($dateFrom);
        $end   = new DateTime($dateTo);
        $end->modify('+1 day'); // inclusive

        $period = new DatePeriod($start, new DateInterval('P1D'), $end);

        $multiParams = array_merge([':date_from' => $dateFrom, ':date_to' => $dateTo], ($ownerId !== null && $ownerId > 0) ? [':owner_id' => $ownerId, ':owner_id2' => $ownerId] : []);
        if ($machineId !== null && $machineId > 0) {
            $multiParams[':filter_machine_id'] = $machineId;
        }

        $sql = "SELECT DATE(COALESCE(st.completed_at, st.created_at)) AS dt,
                       s.service_key,
                       COALESCE(SUM(st.amount), 0) AS revenue
                FROM service_transactions st
                INNER JOIN services s ON s.id = st.service_id
                INNER JOIN machines m ON m.id = st.machine_id
                WHERE st.status = 'completed'
                  AND DATE(COALESCE(st.completed_at, st.created_at)) >= :date_from
                  AND DATE(COALESCE(st.completed_at, st.created_at)) <= :date_to
                  {$machineClause}
                  {$ownerClause}
                GROUP BY dt, s.service_key";
        $rows = $this->db->query($sql, $multiParams)->fetchAll();

        $dayMap = [];
        foreach ($rows as $r) {
            $dt  = $r['dt'];
            $key = $r['service_key'];
            $dayMap[$dt][$key] = (float) $r['revenue'];
        }

        $labels = [];
        $waterData = [];
        $soapData  = [];
        $totalData = [];

        foreach ($period as $d) {
            $dateStr = $d->format('Y-m-d');
            $label   = $d->format('M j');

            $w = $dayMap[$dateStr]['water'] ?? 0.0;
            $s = $dayMap[$dateStr]['soap'] ?? 0.0;

            if ($serviceFilter === 'water') {
                $s = 0.0;
            } elseif ($serviceFilter === 'soap') {
                $w = 0.0;
            }

            $t = $w + $s;

            $labels[]    = $label;
            $waterData[] = $w;
            $soapData[]  = $s;
            $totalData[] = $t;
        }

        $maxValue = max(array_merge([0], $totalData));

        return [
            'interval'   => 'daily',
            'labels'     => $labels,
            'water_data' => $waterData,
            'soap_data'  => $soapData,
            'total_data' => $totalData,
            'max_value'  => $maxValue,
        ];
    }

    /**
     * Compute maintenance events for the period and current equipment health.
     *
     * @param string   $dateFrom
     * @param string   $dateTo
     * @param int|null $ownerId
     * @return array
     */
    public function getMaintenanceSummary(string $dateFrom, string $dateTo, ?int $ownerId = null): array
    {
        $params = [':date_from' => $dateFrom, ':date_to' => $dateTo];
        $ownerClause = '';
        $itemOwnerClause = '';
        if ($ownerId !== null && $ownerId > 0) {
            $ownerClause = ' AND (m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))';
            $itemOwnerClause = ' AND (m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))';
            $params[':owner_id']  = $ownerId;
            $params[':owner_id2'] = $ownerId;
        }

        // 1. Completed maintenance in the reporting period
        $completedSql = "SELECT mh.*, mi.name AS maintenance_name, s.name AS service_name, u.full_name
                         FROM maintenance_history mh
                         INNER JOIN maintenance_items mi ON mi.id = mh.maintenance_item_id
                         INNER JOIN machines m ON m.id = mi.machine_id
                         LEFT JOIN services s ON s.id = mi.service_id
                         LEFT JOIN users u ON u.id = mh.completed_by_user_id
                         WHERE DATE(mh.completed_at) >= :date_from
                           AND DATE(mh.completed_at) <= :date_to
                           {$ownerClause}
                         ORDER BY mh.completed_at DESC
                         LIMIT 10";
        $completedRows = $this->db->query($completedSql, $params)->fetchAll();

        $completedCount = (int) $this->db->query(
            "SELECT COUNT(*) FROM maintenance_history mh
             INNER JOIN maintenance_items mi ON mi.id = mh.maintenance_item_id
             INNER JOIN machines m ON m.id = mi.machine_id
             WHERE DATE(mh.completed_at) >= :date_from AND DATE(mh.completed_at) <= :date_to {$ownerClause}",
            $params
        )->fetchColumn();

        // 2. Active configured reminders summary
        $reminderParams = [];
        if ($ownerId !== null && $ownerId > 0) {
            $reminderParams = [':owner_id' => $ownerId, ':owner_id2' => $ownerId];
        }
        $remindersCount = (int) $this->db->query(
            "SELECT COUNT(*) FROM maintenance_items mi
             INNER JOIN machines m ON m.id = mi.machine_id
             WHERE mi.is_enabled = 1 AND mi.is_deleted = 0 {$itemOwnerClause}",
            $reminderParams
        )->fetchColumn();

        return [
            'period_completed_count' => $completedCount,
            'active_reminders_count' => $remindersCount,
            'recent_completions'     => $completedRows,
        ];
    }

    /**
     * Export completed transactions matching the period and filter as CSV.
     *
     * @param string   $dateFrom
     * @param string   $dateTo
     * @param string   $serviceFilter
     * @param int|null $ownerId
     */
    public function exportCsv(string $dateFrom, string $dateTo, string $serviceFilter = 'all', ?int $ownerId = null): void
    {
        $params = [
            ':date_from' => $dateFrom,
            ':date_to'   => $dateTo,
        ];

        $ownerClause = '';
        if ($ownerId !== null && $ownerId > 0) {
            $ownerClause = ' AND (m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))';
            $params[':owner_id']  = $ownerId;
            $params[':owner_id2'] = $ownerId;
        }

        $serviceClause = '';
        if ($serviceFilter !== 'all' && ($serviceFilter === 'water' || $serviceFilter === 'soap')) {
            $serviceClause = ' AND s.service_key = :service_key';
            $params[':service_key'] = $serviceFilter;
        }

        $sql = "SELECT st.id,
                       st.created_at,
                       m.name AS machine_name,
                       s.name AS service_name,
                       st.amount,
                       st.coin_value_at_purchase,
                       st.service_seconds_at_purchase,
                       st.runtime_generated_seconds,
                       st.status
                FROM service_transactions st
                LEFT JOIN services s ON s.id = st.service_id
                LEFT JOIN machines m ON m.id = st.machine_id
                WHERE DATE(st.created_at) >= :date_from
                  AND DATE(st.created_at) <= :date_to
                  {$serviceClause}
                  {$ownerClause}
                ORDER BY st.created_at DESC, st.id DESC";

        $rows = $this->db->query($sql, $params)->fetchAll();

        $filename = ($dateFrom === $dateTo)
            ? 'vendolink-report-' . $dateFrom . '.csv'
            : 'vendolink-report-' . $dateFrom . '-to-' . $dateTo . '.csv';

        // Clear any active output buffers to prevent stray whitespace
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

        // CSV Header
        fputcsv($output, [
            'Transaction ID',
            'Date & Time',
            'Machine',
            'Service',
            'Amount (PHP)',
            'Rate Snapshot (PHP)',
            'Purchased Time (Seconds)',
            'Purchased Time (Formatted)',
            'Status',
        ]);

        foreach ($rows as $r) {
            fputcsv($output, [
                '#' . str_pad((string) $r['id'], 5, '0', STR_PAD_LEFT),
                $r['created_at'],
                $r['machine_name'] ?: 'VendoLink Machine 01',
                $r['service_name'] ?: 'Service',
                number_format((float) $r['amount'], 2, '.', ''),
                number_format((float) $r['coin_value_at_purchase'], 2, '.', ''),
                $r['runtime_generated_seconds'],
                Transaction::formatDuration((int) $r['runtime_generated_seconds']),
                ucfirst($r['status']),
            ]);
        }

        fclose($output);
        exit;
    }

    /**
     * Compute comprehensive sales analytics, time-series data, and period-over-period
     * comparisons for the interactive Sales Analytics graph.
     *
    /**
     * Compute real-time dynamic sales analytics and trend data for the Sales Overview graph.
     *
     * @param string   $period     'today' | 'week' | 'month' | 'year' | 'all'
     * @param int|null $ownerId
     * @param int|null $machineId  Optional machine filter
     * @param string   $service    'all' | 'water' | 'soap'
     * @return array
     */
    public function getSalesAnalytics(
        string $period = 'today',
        ?int $ownerId = null,
        ?int $machineId = null,
        string $service = 'all'
    ): array {
        $period = strtolower(trim($period));
        if (!in_array($period, ['today', 'week', 'month', 'year', 'all'], true)) {
            $period = 'today';
        }

        $now = new DateTime('now');
        $todayStr = $now->format('Y-m-d');

        $ownerClause = '';
        $baseParams = [];
        if ($ownerId !== null && $ownerId > 0) {
            $ownerClause = ' AND (m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))';
            $baseParams[':owner_id']  = $ownerId;
            $baseParams[':owner_id2'] = $ownerId;
        }

        $machineClause = '';
        if ($machineId !== null && $machineId > 0) {
            $machineClause = ' AND st.machine_id = :filter_machine_id';
            $baseParams[':filter_machine_id'] = $machineId;
        }

        $service = strtolower(trim($service));
        $serviceClause = '';
        if ($service === 'water' || $service === 'soap') {
            $serviceClause = ' AND s.service_key = :filter_service_key';
            $baseParams[':filter_service_key'] = $service;
        }

        $labels = [];
        $totalData = [];
        $waterData = [];
        $soapData = [];
        $timeGrouping = 'hourly';
        $periodLabel = '';
        $prevPeriodLabel = '';
        $dateFrom = '';
        $dateTo = '';
        $prevDateFrom = '';
        $prevDateTo = '';

        if ($period === 'today') {
            $dateFrom = $todayStr;
            $dateTo = $todayStr;
            $prevDateFrom = date('Y-m-d', strtotime('-1 day'));
            $prevDateTo = $prevDateFrom;
            $periodLabel = "Today (" . date('M j, Y') . ")";
            $prevPeriodLabel = "vs yesterday";
            $timeGrouping = 'hourly';

            // Query current day hourly data
            $sql = "SELECT HOUR(COALESCE(st.completed_at, st.created_at)) AS hr,
                           s.service_key,
                           COALESCE(SUM(st.amount), 0) AS revenue
                    FROM service_transactions st
                    INNER JOIN services s ON s.id = st.service_id
                    INNER JOIN machines m ON m.id = st.machine_id
                    WHERE st.status = 'completed'
                      AND DATE(COALESCE(st.completed_at, st.created_at)) = :date_from
                      {$ownerClause} {$machineClause} {$serviceClause}
                    GROUP BY hr, s.service_key";
            $rows = $this->db->query($sql, array_merge($baseParams, [':date_from' => $dateFrom]))->fetchAll();

            $hourMap = [];
            foreach ($rows as $r) {
                $hr = (int) $r['hr'];
                $key = $r['service_key'];
                $hourMap[$hr][$key] = (float) $r['revenue'];
            }

            for ($h = 0; $h < 24; $h++) {
                $labels[] = date('g A', strtotime("{$h}:00:00"));
                $w = $hourMap[$h]['water'] ?? 0.0;
                $s = $hourMap[$h]['soap'] ?? 0.0;
                if ($service === 'water') {
                    $s = 0.0;
                } elseif ($service === 'soap') {
                    $w = 0.0;
                }
                $waterData[] = round($w, 2);
                $soapData[]  = round($s, 2);
                $totalData[] = round($w + $s, 2);
            }

        } elseif ($period === 'week') {
            $monday = date('Y-m-d', strtotime('monday this week'));
            $sunday = date('Y-m-d', strtotime('sunday this week'));
            $dateFrom = $monday;
            $dateTo = $sunday;
            $prevDateFrom = date('Y-m-d', strtotime('monday last week'));
            $prevDateTo = date('Y-m-d', strtotime('sunday last week'));
            $periodLabel = "This Week (" . date('M j', strtotime($monday)) . " - " . date('M j, Y', strtotime($sunday)) . ")";
            $prevPeriodLabel = "vs last week";
            $timeGrouping = 'daily';

            $sql = "SELECT DATE(COALESCE(st.completed_at, st.created_at)) AS dt,
                           s.service_key,
                           COALESCE(SUM(st.amount), 0) AS revenue
                    FROM service_transactions st
                    INNER JOIN services s ON s.id = st.service_id
                    INNER JOIN machines m ON m.id = st.machine_id
                    WHERE st.status = 'completed'
                      AND DATE(COALESCE(st.completed_at, st.created_at)) >= :date_from
                      AND DATE(COALESCE(st.completed_at, st.created_at)) <= :date_to
                      {$ownerClause} {$machineClause} {$serviceClause}
                    GROUP BY dt, s.service_key";
            $rows = $this->db->query($sql, array_merge($baseParams, [':date_from' => $dateFrom, ':date_to' => $dateTo]))->fetchAll();

            $dayMap = [];
            foreach ($rows as $r) {
                $dt  = $r['dt'];
                $key = $r['service_key'];
                $dayMap[$dt][$key] = (float) $r['revenue'];
            }

            // Loop Monday to Sunday (7 days)
            for ($i = 0; $i < 7; $i++) {
                $curDate = date('Y-m-d', strtotime("{$monday} +{$i} days"));
                $labels[] = date('D (M j)', strtotime($curDate));
                $w = $dayMap[$curDate]['water'] ?? 0.0;
                $s = $dayMap[$curDate]['soap'] ?? 0.0;
                if ($service === 'water') {
                    $s = 0.0;
                } elseif ($service === 'soap') {
                    $w = 0.0;
                }
                $waterData[] = round($w, 2);
                $soapData[]  = round($s, 2);
                $totalData[] = round($w + $s, 2);
            }

        } elseif ($period === 'month') {
            $monthStart = date('Y-m-01');
            $monthEnd = date('Y-m-t');
            $dateFrom = $monthStart;
            $dateTo = $monthEnd;
            $prevDateFrom = date('Y-m-01', strtotime('-1 month'));
            $prevDateTo = date('Y-m-t', strtotime('-1 month'));
            $periodLabel = "This Month (" . date('F Y') . ")";
            $prevPeriodLabel = "vs last month";
            $timeGrouping = 'daily';

            $sql = "SELECT DATE(COALESCE(st.completed_at, st.created_at)) AS dt,
                           s.service_key,
                           COALESCE(SUM(st.amount), 0) AS revenue
                    FROM service_transactions st
                    INNER JOIN services s ON s.id = st.service_id
                    INNER JOIN machines m ON m.id = st.machine_id
                    WHERE st.status = 'completed'
                      AND DATE(COALESCE(st.completed_at, st.created_at)) >= :date_from
                      AND DATE(COALESCE(st.completed_at, st.created_at)) <= :date_to
                      {$ownerClause} {$machineClause} {$serviceClause}
                    GROUP BY dt, s.service_key";
            $rows = $this->db->query($sql, array_merge($baseParams, [':date_from' => $dateFrom, ':date_to' => $dateTo]))->fetchAll();

            $dayMap = [];
            foreach ($rows as $r) {
                $dt  = $r['dt'];
                $key = $r['service_key'];
                $dayMap[$dt][$key] = (float) $r['revenue'];
            }

            $numDays = (int) date('t');
            for ($d = 1; $d <= $numDays; $d++) {
                $curDate = date('Y-m-') . sprintf('%02d', $d);
                $labels[] = date('M j', strtotime($curDate));
                $w = $dayMap[$curDate]['water'] ?? 0.0;
                $s = $dayMap[$curDate]['soap'] ?? 0.0;
                if ($service === 'water') {
                    $s = 0.0;
                } elseif ($service === 'soap') {
                    $w = 0.0;
                }
                $waterData[] = round($w, 2);
                $soapData[]  = round($s, 2);
                $totalData[] = round($w + $s, 2);
            }

        } elseif ($period === 'year') {
            $yearStart = date('Y-01-01');
            $yearEnd = date('Y-12-31');
            $dateFrom = $yearStart;
            $dateTo = $yearEnd;
            $prevDateFrom = date('Y-01-01', strtotime('-1 year'));
            $prevDateTo = date('Y-12-31', strtotime('-1 year'));
            $periodLabel = "This Year (" . date('Y') . ")";
            $prevPeriodLabel = "vs last year";
            $timeGrouping = 'monthly';

            $sql = "SELECT MONTH(COALESCE(st.completed_at, st.created_at)) AS mo,
                           s.service_key,
                           COALESCE(SUM(st.amount), 0) AS revenue
                    FROM service_transactions st
                    INNER JOIN services s ON s.id = st.service_id
                    INNER JOIN machines m ON m.id = st.machine_id
                    WHERE st.status = 'completed'
                      AND YEAR(COALESCE(st.completed_at, st.created_at)) = YEAR(CURDATE())
                      {$ownerClause} {$machineClause} {$serviceClause}
                    GROUP BY mo, s.service_key";
            $rows = $this->db->query($sql, $baseParams)->fetchAll();

            $monthMap = [];
            foreach ($rows as $r) {
                $mo  = (int) $r['mo'];
                $key = $r['service_key'];
                $monthMap[$mo][$key] = (float) $r['revenue'];
            }

            for ($m = 1; $m <= 12; $m++) {
                $labels[] = date('M', mktime(0, 0, 0, $m, 1));
                $w = $monthMap[$m]['water'] ?? 0.0;
                $s = $monthMap[$m]['soap'] ?? 0.0;
                if ($service === 'water') {
                    $s = 0.0;
                } elseif ($service === 'soap') {
                    $w = 0.0;
                }
                $waterData[] = round($w, 2);
                $soapData[]  = round($s, 2);
                $totalData[] = round($w + $s, 2);
            }

        } else { // 'all' - All Time
            $minSql = "SELECT MIN(st.created_at) AS min_dt, MAX(st.created_at) AS max_dt
                       FROM service_transactions st
                       INNER JOIN machines m ON m.id = st.machine_id
                       INNER JOIN services s ON s.id = st.service_id
                       WHERE st.status = 'completed' {$ownerClause} {$machineClause} {$serviceClause}";
            $minRow = $this->db->query($minSql, $baseParams)->fetch();
            $earliest = !empty($minRow['min_dt']) ? new DateTime($minRow['min_dt']) : new DateTime('-3 months');
            $latest   = !empty($minRow['max_dt']) ? new DateTime($minRow['max_dt']) : new DateTime('now');

            $dateFrom = $earliest->format('Y-m-d');
            $dateTo = $todayStr;
            $prevDateFrom = '';
            $prevDateTo = '';
            $periodLabel = "All Time (" . $earliest->format('M Y') . " - Present)";
            $prevPeriodLabel = "all-time verified";

            $intervalDays = (int) $earliest->diff($latest)->days;

            if ($intervalDays <= 31) {
                $timeGrouping = 'daily';
                $start = clone $earliest;
                $end = (clone $latest)->modify('+1 day');
                $p = new DatePeriod($start, new DateInterval('P1D'), $end);

                $sql = "SELECT DATE(st.created_at) AS dt, s.service_key, COALESCE(SUM(st.amount), 0) AS revenue
                        FROM service_transactions st
                        INNER JOIN services s ON s.id = st.service_id
                        INNER JOIN machines m ON m.id = st.machine_id
                        WHERE st.status = 'completed' {$ownerClause} {$machineClause} {$serviceClause}
                        GROUP BY dt, s.service_key";
                $rows = $this->db->query($sql, $baseParams)->fetchAll();
                $dayMap = [];
                foreach ($rows as $r) {
                    $dayMap[$r['dt']][$r['service_key']] = (float) $r['revenue'];
                }

                foreach ($p as $d) {
                    $dateStr = $d->format('Y-m-d');
                    $labels[] = $d->format('M j');
                    $w = $dayMap[$dateStr]['water'] ?? 0.0;
                    $s = $dayMap[$dateStr]['soap'] ?? 0.0;
                    if ($service === 'water') {
                        $s = 0.0;
                    } elseif ($service === 'soap') {
                        $w = 0.0;
                    }
                    $waterData[] = round($w, 2);
                    $soapData[]  = round($s, 2);
                    $totalData[] = round($w + $s, 2);
                }
            } else {
                $timeGrouping = 'monthly';
                $start = new DateTime($earliest->format('Y-m-01'));
                $end = (new DateTime($latest->format('Y-m-01')))->modify('+1 month');
                $p = new DatePeriod($start, new DateInterval('P1M'), $end);

                $sql = "SELECT DATE_FORMAT(st.created_at, '%Y-%m') AS ym, s.service_key, COALESCE(SUM(st.amount), 0) AS revenue
                        FROM service_transactions st
                        INNER JOIN services s ON s.id = st.service_id
                        INNER JOIN machines m ON m.id = st.machine_id
                        WHERE st.status = 'completed' {$ownerClause} {$machineClause} {$serviceClause}
                        GROUP BY ym, s.service_key";
                $rows = $this->db->query($sql, $baseParams)->fetchAll();
                $ymMap = [];
                foreach ($rows as $r) {
                    $ymMap[$r['ym']][$r['service_key']] = (float) $r['revenue'];
                }

                foreach ($p as $d) {
                    $ymStr = $d->format('Y-m');
                    $labels[] = $d->format('M Y');
                    $w = $ymMap[$ymStr]['water'] ?? 0.0;
                    $s = $ymMap[$ymStr]['soap'] ?? 0.0;
                    if ($service === 'water') {
                        $s = 0.0;
                    } elseif ($service === 'soap') {
                        $w = 0.0;
                    }
                    $waterData[] = round($w, 2);
                    $soapData[]  = round($s, 2);
                    $totalData[] = round($w + $s, 2);
                }
            }
        }

        // Current Period Totals
        if ($period === 'all') {
            $currStatsSql = "SELECT COALESCE(SUM(st.amount), 0) AS total_rev,
                                    COUNT(*) AS total_count
                             FROM service_transactions st
                             INNER JOIN machines m ON m.id = st.machine_id
                             INNER JOIN services s ON s.id = st.service_id
                             WHERE st.status = 'completed'
                               {$ownerClause} {$machineClause} {$serviceClause}";
            $currRow = $this->db->query($currStatsSql, $baseParams)->fetch();
        } else {
            $currStatsSql = "SELECT COALESCE(SUM(st.amount), 0) AS total_rev,
                                    COUNT(*) AS total_count
                             FROM service_transactions st
                             INNER JOIN machines m ON m.id = st.machine_id
                             INNER JOIN services s ON s.id = st.service_id
                             WHERE st.status = 'completed'
                               AND DATE(COALESCE(st.completed_at, st.created_at)) >= :d_from
                               AND DATE(COALESCE(st.completed_at, st.created_at)) <= :d_to
                               {$ownerClause} {$machineClause} {$serviceClause}";
            $currRow = $this->db->query($currStatsSql, array_merge($baseParams, [':d_from' => $dateFrom, ':d_to' => $dateTo]))->fetch();
        }
        $currRevenue = (float) ($currRow['total_rev'] ?? 0);
        $currCount   = (int) ($currRow['total_count'] ?? 0);
        $currAvg     = ($currCount > 0) ? round($currRevenue / $currCount, 2) : 0.0;

        // Previous Period Totals (for comparison)
        $prevRevenue = 0.0;
        $prevCount   = 0;
        if (!empty($prevDateFrom) && !empty($prevDateTo)) {
            $prevRow = $this->db->query($currStatsSql, array_merge($baseParams, [':d_from' => $prevDateFrom, ':d_to' => $prevDateTo]))->fetch();
            $prevRevenue = (float) ($prevRow['total_rev'] ?? 0);
            $prevCount   = (int) ($prevRow['total_count'] ?? 0);
        }

        // Service Breakdown (Water & Soap)
        $waterParams = array_merge($baseParams);
        $soapParams  = array_merge($baseParams);
        $dateClause  = '';
        if ($period !== 'all') {
            $dateClause = ' AND DATE(st.created_at) >= :bd_from AND DATE(st.created_at) <= :bd_to';
            $waterParams[':bd_from'] = $dateFrom;
            $waterParams[':bd_to']   = $dateTo;
            $soapParams[':bd_from']  = $dateFrom;
            $soapParams[':bd_to']    = $dateTo;
        }

        $waterRow = ['rev' => 0.0, 'cnt' => 0];
        $soapRow  = ['rev' => 0.0, 'cnt' => 0];

        if ($service === 'all' || $service === 'water') {
            $wSql = "SELECT COALESCE(SUM(st.amount), 0) AS rev, COUNT(*) AS cnt
                     FROM service_transactions st
                     INNER JOIN machines m ON m.id = st.machine_id
                     INNER JOIN services s ON s.id = st.service_id
                     WHERE st.status = 'completed' AND s.service_key = 'water'
                       {$dateClause} {$ownerClause} {$machineClause}";
            $waterRow = $this->db->query($wSql, $waterParams)->fetch() ?: ['rev' => 0.0, 'cnt' => 0];
        }

        if ($service === 'all' || $service === 'soap') {
            $sSql = "SELECT COALESCE(SUM(st.amount), 0) AS rev, COUNT(*) AS cnt
                     FROM service_transactions st
                     INNER JOIN machines m ON m.id = st.machine_id
                     INNER JOIN services s ON s.id = st.service_id
                     WHERE st.status = 'completed' AND s.service_key = 'soap'
                       {$dateClause} {$ownerClause} {$machineClause}";
            $soapRow = $this->db->query($sSql, $soapParams)->fetch() ?: ['rev' => 0.0, 'cnt' => 0];
        }

        // Percentage Change Calculation
        if ($prevRevenue > 0) {
            $pctChange = round((($currRevenue - $prevRevenue) / $prevRevenue) * 100, 1);
            $pctDirection = ($pctChange > 0) ? 'up' : (($pctChange < 0) ? 'down' : 'neutral');
            $pctFormatted = ($pctChange > 0 ? '+' : '') . number_format($pctChange, 1) . '%';
        } elseif ($currRevenue > 0) {
            $pctChange = 100.0;
            $pctDirection = 'up';
            $pctFormatted = '+100%';
        } else {
            $pctChange = 0.0;
            $pctDirection = 'neutral';
            $pctFormatted = '0.0%';
        }

        $maxValue = max(array_merge([0], $totalData));

        return [
            'period'               => $period,
            'period_label'         => $periodLabel,
            'prev_period_label'    => $prevPeriodLabel,
            'time_grouping'        => $timeGrouping,
            'machine_id'           => $machineId,
            'service'              => $service,
            'date_from'            => $dateFrom,
            'date_to'              => $dateTo,
            'metrics'              => [
                'total_revenue'             => $currRevenue,
                'total_revenue_formatted'   => '₱' . number_format($currRevenue, 2),
                'total_transactions'        => $currCount,
                'avg_transaction'           => $currAvg,
                'avg_transaction_formatted' => '₱' . number_format($currAvg, 2),
                'prev_revenue'              => $prevRevenue,
                'prev_revenue_formatted'    => '₱' . number_format($prevRevenue, 2),
                'pct_change'                => $pctChange,
                'pct_change_formatted'      => $pctFormatted,
                'pct_direction'             => $pctDirection,
            ],
            'breakdown'            => [
                'water_revenue'      => (float)($waterRow['rev'] ?? 0),
                'water_transactions' => (int)($waterRow['cnt'] ?? 0),
                'soap_revenue'       => (float)($soapRow['rev'] ?? 0),
                'soap_transactions'  => (int)($soapRow['cnt'] ?? 0),
            ],
            'chart'                => [
                'labels'     => $labels,
                'total_data' => $totalData,
                'water_data' => $waterData,
                'soap_data'  => $soapData,
                'max_value'  => $maxValue,
                'has_data'   => ($currRevenue > 0 || $currCount > 0),
            ],
            'insight_context'      => [
                'period'       => $period,
                'period_label' => $periodLabel,
                'revenue'      => $currRevenue,
                'count'        => $currCount,
                'avg_ticket'   => $currAvg,
                'growth_pct'   => $pctChange,
                'growth_trend' => $pctDirection,
            ]
        ];
    }

    /**
     * Export complete Sales Performance Analytics Report to Excel-compatible CSV.
     *
     * @param string   $period    'today' | 'week' | 'month' | 'year' | 'all'
     * @param int|null $machineId
     * @param string   $service   'all' | 'water' | 'soap'
     * @param int|null $ownerId
     */
    public function exportSalesPerformanceCsv(
        string $period = 'today',
        ?int $machineId = null,
        string $service = 'all',
        ?int $ownerId = null
    ): void {
        $analytics = $this->getSalesAnalytics($period, $ownerId, $machineId, $service);

        $machineName = 'All Machines';
        if ($machineId !== null && $machineId > 0) {
            $mRow = $this->db->query("SELECT name, machine_code FROM machines WHERE id = :id", [':id' => $machineId])->fetch();
            if ($mRow) {
                $machineName = ($mRow['name'] ?: 'Machine') . ' (' . ($mRow['machine_code'] ?: 'Kit') . ')';
            }
        }

        $serviceLabel = match ($service) {
            'water' => 'Water Service',
            'soap'  => 'Soap Service',
            default => 'All Services',
        };

        $slug = preg_replace('/[^a-z0-9_-]/i', '_', $period);
        $filename = 'vendolink-sales-performance-' . $slug . '-' . date('Ymd-His') . '.csv';

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
        // UTF-8 BOM
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // 1. Report Header & Metadata
        fputcsv($output, ['VendoLink — Sales Performance Analytics Report']);
        fputcsv($output, ['Report Period', $analytics['period_label']]);
        fputcsv($output, ['Machine Filter', sanitize_excel_cell($machineName)]);
        fputcsv($output, ['Service Filter', $serviceLabel]);
        fputcsv($output, ['Generated Date & Time', date('M j, Y, g:i A')]);
        fputcsv($output, []); // blank line

        // 2. Metrics Summary Block
        fputcsv($output, ['ANALYTICS SUMMARY']);
        fputcsv($output, ['Total Revenue', '₱' . number_format($analytics['metrics']['total_revenue'], 2, '.', '')]);
        fputcsv($output, ['Completed Transactions', (string)$analytics['metrics']['total_transactions']]);
        fputcsv($output, ['Average Spend', '₱' . number_format($analytics['metrics']['avg_transaction'], 2, '.', '')]);
        fputcsv($output, ['Water Revenue', '₱' . number_format($analytics['breakdown']['water_revenue'], 2, '.', '')]);
        fputcsv($output, ['Water Completed Transactions', (string)$analytics['breakdown']['water_transactions']]);
        fputcsv($output, ['Soap Revenue', '₱' . number_format($analytics['breakdown']['soap_revenue'], 2, '.', '')]);
        fputcsv($output, ['Soap Completed Transactions', (string)$analytics['breakdown']['soap_transactions']]);
        fputcsv($output, []); // blank line

        // 3. Time-Grouped Sales Trend Data
        fputcsv($output, ['TIME-GROUPED TREND DATA (' . strtoupper($analytics['time_grouping']) . ')']);
        fputcsv($output, ['Time Interval', 'Water Sales (₱)', 'Soap Sales (₱)', 'Total Revenue (₱)']);

        $labels = $analytics['chart']['labels'] ?? [];
        $wData  = $analytics['chart']['water_data'] ?? [];
        $sData  = $analytics['chart']['soap_data'] ?? [];
        $tData  = $analytics['chart']['total_data'] ?? [];

        foreach ($labels as $idx => $lbl) {
            fputcsv($output, [
                $lbl,
                '₱' . number_format((float)($wData[$idx] ?? 0), 2, '.', ''),
                '₱' . number_format((float)($sData[$idx] ?? 0), 2, '.', ''),
                '₱' . number_format((float)($tData[$idx] ?? 0), 2, '.', ''),
            ]);
        }
        fputcsv($output, []); // blank line

        // 4. Machine Breakdown
        $ownerClause = '';
        $mParams = [];
        if ($ownerId !== null && $ownerId > 0) {
            $ownerClause = ' AND (m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))';
            $mParams[':owner_id']  = $ownerId;
            $mParams[':owner_id2'] = $ownerId;
        }

        $dateClause = '';
        if ($period !== 'all') {
            $dateClause = ' AND DATE(st.created_at) >= :m_from AND DATE(st.created_at) <= :m_to';
            $mParams[':m_from'] = $analytics['date_from'];
            $mParams[':m_to']   = $analytics['date_to'];
        }

        $machineClause = '';
        if ($machineId !== null && $machineId > 0) {
            $machineClause = ' AND st.machine_id = :m_mid';
            $mParams[':m_mid'] = $machineId;
        }

        $serviceClause = '';
        if ($service === 'water' || $service === 'soap') {
            $serviceClause = ' AND s.service_key = :m_svc';
            $mParams[':m_svc'] = $service;
        }

        $mBreakdownSql = "SELECT m.id, m.name, m.machine_code,
                                 COALESCE(SUM(CASE WHEN s.service_key = 'water' THEN st.amount ELSE 0 END), 0) AS water_rev,
                                 COALESCE(SUM(CASE WHEN s.service_key = 'soap' THEN st.amount ELSE 0 END), 0) AS soap_rev,
                                 COALESCE(SUM(st.amount), 0) AS total_rev,
                                 COUNT(*) AS total_count
                          FROM service_transactions st
                          INNER JOIN machines m ON m.id = st.machine_id
                          INNER JOIN services s ON s.id = st.service_id
                          WHERE st.status = 'completed'
                            {$dateClause} {$ownerClause} {$machineClause} {$serviceClause}
                          GROUP BY m.id, m.name, m.machine_code
                          ORDER BY m.name ASC";
        $mRows = $this->db->query($mBreakdownSql, $mParams)->fetchAll();

        fputcsv($output, ['MACHINE BREAKDOWN']);
        fputcsv($output, ['Machine Name', 'Machine Code', 'Water Revenue', 'Soap Revenue', 'Total Revenue', 'Completed Transactions']);
        if (empty($mRows)) {
            fputcsv($output, ['No machine data recorded for this period.']);
        } else {
            foreach ($mRows as $mr) {
                fputcsv($output, [
                    sanitize_excel_cell($mr['name'] ?: 'Machine'),
                    sanitize_excel_cell($mr['machine_code'] ?: 'N/A'),
                    '₱' . number_format((float)$mr['water_rev'], 2, '.', ''),
                    '₱' . number_format((float)$mr['soap_rev'], 2, '.', ''),
                    '₱' . number_format((float)$mr['total_rev'], 2, '.', ''),
                    (string)$mr['total_count'],
                ]);
            }
        }
        fputcsv($output, []); // blank line

        // 5. All Matching Completed Transactions
        fputcsv($output, ['COMPLETED TRANSACTIONS IN PERIOD']);
        $txSql = "SELECT st.id, st.created_at, st.amount, st.runtime_generated_seconds,
                         s.name AS service_name, s.service_key,
                         m.name AS machine_name, m.machine_code
                  FROM service_transactions st
                  INNER JOIN machines m ON m.id = st.machine_id
                  INNER JOIN services s ON s.id = st.service_id
                  WHERE st.status = 'completed'
                    {$dateClause} {$ownerClause} {$machineClause} {$serviceClause}
                  ORDER BY st.created_at DESC, st.id DESC";
        $txRows = $this->db->query($txSql, $mParams)->fetchAll();

        if (empty($txRows)) {
            fputcsv($output, ['No completed sales found for the selected period and filters.']);
        } else {
            fputcsv($output, [
                'Transaction ID',
                'Date & Time',
                'Machine',
                'Service',
                'Amount',
                'Purchased Time',
                'Status'
            ]);

            foreach ($txRows as $tx) {
                $serviceDisplayName = match ($tx['service_key'] ?? '') {
                    'water' => 'Water',
                    'soap'  => 'Soap',
                    default => preg_replace('/\s+Service$/i', '', $tx['service_name'] ?: 'Service'),
                };
                $machineDisplayName = preg_replace('/^VendoLink\s+/i', '', $tx['machine_name'] ?: 'Machine 01');

                fputcsv($output, [
                    '#' . str_pad((string)$tx['id'], 5, '0', STR_PAD_LEFT),
                    $tx['created_at'],
                    sanitize_excel_cell($machineDisplayName),
                    sanitize_excel_cell($serviceDisplayName),
                    '₱' . number_format((float)$tx['amount'], 2, '.', ''),
                    Transaction::formatDuration((int)$tx['runtime_generated_seconds']),
                    'Completed',
                ]);
            }
        }

        fclose($output);
        exit;
    }
}

