<?php
/**
 * Procurement Progress Dashboard
 * ==============================
 * Task-completion view across all active procurement requests.
 *
 * Shows, at a glance, for every request:
 *   - completed tasks
 *   - in-progress tasks
 *   - pending / missing tasks
 *   - the department responsible for each outstanding task
 *
 * This replaces "where is this stuck in the pipeline?" with "what is left to
 * do, and who owns it?".  It works in both workflow modes; in LEGACY_MODE it
 * is purely informational and changes no behaviour.
 */
$REQUIRE_PERMISSION = 'view_procurement_progress';
$ALTERNATE_PERMISSIONS = ['view_requests'];
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/ProcurementTaskService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/WorkflowModeService.php';

$activeMode   = WorkflowModeService::getMode($pdo);
$filterStatus = strtoupper(trim((string)($_GET['task_status'] ?? '')));
$searchTerm   = trim((string)($_GET['q'] ?? ''));
$limit        = 100;

$sql = "
    SELECT pr.*, b.branch_name, u.full_name AS requester_name
    FROM procurement_requests pr
    LEFT JOIN branches b ON b.branch_id = pr.branch_id
    LEFT JOIN users u ON u.user_id = pr.created_by
    WHERE UPPER(pr.status) NOT IN ('DRAFT', 'DECLINED', 'CANCELLED')
";
$params = [];

if ($searchTerm !== '') {
    $sql .= " AND (pr.request_number LIKE ? OR pr.description LIKE ?)";
    $params[] = '%' . $searchTerm . '%';
    $params[] = '%' . $searchTerm . '%';
}

$sql .= ' ORDER BY pr.request_id DESC LIMIT ' . (int)$limit;

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $requests = [];
}

$rows = [];
$totals = ['completed' => 0, 'in_progress' => 0, 'pending' => 0, 'not_required' => 0];

foreach ($requests as $requestRow) {
    $board = ProcurementTaskService::getTaskBoard($pdo, $requestRow);

    if ($filterStatus !== '') {
        $matches = false;
        foreach ($board['tasks'] as $task) {
            if ($task['task_status'] === $filterStatus) {
                $matches = true;
                break;
            }
        }
        if (!$matches) {
            continue;
        }
    }

    foreach ($totals as $key => $_) {
        $totals[$key] += (int)($board['summary'][$key] ?? 0);
    }

    $rows[] = ['request' => $requestRow, 'board' => $board];
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h3 class="mb-0"><i class="bi bi-kanban me-2"></i>Procurement Progress Dashboard</h3>
        <span class="badge <?= $activeMode === WorkflowModeService::MODE_PARALLEL ? 'bg-info text-dark' : 'bg-secondary' ?> fs-6">
            <?= htmlspecialchars(WorkflowModeService::modeLabel($activeMode)) ?>
        </span>
    </div>

    <div class="row g-3 mb-3">
        <?php
        $cards = [
            ['Completed',    $totals['completed'],    'success',          '✅'],
            ['In Progress',  $totals['in_progress'],  'primary',          '🔄'],
            ['Pending',      $totals['pending'],      'warning text-dark', '⏳'],
            ['Not Required', $totals['not_required'], 'secondary',        '➖'],
        ];
        foreach ($cards as [$label, $count, $variant, $icon]): ?>
            <div class="col-6 col-lg-3">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <span class="fs-3" aria-hidden="true"><?= $icon ?></span>
                        <div>
                            <div class="fs-4 fw-bold"><?= (int)$count ?></div>
                            <div class="text-muted small"><?= htmlspecialchars($label) ?> tasks</div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <form method="get" class="row g-2 align-items-end mb-3">
        <div class="col-md-4">
            <label class="form-label small fw-bold" for="q">Search</label>
            <input type="search" class="form-control" id="q" name="q"
                   value="<?= htmlspecialchars($searchTerm) ?>" placeholder="Request number or description">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold" for="task_status">Has task with status</label>
            <select class="form-select" id="task_status" name="task_status">
                <option value="">Any</option>
                <?php foreach (ProcurementTaskService::validStatuses() as $statusOption): ?>
                    <option value="<?= htmlspecialchars($statusOption) ?>" <?= $filterStatus === $statusOption ? 'selected' : '' ?>>
                        <?= htmlspecialchars(ProcurementTaskService::statusPresentation($statusOption)['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Request</th>
                        <th scope="col">Branch</th>
                        <?php foreach (ProcurementTaskService::taskDefinitions() as $definition): ?>
                            <th scope="col" class="text-center small"><?= htmlspecialchars($definition['label']) ?></th>
                        <?php endforeach; ?>
                        <th scope="col" class="text-center">Progress</th>
                        <th scope="col">Next Owner</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="12" class="text-center text-muted py-4">No procurement requests match the current filter.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row):
                    $requestRow = $row['request'];
                    $board      = $row['board'];

                    $nextOwner = '—';
                    foreach ($board['tasks'] as $task) {
                        if (in_array($task['task_status'], [ProcurementTaskService::STATUS_PENDING, ProcurementTaskService::STATUS_IN_PROGRESS], true)
                            && !$task['optional']) {
                            $nextOwner = $task['label'] . ' — ' . $task['department'];
                            break;
                        }
                    }
                ?>
                    <tr>
                        <td>
                            <a href="/procurement/view.php?id=<?= (int)$requestRow['request_id'] ?>" class="fw-semibold text-decoration-none">
                                <?= htmlspecialchars((string)$requestRow['request_number']) ?>
                            </a>
                            <div class="text-muted small"><?= htmlspecialchars(mb_strimwidth((string)($requestRow['description'] ?? ''), 0, 60, '…')) ?></div>
                        </td>
                        <td class="small text-muted"><?= htmlspecialchars((string)($requestRow['branch_name'] ?? '—')) ?></td>
                        <?php foreach ($board['tasks'] as $task): ?>
                            <td class="text-center" title="<?= htmlspecialchars($task['label'] . ': ' . $task['status_label'] . ' (' . $task['department'] . ')') ?>">
                                <span class="fs-5" aria-hidden="true"><?= $task['icon'] ?></span>
                                <span class="visually-hidden"><?= htmlspecialchars($task['label'] . ' ' . $task['status_label']) ?></span>
                            </td>
                        <?php endforeach; ?>
                        <td class="text-center" style="min-width: 110px;">
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-success" style="width: <?= (int)$board['summary']['percent_complete'] ?>%"></div>
                            </div>
                            <small class="text-muted"><?= (int)$board['summary']['percent_complete'] ?>%</small>
                        </td>
                        <td class="small text-muted"><?= htmlspecialchars($nextOwner) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-muted small mt-3">
        ✅ Completed &nbsp; 🔄 In Progress &nbsp; ⏳ Pending &nbsp; ➖ Not Required
    </p>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
