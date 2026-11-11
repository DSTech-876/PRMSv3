<?php
$REQUIRE_PERMISSION = 'management_dashboard';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';

$filters = [];
$whereClauses = [];
$params = [];
$perPage = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $perPage;

// Filtering logic
if (!empty($_GET['status'])) {
    $filters['status'] = $_GET['status'];
    $whereClauses[] = 'pr.status = :status';
    $params[':status'] = $_GET['status'];
}
if (!empty($_GET['stage'])) {
    $filters['stage'] = $_GET['stage'];
    $whereClauses[] = 'pr.procurement_method = :stage';
    $params[':stage'] = $_GET['stage'];
}
if (!empty($_GET['search'])) {
    $filters['search'] = $_GET['search'];
    $whereClauses[] = '(pr.description LIKE :search OR u.full_name LIKE :search)';
    $params[':search'] = '%'.$_GET['search'].'%';
}

$where = empty($whereClauses) ? '' : 'WHERE '.implode(' AND ', $whereClauses);

$sql = "SELECT pr.*, u.full_name AS requestor_name FROM procurement_requests pr LEFT JOIN users u ON pr.created_by = u.user_id $where ORDER BY pr.created_at DESC LIMIT :perPage OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':perPage', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$countSql = "SELECT COUNT(*) FROM procurement_requests pr LEFT JOIN users u ON pr.created_by = u.user_id $where";
$countStmt = $pdo->prepare($countSql);
foreach ($params as $key => $val) {
    $countStmt->bindValue($key, $val);
}
$countStmt->execute();
$totalRows = $countStmt->fetchColumn();

require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/ui.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/pagination.php';

$statusOptions = [
    'DRAFT' => 'Draft', 'SUBMITTED' => 'Submitted', 'HOD_APPROVED' => 'HOD Approved',
    'FUNDS_VERIFIED' => 'Funds Verified', 'DIRECTOR_APPROVED' => 'Director Approved',
    'PROCUREMENT_STAGE' => 'Procurement Stage', 'EVALUATION_STAGE' => 'Evaluation Stage',
    'COMMITTEE_RECOMMENDED' => 'Committee Recommended', 'GC_APPROVED' => 'GC Approved',
    'AWARDED' => 'Awarded', 'COMPLETED' => 'Completed', 'DECLINED' => 'Declined',
];
$methodOptions = [
    'SINGLE_SOURCE' => 'Single Source',
    'RESTRICTED_BIDDING' => 'Restricted Bidding',
    'NATIONAL_COMPETITIVE' => 'National Competitive',
    'INTERNATIONAL_COMPETITIVE' => 'International Competitive',
];
$filtersActive = !empty($filters);
?>
<div class="ui-page">
<?php
ui_page_header([
    'title'       => 'Management Approval Queue',
    'subtitle'    => number_format((int)$totalRows) . ' procurement requests matching the current filters',
    'icon'        => 'bi-list-check',
    'breadcrumbs' => [
        ['label' => 'Home', 'href' => '/dashboard/index.php'],
        ['label' => 'Management'],
        ['label' => 'Approval Queue'],
    ],
    'actions' => [
        ['label' => 'Metrics', 'href' => '/dashboard/metrics.php', 'icon' => 'bi-graph-up'],
        ['label' => 'Monthly Trend', 'href' => '/dashboard/monthly.php', 'icon' => 'bi-bar-chart-line'],
    ],
]);
?>

