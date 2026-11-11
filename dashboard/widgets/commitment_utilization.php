<?php
if (!isset($pdo)) {
    require_once __DIR__.'/_init.php';
}
require_once dirname(__DIR__, 2) . '/includes/ui.php';

$commitments = $pdo->query("
    SELECT c.commitment_id, c.commitment_number, c.commitment_total, c.status,
           IFNULL(SUM(po.po_total), 0) AS total_pos,
           IFNULL(SUM(inv_total.invoiced), 0) AS total_invoiced
    FROM commitments c
    LEFT JOIN purchase_orders po ON c.commitment_id = po.commitment_id
    LEFT JOIN (
        SELECT po.commitment_id, SUM(i.invoice_amount) AS invoiced
        FROM purchase_orders po
        JOIN invoices i ON po.po_id = i.po_id
        GROUP BY po.commitment_id
    ) inv_total ON c.commitment_id = inv_total.commitment_id
    WHERE c.status = 'open'
    GROUP BY c.commitment_id
    ORDER BY c.commitment_date DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

ui_card_open([
    'title' => 'Commitment Utilization',
    'icon'  => 'bi-pie-chart',
    'tools' => '<a class="ui-action ui-action--sm" href="/commitments/list.php">All Commitments</a>',
]);

if (empty($commitments)) {
    ui_empty('No open commitments.', 'bi-pin-angle');
} else {
    echo '<div class="ui-scroll-area ui-scroll-area--md"><div class="ui-meter-list">';
    foreach ($commitments as $c) {
        $total     = (float)$c['commitment_total'];
        $pos       = (float)$c['total_pos'];
        $pctPO     = $total > 0 ? min(round(($pos / $total) * 100), 100) : 0;
        $remaining = max($total - $pos, 0);

        if ($pctPO >= 90) {
            $segClass = 'ui-progress__seg--danger';
        } elseif ($pctPO >= 70) {
            $segClass = 'ui-progress__seg--secondary';
        } else {
            $segClass = 'ui-progress__seg--info';
        }
        ?>
        <div class="ui-meter">
            <div class="ui-meter__head">
                <a class="ui-meter__title" href="/commitments/view.php?id=<?= (int)$c['commitment_id'] ?>"><?= ui_e($c['commitment_number']) ?></a>
                <span class="ui-muted">Budget: <strong>$<?= ui_e(number_format($total, 2)) ?></strong></span>
            </div>
            <div class="ui-progress"
                 role="img"
                 aria-label="<?= ui_e($pctPO . '% of budget committed to purchase orders') ?>">
                <span class="ui-progress__seg <?= $segClass ?>" style="width: <?= $pctPO ?>%"></span>
            </div>
            <div class="ui-meter__foot">
                <span>POs: <strong>$<?= ui_e(number_format($pos, 2)) ?></strong> (<?= (int)$pctPO ?>%)</span>
                <span>Remaining: <strong>$<?= ui_e(number_format($remaining, 2)) ?></strong></span>
            </div>
        </div>
        <?php
    }
    echo '</div></div>';
}

ui_card_close();
