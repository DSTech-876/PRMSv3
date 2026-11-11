<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/config/workflow.php';

/**
 * Parallel Procurement Mode
 * =========================
 * Verifies that LEGACY_MODE keeps every sequential gate in force, and that
 * PARALLEL_MODE replaces stage-based blocking with permission-based actions
 * once the approval chain is cleared.
 */
final class ParallelProcurementModeTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE system_config (config_key TEXT PRIMARY KEY, config_value TEXT, description TEXT, created_at TEXT)');
        $this->pdo->exec('CREATE TABLE request_approvals (id INTEGER PRIMARY KEY, request_id INTEGER, role TEXT, status TEXT, entity_type TEXT, stage_order INTEGER)');
        $this->pdo->exec('CREATE TABLE procurement_requests (request_id INTEGER PRIMARY KEY, status TEXT, workflow_mode TEXT DEFAULT "INHERIT")');

        $GLOBALS['pdo'] = $this->pdo;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pdo']);
    }

    private function setGlobalMode(string $mode): void
    {
        $this->pdo->exec("DELETE FROM system_config WHERE config_key = 'procurement_workflow_mode'");
        $stmt = $this->pdo->prepare('INSERT INTO system_config (config_key, config_value) VALUES (?, ?)');
        $stmt->execute([WorkflowModeService::CONFIG_KEY, $mode]);
    }

    private function request(string $status, string $mode = 'INHERIT', int $id = 1): array
    {
        return ['request_id' => $id, 'status' => $status, 'workflow_mode' => $mode];
    }

    // ------------------------------------------------------------------
    // Mode resolution
    // ------------------------------------------------------------------

    public function testModeDefaultsToLegacyWhenUnset(): void
    {
        $this->assertSame(WorkflowModeService::MODE_LEGACY, WorkflowModeService::getMode($this->pdo));
        $this->assertFalse(WorkflowModeService::isParallelMode($this->pdo));
    }

    public function testCorruptModeValueFallsBackToLegacy(): void
    {
        $this->setGlobalMode('SOMETHING_ELSE');

        $this->assertSame(
            WorkflowModeService::MODE_LEGACY,
            WorkflowModeService::getMode($this->pdo),
            'An unrecognised setting must never unlock the workflow'
        );
    }

    public function testExistingRecordsInheritTheGlobalModeWithoutDataChanges(): void
    {
        $this->setGlobalMode(WorkflowModeService::MODE_PARALLEL);
        $legacyRecord = $this->request('HOD_APPROVED', 'INHERIT');

        $this->assertTrue(
            WorkflowModeService::isParallelRequest($this->pdo, $legacyRecord),
            'A pre-existing record with no pinned mode must follow the global switch'
        );
    }

    public function testPinnedRequestModeOverridesTheGlobalSetting(): void
    {
        $this->setGlobalMode(WorkflowModeService::MODE_PARALLEL);

        $this->assertFalse(WorkflowModeService::isParallelRequest($this->pdo, $this->request('HOD_APPROVED', 'LEGACY_MODE')));
        $this->assertTrue(WorkflowModeService::isParallelRequest($this->pdo, $this->request('HOD_APPROVED', 'PARALLEL_MODE')));
    }

    // ------------------------------------------------------------------
    // Procurement eligibility
    // ------------------------------------------------------------------

    public function testApprovalGrantsProcurementEligibility(): void
    {
        $this->assertTrue(WorkflowModeService::isApprovedForProcurement($this->pdo, $this->request('HOD_APPROVED')));
        $this->assertTrue(WorkflowModeService::isApprovedForProcurement($this->pdo, $this->request('QUOTE_APPROVED')));
    }

    public function testUnapprovedAndTerminalRequestsAreNeverEligible(): void
    {
        foreach (['DRAFT', 'DECLINED', 'CANCELLED', 'PAUSED'] as $status) {
            $this->assertFalse(
                WorkflowModeService::isApprovedForProcurement($this->pdo, $this->request($status)),
                "$status must never be treated as approved for procurement"
            );
        }
    }

    public function testSubmittedRequestIsEligibleOnlyAfterItsApprovalChainCloses(): void
    {
        $submitted = $this->request('SUBMITTED');
        $this->pdo->exec("INSERT INTO request_approvals (request_id, role, status, entity_type, stage_order) VALUES (1, 'Branch Head', 'pending', 'REQUEST', 1)");

        $this->assertFalse(WorkflowModeService::isApprovedForProcurement($this->pdo, $submitted));

        $this->pdo->exec("UPDATE request_approvals SET status = 'approved' WHERE request_id = 1");

        $this->assertTrue(WorkflowModeService::isApprovedForProcurement($this->pdo, $submitted));
    }

    public function testSubmittedRequestWithNoApprovalChainIsNotEligible(): void
    {
        $this->assertFalse(
            WorkflowModeService::isApprovedForProcurement($this->pdo, $this->request('SUBMITTED')),
            'A request with no approval records must not be silently unlocked'
        );
    }

    // ------------------------------------------------------------------
    // Status gates
    // ------------------------------------------------------------------

    public function testLegacyModeKeepsTheFundVerificationDependencyBeforeRfq(): void
    {
        $this->setGlobalMode(WorkflowModeService::MODE_LEGACY);
        $rfqStatuses = ['SUBMITTED', 'HOD_APPROVED', 'FUNDS_VERIFIED', 'RFQ_LETTER_AVAILABLE'];

        $this->assertFalse(
            workflowStatusGateAllows($this->request('COMMITMENT_APPROVED'), $rfqStatuses, 'CREATE_RFQ', $this->pdo),
            'LEGACY_MODE must continue to reject out-of-stage RFQ creation'
        );
        $this->assertTrue(workflowStatusGateAllows($this->request('FUNDS_VERIFIED'), $rfqStatuses, 'CREATE_RFQ', $this->pdo));
    }

    public function testParallelModeRemovesTheFundVerificationDependencyBeforeRfq(): void
    {
        $this->setGlobalMode(WorkflowModeService::MODE_PARALLEL);

        // HOD_APPROVED, funds NOT verified — legacy allowlist for commitments
        // would reject an un-verified request at an unexpected stage.
        $this->assertTrue(
            workflowStatusGateAllows($this->request('COMMITMENT_APPROVED'), ['FUNDS_VERIFIED'], 'CREATE_RFQ', $this->pdo),
            'An approved request must reach RFQ without waiting on Accounts'
        );
    }

    public function testParallelModeAllowsCommitmentAndPoInEitherOrder(): void
    {
        $this->setGlobalMode(WorkflowModeService::MODE_PARALLEL);

        $commitmentStatuses = ['QUOTE_APPROVED', 'AWARDED', 'FUNDS_VERIFIED', 'COMMITMENTS_PENDING'];
        $poStatuses         = ['COMMITMENTS_PENDING', 'COMMITMENT_APPROVED', 'PO_PENDING', 'AWARDED'];

        // Commitment before PO: request still only at HOD_APPROVED.
        $this->assertTrue(workflowStatusGateAllows($this->request('HOD_APPROVED'), $commitmentStatuses, 'CREATE_COMMITMENT', $this->pdo));
        // PO before the commitment is approved.
        $this->assertTrue(workflowStatusGateAllows($this->request('HOD_APPROVED'), $poStatuses, 'CREATE_PO', $this->pdo));
    }

    public function testParallelModeAllowlistedStatusChecksApprovalWithoutTypeError(): void
    {
        $this->setGlobalMode(WorkflowModeService::MODE_PARALLEL);

        $commitmentStatuses = ['QUOTE_APPROVED', 'AWARDED', 'HOD_APPROVED', 'SUBMITTED'];

        $this->assertTrue(workflowStatusGateAllows($this->request('HOD_APPROVED'), $commitmentStatuses, 'CREATE_COMMITMENT', $this->pdo));
        $this->assertFalse(
            workflowStatusGateAllows($this->request('SUBMITTED'), $commitmentStatuses, 'CREATE_COMMITMENT', $this->pdo),
            'An allowlisted status must still require a cleared approval chain in PARALLEL_MODE'
        );
    }

    public function testParallelModeStillBlocksUnapprovedRequests(): void
    {
        $this->setGlobalMode(WorkflowModeService::MODE_PARALLEL);

        $this->assertFalse(
            workflowStatusGateAllows($this->request('DRAFT'), ['FUNDS_VERIFIED'], 'CREATE_RFQ', $this->pdo),
            'Only genuinely invalid actions should be restricted — a draft is one of them'
        );
        $this->assertFalse(
            workflowStatusGateAllows($this->request('CANCELLED'), ['FUNDS_VERIFIED'], 'CREATE_PO', $this->pdo)
        );
    }

    // ------------------------------------------------------------------
    // Transition engine
    // ------------------------------------------------------------------

    public function testLegacyTransitionRulesAreUnchanged(): void
    {
        $this->setGlobalMode(WorkflowModeService::MODE_LEGACY);

        $this->assertTrue(canTransitionInMode($this->request('HOD_APPROVED'), 'FUNDS_VERIFIED', $this->pdo));
        $this->assertFalse(
            canTransitionInMode($this->request('HOD_APPROVED'), 'INVOICE_RECEIVED', $this->pdo),
            'LEGACY_MODE must keep the sequential pipeline ordering'
        );
    }

    public function testParallelModeAllowsOutOfOrderOperationalTransitions(): void
    {
        $this->setGlobalMode(WorkflowModeService::MODE_PARALLEL);

        $this->assertTrue(
            canTransitionInMode($this->request('HOD_APPROVED'), 'INVOICE_RECEIVED', $this->pdo),
            'Departments operate independently once the request is approved'
        );
        $this->assertTrue(canTransitionInMode($this->request('HOD_APPROVED'), 'PO_PENDING', $this->pdo));
    }

    public function testParallelModeDoesNotResurrectTerminalRequests(): void
    {
        $this->setGlobalMode(WorkflowModeService::MODE_PARALLEL);

        $this->assertFalse(canTransitionInMode($this->request('CANCELLED'), 'PO_PENDING', $this->pdo));
        $this->assertFalse(canTransitionInMode($this->request('DECLINED'), 'PO_PENDING', $this->pdo));
        $this->assertFalse(
            canTransitionInMode($this->request('DRAFT'), 'PO_PENDING', $this->pdo),
            'An unapproved draft must still follow the pipeline'
        );
    }

    public function testParallelModeRejectsUnknownTargetStatuses(): void
    {
        $this->setGlobalMode(WorkflowModeService::MODE_PARALLEL);

        $this->assertFalse(canTransitionInMode($this->request('HOD_APPROVED'), 'NOT_A_REAL_STATUS', $this->pdo));
    }

    public function testCancellationRulesAreUnaffectedByParallelMode(): void
    {
        $this->setGlobalMode(WorkflowModeService::MODE_PARALLEL);

        $this->assertTrue(canTransitionInMode($this->request('HOD_APPROVED'), 'CANCELLED', $this->pdo));
        $this->assertFalse(canTransitionInMode($this->request('COMPLETED'), 'CANCELLED', $this->pdo));
    }

    public function testGatesFailClosedWithoutADatabaseHandle(): void
    {
        $this->setGlobalMode(WorkflowModeService::MODE_PARALLEL);
        unset($GLOBALS['pdo']);

        $this->assertFalse(parallelActionAllowed($this->request('HOD_APPROVED'), 'CREATE_RFQ'));
    }
}
