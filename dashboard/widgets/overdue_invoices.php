<?php
if (!isset($pdo)) {
    require_once __DIR__.'/_init.php';
}
require_once dirname(__DIR__, 2) . '/includes/ui.php';

$overdue = $pdo->query("
    SELECT i.invoice_id, i.invoice_number, i.invoice_date, i.invoice_amount, i.status,
           po.po_number,
           IFNULL(SUM(p.payment_amount), 0) AS total_paid,
           DATEDIFF(CURDATE(), i.invoice_date) AS days_overdue
    FROM invoices i
    JOIN purchase_orders po ON i.po_id = po.po_id
    LEFT JOIN payments p ON i.invoice_id = p.invoice_id
    WHERE i.status != 'Paid'
    GROUP BY i.invoice_id
    HAVING days_overdue > 30
    ORDER BY days_overdue DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

ui_card_open([
    'title' => 'Overdue Invoices',
    'icon'  => 'bi-clock-history',
    'tools' => ui_badge(count($overdue) . ' overdue', $overdue ? 'danger' : 'success'),
    'flush' => true,
]);

if (empty($overdue)) {
    echo '<div class="ui-card__pad">';
    ui_empty('No overdue invoices.', 'bi-check-circle');
    echo '</div>';
} else {
    ui_table_toolbar('overdue-invoices-table', ['placeholder' => 'Search invoices…']);
    ui_table_open('overdue-invoices-table', [
        'perPage' => 10,
        'label'   => 'Overdue invoices',
    ]);
    ?>
    <thead>
        <tr>
            <th scope="col" data-sortable>Invoice</th>
            <th scope="col" data-sortable>PO</th>
            <th scope="col" class="ui-num" data-sortable>Amount</th>
            <th scope="col" class="ui-num" data-sortable>Paid</th>
            <th scope="col" class="ui-num" data-sortable>Days Overdue</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($overdue as $inv):
        $days = (int)$inv['days_overdue'];
        if ($days > 90) {
            $ageVariant = 'danger';
        } elseif ($days > 60) {
            $ageVariant = 'warning';
        } else {
            $ageVariant = 'info';
        }
    ?>
        <tr>
            <td><a class="ui-link" href="/invoice/view.php?id=<?= (int)$inv['invoice_id'] ?>"><?= ui_e($inv['invoice_number']) ?></a></td>
            <td class="ui-muted"><?= ui_e($inv['po_number']) ?></td>
            <td class="ui-num ui-num--strong">$<?= ui_e(number_format((float)$inv['invoice_amount'], 2)) ?></td>
            <td class="ui-num">$<?= ui_e(number_format((float)$inv['total_paid'], 2)) ?></td>
            <td class="ui-num"><?= ui_badge($days . ' days', $ageVariant) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <?php
    ui_table_close('overdue-invoices-table');
}

ui_card_close();
