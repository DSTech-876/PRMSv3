<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/services/RFQQuoteApprovalService.php';

/**
 * Regression test for Deputy Government Chemist (DGC) authorization in RFQ Branch Head approvals.
 * Ensures DGC users for branch 6 (Analytical) can approve RFQ quotes.
 */
final class RFQQuoteApprovalServiceDGCAuthTest extends PHPUnit\Framework\TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Unset DOCUMENT_ROOT to avoid notification dispatch in tests
        unset($_SERVER['DOCUMENT_ROOT']);
        $this->createTestTables();
    }

    private function createTestTables(): void
    {
        $this->pdo->exec("
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE users (
                user_id INTEGER PRIMARY KEY AUTOINCREMENT,
                role_id INTEGER NOT NULL,
                branch_id INTEGER,
                full_name TEXT NOT NULL,
                is_active BOOLEAN DEFAULT 1,
                FOREIGN KEY (role_id) REFERENCES roles(id)
            )
        ");

        $this->pdo->exec("
            CREATE TABLE branches (
                branch_id INTEGER PRIMARY KEY,
                branch_name TEXT NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE procurement_requests (
                request_id INTEGER PRIMARY KEY AUTOINCREMENT,
                request_number TEXT UNIQUE NOT NULL,
                branch_id INTEGER NOT NULL,
                created_by INTEGER NOT NULL,
                description TEXT,
                estimated_value DECIMAL(15, 2),
                status TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (branch_id) REFERENCES branches(branch_id),
                FOREIGN KEY (created_by) REFERENCES users(user_id)
            )
        ");

        $this->pdo->exec("
            CREATE TABLE rfqs (
                rfq_id INTEGER PRIMARY KEY AUTOINCREMENT,
                rfq_number TEXT UNIQUE NOT NULL,
                request_id INTEGER NOT NULL,
                requestor_reviewer_id INTEGER,
                requestor_spec_review_status TEXT DEFAULT 'PENDING',
                requestor_reviewed_at DATETIME,
                requestor_review_comments TEXT,
                branch_head_approval_status TEXT DEFAULT 'PENDING',
                branch_head_approver_id INTEGER,
                branch_head_approved_at DATETIME,
                branch_head_comments TEXT,
                submission_deadline DATE,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (request_id) REFERENCES procurement_requests(request_id),
                FOREIGN KEY (requestor_reviewer_id) REFERENCES users(user_id),
                FOREIGN KEY (branch_head_approver_id) REFERENCES users(user_id)
            )
        ");

        $this->pdo->exec("
            CREATE TABLE rfq_vendors (
                rfq_vendor_id INTEGER PRIMARY KEY AUTOINCREMENT,
                rfq_id INTEGER NOT NULL,
                vendor_id INTEGER NOT NULL,
                FOREIGN KEY (rfq_id) REFERENCES rfqs(rfq_id)
            )
        ");

        $this->pdo->exec("
            CREATE TABLE vendors (
                vendor_id INTEGER PRIMARY KEY AUTOINCREMENT,
                vendor_name TEXT NOT NULL,
                contact_person TEXT,
                email TEXT
            )
        ");

        $this->pdo->exec("
            CREATE TABLE rfq_quotes (
                quote_id INTEGER PRIMARY KEY AUTOINCREMENT,
                rfq_vendor_id INTEGER NOT NULL,
                quote_amount DECIMAL(15, 2) NOT NULL,
                gct_amount DECIMAL(15, 2),
                review_status TEXT DEFAULT 'PENDING',
                review_comments TEXT,
                quote_file TEXT,
                submitted_at DATETIME,
                currency TEXT DEFAULT 'JMD',
                usd_rate DECIMAL(10, 4),
                is_selected BOOLEAN DEFAULT 0,
                is_deleted BOOLEAN DEFAULT 0,
                FOREIGN KEY (rfq_vendor_id) REFERENCES rfq_vendors(rfq_vendor_id)
            )
        ");

        $this->pdo->exec("
            CREATE TABLE rfq_quote_approvals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                rfq_id INTEGER NOT NULL,
                quote_id INTEGER,
                approval_stage TEXT NOT NULL,
                approver_id INTEGER,
                approver_role TEXT,
                action TEXT NOT NULL,
                comments TEXT,
                rejection_reason TEXT,
                requestor_notes TEXT,
                vendor_submission_details TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (rfq_id) REFERENCES rfqs(rfq_id),
                FOREIGN KEY (quote_id) REFERENCES rfq_quotes(quote_id),
                FOREIGN KEY (approver_id) REFERENCES users(user_id)
            )
        ");
    }

    /**
     * Test that Deputy Government Chemist users are authorized to approve RFQs for branch 6.
     */
    public function testDGCCanApproveBranch6RFQ(): void
    {
        // Setup: Create roles, users, branches, and RFQ data
        $dgcUserId = $this->createDGCUser();
        $requestorUserId = $this->createRequestorUser();
        $this->createBranch6();

        $requestId = $this->createProcurementRequest($requestorUserId);
        $rfqId = $this->createRFQ($requestId);
        $this->createRFQQuote($rfqId);

        // Mark requestor review as approved
        $stmt = $this->pdo->prepare("
            UPDATE rfqs 
            SET requestor_spec_review_status = 'APPROVED',
                requestor_reviewer_id = ?,
                requestor_reviewed_at = CURRENT_TIMESTAMP
            WHERE rfq_id = ?
        ");
        $stmt->execute([$requestorUserId, $rfqId]);

        $service = new RFQQuoteApprovalService($this->pdo, $dgcUserId, 'Deputy Government Chemist');

        // Test: DGC should be able to approve the RFQ without throwing an exception
        $result = $service->decideBranchHeadApproval(
            $rfqId,
            'APPROVE',
            '',
            null,
            true  // confirmationChecked
        );

        $this->assertTrue($result, 'DGC should be authorized to approve branch 6 RFQ');

        // Verify: Check that the approval was recorded
        $stmt = $this->pdo->prepare("
            SELECT branch_head_approver_id, branch_head_approval_status 
            FROM rfqs 
            WHERE rfq_id = ?
        ");
        $stmt->execute([$rfqId]);
        $rfq = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals($dgcUserId, (int) $rfq['branch_head_approver_id'], 'DGC user should be recorded as approver');
        $this->assertEquals('APPROVED', $rfq['branch_head_approval_status'], 'RFQ status should be APPROVED');
    }

    /**
     * Test that DGC authorization works for branch 6 identified by analytical keyword.
     */
    public function testDGCCanApproveBranch6WithAnalyticalKeyword(): void
    {
        // Setup: Create a branch with "Analytical" in the name but using non-6 branch ID
        $dgcUserId = $this->createDGCUser();
        $requestorUserId = $this->createRequestorUser();
        
        $stmt = $this->pdo->prepare("
            INSERT INTO branches (branch_id, branch_name)
            VALUES (?, ?)
        ");
        $stmt->execute([99, 'Ministry of Analytical Services']);

        $requestId = $this->createProcurementRequest($requestorUserId, 99);
        $rfqId = $this->createRFQ($requestId);
        $this->createRFQQuote($rfqId);

        // Mark requestor review as approved
        $stmt = $this->pdo->prepare("
            UPDATE rfqs 
            SET requestor_spec_review_status = 'APPROVED',
                requestor_reviewer_id = ?,
                requestor_reviewed_at = CURRENT_TIMESTAMP
            WHERE rfq_id = ?
        ");
        $stmt->execute([$requestorUserId, $rfqId]);

        $service = new RFQQuoteApprovalService($this->pdo, $dgcUserId, 'Deputy Government Chemist');

        // Test: DGC should be able to approve via keyword match despite branch ID not being 6
        $result = $service->decideBranchHeadApproval(
            $rfqId,
            'APPROVE',
            '',
            null,
            true
        );

        $this->assertTrue($result, 'DGC should be authorized for branch with analytical keyword');
    }

    /**
     * Test that HOD/Branch Head users cannot approve branch 6 RFQs.
     */
    public function testHODCannotApproveBranch6RFQ(): void
    {
        // Setup: Create a HOD user (not DGC)
        $stmt = $this->pdo->prepare("INSERT INTO roles (name) VALUES (?)");
        $stmt->execute(['HOD']);
        $hodRoleId = $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare("
            INSERT INTO users (role_id, branch_id, full_name) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$hodRoleId, 5, 'Test HOD']);
        $hodUserId = $this->pdo->lastInsertId();

        $requestorUserId = $this->createRequestorUser();
        $this->createBranch6();

        $requestId = $this->createProcurementRequest($requestorUserId);
        $rfqId = $this->createRFQ($requestId);
        $this->createRFQQuote($rfqId);

        // Mark requestor review as approved
        $stmt = $this->pdo->prepare("
            UPDATE rfqs 
            SET requestor_spec_review_status = 'APPROVED',
                requestor_reviewer_id = ?,
                requestor_reviewed_at = CURRENT_TIMESTAMP
            WHERE rfq_id = ?
        ");
        $stmt->execute([$requestorUserId, $rfqId]);

        $service = new RFQQuoteApprovalService($this->pdo, $hodUserId, 'HOD');

        // Test: HOD of different branch should NOT be able to approve branch 6 RFQ
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only the auto-routed Branch Head may decide this approval');

        $service->decideBranchHeadApproval(
            $rfqId,
            'APPROVE',
            '',
            null,
            true
        );
    }

    // Helper methods

    private function createDGCUser(): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO roles (name) VALUES (?)");
        $stmt->execute(['Deputy Government Chemist']);
        $roleId = $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare("
            INSERT INTO users (role_id, branch_id, full_name) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$roleId, 6, 'Deputy Government Chemist User']);
        return $this->pdo->lastInsertId();
    }

    private function createRequestorUser(): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO roles (name) VALUES (?)");
        $stmt->execute(['Requestor']);
        $roleId = $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare("
            INSERT INTO users (role_id, full_name) 
            VALUES (?, ?)
        ");
        $stmt->execute([$roleId, 'Test Requestor']);
        return $this->pdo->lastInsertId();
    }

    private function createBranch6(): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO branches (branch_id, branch_name)
            VALUES (?, ?)
        ");
        $stmt->execute([6, 'Analytical & Advisory']);
    }

    private function createProcurementRequest(int $requestorUserId, int $branchId = 6): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO procurement_requests 
            (request_number, branch_id, created_by, description, estimated_value, status)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            'RQ-' . time(),
            $branchId,
            $requestorUserId,
            'Test Procurement Request',
            1000.00,
            'QUOTE_BRANCH_HEAD_APPROVAL_PENDING'
        ]);
        return $this->pdo->lastInsertId();
    }

    private function createRFQ(int $requestId): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO rfqs 
            (rfq_number, request_id, submission_deadline, branch_head_approval_status)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            'RFQ-' . time(),
            $requestId,
            date('Y-m-d', strtotime('+7 days')),
            'PENDING'
        ]);
        return $this->pdo->lastInsertId();
    }

    private function createRFQQuote(int $rfqId): void
    {
        // Create vendor
        $stmt = $this->pdo->prepare("INSERT INTO vendors (vendor_name) VALUES (?)");
        $stmt->execute(['Test Vendor']);
        $vendorId = $this->pdo->lastInsertId();

        // Create RFQ vendor relationship
        $stmt = $this->pdo->prepare("INSERT INTO rfq_vendors (rfq_id, vendor_id) VALUES (?, ?)");
        $stmt->execute([$rfqId, $vendorId]);
        $rfqVendorId = $this->pdo->lastInsertId();

        // Create selected quote
        $stmt = $this->pdo->prepare("
            INSERT INTO rfq_quotes (rfq_vendor_id, quote_amount, gct_amount, is_selected)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$rfqVendorId, 1000.00, 0.00, 1]);
    }
}
