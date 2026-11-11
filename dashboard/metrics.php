<?php
if (!isset($pdo)) {
    $REQUIRE_PERMISSION = 'view_finance_dashboard';
    require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
    require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
}
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

$year  = date('Y');
$month = date('m');

/* ================================
   Financial Metrics
================================ */

// This month
$invoicesThisMonth = $pdo->query("
    SELECT COALESCE(SUM(invoice_amount),0)
    FROM invoices
    WHERE YEAR(invoice_date) = $year
      AND MONTH(invoice_date) = $month
")->fetchColumn();

$paymentsThisMonth = $pdo->query("
    SELECT COALESCE(SUM(payment_amount),0)
    FROM payments
    WHERE YEAR(payment_date) = $year
      AND MONTH(payment_date) = $month
")->fetchColumn();

// Last month
$invoicesLastMonth = $pdo->query("
    SELECT COALESCE(SUM(invoice_amount),0)
    FROM invoices
    WHERE invoice_date >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH,'%Y-%m-01')
      AND invoice_date <  DATE_FORMAT(CURDATE(),'%Y-%m-01')
")->fetchColumn();

$paymentsLastMonth = $pdo->query("
    SELECT COALESCE(SUM(payment_amount),0)
    FROM payments
    WHERE payment_date >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH,'%Y-%m-01')
      AND payment_date <  DATE_FORMAT(CURDATE(),'%Y-%m-01')
")->fetchColumn();

/* ================================
   Global Indicators
================================ */

$outstanding = $pdo->query("
    SELECT balance FROM vw_outstanding_balance
")->fetchColumn();


$openPOs = $pdo->query("
    SELECT COUNT(*) FROM purchase_orders WHERE status = 'Open'
")->fetchColumn();

$overdueInvoices = $pdo->query("
    SELECT COUNT(*)
    FROM invoices
    WHERE status != 'Paid'
      AND invoice_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
")->fetchColumn();

/* ================================
   Trend calculations
================================ */
$invoiceTrend = trend($invoicesThisMonth, $invoicesLastMonth);
$paymentTrend = trend($paymentsThisMonth, $paymentsLastMonth);
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/ui.php';

/** Map trend()'s Bootstrap text class onto the design-system equivalent. */
$trendClass = static fn(string $class): string => match ($class) {
    'text-success' => 'ui-text-success',
    'text-danger'  => 'ui-text-danger',
    default        => 'ui-muted',
};

/** Render a this-month / last-month / trend comparison card. */
$metricsComparison = static function (string $title, string $icon, $thisMonth, $lastMonth, array $trend) use ($trendClass): void {
    ui_card_open(['title' => $title, 'icon' => $icon]);
    ?>
    <dl class="ui-dl">
      <div class="ui-dl__row"><dt class="ui-dl__key">This Month</dt><dd class="ui-dl__val"><?= money($thisMonth) ?></dd></div>
      <div class="ui-dl__row"><dt class="ui-dl__key">Last Month</dt><dd class="ui-dl__val"><?= money($lastMonth) ?></dd></div>
      <div class="ui-dl__row">
        <dt class="ui-dl__key">Trend</dt>
        <dd class="ui-dl__val <?= $trendClass($trend['class']) ?>"><?= $trend['icon'] ?> <?= htmlspecialchars((string)$trend['percent']) ?>%</dd>
      </div>
    </dl>
    <?php
    ui_card_close();
};
?>
<div class="ui-page">
<?php
ui_page_header([
    'title'       => 'Financial Metrics',
    'subtitle'    => 'Department of Government Chemist • ' . date('F Y'),
    'icon'        => 'bi-cash-coin',
    'breadcrumbs' => [
        ['label' => 'Home', 'href' => '/dashboard/index.php'],
        ['label' => 'Reports'],
        ['label' => 'Financial Metrics'],
    ],
    'actions' => [
        ['label' => 'Monthly Trends', 'href' => '/dashboard/monthly.php', 'icon' => 'bi-graph-up'],
        ['label' => 'Dashboard', 'href' => '/dashboard/index.php', 'icon' => 'bi-speedometer2'],
    ],
]);

ui_kpi_grid([
    [
        'label'   => 'Outstanding Balance',
        'value'   => money($outstanding ?: 0),
        'icon'    => 'bi-wallet2',
        'variant' => 'blue',
    ],
    [
        'label'   => 'Open Purchase Orders',
        'value'   => number_format((int)$openPOs),
        'icon'    => 'bi-file-earmark-text',
        'href'    => '/purchase_orders/list.php?status=Open',
        'variant' => 'purple',
    ],
    [
        'label'   => 'Overdue Invoices',
        'value'   => number_format((int)$overdueInvoices),
        'meta'    => '30+ days unpaid',
        'icon'    => 'bi-exclamation-octagon',
        'variant' => (int)$overdueInvoices > 0 ? 'red' : 'green',
    ],
]);

echo '<div class="ui-grid ui-grid--2">';
$metricsComparison('Invoices', 'bi-receipt', $invoicesThisMonth, $invoicesLastMonth, $invoiceTrend);
$metricsComparison('Payments', 'bi-cash-stack', $paymentsThisMonth, $paymentsLastMonth, $paymentTrend);
echo '</div>';
?>
</div>
<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
