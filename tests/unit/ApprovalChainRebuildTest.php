<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/workflow.php';

/**
 * Covers rebuildPendingApprovalChain(), which re-routes the outstanding part
 * of an approval chain when a request is moved to a different branch while
 * it is already travelling through its approval chain.
 */
final class ApprovalChainRebuildTest extends PHPUnit\Framework\TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE request_approvals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type TEXT,
                entity_id INTEGER,
                request_id INTEGER,
                role TEXT,
                stage_order INTEGER,
                status TEXT,
                approved_by INTEGER NULL,
                approved_at TEXT NULL,
                rejection_reason TEXT NULL
            )
        ");
        $this->pdo->exec("
            CREATE TABLE system_config (
                config_key TEXT PRIMARY KEY,
                config_value TEXT
            )
        ");
        $this->pdo->exec("INSERT INTO system_config (config_key, config_value) VALUES ('hod_approval_threshold', '500000')");
        $this->pdo->exec("INSERT INTO system_config (config_key, config_value) VALUES ('committee_review_threshold', '3000000')");
    }

    private function seedApproval(int $requestId, string $role, int $stageOrder, string $status): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO request_approvals (entity_type, entity_id, request_id, role, stage_order, status)
            VALUES ('REQUEST', ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$requestId, $requestId, $role, $stageOrder, $status]);
    }

    /** @return array<int, array{role: string, stage_order: int, status: string}> */
    private function approvals(int $requestId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT role, stage_order, status
            FROM request_approvals
            WHERE request_id = ?
            ORDER BY stage_order ASC, id ASC
        ");
        $stmt->execute([$requestId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testApprovedStagesArePreservedAndPendingStagesRerouted(): void
    {
        // Over-threshold request in a generic branch: HOD → Finance Officer.
        $this->seedApproval(1, 'HOD', 1, 'approved');
        $this->seedApproval(1, 'Finance Officer', 2, 'pending');

        // Move the request to the HRM&A branch (5).
        $result = rebuildPendingApprovalChain($this->pdo, 1, 'REGULAR', 750000.00, 5);

        $this->assertSame(['HOD', 'Director HRM&A', 'Finance Officer'], $result['chain']);
        $this->assertSame(['Director HRM&A', 'Finance Officer'], $result['pending_roles']);
        $this->assertSame('Director HRM&A', $result['next_role']);

        $rows = $this->approvals(1);
        $this->assertCount(3, $rows);
        $this->assertSame(['HOD', 'approved', 1], [$rows[0]['role'], $rows[0]['status'], (int)$rows[0]['stage_order']]);
        $this->assertSame(['Director HRM&A', 'pending', 2], [$rows[1]['role'], $rows[1]['status'], (int)$rows[1]['stage_order']]);
        $this->assertSame(['Finance Officer', 'pending', 3], [$rows[2]['role'], $rows[2]['status'], (int)$rows[2]['stage_order']]);
    }

    public function testCompletedRolesAreNotRequestedAgain(): void
    {
        $this->seedApproval(2, 'HOD', 1, 'approved');
        $this->seedApproval(2, 'Director HRM&A', 2, 'approved');
        $this->seedApproval(2, 'Finance Officer', 3, 'pending');

        // Moving back to a generic branch drops the Director stage from the
        // chain but must not re-request the already-approved HOD stage.
        $result = rebuildPendingApprovalChain($this->pdo, 2, 'REGULAR', 750000.00, 3);

        $this->assertSame(['Finance Officer'], $result['pending_roles']);
        $this->assertSame('Finance Officer', $result['next_role']);

        $rows = $this->approvals(2);
        $this->assertCount(3, $rows);
        $this->assertSame('Finance Officer', $rows[2]['role']);
        $this->assertSame('pending', $rows[2]['status']);
        $this->assertSame(4, (int)$rows[2]['stage_order']);
    }

    public function testChainIsRebuiltWhenNoStageHasBeenCompleted(): void
    {
        $this->seedApproval(3, 'HOD', 1, 'pending');
        $this->seedApproval(3, 'Finance Officer', 2, 'pending');

        $result = rebuildPendingApprovalChain($this->pdo, 3, 'REGULAR', 750000.00, 6);

        $this->assertSame(['HOD', 'Deputy Government Chemist', 'Finance Officer'], $result['pending_roles']);
        $rows = $this->approvals(3);
        $this->assertCount(3, $rows);
        $this->assertSame([1, 2, 3], array_map(static fn(array $r): int => (int)$r['stage_order'], $rows));
    }

    public function testRejectedStagesStillCountAsCompletedForOrdering(): void
    {
        $this->seedApproval(4, 'HOD', 1, 'rejected');

        $result = rebuildPendingApprovalChain($this->pdo, 4, 'REGULAR', 750000.00, 5);

        $this->assertSame(['Director HRM&A', 'Finance Officer'], $result['pending_roles']);
        $rows = $this->approvals(4);
        $this->assertSame(2, (int)$rows[1]['stage_order']);
        $this->assertSame(3, (int)$rows[2]['stage_order']);
    }

    public function testInvalidRequestIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        rebuildPendingApprovalChain($this->pdo, 0, 'REGULAR', 1000.00, 5);
    }
}
