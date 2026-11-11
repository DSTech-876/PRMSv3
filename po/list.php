<?php
$REQUIRE_PERMISSION = 'view_purchase_orders';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/pagination.php";

/* ================================
   Filters
================================ */
$where = [];
$params = [];

if (!empty($_GET['q'])) {
    $where[] = "(
        po.po_number LIKE :q
        OR c.commitment_number LIKE :q
        OR pr.request_number LIKE :q
    )";
    $params[':q'] = '%'.$_GET['q'].'%';
}

if (!empty($_GET['status'])) {
    $where[] = "po.status = :status";
    $params[':status'] = $_GET['status'];
}

if (!empty($_GET['from'])) {
    $where[] = "po.po_date >= :from";
    $params[':from'] = $_GET['from'];
}

if (!empty($_GET['to'])) {
    $where[] = "po.po_date <= :to";
    $params[':to'] = $_GET['to'];
}

$whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';

/* ================================
   Pagination params
================================ */
extract(getPaginationParams(20));

/* ================================
   Data query (PAGINATED)
================================ */
$sql = "
    SELECT
        po.po_id,
        po.po_number,
        po.po_date,
        po.po_total,
        po.status,
        c.commitment_number,
        pr.request_id,
        pr.request_number,
        pr.currency,
        COALESCE(SUM(i.invoice_amount), 0) AS total_invoiced
    FROM purchase_orders po
    JOIN commitments c ON po.commitment_id = c.commitment_id
    JOIN procurement_requests pr ON c.request_id = pr.request_id
    LEFT JOIN invoices i ON po.po_id = i.po_id
    $whereSQL
    GROUP BY po.po_id
    ORDER BY po.po_date DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);

foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}

$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================================
   Count query (FOR PAGINATION)
================================ */
$countSql = "
    SELECT COUNT(DISTINCT po.po_id)
    FROM purchase_orders po
    JOIN commitments c ON po.commitment_id = c.commitment_id
    JOIN procurement_requests pr ON c.request_id = pr.request_id
    LEFT JOIN invoices i ON po.po_id = i.po_id
    $whereSQL
";

$countStmt = $pdo->prepare($countSql);

foreach ($params as $k => $v) {
    $countStmt->bindValue($k, $v);
}

$countStmt->execute();
$totalRows = (int)$countStmt->fetchColumn();

/* ================================
   Statistics
================================ */
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT po.po_id) as total,
        SUM(CASE WHEN po.status = 'Open' THEN 1 ELSE 0 END) as open,
        SUM(po.po_total) as total_value,
        SUM(CASE WHEN po.status = 'Closed' THEN 1 ELSE 0 END) as closed
    FROM purchase_orders po
    JOIN commitments c ON po.commitment_id = c.commitment_id
    JOIN procurement_requests pr ON c.request_id = pr.request_id
");
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

/* ================================
   Render page
================================ */
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/helper.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/header.php";
?>

<style>
    .section-title {
        font-weight: 700;
        color: #1a1a1a;
        margin-bottom: 1.5rem;
    }
    
    .card {
        transition: all 0.3s ease;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }
    
    .card:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        transform: translateY(-2px);
    }
    
    .form-control, .form-select {
        transition: all 0.2s ease;
        box-shadow: none !important;
    }
    
    .form-control:focus, .form-select:focus {
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1) !important;
    }
    
    .btn {
        transition: all 0.2s ease;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border: none;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    }
    
    table tbody tr:hover td {
        background-color: #f9f9f9 !important;
    }
</style>

