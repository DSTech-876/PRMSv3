<?php
/**
 * Procurement Task Tracking API
 * =============================
 * GET  ?action=board&request_id=N        – task board for a single request
 * GET  ?action=summary&request_id=N      – counts only for a single request
 * GET  ?action=mode                      – active workflow mode
 * POST ?action=mark                      – record an explicit task state
 *        body: request_id, task_key, task_status, notes?, csrf_token
 *
 * All responses are JSON. Authentication is enforced via config/auth.php.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/ProcurementTaskService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/WorkflowModeService.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'board';
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

function taskApiFail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

if ($action === 'mode') {
    echo json_encode([
        'mode'  => WorkflowModeService::getMode($pdo),
        'label' => WorkflowModeService::modeLabel(WorkflowModeService::getMode($pdo)),
    ]);
    exit;
}

if ($action === 'mark') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        taskApiFail(405, 'POST required');
    }
    if (!hasPermission('view_requests')) {
        taskApiFail(403, 'Not authorized');
    }

    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$token)) {
        taskApiFail(419, 'Invalid or expired request token');
    }

    $requestId = (int)($_POST['request_id'] ?? 0);
    $taskKey   = (string)($_POST['task_key'] ?? '');
    $status    = (string)($_POST['task_status'] ?? '');
    $notes     = substr(trim((string)($_POST['notes'] ?? '')), 0, 255);

    $request = WorkflowModeService::loadRequest($pdo, $requestId);
    if (!$request) {
        taskApiFail(404, 'Request not found');
    }

    if (!canCurrentUserAccessRequestRecord($request)) {
        taskApiFail(403, 'You do not have permission to access this request');
    }

    if (!ProcurementTaskService::markTask($pdo, $requestId, $taskKey, $status, $userId, $notes)) {
        taskApiFail(422, 'Unable to record task state');
    }

    logAudit(
        $pdo,
        'procurement_tasks',
        $requestId,
        'TASK_STATUS_CHANGE',
        'Task ' . strtoupper($taskKey) . ' set to ' . ProcurementTaskService::normalizeStatus($status)
            . ($notes !== '' ? ' — ' . $notes : '')
    );

    echo json_encode([
        'ok'    => true,
        'board' => ProcurementTaskService::getTaskBoard($pdo, $requestId),
    ]);
    exit;
}

// --- Read-only board / summary -------------------------------------------
$requestId = (int)($_GET['request_id'] ?? 0);
if ($requestId <= 0) {
    taskApiFail(400, 'request_id is required');
}

$request = WorkflowModeService::loadRequest($pdo, $requestId);
if (!$request) {
    taskApiFail(404, 'Request not found');
}

if (function_exists('canCurrentUserAccessRequestRecord')
    && !canCurrentUserAccessRequestRecord($request)) {
    taskApiFail(403, 'Not authorized to view this request');
}

$board = ProcurementTaskService::getTaskBoard($pdo, $request);

echo json_encode($action === 'summary' ? $board['summary'] : $board);
