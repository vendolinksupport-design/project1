<?php
/**
 * ============================================================
 * VendoLink — VendoAI Assistant Test Suite
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/AiAssistant.php';

echo "=== STARTING VENDOAI SMART ASSISTANT TESTS ===\n\n";

$ai = new AiAssistant();

// 1. Test generateInsights()
echo "1. Testing AiAssistant::generateInsights()...\n";
$insights = $ai->generateInsights();
assert(isset($insights['total_tx']), "Insights payload must contain total_tx.");
assert(isset($insights['predicted_monthly_rev']), "Insights payload must contain predicted_monthly_rev.");
assert(is_array($insights['recommendations']), "Recommendations must be an array.");
echo "✅ Generated AI insights cleanly. Projected monthly revenue: ₱" . number_format($insights['predicted_monthly_rev'], 2) . "\n\n";

// 2. Test interactive prompt ask()
echo "2. Testing AiAssistant::ask() interactive chatbot...\n";
$revAnswer = $ai->ask('Predict revenue for next month');
assert(str_contains($revAnswer['answer'], 'Revenue Forecast'), "Ask revenue prompt must return revenue forecast.");
echo "✅ Revenue forecast prompt verified successfully.\n\n";

$refillAnswer = $ai->ask('When should I refill the water tank?');
assert(str_contains($refillAnswer['answer'], 'Refill Forecast'), "Ask refill prompt must return refill forecast.");
echo "✅ Refill forecast prompt verified successfully.\n\n";

// 3. Test Date Queries ("income yesterday", "income in september 1")
echo "3. Testing Date-Specific AI Queries...\n";
$yesterdayAnswer = $ai->ask('what is the income yesterday');
assert(str_contains($yesterdayAnswer['answer'], 'Income Yesterday'), "Yesterday query must return Income Yesterday.");
echo "✅ Yesterday income query verified successfully: " . strtok($yesterdayAnswer['answer'], "\n") . "\n\n";

$septemberAnswer = $ai->ask('income in september 1');
assert(str_contains($septemberAnswer['answer'], 'September 1'), "September query must parse September 1.");
echo "✅ Specific date query verified successfully.\n\n";

echo "🎉 ALL VENDOAI SMART ASSISTANT CHECKS PASSED 100% SUCCESSFULLY!\n";
