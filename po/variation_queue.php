<?php
$REQUIRE_PERMISSION = 'approve_po_adjustment';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/helper.php";

/* ================================
   Fetch Pending Variations
================================ */
$stmt = $pdo->query("
    SELECT
        v.variation_id,
        v.po_id,
        v.variation_amount,
        v.requested_at,
        u.full_name AS requested_by,
        po.po_number,
        po.currency
    FROM po_variations v
    JOIN purchase_orders po ON v.po_id = po.po_id
    JOIN users u ON v.requested_by = u.user_id
    WHERE v.status = 'PENDING'
    ORDER BY v.requested_at ASC
");
$variations = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================================
   Calculate Statistics
================================ */
$totalVariations = count($variations);
$totalAmount = 0;
foreach ($variations as $v) {
    $totalAmount += (float)$v['variation_amount'];
}

require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/header.php";
?>

<style>
    .card {
        transition: all 0.3s ease;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }
    
    .card:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        transform: translateY(-2px);
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

<!-- ═══════════════════════════════════════════════════════
     PAGE HEADER
═══════════════════════════════════════════════════════ -->
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h2 class="mb-1" style="font-weight: 700; color: #1a1a1a;">⚙️ PO Variations Queue</h2>
        <p class="text-muted mb-0">Review and approve pending purchase order variations</p>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     KPI SUMMARY CARDS
═══════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 h-100" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="mb-1 small" style="opacity: 0.9;">Pending Variations</p>
                        <h4 class="mb-0" style="font-weight: 700; font-size: 2rem;"><?= $totalVariations ?></h4>
                    </div>
                    <div style="font-size: 2rem; opacity: 0.3;">⏳</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 h-100" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="mb-1 small" style="opacity: 0.9;">Total Amount</p>
                        <h4 class="mb-0" style="font-weight: 700; font-size: 1.75rem;"><?= money($totalAmount) ?></h4>
                    </div>
                    <div style="font-size: 2rem; opacity: 0.3;">💰</div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!$variations): ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body text-center py-5">
        <p style="color: #999; font-size: 1.1rem;">
            <span style="font-size: 2.5rem; display: block; margin-bottom: 1rem;">✨</span>
            <strong style="color: #1a1a1a; display: block; margin-bottom: 0.5rem;">All Clear!</strong>
            There are no pending PO variations awaiting approval.
        </p>
    </div>
</div>

<?php else: ?>

<!-- ═══════════════════════════════════════════════════════
     VARIATIONS TABLE
═══════════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm mb-4">
    <div style="overflow: auto;">
        <table class="table table-hover mb-0" style="border-collapse: collapse;">
            <thead style="background-color: #f8f9fa; border-bottom: 2px solid #e0e0e0;">
                <tr>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none;">PO Number</th>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none;">Requested By</th>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none; text-align: right;">Amount</th>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none;">Date Requested</th>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none; text-align: center; width: 100px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($variations as $v): ?>
                <tr style="border-bottom: 1px solid #e0e0e0;">
                    <td style="padding: 1rem; border: none;">
                        <a href="/po/view.php?id=<?= (int)$v['po_id'] ?>" class="text-decoration-none">
                            <strong style="color: #667eea;"><?= htmlspecialchars($v['po_number']) ?></strong>
                        </a>
                    </td>
                    <td style="padding: 1rem; border: none;">
                        <small class="text-muted">👤 <?= htmlspecialchars($v['requested_by']) ?></small>
                    </td>
                    <td style="padding: 1rem; border: none; text-align: right; font-weight: 600; color: #f5576c;">
                        <?= money($v['variation_amount'], $v['currency'] ?? null) ?>
                    </td>
                    <td style="padding: 1rem; border: none;">
                        <small class="text-muted">
                            <?= date('d M Y H:i', strtotime($v['requested_at'])) ?>
                        </small>
                    </td>
                    <td style="padding: 1rem; border: none; text-align: center;">
                        <a href="/po/variation_approve.php?id=<?= (int)$v['variation_id'] ?>"
                           class="btn btn-sm" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 4px; padding: 0.35rem 0.75rem;">
                            <i class="bi bi-check-circle"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div style="text-align: center; padding: 1rem; background-color: #f8f9fa; border-radius: 8px; border: 1px solid #e0e0e0;">
    <small style="color: #666; font-weight: 500;">
        📊 Total: <strong><?= count($variations) ?></strong> pending variation request<?= count($variations) !== 1 ? 's' : '' ?> | Amount: <strong><?= money($totalAmount) ?></strong>
    </small>
</div>
<?php endif; ?>

</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>
