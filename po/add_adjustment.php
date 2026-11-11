<?php
$REQUIRE_PERMISSION = 'request_po_adjustment';
require_once $_SERVER['DOCUMENT_ROOT']."/config/page_guard.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";

$parent_po_id = $_GET['po_id'] ?? null;
if (!$parent_po_id) {
    modalPop("Error", "Original PO required.", "/po/list.php", "error");
    exit;
}

/* Fetch original PO with commitment details */
$stmt = $pdo->prepare("
    SELECT 
        po.po_id,
        po.po_number,
        po.po_total,
        po.status,
        po.po_date,
        c.commitment_number,
        c.commitment_total,
        pr.request_number,
        pr.currency
    FROM purchase_orders po
    LEFT JOIN commitments c ON po.commitment_id = c.commitment_id
    LEFT JOIN procurement_requests pr ON c.request_id = pr.request_id
    WHERE po.po_id = ?
");
$stmt->execute([$parent_po_id]);
$parent = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$parent) {
    modalPop("Error", "Original PO not found.", "/po/list.php", "error");
    exit;
}

$currency = normalizeCurrency($parent['currency'] ?? 'JMD');

/* Check PO is Open and fully approved before allowing adjustment */
if ($parent['status'] !== 'Open') {
    modalPop("Error", "Adjustment requires an open PO (current status: {$parent['status']}).", "/po/view.php?po_id=" . $parent_po_id, "error");
    exit;
}

$approvalCheck = $pdo->prepare("
    SELECT COUNT(*) as total,
           SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) as approved
    FROM request_approvals
    WHERE entity_type='PO' AND entity_id=?
");
$approvalCheck->execute([$parent_po_id]);
$appr = $approvalCheck->fetch(PDO::FETCH_ASSOC);
$isFullyApproved = ((int)$appr['total'] > 0 && (int)$appr['approved'] === (int)$appr['total']);

