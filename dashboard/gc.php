<?php
/**
 * Deputy Government Chemist dashboard.
 *
 * All queries, the self-healing approval-chain call and every destination
 * link are unchanged. The layout was split into tabs so the page no longer
 * stacks five full-width tables.
 */
$REQUIRE_PERMISSION = 'view_requests';

require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/ui.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';

/* Self-heal: seed any missing approval chains for SUBMITTED requests */
ensureApprovalChainsExist($pdo);

/* ═════════════════════════════════════════════════════════
   SECTION 1: Analytical & Advisory Branch Approvals
   DGC is sole approver for Analytical & Advisory branch (all amounts)
═════════════════════════════════════════════════════════ */

/* Requests awaiting DGC approval (Analytical & Advisory branch) */
$stmt = $pdo->prepare("
    SELECT 
        pr.request_id,
        pr.request_number,
        pr.estimated_value,
        pr.currency,
        pr.created_at,
        b.branch_name,
        ra.role as required_role,
        ra.stage_order
    FROM procurement_requests pr
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    LEFT JOIN request_approvals ra ON pr.request_id = ra.request_id AND ra.status = 'pending'
    WHERE UPPER(pr.status) = 'SUBMITTED'
    AND b.branch_id = 6  /* Analytical & Advisory Branch */
    AND ra.role = 'Deputy Government Chemist'
    AND ra.status = 'pending'
    ORDER BY pr.created_at ASC
");
$stmt->execute();
$branchRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Summary Counts */
$branchPending = count($branchRequests);

/* ═════════════════════════════════════════════════════════
   SECTION 2: All other pending DGC approvals
   (non branch-6 approvals if any come through the chain)
═════════════════════════════════════════════════════════ */

/* Pending DGC request approvals (excludes branch-6 already shown above) */
$stmt = $pdo->prepare("
    SELECT pr.request_id, pr.request_number, pr.estimated_value,
           pr.currency,
           pr.status as request_status, pr.external_approval_required,
           pr.created_at, b.branch_name, b.branch_id
    FROM request_approvals ra
    JOIN procurement_requests pr ON ra.request_id = pr.request_id AND ra.entity_type = 'REQUEST'
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    WHERE ra.entity_type = 'REQUEST'
      AND ra.role = 'Deputy Government Chemist'
      AND ra.status = 'pending'
      AND UPPER(pr.status) NOT IN ('DECLINED', 'COMPLETED', 'AWARDED')
      AND b.branch_id != 6
    ORDER BY pr.created_at ASC
");
$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Pending RFQ GC approvals (over-threshold RFQs at COMMITTEE_RECOMMENDED) */
$rfqGcStmt = $pdo->prepare("
    SELECT r.rfq_id, r.rfq_number, pr.request_id, pr.request_number,
           pr.estimated_value, pr.currency, pr.status as request_status, b.branch_name
    FROM rfqs r
    JOIN procurement_requests pr ON r.request_id = pr.request_id
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    WHERE pr.status = 'COMMITTEE_RECOMMENDED'
      AND r.status != 'AWARDED'
    ORDER BY pr.created_at ASC
");
$rfqGcStmt->execute();
$rfqGcItems = $rfqGcStmt->fetchAll(PDO::FETCH_ASSOC);

/* Pending commitment approvals for DGC — no longer needed (auto-approved) */
$pendingCommitments = [];

/* Pending PO approvals for DGC — no longer needed (auto-approved) */
$pendingPOs = [];

$totalPending = $branchPending + count($requests) + count($rfqGcItems);
?>

<div class="ui-page">

<?php
ui_page_header([
    'title'    => 'Deputy Government Chemist',
    'subtitle' => sprintf(
        'Welcome back, %s • %s • %s',
        $_SESSION['full_name'] ?? 'User',
        $_SESSION['role_name'] ?? 'Deputy Government Chemist',
        date('l, j F Y')
    ),
    'icon'        => 'bi-bank',
    'breadcrumbs' => [
        ['label' => 'Home', 'href' => '/dashboard/index.php'],
        ['label' => 'Deputy Government Chemist'],
    ],
    'actions' => [
        ['label' => 'Approval Queue',  'href' => '/dashboard/approval_queue.php', 'icon' => 'bi-clock-history', 'variant' => 'primary'],
        ['label' => 'All Requests',    'href' => '/procurement/list.php',         'icon' => 'bi-list-task'],
        ['label' => 'Commitments',     'href' => '/commitments/list.php',         'icon' => 'bi-pin-angle'],
        ['label' => 'Purchase Orders', 'href' => '/po/list.php',                  'icon' => 'bi-file-earmark-text'],
        ['label' => 'Refresh',         'icon' => 'bi-arrow-clockwise', 'onclick' => 'location.reload()', 'title' => 'Refresh page'],
    ],
]);

ui_kpi_grid([
    [
        'label'   => 'Total Pending',
        'value'   => number_format($totalPending),
        'meta'    => 'Awaiting your decision',
        'icon'    => 'bi-inbox',
        'variant' => $totalPending > 0 ? 'amber' : 'green',
    ],
    [
        'label'   => 'Analytical & Advisory',
        'value'   => number_format($branchPending),
        'meta'    => 'Sole-approver branch requests',
        'icon'    => 'bi-building-check',
        'variant' => 'blue',
    ],
    [
        'label'   => 'Request Approvals',
        'value'   => number_format(count($requests)),
        'meta'    => 'Other branches',
        'icon'    => 'bi-file-earmark-check',
        'variant' => 'green',
    ],
    [
        'label'   => 'RFQ GC Approvals',
        'value'   => number_format(count($rfqGcItems)),
        'meta'    => 'Over-threshold awards',
        'icon'    => 'bi-award',
        'variant' => 'purple',
    ],
]);

$gcTabs = [
    ['id' => 'branch',   'label' => 'Analytical & Advisory', 'icon' => 'bi-building-check',    'count' => $branchPending, 'active' => true],
    ['id' => 'requests', 'label' => 'Request Approvals',     'icon' => 'bi-file-earmark-check','count' => count($requests)],
    ['id' => 'rfqs',     'label' => 'RFQ GC Approvals',      'icon' => 'bi-award',             'count' => count($rfqGcItems)],
];
if (!empty($pendingCommitments)) {
    $gcTabs[] = ['id' => 'commitments', 'label' => 'Commitments', 'icon' => 'bi-cash-coin', 'count' => count($pendingCommitments)];
}
if (!empty($pendingPOs)) {
    $gcTabs[] = ['id' => 'pos', 'label' => 'Purchase Orders', 'icon' => 'bi-file-earmark-text', 'count' => count($pendingPOs)];
}
$gcTabs[] = ['id' => 'actions',  'label' => 'Pending Actions', 'icon' => 'bi-check2-square'];
$gcTabs[] = ['id' => 'insights', 'label' => 'Insights',        'icon' => 'bi-graph-up'];

ui_tabs('gc', $gcTabs);
?>

<?php ui_tabpanel_open('gc', 'branch', true); ?>
    <?php
    ui_card_open([
        'title'    => 'Analytical & Advisory Branch Approvals',
        'icon'     => 'bi-building-check',
        'subtitle' => 'As Deputy Government Chemist, you are the sole approver for Analytical & Advisory branch requests.',
        'tools'    => ui_badge(number_format($branchPending) . ' items', 'neutral'),
        'flush'    => true,
    ]);

    if ($branchPending === 0) {
        echo '<div class="ui-card__pad">';
        ui_empty('No Analytical & Advisory approvals pending', 'bi-check-circle');
        echo '</div>';
    } else {
        ui_table_toolbar('gc-branch-table', ['placeholder' => 'Search request…']);
        ui_table_open('gc-branch-table', ['perPage' => 15, 'label' => 'Analytical and Advisory branch approvals']);
        ?>
        <thead>
            <tr>
                <th scope="col" data-sortable>Request #</th>
                <th scope="col" class="ui-num" data-sortable>Value</th>
                <th scope="col" data-sortable>Date Submitted</th>
                <th scope="col" data-ui-noexport>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($branchRequests as $r): ?>
            <tr>
                <th scope="row"><?= ui_e($r['request_number']) ?></th>
                <td class="ui-num"><?= ui_e($r['currency'] ?? 'JMD') ?> <?= ui_e(number_format((float)$r['estimated_value'], 2)) ?></td>
                <td class="ui-muted"><?= ui_e(date('d M Y', strtotime((string)$r['created_at']))) ?></td>
                <td class="ui-nowrap">
                    <a class="ui-action ui-action--sm" href="/procurement/view.php?id=<?= (int)$r['request_id'] ?>">
                        <i class="bi bi-eye" aria-hidden="true"></i><span>View</span>
                    </a>
                    <a class="ui-action ui-action--primary ui-action--sm" href="/procurement/approve.php?id=<?= (int)$r['request_id'] ?>">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i><span>Approve</span>
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php
        ui_table_close('gc-branch-table');
    }
    ui_card_close();
    ?>
<?php ui_tabpanel_close(); ?>

<?php ui_tabpanel_open('gc', 'requests'); ?>
    <?php
    ui_card_open([
        'title' => 'Pending Request Approvals',
        'icon'  => 'bi-file-earmark-check',
        'tools' => ui_badge(number_format(count($requests)) . ' items', 'neutral'),
        'flush' => true,
    ]);

    if (empty($requests)) {
        echo '<div class="ui-card__pad">';
        ui_empty('No pending request approvals', 'bi-check-circle');
        echo '</div>';
    } else {
        ui_table_toolbar('gc-requests-table', ['placeholder' => 'Search request or branch…']);
        ui_table_open('gc-requests-table', ['perPage' => 15, 'label' => 'Pending request approvals']);
        ?>
        <thead>
            <tr>
                <th scope="col" data-sortable>Request #</th>
                <th scope="col" data-sortable>Branch</th>
                <th scope="col" class="ui-num" data-sortable>Value</th>
                <th scope="col" data-sortable>Status</th>
                <th scope="col" data-sortable>Date</th>
                <th scope="col" data-ui-noexport>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
            <tr>
                <th scope="row"><?= ui_e($r['request_number']) ?></th>
                <td><?= ui_e($r['branch_name'] ?? 'N/A') ?></td>
                <td class="ui-num"><?= ui_e($r['currency'] ?? 'JMD') ?> <?= ui_e(number_format((float)$r['estimated_value'], 2)) ?></td>
                <td><?= ui_status_badge((string)$r['request_status']) ?></td>
                <td class="ui-muted"><?= ui_e(date('d M Y', strtotime((string)$r['created_at']))) ?></td>
                <td class="ui-nowrap">
                    <a class="ui-action ui-action--primary ui-action--sm" href="/procurement/view.php?id=<?= (int)$r['request_id'] ?>">
                        <i class="bi bi-search" aria-hidden="true"></i><span>Review</span>
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php
        ui_table_close('gc-requests-table');
    }
    ui_card_close();
    ?>
<?php ui_tabpanel_close(); ?>

<?php ui_tabpanel_open('gc', 'rfqs'); ?>
    <?php
    ui_card_open([
        'title'    => 'RFQ GC Approvals (Over-Threshold)',
        'icon'     => 'bi-award',
        'subtitle' => 'Committee-recommended RFQs requiring GC approval before vendor award (SOP Step 10).',
        'tools'    => ui_badge(number_format(count($rfqGcItems)) . ' items', 'neutral'),
        'flush'    => true,
    ]);

    if (empty($rfqGcItems)) {
        echo '<div class="ui-card__pad">';
        ui_empty('No RFQs awaiting GC approval', 'bi-check-circle');
        echo '</div>';
    } else {
        ui_table_toolbar('gc-rfq-table', ['placeholder' => 'Search RFQ, request or branch…']);
        ui_table_open('gc-rfq-table', ['perPage' => 15, 'label' => 'RFQs awaiting GC approval']);
        ?>
        <thead>
            <tr>
                <th scope="col" data-sortable>RFQ #</th>
                <th scope="col" data-sortable>Request #</th>
                <th scope="col" data-sortable>Branch</th>
                <th scope="col" class="ui-num" data-sortable>Est. Value</th>
                <th scope="col" data-ui-noexport>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rfqGcItems as $rq): ?>
            <tr>
                <th scope="row"><?= ui_e($rq['rfq_number']) ?></th>
                <td><?= ui_e($rq['request_number']) ?></td>
                <td><?= ui_e($rq['branch_name'] ?? 'N/A') ?></td>
                <td class="ui-num ui-num--strong"><?= ui_e($rq['currency'] ?? 'JMD') ?> <?= ui_e(number_format((float)$rq['estimated_value'], 2)) ?></td>
                <td class="ui-nowrap">
                    <a class="ui-action ui-action--primary ui-action--sm" href="/rfq/gc_approve.php?rfq_id=<?= (int)$rq['rfq_id'] ?>">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i><span>Approve</span>
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php
        ui_table_close('gc-rfq-table');
    }
    ui_card_close();
    ?>
<?php ui_tabpanel_close(); ?>

<?php if (!empty($pendingCommitments)): ?>
<?php ui_tabpanel_open('gc', 'commitments'); ?>
    <?php
    ui_card_open([
        'title' => 'Pending Commitment Approvals',
        'icon'  => 'bi-cash-coin',
        'tools' => ui_badge(number_format(count($pendingCommitments)) . ' items', 'neutral'),
        'flush' => true,
    ]);
    ui_table_toolbar('gc-commitments-table', ['placeholder' => 'Search commitment…']);
    ui_table_open('gc-commitments-table', ['perPage' => 15, 'label' => 'Pending commitment approvals']);
    ?>
    <thead>
        <tr>
            <th scope="col" data-sortable>Commitment #</th>
            <th scope="col" data-sortable>Request #</th>
            <th scope="col" data-sortable>Branch</th>
            <th scope="col" class="ui-num" data-sortable>Total</th>
            <th scope="col" data-sortable>Date</th>
            <th scope="col" data-ui-noexport>Action</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($pendingCommitments as $c): ?>
        <tr>
            <th scope="row"><?= ui_e($c['commitment_number']) ?></th>
            <td><?= ui_e($c['request_number']) ?></td>
            <td><?= ui_e($c['branch_name'] ?? 'N/A') ?></td>
            <td class="ui-num ui-num--strong">JMD <?= ui_e(number_format((float)$c['commitment_total'], 2)) ?></td>
            <td class="ui-muted"><?= ui_e(date('d M Y', strtotime((string)$c['created_at']))) ?></td>
            <td class="ui-nowrap">
                <a class="ui-action ui-action--primary ui-action--sm" href="/commitments/view.php?id=<?= (int)$c['commitment_id'] ?>">
                    <i class="bi bi-search" aria-hidden="true"></i><span>Review</span>
                </a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <?php
    ui_table_close('gc-commitments-table');
    ui_card_close();
    ?>
<?php ui_tabpanel_close(); ?>
<?php endif; ?>

<?php if (!empty($pendingPOs)): ?>
<?php ui_tabpanel_open('gc', 'pos'); ?>
    <?php
    ui_card_open([
        'title' => 'Pending PO Approvals',
        'icon'  => 'bi-file-earmark-text',
        'tools' => ui_badge(number_format(count($pendingPOs)) . ' items', 'neutral'),
        'flush' => true,
    ]);
    ui_table_toolbar('gc-po-table', ['placeholder' => 'Search purchase order…']);
    ui_table_open('gc-po-table', ['perPage' => 15, 'label' => 'Pending purchase order approvals']);
    ?>
    <thead>
        <tr>
            <th scope="col" data-sortable>PO #</th>
            <th scope="col" data-sortable>Request #</th>
            <th scope="col" data-sortable>Branch</th>
            <th scope="col" class="ui-num" data-sortable>Total</th>
            <th scope="col" data-sortable>Date</th>
            <th scope="col" data-ui-noexport>Action</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($pendingPOs as $po): ?>
        <tr>
            <th scope="row"><?= ui_e($po['po_number']) ?></th>
            <td><?= ui_e($po['request_number']) ?></td>
            <td><?= ui_e($po['branch_name'] ?? 'N/A') ?></td>
            <td class="ui-num ui-num--strong">JMD <?= ui_e(number_format((float)$po['po_total'], 2)) ?></td>
            <td class="ui-muted"><?= ui_e(date('d M Y', strtotime((string)$po['created_at']))) ?></td>
            <td class="ui-nowrap">
                <a class="ui-action ui-action--primary ui-action--sm" href="/po/view.php?id=<?= (int)$po['po_id'] ?>">
                    <i class="bi bi-search" aria-hidden="true"></i><span>Review</span>
                </a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <?php
    ui_table_close('gc-po-table');
    ui_card_close();
    ?>
<?php ui_tabpanel_close(); ?>
<?php endif; ?>

<?php ui_tabpanel_open('gc', 'actions'); ?>
    <?php include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/pending_actions.php'; ?>
<?php ui_tabpanel_close(); ?>

<?php ui_tabpanel_open('gc', 'insights'); ?>
    <div class="ui-grid ui-grid--2">
        <div><?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/pipeline.php"; ?></div>
        <div><?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/top_vendors.php"; ?></div>
    </div>
    <div class="ui-grid ui-grid--2">
        <div><?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/overdue_invoices.php"; ?></div>
        <div><?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/recent_activity.php"; ?></div>
    </div>
<?php ui_tabpanel_close(); ?>

</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
