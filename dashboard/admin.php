<?php
/**
 * Admin dashboard.
 *
 * Split into tabbed panels so no single view becomes excessively long.
 * All access control, data sources and widget includes are unchanged —
 * this file only arranges them.
 */
$REQUIRE_PERMISSION = 'manage_users';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/ui.php';
require_once $_SERVER['DOCUMENT_ROOT']."/includes/header.php";

$displayRole = match ($_SESSION['role_name'] ?? '') {
    'SuperAdmin' => 'System Administrator',
    'HOD'        => 'Head of Department',
    default      => $_SESSION['role_name'] ?? 'User',
};

$widgets = $_SERVER['DOCUMENT_ROOT'] . '/dashboard/widgets/';
?>

<div class="ui-page">

<?php
ui_page_header([
    'title'    => 'Admin Dashboard',
    'subtitle' => sprintf(
        'Welcome back, %s • %s • %s',
        $_SESSION['full_name'] ?? 'User',
        $displayRole,
        date('l, j F Y')
    ),
    'icon'        => 'bi-speedometer2',
    'breadcrumbs' => [
        ['label' => 'Home', 'href' => '/dashboard/index.php'],
        ['label' => 'Admin Dashboard'],
    ],
    'actions' => [
        ['label' => 'New Request',    'href' => '/procurement/add.php',          'icon' => 'bi-plus-circle', 'variant' => 'primary'],
        ['label' => 'Approval Queue', 'href' => '/dashboard/approval_queue.php', 'icon' => 'bi-clock-history'],
        ['label' => 'Users',          'href' => '/users/list.php',               'icon' => 'bi-people'],
        ['label' => 'Settings',       'href' => '/admin/settings.php',           'icon' => 'bi-gear'],
        ['label' => 'Refresh',        'icon' => 'bi-arrow-clockwise', 'onclick' => 'location.reload()', 'title' => 'Refresh page'],
    ],
]);

/* KPI strip stays outside the tabs: it is the one thing every admin needs
   on every visit, and it is cheap to render. */
include $widgets . 'admin_kpis.php';

ui_tabs('admin', [
    ['id' => 'overview',   'label' => 'Overview',   'icon' => 'bi-grid-1x2',        'active' => true],
    ['id' => 'actions',    'label' => 'My Actions', 'icon' => 'bi-check2-square'],
    ['id' => 'workflow',   'label' => 'Workflow',   'icon' => 'bi-diagram-3'],
    ['id' => 'financials', 'label' => 'Financials', 'icon' => 'bi-cash-stack'],
    ['id' => 'analytics',  'label' => 'Analytics',  'icon' => 'bi-graph-up'],
    ['id' => 'activity',   'label' => 'Activity',   'icon' => 'bi-clock-history'],
    ['id' => 'quick',      'label' => 'Quick Links','icon' => 'bi-lightning-charge'],
]);
?>

<?php ui_tabpanel_open('admin', 'overview', true); ?>
    <div class="ui-grid ui-grid--2">
        <div><?php include $widgets . 'kpis.php'; ?></div>
        <div><?php include $widgets . 'alerts.php'; ?></div>
    </div>
    <div class="ui-grid ui-grid--2">
        <div><?php include $widgets . 'pipeline.php'; ?></div>
        <div><?php include $widgets . 'monthly_trend.php'; ?></div>
    </div>
<?php ui_tabpanel_close(); ?>

<?php ui_tabpanel_open('admin', 'actions'); ?>
    <?php include $widgets . 'pending_actions.php'; ?>
<?php ui_tabpanel_close(); ?>

<?php ui_tabpanel_open('admin', 'workflow'); ?>
    <?php include $widgets . 'workflow_monitor.php'; ?>
<?php ui_tabpanel_close(); ?>

<?php ui_tabpanel_open('admin', 'financials'); ?>
    <div class="ui-grid ui-grid--2">
        <div><?php include $widgets . 'payment_progress.php'; ?></div>
        <div><?php include $widgets . 'commitment_utilization.php'; ?></div>
    </div>
    <div class="ui-grid ui-grid--2">
        <div><?php include $widgets . 'overdue_invoices.php'; ?></div>
        <div><?php include $widgets . 'po_variations.php'; ?></div>
    </div>
<?php ui_tabpanel_close(); ?>

<?php ui_tabpanel_open('admin', 'analytics'); ?>
    <div class="ui-grid ui-grid--2">
        <div><?php include $widgets . 'branch_summary.php'; ?></div>
        <div><?php include $widgets . 'top_vendors.php'; ?></div>
    </div>
<?php ui_tabpanel_close(); ?>

<?php ui_tabpanel_open('admin', 'activity'); ?>
    <?php include $widgets . 'recent_activity.php'; ?>
<?php ui_tabpanel_close(); ?>

<?php ui_tabpanel_open('admin', 'quick'); ?>
    <?php include $widgets . 'quick_actions.php'; ?>
<?php ui_tabpanel_close(); ?>

</div>

<?php require_once $_SERVER['DOCUMENT_ROOT']."/includes/footer.php"; ?>
