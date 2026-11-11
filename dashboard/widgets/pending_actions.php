<?php
/**
 * "Requests Requiring Action" Widget
 * ====================================
 * Shows only requests at a workflow stage requiring THIS user's action.
 * - CANCELLED / COMPLETED / DECLINED / PAUSED requests are excluded.
 * - Monitoring roles (Director HRM&A) are not shown action items here.
 * - Draft requests are never shown in the action queue.
 * - Adds Age (days), SLA due-date, Category, and Submitting Unit columns.
 * - Shows an explicit empty-state when nothing is pending.
 */

$userRole = $_SESSION['role_name'] ?? '';
$userId   = (int)($_SESSION['user_id'] ?? 0);
$isProcurementVisibilityRestrictedRole = function_exists('isProcurementVisibilityRestrictedRole')
    && isProcurementVisibilityRestrictedRole($userRole);

if (!isset($pdo)) {
    require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
}
$userBranchId = 0;
try {
    $userBranchStmt = $pdo->prepare("SELECT branch_id FROM users WHERE user_id = ?");
    $userBranchStmt->execute([$userId]);
    $userBranchId = (int)($userBranchStmt->fetchColumn() ?: 0);
} catch (Throwable $_e) { /* leave as 0 */ }
if (!function_exists('stageOwner')) {
    require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
}
require_once $_SERVER['DOCUMENT_ROOT'].'/services/WorkflowConfigurationService.php';

