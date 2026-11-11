<?php
$REQUIRE_PERMISSION='create_commitment';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
date_default_timezone_set('America/Jamaica');
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/policy.php';

$variation_id = isset($_GET['variation_id']) ? (int)$_GET['variation_id'] : 0;

if ($variation_id <= 0) {
    modalPop('Error', 'Missing Variation ID.', '/po/list.php', 'error');
    exit;
}
// Fetch variation + original PO + commitment
$stmt = $pdo->prepare("
    SELECT 
        pv.variation_id,
        pv.variation_amount,
        pv.reason,
        pv.commitment_id,
        pv.status,

        po.po_id,
        po.po_number,

        c.commitment_id AS parent_commitment_id,
        c.commitment_number,

        pr.request_id,
        pr.currency

    FROM po_variations pv
    JOIN purchase_orders po 
        ON pv.po_id = po.po_id
    JOIN commitments c 
        ON po.commitment_id = c.commitment_id
    JOIN procurement_requests pr
        ON c.request_id = pr.request_id
    WHERE pv.variation_id = ?
      AND pv.status = 'PENDING'
");
$stmt->execute([$variation_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);
$request_id = (int)$data['request_id'];

if ($request_id <= 0) {
    throw new RuntimeException('Invalid request ID for supplementary commitment');
}

if (!$data || empty($data['request_id'])) {
    pop(
        "Invalid request reference for supplementary commitment.",
        "/po/list.php"
    );
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {
        $pdo->beginTransaction();

        $commitmentNumber = generateCommitmentNumber($pdo);

        // Create supplementary commitment
        $stmt = $pdo->prepare("
            INSERT INTO commitments
            (request_id, commitment_number, commitment_date, commitment_total,
             currency, commitment_type, parent_commitment_id)
            VALUES (?, ?, CURDATE(), ?, ?, 'SUPPLEMENTARY', ?)
        ");
        $stmt->execute([
            $data['request_id'],
            $commitmentNumber,
            $data['variation_amount'],
            $data['currency'],
            $data['parent_commitment_id']
        ]);

        $newCommitmentId = (int)$pdo->lastInsertId();

        // ===== Create dynamic approval chain based on threshold (reads from DB) =====
        $reqDetailsStmt = $pdo->prepare("
            SELECT estimated_value, branch_id
            FROM procurement_requests
            WHERE request_id = ?
        ");
        $reqDetailsStmt->execute([$request_id]);
        $reqDetails = $reqDetailsStmt->fetch(PDO::FETCH_ASSOC);

        $estimated_value = (float)($reqDetails['estimated_value'] ?? 0);
        $branch_id = (int)($reqDetails['branch_id'] ?? 0);

        // SOP Step 13→14: Use centralized commitment approval chain (reads threshold from DB)
        $approvalStages = getCommitmentApprovalChain($pdo, $estimated_value, $branch_id);
        foreach ($approvalStages as $stage) {
            $pdo->prepare("
                INSERT INTO request_approvals
                (entity_type, entity_id, role, stage_order, status)
                VALUES ('COMMITMENT', ?, ?, ?, 'pending')
            ")->execute([
                $newCommitmentId,
                $stage['role'],
                $stage['stage_order']
            ]);
        }

        // Link variation to commitment
        $pdo->prepare("
            UPDATE po_variations
            SET commitment_id = ?
            WHERE variation_id = ?
        ")->execute([$newCommitmentId, $variation_id]);

        logAudit(
    $pdo,
    'commitments',
    $newCommitmentId,
    'CREATE',
    'Supplementary commitment created for PO variation '.$variation_id.' with HOD → Finance approval chain'
);
 logAudit($pdo, 'po_variations', $variation_id, 'LINK', 'Variation linked to supplementary commitment');
logRequestTimeline(
    $pdo,
    $request_id,
    'SUPPLEMENTARY_COMMITMENT_CREATED',
    'Supplementary commitment '.$commitmentNumber.' created for JMD '
    . number_format((float)$data['variation_amount'], 2)
);



        $pdo->commit();

        modalPop(
            'Success',
            'Supplementary Commitment created successfully.',
            '/po/view.php?po_id='.$data['po_id'],
            'success'
        );
        exit;

        } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    modalPop('Error', extractDbMessage($e), '', 'error');
    exit;
}

}


require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>

<div class="container mt-4">
    <h3 class="section-title">Supplementary Commitment</h3>

    <div class="card mb-3 table">
        <div class="card-body">
            <p><strong>PO:</strong> <?= htmlspecialchars($data['po_number']) ?></p>
            <p><strong>Original Commitment:</strong> <?= htmlspecialchars($data['commitment_number']) ?></p>
            <p><strong>Variation Amount:</strong> $<?= number_format($data['variation_amount'], 2) ?></p>
            <p><strong>Reason:</strong> <?= htmlspecialchars($data['reason']) ?></p>
        </div>
    </div>

    <form method="post">
        <button class="btn btn-success">
            ✅ Create Supplementary Commitment
        </button>
        <a href="/po/view.php?po_id=<?= (int)$data['po_id'] ?>" class="btn btn-secondary">
            ❌ Cancel
        </a>
    </form>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>