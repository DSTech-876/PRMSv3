<?php
/**
 * Workflow monitoring panel — where work is currently sitting, how long it
 * has been waiting, and what is breaching SLA.
 *
 * Read-only. Respects the same procurement visibility restrictions used by
 * the other dashboard widgets.
 *
 * Requires: $pdo, includes/ui.php
 */
if (!isset($pdo)) {
    require_once __DIR__ . '/_init.php';
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ui.php';

/* Apply the shared procurement visibility rule so restricted roles never see
   requests outside their scope (identical guard to recent_activity.php). */
$wfVisibility = '';
if (function_exists('isProcurementVisibilityRestrictedRole')
    && function_exists('getProcurementVisibilitySqlCondition')
    && isProcurementVisibilityRestrictedRole()
) {
    $wfVisibility = ' AND ' . getProcurementVisibilitySqlCondition('pr');
}

$workflowStages = [];
$pendingApprovals = [];
$slaBuckets = ['fresh' => 0, 'ageing' => 0, 'overdue' => 0];

try {
    $workflowStages = $pdo->query("
        SELECT pr.status AS stage, COUNT(*) AS total
        FROM procurement_requests pr
        WHERE UPPER(pr.status) NOT IN ('COMPLETED', 'DECLINED', 'CANCELLED')
        {$wfVisibility}
        GROUP BY pr.status
        ORDER BY total DESC
        LIMIT 12
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $workflowStages = [];
}

try {
    $pendingApprovals = $pdo->query("
        SELECT ra.role AS approver_role,
               COUNT(*) AS pending_items,
               ROUND(AVG(DATEDIFF(CURDATE(), DATE(ra.created_at))), 1) AS avg_age_days,
               SUM(CASE WHEN DATEDIFF(CURDATE(), DATE(ra.created_at)) > 5 THEN 1 ELSE 0 END) AS overdue_items
        FROM request_approvals ra
        JOIN procurement_requests pr ON pr.request_id = ra.request_id
        WHERE ra.status = 'pending'
        {$wfVisibility}
        GROUP BY ra.role
        ORDER BY pending_items DESC
        LIMIT 15
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $pendingApprovals = [];
}

try {
    $buckets = $pdo->query("
        SELECT
            SUM(CASE WHEN DATEDIFF(CURDATE(), DATE(ra.created_at)) <= 2 THEN 1 ELSE 0 END) AS fresh,
            SUM(CASE WHEN DATEDIFF(CURDATE(), DATE(ra.created_at)) BETWEEN 3 AND 5 THEN 1 ELSE 0 END) AS ageing,
            SUM(CASE WHEN DATEDIFF(CURDATE(), DATE(ra.created_at)) > 5 THEN 1 ELSE 0 END) AS overdue
        FROM request_approvals ra
        JOIN procurement_requests pr ON pr.request_id = ra.request_id
        WHERE ra.status = 'pending'
        {$wfVisibility}
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    $slaBuckets = [
        'fresh'   => (int)($buckets['fresh'] ?? 0),
        'ageing'  => (int)($buckets['ageing'] ?? 0),
        'overdue' => (int)($buckets['overdue'] ?? 0),
    ];
} catch (PDOException $e) {
    // Leave the zeroed defaults in place.
}

$totalPending = array_sum($slaBuckets);
?>

<div class="ui-grid ui-grid--2">
    <?php
    ui_card_open([
        'title' => 'Requests by Workflow Stage',
        'icon'  => 'bi-diagram-3',
        'tools' => '<span class="ui-text-sm ui-text-muted">Active only</span>',
    ]);
    ui_chart_bars(array_map(static function (array $row): array {
        return [
            'label' => str_replace('_', ' ', (string)$row['stage']),
            'value' => (int)$row['total'],
            'href'  => '/procurement/list.php?status=' . urlencode((string)$row['stage']),
        ];
    }, $workflowStages));
    ui_card_close();

    ui_card_open([
        'title' => 'Approval SLA Health',
        'icon'  => 'bi-speedometer2',
        'tools' => '<span class="ui-text-sm ui-text-muted">' . ui_e(number_format($totalPending)) . ' pending</span>',
    ]);
    if ($totalPending === 0) {
        ui_empty('No approvals are currently pending.', 'bi-check2-circle');
    } else {
        ui_distribution([
            ['label' => 'Within SLA (0–2 days)', 'value' => $slaBuckets['fresh'],   'color' => '#15803d'],
            ['label' => 'Ageing (3–5 days)',     'value' => $slaBuckets['ageing'],  'color' => '#d97706'],
            ['label' => 'Overdue (>5 days)',     'value' => $slaBuckets['overdue'], 'color' => '#b91c1c'],
        ]);
    }
    ui_card_close();
    ?>
</div>

<?php
ui_card_open([
    'title' => 'Approval Workload by Role',
    'icon'  => 'bi-people-fill',
    'flush' => true,
]);
ui_table_toolbar('wf-approval-load', [
    'placeholder' => 'Search approver role…',
    'columns'     => true,
    'export'      => true,
]);
ui_table_open('wf-approval-load', ['perPage' => 10, 'label' => 'Approval workload by role']);
?>
    <thead>
        <tr>
            <th scope="col">Approver Role</th>
            <th scope="col" class="ui-num">Pending</th>
            <th scope="col" class="ui-num">Avg. Age (days)</th>
            <th scope="col" class="ui-num">Overdue</th>
            <th scope="col" data-ui-no-sort>Status</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$pendingApprovals): ?>
        <tr><td colspan="5" class="ui-empty">No pending approvals to monitor.</td></tr>
    <?php else: foreach ($pendingApprovals as $row): ?>
        <?php
        $overdue = (int)$row['overdue_items'];
        $health  = $overdue > 0 ? 'Needs intervention' : 'Healthy';
        ?>
        <tr>
            <td><?= ui_e($row['approver_role']) ?></td>
            <td class="ui-num"><?= ui_e(number_format((int)$row['pending_items'])) ?></td>
            <td class="ui-num"><?= ui_e(number_format((float)$row['avg_age_days'], 1)) ?></td>
            <td class="ui-num"><?= ui_e(number_format($overdue)) ?></td>
            <td><?= ui_badge($health, $overdue > 0 ? 'danger' : 'success', true) ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
<?php
ui_table_close('wf-approval-load');
ui_card_close();
