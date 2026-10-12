<?php
$css = file_get_contents(__DIR__ . '/../assets/css/app.css');
preg_match_all('/([^{}]+)\{([^{}]*(?:height|min-height|max-height)[^{}]*)\}/is', $css, $m, PREG_SET_ORDER);
foreach ($m as $x) {
    $sel = trim($x[1]);
    if (preg_match('/(body|app-shell|main-content|page-content|app-footer)/i', $sel)) {
        echo "$sel\n" . trim($x[2]) . "\n-------------------\n";
    }
}
