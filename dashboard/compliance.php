<?php
$REQUIRE_PERMISSION = 'view_compliance';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';

// Fetch compliance metrics
$metricsQuery = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT pr.request_id) as total_requests,
        COUNT(DISTINCT CASE WHEN ca.status = 'approved' THEN pr.request_id END) as compliant,
        COUNT(DISTINCT CASE WHEN ca.status = 'rejected' THEN pr.request_id END) as non_compliant,
        COUNT(DISTINCT CASE WHEN ca.status = 'pending' THEN pr.request_id END) as pending_approval
    FROM procurement_requests pr
    LEFT JOIN compliance_approvals ca ON pr.request_id = ca.entity_id AND ca.entity_type = 'procurement_request'
");
$metricsQuery->execute();
$metrics = $metricsQuery->fetch(PDO::FETCH_ASSOC);

// Fetch compliance records with filters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

$whereConditions = [];
$params = [];

if (isset($_GET['status']) && $_GET['status'] !== '') {
    $whereConditions[] = "ca.status = ?";
    $params[] = $_GET['status'];
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

$countQuery = $pdo->prepare("
    SELECT COUNT(*) as total FROM compliance_approvals ca
    JOIN procurement_requests pr ON ca.entity_id = pr.request_id
    $whereClause
");
$countQuery->execute($params);
$totalRecords = $countQuery->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalRecords / $perPage);

$dataQuery = $pdo->prepare("
    SELECT 
        ca.id,
        ca.entity_id,
        ca.approval_body,
        ca.status,
        ca.created_at,
        ca.updated_at,
        pr.description as title,
        pr.status as request_status
    FROM compliance_approvals ca
    JOIN procurement_requests pr ON ca.entity_id = pr.request_id
    $whereClause
    ORDER BY ca.created_at DESC
    LIMIT ? OFFSET ?
");
$dataQuery->bindValue(1, $perPage, PDO::PARAM_INT);
$dataQuery->bindValue(2, $offset, PDO::PARAM_INT);
foreach ($params as $i => $param) {
    $dataQuery->bindValue($i + 3, $param);
}
$dataQuery->execute();
$records = $dataQuery->fetchAll(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/ui.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/pagination.php';

$statusVariants = ['approved' => 'success', 'rejected' => 'danger', 'pending' => 'warning'];
$activeStatus   = isset($_GET['status']) ? (string)$_GET['status'] : '';
?>
<div class="ui-page">
<?php
ui_page_header([
    'title'       => 'Compliance Dashboard',
    'subtitle'    => 'Approval-body sign-off status across procurement requests',
    'icon'        => 'bi-shield-check',
    'breadcrumbs' => [
        ['label' => 'Home', 'href' => '/dashboard/index.php'],
        ['label' => 'Compliance'],
    ],
]);

ui_kpi_grid([
    ['label' => 'Total Requests', 'value' => number_format((int)$metrics['total_requests']), 'icon' => 'bi-journal-text', 'variant' => 'blue'],
    ['label' => 'Compliant',      'value' => number_format((int)$metrics['compliant']),      'icon' => 'bi-patch-check', 'variant' => 'green'],
    ['label' => 'Non-Compliant',  'value' => number_format((int)$metrics['non_compliant']),  'icon' => 'bi-x-octagon',   'variant' => 'red'],
    ['label' => 'Pending',        'value' => number_format((int)$metrics['pending_approval']), 'icon' => 'bi-hourglass-split', 'variant' => 'amber'],
]);
?>

<details class="ui-filter-panel" data-ui-remember="compliance-filters"<?= $activeStatus !== '' ? ' open' : '' ?>>
  <summary><i class="bi bi-funnel-fill" aria-hidden="true"></i> Filters<?= $activeStatus !== '' ? ' <span class="ui-badge ui-badge--info">active</span>' : '' ?></summary>
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-md-3 col-sm-6">
      <label class="form-label small fw-bold text-muted" for="compliance-status">Status</label>
      <select name="status" id="compliance-status" class="form-select form-select-sm">
        <option value="">All</option>
        <option value="pending"  <?= $activeStatus === 'pending'  ? 'selected' : '' ?>>Pending</option>
        <option value="approved" <?= $activeStatus === 'approved' ? 'selected' : '' ?>>Approved</option>
        <option value="rejected" <?= $activeStatus === 'rejected' ? 'selected' : '' ?>>Rejected</option>
      </select>
    </div>
    <div class="col-md-4 col-sm-6 d-flex gap-2">
      <button type="submit" class="ui-action ui-action--primary"><i class="bi bi-funnel" aria-hidden="true"></i><span>Filter</span></button>
      <a class="ui-action" href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i><span>Reset</span></a>
    </div>
  </form>
</details>

<?php
ui_card_open([
    'title' => 'Approval Records',
    'icon'  => 'bi-journal-check',
    'flush' => true,
    'tools' => ui_badge(number_format((int)$totalRecords) . ' records', 'info'),
]);

ui_table_toolbar('compliance-records-table', [
    'columns'     => true,
    'export'      => true,
    'search'      => false,
]);
/* Server-side pagination is authoritative, so the client-side pager stays off. */
ui_table_open('compliance-records-table', [
    'paginate' => false,
    'label'    => 'Compliance approval records',
]);
?>
  <thead>
    <tr>
      <th scope="col">Request ID</th>
      <th scope="col">Title</th>
      <th scope="col">Approval Body</th>
      <th scope="col">Status</th>
      <th scope="col">Created</th>
      <th scope="col">Updated</th>
      <th scope="col">Action</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($records)): ?>
    <tr><td colspan="7" class="ui-empty">No compliance records found.</td></tr>
  <?php else: foreach ($records as $record): $status = (string)$record['status']; ?>
    <tr>
      <th scope="row"><?= htmlspecialchars((string)$record['entity_id']) ?></th>
      <td class="ui-cell-wrap"><?= htmlspecialchars((string)$record['title']) ?></td>
      <td><?= htmlspecialchars((string)$record['approval_body']) ?></td>
      <td><?= ui_badge(ucfirst($status), $statusVariants[$status] ?? 'neutral') ?></td>
      <td class="ui-nowrap ui-muted-cell"><?= htmlspecialchars(date('d M Y', strtotime((string)$record['created_at']))) ?></td>
      <td class="ui-nowrap ui-muted-cell"><?= htmlspecialchars(date('d M Y', strtotime((string)$record['updated_at']))) ?></td>
      <td>
        <a class="ui-action ui-action--sm ui-action--primary" href="/views/compliance-detail.php?id=<?= htmlspecialchars((string)$record['id']) ?>">
          <i class="bi bi-eye" aria-hidden="true"></i><span>View</span>
        </a>
      </td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
<?php
ui_table_close('compliance-records-table', false);

ob_start();
renderShowingInfo($page, $perPage, (int)$totalRecords);
renderPagination((int)$totalRecords, $perPage, $page, array_filter(['status' => $activeStatus]), 'page');
ui_card_close(ob_get_clean());
?>
</div>
<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
