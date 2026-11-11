<?php
/**
 * Viewer / Audit dashboard.
 *
 * Read-only oversight view. Permission guard and widget data sources are
 * unchanged; the layout is split into tabs so the page stays short.
 */
$REQUIRE_PERMISSION = 'view_audit_dashboard';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/ui.php';
require_once $_SERVER['DOCUMENT_ROOT']."/includes/header.php";

$displayRole = match ($_SESSION['role_name'] ?? '') {
    'Viewer' => 'Audit / Read-Only User',
    default  => $_SESSION['role_name'] ?? 'User',
};

$widgets = __DIR__ . '/widgets/';
?>

<div class="ui-page">

<?php
ui_page_header([
    'title'    => 'Viewer / Audit Dashboard',
    'subtitle' => sprintf(
        'Welcome back, %s • %s • %s',
        $_SESSION['full_name'] ?? 'User',
        $displayRole,
        date('l, j F Y')
    ),
    'icon'        => 'bi-clipboard-data',
    'breadcrumbs' => [
        ['label' => 'Home', 'href' => '/dashboard/index.php'],
        ['label' => 'Viewer / Audit Dashboard'],
    ],
    'actions' => [
        ['label' => 'All Requests', 'href' => '/procurement/list.php', 'icon' => 'bi-list-ul', 'variant' => 'primary'],
        ['label' => 'Reports',      'href' => '/reports/index.php',    'icon' => 'bi-file-earmark-bar-graph'],
        ['label' => 'Refresh',      'icon' => 'bi-arrow-clockwise', 'onclick' => 'location.reload()', 'title' => 'Refresh page'],
    ],
]);

ui_tabs('viewer', [
    ['id' => 'overview',  'label' => 'Overview',  'icon' => 'bi-grid-1x2', 'active' => true],
    ['id' => 'analytics', 'label' => 'Analytics', 'icon' => 'bi-graph-up'],
    ['id' => 'activity',  'label' => 'Activity',  'icon' => 'bi-clock-history'],
]);
?>

<?php ui_tabpanel_open('viewer', 'overview', true); ?>
    <div class="ui-grid ui-grid--2">
        <div><?php include $widgets . 'kpis.php'; ?></div>
        <div><?php include $widgets . 'alerts.php'; ?></div>
    </div>
    <div class="ui-grid ui-grid--2">
        <div><?php include $widgets . 'pipeline.php'; ?></div>
        <div><?php include $widgets . 'overdue_invoices.php'; ?></div>
    </div>
<?php ui_tabpanel_close(); ?>

<?php ui_tabpanel_open('viewer', 'analytics'); ?>
    <div class="ui-grid ui-grid--2">
        <div><?php include $widgets . 'branch_summary.php'; ?></div>
        <div><?php include $widgets . 'monthly_trend.php'; ?></div>
    </div>
<?php ui_tabpanel_close(); ?>

<?php ui_tabpanel_open('viewer', 'activity'); ?>
    <?php include $widgets . 'recent_activity.php'; ?>
<?php ui_tabpanel_close(); ?>

</div>

<?php require_once $_SERVER['DOCUMENT_ROOT']."/includes/footer.php"; ?>
