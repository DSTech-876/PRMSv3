<?php
/**
 * Task Status Panel
 * =================
 * Visual task-tracking panel that replaces the sequential-stage display for
 * requests running under PARALLEL_MODE.
 *
 * Expects:
 *   $pdo              PDO
 *   $taskPanelRequest array  procurement_requests row (must include request_id)
 *
 * Renders nothing when the request is not resolvable.
 */

require_once __DIR__ . '/../services/ProcurementTaskService.php';
require_once __DIR__ . '/../services/WorkflowModeService.php';

$taskPanelRequest = $taskPanelRequest ?? ($request ?? []);
if (!is_array($taskPanelRequest) || (int)($taskPanelRequest['request_id'] ?? 0) <= 0) {
    return;
}

$taskBoard   = ProcurementTaskService::getTaskBoard($pdo, $taskPanelRequest);
$isParallel  = $taskBoard['mode'] === WorkflowModeService::MODE_PARALLEL;

// Fund verification is optional/informational only — Branch Head approval
// already confirms budget availability, so it is excluded from the visible
// Procurement Progress board and its completion summary.
$taskBoard['tasks'] = array_values(array_filter(
    $taskBoard['tasks'],
    static fn(array $task): bool => $task['key'] !== ProcurementTaskService::TASK_FUND_VERIFICATION
));

$taskSummary = [
    'total'        => count($taskBoard['tasks']),
    'completed'    => 0,
    'in_progress'  => 0,
    'pending'      => 0,
    'not_required' => 0,
];
foreach ($taskBoard['tasks'] as $task) {
    switch ($task['task_status']) {
        case ProcurementTaskService::STATUS_COMPLETED:    $taskSummary['completed']++;    break;
        case ProcurementTaskService::STATUS_IN_PROGRESS:  $taskSummary['in_progress']++;  break;
        case ProcurementTaskService::STATUS_NOT_REQUIRED: $taskSummary['not_required']++; break;
        default:                                          $taskSummary['pending']++;      break;
    }
}
$actionableTasks = $taskSummary['total'] - $taskSummary['not_required'];
$taskSummary['percent_complete'] = $actionableTasks > 0
    ? (int)round(($taskSummary['completed'] / $actionableTasks) * 100)
    : 100;
?>
<div class="card shadow-sm mb-4" id="procurement-task-panel">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h5 class="mb-0">
            <i class="bi bi-diagram-3 me-2"></i> Procurement Progress
        </h5>
        <div class="d-flex align-items-center gap-2">
            <span class="badge <?= $isParallel ? 'bg-info text-dark' : 'bg-secondary' ?>">
                <?= htmlspecialchars(WorkflowModeService::modeLabel($taskBoard['mode'])) ?>
            </span>
            <span class="badge bg-light text-dark border">
                <?= (int)$taskSummary['completed'] ?>/<?= (int)($taskSummary['total'] - $taskSummary['not_required']) ?> complete
            </span>
        </div>
    </div>
    <div class="card-body">
        <?php if ($isParallel): ?>
            <p class="text-muted small mb-3">
                Departments work independently. A task is never blocked because another task is still outstanding —
                Branch Head approval already confirms budget availability and management authorization.
            </p>
        <?php endif; ?>

        <div class="progress mb-3" style="height: 8px;" role="progressbar"
             aria-valuenow="<?= (int)$taskSummary['percent_complete'] ?>" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar bg-success" style="width: <?= (int)$taskSummary['percent_complete'] ?>%"></div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 procurement-task-table">
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="width: 1%"></th>
                        <th scope="col">Task</th>
                        <th scope="col">Status</th>
                        <th scope="col">Responsible Department</th>
                        <th scope="col">Detail</th>
                        <th scope="col">Completed</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($taskBoard['tasks'] as $task): ?>
                    <tr data-task-key="<?= htmlspecialchars($task['key']) ?>"
                        data-task-status="<?= htmlspecialchars($task['task_status']) ?>">
                        <td class="fs-5 text-center" aria-hidden="true"><?= $task['icon'] ?></td>
                        <td class="fw-semibold">
                            <?= htmlspecialchars($task['label']) ?>
                            <?php if ($task['optional']): ?>
                                <span class="badge bg-light text-muted border ms-1">Optional</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= htmlspecialchars($task['badge']) ?>">
                                <?= htmlspecialchars($task['status_label']) ?>
                            </span>
                        </td>
                        <td class="text-muted small"><?= htmlspecialchars($task['department']) ?></td>
                        <td class="text-muted small"><?= htmlspecialchars((string)$task['detail']) ?></td>
                        <td class="text-muted small">
                            <?php if (!empty($task['task_completed_date'])): ?>
                                <?= htmlspecialchars(date('d M Y', strtotime((string)$task['task_completed_date']))) ?>
                                <?php if (!empty($task['completed_by_name'])): ?>
                                    <br><span class="text-secondary"><?= htmlspecialchars((string)$task['completed_by_name']) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex flex-wrap gap-3 mt-3 small text-muted">
            <span>✅ Completed</span>
            <span>🔄 In Progress</span>
            <span>⏳ Pending</span>
            <span>➖ Not Required</span>
        </div>
    </div>
</div>
