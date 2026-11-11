<?php
$REQUIRE_PERMISSION = 'award_vendor';

require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';

$quote_id = (int)($_GET['quote_id'] ?? 0);
$rfq_id   = (int)($_GET['rfq_id'] ?? 0);

if (!$quote_id || !$rfq_id) {
    pop('Invalid selection', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* Start Transaction */
$pdo->beginTransaction();

try {

    /* Ensure RFQ not already awarded */
    $stmt = $pdo->prepare("
        SELECT status FROM rfqs WHERE rfq_id = ?
    ");
    $stmt->execute([$rfq_id]);
    $status = $stmt->fetchColumn();

    if ($status === 'AWARDED') {
        throw new Exception("RFQ already awarded.");
    }

    /* Ensure minimum 3 quotes (applies to all) */
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM rfq_quotes q
        JOIN rfq_vendors v ON q.rfq_vendor_id = v.rfq_vendor_id
        WHERE v.rfq_id = ?
    ");
    $stmt->execute([$rfq_id]);
    $quoteCount = $stmt->fetchColumn();

    if ($quoteCount < 3) {
        throw new Exception("Minimum 3 quotations required before awarding.");
    }

    /* UPDATED: Get request details to check threshold */
    $prStmt = $pdo->prepare("
        SELECT pr.status, pr.estimated_value, pr.workflow_mode, pr.request_id as pr_request_id
        FROM procurement_requests pr
        JOIN rfqs r ON r.request_id = pr.request_id
        WHERE r.rfq_id = ?
    ");
    $prStmt->execute([$rfq_id]);
    $prData = $prStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$prData) {
        throw new Exception("Procurement request not found.");
    }
    
    $prStatus = $prData['status'];

    // PARALLEL_MODE gate context: the award decision is still subject to the
    // genuine data-integrity rules above (3 quotes, committee size, evaluation
    // report) — only the stage-ordering constraint is relaxed.
    $awardGateRequest = [
        'request_id'    => (int)$prData['pr_request_id'],
        'status'        => (string)$prStatus,
        'workflow_mode' => $prData['workflow_mode'] ?? null,
    ];
    $estimatedValue = (float)($prData['estimated_value'] ?? 0);
    $isUnderThreshold = $estimatedValue <= getDirectProcurementThreshold($pdo);
    
    // Over-threshold: Requires committee evaluation and formal report
    if (!$isUnderThreshold) {
        /* Check committee compliance (ONLY for over-threshold) */
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM rfq_evaluation_committee WHERE rfq_id = ?
        ");
        $stmt->execute([$rfq_id]);
        $committeeCount = $stmt->fetchColumn();

        if ($committeeCount < 3) {
            throw new Exception("Minimum 3 evaluation committee members required for over-threshold RFQ (SOP Step 7).");
        }

        /* Check evaluation report exists (ONLY for over-threshold) */
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM rfq_evaluation_reports WHERE rfq_id = ?
        ");
        $stmt->execute([$rfq_id]);
        $reportCount = $stmt->fetchColumn();

        if ($reportCount == 0) {
            throw new Exception("Formal evaluation report required before awarding over-threshold RFQ (SOP Step 9).");
        }
        
        // Over-threshold: Must be at evaluation stage or beyond for award
        $allowedOverStatuses = ['PROCUREMENT_STAGE', 'EVALUATION_STAGE', 'COMMITTEE_RECOMMENDED', 'GC_APPROVED', 'APPROVED'];
        if (!workflowStatusGateAllows($awardGateRequest, $allowedOverStatuses, 'AWARD_RFQ', $pdo)) {
            throw new Exception("Over-threshold procurement must complete evaluation before award. Current: $prStatus");
        }
        
        // Auto-advance through intermediate statuses when DGC/HOD awards directly
        if (in_array($prStatus, ['PROCUREMENT_STAGE', 'EVALUATION_STAGE', 'COMMITTEE_RECOMMENDED'])) {
            $prRequestId = $prData['pr_request_id'];
            
            // Advance to GC_APPROVED (DGC/HOD awarding implies GC approval)
            $pdo->prepare("
                UPDATE procurement_requests
                SET status = 'GC_APPROVED',
                    approved_by = ?,
                    approved_at = NOW()
                WHERE request_id = ?
            ")->execute([$_SESSION['user_id'], $prRequestId]);
            
            // Mark any pending GC approval entry as approved
            $pdo->prepare("
                UPDATE request_approvals
                SET status = 'approved',
                    approved_by = ?,
                    approved_at = NOW()
                WHERE request_id = ?
                  AND role = 'Deputy Government Chemist'
                  AND status = 'pending'
            ")->execute([$_SESSION['user_id'], $prRequestId]);
            
            // Audit the auto-advancement
            $pdo->prepare("
                INSERT INTO audit_log
                (table_name, record_id, action, changed_by, change_date, notes)
                VALUES ('procurement_requests', ?, 'STATUS_CHANGE', ?, NOW(), ?)
            ")->execute([
                $prRequestId,
                $_SESSION['user_id'],
                "Auto-advanced from $prStatus to GC_APPROVED during direct award by " . ($_SESSION['role_name'] ?? 'Unknown')
            ]);
        }
    } else {
        // Under-threshold: No committee evaluation required
        // Just needs approval stage completion (HOD, Director, etc.)
        $approvedStages = ['HOD_APPROVED', 'DIRECTOR_APPROVED', 'GC_APPROVED', 'FUNDS_VERIFIED', 'RFQ_LETTER_AVAILABLE', 'QUOTE_REVIEW_PENDING', 'QUOTE_APPROVED'];
        if (!workflowStatusGateAllows($awardGateRequest, $approvedStages, 'AWARD_RFQ', $pdo)) {
            throw new Exception("Request must complete approval stage before awarding (Current: $prStatus).");
        }
    }

    /* Get vendor + rfq_vendor_id */
    $stmt = $pdo->prepare("
        SELECT rv.vendor_id, rv.rfq_vendor_id
        FROM rfq_quotes q
        JOIN rfq_vendors rv ON q.rfq_vendor_id = rv.rfq_vendor_id
        WHERE q.quote_id = ?
    ");
    $stmt->execute([$quote_id]);
    $vendorData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vendorData) {
        throw new Exception("Vendor not found.");
    }

    $vendor_id = $vendorData['vendor_id'];
    $rfq_vendor_id = $vendorData['rfq_vendor_id'];

    /* Clear previous selections */
    $pdo->prepare("
        UPDATE rfq_quotes
        SET is_selected = 0
        WHERE quote_id IN (
            SELECT q.quote_id
            FROM rfq_quotes q
            JOIN rfq_vendors rv ON q.rfq_vendor_id = rv.rfq_vendor_id
            WHERE rv.rfq_id = ?
        )
    ")->execute([$rfq_id]);

    /* Mark selected quote */
    $pdo->prepare("
        UPDATE rfq_quotes
        SET is_selected = 1
        WHERE quote_id = ?
    ")->execute([$quote_id]);

    /* Mark rfq_vendor as SELECTED */
    $pdo->prepare("
        UPDATE rfq_vendors
        SET response_status = 'SELECTED'
        WHERE rfq_vendor_id = ?
    ")->execute([$rfq_vendor_id]);

    /* Increment vendor award count */
    $pdo->prepare("
        UPDATE vendors
        SET total_awards = total_awards + 1
        WHERE vendor_id = ?
    ")->execute([$vendor_id]);

    /* Lock RFQ */
    $pdo->prepare("
        UPDATE rfqs
        SET status = 'AWARDED',
            awarded_quote_id = ?
        WHERE rfq_id = ?
    ")->execute([
        $quote_id,
        $rfq_id
    ]);
    
    /* Move Procurement Request → AWARDED */
    $requestIdStmt = $pdo->prepare("SELECT request_id FROM rfqs WHERE rfq_id = ?");
    $requestIdStmt->execute([$rfq_id]);
    $requestId = $requestIdStmt->fetchColumn();
    
    $pdo->prepare("
        UPDATE procurement_requests
        SET status = 'AWARDED'
        WHERE request_id = ?
    ")->execute([$requestId]);
    
    /* Send notification to requestor */
    if ($requestId) {
        require_once $_SERVER['DOCUMENT_ROOT']."/config/notifications.php";
        notifyRequestFinalized($requestId, 'AWARDED');
    }


    /* Audit */
    $pdo->prepare("
        INSERT INTO audit_log
        (table_name, record_id, action, changed_by, change_date, notes)
        VALUES ('rfqs', ?, 'AWARD', ?, NOW(), ?)
    ")->execute([
        $rfq_id,
        $_SESSION['user_id'],
        "RFQ awarded to Vendor ID $vendor_id (Quote ID $quote_id)"
    ]);

    /* Commit Transaction */
    $pdo->commit();

    ProcurementTaskService::recordCompletion(
        $pdo,
        (int)$prData['pr_request_id'],
        ProcurementTaskService::TASK_RFQ,
        (int)($_SESSION['user_id'] ?? 0) ?: null,
        'RFQ awarded'
    );

} catch (Exception $e) {

    $pdo->rollBack();
    pop(extractDbMessage($e), '/rfq/view.php?id='.$rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

header("Location: view.php?id=".$rfq_id);
exit;