<?php
/**
 * Dashboard Widget: Petty Cash Requests Awaiting Approval
 * Displays cards and table of pending petty cash requests for HOD/Branch Head
 */

// This widget expects $approverRole and $pdo to be available from the parent dashboard

if (!isset($pdo) || !isset($approverRole)) {
    echo '<div class="alert alert-warning">Widget not properly initialized.</div>';
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$pettyCashRequests = getPendingPettyCashApprovals($pdo, $userId, $approverRole);

$totalPcAmount = 0.00;
$oldestDate = null;
$overdueCount = 0;

foreach ($pettyCashRequests as $req) {
    $totalPcAmount += (float)$req['estimated_value'];
    if (!$oldestDate || strtotime($req['created_at']) < strtotime($oldestDate)) {
        $oldestDate = $req['created_at'];
    }
    // Consider overdue if pending for more than 2 days
    if ((int)$req['days_pending'] > 2) {
        $overdueCount++;
    }
}

$daysPendingOldest = $oldestDate ? intval((time() - strtotime($oldestDate)) / 86400) : 0;

require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/widgets/partials/pending_finance_approvals.php';

render_pending_finance_approvals([
    'title'             => 'Petty Cash Requests Awaiting Approval',
    'icon'              => 'bi-cash-coin',
    'tableId'           => 'petty-cash-approvals-table',
    'approverRole'      => (string)$approverRole,
    'rows'              => $pettyCashRequests,
    'totalAmount'       => $totalPcAmount,
    'oldestDate'        => $oldestDate,
    'daysPendingOldest' => $daysPendingOldest,
    'overdueCount'      => $overdueCount,
    'overdueDays'       => 2,
    'listUrl'           => '/petty_cash/list.php?status=SUBMITTED&approval_pending=1',
    'viewUrlTpl'        => '/petty_cash/view.php?request_id={id}',
    'emptyMessage'      => 'All caught up! No petty cash requests are currently awaiting your approval.',
]);
