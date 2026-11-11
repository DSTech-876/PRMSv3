<?php
if (!isset($pdo)) {
require_once __DIR__.'/_init.php';
}
require_once dirname(__DIR__, 2) . '/includes/ui.php';

/* Current month */
$thisMonth = $pdo->query("
    SELECT 
      COUNT(DISTINCT pr.request_id) AS requests,
      COUNT(DISTINCT po.po_id) AS pos,
      IFNULL(SUM(i.invoice_amount),0) AS invoiced
    FROM procurement_requests pr
    LEFT JOIN commitments c ON pr.request_id = c.request_id
    LEFT JOIN purchase_orders po ON c.commitment_id = po.commitment_id
    LEFT JOIN invoices i ON po.po_id = i.po_id
    WHERE MONTH(pr.request_date) = MONTH(CURDATE())
      AND YEAR(pr.request_date) = YEAR(CURDATE())
")->fetch(PDO::FETCH_ASSOC);

/* Last month */
$lastMonth = $pdo->query("
    SELECT 
      COUNT(DISTINCT pr.request_id) AS requests,
      COUNT(DISTINCT po.po_id) AS pos,
      IFNULL(SUM(i.invoice_amount),0) AS invoiced
    FROM procurement_requests pr
    LEFT JOIN commitments c ON pr.request_id = c.request_id
    LEFT JOIN purchase_orders po ON c.commitment_id = po.commitment_id
    LEFT JOIN invoices i ON po.po_id = i.po_id
    WHERE MONTH(pr.request_date) = MONTH(CURDATE() - INTERVAL 1 MONTH)
      AND YEAR(pr.request_date) = YEAR(CURDATE() - INTERVAL 1 MONTH)
")->fetch(PDO::FETCH_ASSOC);

/**
 * Build a month-over-month delta descriptor for a metric.
 */
function monthlyTrendDelta(float $current, float $previous): array
{
    if ($previous <= 0.0) {
        return $current > 0.0
            ? ['label' => 'New', 'variant' => 'success', 'icon' => 'bi-arrow-up-right']
            : ['label' => 'No change', 'variant' => 'neutral', 'icon' => 'bi-dash'];
    }
    $change = (($current - $previous) / $previous) * 100;
    if (abs($change) < 0.5) {
        return ['label' => 'No change', 'variant' => 'neutral', 'icon' => 'bi-dash'];
    }
    return [
        'label'   => ($change > 0 ? '+' : '') . number_format($change, 1) . '%',
        'variant' => $change > 0 ? 'success' : 'danger',
        'icon'    => $change > 0 ? 'bi-arrow-up-right' : 'bi-arrow-down-right',
    ];
}

$metrics = [
    [
        'label'   => 'Requests',
        'icon'    => 'bi-file-earmark-text',
        'current' => (float)$thisMonth['requests'],
        'previous' => (float)$lastMonth['requests'],
        'money'   => false,
    ],
    [
        'label'   => 'Purchase Orders',
        'icon'    => 'bi-receipt',
        'current' => (float)$thisMonth['pos'],
        'previous' => (float)$lastMonth['pos'],
        'money'   => false,
    ],
    [
        'label'   => 'Invoiced',
        'icon'    => 'bi-cash-stack',
        'current' => (float)$thisMonth['invoiced'],
        'previous' => (float)$lastMonth['invoiced'],
        'money'   => true,
    ],
];

ui_card_open([
    'title' => 'Monthly Overview',
    'icon'  => 'bi-graph-up',
    'tools' => ui_badge(date('F Y') . ' vs ' . date('F Y', strtotime('-1 month')), 'info'),
    'flush' => true,
]);
?>
<div class="ui-table-wrap">
    <table class="ui-table ui-table--compact">
        <caption class="ui-visually-hidden">Month-over-month procurement metrics</caption>
        <thead>
            <tr>
                <th scope="col">Metric</th>
                <th scope="col" class="ui-num">This Month</th>
                <th scope="col" class="ui-num">Last Month</th>
                <th scope="col" class="ui-num">Change</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($metrics as $metric):
            $delta  = monthlyTrendDelta($metric['current'], $metric['previous']);
            $prefix = $metric['money'] ? '$' : '';
        ?>
            <tr>
                <th scope="row">
                    <i class="bi <?= ui_e($metric['icon']) ?> ui-row-icon" aria-hidden="true"></i>
                    <?= ui_e($metric['label']) ?>
                </th>
                <td class="ui-num ui-num--strong"><?= ui_e($prefix . number_format($metric['current'], 0)) ?></td>
                <td class="ui-num"><?= ui_e($prefix . number_format($metric['previous'], 0)) ?></td>
                <td class="ui-num">
                    <?= ui_badge($delta['label'], $delta['variant']) ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
ui_card_close();
