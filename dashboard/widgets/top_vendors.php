<?php
if (!isset($pdo)) {
    require_once __DIR__.'/_init.php';
}
require_once dirname(__DIR__, 2) . '/includes/ui.php';

$topVendors = $pdo->query("
    SELECT v.vendor_name, v.status, v.performance_rating, v.total_awards,
           COUNT(rq.quote_id) AS quotes_submitted,
           SUM(CASE WHEN rq.is_selected = 1 THEN 1 ELSE 0 END) AS times_selected,
           IFNULL(SUM(CASE WHEN rq.is_selected = 1 THEN rq.quote_amount + rq.gct_amount ELSE 0 END), 0) AS total_awarded_value
    FROM vendors v
    LEFT JOIN rfq_vendors rv ON v.vendor_id = rv.vendor_id
    LEFT JOIN rfq_quotes rq ON rv.rfq_vendor_id = rq.rfq_vendor_id
    WHERE v.status = 'ACTIVE'
    GROUP BY v.vendor_id
    ORDER BY times_selected DESC, total_awarded_value DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

ui_card_open([
    'title' => 'Top Vendors',
    'icon'  => 'bi-trophy',
    'tools' => '<a class="ui-link" href="/vendors/list.php">View All <i class="bi bi-arrow-right" aria-hidden="true"></i></a>',
    'flush' => true,
]);

if (empty($topVendors)) {
    echo '<div class="ui-card__pad">';
    ui_empty('No vendor data available.', 'bi-shop');
    echo '</div>';
} else {
    ui_table_open('top-vendors-table', [
        'paginate' => false,
        'label'    => 'Top performing vendors',
    ]);
    ?>
    <thead>
        <tr>
            <th scope="col" class="ui-num">#</th>
            <th scope="col" data-sortable>Vendor Name</th>
            <th scope="col" class="ui-num" data-sortable>Awards</th>
            <th scope="col" class="ui-num" data-sortable>Awarded Value</th>
            <th scope="col" class="ui-num" data-sortable>Rating</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($topVendors as $i => $v):
        $rating = (float)$v['performance_rating'];
        if ($rating >= 4) {
            $ratingVariant = 'success';
        } elseif ($rating >= 3) {
            $ratingVariant = 'warning';
        } else {
            $ratingVariant = 'danger';
        }
    ?>
        <tr>
            <td class="ui-num ui-muted"><?= $i + 1 ?></td>
            <th scope="row"><?= ui_e($v['vendor_name']) ?></th>
            <td class="ui-num"><?= ui_badge((string)(int)$v['times_selected'], 'info') ?></td>
            <td class="ui-num ui-num--strong">$<?= ui_e(number_format((float)$v['total_awarded_value'], 0)) ?></td>
            <td class="ui-num">
                <?php if ($rating > 0): ?>
                    <?= ui_badge('★ ' . $rating, $ratingVariant) ?>
                <?php else: ?>
                    <span class="ui-muted">—</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <?php
    ui_table_close('top-vendors-table', false);
}

ui_card_close();
