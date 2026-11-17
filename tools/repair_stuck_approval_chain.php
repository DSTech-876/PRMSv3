<?php
/**
 * Data Repair Script: Reopened/Duplicate Approval Stage Repair
 *
 * Root cause: procurement/approve.php used to mark a request_approvals
 * stage as 'approved' BEFORE validating/writing the new
 * procurement_requests.status. If that later step failed, the stage stayed
 * 'approved' while the request's status never advanced past SUBMITTED.
 * On every subsequent page load, ensureRequestApprovalChain() saw zero
 * *pending* rows and (incorrectly) treated the chain as "missing", so it
 * recreated a brand-new pending chain — reopening the stage that had
 * already been approved. From then on the request appears permanently
 * "awaiting approval" even though e.g. the Branch Head already approved it.
 *
 * Both root causes are now fixed in code (procurement/approve.php and
 * config/workflow.php::ensureRequestApprovalChain()). This script detects
 * and repairs requests that were already left in the broken state before
 * that fix was deployed.
 *
 * For each affected request, it:
 *   1. Deletes the duplicate "pending" rows that reopened an already
 *      approved/rejected stage.
 *   2. Advances procurement_requests.status to the correct next status
 *      using the same resolver the normal approval flow uses, so request
 *      type / threshold / branch routing stays consistent.
 *   3. Writes an audit log entry recording the repair.
 *
 * Usage:
 *   php repair_stuck_approval_chain.php --dry-run    # Report only (default)
 *   php repair_stuck_approval_chain.php --execute    # Apply the repair
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';

$argvList = (isset($argv) && is_array($argv)) ? $argv : [];
$execute  = in_array('--execute', $argvList, true);
$mode     = $execute ? 'EXECUTION' : 'DRY-RUN';

echo "================================================================================\n";
echo "Stuck Approval Chain Repair ($mode)\n";
echo "================================================================================\n\n";

try {
    // Requests still SUBMITTED that have at least one approved/rejected
    // REQUEST-level stage AND at least one pending row re-using a role that
    // was already approved/rejected — the signature of the reopened chain.
    $stmt = $pdo->query("
        SELECT DISTINCT pr.request_id, pr.request_number, pr.request_type,
               pr.estimated_value, pr.branch_id, pr.status
        FROM procurement_requests pr
        JOIN request_approvals done
             ON done.request_id = pr.request_id
            AND (done.entity_type IS NULL OR done.entity_type = '' OR done.entity_type = 'REQUEST')
            AND done.status IN ('approved', 'rejected')
        JOIN request_approvals dupe
             ON dupe.request_id = pr.request_id
            AND (dupe.entity_type IS NULL OR dupe.entity_type = '' OR dupe.entity_type = 'REQUEST')
            AND dupe.status = 'pending'
            AND dupe.role = done.role
        WHERE pr.status = 'SUBMITTED'
        ORDER BY pr.request_id ASC
    ");
    $affected = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($affected) . " request(s) with a reopened/duplicate approval stage.\n\n";

    $successCount = 0;
    $errorCount = 0;

    foreach ($affected as $req) {
        $requestId = (int)$req['request_id'];
        echo "  • Request ID: {$requestId} (" . ($req['request_number'] ?? 'N/A') . ")\n";

        try {
            // Highest stage_order already approved — the real point the
            // request reached before the bug reopened it.
            $maxApprovedStmt = $pdo->prepare("
                SELECT role, stage_order
                FROM request_approvals
                WHERE request_id = ?
                  AND (entity_type IS NULL OR entity_type = '' OR entity_type = 'REQUEST')
                  AND status = 'approved'
                ORDER BY stage_order DESC
                LIMIT 1
            ");
            $maxApprovedStmt->execute([$requestId]);
            $maxApproved = $maxApprovedStmt->fetch(PDO::FETCH_ASSOC);

            if (!$maxApproved) {
                echo "    skipped — no approved stage found (unexpected)\n";
                continue;
            }

            // Compute the correct next status exactly as the normal approval
            // flow would, using the duplicate pending row still in place.
            $nextStatus = getNextStatusAfterApproval($pdo, $requestId, (string)$maxApproved['role']);
            echo "    current status: {$req['status']} -> repaired status: {$nextStatus}\n";

            if ($execute) {
                $pdo->beginTransaction();

                // Remove every reopened pending row whose role/stage was
                // already decided (approved or rejected).
                $pdo->prepare("
                    DELETE dupe FROM request_approvals dupe
                    JOIN request_approvals done
                         ON done.request_id = dupe.request_id
                        AND done.role = dupe.role
                        AND done.status IN ('approved', 'rejected')
                    WHERE dupe.request_id = ?
                      AND dupe.status = 'pending'
                      AND (dupe.entity_type IS NULL OR dupe.entity_type = '' OR dupe.entity_type = 'REQUEST')
                ")->execute([$requestId]);

                $pdo->prepare("
                    UPDATE procurement_requests
                    SET status = ?
                    WHERE request_id = ?
                ")->execute([$nextStatus, $requestId]);

                logAudit(
                    $pdo,
                    'procurement_requests',
                    $requestId,
                    'STUCK_APPROVAL_CHAIN_REPAIRED',
                    "Removed reopened duplicate approval stage(s) and advanced status from {$req['status']} to {$nextStatus}"
                );

                $pdo->commit();
                echo "    ✓ repaired\n";
            }

            $successCount++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo "    ✗ failed: " . $e->getMessage() . "\n";
            $errorCount++;
        }
    }

    echo "\n================================================================================\n";
    echo "Summary: {$successCount} " . ($execute ? 'repaired' : 'would be repaired') . ", {$errorCount} failed\n";
    if (!$execute) {
        echo "Dry-run only — rerun with --execute to apply changes.\n";
    }
    echo "================================================================================\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone.\n";
