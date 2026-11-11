<?php
/**
 * Dashboard Widget: Reimbursement Requests Awaiting Approval
 * Displays cards and table of pending reimbursement requests for HOD/Branch Head
 */

// This widget expects $approverRole and $pdo to be available from the parent dashboard

if (!isset($pdo) || !isset($approverRole)) {
    echo '<div class="alert alert-warning">Widget not properly initialized.</div>';
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$reimbursementRequests = getPendingReimbursementApprovals($pdo, $userId, $approverRole);

$totalReimbAmount = 0.00;
$oldestDate = null;
$overdueCount = 0;

foreach ($reimbursementRequests as $req) {
    $totalReimbAmount += (float)$req['estimated_value'];
    if (!$oldestDate || strtotime($req['created_at']) < strtotime($oldestDate)) {
        $oldestDate = $req['created_at'];
    }
    // Consider overdue if pending for more than 5 days
    if ((int)$req['days_pending'] > 5) {
        $overdueCount++;
    }
}

$daysPendingOldest = $oldestDate ? intval((time() - strtotime($oldestDate)) / 86400) : 0;

require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/widgets/partials/pending_finance_approvals.php';

render_pending_finance_approvals([
    'title'             => 'Reimbursement Requests Awaiting Approval',
    'icon'              => 'bi-receipt',
    'tableId'           => 'reimbursement-approvals-table',
    'approverRole'      => (string)$approverRole,
    'rows'              => $reimbursementRequests,
    'totalAmount'       => $totalReimbAmount,
    'oldestDate'        => $oldestDate,
    'daysPendingOldest' => $daysPendingOldest,
    'overdueCount'      => $overdueCount,
    'overdueDays'       => 5,
    'listUrl'           => '/reimbursement/list.php?status=SUBMITTED&approval_pending=1',
    'viewUrlTpl'        => '/reimbursement/view.php?request_id={id}',
    'emptyMessage'      => 'All caught up! No reimbursement requests are currently awaiting your approval.',
    'showInvoiceCount'  => true,
]);
