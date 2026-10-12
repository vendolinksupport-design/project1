<?php
$css = file_get_contents(__DIR__ . '/../assets/css/app.css');
preg_match_all('/([^{}]+)\{([^{}]*)\}/is', $css, $matches, PREG_SET_ORDER);
foreach ($matches as $m) {
    if (stripos($m[1], 'page-content') !== false) {
        echo trim($m[1]) . " =>\n" . trim($m[2]) . "\n\n";
    }
}
