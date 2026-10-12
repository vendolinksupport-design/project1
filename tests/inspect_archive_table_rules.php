<?php
$css = file_get_contents(__DIR__ . '/../assets/css/app.css');
$lines = explode("\n", $css);
echo "Searching app.css for table / card / pagination rules:\n";
foreach ($lines as $i => $line) {
    if (preg_match('/(tx-table-card|table-responsive|\btable\b|tbody|tx-pagination|archive)/i', $line)) {
        echo ($i + 1) . ': ' . trim($line) . "\n";
    }
}
