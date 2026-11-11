<?php
if (!isset($pdo)) {
    require_once __DIR__.'/_init.php';
}
require_once dirname(__DIR__, 2) . '/includes/ui.php';

$variations = $pdo->query("
    SELECT pv.variation_id, pv.variation_amount, pv.reason, pv.status,
           pv.requested_at, po.po_number,
           u.full_name AS requested_by_name
    FROM po_variations pv
    JOIN purchase_orders po ON pv.po_id = po.po_id
    LEFT JOIN users u ON pv.requested_by = u.user_id
    ORDER BY pv.requested_at DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

$statusVariants = [
    'PENDING'  => 'warning',
    'APPROVED' => 'success',
    'REJECTED' => 'danger',
];

ui_card_open([
    'title' => 'PO Variations',
    'icon'  => 'bi-wrench-adjustable',
    'tools' => '<a class="ui-action ui-action--sm" href="/po/variation_queue.php">View Queue</a>',
    'flush' => true,
]);

if (empty($variations)) {
    echo '<div class="ui-card__pad">';
    ui_empty('No PO variations.', 'bi-check-circle');
    echo '</div>';
} else {
    ui_table_toolbar('po-variations-table', ['placeholder' => 'Search variations…']);
    ui_table_open('po-variations-table', [
        'perPage' => 10,
        'label'   => 'Purchase order variations',
    ]);
    ?>
    <thead>
        <tr>
            <th scope="col" data-sortable>PO</th>
            <th scope="col" class="ui-num" data-sortable>Amount</th>
            <th scope="col">Reason</th>
            <th scope="col" data-sortable>Status</th>
            <th scope="col" data-sortable>Date</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($variations as $v):
        $amount  = (float)$v['variation_amount'];
        $variant = $statusVariants[$v['status']] ?? 'neutral';
    ?>
        <tr>
            <td><strong><?= ui_e($v['po_number']) ?></strong></td>
            <td class="ui-num <?= $amount >= 0 ? 'ui-text-success' : 'ui-text-danger' ?>">
                <?= $amount >= 0 ? '+' : '-' ?>$<?= ui_e(number_format(abs($amount), 2)) ?>
            </td>
            <td class="ui-truncate" title="<?= ui_e($v['reason']) ?>"><?= ui_e($v['reason']) ?></td>
            <td><?= ui_badge((string)$v['status'], $variant) ?></td>
            <td class="ui-muted"><?= ui_e(date('d M Y', strtotime((string)$v['requested_at']))) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <?php
    ui_table_close('po-variations-table');
}

ui_card_close();
