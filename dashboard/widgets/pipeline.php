<?php
if (!isset($pdo)) {
    require_once __DIR__.'/_init.php';
}
require_once dirname(__DIR__, 2) . '/includes/ui.php';

$pipelineVisibilityWhere = '';
if (function_exists('isProcurementVisibilityRestrictedRole')
    && function_exists('getProcurementVisibilitySqlCondition')
    && isProcurementVisibilityRestrictedRole()
) {
    $pipelineVisibilityWhere = 'WHERE ' . getProcurementVisibilitySqlCondition('procurement_requests');
}

$pipeline = $pdo->query("
    SELECT UPPER(status) AS status, COUNT(*) AS cnt, SUM(estimated_value) AS total_value
    FROM procurement_requests
    {$pipelineVisibilityWhere}
    GROUP BY UPPER(status)
    ORDER BY FIELD(UPPER(status), 'DRAFT', 'SUBMITTED', 'HOD_APPROVED', 'FUNDS_VERIFIED', 'DIRECTOR_APPROVED', 'GC_APPROVED', 'RFQ_LETTER_AVAILABLE', 'QUOTE_REVIEW_PENDING', 'ADDITIONAL_QUOTATIONS_REQUIRED', 'QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_APPROVED', 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING', 'QUOTE_APPROVED', 'PROCUREMENT_STAGE', 'EVALUATION_STAGE', 'COMMITTEE_RECOMMENDED', 'COMMITMENTS_PENDING', 'COMMITMENT_APPROVED', 'PO_PENDING', 'PO_APPROVED', 'INVOICE_RECEIVED', 'AWARDED', 'COMPLETED', 'DECLINED')
")->fetchAll(PDO::FETCH_ASSOC);

$totalCount = array_sum(array_column($pipeline, 'cnt'));
$totalValue = (float)array_sum(array_column($pipeline, 'total_value'));

$stageMeta = [
    'DRAFT'                 => ['color' => '#6c757d', 'label' => 'Draft'],
    'SUBMITTED'             => ['color' => '#d97706', 'label' => 'Submitted'],
    'HOD_APPROVED'          => ['color' => '#0891b2', 'label' => 'HOD Approved'],
    'FUNDS_VERIFIED'        => ['color' => '#2563eb', 'label' => 'Funds Verified'],
    'DIRECTOR_APPROVED'     => ['color' => '#15803d', 'label' => 'Director Approved'],
    'PROCUREMENT_STAGE'     => ['color' => '#334155', 'label' => 'Procurement Stage'],
    'EVALUATION_STAGE'      => ['color' => '#6610f2', 'label' => 'Evaluation Stage'],
    'COMMITTEE_RECOMMENDED' => ['color' => '#0891b2', 'label' => 'Committee Recommended'],
    'GC_APPROVED'           => ['color' => '#15803d', 'label' => 'GC Approved'],
    'RFQ_LETTER_AVAILABLE'  => ['color' => '#6f42c1', 'label' => 'RFQ Letters'],
    'QUOTE_REVIEW_PENDING'  => ['color' => '#ea580c', 'label' => 'Quote Review'],
    'ADDITIONAL_QUOTATIONS_REQUIRED' => ['color' => '#b45309', 'label' => 'Additional Quotations Required'],
    'QUOTE_REQUESTOR_REVIEW_PENDING' => ['color' => '#c2410c', 'label' => 'Requestor Review'],
    'QUOTE_REQUESTOR_REVIEW_APPROVED' => ['color' => '#0d9488', 'label' => 'Requestor Approved'],
    'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' => ['color' => '#7e22ce', 'label' => 'Branch Head Approval'],
    'QUOTE_APPROVED'        => ['color' => '#0d9488', 'label' => 'Quote Approved'],
    'COMMITMENTS_PENDING'   => ['color' => '#d97706', 'label' => 'Commitments Pending'],
    'COMMITMENT_APPROVED'   => ['color' => '#2563eb', 'label' => 'Commitment Approved'],
    'COMMITMENT_DECLINED'   => ['color' => '#dc2626', 'label' => 'Commitment Declined'],
    'PO_PENDING'            => ['color' => '#6610f2', 'label' => 'PO Created'],
    'PO_APPROVED'           => ['color' => '#15803d', 'label' => 'PO Approved'],
    'INVOICE_RECEIVED'      => ['color' => '#0891b2', 'label' => 'Invoice Received'],
    'AWARDED'               => ['color' => '#15803d', 'label' => 'Awarded'],
    'COMPLETED'             => ['color' => '#15803d', 'label' => 'Completed'],
    'DECLINED'              => ['color' => '#dc2626', 'label' => 'Declined'],
];

$chartRows = [];
foreach ($pipeline as $stage) {
    $meta = $stageMeta[$stage['status']] ?? ['color' => '#6c757d', 'label' => $stage['status']];
    $chartRows[] = [
        'label' => $meta['label'],
        'value' => (int)$stage['cnt'],
        'color' => $meta['color'],
        'href'  => '/procurement/list.php?status=' . urlencode((string)$stage['status']),
    ];
}

$footer = '<dl class="ui-dl ui-dl--inline">'
    . '<div><dt>Total Requests</dt><dd>' . ui_e(number_format($totalCount)) . '</dd></div>'
    . '<div><dt>Total Value</dt><dd>$' . ui_e(number_format($totalValue, 0)) . '</dd></div>'
    . '</dl>';

ui_card_open([
    'title' => 'Procurement Pipeline',
    'icon'  => 'bi-diagram-3',
    'tools' => ui_badge(number_format($totalCount) . ' requests', 'info'),
]);
echo '<div class="ui-scroll-area ui-scroll-area--md">';
ui_chart_bars($chartRows);
echo '</div>';
ui_card_close($footer);
