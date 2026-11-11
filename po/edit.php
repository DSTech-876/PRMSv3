<?php
$REQUIRE_PERMISSION = 'edit_purchase_order';
require_once $_SERVER['DOCUMENT_ROOT']."/config/page_guard.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";

/* ================================
   Get PO ID (from either 'id' or 'po_id')
================================ */
$po_id = $_GET['id'] ?? $_GET['po_id'] ?? null;

if (!$po_id || !ctype_digit((string)$po_id)) {
    $error = "Invalid or missing PO ID.";
} else {
    $po_id = (int)$po_id;

    /* Fetch PO */
    $stmt = $pdo->prepare("
        SELECT po.*, c.commitment_number, c.commitment_total
        FROM purchase_orders po
        JOIN commitments c ON po.commitment_id = c.commitment_id
        WHERE po.po_id = ?
    ");
    $stmt->execute([$po_id]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$po) {
        $error = "Purchase Order not found.";
    } elseif ($po['status'] !== 'Open') {
        $error = "This PO cannot be edited (status: {$po['status']}).";
    } else {
        /* Check if PO is fully approved via approval stages */
        $approvalCheck = $pdo->prepare("
            SELECT COUNT(*) as total,
                   SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) as approved
            FROM request_approvals
            WHERE entity_type='PO' AND entity_id=?
        ");
        $approvalCheck->execute([$po_id]);
        $appr = $approvalCheck->fetch(PDO::FETCH_ASSOC);
        // POs no longer require approval chain — treat as approved if no stages exist OR all stages approved
        $isFullyApproved = ((int)$appr['total'] === 0) || ((int)$appr['approved'] === (int)$appr['total']);

        if ($isFullyApproved && $po['po_type'] === 'ORIGINAL') {
            $error = "Approved original POs cannot be edited. Use Variation instead.";
        } elseif ($isFullyApproved && $po['po_type'] === 'ADJUSTMENT') {
            $error = "Approved adjustment POs cannot be edited.";
        }
    }
}

/* Handle update */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {

    $amount = trim($_POST['total_amount'] ?? '');
    $reason = trim($_POST['adjustment_reason'] ?? '');

    if (!$amount || $amount <= 0) {
        $form_error = "Total amount must be greater than 0.";
    } else {
        try {
        $stmt = $pdo->prepare("
            UPDATE purchase_orders
            SET po_total = ?
            WHERE po_id = ?
        ");

        if ($po['po_type'] === 'ADJUSTMENT') {
            $stmt = $pdo->prepare("
                UPDATE purchase_orders
                SET po_total = ?, adjustment_reason = ?
                WHERE po_id = ?
            ");
            $stmt->execute([$amount, $reason, $po_id]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE purchase_orders
                SET po_total = ?
                WHERE po_id = ?
            ");
            $stmt->execute([$amount, $po_id]);
        }

        /* Audit */
        $pdo->prepare("
            INSERT INTO audit_log
            (table_name, record_id, action, changed_by, change_date, notes)
            VALUES ('purchase_orders', ?, 'UPDATE', ?, NOW(), ?)
        ")->execute([
            $po_id,
            $_SESSION['user_id'],
            "PO updated: Amount changed to $amount"
        ]);

        header("Location: view.php?po_id=$po_id");
        exit;
        } catch (Throwable $e) {
            $form_error = extractDbMessage($e);
        }
    }
}

require_once $_SERVER['DOCUMENT_ROOT']."/includes/header.php";
?>

<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong>⚠️ Error:</strong> <?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<div class="mt-3">
    <a href="/po/list.php" class="btn btn-secondary">
        ← Back to PO List
    </a>
</div>
<?php else: ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="section-title">✏️ Edit Purchase Order</h3>
    <a href="/po/view.php?po_id=<?= (int)$po['po_id'] ?>" class="btn btn-secondary btn-sm">
        ← Back
    </a>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0">📋 PO Information</h5>
            </div>
            <div class="card-body">

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">PO Number</label>
                        <p class="h5 mb-0"><?= htmlspecialchars($po['po_number']) ?></p>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Commitment</label>
                        <p class="mb-0"><?= htmlspecialchars($po['commitment_number']) ?></p>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">PO Type</label>
                        <p class="mb-0">
                            <span class="badge bg-secondary"><?= htmlspecialchars($po['po_type']) ?></span>
                        </p>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Current Status</label>
                        <p class="mb-0">
                            <span class="badge bg-primary">🟢 <?= htmlspecialchars($po['status']) ?></span>
                        </p>
                    </div>
                </div>

                <hr>

                <form method="POST">

                    <div class="mb-3">
                        <label class="form-label fw-bold">PO Total Amount *</label>
                        <input type="number" step="0.01" name="total_amount"
                               value="<?= htmlspecialchars($po['po_total']) ?>"
                               class="form-control" required>
                        <small class="text-muted">Current amount: <?= money((float)$po['po_total'], $po['currency'] ?? null) ?></small>
                    </div>

                    <?php if ($po['po_type'] === 'ADJUSTMENT'): ?>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Adjustment Reason</label>
                        <textarea name="adjustment_reason" rows="3" class="form-control"><?= htmlspecialchars($po['adjustment_reason'] ?? '') ?></textarea>
                        <small class="text-muted">Explain the reason for this adjustment</small>
                    </div>
                    <?php endif; ?>

                    <?php if (isset($form_error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($form_error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex gap-2 pt-3">
                        <button type="submit" class="btn btn-success">
                            💾 Save Changes
                        </button>
                        <a href="/po/view.php?po_id=<?= (int)$po['po_id'] ?>" class="btn btn-secondary">
                            Cancel
                        </a>
                    </div>

                </form>

            </div>
        </div>
    </div>

    <!-- Sidebar Info -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm bg-light mb-3">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">📊 Commitment Details</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <small class="text-muted fw-bold">Commitment Total</small>
                    <p class="h6 mb-0"><?= money((float)$po['commitment_total'], $po['currency'] ?? null) ?></p>
                </div>

                <div class="mb-3">
                    <small class="text-muted fw-bold">Current PO Amount</small>
                    <p class="h6 mb-0"><?= money((float)$po['po_total'], $po['currency'] ?? null) ?></p>
                </div>

                <div class="mb-3">
                    <small class="text-muted fw-bold">Remaining Allocation</small>
                    <p class="h6 mb-0">
                        <?php $remaining = (float)$po['commitment_total'] - (float)$po['po_total']; ?>
                        <?= money(max(0, $remaining), $po['currency'] ?? null) ?>
                    </p>
                </div>

                <hr>

                <div class="alert alert-info small mb-0">
                    <strong>💡 Note:</strong> Any changes will be logged in the audit trail. Make sure the new amount does not exceed the commitment total.
                </div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php require_once $_SERVER['DOCUMENT_ROOT']."/includes/footer.php"; ?>
