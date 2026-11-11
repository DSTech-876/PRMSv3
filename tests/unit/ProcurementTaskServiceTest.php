<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/services/WorkflowModeService.php';
require_once dirname(__DIR__, 2) . '/services/ProcurementTaskService.php';

/**
 * Task-completion tracking that replaces strict pipeline dependency logic.
 */
final class ProcurementTaskServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('CREATE TABLE procurement_requests (
            request_id INTEGER PRIMARY KEY,
            request_number TEXT,
            status TEXT,
            workflow_mode TEXT DEFAULT "INHERIT",
            requires_rfq INTEGER DEFAULT 1,
            funds_available INTEGER DEFAULT 0,
            finance_reviewed_by INTEGER NULL,
            finance_reviewed_at TEXT NULL,
            approved_by INTEGER NULL,
            approved_at TEXT NULL
        )');
        $this->pdo->exec('CREATE TABLE request_approvals (
            id INTEGER PRIMARY KEY, request_id INTEGER, role TEXT,
            status TEXT, entity_type TEXT, stage_order INTEGER
        )');
        $this->pdo->exec('CREATE TABLE procurement_tasks (
            task_id INTEGER PRIMARY KEY,
            request_id INTEGER, task_key TEXT, task_status TEXT,
            task_completed_by INTEGER NULL, task_completed_date TEXT NULL,
            notes TEXT DEFAULT ""
        )');
        $this->pdo->exec('CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT)');
        $this->pdo->exec('CREATE TABLE system_config (config_key TEXT PRIMARY KEY, config_value TEXT, description TEXT, created_at TEXT)');
        $this->pdo->exec('CREATE TABLE rfqs (rfq_id INTEGER PRIMARY KEY, request_id INTEGER, rfq_number TEXT, status TEXT, rfq_date TEXT, created_at TEXT, created_by INTEGER)');
        $this->pdo->exec('CREATE TABLE commitments (commitment_id INTEGER PRIMARY KEY, request_id INTEGER, commitment_number TEXT, status TEXT, document_path TEXT, approved_at TEXT NULL, created_at TEXT, commitment_type TEXT)');
        $this->pdo->exec('CREATE TABLE purchase_orders (po_id INTEGER PRIMARY KEY, commitment_id INTEGER, po_number TEXT, status TEXT, approved_at TEXT NULL, approved_by INTEGER NULL, created_at TEXT)');
        $this->pdo->exec('CREATE TABLE invoices (invoice_id INTEGER PRIMARY KEY, po_id INTEGER, invoice_number TEXT, status TEXT, invoice_amount REAL, created_at TEXT)');
        $this->pdo->exec('CREATE TABLE payments (payment_id INTEGER PRIMARY KEY, invoice_id INTEGER, payment_reference TEXT, payment_date TEXT, payment_amount REAL, created_by INTEGER)');

        $this->pdo->exec("INSERT INTO users (user_id, full_name) VALUES (7, 'Avery Finance')");
        $this->pdo->exec("INSERT INTO system_config (config_key, config_value) VALUES ('procurement_workflow_mode', 'PARALLEL_MODE')");
    }

    private function seedRequest(string $status = 'HOD_APPROVED', array $overrides = []): array
    {
        $row = array_merge([
            'request_id'     => 1,
            'request_number' => 'PR001',
            'status'         => $status,
            'workflow_mode'  => 'INHERIT',
            'requires_rfq'   => 1,
        ], $overrides);

        $this->pdo->prepare(
            'INSERT INTO procurement_requests (request_id, request_number, status, workflow_mode, requires_rfq, funds_available, finance_reviewed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $row['request_id'], $row['request_number'], $row['status'], $row['workflow_mode'],
            $row['requires_rfq'], $overrides['funds_available'] ?? 0, $overrides['finance_reviewed_at'] ?? null,
        ]);

        return $this->pdo->query('SELECT * FROM procurement_requests WHERE request_id = 1')->fetch(PDO::FETCH_ASSOC);
    }

    /** @return array<string,string> task_key => task_status */
    private function statusesOf(array $board): array
    {
        $map = [];
        foreach ($board['tasks'] as $task) {
            $map[$task['key']] = $task['task_status'];
        }
        return $map;
    }

    public function testLegacyRecordWithNoTaskRowsStillProducesAFullBoard(): void
    {
        $request = $this->seedRequest('HOD_APPROVED');

        $board = ProcurementTaskService::getTaskBoard($this->pdo, $request);
        $statuses = $this->statusesOf($board);

        $this->assertCount(7, $board['tasks'], 'Every task must be represented, even with no stored rows');
        $this->assertSame(ProcurementTaskService::STATUS_COMPLETED, $statuses[ProcurementTaskService::TASK_REQUEST_APPROVAL]);
        $this->assertSame(ProcurementTaskService::STATUS_PENDING, $statuses[ProcurementTaskService::TASK_RFQ]);
        $this->assertSame(ProcurementTaskService::STATUS_PENDING, $statuses[ProcurementTaskService::TASK_COMMITMENT]);
        $this->assertSame(ProcurementTaskService::STATUS_PENDING, $statuses[ProcurementTaskService::TASK_PAYMENT]);
    }

    public function testFundVerificationIsOptionalAndPendingUntilRecorded(): void
    {
        $request = $this->seedRequest('HOD_APPROVED');
        $board   = ProcurementTaskService::getTaskBoard($this->pdo, $request);

        $fundTask = null;
        foreach ($board['tasks'] as $task) {
            if ($task['key'] === ProcurementTaskService::TASK_FUND_VERIFICATION) {
                $fundTask = $task;
            }
        }

        $this->assertNotNull($fundTask);
        $this->assertTrue($fundTask['optional'], 'Fund verification must be flagged optional/informational');
        $this->assertSame(ProcurementTaskService::STATUS_PENDING, $fundTask['task_status']);
        $this->assertSame('Accounts', $fundTask['department']);
    }

    public function testFundVerificationDerivesCompletedFromExistingFinanceReview(): void
    {
        $request = $this->seedRequest('HOD_APPROVED', [
            'funds_available'     => 1,
            'finance_reviewed_at' => '2026-02-01 09:00:00',
        ]);

        $statuses = $this->statusesOf(ProcurementTaskService::getTaskBoard($this->pdo, $request));

        $this->assertSame(
            ProcurementTaskService::STATUS_COMPLETED,
            $statuses[ProcurementTaskService::TASK_FUND_VERIFICATION]
        );
    }

    public function testCommitmentRecordedBeforePoIsReportedIndependently(): void
    {
        $request = $this->seedRequest('HOD_APPROVED');
        $this->pdo->exec("INSERT INTO commitments (commitment_id, request_id, commitment_number, status, commitment_type, created_at)
                          VALUES (10, 1, 'CM-001', 'closed', 'ORIGINAL', '2026-02-02 10:00:00')");

        $statuses = $this->statusesOf(ProcurementTaskService::getTaskBoard($this->pdo, $request));

        $this->assertSame(ProcurementTaskService::STATUS_COMPLETED, $statuses[ProcurementTaskService::TASK_COMMITMENT]);
        $this->assertSame(ProcurementTaskService::STATUS_PENDING, $statuses[ProcurementTaskService::TASK_PO],
            'A completed commitment must not imply the PO is done');
        $this->assertSame(ProcurementTaskService::STATUS_PENDING, $statuses[ProcurementTaskService::TASK_RFQ],
            'Commitment completion must not be blocked by, nor imply, the RFQ task');
    }

    public function testPoRecordedBeforeCommitmentApprovalIsStillTracked(): void
    {
        $request = $this->seedRequest('HOD_APPROVED');
        $this->pdo->exec("INSERT INTO commitments (commitment_id, request_id, commitment_number, status, commitment_type, created_at)
                          VALUES (10, 1, 'CM-001', 'open', 'ORIGINAL', '2026-02-02 10:00:00')");
        $this->pdo->exec("INSERT INTO purchase_orders (po_id, commitment_id, po_number, status, approved_at, created_at)
                          VALUES (20, 10, 'PO-001', 'Open', '2026-02-03 10:00:00', '2026-02-03 09:00:00')");

        $statuses = $this->statusesOf(ProcurementTaskService::getTaskBoard($this->pdo, $request));

        $this->assertSame(ProcurementTaskService::STATUS_IN_PROGRESS, $statuses[ProcurementTaskService::TASK_COMMITMENT]);
        $this->assertSame(ProcurementTaskService::STATUS_COMPLETED, $statuses[ProcurementTaskService::TASK_PO]);
    }

    public function testPaymentProgressTracksInvoiceSettlement(): void
    {
        $request = $this->seedRequest('HOD_APPROVED');
        $this->pdo->exec("INSERT INTO commitments (commitment_id, request_id, commitment_number, status, commitment_type, created_at) VALUES (10, 1, 'CM-001', 'closed', 'ORIGINAL', '2026-02-02')");
        $this->pdo->exec("INSERT INTO purchase_orders (po_id, commitment_id, po_number, status, created_at) VALUES (20, 10, 'PO-001', 'Open', '2026-02-03')");
        $this->pdo->exec("INSERT INTO invoices (invoice_id, po_id, invoice_number, status, invoice_amount, created_at) VALUES (30, 20, 'INV-1', 'Partially Paid', 1000, '2026-02-04')");

        $statuses = $this->statusesOf(ProcurementTaskService::getTaskBoard($this->pdo, $request));
        $this->assertSame(ProcurementTaskService::STATUS_COMPLETED, $statuses[ProcurementTaskService::TASK_INVOICE]);
        $this->assertSame(ProcurementTaskService::STATUS_IN_PROGRESS, $statuses[ProcurementTaskService::TASK_PAYMENT]);

        $this->pdo->exec("UPDATE invoices SET status = 'Paid' WHERE invoice_id = 30");
        $statuses = $this->statusesOf(ProcurementTaskService::getTaskBoard($this->pdo, $request));
        $this->assertSame(ProcurementTaskService::STATUS_COMPLETED, $statuses[ProcurementTaskService::TASK_PAYMENT]);
    }

    public function testRfqIsNotRequiredForDirectProcurement(): void
    {
        $request  = $this->seedRequest('HOD_APPROVED', ['requires_rfq' => 0]);
        $statuses = $this->statusesOf(ProcurementTaskService::getTaskBoard($this->pdo, $request));

        $this->assertSame(ProcurementTaskService::STATUS_NOT_REQUIRED, $statuses[ProcurementTaskService::TASK_RFQ]);
    }

    public function testMarkTaskStampsCompletionMetadataAndOverridesDerivedState(): void
    {
        $request = $this->seedRequest('HOD_APPROVED');

        $this->assertTrue(ProcurementTaskService::markTask(
            $this->pdo,
            1,
            ProcurementTaskService::TASK_RFQ,
            ProcurementTaskService::STATUS_COMPLETED,
            7,
            'RFQ sent to vendors'
        ));

        $board = ProcurementTaskService::getTaskBoard($this->pdo, $request);
        $rfq   = null;
        foreach ($board['tasks'] as $task) {
            if ($task['key'] === ProcurementTaskService::TASK_RFQ) {
                $rfq = $task;
            }
        }

        $this->assertSame(ProcurementTaskService::STATUS_COMPLETED, $rfq['task_status']);
        $this->assertSame(7, $rfq['task_completed_by']);
        $this->assertSame('Avery Finance', $rfq['completed_by_name']);
        $this->assertNotEmpty($rfq['task_completed_date']);
        $this->assertSame('RFQ sent to vendors', $rfq['detail']);
    }

    public function testMarkTaskIsIdempotentAndReopeningClearsTheSignature(): void
    {
        $this->seedRequest('HOD_APPROVED');

        ProcurementTaskService::markTask($this->pdo, 1, ProcurementTaskService::TASK_PO, ProcurementTaskService::STATUS_COMPLETED, 7);
        ProcurementTaskService::markTask($this->pdo, 1, ProcurementTaskService::TASK_PO, ProcurementTaskService::STATUS_PENDING, 7);

        $rows = $this->pdo->query("SELECT task_status, task_completed_by, task_completed_date FROM procurement_tasks WHERE task_key = 'PO'")
                          ->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows, 'Re-marking a task must update in place, not duplicate');
        $this->assertSame('PENDING', $rows[0]['task_status']);
        $this->assertNull($rows[0]['task_completed_by']);
        $this->assertNull($rows[0]['task_completed_date']);
    }

    public function testUnknownTaskKeyIsRejected(): void
    {
        $this->seedRequest('HOD_APPROVED');

        $this->assertFalse(ProcurementTaskService::markTask($this->pdo, 1, 'NOT_A_TASK', ProcurementTaskService::STATUS_COMPLETED, 7));
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM procurement_tasks')->fetchColumn());
    }

    public function testNotRequiredTasksAreExcludedFromProgressPercentage(): void
    {
        $request = $this->seedRequest('HOD_APPROVED', ['requires_rfq' => 0]);
        $board   = ProcurementTaskService::getTaskBoard($this->pdo, $request);

        $this->assertSame(1, $board['summary']['not_required']);
        $this->assertSame(6, $board['summary']['total'] - $board['summary']['not_required']);
        // Only REQUEST_APPROVAL is complete out of 6 actionable tasks.
        $this->assertSame(17, $board['summary']['percent_complete']);
    }
}
