<?php
$REQUIRE_PERMISSION = 'approve_po_excess';
require_once $_SERVER['DOCUMENT_ROOT']."/config/page_guard.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";

$po_id = (int)($_GET['id'] ?? 0);

if ($po_id <= 0) {
    pop("Invalid PO reference.", "/po/list.php");
    exit;
}

$user_id = $_SESSION['user_id'];

/* ==================================
   Fetch PO + Request
================================== */
$stmt = $pdo->prepare("
    SELECT po.po_number, po.po_total, c.request_id, pr.currency
    FROM purchase_orders po
    JOIN commitments c ON po.commitment_id = c.commitment_id
    JOIN procurement_requests pr ON c.request_id = pr.request_id
    WHERE po.po_id = ?
");
$stmt->execute([$po_id]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    pop("PO not found.", "/po/list.php");
    exit;
}

$exCurrency = normalizeCurrency($po['currency'] ?? 'JMD');

/* Check if warning exists */
$check = $pdo->prepare("
    SELECT po_total, status
    FROM po_warnings
    WHERE po_id = ?
      AND warning_type = 'PO_LIMIT_ATTEMPT'
");
$check->execute([$po_id]);
$warning = $check->fetch(PDO::FETCH_ASSOC);

if (!$warning) {
    pop("No PO excess warning found.", "/po/list.php");
    exit;
}

/* ===============================
   Handle POST (Approve / Reject)
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';
    $excess_amount = (float)($_POST['excess_amount'] ?? 0);
    $requested_by = (int)($_POST['requested_by'] ?? 0);

    if ($excess_amount <= 0) {
        pop("Invalid excess amount.", "/po/view.php?po_id=".$po_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    /* ===============================
       APPROVE
    ================================ */
    if ($action === 'approve') {

        try {

            $pdo->beginTransaction();

            /* 1️⃣ Approve PO excess */
            $pdo->prepare("
                UPDATE purchase_orders
                SET status='Open',
                    excess_approved_by=?,
                    excess_approved_at=NOW()
                WHERE po_id=?
            ")->execute([$user_id, $po_id]);

            logAudit(
                $pdo,
                'purchase_orders',
                $po_id,
                'PO_EXCESS_APPROVED',
                "Excess approved by user ID {$user_id}"
            );

            /* 2️⃣ Insert approved variation */
            $pdo->prepare("
                INSERT INTO po_variations
                (po_id, variation_amount, currency, reason, requested_by, approved_by, approved_at, status)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), 'APPROVED')
            ")->execute([
                $po_id,
                $excess_amount,
                $exCurrency,
                'Approved PO excess',
                $requested_by,
                $user_id
            ]);

            $variation_id = $pdo->lastInsertId();

            logAudit(
                $pdo,
                'po_variations',
                $variation_id,
                'AUTO_CREATE',
                "Auto-created excess variation of {$exCurrency} ".number_format($excess_amount,2)
            );

            /* 3️⃣ Update PO total */
            $pdo->prepare("
                UPDATE purchase_orders
                SET po_total = po_total + ?
                WHERE po_id = ?
            ")->execute([$excess_amount, $po_id]);

            logAudit(
                $pdo,
                'purchase_orders',
                $po_id,
                'UPDATE_TOTAL',
                "PO total increased by excess {$exCurrency} ".number_format($excess_amount,2)
            );

            /* 4️⃣ Clear warnings */
            $pdo->prepare("
                DELETE FROM po_warnings
                WHERE po_id = ?
                  AND warning_type = 'PO_LIMIT_ATTEMPT'
            ")->execute([$po_id]);

            /* Timeline */
            logRequestTimeline(
                $pdo,
                $po['request_id'],
                'PO_EXCESS_APPROVED',
                "PO {$po['po_number']} excess approved ({$exCurrency} ".number_format($excess_amount,2).")"
            );

            $pdo->commit();

            pop(
                "PO excess approved successfully.",
                "/po/view.php?po_id=".$po_id,
                2000,
                "success"
            );
            exit;

        } catch (Throwable $e) {

            $pdo->rollBack();

            pop(
                "Approval failed: " . extractDbMessage($e),
                "/po/excess_approve.php?id=".$po_id,
                2000,
                "error"
            );
            exit;
        }
    }

    /* ===============================
       REJECT
    ================================ */
    elseif ($action === 'reject') {

        $reason = trim($_POST['rejection_reason'] ?? '');

        if (empty($reason)) {
            pop(
                "Rejection Reason Required",
                "You must provide a reason for rejection.",
                "/po/excess_approve.php?id=".$po_id,
                "warning"
            );
            exit;
        }

        try {

            $pdo->beginTransaction();

            /* Delete the warning instead of approving */
            $pdo->prepare("
                DELETE FROM po_warnings
                WHERE po_id = ?
                  AND warning_type = 'PO_LIMIT_ATTEMPT'
            ")->execute([$po_id]);

            logAudit(
                $pdo,
                'purchase_orders',
                $po_id,
                'PO_EXCESS_REJECTED',
                "PO excess rejected by user ID {$user_id}: {$reason}"
            );

            logRequestTimeline(
                $pdo,
                $po['request_id'],
                'PO_EXCESS_REJECTED',
                "PO {$po['po_number']} excess rejected: {$reason}"
            );

            $pdo->commit();

            pop(
                "PO excess rejected. Reason: " . htmlspecialchars($reason),
                "/po/view.php?po_id=".$po_id,
                2000,
                "warning"
            );
            exit;

        } catch (Throwable $e) {

            $pdo->rollBack();

            pop(
                "Rejection failed: " . extractDbMessage($e),
                "/po/excess_approve.php?id=".$po_id,
                2000,
                "error"
            );
            exit;
        }
    }
}

/* ===============================
   Render Form
================================ */
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>

<div class="container mt-4">
    <div class="card shadow-sm">
        <div class="card-header bg-light">
            <h4 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i> PO Excess Approval</h4>
        </div>
        <div class="card-body">
            <div class="alert alert-warning mb-3">
                <strong>⚠️ Purchase Order Limit Exceeded</strong><br>
                <strong>PO Number:</strong> <?= htmlspecialchars($po['po_number'] ?? 'N/A') ?><br>
                <strong>Current Total:</strong> <?= htmlspecialchars($exCurrency) ?> <?= number_format($po['po_total'], 2) ?><br>
                <strong>Request Status:</strong> <span class="badge bg-danger">Requires Approval</span>
            </div>
            <form method="post">
                <div class="mb-3">
                    <label for="excess_amount" class="form-label fw-bold">Excess Amount <span class="text-danger">*</span></label>
                    <input 
                        type="number" 
                        id="excess_amount"
                        name="excess_amount" 
                        class="form-control"
                        step="0.01"
                        min="0"
                        required
                        placeholder="Enter excess amount..."
                    >
                </div>
                <div class="mb-3">
                    <label for="requested_by" class="form-label fw-bold">Requested By (User ID) <span class="text-danger">*</span></label>
                    <input 
                        type="number" 
                        id="requested_by"
                        name="requested_by" 
                        class="form-control"
                        min="1"
                        required
                        placeholder="Enter requesting user ID..."
                    >
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Rejection Reason <span class="text-danger">*</span> (Required if rejecting)</label>
                    <textarea 
                        name="rejection_reason" 
                        class="form-control"
                        rows="3"
                        placeholder="Enter reason if rejecting..."
                    ></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button name="action" value="approve" class="btn btn-success">
                        <i class="bi bi-check-lg me-1"></i> Approve Excess
                    </button>
                    <button 
                        name="action" 
                        value="reject" 
                        class="btn btn-danger"
                        onclick="return confirm('Are you sure you want to reject this PO excess request?')"
                    >
                        <i class="bi bi-x-circle me-1"></i> Reject
                    </button>
                    <a href="/po/view.php?po_id=<?= (int)$po_id ?>"
                         class="btn btn-secondary">
                        <i class="bi bi-arrow-left me-1"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
