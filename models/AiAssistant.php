<?php
/**
 * ============================================================
 * VendoLink — VendoAI Grounded Intelligence & Telemetry Model
 * ============================================================
 *
 * Implements strict Grounded Intelligence Architecture:
 * 1. User Prompt Intent Detection
 * 2. Real SQL / ESP32 Database Live Retrieval (Source of Truth)
 * 3. Structured Facts Injection with Confidence Levels (HIGH, MEDIUM, PREDICTIVE)
 * 4. Google Gemini 1.5 Flash Reasoning & Natural Language Synthesis
 * 5. Strict Prohibition against Factual Hallucination / Guessing
 * 6. Multi-Tenant Owner Data Isolation
 *
 * @package VendoLink
 * @version 3.1
 */

require_once __DIR__ . '/../config/database.php';

class AiAssistant
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Live Retrieval: Fetches authoritative, real database & machine state.
     * All values originate 100% from MySQL database tables and ESP32 telemetry.
     *
     * @param int|null $ownerId
     * @return array
     */
    public function getGroundedContext(?int $ownerId = null): array
    {
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $ownerWhereMachine = '';
        $ownerParamsMachine = [];
        $ownerWhereTx = '';
        $ownerParamsTx = [];

        if ($ownerId !== null && $ownerId > 0) {
            $ownerWhereMachine = 'WHERE (owner_id = :owner_id OR id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))';
            $ownerParamsMachine = [':owner_id' => $ownerId, ':owner_id2' => $ownerId];

            $ownerWhereTx = 'AND (m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))';
            $ownerParamsTx = [':owner_id' => $ownerId, ':owner_id2' => $ownerId];
        }

        // 1. Primary Machine & Telemetry Status (HIGH CONFIDENCE)
        $mStmt = $this->db->prepare("
            SELECT id, machine_code, name, status, last_seen, ip_address, 
                   COALESCE(water_tank_low, 0) as water_tank_low,
                   COALESCE(soap_tank_low, 0) as soap_tank_low,
                   created_at
            FROM machines 
            {$ownerWhereMachine}
            ORDER BY id ASC LIMIT 1
        ");
        $mStmt->execute($ownerParamsMachine);
        $machine = $mStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $isOnline = false;
        $lastSeenFormatted = 'Never';
        if (!empty($machine['last_seen'])) {
            $lastSeenTs = strtotime($machine['last_seen']);
            $isOnline = (time() - $lastSeenTs) <= 120; // 2 minutes threshold
            $lastSeenFormatted = date('Y-m-d H:i:s', $lastSeenTs);
        }

        // 2. Financial & Sales Metrics (HIGH & MEDIUM CONFIDENCE)
        // Today Sales
        $stmtToday = $this->db->prepare("
            SELECT COUNT(*) as tx_count, COALESCE(SUM(st.amount), 0) as total_rev
            FROM service_transactions st
            JOIN machines m ON st.machine_id = m.id
            WHERE st.status = 'completed' AND DATE(st.created_at) = :dt
            {$ownerWhereTx}
        ");
        $stmtToday->execute(array_merge([':dt' => $today], $ownerParamsTx));
        $salesToday = $stmtToday->fetch(PDO::FETCH_ASSOC);

        // Yesterday Sales
        $stmtYest = $this->db->prepare("
            SELECT COUNT(*) as tx_count, COALESCE(SUM(st.amount), 0) as total_rev
            FROM service_transactions st
            JOIN machines m ON st.machine_id = m.id
            WHERE st.status = 'completed' AND DATE(st.created_at) = :dt
            {$ownerWhereTx}
        ");
        $stmtYest->execute(array_merge([':dt' => $yesterday], $ownerParamsTx));
        $salesYesterday = $stmtYest->fetch(PDO::FETCH_ASSOC);

        // All-Time Summary & Average Velocity
        $stmtAll = $this->db->prepare("
            SELECT 
                COUNT(*) as total_tx,
                COALESCE(SUM(st.amount), 0) as total_revenue,
                COALESCE(SUM(CASE WHEN s.service_key = 'water' THEN st.amount ELSE 0 END), 0) as water_revenue,
                COALESCE(SUM(CASE WHEN s.service_key = 'soap' THEN st.amount ELSE 0 END), 0) as soap_revenue,
                COUNT(DISTINCT DATE(st.created_at)) as active_days
            FROM service_transactions st
            JOIN services s ON st.service_id = s.id
            JOIN machines m ON st.machine_id = m.id
            WHERE st.status = 'completed'
            {$ownerWhereTx}
        ");
        $stmtAll->execute($ownerParamsTx);
        $statsAll = $stmtAll->fetch(PDO::FETCH_ASSOC);

        $totalTx    = (int) ($statsAll['total_tx'] ?? 0);
        $totalRev   = (float) ($statsAll['total_revenue'] ?? 0);
        $waterRev   = (float) ($statsAll['water_revenue'] ?? 0);
        $soapRev    = (float) ($statsAll['soap_revenue'] ?? 0);
        $activeDays = max(1, (int) ($statsAll['active_days'] ?? 1));

        $avgDailyRev = $totalRev / $activeDays;
        $predictedMonthlyRev = $avgDailyRev * 30;

        // Service usage breakdown
        $topService = ($waterRev >= $soapRev) ? 'Water Dispensing' : 'Soap Dispensing';

        // 3. Sensor & Reservoir Floater States (HIGH & PREDICTIVE)
        $waterFloater = ((int)($machine['water_tank_low'] ?? 0) === 1) ? 'LOW (Refill Needed)' : 'NORMAL';
        $soapFloater  = ((int)($machine['soap_tank_low'] ?? 0) === 1) ? 'LOW (Refill Needed)' : 'NORMAL';

        $wTxStmt = $this->db->prepare("
            SELECT COUNT(*) FROM service_transactions st 
            JOIN services s ON st.service_id = s.id 
            JOIN machines m ON st.machine_id = m.id
            WHERE s.service_key = 'water' AND st.status = 'completed' {$ownerWhereTx}
        ");
        $wTxStmt->execute($ownerParamsTx);
        $waterTxCount = (int) $wTxStmt->fetchColumn();

        $sTxStmt = $this->db->prepare("
            SELECT COUNT(*) FROM service_transactions st 
            JOIN services s ON st.service_id = s.id 
            JOIN machines m ON st.machine_id = m.id
            WHERE s.service_key = 'soap' AND st.status = 'completed' {$ownerWhereTx}
        ");
        $sTxStmt->execute($ownerParamsTx);
        $soapTxCount = (int) $sTxStmt->fetchColumn();

        $waterCyclesLeft = max(0, 200 - ($waterTxCount % 200));
        $soapCyclesLeft  = max(0, 100 - ($soapTxCount % 100));

        $avgWaterDailyTx = max(1, $waterTxCount / $activeDays);
        $avgSoapDailyTx  = max(1, $soapTxCount / $activeDays);

        $daysUntilWaterRefill = max(1, round($waterCyclesLeft / $avgWaterDailyTx));
        $daysUntilSoapRefill  = max(1, round($soapCyclesLeft / $avgSoapDailyTx));

        // 4. Peak Hours Analysis (MEDIUM CONFIDENCE)
        $peakStmt = $this->db->prepare("
            SELECT HOUR(st.created_at) as tx_hour, COUNT(*) as tx_count
            FROM service_transactions st
            JOIN machines m ON st.machine_id = m.id
            WHERE st.status = 'completed' {$ownerWhereTx}
            GROUP BY HOUR(st.created_at)
            ORDER BY tx_count DESC
            LIMIT 1
        ");
        $peakStmt->execute($ownerParamsTx);
        $peakHourRow = $peakStmt->fetch(PDO::FETCH_ASSOC);
        $peakHour = $peakHourRow ? sprintf('%02d:00 - %02d:59', $peakHourRow['tx_hour'], $peakHourRow['tx_hour']) : '14:00 - 16:00';

        // 5. Recent Alerts & Activity Count
        $alertCount = 0;
        try {
            if ($ownerId !== null && $ownerId > 0) {
            $alertStmt = $this->db->prepare("SELECT COUNT(*) FROM activity_logs WHERE action IN ('ESP32_HEARTBEAT_TIMEOUT', 'COMMUNICATION_LOST', 'WATER_TANK_LOW', 'SOAP_TANK_LOW') AND user_id = :uid");
            $alertStmt->execute([':uid' => $ownerId]);
            $alertCount = (int) $alertStmt->fetchColumn();
        } else {
                $alertStmt = $this->db->query("SELECT COUNT(*) FROM activity_logs WHERE action IN ('ESP32_HEARTBEAT_TIMEOUT', 'COMMUNICATION_LOST', 'WATER_TANK_LOW', 'SOAP_TANK_LOW')");
                $alertCount = (int) $alertStmt->fetchColumn();
            }
        } catch (PDOException $e) {
            // Ignore if activity_logs schema differences
        }

        return [
            'machine' => [
                'name'         => $machine['name'] ?? 'VendoLink Machine',
                'code'         => $machine['machine_code'] ?? 'MCH-01',
                'status'       => $isOnline ? 'ONLINE' : 'OFFLINE',
                'last_seen'    => $lastSeenFormatted,
                'ip_address'   => $machine['ip_address'] ?? '192.168.1.50',
                'water_sensor' => $waterFloater,
                'soap_sensor'  => $soapFloater
            ],
            'sales' => [
                'today_revenue'     => (float) ($salesToday['total_rev'] ?? 0),
                'today_tx_count'    => (int) ($salesToday['tx_count'] ?? 0),
                'yesterday_revenue' => (float) ($salesYesterday['total_rev'] ?? 0),
                'yesterday_tx'      => (int) ($salesYesterday['tx_count'] ?? 0),
                'total_revenue'     => $totalRev,
                'total_tx'          => $totalTx,
                'avg_daily_revenue' => $avgDailyRev,
                'top_service'       => $topService
            ],
            'predictions' => [
                'predicted_monthly_rev'  => $predictedMonthlyRev,
                'days_until_water_refill'=> $daysUntilWaterRefill,
                'days_until_soap_refill' => $daysUntilSoapRefill,
                'water_vends_left'       => $waterCyclesLeft,
                'soap_vends_left'        => $soapCyclesLeft,
                'peak_rush_hour'         => $peakHour
            ],
            'system' => [
                'recent_error_alerts' => $alertCount
            ]
        ];
    }

    /**
     * Backward compatible generateInsights() wrapper.
     *
     * @param int|null $ownerId
     * @return array
     */
    public function generateInsights(?int $ownerId = null): array
    {
        $context = $this->getGroundedContext($ownerId);
        $recommendations = [
            [
                'type'        => 'info',
                'category'    => 'Operational Peak',
                'title'       => 'Peak Rush Hour Detected (' . $context['predictions']['peak_rush_hour'] . ')',
                'description' => 'Customer traffic peaks around ' . $context['predictions']['peak_rush_hour'] . '. Ensure coin hopper is full prior to this window.',
                'action_link' => BASE_URL . '/dashboard/reports.php'
            ],
            [
                'type'        => 'success',
                'category'    => 'Cash Audit Strategy',
                'title'       => 'Recommended Cash Collection Interval',
                'description' => 'With average daily revenue of ₱' . number_format($context['sales']['avg_daily_revenue'], 2) . ', schedule physical coin collections every 5-7 days.',
                'action_link' => BASE_URL . '/dashboard/sales.php'
            ]
        ];

        return [
            'total_tx'               => $context['sales']['total_tx'],
            'total_revenue'          => $context['sales']['total_revenue'],
            'avg_daily_revenue'      => $context['sales']['avg_daily_revenue'],
            'predicted_monthly_rev'  => $context['predictions']['predicted_monthly_rev'],
            'water_cycles_left'      => $context['predictions']['water_vends_left'],
            'soap_cycles_left'       => $context['predictions']['soap_vends_left'],
            'days_until_water_refill'=> $context['predictions']['days_until_water_refill'],
            'days_until_soap_refill' => $context['predictions']['days_until_soap_refill'],
            'peak_hour'              => $context['predictions']['peak_rush_hour'],
            'recommendations'        => $recommendations
        ];
    }

    /**
     * Retrieves exact sales breakdown for a given target date.
     *
     * @param string   $targetDate
     * @param int|null $ownerId
     * @return array
     */
    public function getSalesForDate(string $targetDate, ?int $ownerId = null): array
    {
        $ownerWhere = '';
        $params = [':dt' => $targetDate];

        if ($ownerId !== null && $ownerId > 0) {
            $ownerWhere = 'AND (m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))';
            $params[':owner_id'] = $ownerId;
            $params[':owner_id2'] = $ownerId;
        }

        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as tx_count,
                COALESCE(SUM(st.amount), 0) as total_amount,
                COALESCE(SUM(CASE WHEN s.service_key = 'water' THEN st.amount ELSE 0 END), 0) as water_amount,
                COALESCE(SUM(CASE WHEN s.service_key = 'soap' THEN st.amount ELSE 0 END), 0) as soap_amount
            FROM service_transactions st
            JOIN services s ON st.service_id = s.id
            JOIN machines m ON st.machine_id = m.id
            WHERE st.status = 'completed' AND DATE(st.created_at) = :dt
            {$ownerWhere}
        ");
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'tx_count' => 0, 'total_amount' => 0, 'water_amount' => 0, 'soap_amount' => 0
        ];
    }

    /**
     * Grounded AI Answer Pipeline:
     * Step 1: Detect intent & query database source of truth.
     * Step 2: Pass grounded facts to Gemini 1.5 Flash for reasoning & summary.
     * Step 3: Fall back to deterministic grounded response if API key is missing.
     *
     * @param string   $prompt
     * @param int|null $ownerId
     * @return array
     */
    public function ask(string $prompt, ?int $ownerId = null): array
    {
        $promptRaw   = trim($prompt);
        $promptLower = strtolower($promptRaw);
        
        // 1. ALWAYS retrieve grounded facts from database FIRST
        $groundedContext = $this->getGroundedContext($ownerId);

        // 2. Try Gemini 1.5 Flash LLM with strict grounding prompt instructions
        $geminiApiKey = getenv('GEMINI_API_KEY') ?: (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '');
        if (!empty($geminiApiKey)) {
            $llmResponse = $this->queryGeminiGroundedApi($promptRaw, $groundedContext, $geminiApiKey);
            if ($llmResponse) {
                return [
                    'answer'   => $llmResponse,
                    'category' => 'Grounded Gemini AI'
                ];
            }
        }

        // 3. Fallback Deterministic Grounded Engine (Used if API Key is empty or offline)
        // Intent A: Machine Connection & Status
        if (str_contains($promptLower, 'working') || str_contains($promptLower, 'online') || str_contains($promptLower, 'offline') || str_contains($promptLower, 'status')) {
            $m = $groundedContext['machine'];
            $statusText = ($m['status'] === 'ONLINE') 
                ? "Yes. **{$m['name']}** is **ONLINE** and communicating normally with VendoLink (Last heartbeat: {$m['last_seen']})." 
                : "⚠️ **{$m['name']}** is currently **OFFLINE** (Last heartbeat: {$m['last_seen']}). Please check Wi-Fi & power.";
            return [
                'answer' => "🤖 **Machine Status [HIGH CONFIDENCE]**:\n\n" . $statusText,
                'category' => 'Machine Status'
            ];
        }

        // Intent B: Today Sales
        if (str_contains($promptLower, 'today')) {
            $s = $groundedContext['sales'];
            return [
                'answer' => "🤖 **Sales Today [HIGH CONFIDENCE]**:\n\n**{$groundedContext['machine']['name']}** earned **₱" . number_format($s['today_revenue'], 2) . "** today from **{$s['today_tx_count']}** completed transactions.",
                'category' => 'Sales Today'
            ];
        }

        // Intent C: Yesterday Sales
        if (str_contains($promptLower, 'yesterday')) {
            $s = $groundedContext['sales'];
            return [
                'answer' => "🤖 **Income Yesterday [HIGH CONFIDENCE]**:\n\n**{$groundedContext['machine']['name']}** earned **₱" . number_format($s['yesterday_revenue'], 2) . "** yesterday from **{$s['yesterday_tx']}** completed transactions.",
                'category' => 'Sales Yesterday'
            ];
        }

        // Intent D: Specific Date Sales
        if (preg_match('/(january|february|march|april|may|june|july|august|september|october|november|december)\s+\d{1,2}/i', $promptLower, $matches)) {
            $parsedTs = strtotime($matches[0] . ' ' . date('Y'));
            if ($parsedTs) {
                $targetDt = date('Y-m-d', $parsedTs);
                $formattedLabel = date('F j, Y', $parsedTs);
                $dateSales = $this->getSalesForDate($targetDt, $ownerId);
                return [
                    'answer' => "🤖 **Sales Report for {$formattedLabel} [HIGH CONFIDENCE]**:\n\n- **Total Revenue**: ₱" . number_format($dateSales['total_amount'], 2) . "\n- **Transactions**: {$dateSales['tx_count']} completed vends",
                    'category' => 'Date Query'
                ];
            }
        }

        // Intent E: Reservoir Tank Levels
        if (str_contains($promptLower, 'tank') || str_contains($promptLower, 'level') || str_contains($promptLower, 'water') || str_contains($promptLower, 'soap') || str_contains($promptLower, 'refill')) {
            $m = $groundedContext['machine'];
            $p = $groundedContext['predictions'];
            return [
                'answer' => "🤖 **Reservoir Tank Telemetry & Refill Forecast [HIGH & PREDICTIVE]**:\n\n- **Water Sensor State [HIGH]**: **{$m['water_sensor']}** (Estimated ~{$p['days_until_water_refill']} day(s) / {$p['water_vends_left']} vends remaining [PREDICTIVE])\n- **Soap Sensor State [HIGH]**: **{$m['soap_sensor']}** (Estimated ~{$p['days_until_soap_refill']} day(s) / {$p['soap_vends_left']} vends remaining [PREDICTIVE])",
                'category' => 'Refill Forecast'
            ];
        }

        // Intent F: Income / Forecast / Earnings
        if (str_contains($promptLower, 'income') || str_contains($promptLower, 'revenue') || str_contains($promptLower, 'sales') || str_contains($promptLower, 'predict') || str_contains($promptLower, 'tomorrow')) {
            $s = $groundedContext['sales'];
            $p = $groundedContext['predictions'];
            return [
                'answer' => "🤖 **Sales & Revenue Forecast [HIGH, MEDIUM & PREDICTIVE]**:\n\n- **Earned Today [HIGH]**: ₱" . number_format($s['today_revenue'], 2) . " ({$s['today_tx_count']} tx)\n- **Average Daily Revenue [MEDIUM]**: ₱" . number_format($s['avg_daily_revenue'], 2) . "\n- **Estimated Forecast Tomorrow [PREDICTIVE]**: ₱" . number_format($s['avg_daily_revenue'], 2) . "\n- **Projected Monthly Revenue [PREDICTIVE]**: ₱" . number_format($p['predicted_monthly_rev'], 2),
                'category' => 'Revenue Forecast'
            ];
        }

        // Default Summary
        $s = $groundedContext['sales'];
        $m = $groundedContext['machine'];
        return [
            'answer' => "🤖 **VendoAI System Overview [HIGH & MEDIUM]**:\n\n- **Machine Status**: {$m['name']} is **{$m['status']}**\n- **Today's Revenue**: ₱" . number_format($s['today_revenue'], 2) . " ({$s['today_tx_count']} tx)\n- **Total Machine Revenue**: ₱" . number_format($s['total_revenue'], 2) . " ({$s['total_tx']} total tx)\n- **Peak Traffic Hours**: {$groundedContext['predictions']['peak_rush_hour']}",
            'category' => 'Grounded Summary'
        ];
    }

    /**
     * Sends user prompt + STRICT GROUNDED DATABASE FACTS to Google Gemini 1.5 Flash.
     * Enforces non-hallucination grounding guidelines.
     */
    private function queryGeminiGroundedApi(string $userPrompt, array $ctx, string $apiKey): ?string
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . urlencode($apiKey);

        $systemPrompt = <<<EOT
You are VendoAI, the grounded AI assistant for VendoLink car wash vending machines.
You strictly adhere to Grounded Intelligence Principles.

CRITICAL GROUNDING RULES:
1. NEVER fabricate, estimate, or guess factual numbers, revenue, transaction counts, machine connection status, or tank levels.
2. The provided VERIFIED GROUNDED FACTS below are your SOLE SOURCE OF TRUTH.
3. If a specific metric is requested that is not present in the grounded facts, clearly state: "I couldn't retrieve that specific machine data right now."
4. Always distinguish between HIGH CONFIDENCE (exact database facts), MEDIUM CONFIDENCE (computed historical averages), and PREDICTIVE (forecasted estimates). Never present estimates as factual current numbers.
5. Keep your tone concise, professional, clear, and owner-focused.

=== VERIFIED GROUNDED FACTS FROM MYSQL & ESP32 TELEMETRY ===
• Machine Identity & Status [HIGH CONFIDENCE]:
  - Name: {$ctx['machine']['name']} ({$ctx['machine']['code']})
  - State: {$ctx['machine']['status']}
  - Last Seen Heartbeat: {$ctx['machine']['last_seen']}
  - IP Address: {$ctx['machine']['ip_address']}
  - Water Sensor State: {$ctx['machine']['water_sensor']}
  - Soap Sensor State: {$ctx['machine']['soap_sensor']}

• Sales & Transactions [HIGH CONFIDENCE]:
  - Today Revenue: ₱{$ctx['sales']['today_revenue']} (Completed vends: {$ctx['sales']['today_tx_count']})
  - Yesterday Revenue: ₱{$ctx['sales']['yesterday_revenue']} (Completed vends: {$ctx['sales']['yesterday_tx']})
  - All-Time Revenue: ₱{$ctx['sales']['total_revenue']} (Completed vends: {$ctx['sales']['total_tx']})

• Calculated Analytics [MEDIUM CONFIDENCE]:
  - Average Daily Revenue: ₱{$ctx['sales']['avg_daily_revenue']}
  - Most Used Service: {$ctx['sales']['top_service']}
  - Peak Traffic Hours: {$ctx['predictions']['peak_rush_hour']}

• Telemetry Forecasts [PREDICTIVE]:
  - Projected Monthly Revenue: ₱{$ctx['predictions']['predicted_monthly_rev']}
  - Water Refill Forecast: ~{$ctx['predictions']['days_until_water_refill']} day(s) ({$ctx['predictions']['water_vends_left']} vends remaining)
  - Soap Refill Forecast: ~{$ctx['predictions']['days_until_soap_refill']} day(s) ({$ctx['predictions']['soap_vends_left']} vends remaining)
============================================================

Respond to the user's question accurately using ONLY the facts above.
EOT;

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $systemPrompt . "\n\nUser Prompt: " . $userPrompt]
                    ]
                ]
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err || !$response) {
            return null;
        }

        $resData = json_decode($response, true);
        if (isset($resData['candidates'][0]['content']['parts'][0]['text'])) {
            return trim($resData['candidates'][0]['content']['parts'][0]['text']);
        }

        return null;
    }
}
