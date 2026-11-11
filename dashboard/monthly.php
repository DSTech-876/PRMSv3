<?php

$REQUIRE_PERMISSION = 'monthly_metrics';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';


$sql = "SELECT MONTH(pr.request_date) AS month, YEAR(pr.request_date) AS year, SUM(pr.estimated_value) AS total_amount, COUNT(*) AS request_count FROM procurement_requests pr GROUP BY year, month ORDER BY year DESC, month DESC LIMIT 12";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ui.php';

$trendRows = array_reverse($rows);
$chartRows = array_map(static fn(array $r): array => [
    'label' => date('M y', strtotime($r['year'] . '-' . $r['month'] . '-01')),
    'value' => (float)$r['total_amount'],
], $trendRows);

$totalRequests = array_sum(array_map(static fn($r) => (int)$r['request_count'], $rows));
$totalAmount   = array_sum(array_map(static fn($r) => (float)$r['total_amount'], $rows));
$avgAmount     = $totalRequests > 0 ? $totalAmount / $totalRequests : 0.0;
?>
<div class="ui-page">
<?php
ui_page_header([
    'title'       => 'Monthly Procurement Trend',
    'subtitle'    => 'Request volume and value across the last 12 months',
    'icon'        => 'bi-graph-up-arrow',
    'breadcrumbs' => [
        ['label' => 'Home', 'href' => '/dashboard/index.php'],
        ['label' => 'Reports', 'href' => '/dashboard/metrics.php'],
        ['label' => 'Monthly Trend'],
    ],
    'actions' => [
        ['label' => 'Metrics', 'href' => '/dashboard/metrics.php', 'icon' => 'bi-graph-up'],
        ['label' => 'Approval Queue', 'href' => '/dashboard/management.php', 'icon' => 'bi-list-check'],
    ],
]);

ui_kpi_grid([
    ['label' => 'Months Reported', 'value' => (string)count($rows), 'icon' => 'bi-calendar3', 'variant' => 'slate'],
    ['label' => 'Total Requests',  'value' => number_format($totalRequests), 'icon' => 'bi-journal-text', 'variant' => 'blue'],
    ['label' => 'Total Value',     'value' => money($totalAmount), 'icon' => 'bi-cash-stack', 'variant' => 'green'],
    ['label' => 'Average Request', 'value' => money($avgAmount), 'icon' => 'bi-calculator', 'variant' => 'purple'],
]);

ui_card_open(['title' => 'Value by Month', 'icon' => 'bi-bar-chart-line']);
ui_chart_columns($chartRows);
ui_card_close();

ui_card_open(['title' => 'Monthly Breakdown', 'icon' => 'bi-table', 'flush' => true]);
ui_table_toolbar('monthly-trend-table', [
    'columns'     => true,
    'export'      => true,
    'placeholder' => 'Search months…',
]);
ui_table_open('monthly-trend-table', [
    'paginate' => true,
    'perPage'  => 12,
    'label'    => 'Monthly procurement trend',
]);
?>
  <thead>
    <tr>
      <th scope="col">Month</th>
      <th scope="col" class="ui-num">Requests</th>
      <th scope="col" class="ui-num">Total Amount</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($rows)): ?>
    <tr><td colspan="3"><?php ui_empty('No monthly data available.', 'bi-calendar-x'); ?></td></tr>
  <?php else: foreach ($rows as $row): ?>
    <tr>
      <th scope="row"><?= date('M Y', strtotime($row['year'] . '-' . $row['month'] . '-01')) ?></th>
      <td class="ui-num"><?= (int)$row['request_count'] ?></td>
      <td class="ui-num ui-num--strong"><?= money($row['total_amount']) ?></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
<?php
ui_table_close('monthly-trend-table');
ui_card_close();
?>
</div>
<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
