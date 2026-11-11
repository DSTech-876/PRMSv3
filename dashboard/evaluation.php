<?php
$REQUIRE_PERMISSION = 'view_requests';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';

$rfqs = []; // ALWAYS initialize

$stmt = $pdo->prepare("
        SELECT r.rfq_id, r.rfq_number, pr.request_number, pr.status AS pr_status, r.status AS rfq_status
        FROM rfqs r
        JOIN procurement_requests pr ON r.request_id = pr.request_id
        JOIN rfq_evaluation_committee ec ON r.rfq_id = ec.rfq_id
        WHERE (pr.status = 'EVALUATION_STAGE'
               OR (r.status IN ('PUBLISHED','EVALUATION') AND pr.status NOT IN ('AWARDED','COMPLETED')))
            AND r.status <> 'AWARDED'
            AND ec.user_id = ?
        ORDER BY r.created_at ASC
");
$stmt->execute([$_SESSION['user_id']]);
$rfqs = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/ui.php';

// Pre-compute compliance per RFQ so the markup stays presentational.
$committeeStmt = $pdo->prepare("SELECT COUNT(*) FROM rfq_evaluation_committee WHERE rfq_id = ?");
$reportStmt    = $pdo->prepare("SELECT COUNT(*) FROM rfq_evaluation_reports WHERE rfq_id = ?");
$compliantCount = 0;
foreach ($rfqs as $i => $r) {
    $committeeStmt->execute([$r['rfq_id']]);
    $committeeCount = (int)$committeeStmt->fetchColumn();
    $reportStmt->execute([$r['rfq_id']]);
    $reportCount = (int)$reportStmt->fetchColumn();

    $rfqs[$i]['_committee_count'] = $committeeCount;
    $rfqs[$i]['_report_count']    = $reportCount;
    $rfqs[$i]['_compliant']       = ($committeeCount >= 3 && $reportCount > 0);
    if ($rfqs[$i]['_compliant']) {
        $compliantCount++;
    }
}
?>
<div class="ui-page">
<?php
ui_page_header([
    'title'       => 'Evaluation Committee Dashboard',
    'subtitle'    => 'RFQs assigned to you for technical and financial evaluation',
    'icon'        => 'bi-clipboard-data',
    'breadcrumbs' => [
        ['label' => 'Home', 'href' => '/dashboard/index.php'],
        ['label' => 'Evaluation Committee'],
    ],
    'actions' => [
        ['label' => 'All RFQs', 'href' => '/rfq/list.php', 'icon' => 'bi-list-ul'],
        ['label' => 'Refresh', 'href' => '/dashboard/evaluation.php', 'icon' => 'bi-arrow-clockwise'],
    ],
]);

ui_kpi_grid([
    [
        'label'   => 'RFQs Pending Evaluation',
        'value'   => (string)count($rfqs),
        'icon'    => 'bi-hourglass-split',
        'variant' => 'blue',
    ],
    [
        'label'   => 'Compliance Ready',
        'value'   => (string)$compliantCount,
        'meta'    => '3+ members and a report filed',
        'icon'    => 'bi-patch-check',
        'variant' => 'green',
    ],
    [
        'label'   => 'Needs Attention',
        'value'   => (string)(count($rfqs) - $compliantCount),
        'meta'    => 'Committee or report incomplete',
        'icon'    => 'bi-exclamation-triangle',
        'variant' => (count($rfqs) - $compliantCount) > 0 ? 'red' : 'slate',
    ],
]);

ui_tabs('evaluation', [
    ['id' => 'rfqs',    'label' => 'RFQs Pending Evaluation', 'icon' => 'bi-hourglass-split', 'count' => count($rfqs)],
    ['id' => 'actions', 'label' => 'Pending Actions',         'icon' => 'bi-check2-square'],
    ['id' => 'insights','label' => 'Insights',                'icon' => 'bi-graph-up'],
]);

ui_tabpanel_open('evaluation', 'rfqs', true);
ui_card_open(['title' => 'RFQs Pending Evaluation', 'icon' => 'bi-hourglass-split', 'flush' => true]);
if (empty($rfqs)) {
    echo '<div class="ui-card__pad">';
    ui_empty('No RFQs in evaluation stage.', 'bi-check2-circle');
    echo '</div>';
} else {
    ui_table_toolbar('evaluation-rfqs-table', [
        'columns'     => true,
        'export'      => true,
        'placeholder' => 'Search RFQs…',
    ]);
    ui_table_open('evaluation-rfqs-table', [
        'paginate' => true,
        'perPage'  => 10,
        'label'    => 'RFQs pending evaluation',
    ]);
    ?>
    <thead>
      <tr>
        <th scope="col">RFQ #</th>
        <th scope="col">Request #</th>
        <th scope="col">RFQ Status</th>
        <th scope="col" class="ui-num">Committee</th>
        <th scope="col" class="ui-num">Reports</th>
        <th scope="col">Compliance</th>
        <th scope="col">Action</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rfqs as $r): ?>
      <tr>
        <th scope="row"><?= htmlspecialchars($r['rfq_number']) ?></th>
        <td><?= htmlspecialchars($r['request_number']) ?></td>
        <td><?= ui_status_badge($r['rfq_status']) ?></td>
        <td class="ui-num"><?= (int)$r['_committee_count'] ?></td>
        <td class="ui-num"><?= (int)$r['_report_count'] ?></td>
        <td><?= $r['_compliant'] ? ui_badge('Compliant', 'success') : ui_badge('Non-Compliant', 'danger') ?></td>
        <td>
          <a class="ui-action ui-action--sm ui-action--primary" href="/rfq/view.php?id=<?= (int)$r['rfq_id'] ?>">
            <i class="bi bi-clipboard-check" aria-hidden="true"></i><span>Evaluate</span>
          </a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php
    ui_table_close('evaluation-rfqs-table');
}
ui_card_close();
ui_tabpanel_close();

ui_tabpanel_open('evaluation', 'actions');
include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/pending_actions.php';
ui_tabpanel_close();

ui_tabpanel_open('evaluation', 'insights');
echo '<div class="ui-grid ui-grid--2">';
include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/top_vendors.php';
include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/pipeline.php';
echo '</div>';
ui_tabpanel_close();
?>
</div>
<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
