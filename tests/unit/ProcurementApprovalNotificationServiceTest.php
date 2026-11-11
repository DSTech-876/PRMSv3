<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/services/ProcurementApprovalNotificationService.php';

final class ProcurementApprovalNotificationServiceTest extends PHPUnit\Framework\TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
        $this->pdo->exec(
            'CREATE TABLE users (
                user_id INTEGER PRIMARY KEY,
                full_name TEXT,
                email TEXT,
                role_id INTEGER,
                is_active INTEGER DEFAULT 1
            )'
        );
        $this->pdo->exec('CREATE TABLE branches (branch_id INTEGER PRIMARY KEY, branch_name TEXT)');
        $this->pdo->exec(
            'CREATE TABLE procurement_requests (
                request_id INTEGER PRIMARY KEY,
                request_number TEXT,
                description TEXT,
                status TEXT,
                estimated_value REAL,
                currency TEXT,
                request_type TEXT,
                branch_id INTEGER,
                created_by INTEGER,
                approved_by INTEGER,
                approved_at TEXT
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE request_approvals (
                approval_id INTEGER PRIMARY KEY AUTOINCREMENT,
                request_id INTEGER,
                approver_role TEXT,
                approved_by INTEGER,
                status TEXT
            )'
        );

        $this->pdo->exec(
            "INSERT INTO roles (id, name) VALUES
                (1, 'Procurement Officer'),
                (2, 'Finance Officer'),
                (3, 'Requestor'),
                (4, 'Branch Head')"
        );
        $this->pdo->exec(
            "INSERT INTO users (user_id, full_name, email, role_id, is_active) VALUES
                (1, 'Pat Procurement', 'pat@dgc.test',  1, 1),
                (2, 'Fay Finance',     'fay@dgc.test',  2, 1),
                (3, 'Rita Requestor',  'rita@dgc.test', 3, 1),
                (4, 'Bob BranchHead',  'bob@dgc.test',  4, 1),
                (5, 'Ina Inactive',    'ina@dgc.test',  1, 0)"
        );
        $this->pdo->exec("INSERT INTO branches VALUES (1, 'Kingston')");
        $this->pdo->exec(
            "INSERT INTO procurement_requests
                (request_id, request_number, description, status, estimated_value, currency,
                 request_type, branch_id, created_by, approved_by, approved_at)
             VALUES (1, 'PR001', 'Office chairs', 'APPROVED', 1500, 'GBP',
                     'REGULAR', 1, 3, 4, '2026-01-20 09:30:00')"
        );
    }

    private function request(): array
    {
        return $this->pdo
            ->query('SELECT pr.*, b.branch_name FROM procurement_requests pr
                     LEFT JOIN branches b ON b.branch_id = pr.branch_id WHERE pr.request_id = 1')
            ->fetch(PDO::FETCH_ASSOC);
    }

    public function testFinalApprovalIsOnlyTrueWhenNoStagesRemainPending(): void
    {
        $this->pdo->exec(
            "INSERT INTO request_approvals (request_id, approver_role, approved_by, status)
             VALUES (1, 'HOD', 4, 'approved'),
                    (1, 'Branch Head', NULL, 'pending')"
        );

        $this->assertFalse(
            ProcurementApprovalNotificationService::isFinalApproval($this->pdo, 1),
            'A pending stage means approval is not final'
        );

        $this->pdo->exec("UPDATE request_approvals SET status = 'approved', approved_by = 4 WHERE status = 'pending'");

        $this->assertTrue(ProcurementApprovalNotificationService::isFinalApproval($this->pdo, 1));
    }

    public function testRejectedStagesDoNotBlockFinalApproval(): void
    {
        $this->pdo->exec(
            "INSERT INTO request_approvals (request_id, approver_role, approved_by, status)
             VALUES (1, 'HOD', 4, 'approved'),
                    (1, 'Committee', 4, 'rejected')"
        );

        $this->assertTrue(ProcurementApprovalNotificationService::isFinalApproval($this->pdo, 1));
    }

    public function testRecipientsCoverLifecycleRolesRequestorAndPriorApprovers(): void
    {
        $this->pdo->exec(
            "INSERT INTO request_approvals (request_id, approver_role, approved_by, status)
             VALUES (1, 'Branch Head', 4, 'approved')"
        );

        $recipients = ProcurementApprovalNotificationService::resolveRecipients($this->pdo, $this->request());
        $ids = array_keys($recipients);

        $this->assertContains(1, $ids, 'Procurement Officer is a lifecycle role');
        $this->assertContains(2, $ids, 'Finance Officer is a lifecycle role');
        $this->assertContains(3, $ids, 'The requestor must be notified');
        $this->assertContains(4, $ids, 'The approving Branch Head must be notified');
    }

    public function testInactiveUsersAreNeverNotified(): void
    {
        $recipients = ProcurementApprovalNotificationService::resolveRecipients($this->pdo, $this->request());

        $this->assertArrayNotHasKey(5, $recipients, 'Deactivated accounts must be excluded');
    }

    public function testRecipientsAreDeduplicatedAcrossRolesAndParticipation(): void
    {
        /* The requestor also holds a lifecycle role and approved a stage. */
        $this->pdo->exec('UPDATE users SET role_id = 1 WHERE user_id = 3');
        $this->pdo->exec(
            "INSERT INTO request_approvals (request_id, approver_role, approved_by, status)
             VALUES (1, 'HOD', 3, 'approved')"
        );

        $recipients = ProcurementApprovalNotificationService::resolveRecipients($this->pdo, $this->request());
        $ids = array_keys($recipients);

        $this->assertSame(
            count(array_unique($ids)),
            count($ids),
            'Each user must appear exactly once regardless of how many ways they qualify'
        );
        $this->assertContains(3, $ids);
    }

    public function testContentCarriesEveryRequiredNotificationField(): void
    {
        $content = ProcurementApprovalNotificationService::buildContent(
            $this->request(),
            'APPROVED',
            $this->pdo
        );

        $this->assertStringContainsString('PR001', $content['body']);
        $this->assertStringContainsString('Office chairs', $content['body']);
        $this->assertStringContainsString('Kingston', $content['body']);
        $this->assertStringContainsString('20 Jan 2026', $content['body']);

        $this->assertSame('PR001', $content['request_number']);
        $this->assertSame('Office chairs', $content['request_title']);
        $this->assertSame('Kingston', $content['department']);
        $this->assertSame('/procurement/view.php?id=1', $content['link']);
        $this->assertNotSame('', $content['status_label']);

        /* The request's own currency must survive into the notification. */
        $this->assertSame('GBP £1,500.00', $content['amount']);
    }

    public function testContentFallsBackSafelyWhenOptionalFieldsAreMissing(): void
    {
        $content = ProcurementApprovalNotificationService::buildContent(
            ['request_id' => 9, 'request_number' => 'PR009'],
            '',
            $this->pdo
        );

        $this->assertSame('Not specified', $content['department']);
        $this->assertSame('Procurement Request PR009', $content['request_title']);
        $this->assertSame('Approved', $content['status_label']);
    }

    public function testEmailHtmlEscapesContentAndLinksToTheRequest(): void
    {
        $content = ProcurementApprovalNotificationService::buildContent(
            ['request_id' => 1, 'request_number' => 'PR001', 'description' => 'Chairs & <desks>'],
            'APPROVED',
            $this->pdo
        );

        $html = ProcurementApprovalNotificationService::buildEmailHtml($content);

        $this->assertStringContainsString('PR001', $html);
        $this->assertStringNotContainsString('<desks>', $html, 'User content must be escaped');
        $this->assertStringContainsString('/procurement/view.php?id=1', $html);
    }

    public function testNotifyIsANoOpForAnUnknownRequest(): void
    {
        $result = ProcurementApprovalNotificationService::notifyApprovalGranted(9999, 'APPROVED', $this->pdo);

        $this->assertSame(0, (int)($result['notified'] ?? 0));
    }
}
