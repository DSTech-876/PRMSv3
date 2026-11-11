<?php
/**
 * Procurement Committee dashboard.
 *
 * Queries, permission guard and destination links are unchanged — only the
 * presentation layer was migrated to the shared design system.
 */
$REQUIRE_PERMISSION = 'view_requests';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/ui.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';

/* RFQs at COMMITTEE_RECOMMENDED awaiting Procurement Committee action,
   plus EVALUATION_STAGE for visibility of upcoming items */
$userId = $_SESSION['user_id'] ?? 0;

$stmt = $pdo->prepare("
    SELECT DISTINCT r.rfq_id, r.rfq_number, pr.request_id, pr.request_number,
           pr.estimated_value, pr.status as request_status, b.branch_name
    FROM rfqs r
    JOIN procurement_requests pr ON r.request_id = pr.request_id
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    WHERE pr.status IN ('EVALUATION_STAGE', 'COMMITTEE_RECOMMENDED')
      AND r.status <> 'AWARDED'
    ORDER BY pr.created_at ASC
");
$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Also get any pending request_approvals for Procurement Committee role */
$raStmt = $pdo->prepare("
    SELECT ra.id, ra.entity_type, ra.entity_id, ra.request_id,
           pr.request_number, pr.estimated_value, pr.status as request_status,
           b.branch_name, ra.created_at
    FROM request_approvals ra
    JOIN procurement_requests pr ON ra.request_id = pr.request_id
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    WHERE ra.role = 'Procurement Committee'
      AND ra.status = 'pending'
    ORDER BY ra.created_at ASC
");
$raStmt->execute();
$pendingApprovals = $raStmt->fetchAll(PDO::FETCH_ASSOC);

$totalPending = count($requests) + count($pendingApprovals);

$evaluationCount = 0;
$committeeValue  = 0.0;
foreach ($requests as $r) {
    if (($r['request_status'] ?? '') === 'EVALUATION_STAGE') {
        $evaluationCount++;
    }
    $committeeValue += (float)($r['estimated_value'] ?? 0);
}
?>

<div class="ui-page">

<?php
ui_page_header([
    'title'    => 'Procurement Committee',
    'subtitle' => sprintf(
        'Welcome back, %s • %s • %s',
        $_SESSION['full_name'] ?? 'User',
        $_SESSION['role_name'] ?? 'Procurement Committee',
        date('l, j F Y')
    ),
    'icon'        => 'bi-people',
    'breadcrumbs' => [
        ['label' => 'Home', 'href' => '/dashboard/index.php'],
        ['label' => 'Procurement Committee'],
    ],
    'actions' => [
        ['label' => 'RFQ / Evaluation', 'href' => '/rfq/list.php',         'icon' => 'bi-file-earmark-text', 'variant' => 'primary'],
        ['label' => 'All Requests',     'href' => '/procurement/list.php', 'icon' => 'bi-list-ul'],
        ['label' => 'Refresh',          'icon' => 'bi-arrow-clockwise', 'onclick' => 'location.reload()', 'title' => 'Refresh page'],
    ],
]);

ui_kpi_grid([
    [
        'label'   => 'Total Pending',
        'value'   => number_format($totalPending),
        'meta'    => 'Items needing committee attention',
        'icon'    => 'bi-inbox',
        'variant' => $totalPending > 0 ? 'warning' : 'success',
    ],
    [
        'label'   => 'RFQs to Evaluate',
        'value'   => number_format(count($requests)),
        'meta'    => $evaluationCount . ' at evaluation stage',
        'icon'    => 'bi-clipboard-check',
        'variant' => 'info',
    ],
    [
        'label'   => 'Approval Chain Items',
        'value'   => number_format(count($pendingApprovals)),
        'meta'    => 'Pending committee sign-off',
        'icon'    => 'bi-diagram-3',
        'variant' => count($pendingApprovals) > 0 ? 'warning' : 'neutral',
    ],
    [
        'label'   => 'Estimated Value',
        'value'   => '$' . number_format($committeeValue, 0),
        'meta'    => 'Across open RFQs',
        'icon'    => 'bi-cash-stack',
        'variant' => 'primary',
    ],
]);

ui_tabs('committee', [
    ['id' => 'rfqs',      'label' => 'RFQs to Evaluate', 'icon' => 'bi-clipboard-check', 'count' => count($requests), 'active' => true],
    ['id' => 'approvals', 'label' => 'Approval Chain',   'icon' => 'bi-diagram-3',       'count' => count($pendingApprovals)],
    ['id' => 'insights',  'label' => 'Insights',         'icon' => 'bi-graph-up'],
]);
?>

<?php ui_tabpanel_open('committee', 'rfqs', true); ?>
    <?php
    ui_card_open([
        'title' => 'RFQs Awaiting Evaluation / Recommendation',
        'icon'  => 'bi-hourglass-split',
        'tools' => ui_badge(number_format(count($requests)) . ' items', 'neutral'),
        'flush' => true,
    ]);

    if (empty($requests)) {
        echo '<div class="ui-card__pad">';
        ui_empty('No RFQs pending evaluation', 'bi-check-circle');
        echo '</div>';
    } else {
        ui_table_toolbar('committee-rfq-table', ['placeholder' => 'Search RFQ, request or branch…']);
        ui_table_open('committee-rfq-table', [
            'perPage' => 15,
            'label'   => 'RFQs awaiting committee evaluation',
        ]);
        ?>
        <thead>
            <tr>
                <th scope="col" data-sortable>RFQ #</th>
                <th scope="col" data-sortable>Request #</th>
                <th scope="col" data-sortable>Branch</th>
                <th scope="col" class="ui-num" data-sortable>Est. Value</th>
                <th scope="col" data-sortable>Status</th>
                <th scope="col" data-ui-noexport>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
            <tr>
                <th scope="row"><?= ui_e($r['rfq_number'] ?? '-') ?></th>
                <td><?= ui_e($r['request_number']) ?></td>
                <td><?= ui_e($r['branch_name'] ?? '-') ?></td>
                <td class="ui-num">$<?= ui_e(number_format((float)($r['estimated_value'] ?? 0), 2)) ?></td>
                <td><?= ui_status_badge((string)$r['request_status']) ?></td>
                <td class="ui-nowrap">
                    <a class="ui-action ui-action--primary ui-action--sm" href="/rfq/view.php?id=<?= (int)$r['rfq_id'] ?>">
                        <i class="bi <?= $r['request_status'] === 'EVALUATION_STAGE' ? 'bi-pencil-square' : 'bi-check2-circle' ?>" aria-hidden="true"></i>
                        <span><?= $r['request_status'] === 'EVALUATION_STAGE' ? 'Evaluate' : 'Recommend' ?></span>
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php
        ui_table_close('committee-rfq-table');
    }

    ui_card_close();
    ?>
<?php ui_tabpanel_close(); ?>

<?php ui_tabpanel_open('committee', 'approvals'); ?>
    <?php
    ui_card_open([
        'title' => 'Pending Approval Chain Items',
        'icon'  => 'bi-diagram-3',
        'tools' => ui_badge(number_format(count($pendingApprovals)) . ' items', 'neutral'),
        'flush' => true,
    ]);

    if (empty($pendingApprovals)) {
        echo '<div class="ui-card__pad">';
        ui_empty('No approval chain items pending', 'bi-check-circle');
        echo '</div>';
    } else {
        ui_table_toolbar('committee-approvals-table', ['placeholder' => 'Search request or branch…']);
        ui_table_open('committee-approvals-table', [
            'perPage' => 15,
            'label'   => 'Pending committee approval chain items',
        ]);
        ?>
        <thead>
            <tr>
                <th scope="col" data-sortable>Type</th>
                <th scope="col" data-sortable>Request #</th>
                <th scope="col" data-sortable>Branch</th>
                <th scope="col" class="ui-num" data-sortable>Est. Value</th>
                <th scope="col" data-sortable>Raised</th>
                <th scope="col" data-ui-noexport>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($pendingApprovals as $pa): ?>
            <tr>
                <td><?= ui_badge((string)$pa['entity_type'], 'info') ?></td>
                <th scope="row"><?= ui_e($pa['request_number']) ?></th>
                <td><?= ui_e($pa['branch_name'] ?? '-') ?></td>
                <td class="ui-num">$<?= ui_e(number_format((float)($pa['estimated_value'] ?? 0), 2)) ?></td>
                <td class="ui-muted"><?= ui_e(date('d M Y', strtotime((string)$pa['created_at']))) ?></td>
                <td class="ui-nowrap">
                    <a class="ui-action ui-action--primary ui-action--sm" href="/procurement/view.php?id=<?= (int)$pa['request_id'] ?>">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i><span>Approve</span>
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php
        ui_table_close('committee-approvals-table');
    }

    ui_card_close();
    ?>
<?php ui_tabpanel_close(); ?>

<?php ui_tabpanel_open('committee', 'insights'); ?>
    <div class="ui-grid ui-grid--2">
        <div><?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/pipeline.php"; ?></div>
        <div><?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/top_vendors.php"; ?></div>
    </div>
<?php ui_tabpanel_close(); ?>

</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