<div class="mb-5">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h2 class="mb-1" style="font-weight: 700; color: #1a1a1a;">🧾 Purchase Order Register</h2>
            <p class="text-muted mb-0">Manage and track all purchase orders</p>
        </div>
        <a href="/po/add.php" class="btn btn-primary" style="border-radius: 6px; font-weight: 600;">
            <i class="bi bi-plus-lg me-2"></i>Create PO
        </a>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-6 col-lg-3">
            <div class="card border-0 h-100" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="mb-1 small" style="opacity: 0.9;">Total POs</p>
                            <h4 class="mb-0" style="font-weight: 700; font-size: 2rem;"><?= (int)$stats['total'] ?></h4>
                        </div>
                        <div style="font-size: 2rem; opacity: 0.3;">📊</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card border-0 h-100" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="mb-1 small" style="opacity: 0.9;">Open POs</p>
                            <h4 class="mb-0" style="font-weight: 700; font-size: 2rem;"><?= (int)$stats['open'] ?></h4>
                        </div>
                        <div style="font-size: 2rem; opacity: 0.3;">⏳</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card border-0 h-100" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="mb-1 small" style="opacity: 0.9;">Closed POs</p>
                            <h4 class="mb-0" style="font-weight: 700; font-size: 2rem;"><?= (int)$stats['closed'] ?></h4>
                        </div>
                        <div style="font-size: 2rem; opacity: 0.3;">✅</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card border-0 h-100" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="mb-1 small" style="opacity: 0.9;">Total Value</p>
                            <h4 class="mb-0" style="font-weight: 700; font-size: 1.5rem;"><?= money($stats['total_value'] ?? 0) ?></h4>
                        </div>
                        <div style="font-size: 2rem; opacity: 0.3;">💰</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Advanced Filter Section -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom">
        <div class="d-flex align-items-center gap-2 py-2">
            <i class="bi bi-funnel" style="font-size: 1.2rem; color: #667eea;"></i>
            <h6 class="mb-0" style="font-weight: 600; color: #1a1a1a;">Search & Filter</h6>
        </div>
    </div>
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-4 col-sm-6">
                <label class="form-label small text-muted" style="font-weight: 600;">Search</label>
                <input type="text"
                       name="q"
                       value="<?= htmlspecialchars($_GET['q'] ?? '') ?>"
                       placeholder="PO #, Commitment #, Request #"
                       class="form-control"
                       style="border-radius: 6px; border: 1px solid #e0e0e0;">
            </div>

            <div class="col-md-2 col-sm-6">
                <label class="form-label small text-muted" style="font-weight: 600;">Status</label>
                <select name="status" class="form-select" style="border-radius: 6px; border: 1px solid #e0e0e0;">
                    <option value="">All Status</option>
                    <?php foreach (['Open','Closed','Cancelled'] as $s): ?>
                        <option value="<?= $s ?>"
                            <?= ($_GET['status'] ?? '') === $s ? 'selected' : '' ?>>
                            <?= $s ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2 col-sm-6">
                <label class="form-label small text-muted" style="font-weight: 600;">From Date</label>
                <input type="date" name="from" value="<?= $_GET['from'] ?? '' ?>" class="form-control" style="border-radius: 6px; border: 1px solid #e0e0e0;">
            </div>

            <div class="col-md-2 col-sm-6">
                <label class="form-label small text-muted" style="font-weight: 600;">To Date</label>
                <input type="date" name="to" value="<?= $_GET['to'] ?? '' ?>" class="form-control" style="border-radius: 6px; border: 1px solid #e0e0e0;">
            </div>

            <div class="col-md-2 d-flex gap-2 align-items-end">
                <button type="submit" class="btn btn-primary flex-grow-1" style="border-radius: 6px; font-weight: 600;">
                    <i class="bi bi-search me-2"></i>Filter
                </button>
                <a href="/po/list.php" class="btn btn-outline-secondary" style="border-radius: 6px; font-weight: 600;">
                    <i class="bi bi-arrow-clockwise"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<div class="alert alert-info border-0 mb-4" style="border-radius: 6px; background-color: #e3f2fd; color: #1565c0;">
    <div class="d-flex align-items-center gap-2">
        <i class="bi bi-info-circle" style="font-size: 1.2rem;"></i>
        <small><strong>Showing</strong> <?= ($offset + 1) ?> - <?= min($offset + $perPage, $totalRows) ?> of <?= number_format($totalRows) ?> POs (Page <?= $page ?>/<?= max(1, ceil($totalRows / $perPage)) ?>)</small>
    </div>
</div>

