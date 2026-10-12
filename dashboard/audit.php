<?php
/**
 * ============================================================
 * VendoLink — Audit Redirect
 * ============================================================
 *
 * Redirects to Sales Overview (sales.php) as Sales Overview is the
 * unified page for financial verification and sales history.
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/../config/config.php';
header('Location: ' . BASE_URL . '/dashboard/sales.php', true, 301);
exit;
