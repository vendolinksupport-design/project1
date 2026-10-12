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
    public function getSummaryMetrics(string $dateFrom, string $dateTo, string $serviceFilter = 'all', ?int $ownerId = null): array
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

        // 1. Overall Completed Revenue and Transactions in Period
        $sql = "SELECT COALESCE(SUM(st.amount), 0) AS total_revenue,
                       COUNT(*) AS total_transactions,
                       COALESCE(SUM(st.runtime_generated_seconds), 0) AS total_purchased_seconds
                FROM service_transactions st
                INNER JOIN services s ON s.id = st.service_id
                INNER JOIN machines m ON m.id = st.machine_id
                WHERE st.status = 'completed'
                  AND DATE(st.created_at) >= :date_from
                  AND DATE(st.created_at) <= :date_to
                  {$serviceClause}
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

        $waterSql = "SELECT COALESCE(SUM(st.amount), 0) AS revenue,
                            COUNT(*) AS count,
                            COALESCE(SUM(st.runtime_generated_seconds), 0) AS purchased_seconds
                     FROM service_transactions st
                     INNER JOIN services s ON s.id = st.service_id
                     INNER JOIN machines m ON m.id = st.machine_id
                     WHERE st.status = 'completed'
                       AND s.service_key = 'water'
                       AND DATE(st.created_at) >= :date_from
                       AND DATE(st.created_at) <= :date_to
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
               AND DATE(st.created_at) >= :date_from
               AND DATE(st.created_at) <= :date_to
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
     * Generate structured time-series data for the Sales Over Time chart.
     *
     * @param string   $dateFrom
     * @param string   $dateTo
     * @param string   $periodType 'today' | 'week' | 'month' | 'custom'
     * @param string   $serviceFilter
     * @param int|null $ownerId
     * @return array [labels, water_data, soap_data, total_data, max_value]
     */
    public function getTimeSeries(string $dateFrom, string $dateTo, string $periodType, string $serviceFilter = 'all', ?int $ownerId = null): array
    {
        $isSingleDay = ($dateFrom === $dateTo);

        $ownerClause = '';
        $tsParams    = [':date_from' => $dateFrom];
        if ($ownerId !== null && $ownerId > 0) {
            $ownerClause = ' AND (m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))';
            $tsParams[':owner_id']  = $ownerId;
            $tsParams[':owner_id2'] = $ownerId;
        }

        if ($isSingleDay) {
            // Hourly breakdown (00:00 to 23:00)
            $labels = [];
            $waterData = [];
            $soapData  = [];
            $totalData = [];

            // Query actual completed sales grouped by HOUR
            $sql = "SELECT HOUR(st.created_at) AS hr,
                           s.service_key,
                           COALESCE(SUM(st.amount), 0) AS revenue
                    FROM service_transactions st
                    INNER JOIN services s ON s.id = st.service_id
                    INNER JOIN machines m ON m.id = st.machine_id
                    WHERE st.status = 'completed'
                      AND DATE(st.created_at) = :date_from
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

        $sql = "SELECT DATE(st.created_at) AS dt,
                       s.service_key,
                       COALESCE(SUM(st.amount), 0) AS revenue
                FROM service_transactions st
                INNER JOIN services s ON s.id = st.service_id
                INNER JOIN machines m ON m.id = st.machine_id
                WHERE st.status = 'completed'
                  AND DATE(st.created_at) >= :date_from
                  AND DATE(st.created_at) <= :date_to
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
     * @param string   $period  'today' | 'week' | 'month' | 'year' | 'all'
     * @param int|null $ownerId
     * @return array
     */
    public function getSalesAnalytics(string $period = 'today', ?int $ownerId = null): array
    {
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
            $sql = "SELECT HOUR(st.created_at) AS hr,
                           s.service_key,
                           COALESCE(SUM(st.amount), 0) AS revenue
                    FROM service_transactions st
                    INNER JOIN services s ON s.id = st.service_id
                    INNER JOIN machines m ON m.id = st.machine_id
                    WHERE st.status = 'completed'
                      AND DATE(st.created_at) = :date_from
                      {$ownerClause}
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

            $sql = "SELECT DATE(st.created_at) AS dt,
                           s.service_key,
                           COALESCE(SUM(st.amount), 0) AS revenue
                    FROM service_transactions st
                    INNER JOIN services s ON s.id = st.service_id
                    INNER JOIN machines m ON m.id = st.machine_id
                    WHERE st.status = 'completed'
                      AND DATE(st.created_at) >= :date_from
                      AND DATE(st.created_at) <= :date_to
                      {$ownerClause}
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

            $sql = "SELECT DATE(st.created_at) AS dt,
                           s.service_key,
                           COALESCE(SUM(st.amount), 0) AS revenue
                    FROM service_transactions st
                    INNER JOIN services s ON s.id = st.service_id
                    INNER JOIN machines m ON m.id = st.machine_id
                    WHERE st.status = 'completed'
                      AND DATE(st.created_at) >= :date_from
                      AND DATE(st.created_at) <= :date_to
                      {$ownerClause}
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

            $sql = "SELECT MONTH(st.created_at) AS mo,
                           s.service_key,
                           COALESCE(SUM(st.amount), 0) AS revenue
                    FROM service_transactions st
                    INNER JOIN services s ON s.id = st.service_id
                    INNER JOIN machines m ON m.id = st.machine_id
                    WHERE st.status = 'completed'
                      AND YEAR(st.created_at) = YEAR(CURDATE())
                      {$ownerClause}
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
                $waterData[] = round($w, 2);
                $soapData[]  = round($s, 2);
                $totalData[] = round($w + $s, 2);
            }

        } else { // 'all' - All Time
            $minSql = "SELECT MIN(st.created_at) AS min_dt, MAX(st.created_at) AS max_dt
                       FROM service_transactions st
                       INNER JOIN machines m ON m.id = st.machine_id
                       WHERE st.status = 'completed' {$ownerClause}";
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
                        WHERE st.status = 'completed' {$ownerClause}
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
                        WHERE st.status = 'completed' {$ownerClause}
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
                    $waterData[] = round($w, 2);
                    $soapData[]  = round($s, 2);
                    $totalData[] = round($w + $s, 2);
                }
            }
        }

        // Current Period Totals
        $currStatsSql = "SELECT COALESCE(SUM(st.amount), 0) AS total_rev,
                                COUNT(*) AS total_count
                         FROM service_transactions st
                         INNER JOIN machines m ON m.id = st.machine_id
                         WHERE st.status = 'completed'
                           AND DATE(st.created_at) >= :d_from
                           AND DATE(st.created_at) <= :d_to
                           {$ownerClause}";
        $currRow = $this->db->query($currStatsSql, array_merge($baseParams, [':d_from' => $dateFrom, ':d_to' => $dateTo]))->fetch();
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
}
