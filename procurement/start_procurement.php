<?php
$REQUIRE_PERMISSION = 'view_requests';

require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

requirePostRequest('/procurement/list.php');
requireCsrfToken('/procurement/list.php');

$id = (int)($_POST['request_id'] ?? 0);
if (!$id) {
    pop('Invalid request ID', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM procurement_requests WHERE request_id = ?");
$stmt->execute([$id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    pop('Request not found', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

if (!canCurrentUserAccessRequestRecord($request)) {
    pop('You do not have permission to update this request.', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

if (!in_array((string)($_SESSION['role_name'] ?? ''), ['Procurement Officer', 'Admin', 'SuperAdmin'], true)) {
    pop('You do not have permission to start procurement for this request.', '/procurement/view.php?id=' . $id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

enforceTransition($request, 'PROCUREMENT_STAGE');

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE procurement_requests
        SET status = 'PROCUREMENT_STAGE'
        WHERE request_id = ?
    ");
    $stmt->execute([$id]);

    logProcurementVisibilityGranted($pdo, $id, 'PROCUREMENT_STAGE', (string)($request['status'] ?? ''));

    $actorName = (string)($_SESSION['full_name'] ?? 'Unknown');
    $note = 'Procurement stage started by ' . $actorName
        . ' — status changed from ' . (string)($request['status'] ?? 'UNKNOWN') . ' to PROCUREMENT_STAGE.';
    logAudit($pdo, 'procurement_requests', $id, 'STATUS_CHANGE', $note);
    logRequestTimeline($pdo, $id, 'PROCUREMENT_STAGE', $note);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    pop(extractDbMessage($e), '/procurement/view.php?id=' . $id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* Alert the requestor and procurement team that the procurement stage is active */
require_once $_SERVER['DOCUMENT_ROOT'].'/config/notifications.php';
notifyProcurementStageStarted($id, (string)($_SESSION['full_name'] ?? 'Procurement'));

header("Location: view.php?id=".$id);
exit;
