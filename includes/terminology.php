<?php
/**
 * ============================================================
 * VendoLink — Canonical Terminology & Label Dictionary
 * ============================================================
 *
 * Single source of truth for user-facing terms, labels, and actions
 * across the VendoLink admin and machine owner portals.
 *
 * @package VendoLink
 * @version 1.0.0
 */

// ============================================================
// Canonical Concept Constants
// ============================================================
define('TERM_OVERVIEW',               'Overview');
define('TERM_MACHINES',               'Machines');
define('TERM_CUSTOMERS',              'Customers');
define('TERM_MACHINE_ACTIVATION',     'Machine Activation');
define('TERM_ADMIN_ACCOUNTS',         'Admin Accounts');
define('TERM_ONLINE',                 'Online');
define('TERM_OFFLINE',                'Offline');
define('TERM_LAST_SEEN',              'Last Seen');
define('TERM_REGISTER_MACHINE',       'Register Machine');
define('TERM_ACTIVATE_MACHINE',       'Activate Machine');
define('TERM_MACHINE_ACTIVITY',       'Machine Activity');
define('TERM_RECENT_ACTIVITY',        'Recent Activity');
define('TERM_CUSTOMER_SUPPORT',       'Customer Support');
define('TERM_UNUSED_KEYS',            'Unused Keys');
define('TERM_ACTIVE_ACTIVATIONS',     'Active Activations');
define('TERM_TOTAL_MACHINES',         'Total Machines');
define('TERM_ONLINE_MACHINES',        'Online Machines');
define('TERM_OFFLINE_MACHINES',       'Offline Machines');
define('TERM_OPEN_SUPPORT_REQUESTS',  'Open Support Requests');

define('TERM_DASHBOARD',              'Dashboard');
define('TERM_ALERTS',                 'Alerts');
define('TERM_MAINTENANCE',            'Maintenance');
define('TERM_SERVICE_REMINDERS',       'Service Reminders');
define('TERM_HISTORY',                'History');
define('TERM_TRANSACTION_HISTORY',    'History');
define('TERM_TRANSACTION_RECORDS',    'Transaction Records');
define('TERM_RECENT_TRANSACTIONS',    'Transaction Records');
define('TERM_SALES_OVERVIEW',         'Sales Overview');
define('TERM_TODAYS_SALES',           "Today's Sales");
define('TERM_TODAYS_REVENUE',         "Today's Revenue");
define('TERM_TODAYS_TRANSACTIONS',    "Today's Transactions");
define('TERM_WATER_SALES',            'Water Sales');
define('TERM_SOAP_SALES',             'Soap Sales');
define('TERM_CASH_COLLECTIONS',       'Cash Collections');
define('TERM_PURCHASED_TIME',         'Purchased Time');
define('TERM_CURRENT_SESSION_TIME',   'Current Session Time');
define('TERM_TOTAL_RUNTIME',          'Total Runtime');
define('TERM_MACHINE_STATUS',         'Machine Status');
define('TERM_TIME_PER_COIN',          'Time per ₱5');
define('TERM_VENDOAI_INSIGHTS',       'VendoAI Insights');
define('TERM_ESTIMATED_MONTHLY_SALES', 'Estimated Monthly Sales');
define('TERM_WATER_REFILL_ESTIMATE',  'Water Refill Estimate');
define('TERM_SOAP_REFILL_ESTIMATE',   'Soap Refill Estimate');
define('TERM_PEAK_USAGE_TIME',        'Peak Usage Time');

define('TERM_MACHINE_CONFIGURATION', 'Machine Configuration');
define('TERM_CONFIGURATION_HISTORY', 'Configuration History');

// Standard Action Buttons
define('TERM_BTN_REGISTER_MACHINE',   'Register Machine');
define('TERM_BTN_ACTIVATE_MACHINE',   'Activate Machine');
define('TERM_BTN_VIEW_DETAILS',       'View Details');
define('TERM_BTN_VIEW_MAINTENANCE',   'View Maintenance');
define('TERM_BTN_VIEW_MACHINE',       'View Machine');
define('TERM_BTN_VIEW_SALES',         'View Sales');
define('TERM_BTN_VIEW_HISTORY',       'View History');
define('TERM_BTN_VIEW_ALL',           'View All');
define('TERM_BTN_VIEW_MORE',          'View More');
define('TERM_BTN_OPEN_VENDOAI',       'Open VendoAI');
define('TERM_BTN_EDIT',               'Edit');
define('TERM_BTN_EDIT_CUSTOMER',      'Edit Customer');
define('TERM_BTN_MANAGE_ACCOUNTS',    'Manage Accounts');
define('TERM_BTN_VIEW_ACTIVITY',      'View Activity');
define('TERM_BTN_CONFIGURATION_HISTORY', 'Configuration History');
define('TERM_BTN_REFRESH_DATA',       'Refresh Data');
define('TERM_BTN_SAVE_CHANGES',       'Save Changes');
define('TERM_BTN_CANCEL',             'Cancel');
define('TERM_BTN_DELETE_MACHINE',     'Delete Machine');

