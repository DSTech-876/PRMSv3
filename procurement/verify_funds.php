<?php
/**
 * Verify Funds
 * ============
 * Finance/Accounts verifies fund availability for a procurement request.
 * New workflow: Procurement sends to accounts → accounts verifies funds →
 * accounts uploads commitment → responds to procurement with commitment #
 */
$REQUIRE_PERMISSION = 'verify_funds';
// The supervising Director Accounts & Finance holds 'approve_request' rather than
// the Finance Officer's operational 'verify_funds' permission, but may act on the
// finance stage (see canApproveStage() equivalence in config/workflow.php).
$ALTERNATE_PERMISSIONS = ['approve_request'];
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/helper.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/workflow.php";

$actorRole = $_SESSION['role_name'] ?? '';
$isPrivilegedActor = in_array($actorRole, ['Admin', 'SuperAdmin'], true);
if (!$isPrivilegedActor && !canApproveStage($actorRole, 'Finance Officer', 0.0)) {
    pop(
        'You are not authorized to verify funds for procurement requests.',
        '/dashboard/index.php',
        POP_DEFAULT_DELAY_MS,
        'error'
    );
    exit;
}

$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($request_id <= 0) {
    pop("Invalid Request ID.", "/procurement/list.php", POP_DEFAULT_DELAY_MS, "warning");
    exit;
}

