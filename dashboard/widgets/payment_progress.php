<?php
if (!isset($pdo)) {
    require_once __DIR__.'/_init.php';
}
require_once dirname(__DIR__, 2) . '/includes/ui.php';

$poPayments = $pdo->query("
    SELECT po.po_id, po.po_number, po.po_total, po.status,
           IFNULL(SUM(i.invoice_amount), 0) AS total_invoiced,
           IFNULL(SUM(p.paid), 0) AS total_paid,
           pr.currency
    FROM purchase_orders po
    LEFT JOIN invoices i ON po.po_id = i.po_id
    LEFT JOIN (
        SELECT invoice_id, SUM(payment_amount) AS paid
        FROM payments
        GROUP BY invoice_id
    ) p ON i.invoice_id = p.invoice_id
    LEFT JOIN commitments c ON po.commitment_id = c.commitment_id
    LEFT JOIN procurement_requests pr ON c.request_id = pr.request_id
    WHERE po.status = 'Open'
    GROUP BY po.po_id
    ORDER BY po.po_date DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

ui_card_open([
    'title' => 'Payment Progress',
    'icon'  => 'bi-credit-card',
    'tools' => '<a class="ui-link" href="/po/list.php?status=Open">All Open POs <i class="bi bi-arrow-right" aria-hidden="true"></i></a>',
]);

if (empty($poPayments)) {
    ui_empty('No open purchase orders.', 'bi-receipt');
} else {
    echo '<div class="ui-scroll-area ui-scroll-area--md"><div class="ui-meter-list">';
    foreach ($poPayments as $po) {
        $invoiced = (float)$po['total_invoiced'];
        $paid     = (float)$po['total_paid'];
        $total    = (float)$po['po_total'];
        $pctInvoiced = $total > 0 ? min(round(($invoiced / $total) * 100), 100) : 0;
        $pctPaid     = $total > 0 ? min(round(($paid / $total) * 100), 100) : 0;
        $pctUnpaid   = max($pctInvoiced - $pctPaid, 0);
        ?>
        <div class="ui-meter">
            <div class="ui-meter__head">
                <a class="ui-meter__title" href="/po/view.php?id=<?= (int)$po['po_id'] ?>"><?= ui_e($po['po_number']) ?></a>
                <span class="ui-muted">JMD <?= ui_e(number_format($total, 0)) ?></span>
            </div>
            <div class="ui-progress"
                 role="img"
                 aria-label="<?= ui_e(sprintf('%d%% paid, %d%% invoiced of JMD %s', $pctPaid, $pctInvoiced, number_format($total, 0))) ?>">
                <span class="ui-progress__seg ui-progress__seg--primary" style="width: <?= $pctPaid ?>%"></span>
                <span class="ui-progress__seg ui-progress__seg--secondary" style="width: <?= $pctUnpaid ?>%"></span>
            </div>
            <div class="ui-meter__foot">
                <span>Paid: <strong>JMD <?= ui_e(number_format($paid, 0)) ?></strong></span>
                <span>Invoiced: <strong>JMD <?= ui_e(number_format($invoiced, 0)) ?></strong></span>
            </div>
        </div>
        <?php
    }
    echo '</div></div>';
}

ui_card_close();
