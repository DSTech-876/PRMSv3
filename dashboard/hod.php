<?php
$REQUIRE_PERMISSION = 'view_requests';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/ui.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';

/* Self-heal: seed any missing approval chains for SUBMITTED requests */
ensureApprovalChainsExist($pdo);

/* Check if current user is HOD or Branch Head for approvals display */
$approverRole = getCurrentApproverRole();
$showApprovalsPanel = !empty($approverRole);

/* ================================
   Advanced filter parameters
================================ */
$fReqNum  = trim($_GET['req_number'] ?? '');
$fBranch  = (int)($_GET['branch_id'] ?? 0);
$fRequestor = trim($_GET['requestor'] ?? '');
$fStatus  = trim($_GET['status'] ?? '');
$fFrom    = trim($_GET['from'] ?? '');
$fTo      = trim($_GET['to'] ?? '');
$fYear    = (int)($_GET['budget_year'] ?? 0);

/* Sorting */
$sortAllowed = ['request_number' => 'pr.request_number', 'estimated_value' => 'pr.estimated_value', 'request_date' => 'pr.created_at', 'branch_name' => 'b.branch_name', 'requestor_name' => 'u.full_name'];
$sortCol = $sortAllowed[$_GET['sort'] ?? ''] ?? 'pr.created_at';
$sortDir = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

/* ================================
   Build WHERE clause for approval queue
================================ */
$pendingWhere = [
    "ra.entity_type = 'REQUEST'",
    "ra.role = 'HOD'",
    "ra.status = 'pending'",
    "UPPER(pr.status) NOT IN ('DECLINED','COMPLETED','AWARDED')",
];
$pendingParams = [];
if ($fReqNum !== '') {
    $pendingWhere[] = "pr.request_number LIKE :fReqNum";
    $pendingParams[':fReqNum'] = '%' . $fReqNum . '%';
}
if ($fBranch > 0) {
    $pendingWhere[] = "pr.branch_id = :fBranch";
    $pendingParams[':fBranch'] = $fBranch;
}
if ($fRequestor !== '') {
    $pendingWhere[] = "u.full_name LIKE :fRequestor";
    $pendingParams[':fRequestor'] = '%' . $fRequestor . '%';
}
if ($fStatus !== '') {
    $pendingWhere[] = "pr.status = :fStatus";
    $pendingParams[':fStatus'] = $fStatus;
}
if ($fFrom !== '') {
    $pendingWhere[] = "DATE(pr.created_at) >= :fFrom";
    $pendingParams[':fFrom'] = $fFrom;
}
if ($fTo !== '') {
    $pendingWhere[] = "DATE(pr.created_at) <= :fTo";
    $pendingParams[':fTo'] = $fTo;
}
if ($fYear > 0) {
    $pendingWhere[] = "YEAR(pr.created_at) = :fYear";
    $pendingParams[':fYear'] = $fYear;
}
$pendingWhereSQL = 'WHERE ' . implode(' AND ', $pendingWhere);