<details class="ui-filter-panel" data-ui-remember="management-filters"<?= $filtersActive ? ' open' : '' ?>>
  <summary><i class="bi bi-funnel-fill" aria-hidden="true"></i> Search &amp; Filter<?= $filtersActive ? ' <span class="ui-badge ui-badge--info">active</span>' : '' ?></summary>
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md-4 col-sm-6">
      <label class="form-label small fw-bold text-muted" for="mg-search">Search</label>
      <input type="search" id="mg-search" name="search" class="form-control form-control-sm"
             placeholder="Title or requestor" value="<?= htmlspecialchars($filters['search'] ?? '') ?>">
    </div>
    <div class="col-md-3 col-sm-6">
      <label class="form-label small fw-bold text-muted" for="mg-status">Status</label>
      <select name="status" id="mg-status" class="form-select form-select-sm">
        <option value="">All Status</option>
        <?php foreach ($statusOptions as $value => $label): ?>
          <option value="<?= $value ?>" <?= (($filters['status'] ?? '') === $value) ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3 col-sm-6">
      <label class="form-label small fw-bold text-muted" for="mg-stage">Procurement Method</label>
      <select name="stage" id="mg-stage" class="form-select form-select-sm">
        <option value="">All Methods</option>
        <?php foreach ($methodOptions as $value => $label): ?>
          <option value="<?= $value ?>" <?= (($filters['stage'] ?? '') === $value) ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2 col-sm-6 d-flex gap-2">
      <button type="submit" class="ui-action ui-action--primary"><i class="bi bi-funnel" aria-hidden="true"></i><span>Apply</span></button>
      <a class="ui-action" href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i><span>Reset</span></a>
    </div>
  </form>
</details>

<?php
ui_tabs('management', [
    ['id' => 'queue',    'label' => 'Approval Queue', 'icon' => 'bi-list-check', 'count' => (int)$totalRows],
    ['id' => 'analytics','label' => 'Analytics',      'icon' => 'bi-graph-up'],
    ['id' => 'activity', 'label' => 'Recent Activity','icon' => 'bi-clock-history'],
]);

ui_tabpanel_open('management', 'queue', true);
ui_card_open(['title' => 'Procurement Requests', 'icon' => 'bi-journal-text', 'flush' => true]);
ui_table_toolbar('management-queue-table', ['columns' => true, 'export' => true, 'search' => false]);
/* Server-side pagination is authoritative, so the client-side pager stays off. */
ui_table_open('management-queue-table', [
    'paginate' => false,
    'virtual'  => true,
    'label'    => 'Management approval queue',
]);
?>
  <thead>
    <tr>
      <th scope="col" class="ui-num">#</th>
      <th scope="col">Title</th>
      <th scope="col">Requestor</th>
      <th scope="col">Method</th>
      <th scope="col">Status</th>
      <th scope="col" class="ui-num">Amount</th>
      <th scope="col">Created</th>
      <th scope="col">Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($rows)): ?>
    <tr><td colspan="8" class="ui-empty">No procurement requests found for the selected filters.</td></tr>
  <?php else: foreach ($rows as $i => $row): ?>
    <tr>
      <td class="ui-num"><?= ($offset + $i + 1) ?></td>
      <th scope="row" class="ui-cell-wrap"><?= htmlspecialchars($row['request_number'] . ' — ' . ($row['description'] ?: 'No description')) ?></th>
      <td><?= htmlspecialchars((string)$row['requestor_name']) ?></td>
      <td class="ui-nowrap"><?= htmlspecialchars($methodOptions[$row['procurement_method'] ?? ''] ?? ($row['procurement_method'] ?? 'N/A')) ?></td>
      <td><?= ui_status_badge($row['status'], $statusOptions[strtoupper((string)$row['status'])] ?? null) ?></td>
      <td class="ui-num ui-num--strong"><?= money($row['estimated_value']) ?></td>
      <td class="ui-nowrap ui-muted-cell"><?= date('d M Y', strtotime((string)$row['created_at'])) ?></td>
      <td>
        <a class="ui-action ui-action--sm ui-action--primary" href="/procurement/view.php?id=<?= (int)$row['request_id'] ?>">
          <i class="bi bi-eye" aria-hidden="true"></i><span>View</span>
        </a>
      </td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
<?php
ui_table_close('management-queue-table', false);

ob_start();
renderShowingInfo($page, $perPage, (int)$totalRows);
renderPagination((int)$totalRows, $perPage, $page, $filters, 'page');
ui_card_close(ob_get_clean());
ui_tabpanel_close();

ui_tabpanel_open('management', 'analytics');
echo '<div class="ui-grid ui-grid--2">';
include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/pipeline.php';
include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/branch_summary.php';
include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/commitment_utilization.php';
include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/monthly_trend.php';
echo '</div>';
ui_tabpanel_close();

ui_tabpanel_open('management', 'activity');
include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/recent_activity.php';
ui_tabpanel_close();
?>
</div>
<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
