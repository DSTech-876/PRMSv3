<?php
$REQUIRE_PERMISSION = 'view_approval_analytics';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';

// Query approval analytics data. The approval_queue table is optional in some
// deployments, so a missing table degrades to an empty dataset instead of a fatal.
$analytics = [];
$analyticsUnavailable = false;
try {
    $stmt = $pdo->prepare("SELECT stage_order, status, COUNT(*) as count FROM approval_queue GROUP BY stage_order, status");
    $stmt->execute();
    $analytics = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $analyticsUnavailable = true;
}

// Total pending approvals
$stmt = $pdo->query("SELECT COUNT(*) FROM request_approvals WHERE status = 'pending'");
$total_pending = $stmt->fetchColumn();

// Map stage_order to readable stage names
$stageNames = [
        1 => 'Submitted',
        2 => 'HOD Review',
        3 => 'Finance Review',
        4 => 'Procurement Stage',
        5 => 'Evaluation Stage',
        6 => 'Committee Recommendation',
        7 => 'GC Approval',
        8 => 'Awarded',
        9 => 'Completed',
];

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/ui.php';

$statusVariants = [
    'approved' => 'success',
    'pending'  => 'warning',
    'rejected' => 'danger',
];

// Aggregate per stage so the chart shows total throughput per approval stage.
$stageTotals = [];
foreach ($analytics as $row) {
    $name = $stageNames[$row['stage_order']] ?? (string)$row['stage_order'];
    $stageTotals[$name] = ($stageTotals[$name] ?? 0) + (int)$row['count'];
}
$totalRecords = array_sum($stageTotals);
?>
<div class="ui-page">
<?php
ui_page_header([
    'title'       => 'Approval Analytics',
    'subtitle'    => 'Distribution of approval records across workflow stages',
    'icon'        => 'bi-bar-chart-line',
    'breadcrumbs' => [
        ['label' => 'Home', 'href' => '/dashboard/index.php'],
        ['label' => 'Reports'],
        ['label' => 'Approval Analytics'],
    ],
    'actions' => [
        ['label' => 'Management Dashboard', 'href' => '/dashboard/management.php', 'icon' => 'bi-person-badge'],
        ['label' => 'Admin Dashboard', 'href' => '/dashboard/admin.php', 'icon' => 'bi-tools'],
    ],
]);

ui_kpi_grid([
    ['label' => 'Pending Approvals', 'value' => number_format((int)$total_pending), 'icon' => 'bi-hourglass-split', 'variant' => (int)$total_pending > 0 ? 'amber' : 'green'],
    ['label' => 'Approval Records',  'value' => number_format($totalRecords), 'icon' => 'bi-stack', 'variant' => 'blue'],
    ['label' => 'Stages Tracked',    'value' => number_format(count($stageTotals)), 'icon' => 'bi-diagram-3', 'variant' => 'purple'],
]);

echo '<div class="ui-grid ui-grid--2">';

ui_card_open(['title' => 'Queue Volume by Stage', 'icon' => 'bi-diagram-3']);
ui_chart_bars(array_map(
    static fn(string $label, int $value): array => ['label' => $label, 'value' => $value],
    array_keys($stageTotals),
    array_values($stageTotals)
));
ui_card_close();

ui_card_open(['title' => 'Approval Stages Overview', 'icon' => 'bi-table', 'flush' => true]);
if (empty($analytics)) {
    echo '<div class="ui-card__pad">';
    ui_empty(
        $analyticsUnavailable
            ? 'Stage analytics are unavailable: the approval queue table is not provisioned in this environment.'
            : 'No approval data available.',
        'bi-info-circle'
    );
    echo '</div>';
} else {
    ui_table_toolbar('approval-analytics-table', [
        'columns'     => true,
        'export'      => true,
        'placeholder' => 'Search stages…',
    ]);
    ui_table_open('approval-analytics-table', [
        'paginate' => true,
        'perPage'  => 12,
        'label'    => 'Approval stages overview',
    ]);
    ?>
    <thead>
      <tr>
        <th scope="col">Stage</th>
        <th scope="col">Status</th>
        <th scope="col" class="ui-num">Count</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($analytics as $row): $status = (string)$row['status']; ?>
      <tr>
        <th scope="row"><?= htmlspecialchars($stageNames[$row['stage_order']] ?? (string)$row['stage_order']) ?></th>
        <td><?= ui_badge(ucfirst($status), $statusVariants[$status] ?? 'neutral') ?></td>
        <td class="ui-num ui-num--strong"><?= (int)$row['count'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php
    ui_table_close('approval-analytics-table');
}
ui_card_close();

echo '</div>';
?>
</div>
<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
