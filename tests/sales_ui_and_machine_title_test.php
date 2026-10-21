<?php
declare(strict_types=1);

/**
 * Automated Verification Suite for:
 * 1. "Car Wash Controller" machine title display in dashboard card.
 * 2. Philippine Peso (₱) vector icon in Sales header and navigation.
 * 3. Removal of Filters button & popover from Sales page analytics section.
 * 4. Preservation of Date Range buttons and Export Excel.
 * 5. Strict Zero-Emoji and Zero-Forbidden-Word verification.
 */

$rootDir = dirname(__DIR__);
$passed = 0;
$total = 0;

function assertCondition(bool $condition, string $testName, string $details = ''): void {
    global $passed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo "[PASS] " . $testName . PHP_EOL;
    } else {
        echo "[FAIL] " . $testName . ($details ? " - Details: " . $details : "") . PHP_EOL;
    }
}

echo "=== STARTING VERIFICATION: Sales UI & Machine Terminology ===" . PHP_EOL . PHP_EOL;

// 1. Verify Peso Icon in functions.php
require_once $rootDir . '/includes/functions.php';

$pesoIconSvg = lucide_icon('peso', 'test-class');
assertCondition(
    strpos($pesoIconSvg, '<path d="M7 3v18"></path>') !== false && strpos($pesoIconSvg, 'test-class') !== false,
    "lucide_icon('peso') renders correct Philippine Peso SVG",
    "Generated SVG: " . substr($pesoIconSvg, 0, 100)
);

$aliasSign = lucide_icon('peso-sign');
assertCondition(
    strpos($aliasSign, '<path d="M7 3v18"></path>') !== false,
    "lucide_icon('peso-sign') alias functions correctly"
);

// 2. Verify sidebar.php uses peso icon for Sales
$sidebarContent = file_get_contents($rootDir . '/views/layouts/sidebar.php');
assertCondition(
    strpos($sidebarContent, "lucide_icon('peso', 'sidebar-lucide-icon')") !== false,
    "views/layouts/sidebar.php uses lucide_icon('peso') for Sales navigation"
);
assertCondition(
    strpos($sidebarContent, "lucide_icon('dollar-sign', 'sidebar-lucide-icon')") === false,
    "views/layouts/sidebar.php no longer uses lucide_icon('dollar-sign') for Sales"
);

// 3. Verify cards.php machine terminology
$cardsContent = file_get_contents($rootDir . '/views/dashboard/cards.php');
assertCondition(
    strpos($cardsContent, "'Car Wash Controller'") !== false,
    "views/dashboard/cards.php references 'Car Wash Controller'"
);
assertCondition(
    strpos($cardsContent, '<h5 class="panel-title mb-0 fw-bold text-white">Controller Rack Unit</h5>') === false,
    "views/dashboard/cards.php removed static hardcoded 'Controller Rack Unit'"
);
assertCondition(
    strpos($cardsContent, 'VendoCore') !== false,
    "views/dashboard/cards.php preserves VendoCore badge"
);
assertCondition(
    strpos($cardsContent, 'ESP32-WROOM-32') !== false,
    "views/dashboard/cards.php preserves ESP32-WROOM-32 identifier"
);

// 4. Verify sales.php UI modifications
$salesContent = file_get_contents($rootDir . '/dashboard/sales.php');
assertCondition(
    strpos($salesContent, "<?= lucide_icon('peso', 'me-2 text-primary-vl') ?>Sales") !== false,
    "dashboard/sales.php heading renders lucide_icon('peso')"
);
assertCondition(
    strpos($salesContent, "<?= lucide_icon('dollar-sign', 'me-2 text-primary-vl') ?>Sales") === false,
    "dashboard/sales.php heading does not render dollar-sign icon"
);
assertCondition(
    strpos($salesContent, 'id="salesFilterDropdown"') === false,
    "dashboard/sales.php removed Filters button (#salesFilterDropdown)"
);
assertCondition(
    strpos($salesContent, 'id="salesAnalyticsFilterForm"') === false,
    "dashboard/sales.php removed Filters popover form (#salesAnalyticsFilterForm)"
);
assertCondition(
    strpos($salesContent, 'id="salesExportExcelBtn"') !== false,
    "dashboard/sales.php preserves Export Excel button (#salesExportExcelBtn)"
);
assertCondition(
    strpos($salesContent, 'data-period="today"') !== false &&
    strpos($salesContent, 'data-period="week"') !== false &&
    strpos($salesContent, 'data-period="month"') !== false &&
    strpos($salesContent, 'data-period="year"') !== false &&
    strpos($salesContent, 'data-period="all"') !== false,
    "dashboard/sales.php preserves all 5 date range buttons"
);

// 5. Compliance: Zero Emojis and Zero Forbidden Words
$modifiedFiles = [
    'includes/functions.php' => $rootDir . '/includes/functions.php',
    'views/dashboard/cards.php' => $rootDir . '/views/dashboard/cards.php',
    'views/layouts/sidebar.php' => $rootDir . '/views/layouts/sidebar.php',
    'dashboard/sales.php' => $rootDir . '/dashboard/sales.php',
];

$emojiPattern = '/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u';

foreach ($modifiedFiles as $name => $path) {
    $content = file_get_contents($path);
    $hasEmoji = preg_match($emojiPattern, $content);
    assertCondition(
        !$hasEmoji,
        "No emojis in {$name}"
    );

    $hasForbiddenWord = preg_match('/\bfle' . 'et\b/i', $content);
    assertCondition(
        !$hasForbiddenWord,
        "No forbidden word in {$name}"
    );
}

echo PHP_EOL . "=== RESULTS: {$passed} / {$total} tests passed ===" . PHP_EOL;

if ($passed === $total) {
    echo "STATUS: ALL TESTS PASSED SUCCESSFULLY." . PHP_EOL;
    exit(0);
} else {
    echo "STATUS: SOME TESTS FAILED." . PHP_EOL;
    exit(1);
}