if (!$isFullyApproved) {
    modalPop("Error", "Adjustment requires an approved original PO.", "/po/view.php?po_id=" . $parent_po_id, "error");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $amount = (float)($_POST['total_amount'] ?? 0);
    $reason = trim($_POST['adjustment_reason'] ?? '');

    if ($amount == 0) {
        modalPop("Error", "Adjustment amount cannot be zero.", "", "error");
        exit;
    }

    if (empty($reason)) {
        modalPop("Error", "Adjustment reason is required.", "", "error");
        exit;
    }

    try {
    $stmt = $pdo->prepare("
        INSERT INTO purchase_orders
        (po_type, parent_po_id, po_total, currency, adjustment_reason, status, created_at)
        VALUES ('ADJUSTMENT', ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([$parent_po_id, $amount, $currency, $reason]);

    logAudit(
        $pdo,
        'purchase_orders',
        $pdo->lastInsertId(),
        'CREATE_ADJUSTMENT',
        'PO adjustment requested'
    );

    modalPop(
        "Success",
        "PO adjustment submitted for approval.",
        "/po/view.php?po_id=" . $parent_po_id,
        "success"
    );
    } catch (Throwable $e) {
        modalPop("Error", extractDbMessage($e), "/po/view.php?po_id=" . $parent_po_id, "error");
    }
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT']."/includes/header.php";

$newPoTotal = (float)$parent['po_total'] + (float)($_POST['total_amount'] ?? 0);
?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-lg-8">
            <h3 class="section-title">📊 Request PO Adjustment</h3>
            <p class="text-muted">Submit a request to adjust the total amount for this purchase order</p>
        </div>
    </div>

    <!-- Parent PO Details Card -->
    <div class="card mb-4 border-start border-primary border-3">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">📦 Original Purchase Order</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="mb-3">
                        <small class="text-muted d-block">PO Number</small>
                        <h6 class="fw-bold text-primary"><?= htmlspecialchars($parent['po_number']) ?></h6>
                    </div>
                    <div class="mb-3">
                        <small class="text-muted d-block">Request Number</small>
                        <p class="mb-0"><?= htmlspecialchars($parent['request_number'] ?? 'N/A') ?></p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <small class="text-muted d-block">Current PO Total</small>
                        <p class="mb-0 fs-6">JMD <span class="badge bg-success"><?= number_format($parent['po_total'], 2) ?></span></p>
                    </div>
                    <div class="mb-3">
                        <small class="text-muted d-block">Status</small>
                        <p class="mb-0">
                            <span class="badge bg-info"><?= htmlspecialchars(ucfirst(strtolower($parent['status']))) ?></span>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Adjustment Request Form -->
    <div class="card border-secondary mb-4">
        <div class="card-header bg-secondary text-white">
            <h5 class="mb-0">✍️ Adjustment Details</h5>
        </div>
        <div class="card-body">
            <form method="post" id="adjustmentForm" onsubmit="return validateForm()">

                <!-- Adjustment Amount Field -->
                <div class="mb-4">
                    <label for="total_amount" class="form-label">
                        <i class="bi bi-currency-dollar"></i>
                        <span class="text-danger">*</span> Adjustment Amount (JMD)
                    </label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text" id="adjustmentSign">JMD</span>
                        <input type="number"
                               id="total_amount"
                               name="total_amount"
                               class="form-control"
                               step="0.01"
                               placeholder="Enter positive or negative amount"
                               onchange="updatePreview()"
                               onkeyup="updatePreview()"
                               required>
                    </div>
                    <small class="text-muted d-block mt-2">
                        <i class="bi bi-info-circle"></i>
                        Use positive numbers to increase, negative to decrease
                    </small>
                </div>

                <!-- Adjustment Reason Field -->
                <div class="mb-4">
                    <label for="adjustment_reason" class="form-label">
                        <i class="bi bi-chat-square-text"></i>
                        <span class="text-danger">*</span> Reason for Adjustment
                    </label>
                    <textarea id="adjustment_reason"
                              name="adjustment_reason"
                              class="form-control"
                              rows="5"
                              placeholder="Explain the reason for this adjustment. Include details about cost changes, scope modifications, or other factors."
                              required></textarea>
                    <small class="text-muted d-block mt-2">
                        <i class="bi bi-info-circle"></i>
                        Provide clear details to help approvers understand the adjustment.
                    </small>
                </div>

                <!-- Preview Box -->
                <div class="alert alert-light border border-primary p-3 mb-4" id="previewBox">
                    <div class="row g-3">
                        <div class="col-6">
                            <small class="text-muted d-block">Current Total</small>
                            <p class="mb-0 fs-6">JMD <?= number_format($parent['po_total'], 2) ?></p>
                        </div>
                        <div class="col-6">
                            <small class="text-muted d-block">Adjustment</small>
                            <p class="mb-0 fs-6 fw-bold" id="adjustmentDisplay">+/- JMD 0.00</p>
                        </div>
                    </div>
                    <hr>
                    <div>
                        <small class="text-muted d-block">New Total (If Approved)</small>
                        <h5 class="mb-0" id="newTotal">JMD <?= number_format($parent['po_total'], 2) ?></h5>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="d-grid gap-2 d-sm-flex justify-content-between">
                    <button type="submit" class="btn btn-warning btn-lg">
                        <i class="bi bi-send"></i> Submit Adjustment Request
                    </button>

                    <a href="/po/view.php?po_id=<?= (int)$parent_po_id ?>" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-arrow-left"></i> Cancel
                    </a>
                </div>

            </form>
        </div>
    </div>

    <!-- Information Box -->
    <div class="row">
        <div class="col-lg-8">
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="bi bi-lightbulb"></i>
                <strong>Adjustment Types:</strong>
                <ul class="mb-0 ms-3 mt-2">
                    <li><strong>Increase (+):</strong> For additional work or cost increases</li>
                    <li><strong>Decrease (-):</strong> For cost reductions or scope reductions</li>
                    <li>Adjustments must be approved before becoming effective</li>
                    <li>The commitment or budget will be updated once approved</li>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        </div>
    </div>
</div>

<script>
const currencyLabel = 'JMD'; // PO amounts are always in JMD
function updatePreview() {
    const amountInput = document.getElementById('total_amount');
    const amount = parseFloat(amountInput.value) || 0;
    const currentTotal = <?= (float)$parent['po_total'] ?>;
    const newTotal = currentTotal + amount;
    
    const sign = amount >= 0 ? '+' : '';
    document.getElementById('adjustmentDisplay').textContent = 
        sign + ' ' + currencyLabel + ' ' + amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    
    document.getElementById('newTotal').textContent = 
        currencyLabel + ' ' + newTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    
    // Change color based on increase/decrease
    const adjustmentDisplay = document.getElementById('adjustmentDisplay');
    if (amount > 0) {
        adjustmentDisplay.className = 'fw-bold text-danger';
        document.getElementById('adjustmentSign').textContent = '➕ ' + currencyLabel;
    } else if (amount < 0) {
        adjustmentDisplay.className = 'fw-bold text-success';
        document.getElementById('adjustmentSign').textContent = '➖ ' + currencyLabel;
    } else {
        adjustmentDisplay.className = 'fw-bold';
        document.getElementById('adjustmentSign').textContent = currencyLabel;
    }
}

function validateForm() {
    const amount = parseFloat(document.getElementById('total_amount').value) || 0;
    const reason = document.getElementById('adjustment_reason').value.trim();
    
    if (amount === 0) {
        alert('Adjustment amount cannot be zero.');
        document.getElementById('total_amount').focus();
        return false;
    }
    
    if (reason.length < 10) {
        alert('Please provide a detailed reason (at least 10 characters).');
        document.getElementById('adjustment_reason').focus();
        return false;
    }
    
    const typeText = amount > 0 ? 'increase' : 'decrease';
    const absAmount = Math.abs(amount);
    return confirm(`Submit PO ${typeText} of ${currencyLabel} ${absAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}?`);
}

// Initialize preview on page load
window.addEventListener('load', updatePreview);
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT']."/includes/footer.php"; ?>
