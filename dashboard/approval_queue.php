<?php
$REQUIRE_PERMISSION = 'view_approval_que';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';

$userRole = $_SESSION['role'] ?? '';
$userId = $_SESSION['user_id'] ?? 0;

/* Self-heal: seed any missing approval chains for SUBMITTED requests */
ensureApprovalChainsExist($pdo);

/* ═══════════════════════════════════════════════════════════════
   1. Pending Request Approvals (from request_approvals table)
═══════════════════════════════════════════════════════════════ */
$requestStmt = $pdo->prepare("
    SELECT 
        pr.request_id,
        pr.request_number,
        pr.request_type,
        pr.estimated_value,
        pr.currency,
        pr.status as request_status,
        pr.created_at,
        ra.role as required_role,
        ra.stage_order,
        b.branch_name,
        u.full_name as requestor_name
    FROM request_approvals ra
    JOIN procurement_requests pr ON ra.request_id = pr.request_id
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    LEFT JOIN users u ON pr.created_by = u.user_id
    WHERE ra.entity_type = 'REQUEST'
      AND ra.role = ?
      AND ra.status = 'pending'
    ORDER BY ra.created_at ASC
");
$requestStmt->execute([$userRole]);
$pendingRequests = $requestStmt->fetchAll(PDO::FETCH_ASSOC);

/* ═══════════════════════════════════════════════════════════════
   2. Commitments & POs — no longer need approval (auto-approved)
═══════════════════════════════════════════════════════════════ */
$pendingCommitments = [];
$pendingPOs = [];

/* ═══════════════════════════════════════════════════════════════
   3. Workflow Actions Required (filtered by role using stageOwner)
═══════════════════════════════════════════════════════════════ */
$allWorkflowStatuses = [
    'PROCUREMENT_STAGE', 'EVALUATION_STAGE',
    'RFQ_LETTER_AVAILABLE', 'QUOTE_REVIEW_PENDING', 'QUOTE_APPROVED',
    'COMMITTEE_RECOMMENDED', 'GC_APPROVED',
    'COMMITMENT_APPROVED', 'COMMITMENT_DECLINED'
];
$myStatuses = [];
foreach ($allWorkflowStatuses as $st) {
    if (in_array($userRole, stageOwner($st))) {
        $myStatuses[] = $st;
    }
}
$workflowActions = [];
if (!empty($myStatuses)) {
    $placeholders = implode(',', array_fill(0, count($myStatuses), '?'));
    // Branch filtering: Director HRM&A sees only branch 5, Deputy GC sees only branch 6
    $branchFilter = '';
    $branchParams = [];
    if ($userRole === 'Director HRM&A') {
        $branchFilter = 'AND pr.branch_id = ?';
        $branchParams = [5];
    } elseif ($userRole === 'Deputy Government Chemist') {
        $branchFilter = 'AND pr.branch_id = ?';
        $branchParams = [6];
    }
    $workflowStmt = $pdo->prepare("
        SELECT 
            pr.request_id,
            pr.request_number,
            pr.request_type,
            pr.estimated_value,
            pr.currency,
            pr.status as request_status,
            pr.created_at,
            b.branch_name,
            u.full_name as requestor_name
        FROM procurement_requests pr
        LEFT JOIN branches b ON pr.branch_id = b.branch_id
        LEFT JOIN users u ON pr.created_by = u.user_id
        WHERE UPPER(pr.status) IN ($placeholders)
        $branchFilter
        ORDER BY pr.created_at ASC
    ");
    $workflowStmt->execute(array_merge($myStatuses, $branchParams));
    $workflowActions = $workflowStmt->fetchAll(PDO::FETCH_ASSOC);
}

$statusActionMap = [
    'PROCUREMENT_STAGE'    => ['label' => 'Create RFQ', 'color' => '#6c757d', 'icon' => 'bi-cart-plus'],
    'EVALUATION_STAGE'     => ['label' => 'Evaluate RFQ', 'color' => '#fd7e14', 'icon' => 'bi-clipboard-check'],
    'RFQ_LETTER_AVAILABLE' => ['label' => 'Move to Quote Review', 'color' => '#4facfe', 'icon' => 'bi-envelope-open'],
    'QUOTE_REVIEW_PENDING' => ['label' => 'Review Quotes', 'color' => '#fa709a', 'icon' => 'bi-search'],
    'QUOTE_APPROVED'       => ['label' => 'Create Commitment', 'color' => '#43e97b', 'icon' => 'bi-plus-circle'],
    'COMMITTEE_RECOMMENDED'=> ['label' => 'GC Approval Required', 'color' => '#f093fb', 'icon' => 'bi-shield-check'],
    'GC_APPROVED'          => ['label' => 'Ready for Award', 'color' => '#20c997', 'icon' => 'bi-trophy'],
    'COMMITMENT_APPROVED'  => ['label' => 'Create Purchase Order', 'color' => '#667eea', 'icon' => 'bi-file-earmark-plus'],
    'COMMITMENT_DECLINED'  => ['label' => 'Revise & Resubmit', 'color' => '#f5576c', 'icon' => 'bi-arrow-repeat'],
];

/* Counts */
$cntRequests = count($pendingRequests);
$cntWorkflow = count($workflowActions);
$totalAll = $cntRequests + $cntWorkflow;

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/ui.php';
?>
<div class="ui-page">
<?php
ui_page_header([
    'title'       => 'Approval Queue',
    'subtitle'    => 'All pending approvals and workflow actions assigned to your role',
    'icon'        => 'bi-clock-history',
    'breadcrumbs' => [
        ['label' => 'Home', 'href' => '/dashboard/index.php'],
        ['label' => 'Approval Queue'],
    ],
    'actions' => [
        ['label' => 'Dashboard', 'href' => '/dashboard/index.php', 'icon' => 'bi-speedometer2'],
    ],
]);

ui_kpi_grid([
    [
        'label'   => 'Total Pending',
        'value'   => number_format($totalAll),
        'icon'    => 'bi-inboxes',
        'variant' => $totalAll > 0 ? 'amber' : 'green',
    ],
    [
        'label'   => 'Requests',
        'value'   => number_format($cntRequests),
        'meta'    => 'Awaiting your approval',
        'icon'    => 'bi-file-earmark-text',
        'variant' => 'blue',
    ],
    [
        'label'   => 'Workflow Actions',
        'value'   => number_format($cntWorkflow),
        'meta'    => 'Need attention',
        'icon'    => 'bi-arrow-repeat',
        'variant' => 'purple',
    ],
]);

ui_tabs('queue', [
    ['id' => 'requests', 'label' => 'Request Approvals', 'icon' => 'bi-file-earmark-text', 'count' => $cntRequests],
    ['id' => 'workflow', 'label' => 'Workflow Actions',  'icon' => 'bi-arrow-repeat',      'count' => $cntWorkflow],
]);

ui_tabpanel_open('queue', 'requests', true);
ui_card_open(['title' => 'Pending Request Approvals', 'icon' => 'bi-file-earmark-text', 'flush' => true]);
if (empty($pendingRequests)) {
    echo '<div class="ui-card__pad">';
    ui_empty('No pending request approvals.', 'bi-check2-circle');
    echo '</div>';
} else {
    ui_table_toolbar('queue-requests-table', [
        'columns'     => true,
        'export'      => true,
        'placeholder' => 'Search requests…',
    ]);
    ui_table_open('queue-requests-table', [
        'paginate' => true,
        'perPage'  => 15,
        'label'    => 'Pending request approvals',
    ]);
    ?>
    <thead>
      <tr>
        <th scope="col">Request #</th>
        <th scope="col">Type</th>
        <th scope="col">Branch</th>
        <th scope="col">Requestor</th>
        <th scope="col" class="ui-num">Amount</th>
        <th scope="col">Awaiting</th>
        <th scope="col">Status</th>
        <th scope="col">Submitted</th>
        <th scope="col">Action</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($pendingRequests as $r): $canAct = ($r['required_role'] === $userRole); ?>
      <tr<?= $canAct ? ' class="ui-row--flagged"' : '' ?>>
        <th scope="row"><?= htmlspecialchars($r['request_number']) ?></th>
        <td><?= ui_badge((string)$r['request_type'], 'neutral') ?></td>
        <td><?= htmlspecialchars($r['branch_name'] ?? 'N/A') ?></td>
        <td><?= htmlspecialchars($r['requestor_name'] ?? 'N/A') ?></td>
        <td class="ui-num ui-num--strong"><?= htmlspecialchars($r['currency'] ?? 'JMD') ?> <?= number_format((float)$r['estimated_value'], 2) ?></td>
        <td>
          <div class="ui-badges">
            <?= ui_badge((string)$r['required_role'], $canAct ? 'warning' : 'info') ?>
            <?php if ($canAct): ?><?= ui_badge('You', 'success') ?><?php endif; ?>
          </div>
        </td>
        <td><?= ui_status_badge($r['request_status']) ?></td>
        <td class="ui-nowrap ui-muted-cell"><?= date('d M Y', strtotime((string)$r['created_at'])) ?></td>
        <td>
          <a class="ui-action ui-action--sm<?= $canAct ? ' ui-action--primary' : '' ?>" href="/procurement/view.php?id=<?= (int)$r['request_id'] ?>">
            <i class="bi bi-eye" aria-hidden="true"></i><span><?= $canAct ? 'Review' : 'View' ?></span>
          </a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php
    ui_table_close('queue-requests-table');
}
ui_card_close();
ui_tabpanel_close();

ui_tabpanel_open('queue', 'workflow');
ui_card_open(['title' => 'Workflow Actions Required', 'icon' => 'bi-arrow-repeat', 'flush' => true]);
if (empty($workflowActions)) {
    echo '<div class="ui-card__pad">';
    ui_empty('No pending workflow actions.', 'bi-check2-circle');
    echo '</div>';
} else {
    ui_table_toolbar('queue-workflow-table', [
        'columns'     => true,
        'export'      => true,
        'placeholder' => 'Search workflow actions…',
    ]);
    ui_table_open('queue-workflow-table', [
        'paginate' => true,
        'perPage'  => 15,
        'label'    => 'Workflow actions required',
    ]);
    ?>
    <thead>
      <tr>
        <th scope="col">Request #</th>
        <th scope="col">Type</th>
        <th scope="col">Branch</th>
        <th scope="col">Requestor</th>
        <th scope="col" class="ui-num">Amount</th>
        <th scope="col">Current Status</th>
        <th scope="col">Date</th>
        <th scope="col">Next Action</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($workflowActions as $w):
        $st     = strtoupper((string)$w['request_status']);
        $action = $statusActionMap[$st] ?? ['label' => $st, 'color' => '#999', 'icon' => 'bi-question-circle'];
    ?>
      <tr>
        <th scope="row"><?= htmlspecialchars($w['request_number']) ?></th>
        <td><?= ui_badge((string)$w['request_type'], 'neutral') ?></td>
        <td><?= htmlspecialchars($w['branch_name'] ?? 'N/A') ?></td>
        <td><?= htmlspecialchars($w['requestor_name'] ?? 'N/A') ?></td>
        <td class="ui-num ui-num--strong"><?= htmlspecialchars($w['currency'] ?? 'JMD') ?> <?= number_format((float)$w['estimated_value'], 2) ?></td>
        <td><?= ui_status_badge($st) ?></td>
        <td class="ui-nowrap ui-muted-cell"><?= date('d M Y', strtotime((string)$w['created_at'])) ?></td>
        <td>
          <a class="ui-action ui-action--sm ui-action--primary ui-nowrap" href="/procurement/view.php?id=<?= (int)$w['request_id'] ?>">
            <i class="bi <?= htmlspecialchars($action['icon']) ?>" aria-hidden="true"></i><span><?= htmlspecialchars($action['label']) ?></span>
          </a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php
    ui_table_close('queue-workflow-table');
}
ui_card_close();
ui_tabpanel_close();
?>
</div>
<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
