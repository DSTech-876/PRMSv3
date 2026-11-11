<?php
if (!isset($pdo)) {
    require_once __DIR__.'/_init.php';
}
require_once dirname(__DIR__, 2) . '/includes/ui.php';

$branches = $pdo->query("
    SELECT b.branch_name,
           COUNT(pr.request_id) AS total_requests,
           SUM(pr.estimated_value) AS total_estimated,
           SUM(CASE WHEN UPPER(pr.status) IN ('GC_APPROVED','AWARDED','COMPLETED') THEN 1 ELSE 0 END) AS approved,
           SUM(CASE WHEN UPPER(pr.status) = 'SUBMITTED' THEN 1 ELSE 0 END) AS pending
    FROM branches b
    LEFT JOIN procurement_requests pr ON b.branch_id = pr.branch_id
    WHERE b.is_active = 1
    GROUP BY b.branch_id
    ORDER BY total_estimated DESC
")->fetchAll(PDO::FETCH_ASSOC);

$grandTotal = array_sum(array_column($branches, 'total_estimated'));

ui_card_open([
    'title' => 'Branch Summary',
    'icon'  => 'bi-building',
    'tools' => '<a class="ui-link" href="/reports/branch_summary.php">Full Report <i class="bi bi-arrow-right" aria-hidden="true"></i></a>',
    'flush' => true,
]);

if (empty($branches)) {
    echo '<div class="ui-card__pad">';
    ui_empty('No branch data available.', 'bi-building');
    echo '</div>';
} else {
    ui_table_toolbar('branch-summary-table', ['placeholder' => 'Search branches…']);
    ui_table_open('branch-summary-table', [
        'perPage' => 12,
        'label'   => 'Procurement activity by branch',
    ]);
    ?>
    <thead>
        <tr>
            <th scope="col" data-sortable>Branch</th>
            <th scope="col" class="ui-num" data-sortable>Requests</th>
            <th scope="col" class="ui-num" data-sortable>Approved</th>
            <th scope="col" class="ui-num" data-sortable>Pending</th>
            <th scope="col" class="ui-num" data-sortable>Est. Value</th>
            <th scope="col">Share</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($branches as $b):
        $pct = $grandTotal > 0 ? round(($b['total_estimated'] / $grandTotal) * 100) : 0;
    ?>
        <tr>
            <th scope="row"><?= ui_e($b['branch_name']) ?></th>
            <td class="ui-num"><?= (int)$b['total_requests'] ?></td>
            <td class="ui-num"><?= ui_badge((string)(int)$b['approved'], 'success') ?></td>
            <td class="ui-num"><?= ui_badge((string)(int)$b['pending'], 'warning') ?></td>
            <td class="ui-num ui-num--strong">$<?= ui_e(number_format((float)$b['total_estimated'], 0)) ?></td>
            <td>
                <div class="ui-share">
                    <span class="ui-progress" role="img" aria-label="<?= ui_e($pct . '% of total estimated value') ?>">
                        <span class="ui-progress__seg ui-progress__seg--info" style="width: <?= $pct ?>%"></span>
                    </span>
                    <span class="ui-share__pct"><?= (int)$pct ?>%</span>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <?php
    ui_table_close('branch-summary-table');
}

ui_card_close();