<?php if (empty($rows)): ?>
<div class="alert alert-info border-0 mb-4" style="border-radius: 6px;" role="alert">
    <div class="d-flex align-items-center gap-3">
        <span style="font-size: 3rem;">📭</span>
        <div>
            <strong style="color: #1a1a1a;">No Purchase Orders Found</strong>
            <p class="mb-0" style="color: #666; font-size: 0.9rem;">Try adjusting your filters or <a href="/po/add.php" style="color: #667eea; text-decoration: none; font-weight: 600;">create a new PO</a></p>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
    <div style="overflow: auto;">
        <table class="table table-hover mb-0" style="border-collapse: collapse;">
            <thead style="background-color: #f8f9fa; border-bottom: 2px solid #e0e0e0;">
                <tr>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none;">PO Number</th>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none;">Date</th>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none;">Request</th>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none;">Commitment</th>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none; text-align: right;">PO Total</th>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none; text-align: right;">Invoiced</th>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none;">Status</th>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none; text-align: center; width: 100px;">Actions</th>
                </tr>
            </thead>
            <tbody>

            <?php foreach ($rows as $po): ?>
            <?php
                $rowBgColor = 'white';
                if ($po['status'] === 'Closed') {
                    $rowBgColor = '#e8f5e9';
                } elseif ($po['status'] === 'Cancelled') {
                    $rowBgColor = '#ffebee';
                }
            ?>
            <tr style="background-color: <?= $rowBgColor ?>; border-bottom: 1px solid #e0e0e0; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor='#f5f5f5'" onmouseout="this.style.backgroundColor='<?= $rowBgColor ?>'">
                <td style="padding: 1rem; border: none; vertical-align: middle;">
                    <code style="background-color: #f0f0f0; padding: 0.4rem 0.8rem; border-radius: 4px; color: #1a1a1a; font-weight: 600; font-size: 0.9rem;"><?= htmlspecialchars($po['po_number']) ?></code>
                </td>
                <td style="padding: 1rem; border: none; vertical-align: middle;">
                    <small style="color: #666; font-weight: 500;"><?= date('d M Y', strtotime($po['po_date'])) ?></small>
                </td>
                <td style="padding: 1rem; border: none; vertical-align: middle;">
                    <a href="/procurement/view.php?id=<?= (int)$po['request_id'] ?>" style="color: #667eea; text-decoration: none; font-weight: 600;">
                        <?= htmlspecialchars($po['request_number']) ?>
                    </a>
                </td>
                <td style="padding: 1rem; border: none; vertical-align: middle;">
                    <small style="color: #999;"><?= htmlspecialchars($po['commitment_number']) ?></small>
                </td>
                <td style="padding: 1rem; border: none; vertical-align: middle; text-align: right; font-weight: 600; color: #1a1a1a;"><?= money((float)$po['po_total'], $po['currency'] ?? null) ?></td>
                <td style="padding: 1rem; border: none; vertical-align: middle; text-align: right;">
                    <span style="display: inline-block; background-color: #e3f2fd; color: #1565c0; padding: 0.4rem 0.8rem; border-radius: 6px; font-weight: 600; font-size: 0.85rem;"><?= money((float)$po['total_invoiced'], $po['currency'] ?? null) ?></span>
                </td>
                <td style="padding: 1rem; border: none; vertical-align: middle;">
                    <?php
                        $icon = match ($po['status']) {
                            'Closed'    => '✅',
                            'Cancelled' => '❌',
                            default     => '⏳'
                        };
                        $badgeBgColor = match ($po['status']) {
                            'Closed'    => '#e8f5e9',
                            'Cancelled' => '#ffebee',
                            default     => '#fff3cd'
                        };
                        $badgeTextColor = match ($po['status']) {
                            'Closed'    => '#2e7d32',
                            'Cancelled' => '#c62828',
                            default     => '#b09500'
                        };
                    ?>
                    <span style="display: inline-block; background-color: <?= $badgeBgColor ?>; color: <?= $badgeTextColor ?>; padding: 0.4rem 0.8rem; border-radius: 6px; font-size: 0.85rem; font-weight: 600;">
                        <?= $icon ?> <?= htmlspecialchars($po['status']) ?>
                    </span>
                </td>
                <td style="padding: 1rem; border: none; vertical-align: middle; text-align: center;">
                    <div style="display: flex; gap: 0.25rem; justify-content: center;">
                        <a href="/po/view.php?po_id=<?= (int)$po['po_id'] ?>"
                           style="display: inline-block; padding: 0.4rem 0.8rem; background-color: #e8eaf6; color: #3f51b5; text-decoration: none; border-radius: 4px; font-weight: 600; border: none; cursor: pointer;" title="View PO">👁️</a>
                        <a href="/po/edit.php?id=<?= (int)$po['po_id'] ?>"
                           style="display: inline-block; padding: 0.4rem 0.8rem; background-color: #fff3cd; color: #b09500; text-decoration: none; border-radius: 4px; font-weight: 600; border: none; cursor: pointer;" title="Edit">✏️</a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>

            </tbody>
        </table>
    </div>

    <div style="background-color: #f8f9fa; padding: 1.5rem; border-top: 1px solid #e0e0e0; border-radius: 0 0 8px 8px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <small style="color: #666; font-weight: 500;">
                Showing <strong><?= count($rows) ?></strong> records 
                <span style="color: #999;">•</span> 
                Page <strong><?= $page ?></strong> of <strong><?= max(1, ceil($totalRows / $perPage)) ?></strong>
            </small>
        </div>
        <small style="color: #999;">
            Total: <strong style="color: #1a1a1a;"><?= number_format((int)$stats['total']) ?> POs</strong>
        </small>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($rows)): ?>
<!-- Modern Pagination -->
<div class="mt-4 mb-4">
    <?php
    $queryParams = $_GET;
    unset($queryParams['page']);

    renderPagination(
        $totalRows,
        $perPage,
        $page,
        $queryParams
    );
    ?>
</div>
<?php endif; ?>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>