// Allowed sort columns
$dashboardSortFields = [
    'ref'          => 'pr.request_number',
    'description'  => 'pr.description',
    'requested_by' => 'u.full_name',
    'amount'       => 'pr.estimated_value',
    'status'       => 'pr.status',
    'date'         => 'pr.created_at',
];
$dashboardSort    = $_GET['dashboard_sort'] ?? 'date';
$dashboardDir     = strtoupper($_GET['dashboard_dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
$dashboardOrderBy = $dashboardSortFields[$dashboardSort] ?? $dashboardSortFields['date'];

if (!function_exists('dashboardSortLink')) {
    function dashboardSortLink(string $field, string $label): string
    {
        $params = $_GET;
        $currentSort = $params['dashboard_sort'] ?? 'date';
        $currentDir  = strtoupper($params['dashboard_dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
        $params['dashboard_sort'] = $field;
        $params['dashboard_dir']  = ($currentSort === $field && $currentDir === 'ASC') ? 'DESC' : 'ASC';
        $arrow = $currentSort === $field ? ($currentDir === 'ASC' ? ' ▲' : ' ▼') : '';
        return '<a href="?' . htmlspecialchars(http_build_query($params)) . '" style="color:inherit;text-decoration:none;">'
            . htmlspecialchars($label) . '<span style="font-size:.7rem;">' . $arrow . '</span></a>';
    }
}
if (!function_exists('dashboardShortText')) {
    function dashboardShortText(?string $text, int $limit = 80): string
    {
        $text = trim((string)$text);
        return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
    }
}

/* ═══════════════════════════════════════════════════════════════
   1. Approval-chain actions (request_approvals table)
      Exclude: DRAFT, CANCELLED, PAUSED, COMPLETED, DECLINED
═══════════════════════════════════════════════════════════════ */
$requestApprovalsStmt = $pdo->prepare("
    SELECT
        pr.request_id,
        pr.request_number,
        pr.request_type,
        pr.description,
        pr.estimated_value,
        pr.currency,
        pr.status        AS request_status,
        pr.created_at,
        b.branch_name,
        u.full_name      AS requestor_name
    FROM request_approvals ra
    JOIN procurement_requests pr ON ra.request_id = pr.request_id
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    LEFT JOIN users u    ON pr.created_by = u.user_id
    WHERE ra.entity_type = 'REQUEST'
      AND ra.role        = ?
      AND ra.status      = 'pending'
      AND UPPER(pr.status) NOT IN ('DRAFT','DECLINED','COMPLETED','AWARDED','PAUSED','CANCELLED')
      " . ($isProcurementVisibilityRestrictedRole && function_exists('getProcurementVisibilitySqlCondition')
            ? " AND " . getProcurementVisibilitySqlCondition('pr')
            : "") . "
    ORDER BY {$dashboardOrderBy} {$dashboardDir}
");
$requestApprovalsStmt->execute([$userRole]);
$pendingApprovals = $requestApprovalsStmt->fetchAll(PDO::FETCH_ASSOC);

/* ═══════════════════════════════════════════════════════════════
   2. Workflow-stage actions (stageOwner mapping)
      Only statuses where THIS role is the owner; exclude terminal states.
      Monitoring roles are intentionally excluded (no active queue items).
═══════════════════════════════════════════════════════════════ */
$workflowActions = [];

if (!function_exists('isMonitoringRole') ||
    (function_exists('isMonitoringRole') && !isMonitoringRole($userRole))
) {
    $allWorkflowStatuses = [
        'PROCUREMENT_STAGE', 'EVALUATION_STAGE',
        'RFQ_LETTER_AVAILABLE', 'QUOTE_REVIEW_PENDING',
        'ADDITIONAL_QUOTATIONS_REQUIRED',
        'QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_APPROVED',
        'QUOTE_BRANCH_HEAD_APPROVAL_PENDING',
        'QUOTE_APPROVED',
        'COMMITTEE_RECOMMENDED', 'GC_APPROVED',
        'FUNDS_VERIFIED', 'COMMITMENTS_PENDING', 'COMMITMENT_APPROVED', 'COMMITMENT_DECLINED',
        'PO_PENDING', 'INVOICE_RECEIVED',
    ];
    if ($isProcurementVisibilityRestrictedRole && function_exists('procurementVisibleStatuses')) {
        $allWorkflowStatuses = array_values(array_intersect($allWorkflowStatuses, procurementVisibleStatuses()));
    }
    $myStatuses = [];
    foreach ($allWorkflowStatuses as $st) {
        if (in_array($userRole, stageOwner($st), true)) {
            $myStatuses[] = $st;
        }
    }

    if (!empty($myStatuses)) {
        $canOverrideRequestor = function_exists('hasPermission') && (hasPermission('override_requestor_review') || hasPermission('admin_override_approvals'));
        $canOverrideBranchHead = function_exists('hasPermission') && (hasPermission('override_branch_head_approval') || hasPermission('admin_override_approvals'));
        $statusPlaceholders = [];
        $params = [
            ':current_user_id' => $userId,
            ':can_override_requestor' => $canOverrideRequestor ? 1 : 0,
            ':can_override_branch_head' => $canOverrideBranchHead ? 1 : 0,
            ':user_is_hrma_director' => $userRole === 'Director HRM&A' ? 1 : 0,
            ':user_is_branch_head' => in_array($userRole, ['HOD', 'Branch Head'], true) ? 1 : 0,
            ':user_branch_id' => $userBranchId,
        ];
        foreach ($myStatuses as $index => $status) {
            $placeholder = ':status_' . $index;
            $statusPlaceholders[] = $placeholder;
            $params[$placeholder] = $status;
        }

        // Deputy Government Chemist: restrict to their branch
        $branchFilter = '';
        if ($userRole === 'Deputy Government Chemist') {
            $branchFilter = 'AND pr.branch_id = :dashboard_branch_id';
            $params[':dashboard_branch_id'] = 6;
        }

        $workflowStmt = $pdo->prepare("
            SELECT
                pr.request_id,
                r.rfq_id AS rfq_id,
                pr.request_number,
                pr.request_type,
                pr.description,
                pr.estimated_value,
                pr.currency,
                pr.status  AS request_status,
                pr.created_at,
                pr.created_by,
                pr.branch_id,
                b.branch_name,
                u.full_name AS requestor_name
            FROM procurement_requests pr
            LEFT JOIN rfqs r     ON r.request_id = pr.request_id
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            LEFT JOIN users u    ON pr.created_by = u.user_id
            WHERE UPPER(pr.status) IN (" . implode(',', $statusPlaceholders) . ")
              AND UPPER(pr.status) NOT IN ('CANCELLED','PAUSED')
              {$branchFilter}
              AND (
                    UPPER(pr.status) NOT IN ('QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_APPROVED', 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING')
                    OR (
                        UPPER(pr.status) = 'QUOTE_REQUESTOR_REVIEW_PENDING'
                        AND (
                            :can_override_requestor = 1
                            OR pr.created_by = :current_user_id
                        )
                    )
                    OR (
                        UPPER(pr.status) IN ('QUOTE_REQUESTOR_REVIEW_APPROVED', 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING')
                        AND (
                            :can_override_branch_head = 1
                            OR (
                                :user_is_hrma_director = 1
                                AND (pr.branch_id = 5 OR UPPER(COALESCE(b.branch_name, '')) LIKE '%HRM%')
                            )
                            OR (
                                :user_is_branch_head = 1
                                AND :user_branch_id > 0
                                AND pr.branch_id = :user_branch_id
                            )
                        )
                    )
              )
            ORDER BY {$dashboardOrderBy} {$dashboardDir}
        ");
        $workflowStmt->execute($params);
        $workflowActions = $workflowStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$totalPendingActions = count($pendingApprovals) + count($workflowActions);

// Status → action label / colour / icon / target URL
$statusActionMap = [
    'PROCUREMENT_STAGE'                  => ['label' => 'Create RFQ',              'color' => '#6c757d', 'icon' => 'bi-cart-plus',          'href_tpl' => '/rfq/create.php?request_id={id}'],
    'EVALUATION_STAGE'                   => ['label' => 'Evaluate RFQ',            'color' => '#fd7e14', 'icon' => 'bi-clipboard-check',     'href_tpl' => '/rfq/list.php?request_id={id}'],
    'RFQ_LETTER_AVAILABLE'               => ['label' => 'Generate RFQ Letters',    'color' => '#4facfe', 'icon' => 'bi-envelope-open',       'href_tpl' => '/rfq/view.php?request_id={id}'],
    'QUOTE_REVIEW_PENDING'               => ['label' => 'Review Quotes',           'color' => '#fa709a', 'icon' => 'bi-search',              'href_tpl' => '/rfq/view.php?id={id}'],
    'ADDITIONAL_QUOTATIONS_REQUIRED'     => ['label' => 'Collect Additional Quotations', 'color' => '#f39c12', 'icon' => 'bi-arrow-repeat', 'href_tpl' => '/rfq/view.php?id={id}'],
    'QUOTE_REQUESTOR_REVIEW_PENDING'    => ['label' => 'Pending Requestor Review','color' => '#e67e22', 'icon' => 'bi-file-earmark-check',  'href_tpl' => '/rfq/requestor_spec_review.php?id={id}'],
    'QUOTE_REQUESTOR_REVIEW_APPROVED'    => ['label' => 'Branch Head Review',      'color' => '#9b59b6', 'icon' => 'bi-person-check',        'href_tpl' => '/rfq/branch_head_approve.php?id={id}'],
    'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' => ['label' => 'Pending Branch Head Approval', 'color' => '#8e44ad', 'icon' => 'bi-shield-check',   'href_tpl' => '/rfq/branch_head_approve.php?id={id}'],
    'QUOTE_APPROVED'                     => ['label' => 'Create Commitment',       'color' => '#43e97b', 'icon' => 'bi-plus-circle',         'href_tpl' => '/commitments/add.php?request_id={id}'],
    'COMMITTEE_RECOMMENDED'              => ['label' => 'GC Approval Required',    'color' => '#f093fb', 'icon' => 'bi-shield-check',        'href_tpl' => '/rfq/gc_approve.php?request_id={id}'],
    'GC_APPROVED'                        => ['label' => 'Ready for Award',         'color' => '#20c997', 'icon' => 'bi-trophy',              'href_tpl' => '/rfq/award.php?request_id={id}'],
    'FUNDS_VERIFIED'                     => ['label' => 'Proceed with Commitment', 'color' => '#17a2b8', 'icon' => 'bi-check-circle',       'href_tpl' => '/commitments/add.php?request_id={id}'],
    'COMMITMENTS_PENDING'                => ['label' => 'Create Commitment',       'color' => '#3498db', 'icon' => 'bi-upload',              'href_tpl' => '/commitments/add.php?request_id={id}'],
    'COMMITMENT_APPROVED'                => ['label' => 'Create Purchase Order',   'color' => '#667eea', 'icon' => 'bi-file-earmark-plus',   'href_tpl' => '/po/add.php?request_id={id}'],
    'COMMITMENT_DECLINED'                => ['label' => 'Revise & Resubmit',       'color' => '#f5576c', 'icon' => 'bi-arrow-repeat',        'href_tpl' => '/procurement/view.php?id={id}'],
    'PO_PENDING'                         => ['label' => 'Create PO',               'color' => '#2980b9', 'icon' => 'bi-file-earmark-text',   'href_tpl' => '/po/add.php?request_id={id}'],
    'INVOICE_RECEIVED'                   => ['label' => 'Record Payment',          'color' => '#27ae60', 'icon' => 'bi-cash-stack',          'href_tpl' => '/invoice/view.php?request_id={id}'],
];

/**
 * Build the direct-action URL for a given status and request_id.
 */
function actionHref(string $status, int $requestId, array $actionMap, ?int $rfqId = null): string
{
    $tpl = $actionMap[$status]['href_tpl'] ?? '/procurement/view.php?id={id}';
    $targetId = $requestId;
    if (in_array($status, ['QUOTE_REVIEW_PENDING', 'ADDITIONAL_QUOTATIONS_REQUIRED', 'QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_APPROVED', 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING'], true) && $rfqId) {
        $targetId = $rfqId;
    }
    return str_replace('{id}', (string)$targetId, $tpl);
}

/**
 * Compute age in days from created_at.
 */
function ageDays(string $createdAt): int
{
    $created = new DateTime($createdAt);
    $now     = new DateTime();
    return max(0, (int)$now->diff($created)->days);
}

function pendingActionRiskWeight(string $riskLevel, string $priority): int
{
    $riskWeights = ['critical' => 400, 'overdue' => 300, 'warning' => 200, 'normal' => 100];
    $priorityWeights = ['urgent' => 30, 'high' => 20, 'normal' => 10];
    return ($riskWeights[$riskLevel] ?? 0) + ($priorityWeights[$priority] ?? 0);
}

function pendingActionDecorate(PDO $pdo, array $row): array
{
    $age = ageDays((string)$row['created_at']);
    $snapshot = WorkflowConfigurationService::getEscalationSnapshot(
        $pdo,
        (string)($row['request_type'] ?? 'REGULAR'),
        (string)($row['request_status'] ?? ''),
        $age,
        (float)($row['estimated_value'] ?? 0)
    );

    $row['_age_days'] = $age;
    $row['_sla'] = $snapshot;
    $row['_risk_weight'] = pendingActionRiskWeight($snapshot['risk_level'], $snapshot['priority']);
    return $row;
}

function pendingActionCompare(array $left, array $right): int
{
    return [$right['_risk_weight'], (float)$right['estimated_value'], $right['_age_days']]
        <=> [$left['_risk_weight'], (float)$left['estimated_value'], $left['_age_days']];
}

function pendingActionRiskBadge(array $snapshot): string
{
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ui.php';

    $map = [
        'critical' => ['danger', 'Critical'],
        'overdue' => ['warning', 'High'],
        'warning' => ['info', 'Approaching'],
        'normal' => ['neutral', 'Normal'],
    ];
    [$variant, $label] = $map[$snapshot['risk_level']] ?? $map['normal'];
    return ui_badge($label, $variant);
}

$pendingApprovals = array_map(static fn(array $row): array => pendingActionDecorate($pdo, $row), $pendingApprovals);
$workflowActions = array_map(static fn(array $row): array => pendingActionDecorate($pdo, $row), $workflowActions);
usort($pendingApprovals, 'pendingActionCompare');
usort($workflowActions, 'pendingActionCompare');

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ui.php';

/**
 * Shared renderer for both pending-action tables. Keeps the two sections
 * visually identical while letting each pass its own extra columns.
 */
$renderPendingActionRows = static function (array $rows, bool $isWorkflow) use ($statusActionMap): void {
    foreach ($rows as $row) {
        $age     = (int)$row['_age_days'];
        $sla     = $row['_sla'];
        $slaDate = date('d M Y', strtotime($row['created_at'] . ' +' . $sla['sla_days'] . ' days'));
        $ageCls  = $sla['risk_level'] === 'normal' ? '' : ' ui-text-danger';

        if ($isWorkflow) {
            $status     = strtoupper($row['request_status']);
            $actionInfo = $statusActionMap[$status] ?? ['label' => 'View', 'color' => '#6c757d', 'icon' => 'bi-eye', 'href_tpl' => '/procurement/view.php?id={id}'];
            $href       = actionHref($status, (int)$row['request_id'], $statusActionMap, isset($row['rfq_id']) ? (int)$row['rfq_id'] : null);
        } else {
            $href = '/procurement/approve.php?id=' . (int)$row['request_id'];
        }
        ?>
        <tr>
            <th scope="row"><?= htmlspecialchars($row['request_number']) ?></th>
            <td class="ui-cell-wrap"><?= htmlspecialchars(dashboardShortText($row['description'] ?? '')) ?></td>
            <td><?= htmlspecialchars($row['request_type']) ?></td>
            <td><?= htmlspecialchars($row['requestor_name'] ?? 'N/A') ?></td>
            <td><?= htmlspecialchars($row['branch_name'] ?? 'N/A') ?></td>
            <td class="ui-num"><?= htmlspecialchars(normalizeCurrency($row['currency'] ?? 'JMD')) ?> <?= number_format((float)$row['estimated_value'], 2) ?></td>
            <td>
                <?php if ($isWorkflow): ?>
                    <?= ui_badge($status, 'warning') ?>
                <?php else: ?>
                    <?= statusBadge($row['request_status']) ?>
                <?php endif; ?>
            </td>
            <td class="ui-num<?= $ageCls ?>"><?= $age ?>d</td>
            <td class="ui-num ui-muted-cell"><?= $slaDate ?></td>
            <td><?= pendingActionRiskBadge($sla) ?></td>
            <td>
                <a class="ui-action ui-action--sm ui-action--primary ui-nowrap" href="<?= htmlspecialchars($href) ?>">
                    <?php if ($isWorkflow): ?>
                        <i class="bi <?= htmlspecialchars($actionInfo['icon']) ?>" aria-hidden="true"></i>
                        <span><?= htmlspecialchars($actionInfo['label']) ?></span>
                    <?php else: ?>
                        <span>Review</span>
                    <?php endif; ?>
                </a>
            </td>
        </tr>
        <?php
    }
};

$pendingActionHeadings = static function (bool $isWorkflow): void {
    ?>
    <tr>
        <th scope="col"><?= dashboardSortLink('ref', 'Ref #') ?></th>
        <th scope="col"><?= dashboardSortLink('description', 'Description') ?></th>
        <th scope="col">Category</th>
        <th scope="col"><?= dashboardSortLink('requested_by', 'Requestor') ?></th>
        <th scope="col">Unit</th>
        <th scope="col" class="ui-num"><?= dashboardSortLink('amount', 'Amount') ?></th>
        <th scope="col"><?= dashboardSortLink('status', 'Stage') ?></th>
        <th scope="col" class="ui-num"><?= dashboardSortLink('date', 'Age') ?></th>
        <th scope="col" class="ui-num">Due (SLA)</th>
        <th scope="col">Risk</th>
        <th scope="col">Next Action</th>
    </tr>
    <?php
};

$pendingActionsTools = '';
if ($totalPendingActions > 0) {
    $pendingActionsTools = ui_badge((string)$totalPendingActions, 'danger') . ' ';
    $escalationProfile = WorkflowConfigurationService::getEscalationPercentages($pdo);
    $pendingActionsTools .= '<span class="ui-hint">SLA alerts: '
        . (int)$escalationProfile['warning'] . '% / '
        . (int)$escalationProfile['overdue'] . '% / '
        . (int)$escalationProfile['critical'] . '%</span>';
}

ui_card_open([
    'title' => 'Requests Requiring Action',
    'icon'  => 'bi-hourglass-split',
    'tools' => $pendingActionsTools,
]);

if ($totalPendingActions === 0) {
    ui_empty('You\'re all caught up! No requests currently require your action.', 'bi-check2-circle');
}

if (!empty($pendingApprovals)) {
    ui_section_open('Approvals Awaiting Your Review (' . count($pendingApprovals) . ')', true, 'bi-check-circle');
    ui_table_toolbar('pending-approvals-table', [
        'columns'     => true,
        'export'      => true,
        'placeholder' => 'Search approvals…',
    ]);
    ui_table_open('pending-approvals-table', [
        'paginate' => true,
        'perPage'  => 10,
        'label'    => 'Approvals awaiting your review',
    ]);
    echo '<thead>';
    $pendingActionHeadings(false);
    echo '</thead><tbody>';
    $renderPendingActionRows($pendingApprovals, false);
    echo '</tbody>';
    ui_table_close('pending-approvals-table');
    ui_section_close();
}

if (!empty($workflowActions)) {
    ui_section_open('Workflow Actions Required (' . count($workflowActions) . ')', true, 'bi-arrow-repeat');
    ui_table_toolbar('workflow-actions-table', [
        'columns'     => true,
        'export'      => true,
        'placeholder' => 'Search workflow actions…',
    ]);
    ui_table_open('workflow-actions-table', [
        'paginate' => true,
        'perPage'  => 10,
        'label'    => 'Workflow actions required',
    ]);
    echo '<thead>';
    $pendingActionHeadings(true);
    echo '</thead><tbody>';
    $renderPendingActionRows($workflowActions, true);
    echo '</tbody>';
    ui_table_close('workflow-actions-table');
    ui_section_close();
}

ui_card_close();
