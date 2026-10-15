<?php

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * Guards the dashboard/edit wiring that makes a request awaiting Director
 * HRM&A sign-off visible and re-routable:
 *   - the HRM&A dashboard queue is driven by pending approval rows, so
 *     over-threshold requests already approved by the HOD (HOD_APPROVED)
 *     still appear as actions;
 *   - the procurement edit screen can move a request to another branch and
 *     re-routes/notifies accordingly.
 */
final class DirectorHrmaActionQueueTest extends PHPUnit\Framework\TestCase
{
    private function readFile(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path);
        return (string)file_get_contents($path);
    }

    public function testHrmaDashboardQueueIsDrivenByPendingApprovals(): void
    {
        $contents = $this->readFile('dashboard/director_hrma.php');

        $this->assertStringContainsString('FROM request_approvals ra', $contents);
        $this->assertStringContainsString("ra.role = 'Director HRM&A'", $contents);
        $this->assertStringContainsString("ra.status = 'pending'", $contents);

        // The queue must not be limited to freshly submitted requests or to a
        // single branch, otherwise HOD_APPROVED requests never surface.
        $this->assertStringNotContainsString("UPPER(pr.status) = 'SUBMITTED'", $contents);
        $this->assertStringNotContainsString('b.branch_id = 5', $contents);
    }

    public function testEditScreenAllowsBranchChangeAndRebuildsChain(): void
    {
        $contents = $this->readFile('procurement/edit.php');

        $this->assertStringContainsString('name="branch_id"', $contents);
        $this->assertStringContainsString('rebuildPendingApprovalChain(', $contents);
        $this->assertStringContainsString('notifyRequestBranchChanged(', $contents);
        $this->assertStringContainsString('branch_id = ?', $contents);
    }

    public function testBranchChangeNotifierIsAvailable(): void
    {
        $contents = $this->readFile('config/notifications.php');
        $this->assertStringContainsString('function notifyRequestBranchChanged(', $contents);
    }

    public function testSubmissionNotifiesEveryHolderOfTheFirstApproverRole(): void
    {
        $contents = $this->readFile('procurement/submit.php');

        $this->assertStringContainsString('getUsersByRole($firstApprovalRole)', $contents);
        $this->assertStringContainsString('foreach ($approvers as $approver)', $contents);
    }

    public function testRevertNotifiesTheNewlyResponsibleApprovers(): void
    {
        $contents = $this->readFile('procurement/revert_status.php');

        $this->assertStringContainsString('notifyApprovalNeeded(', $contents);
        $this->assertStringContainsString("status = 'pending'", $contents);
    }
}
