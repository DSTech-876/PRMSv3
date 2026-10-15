<?php
/**
 * Verify Funds
 * ============
 * Finance/Accounts verifies fund availability for a procurement request.
 * New workflow: Procurement sends to accounts → accounts verifies funds →
 * accounts uploads commitment → responds to procurement with commitment #
 */
$REQUIRE_PERMISSION = 'verify_funds';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/helper.php";

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

/* Must be in HOD_APPROVED or SUBMITTED status */
$allowedStatuses = ['HOD_APPROVED', 'SUBMITTED', 'DIRECTOR_APPROVED', 'GC_APPROVED'];
if (!in_array(strtoupper($request['status']), $allowedStatuses)) {
    modalPop('Cannot Verify', 'Request must be approved by HOD before fund verification.', '/procurement/view.php?id=' . $request_id, 'error');
    exit;
}

/* Handle POST - verify funds */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken('/procurement/verify_funds.php?id=' . $request_id);

    try {
        $fundsAvailable = (int)($_POST['funds_available'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? '');

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE procurement_requests
            SET funds_available = ?,
                finance_reviewed_by = ?,
                finance_reviewed_at = NOW()
            WHERE request_id = ?
        ");
        $stmt->execute([$fundsAvailable, $_SESSION['user_id'], $request_id]);

        $action = $fundsAvailable ? 'FUNDS_VERIFIED' : 'FUNDS_DENIED';
        $note = $fundsAvailable
            ? 'Funds verified by Accounts. Ready for commitment upload.'
            : 'Funds not available. Remarks: ' . $remarks;

        logAudit($pdo, 'procurement_requests', $request_id, $action, $note);
        logRequestTimeline($pdo, $request_id, $action, $note);

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
            modalPop(
                'Funds Verified',
                'Funds have been certified as available. You can now upload the commitment document.',
                '/commitments/upload.php?request_id=' . $request_id,
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
                        <li>If funds are available, you will be redirected to upload the commitment document</li>
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
