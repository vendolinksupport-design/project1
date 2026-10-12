<?php
$_GET['page'] = 2;
var_dump(filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT));
var_dump((int)($_GET['page'] ?? 1));
