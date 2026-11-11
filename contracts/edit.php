<?php
$REQUIRE_PERMISSION = 'manage_contracts';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

$contract_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($contract_id <= 0) {
    pop('Invalid contract reference.', '/contracts/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* Fetch contract */
$stmt = $pdo->prepare("SELECT * FROM service_contracts WHERE contract_id = ?");
$stmt->execute([$contract_id]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contract) {
    pop('Contract not found.', '/contracts/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* Fetch vendors and branches */
$vendors = $pdo->query("SELECT vendor_id, vendor_name FROM vendors WHERE status = 'ACTIVE' ORDER BY vendor_name")->fetchAll(PDO::FETCH_ASSOC);
$branches = $pdo->query("SELECT branch_id, branch_name FROM branches ORDER BY branch_name")->fetchAll(PDO::FETCH_ASSOC);

/* Handle POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $contract_title    = trim($_POST['contract_title'] ?? '');
    $vendor_id         = (int)($_POST['vendor_id'] ?? 0);
    $branch_id         = (int)($_POST['branch_id'] ?? 0);
    $contract_type     = $_POST['contract_type'] ?? 'FIXED_PRICE';
    $description       = trim($_POST['description'] ?? '');
    $total_value       = (float)($_POST['total_value'] ?? 0);
    $currency          = CurrencyService::normalize($_POST['currency'] ?? null, $pdo);
    $start_date        = trim($_POST['start_date'] ?? '');
    $end_date          = trim($_POST['end_date'] ?? '');
    $payment_terms     = (int)($_POST['payment_terms'] ?? 30);
    $billing_frequency = $_POST['billing_frequency'] ?? 'MONTHLY';
    $notes             = trim($_POST['notes'] ?? '');

    $errors = [];
    if ($contract_title === '') $errors[] = 'Contract title is required.';
    if ($vendor_id <= 0) $errors[] = 'Vendor is required.';
    if ($branch_id <= 0) $errors[] = 'Department is required.';
    if ($total_value <= 0) $errors[] = 'Contract value must be greater than zero.';
    if ($total_value < (float)$contract['consumed_value']) $errors[] = 'Total value cannot be less than already consumed value.';
    if ($start_date === '') $errors[] = 'Start date is required.';
    if ($end_date === '') $errors[] = 'End date is required.';
    if ($start_date && $end_date && $end_date <= $start_date) $errors[] = 'End date must be after start date.';

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE service_contracts SET
                    contract_title = ?, vendor_id = ?, branch_id = ?, contract_type = ?,
                    description = ?, total_value = ?, currency = ?, start_date = ?, end_date = ?,
                    payment_terms = ?, billing_frequency = ?, notes = ?
                WHERE contract_id = ?
            ");
            $stmt->execute([
                $contract_title, $vendor_id, $branch_id, $contract_type,
                $description, $total_value, $currency, $start_date, $end_date,
                $payment_terms, $billing_frequency, $notes, $contract_id
            ]);

            logAudit($pdo, 'service_contracts', $contract_id, 'UPDATE', 'Contract updated');

            header("Location: /contracts/view.php?id=$contract_id");
            exit;
        } catch (Throwable $e) {
            $errors[] = extractDbMessage($e);
        }
    }
    $error = implode('<br>', $errors);
} else {
    // Pre-fill form with existing data
    $_POST = $contract;
}

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="section-title">✏️ Edit Contract: <?= htmlspecialchars($contract['contract_number']) ?></h3>
    <a href="/contracts/view.php?id=<?= $contract_id ?>" class="btn btn-secondary btn-sm">← Back</a>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong>⚠️ Error:</strong> <?= $error ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0">📋 Contract Information</h5>
            </div>
            <div class="card-body">
                <form method="POST">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Contract Number</label>
                        <input type="text" value="<?= htmlspecialchars($contract['contract_number']) ?>" class="form-control" readonly disabled>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Contract Title *</label>
                        <input type="text" name="contract_title"
                               value="<?= htmlspecialchars($_POST['contract_title'] ?? '') ?>"
                               class="form-control" required>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Vendor *</label>
                            <select name="vendor_id" class="form-select" required>
                                <option value="">-- Select Vendor --</option>
                                <?php foreach ($vendors as $v): ?>
                                <option value="<?= $v['vendor_id'] ?>" <?= (int)($_POST['vendor_id'] ?? 0) === (int)$v['vendor_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($v['vendor_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Department *</label>
                            <select name="branch_id" class="form-select" required>
                                <option value="">-- Select Department --</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= $b['branch_id'] ?>" <?= (int)($_POST['branch_id'] ?? 0) === (int)$b['branch_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['branch_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Contract Type</label>
                            <select name="contract_type" class="form-select">
                                <option value="FIXED_PRICE" <?= ($_POST['contract_type'] ?? '') === 'FIXED_PRICE' ? 'selected' : '' ?>>Fixed Price</option>
                                <option value="TIME_MATERIALS" <?= ($_POST['contract_type'] ?? '') === 'TIME_MATERIALS' ? 'selected' : '' ?>>Time & Materials</option>
                                <option value="RETAINER" <?= ($_POST['contract_type'] ?? '') === 'RETAINER' ? 'selected' : '' ?>>Retainer</option>
                                <option value="UNIT_RATE" <?= ($_POST['contract_type'] ?? '') === 'UNIT_RATE' ? 'selected' : '' ?>>Unit Rate</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Currency</label>
                            <select name="currency" class="form-select">
                                <?php $__cur = $_POST['currency'] ?? ($contract['currency'] ?? CurrencyService::defaultCurrency($pdo)); ?>
                                <?php foreach (CurrencyService::selectOptions($pdo) as $__opt): ?>
                                    <option value="<?= htmlspecialchars($__opt['code']) ?>"
                                      <?= $__cur === $__opt['code'] ? 'selected' : '' ?>>
                                      <?= htmlspecialchars($__opt['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Total Value *</label>
                            <input type="number" step="0.01" min="<?= number_format((float)$contract['consumed_value'], 2, '.', '') ?>" name="total_value"
                                   value="<?= htmlspecialchars($_POST['total_value'] ?? '') ?>"
                                   class="form-control" required>
                            <small class="text-muted">Min: <?= money((float)$contract['consumed_value']) ?> (already consumed)</small>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Start Date *</label>
                            <input type="date" name="start_date"
                                   value="<?= htmlspecialchars($_POST['start_date'] ?? '') ?>"
                                   class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">End Date *</label>
                            <input type="date" name="end_date"
                                   value="<?= htmlspecialchars($_POST['end_date'] ?? '') ?>"
                                   class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Payment Terms (days)</label>
                            <input type="number" min="1" name="payment_terms"
                                   value="<?= htmlspecialchars($_POST['payment_terms'] ?? '30') ?>"
                                   class="form-control">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Billing Frequency</label>
                        <select name="billing_frequency" class="form-select">
                            <option value="MONTHLY" <?= ($_POST['billing_frequency'] ?? '') === 'MONTHLY' ? 'selected' : '' ?>>Monthly</option>
                            <option value="QUARTERLY" <?= ($_POST['billing_frequency'] ?? '') === 'QUARTERLY' ? 'selected' : '' ?>>Quarterly</option>
                            <option value="MILESTONE" <?= ($_POST['billing_frequency'] ?? '') === 'MILESTONE' ? 'selected' : '' ?>>Milestone</option>
                            <option value="ON_DELIVERY" <?= ($_POST['billing_frequency'] ?? '') === 'ON_DELIVERY' ? 'selected' : '' ?>>On Delivery</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <textarea name="description" rows="3" class="form-control"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold">Notes</label>
                        <textarea name="notes" rows="2" class="form-control"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success">💾 Save Changes</button>
                        <a href="/contracts/view.php?id=<?= $contract_id ?>" class="btn btn-secondary">Cancel</a>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
