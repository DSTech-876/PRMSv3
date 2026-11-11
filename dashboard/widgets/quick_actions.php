<?php
/**
 * Quick actions panel — the most common destinations for an administrator,
 * rendered as a compact tile grid to minimise clicks.
 *
 * Links are filtered by the existing permission helpers, so this panel never
 * widens a user's access.
 *
 * Requires: includes/ui.php (and config/auth.php for has_permission()).
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ui.php';

$quickActionGroups = [
    'Procurement' => [
        ['label' => 'New Request',    'href' => '/procurement/add.php',           'icon' => 'bi-plus-circle'],
        ['label' => 'All Requests',   'href' => '/procurement/list.php',          'icon' => 'bi-list-task'],
        ['label' => 'RFQs',           'href' => '/rfq/list.php',                  'icon' => 'bi-journal-text'],
        ['label' => 'Approval Queue', 'href' => '/dashboard/approval_queue.php',  'icon' => 'bi-clock-history'],
        ['label' => 'Vendors',        'href' => '/vendors/list.php',              'icon' => 'bi-shop'],
        ['label' => 'Contracts',      'href' => '/contracts/list.php',            'icon' => 'bi-file-earmark-medical'],
    ],
    'Financial' => [
        ['label' => 'Commitments',    'href' => '/commitments/list.php', 'icon' => 'bi-pin-angle'],
        ['label' => 'Purchase Orders','href' => '/po/list.php',          'icon' => 'bi-receipt'],
        ['label' => 'Invoices',       'href' => '/invoice/list.php',     'icon' => 'bi-file-earmark-ruled'],
        ['label' => 'Payments',       'href' => '/payment/list.php',     'icon' => 'bi-credit-card'],
        ['label' => 'Reimbursements', 'href' => '/reimbursement/list.php','icon' => 'bi-arrow-return-left'],
        ['label' => 'Petty Cash',     'href' => '/petty_cash/list.php',  'icon' => 'bi-cash-coin'],
    ],
    'Inventory & Assets' => [
        ['label' => 'Inventory Dashboard', 'href' => '/inventory/dashboard.php',        'icon' => 'bi-box-seam'],
        ['label' => 'Items',               'href' => '/inventory/items/list.php',       'icon' => 'bi-boxes'],
        ['label' => 'Requisitions',        'href' => '/inventory/requisitions/list.php','icon' => 'bi-card-checklist'],
        ['label' => 'Receiving (GRN)',     'href' => '/inventory/receiving/list.php',   'icon' => 'bi-truck'],
        ['label' => 'Transfers',           'href' => '/inventory/transfers/list.php',   'icon' => 'bi-arrow-left-right'],
        ['label' => 'Stocktake',           'href' => '/inventory/stocktake/list.php',   'icon' => 'bi-clipboard-check'],
    ],
    'Administration' => [
        ['label' => 'Users',          'href' => '/users/list.php',            'icon' => 'bi-people'],
        ['label' => 'Settings',       'href' => '/admin/settings.php',        'icon' => 'bi-gear'],
        ['label' => 'Audit Log',      'href' => '/audit/list.php',            'icon' => 'bi-shield-check'],
        ['label' => 'Email Diagnostics', 'href' => '/tools/email_diagnostic.php', 'icon' => 'bi-envelope-check'],
        ['label' => 'Monthly Report', 'href' => '/dashboard/monthly.php',     'icon' => 'bi-bar-chart-line'],
        ['label' => 'Management View','href' => '/dashboard/management.php',  'icon' => 'bi-person-badge'],
        ['label' => 'Notifications',  'href' => '/notifications/index.php',   'icon' => 'bi-bell'],
    ],
];

/* Reminder Settings is gated separately since it has its own dedicated
   permission rather than belonging to the always-shown Administration set. */
if (function_exists('has_permission') && has_permission('manage_reminder_settings')) {
    $quickActionGroups['Administration'][] = [
        'label' => 'Reminder Settings', 'href' => '/admin/reminder_settings.php', 'icon' => 'bi-alarm',
    ];
}

/* Permission-gated extras keep the panel honest about what the user can open. */
if (function_exists('has_permission') && has_permission('access_test_suite')) {
    $quickActionGroups['Administration'][] = [
        'label' => 'Test Suite', 'href' => '/admin/test_suite.php', 'icon' => 'bi-bug',
    ];
}

foreach ($quickActionGroups as $groupLabel => $groupActions) {
    ui_section_open($groupLabel, true, 'bi-lightning-charge');
    ui_action_tiles($groupActions);
    ui_section_close();
}