// Canonical Dictionary Map
const VENDOLINK_TERMINOLOGY = [
    // Entities & Navigation
    'machine_configuration'   => TERM_MACHINE_CONFIGURATION,
    'configuration_history'   => TERM_CONFIGURATION_HISTORY,
    'overview'                => TERM_OVERVIEW,
    'dashboard'               => TERM_DASHBOARD,
    'machines'                => TERM_MACHINES,
    'customers'               => TERM_CUSTOMERS,
    'machine_activation'      => TERM_MACHINE_ACTIVATION,
    'admin_accounts'          => TERM_ADMIN_ACCOUNTS,
    'customer_support'        => TERM_CUSTOMER_SUPPORT,
    'alerts'                  => TERM_ALERTS,
    'maintenance'             => TERM_MAINTENANCE,
    'service_reminders'       => TERM_SERVICE_REMINDERS,
    'history'                 => TERM_HISTORY,
    'transaction_history'     => TERM_TRANSACTION_HISTORY,
    'archive'                 => TERM_TRANSACTION_HISTORY,
    'history_archive'         => TERM_TRANSACTION_HISTORY,
    'transaction_records'     => TERM_TRANSACTION_RECORDS,
    'recent_transactions'     => TERM_RECENT_TRANSACTIONS,
    'sales_overview'          => TERM_SALES_OVERVIEW,

    // Statuses & Telemetry
    'online'                  => TERM_ONLINE,
    'offline'                 => TERM_OFFLINE,
    'last_seen'               => TERM_LAST_SEEN,
    'machine_status'          => TERM_MACHINE_STATUS,
    'runtime_status'          => TERM_MACHINE_STATUS,

    // Metrics & Telemetry
    'total_machines'          => TERM_TOTAL_MACHINES,
    'online_machines'         => TERM_ONLINE_MACHINES,
    'offline_machines'        => TERM_OFFLINE_MACHINES,
    'total_customers'         => 'Total Customers',
    'active_customers'        => 'Active Customers',
    'unused_keys'             => TERM_UNUSED_KEYS,
    'active_activations'      => TERM_ACTIVE_ACTIVATIONS,
    'open_support_requests'   => TERM_OPEN_SUPPORT_REQUESTS,
    'todays_sales'            => TERM_TODAYS_SALES,
    'todays_revenue'          => TERM_TODAYS_REVENUE,
    'todays_transactions'     => TERM_TODAYS_TRANSACTIONS,
    'water_sales'             => TERM_WATER_SALES,
    'soap_sales'              => TERM_SOAP_SALES,
    'cash_collections'        => TERM_CASH_COLLECTIONS,
    'coin_box_collections'    => TERM_CASH_COLLECTIONS,
    'purchased_time'          => TERM_PURCHASED_TIME,
    'purchased_runtime'       => TERM_PURCHASED_TIME,
    'current_session_time'    => TERM_CURRENT_SESSION_TIME,
    'current_runtime'         => TERM_CURRENT_SESSION_TIME,
    'total_runtime'           => TERM_TOTAL_RUNTIME,
    'time_per_coin'           => TERM_TIME_PER_COIN,
    'service_time'            => TERM_TIME_PER_COIN,

    // VendoAI Insights
    'vendoai_insights'        => TERM_VENDOAI_INSIGHTS,
    'estimated_monthly_sales' => TERM_ESTIMATED_MONTHLY_SALES,
    'water_refill_estimate'   => TERM_WATER_REFILL_ESTIMATE,
    'soap_refill_estimate'    => TERM_SOAP_REFILL_ESTIMATE,
    'peak_usage_time'         => TERM_PEAK_USAGE_TIME,

    // Events & Logs
    'machine_activity'        => TERM_MACHINE_ACTIVITY,
    'recent_activity'         => TERM_RECENT_ACTIVITY,

    // Actions & Buttons
    'btn_register_machine'    => TERM_BTN_REGISTER_MACHINE,
    'btn_activate_machine'    => TERM_BTN_ACTIVATE_MACHINE,
    'btn_view_details'        => TERM_BTN_VIEW_DETAILS,
    'btn_view_maintenance'    => TERM_BTN_VIEW_MAINTENANCE,
    'view_maintenance'        => TERM_BTN_VIEW_MAINTENANCE,
    'btn_view_machine'        => TERM_BTN_VIEW_MACHINE,
    'view_machine'            => TERM_BTN_VIEW_MACHINE,
    'btn_view_sales'          => TERM_BTN_VIEW_SALES,
    'view_sales'              => TERM_BTN_VIEW_SALES,
    'btn_view_history'        => TERM_BTN_VIEW_HISTORY,
    'view_history'            => TERM_BTN_VIEW_HISTORY,
    'btn_view_all'            => TERM_BTN_VIEW_ALL,
    'view_all'                => TERM_BTN_VIEW_ALL,
    'btn_view_more'           => TERM_BTN_VIEW_MORE,
    'view_more'               => TERM_BTN_VIEW_MORE,
    'btn_open_vendoai'        => TERM_BTN_OPEN_VENDOAI,
    'open_vendoai'            => TERM_BTN_OPEN_VENDOAI,
    'btn_edit'                => TERM_BTN_EDIT,
    'btn_edit_customer'       => TERM_BTN_EDIT_CUSTOMER,
    'btn_manage_accounts'     => TERM_BTN_MANAGE_ACCOUNTS,
    'btn_view_activity'       => TERM_BTN_VIEW_ACTIVITY,
    'btn_refresh_data'        => TERM_BTN_REFRESH_DATA,
    'btn_save_changes'        => TERM_BTN_SAVE_CHANGES,
    'save_changes'            => TERM_BTN_SAVE_CHANGES,
    'save_configuration'      => TERM_BTN_SAVE_CHANGES,
    'btn_cancel'              => TERM_BTN_CANCEL,
    'cancel'                  => TERM_BTN_CANCEL,
    'btn_delete_machine'      => TERM_BTN_DELETE_MACHINE,
];

/**
 * Return the canonical term for a given dictionary key.
 *
 * @param string      $key     Terminology key
 * @param string|null $default Fallback string if key not found
 * @return string
 */
function term(string $key, ?string $default = null): string
{
    return VENDOLINK_TERMINOLOGY[$key] ?? ($default ?? ucfirst(str_replace('_', ' ', $key)));
}
