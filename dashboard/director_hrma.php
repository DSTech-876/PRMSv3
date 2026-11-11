<?php
$REQUIRE_PERMISSION = 'approve_as_director_hrma';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';

/* Self-heal: seed any missing approval chains for SUBMITTED requests */
ensureApprovalChainsExist($pdo);

/* ═════════════════════════════════════════════════════════
   Requests awaiting Director HRM&A sign-off.

   This covers every stage the Director owns in the approval
   chain — not only freshly SUBMITTED requests. Over-threshold
   requests that were approved by the HOD first arrive here in
   HOD_APPROVED status, so the queue is driven by the pending
   request_approvals row rather than by request status/branch.
═════════════════════════════════════════════════════════ */

$stmt = $pdo->prepare("
    SELECT 
        pr.request_id,
        pr.request_number,
        pr.estimated_value,
        pr.currency,
        pr.status,
        pr.created_at,
        b.branch_name,
        ra.role as required_role
    FROM request_approvals ra
    JOIN procurement_requests pr ON pr.request_id = ra.request_id
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    WHERE ra.entity_type = 'REQUEST'
      AND ra.role = 'Director HRM&A'
      AND ra.status = 'pending'
      AND UPPER(pr.status) NOT IN ('DRAFT','CANCELLED','PAUSED','DECLINED','COMPLETED','AWARDED')
    ORDER BY pr.created_at ASC
");
$stmt->execute();
$branchRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$branchPending = count($branchRequests);

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/ui.php';

/* ─── Organisation-wide monitoring panel (read-only) ─── */
$monitorStmt = $pdo->prepare("
    SELECT
        pr.request_id,
        pr.request_number,
        pr.request_type,
        pr.status,
        pr.estimated_value,
        pr.currency,
        pr.created_at,
        b.branch_name,
        u.full_name AS requestor_name
    FROM procurement_requests pr
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    LEFT JOIN users   u ON pr.created_by = u.user_id
    WHERE UPPER(pr.status) NOT IN ('COMPLETED','CANCELLED','DECLINED')
    ORDER BY pr.created_at DESC
    LIMIT 50
");
$monitorStmt->execute();
$monitorRows = $monitorStmt->fetchAll(PDO::FETCH_ASSOC);

$branchValue = array_sum(array_map(static fn($r) => (float)$r['estimated_value'], $branchRequests));
?>
<div class="ui-page">
<?php
ui_page_header([
    'title'       => 'Director HRM&A Dashboard',
    'subtitle'    => 'Approve procurement requests for the HRM&A branch and monitor organisation-wide activity',
    'icon'        => 'bi-building-gear',
    'breadcrumbs' => [
        ['label' => 'Home', 'href' => '/dashboard/index.php'],
        ['label' => 'Director HRM&A'],
    ],
    'actions' => [
        ['label' => 'All Requests', 'href' => '/procurement/list.php', 'icon' => 'bi-list-ul'],
        ['label' => 'Approval Queue', 'href' => '/dashboard/approval_queue.php', 'icon' => 'bi-clock-history'],
    ],
]);

ui_kpi_grid([
    [
        'label'   => 'Approvals Awaiting You',
        'value'   => number_format($branchPending),
        'meta'    => 'Requests pending your approval',
        'icon'    => 'bi-inbox',
        'variant' => $branchPending > 0 ? 'amber' : 'green',
    ],
    [
        'label'   => 'Value Awaiting Approval',
        'value'   => 'JMD ' . number_format($branchValue, 0),
        'icon'    => 'bi-cash-stack',
        'variant' => 'blue',
    ],
    [
        'label'   => 'Active Requests (org-wide)',
        'value'   => number_format(count($monitorRows)),
        'meta'    => 'Read-only monitoring',
        'icon'    => 'bi-eye',
        'variant' => 'purple',
    ],
]);

ui_tabs('hrma', [
    ['id' => 'approvals', 'label' => 'My Approvals',    'icon' => 'bi-check2-square', 'count' => $branchPending],
    ['id' => 'actions',   'label' => 'Pending Actions', 'icon' => 'bi-hourglass-split'],
    ['id' => 'monitor',   'label' => 'Org Monitoring',  'icon' => 'bi-eye', 'count' => count($monitorRows)],
    ['id' => 'insights',  'label' => 'Insights',        'icon' => 'bi-graph-up'],
]);

ui_tabpanel_open('hrma', 'approvals', true);
ui_card_open([
    'title'    => 'Approvals Requiring Your Action',
    'subtitle' => 'Includes over-threshold requests already approved by the HOD.',
    'icon'     => 'bi-check2-square',
    'flush'    => true,
]);
if ($branchPending === 0) {
    echo '<div class="ui-card__pad">';
    ui_empty('No requests are waiting on your approval.', 'bi-check2-circle');
    echo '</div>';
} else {
    ui_table_toolbar('hrma-approvals-table', [
        'columns'     => true,
        'export'      => true,
        'placeholder' => 'Search requests…',
    ]);
    ui_table_open('hrma-approvals-table', [
        'paginate' => true,
        'perPage'  => 10,
        'label'    => 'Approvals requiring your action',
    ]);
    ?>
    <thead>
      <tr>
        <th scope="col">Request #</th>
        <th scope="col">Branch</th>
        <th scope="col">Stage</th>
        <th scope="col" class="ui-num">Value</th>
        <th scope="col">Date Submitted</th>
        <th scope="col">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($branchRequests as $row): ?>
      <tr>
        <th scope="row"><?= htmlspecialchars($row['request_number']) ?></th>
        <td><?= htmlspecialchars($row['branch_name'] ?? 'N/A') ?></td>
        <td><?= statusBadge($row['status']) ?></td>
        <td class="ui-num ui-num--strong"><?= htmlspecialchars(normalizeCurrency($row['currency'] ?? 'JMD')) ?> <?= number_format((float)$row['estimated_value'], 2) ?></td>
        <td class="ui-nowrap ui-muted-cell"><?= date('d M Y', strtotime((string)$row['created_at'])) ?></td>
        <td class="ui-nowrap">
          <a class="ui-action ui-action--sm" href="/procurement/view.php?id=<?= (int)$row['request_id'] ?>"><i class="bi bi-eye" aria-hidden="true"></i><span>Review</span></a>
          <a class="ui-action ui-action--sm ui-action--primary" href="/procurement/approve.php?id=<?= (int)$row['request_id'] ?>"><i class="bi bi-check2" aria-hidden="true"></i><span>Approve</span></a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php
    ui_table_close('hrma-approvals-table');
}
ui_card_close();
ui_tabpanel_close();

ui_tabpanel_open('hrma', 'actions');
include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/pending_actions.php';
ui_tabpanel_close();

ui_tabpanel_open('hrma', 'monitor');
ui_card_open([
    'title'    => 'Organisation-wide Request Monitoring',
    'subtitle' => 'Read-only — monitoring access only.',
    'icon'     => 'bi-eye',
    'flush'    => true,
]);
if (empty($monitorRows)) {
    echo '<div class="ui-card__pad">';
    ui_empty('No active requests to monitor.', 'bi-inbox');
    echo '</div>';
} else {
    ui_table_toolbar('hrma-monitor-table', [
        'columns'     => true,
        'export'      => true,
        'placeholder' => 'Search requests…',
    ]);
    ui_table_open('hrma-monitor-table', [
        'paginate' => true,
        'perPage'  => 15,
        'virtual'  => true,
        'label'    => 'Organisation-wide request monitoring',
    ]);
    ?>
    <thead>
      <tr>
        <th scope="col">Ref #</th>
        <th scope="col">Category</th>
        <th scope="col">Requestor</th>
        <th scope="col">Unit</th>
        <th scope="col">Stage</th>
        <th scope="col" class="ui-num">Value</th>
        <th scope="col">Date</th>
        <th scope="col">View</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($monitorRows as $mr): ?>
      <tr>
        <th scope="row"><?= htmlspecialchars($mr['request_number']) ?></th>
        <td><?= htmlspecialchars($mr['request_type']) ?></td>
        <td><?= htmlspecialchars($mr['requestor_name'] ?? 'N/A') ?></td>
        <td><?= htmlspecialchars($mr['branch_name'] ?? 'N/A') ?></td>
        <td><?= statusBadge($mr['status']) ?></td>
        <td class="ui-num"><?= htmlspecialchars(normalizeCurrency($mr['currency'] ?? 'JMD')) ?> <?= number_format((float)$mr['estimated_value'], 2) ?></td>
        <td class="ui-nowrap ui-muted-cell"><?= date('d M Y', strtotime((string)$mr['created_at'])) ?></td>
        <td><a class="ui-action ui-action--sm" href="/procurement/view.php?id=<?= (int)$mr['request_id'] ?>"><i class="bi bi-eye" aria-hidden="true"></i><span>View</span></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php
    ui_table_close('hrma-monitor-table');
}
ui_card_close();
ui_tabpanel_close();

ui_tabpanel_open('hrma', 'insights');
echo '<div class="ui-grid ui-grid--2">';
include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/kpis.php';
include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/alerts.php';
echo '</div>';
ui_tabpanel_close();
?>
</div>
<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
