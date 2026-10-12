<?php
$css = file_get_contents(__DIR__ . '/../assets/css/app.css');
preg_match_all('/([^{}]+)\{([^{}]*)\}/is', $css, $matches, PREG_SET_ORDER);
foreach ($matches as $m) {
    $sel = trim($m[1]);
    $props = trim($m[2]);
    if (stripos($props, 'height') !== false && (stripos($sel, 'table') !== false || stripos($sel, 'tx-') !== false || stripos($sel, 'archive') !== false || stripos($sel, 'panel') !== false)) {
        echo "$sel => $props\n\n";
    }
}
