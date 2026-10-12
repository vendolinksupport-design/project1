<?php
/**
 * VendoLink Root Logout Alias Handler
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

redirect('auth/logout.php');