/* Requests awaiting HOD approval */
$stmt = $pdo->prepare("
    SELECT 
        pr.request_id, 
        pr.request_number, 
        pr.estimated_value, 
        pr.currency,
        pr.created_at,
        pr.status as request_status,
        pr.branch_id,
        pr.workflow_path,
        b.branch_name,
        ra.role as required_role,
        ra.stage_order,
        u.full_name as requestor_name
    FROM request_approvals ra
    JOIN procurement_requests pr ON ra.request_id = pr.request_id
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    LEFT JOIN users u ON pr.created_by = u.user_id
    $pendingWhereSQL
    ORDER BY $sortCol $sortDir
");
foreach ($pendingParams as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Commitment approvals awaiting HOD — no longer needed (auto-approved) */
$pendingCommitments = [];

/* PO approvals awaiting HOD — no longer needed (auto-approved) */
$pendingPOs = [];

/* Over-threshold RFQs at GC_APPROVED awaiting award by HOD */
$rfqAwardStmt = $pdo->prepare("
    SELECT 
        r.rfq_id,
        r.rfq_number,
        pr.request_id,
        pr.request_number,
        pr.estimated_value,
        pr.currency,
        pr.status as request_status,
        b.branch_name
    FROM rfqs r
    JOIN procurement_requests pr ON r.request_id = pr.request_id
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    WHERE pr.status = 'GC_APPROVED'
      AND r.status != 'AWARDED'
    ORDER BY pr.created_at ASC
");
$rfqAwardStmt->execute();
$rfqAwards = $rfqAwardStmt->fetchAll(PDO::FETCH_ASSOC);

/* RFQs awaiting this Branch Head's final approval (requestor already confirmed) */
$pendingBranchHeadRfqApprovals = [];
if (hasPermission('approve_branch_head_award')) {
    require_once $_SERVER['DOCUMENT_ROOT'].'/services/RFQQuoteApprovalService.php';
    $rfqApprovalService = new RFQQuoteApprovalService($pdo, (int)$_SESSION['user_id'], $_SESSION['role_name'] ?? '');
    $pendingBranchHeadRfqApprovals = $rfqApprovalService->getPendingBranchHeadApprovals();
}

$totalPending = count($requests) + count($pendingCommitments) + count($pendingPOs) + count($rfqAwards) + count($pendingBranchHeadRfqApprovals);

/* ================================
   Status count cards (all requests, not filtered)
================================ */
$statusCounts = [];
$countStmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM procurement_requests GROUP BY status");
foreach ($countStmt as $row) {
    $statusCounts[$row['status']] = (int)$row['cnt'];
}
$cntPendingApprovals    = $statusCounts['SUBMITTED'] ?? 0;
$cntHodApproved         = $statusCounts['HOD_APPROVED'] ?? 0;
$cntDeclined            = $statusCounts['DECLINED'] ?? 0;
$cntAwaitingProcurement = $statusCounts['FUNDS_VERIFIED'] ?? 0;
$cntAwaitingFinance     = $statusCounts['COMMITMENTS_PENDING'] ?? 0;

/* Branches & budget years for filter dropdowns */
$branches   = $pdo->query("SELECT branch_id, branch_name FROM branches ORDER BY branch_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$budgetYears = $pdo->query("SELECT DISTINCT YEAR(request_date) AS yr FROM procurement_requests WHERE request_date IS NOT NULL ORDER BY yr DESC")->fetchAll(PDO::FETCH_COLUMN);

/* All distinct statuses for filter dropdown */
$allStatuses = [
    'SUBMITTED' => 'Submitted', 'HOD_APPROVED' => 'HOD Approved',
    'FUNDS_VERIFIED' => 'Funds Verified', 'DIRECTOR_APPROVED' => 'Director Approved',
    'GC_APPROVED' => 'GC Approved', 'RFQ_LETTER_AVAILABLE' => 'RFQ Letters',
    'QUOTE_REVIEW_PENDING' => 'Quote Review', 'QUOTE_APPROVED' => 'Quote Selected',
    'COMMITMENTS_PENDING' => 'Commitment Pending', 'COMMITMENT_APPROVED' => 'Committed',
    'COMMITMENT_DECLINED' => 'Commitment Declined', 'PO_PENDING' => 'PO Created',
    'AWARDED' => 'Awarded', 'COMPLETED' => 'Completed',
    'DECLINED' => 'Declined', 'CANCELLED' => 'Cancelled',
];

/* Helper: build sort link preserving current filters */
function hodSortLink(string $col, string $currentSort, string $currentDir, string $label): string {
    $params = $_GET;
    $params['sort'] = $col;
    $params['dir']  = ($currentSort === $col && $currentDir === 'DESC') ? 'ASC' : 'DESC';
    $arrow = '';
    if ($currentSort === $col) {
        $arrow = $currentDir === 'ASC' ? ' ▲' : ' ▼';
    }
    $qs = http_build_query($params);
    return '<a href="/dashboard/hod.php?' . htmlspecialchars($qs) . '" style="color:inherit;text-decoration:none;">'
         . htmlspecialchars($label) . '<span style="font-size:0.7rem;opacity:0.8;">' . $arrow . '</span></a>';
}
$curSort = $_GET['sort'] ?? 'request_date';
$curDir  = strtoupper($_GET['dir'] ?? 'ASC');
?>

<div class="ui-page">

<?php
$hodFiltersActive = ($fReqNum !== '' || $fBranch || $fRequestor !== '' || $fStatus !== '' || $fFrom !== '' || $fTo !== '' || $fYear);

ui_page_header([
    'title'    => 'Branch Head Dashboard',
    'subtitle' => sprintf(
        'Welcome back, %s • %s • %s',
        $_SESSION['full_name'] ?? 'User',
        $_SESSION['role_name'] ?? 'HOD',
        date('l, j F Y')
    ),
    'icon'        => 'bi-person-badge',
    'breadcrumbs' => [
        ['label' => 'Home', 'href' => '/dashboard/index.php'],
        ['label' => 'Branch Head Dashboard'],
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
    ['label' => 'Pending Approvals',    'value' => number_format($cntPendingApprovals),    'meta' => 'Submitted requests',      'icon' => 'bi-hourglass-split',   'variant' => 'amber',  'href' => '/procurement/list.php?request_status=SUBMITTED'],
    ['label' => 'Approved Requests',    'value' => number_format($cntHodApproved),         'meta' => 'HOD approved',            'icon' => 'bi-check2-circle',     'variant' => 'purple', 'href' => '/procurement/list.php?request_status=HOD_APPROVED'],
    ['label' => 'Rejected Requests',    'value' => number_format($cntDeclined),            'meta' => 'Declined',                'icon' => 'bi-x-circle',          'variant' => 'red',    'href' => '/procurement/list.php?request_status=DECLINED'],
    ['label' => 'Awaiting Procurement', 'value' => number_format($cntAwaitingProcurement), 'meta' => 'Funds verified',          'icon' => 'bi-gear',              'variant' => 'green',  'href' => '/procurement/list.php?request_status=FUNDS_VERIFIED'],
    ['label' => 'Awaiting Finance',     'value' => number_format($cntAwaitingFinance),     'meta' => 'Commitments pending',     'icon' => 'bi-cash-stack',        'variant' => 'blue',   'href' => '/procurement/list.php?request_status=COMMITMENTS_PENDING'],
    ['label' => 'Total Pending Items',  'value' => number_format($totalPending),           'meta' => 'Across all queues',       'icon' => 'bi-inbox',             'variant' => 'slate',  'href' => '/procurement/list.php'],
]);

$hodTabs = [
    ['id' => 'approvals', 'label' => 'Request Approvals', 'icon' => 'bi-file-earmark-check', 'count' => count($requests), 'active' => true],
];
if ($showApprovalsPanel) {
    $hodTabs[] = ['id' => 'special', 'label' => 'Petty Cash & Reimbursements', 'icon' => 'bi-wallet2'];
}
$hodTabs[] = ['id' => 'actions', 'label' => 'Pending Actions', 'icon' => 'bi-check2-square'];
if (!empty($pendingBranchHeadRfqApprovals)) {
    $hodTabs[] = ['id' => 'rfqapprovals', 'label' => 'RFQ Approvals', 'icon' => 'bi-shield-check', 'count' => count($pendingBranchHeadRfqApprovals)];
}
if (!empty($rfqAwards)) {
    $hodTabs[] = ['id' => 'awards', 'label' => 'RFQ Awards', 'icon' => 'bi-trophy', 'count' => count($rfqAwards)];
}
if (!empty($pendingCommitments)) {
    $hodTabs[] = ['id' => 'commitments', 'label' => 'Commitments', 'icon' => 'bi-cash-coin', 'count' => count($pendingCommitments)];
}
if (!empty($pendingPOs)) {
    $hodTabs[] = ['id' => 'pos', 'label' => 'Purchase Orders', 'icon' => 'bi-file-earmark-text', 'count' => count($pendingPOs)];
}
$hodTabs[] = ['id' => 'analytics', 'label' => 'Analytics', 'icon' => 'bi-graph-up'];
$hodTabs[] = ['id' => 'activity',  'label' => 'Activity',  'icon' => 'bi-clock-history'];

ui_tabs('hod', $hodTabs);
?>

<?php ui_tabpanel_open('hod', 'approvals', true); ?>
    <details class="ui-filter-panel" data-ui-remember="hod-filters"<?= $hodFiltersActive ? ' open' : '' ?>>
        <summary><i class="bi bi-funnel-fill" aria-hidden="true"></i> Advanced Search &amp; Filter<?= $hodFiltersActive ? ' <span class="ui-badge ui-badge--info">active</span>' : '' ?></summary>
        <form method="get" class="row g-2">
            <div class="col-md-2 col-sm-4">
                <label class="form-label small fw-bold text-muted" for="hod-f-reqnum">Request Number</label>
                <input type="text" id="hod-f-reqnum" name="req_number" value="<?= htmlspecialchars($fReqNum) ?>" placeholder="e.g. PR001" class="form-control form-control-sm">
            </div>
            <div class="col-md-2 col-sm-4">
                <label class="form-label small fw-bold text-muted" for="hod-f-branch">Department</label>
                <select id="hod-f-branch" name="branch_id" class="form-select form-select-sm">
                    <option value="">All Departments</option>
                    <?php foreach ($branches as $br): ?>
                        <option value="<?= $br['branch_id'] ?>" <?= $fBranch == $br['branch_id'] ? 'selected' : '' ?>><?= htmlspecialchars($br['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 col-sm-4">
                <label class="form-label small fw-bold text-muted" for="hod-f-requestor">Requestor</label>
                <input type="text" id="hod-f-requestor" name="requestor" value="<?= htmlspecialchars($fRequestor) ?>" placeholder="Name" class="form-control form-control-sm">
            </div>
            <div class="col-md-2 col-sm-4">
                <label class="form-label small fw-bold text-muted" for="hod-f-status">Status</label>
                <select id="hod-f-status" name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <?php foreach ($allStatuses as $val => $lbl): ?>
                        <option value="<?= $val ?>" <?= $fStatus === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 col-sm-3">
                <label class="form-label small fw-bold text-muted" for="hod-f-from">From</label>
                <input type="date" id="hod-f-from" name="from" value="<?= htmlspecialchars($fFrom) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-md-1 col-sm-3">
                <label class="form-label small fw-bold text-muted" for="hod-f-to">To</label>
                <input type="date" id="hod-f-to" name="to" value="<?= htmlspecialchars($fTo) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-md-1 col-sm-3">
                <label class="form-label small fw-bold text-muted" for="hod-f-year">Budget Year</label>
                <select id="hod-f-year" name="budget_year" class="form-select form-select-sm">
                    <option value="">All Years</option>
                    <?php foreach ($budgetYears as $yr): ?>
                        <option value="<?= $yr ?>" <?= $fYear == $yr ? 'selected' : '' ?>><?= $yr ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 col-sm-3 d-flex align-items-end gap-2">
                <button type="submit" class="ui-action ui-action--primary ui-action--sm flex-grow-1"><i class="bi bi-search" aria-hidden="true"></i><span>Filter</span></button>
                <a href="/dashboard/hod.php" class="ui-action ui-action--sm" title="Clear filters"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></a>
            </div>
        </form>
    </details>

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
        ui_table_toolbar('hod-requests-table', ['placeholder' => 'Search request, requestor or branch…']);
        /* Sorting stays server-side via hodSortLink() so it spans the whole
           result set, not just the current client-side page. */
        ui_table_open('hod-requests-table', [
            'perPage'  => 15,
            'sortable' => false,
            'label'    => 'Pending request approvals',
        ]);
        ?>
        <thead>
            <tr>
                <th scope="col"><?= hodSortLink('request_number', $curSort, $curDir, 'Request #') ?></th>
                <th scope="col"><?= hodSortLink('requestor_name', $curSort, $curDir, 'Requestor') ?></th>
                <th scope="col"><?= hodSortLink('branch_name', $curSort, $curDir, 'Branch') ?></th>
                <th scope="col" class="ui-num"><?= hodSortLink('estimated_value', $curSort, $curDir, 'Amount') ?></th>
                <th scope="col">Status</th>
                <th scope="col">Workflow</th>
                <th scope="col"><?= hodSortLink('request_date', $curSort, $curDir, 'Date') ?></th>
                <th scope="col" data-ui-noexport>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
            <tr>
                <th scope="row"><a class="ui-link" href="/procurement/view.php?id=<?= (int)$r['request_id'] ?>"><?= ui_e($r['request_number']) ?></a></th>
                <td><?= ui_e($r['requestor_name'] ?? 'N/A') ?></td>
                <td><?= ui_e($r['branch_name'] ?? 'N/A') ?></td>
                <td class="ui-num ui-num--strong"><?= ui_e(normalizeCurrency($r['currency'] ?? 'JMD')) ?> <?= ui_e(number_format((float)$r['estimated_value'], 2)) ?></td>
                <td><?= statusBadge($r['request_status']) ?></td>
                <td><?= getWorkflowBadgeHtml(['workflow_path' => $r['workflow_path'] ?? 'STANDARD']) ?></td>
                <td class="ui-muted"><?= ui_e(date('d M Y', strtotime((string)$r['created_at']))) ?></td>
                <td class="ui-nowrap">
                    <a class="ui-action ui-action--sm" href="/procurement/view.php?id=<?= (int)$r['request_id'] ?>"><i class="bi bi-search" aria-hidden="true"></i><span>Review</span></a>
                    <a class="ui-action ui-action--primary ui-action--sm" href="/procurement/approve_hod.php?id=<?= (int)$r['request_id'] ?>" title="Quick Approve"><i class="bi bi-check2-circle" aria-hidden="true"></i><span>Approve</span></a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php
        ui_table_close('hod-requests-table');
    }
    ui_card_close();
    ?>
<?php ui_tabpanel_close(); ?>

<?php if ($showApprovalsPanel): ?>
<?php ui_tabpanel_open('hod', 'special'); ?>
    <?php
    ui_card_open(['title' => 'Approvals Required', 'icon' => 'bi-wallet2']);
    echo '<p class="ui-muted">As a ' . ui_e($approverRole) . ', you have pending petty cash and reimbursement requests awaiting your decision.</p>';
    ui_card_close();

    /* Petty Cash Approvals Widget */
    require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/widgets/pending_petty_cash_approvals.php';

    /* Reimbursement Approvals Widget */
    require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/widgets/pending_reimbursement_approvals.php';
    ?>
<?php ui_tabpanel_close(); ?>
<?php endif; ?>

<?php ui_tabpanel_open('hod', 'actions'); ?>
    <?php include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/pending_actions.php'; ?>
<?php ui_tabpanel_close(); ?>

<?php if (!empty($pendingBranchHeadRfqApprovals)): ?>
<?php ui_tabpanel_open('hod', 'rfqapprovals'); ?>
    <?php
    ui_card_open([
        'title'    => 'Pending RFQ Approvals',
        'icon'     => 'bi-shield-check',
        'subtitle' => 'These RFQs have been confirmed by the requestor and are awaiting your final Branch Head approval.',
        'tools'    => '<a class="ui-action ui-action--sm" href="/rfq/approval_pending.php">View All</a>',
        'flush'    => true,
    ]);
    ui_table_toolbar('hod-rfq-approvals-table', ['placeholder' => 'Search RFQ, requestor or supplier…']);
    ui_table_open('hod-rfq-approvals-table', ['perPage' => 15, 'label' => 'RFQs awaiting Branch Head approval']);
    ?>
    <thead>
        <tr>
            <th scope="col" data-sortable>RFQ #</th>
            <th scope="col" data-sortable>Requestor</th>
            <th scope="col" data-sortable>Branch</th>
            <th scope="col" data-sortable>Supplier</th>
            <th scope="col" class="ui-num" data-sortable>Quote Amount</th>
            <th scope="col" data-sortable>Submitted</th>
            <th scope="col">Status</th>
            <th scope="col" class="ui-num" data-sortable>Days Pending</th>
            <th scope="col" data-ui-noexport>Action</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($pendingBranchHeadRfqApprovals as $bh): ?>
        <?php
          $bhSubmittedAt  = $bh['requestor_reviewed_at'] ?: null;
          $bhDaysPending  = $bhSubmittedAt ? max(0, (int)floor((time() - strtotime($bhSubmittedAt)) / 86400)) : null;
        ?>
        <tr>
            <th scope="row"><?= ui_e($bh['rfq_number']) ?></th>
            <td><?= ui_e($bh['requestor_name'] ?? 'N/A') ?></td>
            <td><?= ui_e($bh['branch_name'] ?? 'N/A') ?></td>
            <td><?= ui_e($bh['selected_vendor_name'] ?? 'Pending selection') ?></td>
            <td class="ui-num ui-num--strong"><?= !empty($bh['selected_quote_amount']) ? 'JMD ' . ui_e(number_format((float)$bh['selected_quote_amount'], 2)) : '—' ?></td>
            <td class="ui-muted"><?= $bhSubmittedAt ? ui_e(date('d M Y', strtotime($bhSubmittedAt))) : '—' ?></td>
            <td><?= ui_badge('Awaiting Branch Head Approval', 'warning') ?></td>
            <td class="ui-num <?= ($bhDaysPending !== null && $bhDaysPending > 3) ? 'ui-text-danger' : '' ?>"><?= $bhDaysPending !== null ? (int)$bhDaysPending : '—' ?></td>
            <td class="ui-nowrap">
                <a class="ui-action ui-action--primary ui-action--sm" href="/rfq/branch_head_approve.php?id=<?= (int)$bh['rfq_id'] ?>"><i class="bi bi-check2-circle" aria-hidden="true"></i><span>Review &amp; Approve</span></a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <?php
    ui_table_close('hod-rfq-approvals-table');
    ui_card_close();
    ?>
<?php ui_tabpanel_close(); ?>
<?php endif; ?>

<?php if (!empty($rfqAwards)): ?>
<?php ui_tabpanel_open('hod', 'awards'); ?>
    <?php
    ui_card_open([
        'title' => 'RFQs Ready for Award',
        'icon'  => 'bi-trophy',
        'tools' => ui_badge(number_format(count($rfqAwards)) . ' items', 'neutral'),
        'flush' => true,
    ]);
    ui_table_toolbar('hod-awards-table', ['placeholder' => 'Search RFQ, request or branch…']);
    ui_table_open('hod-awards-table', ['perPage' => 15, 'label' => 'RFQs ready for award']);
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
    <?php foreach ($rfqAwards as $rq): ?>
        <tr>
            <th scope="row"><?= ui_e($rq['rfq_number']) ?></th>
            <td><a class="ui-link" href="/procurement/view.php?id=<?= (int)$rq['request_id'] ?>"><?= ui_e($rq['request_number']) ?></a></td>
            <td><?= ui_e($rq['branch_name'] ?? 'N/A') ?></td>
            <td class="ui-num ui-num--strong"><?= ui_e(normalizeCurrency($rq['currency'] ?? 'JMD')) ?> <?= ui_e(number_format((float)$rq['estimated_value'], 2)) ?></td>
            <td class="ui-nowrap">
                <a class="ui-action ui-action--primary ui-action--sm" href="/rfq/view.php?id=<?= (int)$rq['rfq_id'] ?>"><i class="bi bi-trophy" aria-hidden="true"></i><span>Award</span></a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <?php
    ui_table_close('hod-awards-table');
    ui_card_close();
    ?>
<?php ui_tabpanel_close(); ?>
<?php endif; ?>

<?php if (!empty($pendingCommitments)): ?>
<?php ui_tabpanel_open('hod', 'commitments'); ?>
    <?php
    ui_card_open([
        'title' => 'Pending Commitment Approvals',
        'icon'  => 'bi-cash-coin',
        'tools' => ui_badge(number_format(count($pendingCommitments)) . ' items', 'neutral'),
        'flush' => true,
    ]);
    ui_table_toolbar('hod-commitments-table', ['placeholder' => 'Search commitment…']);
    ui_table_open('hod-commitments-table', ['perPage' => 15, 'label' => 'Pending commitment approvals']);
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
                <a class="ui-action ui-action--primary ui-action--sm" href="/commitments/view.php?id=<?= (int)$c['commitment_id'] ?>"><i class="bi bi-search" aria-hidden="true"></i><span>Review</span></a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <?php
    ui_table_close('hod-commitments-table');
    ui_card_close();
    ?>
<?php ui_tabpanel_close(); ?>
<?php endif; ?>

<?php if (!empty($pendingPOs)): ?>
<?php ui_tabpanel_open('hod', 'pos'); ?>
    <?php
    ui_card_open([
        'title' => 'Pending PO Approvals',
        'icon'  => 'bi-file-earmark-text',
        'tools' => ui_badge(number_format(count($pendingPOs)) . ' items', 'neutral'),
        'flush' => true,
    ]);
    ui_table_toolbar('hod-po-table', ['placeholder' => 'Search purchase order…']);
    ui_table_open('hod-po-table', ['perPage' => 15, 'label' => 'Pending purchase order approvals']);
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
                <a class="ui-action ui-action--primary ui-action--sm" href="/po/view.php?po_id=<?= (int)$po['po_id'] ?>"><i class="bi bi-search" aria-hidden="true"></i><span>Review</span></a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <?php
    ui_table_close('hod-po-table');
    ui_card_close();
    ?>
<?php ui_tabpanel_close(); ?>
<?php endif; ?>

<?php ui_tabpanel_open('hod', 'analytics'); ?>
    <div class="ui-grid ui-grid--2">
        <div><?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/branch_summary.php"; ?></div>
        <div><?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/pipeline.php"; ?></div>
    </div>
<?php ui_tabpanel_close(); ?>

<?php ui_tabpanel_open('hod', 'activity'); ?>
    <?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/recent_activity.php"; ?>
<?php ui_tabpanel_close(); ?>

</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
