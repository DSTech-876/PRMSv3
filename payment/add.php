<?php
$REQUIRE_PERMISSION = 'create_payment';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/helper.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/services/ProcurementTaskService.php";

// Validate invoice id
$invoice_id = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
if ($invoice_id <= 0) {
    pop('Invalid invoice reference', '/invoice/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}


/* Fetch invoice + current payments */
$stmt = $pdo->prepare("
    SELECT 
        i.invoice_id,
        i.invoice_amount,
        i.po_id,
        i.commitment_id,
        i.contract_id,
        COALESCE(SUM(p.payment_amount), 0) AS total_paid,
        COALESCE(pr.currency, 'JMD') AS currency
    FROM invoices i
    LEFT JOIN payments p ON i.invoice_id = p.invoice_id
    LEFT JOIN purchase_orders po ON i.po_id = po.po_id
    LEFT JOIN commitments c ON COALESCE(i.commitment_id, po.commitment_id) = c.commitment_id
    LEFT JOIN procurement_requests pr ON c.request_id = pr.request_id
    WHERE i.invoice_id = ?
    GROUP BY i.invoice_id
");
$stmt->execute([$invoice_id]);
$i = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$i) {
    
    modalPop(
    "Invoice Missing",
    "The selected invoice could not be found.",
    "/invoice/list.php",
    "error"
);

    exit;
}

$balance = (float)$i['invoice_amount'] - (float)$i['total_paid'];
$payCurrency = 'JMD'; // Invoice/payment amounts are always stored in JMD

/* Handle POST before output */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $amount = isset($_POST['payment_amount']) ? (float)$_POST['payment_amount'] : 0.0;

if ($amount > $balance) {
    logAudit($pdo, 'POLICY', null, 'OVERPAY_ATTEMPT', 'Payment exceeds invoice balance');
 
    modalPop(
    "Payment Exceeds",
    "Payment exceeds outstanding invoice balance.",
    "/invoice/view.php?id=".$invoice_id,
    "error"
);

    exit;
}


    try {
    $stmt = $pdo->prepare("
      INSERT INTO payments
      (invoice_id, payment_date, payment_reference, payment_amount, currency, created_by)
      VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $invoice_id,
        $_POST['payment_date'],
        $_POST['payment_reference'],
        $amount,
        $i['currency'],
        $_SESSION['user_id']
    ]);
    
    $payment_id = $pdo->lastInsertId();

logAudit(
    $pdo, 
    'payments',
    $payment_id,
    'CREATE',
    'Payment recorded'
);


    /* Recalculate invoice payments */
    $sum = $pdo->prepare("SELECT COALESCE(SUM(payment_amount), 0) FROM payments WHERE invoice_id = ?");
    $sum->execute([$invoice_id]);
    $totalPaid = (float)$sum->fetchColumn();

    /* Update invoice status */
    $newStatus = 'Unpaid';
    if ($totalPaid >= (float)$i['invoice_amount']) {
        $newStatus = 'Paid';
    } elseif ($totalPaid > 0) {
        $newStatus = 'Partially Paid';
    }

    $upd = $pdo->prepare("UPDATE invoices SET status = ? WHERE invoice_id = ?");
    $upd->execute([$newStatus, $invoice_id]);

    /* Task tracking: a payment always counts as progress; the task is only
       COMPLETED once the invoice is fully settled. */
    $paymentRequestIdStmt = $pdo->prepare("
        SELECT c.request_id
        FROM invoices i
        JOIN purchase_orders p ON p.po_id = i.po_id
        JOIN commitments c ON c.commitment_id = p.commitment_id
        WHERE i.invoice_id = ?
        LIMIT 1
    ");
    $paymentRequestIdStmt->execute([$invoice_id]);
    $paymentRequestId = (int)($paymentRequestIdStmt->fetchColumn() ?: 0);
    if ($paymentRequestId > 0) {
        ProcurementTaskService::markTask(
            $pdo,
            $paymentRequestId,
            ProcurementTaskService::TASK_PAYMENT,
            $newStatus === 'Paid'
                ? ProcurementTaskService::STATUS_COMPLETED
                : ProcurementTaskService::STATUS_IN_PROGRESS,
            (int)($_SESSION['user_id'] ?? 0) ?: null,
            $newStatus === 'Paid' ? 'Invoice fully paid' : 'Partial payment recorded'
        );
    }

    /* Check if PO is fully paid */
    if ($i['po_id']) {
        $poCheck = $pdo->prepare("
          SELECT po.po_id, po.po_total,
                 SUM(i.invoice_amount) AS total_invoiced,
                 SUM(p.payment_amount) AS total_paid
          FROM purchase_orders po
          JOIN invoices i ON po.po_id = i.po_id
          LEFT JOIN payments p ON i.invoice_id = p.invoice_id
          WHERE po.po_id = ?
          GROUP BY po.po_id
        ");
        $poCheck->execute([$i['po_id']]);
        $poData = $poCheck->fetch(PDO::FETCH_ASSOC);

        if ($poData) {
            $totalPaidPO = (float)($poData['total_paid'] ?? 0);
            if ($totalPaidPO >= (float)$poData['po_total']) {
                $close = $pdo->prepare("UPDATE purchase_orders SET status = 'Closed' WHERE po_id = ?");
                $close->execute([$i['po_id']]);
                
                // Auto-transition to COMPLETED when PO fully paid
                $completedReqStmt = $pdo->prepare("
                    SELECT c.request_id
                    FROM commitments c
                    JOIN purchase_orders po ON po.commitment_id = c.commitment_id
                    WHERE po.po_id = ?
                    LIMIT 1
                ");
                $completedReqStmt->execute([$i['po_id']]);
                $completedReqId = $completedReqStmt->fetchColumn();
                if ($completedReqId) {
                    $pdo->prepare("UPDATE procurement_requests SET status = 'COMPLETED' WHERE request_id = ?")->execute([$completedReqId]);
                    logRequestTimeline($pdo, $completedReqId, 'COMPLETED', 'All invoices fully paid. Procurement process completed.');
                    
                    require_once $_SERVER['DOCUMENT_ROOT']."/config/notifications.php";
                    notifyRequestFinalized($completedReqId, 'COMPLETED');
                }
            }
        }
    } elseif ($i['commitment_id']) {
        /* Service contract: Check if invoice is fully paid → complete request */
        if ($totalPaid >= (float)$i['invoice_amount']) {
            $completedReqStmt = $pdo->prepare("
                SELECT c.request_id
                FROM commitments c
                WHERE c.commitment_id = ?
                LIMIT 1
            ");
            $completedReqStmt->execute([$i['commitment_id']]);
            $completedReqId = $completedReqStmt->fetchColumn();
            if ($completedReqId) {
                $pdo->prepare("UPDATE procurement_requests SET status = 'COMPLETED' WHERE request_id = ? AND status = 'INVOICE_RECEIVED'")->execute([$completedReqId]);
                logRequestTimeline($pdo, $completedReqId, 'COMPLETED', 'Invoice fully paid. Service contract payment completed.');
                
                require_once $_SERVER['DOCUMENT_ROOT']."/config/notifications.php";
                notifyRequestFinalized($completedReqId, 'COMPLETED');
            }
        }
    }
    
    // Notify about payment recorded
    $paymentReqId = null;
    if ($i['po_id']) {
        $paymentReqStmt = $pdo->prepare("
            SELECT c.request_id
            FROM commitments c
            JOIN purchase_orders po ON po.commitment_id = c.commitment_id
            WHERE po.po_id = ?
            LIMIT 1
        ");
        $paymentReqStmt->execute([$i['po_id']]);
        $paymentReqId = $paymentReqStmt->fetchColumn();
    } elseif ($i['commitment_id']) {
        $paymentReqStmt = $pdo->prepare("
            SELECT c.request_id
            FROM commitments c
            WHERE c.commitment_id = ?
            LIMIT 1
        ");
        $paymentReqStmt->execute([$i['commitment_id']]);
        $paymentReqId = $paymentReqStmt->fetchColumn();
    }
    if ($paymentReqId) {
        require_once $_SERVER['DOCUMENT_ROOT']."/config/notifications.php";
        notifyPaymentRecorded($paymentReqId, $invoice_id, $amount, $_POST['payment_reference']);
    }

    header("Location: /invoice/view.php?id=" . $invoice_id);
    exit;

    } catch (Throwable $e) {
        modalPop('Error', extractDbMessage($e), '/invoice/view.php?id=' . $invoice_id, 'error');
        exit;
    }
}

/* Render page AFTER POST handling */
require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/header.php";
?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-lg-8">
            <h3 class="section-title">💸 Add Payment</h3>
            <p class="text-muted">Record a payment against this invoice</p>
        </div>
    </div>

    <!-- Invoice Summary Card -->
    <div class="card mb-4 border-start border-info border-3">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0">📋 Invoice Summary</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <small class="text-muted d-block">Invoice ID</small>
                    <h6 class="fw-bold text-primary">#<?= htmlspecialchars($i['invoice_id']) ?></h6>
                </div>
                <div class="col-md-6">
                    <small class="text-muted d-block">PO ID</small>
                    <h6 class="fw-bold text-primary">#<?= htmlspecialchars($i['po_id']) ?></h6>
                </div>
            </div>
            <div class="row g-3 mt-3">
                <div class="col-md-4">
                    <small class="text-muted d-block">Invoice Amount</small>
                    <span class="badge bg-success fs-6"><?= htmlspecialchars($payCurrency) ?> <?= number_format($i['invoice_amount'], 2) ?></span>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Total Paid</small>
                    <span class="badge bg-info fs-6"><?= htmlspecialchars($payCurrency) ?> <?= number_format($i['total_paid'], 2) ?></span>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Outstanding Balance</small>
                    <span class="badge bg-warning text-dark fs-6"><?= htmlspecialchars($payCurrency) ?> <?= number_format(max($balance, 0), 2) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Form Card -->
    <div class="card border-secondary mb-4">
        <div class="card-header bg-secondary text-white">
            <h5 class="mb-0">✍️ Payment Details</h5>
        </div>
        <div class="card-body">
            <form method="post" id="paymentForm" onsubmit="return validatePaymentForm()">
                <div class="mb-4">
                    <label for="payment_date" class="form-label">
                        <i class="bi bi-calendar-event"></i> <span class="text-danger">*</span> Payment Date
                    </label>
                    <input type="date" name="payment_date" id="payment_date" class="form-control form-control-lg" required>
                </div>
                <div class="mb-4">
                    <label for="payment_reference" class="form-label">
                        <i class="bi bi-hash"></i> <span class="text-danger">*</span> Reference Number
                    </label>
                    <input type="text" name="payment_reference" id="payment_reference" class="form-control form-control-lg" required placeholder="Enter payment reference">
                </div>
                <div class="mb-4">
                    <label for="payment_amount" class="form-label">
                        <i class="bi bi-currency-dollar"></i> <span class="text-danger">*</span> Amount
                    </label>
                    <div class="input-group">
                        <span class="input-group-text"><?= htmlspecialchars($payCurrency) ?></span>
                        <input type="number" step="0.01" name="payment_amount" id="payment_amount" class="form-control form-control-lg" min="0.01" max="<?= max($balance, 0) ?>" required placeholder="Enter payment amount" onchange="updateAmountFeedback()" onkeyup="updateAmountFeedback()">
                    </div>
                    <small class="text-muted d-block mt-2">Cannot exceed outstanding balance.</small>
                </div>
                <div class="alert alert-light border border-warning p-3 mb-4" id="amountFeedback" style="display: none;"></div>
                <div class="d-grid gap-2 d-sm-flex justify-content-between">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="bi bi-check-circle"></i> Save Payment
                    </button>
                    <a href="/invoice/view.php?id=<?= (int)$invoice_id ?>" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-arrow-left"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Info Box -->
    <div class="row">
        <div class="col-lg-8">
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="bi bi-lightbulb"></i>
                <strong>Note:</strong> Payments update invoice and PO status automatically. Overpayments are blocked.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        </div>
    </div>
</div>

<script>
function updateAmountFeedback() {
    const amount = parseFloat(document.getElementById('payment_amount').value) || 0;
    const balance = <?= max($balance, 0) ?>;
    const feedback = document.getElementById('amountFeedback');
    if (amount > 0) {
        feedback.style.display = 'block';
        if (amount > balance) {
            feedback.className = 'alert alert-danger border border-danger p-3 mb-4';
            feedback.textContent = 'Amount exceeds outstanding balance!';
        } else {
            feedback.className = 'alert alert-light border border-warning p-3 mb-4';
            feedback.textContent = 'Payment will reduce balance to JMD ' + (balance - amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
    } else {
        feedback.style.display = 'none';
    }
}

function validatePaymentForm() {
    const date = document.getElementById('payment_date').value;
    const ref = document.getElementById('payment_reference').value.trim();
    const amount = parseFloat(document.getElementById('payment_amount').value) || 0;
    const balance = <?= max($balance, 0) ?>;
    if (!date) {
        alert('Please select a payment date.');
        document.getElementById('payment_date').focus();
        return false;
    }
    if (!ref) {
        alert('Please enter a reference number.');
        document.getElementById('payment_reference').focus();
        return false;
    }
    if (amount <= 0) {
        alert('Please enter a valid payment amount.');
        document.getElementById('payment_amount').focus();
        return false;
    }
    if (amount > balance) {
        alert('Payment amount cannot exceed outstanding balance.');
        document.getElementById('payment_amount').focus();
        return false;
    }
    return confirm('Save this payment?');
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>