/* Fetch request */
$stmt = $pdo->prepare("
    SELECT pr.*, b.branch_name, u.full_name AS requester_name
    FROM procurement_requests pr
    JOIN branches b ON pr.branch_id = b.branch_id
    LEFT JOIN users u ON pr.created_by = u.user_id
    WHERE pr.request_id = ?
");
$stmt->execute([$request_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    pop("Request not found.", "/procurement/list.php", POP_DEFAULT_DELAY_MS, "warning");
    exit;
}

$currentStatus = strtoupper((string)$request['status']);

/* Fund verification happens after branch approval, or after the quote is approved
   on the RFQ path. */
$allowedStatuses = ['HOD_APPROVED', 'DIRECTOR_APPROVED', 'GC_APPROVED', 'QUOTE_APPROVED'];
if (!in_array($currentStatus, $allowedStatuses, true)) {
    modalPop('Cannot Verify', 'This request is not at a stage where funds can be verified. Current status: ' . $request['status'], '/procurement/view.php?id=' . $request_id, 'error');
    exit;
}

/* "Proceed Without RFQ" bypasses fund verification entirely — the request must
   never transition to FUNDS_VERIFIED on that path. */
$rfqIdStmt = $pdo->prepare("SELECT rfq_id FROM rfqs WHERE request_id = ? LIMIT 1");
$rfqIdStmt->execute([$request_id]);
$linkedRfqId = $rfqIdStmt->fetchColumn();

if (isSkipRfqPath((string)($request['request_type'] ?? ''), $linkedRfqId, $currentStatus, $request)) {
    modalPop(
        'Cannot Verify',
        'Funds verification is bypassed for requests that proceeded without an RFQ. Create the commitment directly.',
        '/procurement/view.php?id=' . $request_id,
        'warning'
    );
    exit;
}

/* A request can reach this page with funds_available already set while the status
   was never advanced (legacy records written before fund verification performed a
   real stage transition). Surface it, but still allow the verification to be
   completed so the pipeline can catch up. */
$previouslyMarkedVerified = !empty($request['funds_available']) && !empty($request['finance_reviewed_at']);

/* The pending Finance Officer approval (when the chain has one) must be closed
   off together with the status change, otherwise the pipeline stays behind while
   the request shows funds as verified. */
$financeApprovalStmt = $pdo->prepare("
    SELECT id FROM request_approvals
    WHERE request_id = ?
      AND status = 'pending'
      AND role = 'Finance Officer'
    ORDER BY stage_order ASC
    LIMIT 1
");
$financeApprovalStmt->execute([$request_id]);
$pendingFinanceApprovalId = $financeApprovalStmt->fetchColumn();

/* Handle POST - verify funds */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken('/procurement/verify_funds.php?id=' . $request_id);

    $fundsAvailable = (int)($_POST['funds_available'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');

    /* Resolve (and validate) the status change BEFORE writing anything, so the
       request can never end up flagged as verified while the pipeline stays at
       the previous stage. */
    $nextStatus = null;
    if ($fundsAvailable) {
        $nextStatus = $pendingFinanceApprovalId
            ? getNextStatusAfterApproval($pdo, $request_id, 'Finance Officer')
            : 'FUNDS_VERIFIED';
         
        // When Finance Officer is the last approval, only validate the transition itself
        // without requiring authorization for the target stage (which excludes Finance Officer).
        // Otherwise, enforce full authorization for the target stage.
        if ($pendingFinanceApprovalId) {
            if (!canTransition($request['status'], $nextStatus)) {
                pop(
                    "Invalid workflow transition from {$request['status']} to {$nextStatus}",
                    '/procurement/list.php',
                    POP_DEFAULT_DELAY_MS,
                    'error'
                );
                exit;
            }
        } else {
            enforceTransition($request, $nextStatus);
        }
    }

    try {
        $pdo->beginTransaction();

        if ($fundsAvailable) {
            if ($pendingFinanceApprovalId) {
                $pdo->prepare("
                    UPDATE request_approvals
                    SET status = 'approved',
                        approved_by = ?,
                        approved_at = NOW()
                    WHERE id = ?
                ")->execute([$_SESSION['user_id'], $pendingFinanceApprovalId]);
            }

            $pdo->prepare("
                UPDATE procurement_requests
                SET status = ?,
                    funds_available = 1,
                    finance_reviewed_by = ?,
                    finance_reviewed_at = NOW()
                WHERE request_id = ?
            ")->execute([$nextStatus, $_SESSION['user_id'], $request_id]);

            logProcurementVisibilityGranted($pdo, $request_id, $nextStatus, $currentStatus);
        } else {
            $pdo->prepare("
                UPDATE procurement_requests
                SET funds_available = 0,
                    finance_reviewed_by = ?,
                    finance_reviewed_at = NOW()
                WHERE request_id = ?
            ")->execute([$_SESSION['user_id'], $request_id]);
        }

        $action = $fundsAvailable ? 'FUNDS_VERIFIED' : 'FUNDS_DENIED';
        $note = $fundsAvailable
            ? 'Funds verified by ' . ($_SESSION['full_name'] ?? $actorRole) . '. Status changed to ' . $nextStatus . '.'
            : 'Funds not available. Remarks: ' . $remarks;

        logAudit($pdo, 'procurement_requests', $request_id, $action, $note);
        logRequestTimeline($pdo, $request_id, $fundsAvailable ? $nextStatus : $action, $note);

        $pdo->commit();

        /* Inform the requestor (and procurement, when visible) of the outcome */
        require_once $_SERVER['DOCUMENT_ROOT'] . "/config/notifications.php";
        notifyFundVerificationRecorded(
            $request_id,
            (bool)$fundsAvailable,
            $remarks,
            $_SESSION['full_name'] ?? ($_SESSION['role_name'] ?? 'Finance')
        );

        if ($fundsAvailable) {
            notifyNextApprover($request_id, 'Finance Officer');
            notifyProcurementOfApproval($request_id, $nextStatus);
            if ($nextStatus === 'FUNDS_VERIFIED') {
                notifyProcurementCommitmentFormNeeded($request_id);
            }

            modalPop(
                'Funds Verified',
                'Funds have been certified as available and the request has moved to ' . $nextStatus . '.',
                '/procurement/view.php?id=' . $request_id,
                'success'
            );
        } else {
            modalPop(
                'Funds Not Available',
                'The request has been marked as funds not available.',
                '/procurement/view.php?id=' . $request_id,
                'warning'
            );
        }
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Fund verification failed: " . $e->getMessage());
        $_SESSION['error'] = "Error verifying funds.";
    }
}

/* ===== Render ===== */
require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/header.php";
?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-lg-8">
            <h3 class="section-title">💰 Verify Fund Availability</h3>
            <p class="text-muted">Review and certify whether funds are available for this procurement request</p>
        </div>
    </div>

    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error']) ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if ($previouslyMarkedVerified): ?>
        <div class="alert alert-warning">
            <strong>⚠️ Incomplete verification detected.</strong>
            This request was already flagged as having funds available, but the workflow stage was never advanced.
            Submitting the verification below will move the request forward and update the pipeline.
        </div>
    <?php endif; ?>

    <!-- Request Details -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">📌 Request Information</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <small class="text-muted d-block">Request Number</small>
                    <h6 class="fw-bold"><?= htmlspecialchars($request['request_number']) ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Requester</small>
                    <h6><?= htmlspecialchars($request['requester_name'] ?? 'N/A') ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Branch</small>
                    <h6><?= htmlspecialchars($request['branch_name']) ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Request Type</small>
                    <h6>
                        <?= match($request['request_type'] ?? 'REGULAR') {
                            'PETTY_CASH' => '💰 Petty Cash',
                            'REIMBURSEMENT' => '💵 Reimbursement',
                            default => '📋 Regular'
                        } ?>
                    </h6>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Estimated Value</small>
                    <h5 class="fw-bold text-primary"><?= htmlspecialchars($request['currency'] ?? 'JMD') ?> <?= number_format((float)$request['estimated_value'], 2) ?></h5>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Status</small>
                    <span class="badge bg-info"><?= htmlspecialchars($request['status']) ?></span>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Date Submitted</small>
                    <h6><?= date('d M Y', strtotime($request['request_date'])) ?></h6>
                </div>
            </div>
        </div>
    </div>

    <!-- Items -->
    <?php
    $items = $pdo->prepare("SELECT * FROM procurement_request_items WHERE request_id = ?");
    $items->execute([$request_id]);
    $requestItems = $items->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <?php if (!empty($requestItems)): ?>
    <div class="card mb-4">
        <div class="card-header"><h6 class="mb-0">📦 Requested Items</h6></div>
        <div class="card-body p-0">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light">
                    <tr><th>Item</th><th>Specification</th><th>Qty</th><th>Remarks</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($requestItems as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['item_name']) ?></td>
                        <td><?= htmlspecialchars($item['specification'] ?? '-') ?></td>
                        <td><?= (int)$item['quantity'] ?></td>
                        <td><?= htmlspecialchars($item['remarks'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Verification Form -->
    <div class="card border-success mb-4">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0">✅ Fund Verification Decision</h5>
        </div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="mb-4">
                    <label class="form-label fw-bold">Are funds available for this request?</label>
                    <div class="d-flex gap-3">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="funds_available"
                                   id="funds_yes" value="1" required>
                            <label class="form-check-label text-success fw-bold" for="funds_yes">
                                ✅ Yes — Funds Available
                            </label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="funds_available"
                                   id="funds_no" value="0">
                            <label class="form-check-label text-danger fw-bold" for="funds_no">
                                ❌ No — Funds Not Available
                            </label>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="remarks" class="form-label">Remarks (optional)</label>
                    <textarea name="remarks" id="remarks" class="form-control" rows="3"
                              placeholder="Any notes about fund availability..."></textarea>
                </div>

                <div class="alert alert-light border">
                    <strong>ℹ️ Next Steps:</strong>
                    <ul class="mb-0 mt-2">
                        <li>If funds are available, the request advances to the next workflow stage and Procurement is notified to prepare the commitment form</li>
                        <li>The commitment number will be generated and shared with the procurement team</li>
                        <li>If funds are not available, the request will be flagged</li>
                    </ul>
                </div>

                <div class="d-grid gap-2 d-sm-flex">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="bi bi-check-circle"></i> Submit Verification
                    </button>
                    <a href="/procurement/view.php?id=<?= $request_id ?>" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-arrow-left"></i> Back to Request
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>
