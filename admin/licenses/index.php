<?php
/**
 * ============================================================
 * VendoLink — Machine Activation (Provisioning & Keys)
 * ============================================================
 *
 * Renders the Unified Machines & Hardware Hub with Tab 2
 * (Factory Provisioning & Keys) automatically active.
 *
 * @package VendoLink
 * @version 4.0.0
 */

if (!isset($_GET['tab'])) {
    $_GET['tab'] = 'provisioning';
}

require __DIR__ . '/../machines/index.php';
